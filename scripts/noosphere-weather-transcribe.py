#!/opt/noosphere-whisper/bin/python3
"""
Noosphere NWR auto-transcription.
Supports vosk (SSE2, all hardware) and faster-whisper (AVX required, higher accuracy).
Backend selected by 'transcription_backend' setting: auto | vosk | faster-whisper
"""
import sys, os, glob, subprocess, sqlite3, json, time, re, wave
import urllib.request, base64, tempfile

STREAM_DIR    = '/var/www/noosphere/weather/stream'
WEATHER_DB    = '/var/lib/noosphere/weather.db'
VOSK_DIR      = '/var/lib/noosphere/vosk-models'
WHISPER_DIR   = '/var/lib/noosphere/whisper-models'
TALK_CONF     = '/etc/noosphere/talk-bot.conf'
SETTINGS_DB   = '/var/lib/noosphere/settings.db'
STATUS_FILE   = '/var/lib/noosphere/weather/last-transcription.json'
VENV_PYTHON   = '/opt/noosphere-whisper/bin/python3'
SITE_PACKAGES = '/opt/noosphere-whisper/lib/python3.13/site-packages'


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
        flags = open('/proc/cpuinfo').read()
        for line in flags.splitlines():
            if line.startswith('flags'):
                return ' avx ' in f' {line} '
        return False
    except:
        return False


def faster_whisper_ok():
    try:
        r = subprocess.run(
            [VENV_PYTHON, '-c', 'import faster_whisper; print(1)'],
            capture_output=True, text=True, timeout=10
        )
        return r.stdout.strip() == '1'
    except:
        return False


def vosk_ok():
    try:
        r = subprocess.run(
            [VENV_PYTHON, '-c', 'import vosk; print(1)'],
            capture_output=True, text=True, timeout=10
        )
        return r.stdout.strip() == '1' and bool(glob.glob(f'{VOSK_DIR}/vosk-model*'))
    except:
        return False


def select_backend():
    """Return 'faster-whisper' or 'vosk' based on setting + capability."""
    pref = get_setting('transcription_backend', 'auto')
    avx  = has_avx()
    fw   = faster_whisper_ok()
    vk   = vosk_ok()

    if pref == 'faster-whisper':
        if avx and fw:
            return 'faster-whisper'
        print('WARNING: faster-whisper requested but not available (AVX=%s, installed=%s). Falling back to vosk.' % (avx, fw))
        return 'vosk'
    if pref == 'vosk':
        return 'vosk'
    # auto
    if avx and fw:
        return 'faster-whisper'
    return 'vosk'


def get_segments(n=30):
    segs = sorted(glob.glob(f'{STREAM_DIR}/seg*.aac'))
    return segs[-n:] if segs else []


def stitch_wav(segments):
    tf = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
    tf.close()
    inputs = []
    for s in segments:
        inputs += ['-i', s]
    n = len(segments)
    fc = f'concat=n={n}:v=0:a=1[a]'
    subprocess.run(
        ['ffmpeg', '-y'] + inputs +
        ['-filter_complex', fc, '-map', '[a]',
         '-ar', '16000', '-ac', '1', '-f', 'wav', tf.name],
        capture_output=True, check=True
    )
    return tf.name


def transcribe_vosk(wav_path):
    sys.path.insert(0, SITE_PACKAGES)
    from vosk import Model, KaldiRecognizer
    candidates = sorted(glob.glob(f'{VOSK_DIR}/vosk-model*'))
    if not candidates:
        raise RuntimeError(f'No vosk model found in {VOSK_DIR}')
    model = Model(candidates[0])
    wf = wave.open(wav_path, 'rb')
    rec = KaldiRecognizer(model, wf.getframerate())
    rec.SetWords(False)
    words = []
    while True:
        data = wf.readframes(4000)
        if not data:
            break
        if rec.AcceptWaveform(data):
            r = json.loads(rec.Result())
            words.append(r.get('text', ''))
    r = json.loads(rec.FinalResult())
    words.append(r.get('text', ''))
    wf.close()
    return ' '.join(w for w in words if w).strip()


def transcribe_faster_whisper(wav_path):
    sys.path.insert(0, SITE_PACKAGES)
    from faster_whisper import WhisperModel
    os.makedirs(WHISPER_DIR, exist_ok=True)
    model = WhisperModel('tiny.en', device='cpu', compute_type='int8',
                         download_root=WHISPER_DIR)
    segments, _ = model.transcribe(wav_path, beam_size=5)
    return ' '.join(seg.text for seg in segments).strip()


def transcribe(wav_path, backend):
    if backend == 'faster-whisper':
        return transcribe_faster_whisper(wav_path)
    return transcribe_vosk(wav_path)


