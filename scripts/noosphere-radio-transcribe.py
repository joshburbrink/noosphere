#!/opt/noosphere-whisper/bin/python3
"""
Noosphere radio monitor: squelch-aware rtl_fm capture + vosk/faster-whisper transcription.
Logs each detected transmission to radio_log with source='monitor-auto'.
"""
import sys, os, glob, subprocess, sqlite3, json, time, wave, signal, tempfile, threading

RADIO_DB      = '/var/lib/noosphere/radio.db'
VOSK_DIR      = '/var/lib/noosphere/vosk-models'
WHISPER_DIR   = '/var/lib/noosphere/whisper-models'
TALK_CONF     = '/etc/noosphere/talk-bot.conf'
SETTINGS_DB   = '/var/lib/noosphere/settings.db'
CLIPS_DIR     = '/var/lib/noosphere/radio/clips'
STATUS_FILE   = '/var/lib/noosphere/radio/monitor-status.json'
VENV_PYTHON   = '/opt/noosphere-whisper/bin/python3'
SITE_PACKAGES = '/opt/noosphere-whisper/lib/python3.13/site-packages'

SAMPLE_RATE   = 16000
SQUELCH_DB    = -40.0    # dBFS  -  below this is silence
SILENCE_DUR   = 1.5      # seconds of silence to end a clip
MIN_CLIP_SEC  = 0.5      # ignore very short blips
MAX_CLIP_SEC  = 120      # cap clip length

running = True


def get_setting(key, default=''):
    try:
        conn = sqlite3.connect(SETTINGS_DB)
        row = conn.execute('SELECT value FROM settings WHERE key=?', (key,)).fetchone()
        conn.close()
        return row[0] if row else default
    except:
        return default


def has_avx():
    try:
        for line in open('/proc/cpuinfo'):
            if line.startswith('flags'):
                return ' avx ' in f' {line} '
    except:
        pass
    return False


def select_backend():
    pref = get_setting('transcription_backend', 'auto')
    avx  = has_avx()
    if pref == 'faster-whisper' and avx:
        return 'faster-whisper'
    if pref == 'vosk':
        return 'vosk'
    return 'faster-whisper' if avx else 'vosk'


def transcribe_vosk(wav_path):
    sys.path.insert(0, SITE_PACKAGES)
    from vosk import Model, KaldiRecognizer
    candidates = sorted(glob.glob(f'{VOSK_DIR}/vosk-model*'))
    if not candidates:
        raise RuntimeError(f'No vosk model in {VOSK_DIR}')
    model = Model(candidates[0])
    wf = wave.open(wav_path, 'rb')
    rec = KaldiRecognizer(model, wf.getframerate())
    rec.SetWords(False)
    parts = []
    while True:
        data = wf.readframes(4000)
        if not data:
            break
        if rec.AcceptWaveform(data):
            parts.append(json.loads(rec.Result()).get('text', ''))
    parts.append(json.loads(rec.FinalResult()).get('text', ''))
    wf.close()
    return ' '.join(p for p in parts if p).strip()


def transcribe_faster_whisper(wav_path):
    sys.path.insert(0, SITE_PACKAGES)
    from faster_whisper import WhisperModel
    os.makedirs(WHISPER_DIR, exist_ok=True)
    model = WhisperModel('tiny.en', device='cpu', compute_type='int8',
                         download_root=WHISPER_DIR)
    segs, _ = model.transcribe(wav_path, beam_size=5)
    return ' '.join(s.text for s in segs).strip()


def transcribe(wav_path, backend):
    return transcribe_faster_whisper(wav_path) if backend == 'faster-whisper' else transcribe_vosk(wav_path)


def db_init():
    conn = sqlite3.connect(RADIO_DB)
    conn.execute("PRAGMA journal_mode=WAL")
    # Add columns if missing
    cols = {r[1] for r in conn.execute("PRAGMA table_info(radio_log)")}
    if 'source'     not in cols:
        conn.execute("ALTER TABLE radio_log ADD COLUMN source TEXT")
    if 'transcript' not in cols:
        conn.execute("ALTER TABLE radio_log ADD COLUMN transcript TEXT")
    if 'clip_path'  not in cols:
        conn.execute("ALTER TABLE radio_log ADD COLUMN clip_path TEXT")
    if 'duration'   not in cols:
        conn.execute("ALTER TABLE radio_log ADD COLUMN duration REAL")
    conn.commit()
    conn.close()


def log_transmission(freq, transcript, clip_path, duration):
    conn = sqlite3.connect(RADIO_DB)
    conn.execute(
        "INSERT INTO radio_log (logged_at,callsign,frequency,source,transcript,clip_path,duration,logged_by) "
        "VALUES (?,?,?,?,?,?,?,?)",
        (int(time.time()), 'Monitor Auto', freq, 'monitor-auto',
         transcript, clip_path, duration, 'SDR Monitor')
    )
    conn.commit()
    conn.close()


def post_to_talk(message):
    conf = {}
    try:
        with open(TALK_CONF) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    conf[k.strip()] = v.strip()
    except:
        return
    import urllib.request, base64
    nc_url  = conf.get('NEXTCLOUD_URL', '').rstrip('/')
    nc_user = conf.get('NEXTCLOUD_USER', '')
    nc_pass = conf.get('NEXTCLOUD_PASS', '')
    room    = conf.get('TALK_ROOM', 'wrp84b8i')
    if not all([nc_url, nc_user, nc_pass]):
        return
    url  = f'{nc_url}/ocs/v2.php/apps/spreed/api/v1/chat/{room}'
    data = json.dumps({'message': message, 'actorDisplayName': 'Radio Monitor'}).encode()
    creds = base64.b64encode(f'{nc_user}:{nc_pass}'.encode()).decode()
    req = urllib.request.Request(url, data=data, headers={
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'OCS-APIRequest': 'true', 'Authorization': f'Basic {creds}',
    })
    try:
        urllib.request.urlopen(req, timeout=10)
    except Exception as e:
        print(f'Talk post failed: {e}')


