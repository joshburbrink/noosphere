#!/bin/bash
# noosphere-download-worker.sh  -  background download queue worker (#82 Phase B).
#
# Polls /var/lib/noosphere/downloads.db every 5s for queued items, processes
# one at a time with `curl -C -` (resumable). Writes target.part then renames.
# Updates status/progress in DB. Restarts kiwix when a ZIM completes.

set -u
DB=/var/lib/noosphere/downloads.db
POLL=5

sqlx() { sqlite3 -bail "$DB" "$@"; }

# Bootstrap schema (mirrors shared/downloads.php so worker can start before
# the PHP page is ever hit).
sqlx "PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS downloads (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    kind         TEXT NOT NULL,
    label        TEXT,
    url          TEXT NOT NULL,
    target_path  TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'queued',
    bytes_total  INTEGER DEFAULT 0,
    bytes_done   INTEGER DEFAULT 0,
    pid          INTEGER,
    error        TEXT,
    queued_at    INTEGER NOT NULL,
    started_at   INTEGER,
    finished_at  INTEGER
);"

# Recover from crash: anything marked 'running' on startup goes back to 'queued'.
sqlx "UPDATE downloads SET status='queued', pid=NULL WHERE status='running'"

while true; do
    ROW=$(sqlx "SELECT id||'|'||url||'|'||target_path||'|'||COALESCE(kind,'') FROM downloads WHERE status='queued' ORDER BY id ASC LIMIT 1")
    if [ -z "$ROW" ]; then
        sleep "$POLL"
        continue
    fi

    ID=${ROW%%|*}; REST=${ROW#*|}
    URL=${REST%%|*}; REST=${REST#*|}
    TARGET=${REST%%|*}; KIND=${REST#*|}

    PART="${TARGET}.part"
    mkdir -p "$(dirname "$TARGET")"

    sqlx "UPDATE downloads SET status='running', started_at=strftime('%s','now'), pid=$$, error=NULL WHERE id=$ID"

    # Probe content-length if bytes_total unset.
    TOTAL=$(sqlx "SELECT bytes_total FROM downloads WHERE id=$ID")
    if [ "${TOTAL:-0}" = "0" ]; then
        CL=$(curl -sIL --max-time 15 "$URL" | awk 'BEGIN{IGNORECASE=1} /^content-length:/ {gsub("\r",""); print $2}' | tail -1)
        if [ -n "$CL" ]; then
            sqlx "UPDATE downloads SET bytes_total=$CL WHERE id=$ID"
        fi
    fi

    # Background progress reporter for this download.
    (
        while [ -f "$PART" ]; do
            SZ=$(stat -c%s "$PART" 2>/dev/null || echo 0)
            sqlx "UPDATE downloads SET bytes_done=$SZ WHERE id=$ID AND status='running'"
            sleep 3
        done
    ) &
    PROGRESS_PID=$!

    curl -L --fail --retry 3 --retry-delay 5 --continue-at - -o "$PART" "$URL"
    RC=$?

    kill "$PROGRESS_PID" 2>/dev/null
    wait "$PROGRESS_PID" 2>/dev/null

    # Was this cancelled while running?
    CUR=$(sqlx "SELECT status FROM downloads WHERE id=$ID")
    if [ "$CUR" = "cancelled" ]; then
        rm -f "$PART"
        continue
    fi

    if [ "$RC" -eq 0 ] && [ -f "$PART" ]; then
        mv -f "$PART" "$TARGET"
        SZ=$(stat -c%s "$TARGET" 2>/dev/null || echo 0)
        sqlx "UPDATE downloads SET status='done', bytes_done=$SZ, finished_at=strftime('%s','now'), pid=NULL WHERE id=$ID"
        case "$TARGET" in
            /var/lib/kiwix/zim/*.zim)
                systemctl restart kiwix 2>/dev/null
                ;;
        esac
    else
        ERR="curl exit $RC"
        sqlx "UPDATE downloads SET status='failed', error='$ERR', finished_at=strftime('%s','now'), pid=NULL WHERE id=$ID"
    fi
done