def parse_weather(text):
    t = text.lower()
    result = {}
    m = re.search(r'temperature[s]?\s+(?:is\s+)?(\d+)\s*(?:degrees?|°)?', t)
    if m: result['temp_f'] = float(m.group(1))
    dirs = {'northwest':'NW','northeast':'NE','southwest':'SW','southeast':'SE',
            'north':'N','south':'S','east':'E','west':'W','variable':'Variable'}
    for word, abbr in dirs.items():
        if re.search(r'wind[s]?\s+(?:from\s+(?:the\s+)?)?' + word, t):
            result['wind_dir'] = abbr; break
    m = re.search(r'(\d+)(?:\s+to\s+\d+)?\s+(?:miles\s+per\s+hour|mph)', t)
    if m: result['wind_speed'] = m.group(1) + ' mph'
    m = re.search(r'(?:relative\s+)?humidity\s+(?:is\s+)?(\d+)\s*(?:percent|%)?', t)
    if m: result['humidity'] = m.group(1)
    for cond in ['thunderstorm','heavy rain','rain','snow','fog','smoke','haze',
                 'partly cloudy','mostly cloudy','cloudy','overcast','clear','sunny']:
        if cond in t:
            result['conditions'] = cond.title(); break
    return result


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
    nc_url  = conf.get('NEXTCLOUD_URL', '').rstrip('/')
    nc_user = conf.get('NEXTCLOUD_USER', '')
    nc_pass = conf.get('NEXTCLOUD_PASS', '')
    room    = conf.get('TALK_ROOM', 'wrp84b8i')
    if not all([nc_url, nc_user, nc_pass]):
        return
    url  = f'{nc_url}/ocs/v2.php/apps/spreed/api/v1/chat/{room}'
    data = json.dumps({'message': message, 'actorDisplayName': 'NWR Bot'}).encode()
    creds = base64.b64encode(f'{nc_user}:{nc_pass}'.encode()).decode()
    req = urllib.request.Request(url, data=data, headers={
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'OCS-APIRequest': 'true',
        'Authorization': f'Basic {creds}',
    })
    try:
        urllib.request.urlopen(req, timeout=10)
    except Exception as e:
        print(f'Talk post failed: {e}')


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else 'auto'
    if mode == 'auto' and get_setting('transcription_enabled', '0') != '1':
        print('Transcription disabled.'); sys.exit(0)

    backend = select_backend()
    print(f'Backend: {backend} (AVX: {has_avx()}, setting: {get_setting("transcription_backend","auto")})')

    if backend == 'vosk' and not vosk_ok():
        print(f'ERROR: vosk not available. Install: pip install vosk; download model to {VOSK_DIR}')
        sys.exit(1)
    if backend == 'faster-whisper' and not faster_whisper_ok():
        print('ERROR: faster-whisper not installed. Run: pip install faster-whisper')
        sys.exit(1)

    segments = get_segments(30)
    if not segments:
        print('No HLS segments found.'); sys.exit(1)

    print(f'Stitching {len(segments)} segments (~{len(segments)*2}s)...')
    wav = stitch_wav(segments)
    try:
        print(f'Transcribing with {backend}...')
        transcript = transcribe(wav, backend)
        print(f'Transcript: {transcript!r}')
        parsed = parse_weather(transcript)
        print(f'Parsed: {parsed}')

        conn = sqlite3.connect(WEATHER_DB)
        conn.execute(
            'INSERT INTO weather_log (logged_at,temp_f,conditions,wind_dir,wind_speed,humidity,notes,logged_by,source) '
            'VALUES (?,?,?,?,?,?,?,?,?)',
            (int(time.time()), parsed.get('temp_f'), parsed.get('conditions'),
             parsed.get('wind_dir'), parsed.get('wind_speed'), parsed.get('humidity'),
             f'[NWR transcript/{backend}] {transcript}', 'NWR Auto', 'nwr-auto')
        )
        conn.commit(); conn.close()
        print('Logged to weather_log.')

        do_talk = (get_setting('transcription_talk_post','0') == '1') or (mode == 'manual')
        if do_talk:
            parts = []
            if 'temp_f'     in parsed: parts.append(f"{parsed['temp_f']:.0f}°F")
            if 'conditions' in parsed: parts.append(parsed['conditions'])
            wind = ' '.join(filter(None,[parsed.get('wind_dir',''),parsed.get('wind_speed','')]))
            if wind: parts.append(wind)
            if 'humidity'   in parsed: parts.append(f"Humidity {parsed['humidity']}%")
            summary = ' · '.join(parts) if parts else 'No data parsed'
            short_t = transcript[:300] + ('...' if len(transcript) > 300 else '')
            post_to_talk(f'🌤 NWR Auto | {summary}\n📝 {short_t}')
            print('Posted to Talk.')

        os.makedirs(os.path.dirname(STATUS_FILE), exist_ok=True)
        with open(STATUS_FILE, 'w') as f:
            json.dump({'ts': int(time.time()), 'parsed': parsed,
                       'transcript': transcript[:500], 'mode': mode,
                       'backend': backend}, f)
        print('Done.')
    finally:
        try: os.unlink(wav)
        except: pass


if __name__ == '__main__':
    main()