def write_status(state, freq, last_clip=None, last_transcript=None):
    os.makedirs(os.path.dirname(STATUS_FILE), exist_ok=True)
    with open(STATUS_FILE, 'w') as f:
        json.dump({
            'ts': int(time.time()), 'state': state, 'freq': freq,
            'last_clip': last_clip, 'last_transcript': last_transcript,
        }, f)


def run_monitor(freq, backend):
    """Continuously capture from rtl_fm at freq, detect transmissions, transcribe."""
    os.makedirs(CLIPS_DIR, exist_ok=True)
    db_init()

    print(f'[radio-monitor] Starting on {freq}, backend={backend}')
    write_status('running', freq)

    # rtl_fm -> raw 16-bit signed PCM at 16kHz mono
    rtl_cmd = [
        'rtl_fm', '-f', freq, '-M', 'fm', '-s', '200k',
        '-r', str(SAMPLE_RATE), '-l', '0',  # squelch=0, we do our own
        '-g', get_setting('radio_gain', '40'),
        '-p', get_setting('radio_ppm', '0'),
        '-',
    ]

    buf = b''
    FRAME      = SAMPLE_RATE * 2          # 1s of int16 mono = 32000 bytes
    chunk_sec  = 0.1                      # process 100ms at a time
    chunk_size = int(SAMPLE_RATE * chunk_sec) * 2

    clip_frames = []
    in_transmission = False
    silence_frames  = 0
    silence_thresh  = int(SILENCE_DUR / chunk_sec)
    max_frames      = int(MAX_CLIP_SEC / chunk_sec)

    proc = subprocess.Popen(rtl_cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)

    def shutdown(sig, frame):
        global running
        running = False
        proc.terminate()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT,  shutdown)

    try:
        while running:
            chunk = proc.stdout.read(chunk_size)
            if not chunk:
                break

            # RMS power of this chunk
            import struct, math
            samples = struct.unpack(f'{len(chunk)//2}h', chunk)
            if samples:
                rms = math.sqrt(sum(s*s for s in samples) / len(samples))
                db  = 20 * math.log10(max(rms, 1) / 32768)
            else:
                db = -96

            active = db > SQUELCH_DB

            if active:
                silence_frames = 0
                if not in_transmission:
                    in_transmission = True
                    clip_frames = []
                    print(f'[radio-monitor] TX start ({db:.1f} dBFS)')
                clip_frames.append(chunk)
                if len(clip_frames) >= max_frames:
                    # Force-close overlong clip
                    process_clip(clip_frames, freq, backend)
                    clip_frames = []
                    in_transmission = False
            else:
                if in_transmission:
                    silence_frames += 1
                    clip_frames.append(chunk)
                    if silence_frames >= silence_thresh:
                        # Transmission ended
                        in_transmission = False
                        duration = len(clip_frames) * chunk_sec
                        if duration >= MIN_CLIP_SEC:
                            process_clip(clip_frames, freq, backend)
                        clip_frames = []
                        silence_frames = 0
                        print(f'[radio-monitor] TX end ({duration:.1f}s)')

    except Exception as e:
        print(f'[radio-monitor] Error: {e}')
    finally:
        proc.terminate()
        write_status('stopped', freq)
        print('[radio-monitor] Stopped.')


def process_clip(frames, freq, backend):
    raw = b''.join(frames)
    ts  = int(time.time())
    clip_wav  = os.path.join(CLIPS_DIR, f'clip-{ts}.wav')
    duration  = len(frames) * 0.1

    # Write WAV
    with wave.open(clip_wav, 'wb') as wf:
        wf.setnchannels(1)
        wf.setsampwidth(2)
        wf.setframerate(SAMPLE_RATE)
        wf.writeframes(raw)

    try:
        transcript = transcribe(clip_wav, backend).strip()
    except Exception as e:
        transcript = ''
        print(f'[radio-monitor] Transcription error: {e}')

    if not transcript:
        os.unlink(clip_wav)
        return

    print(f'[radio-monitor] Transcript: {transcript!r}')
    log_transmission(freq, transcript, clip_wav, duration)
    write_status('running', freq, clip_wav, transcript)

    if get_setting('transcription_talk_post', '0') == '1':
        short = transcript[:200] + ('...' if len(transcript) > 200 else '')
        post_to_talk(f'📻 Radio Monitor | {freq}\n📝 {short}')

    # Prune clips older than 48h
    cutoff = time.time() - 48 * 3600
    for f in glob.glob(os.path.join(CLIPS_DIR, 'clip-*.wav')):
        try:
            if os.path.getmtime(f) < cutoff:
                os.unlink(f)
        except:
            pass


def main():
    if len(sys.argv) > 1 and sys.argv[1] == 'status':
        try:
            print(open(STATUS_FILE).read())
        except:
            print('{"state":"stopped"}')
        return

    if get_setting('transcription_radio_log', '0') != '1':
        print('[radio-monitor] Disabled in settings.')
        sys.exit(0)

    freq    = get_setting('radio_monitor_freq', '146.520M')
    backend = select_backend()
    run_monitor(freq, backend)


if __name__ == '__main__':
    main()
