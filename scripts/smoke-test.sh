#!/usr/bin/env bash
#
# End-to-end smoke test for a RUNNING photo gallery.
#
# It talks to the app over HTTP exactly like a phone would: it uploads a real
# photo, waits for the queue worker to process it, checks that the gallery,
# the processed images and the live-update endpoint show it, and downloads the
# download back: either the untouched original or the JPEG download copy.
#
# Usage:
#   bash scripts/smoke-test.sh https://server.tailaa4397.ts.net:14443
#   bash scripts/smoke-test.sh http://127.0.0.1:7000
#   bash scripts/smoke-test.sh https://host:port --insecure     # skip certificate check
#   bash scripts/smoke-test.sh https://host:port --timeout 120  # wait longer for processing
#
# Needs: bash, curl, python3, cmp.
#
# Side effect: each run leaves ONE small test photo (a blue/yellow chess pattern)
# in the gallery and uses one of the hourly uploads of this computer's IP.
# There is no delete feature yet.

set -u

BASE=""
INSECURE=0
PROCESS_TIMEOUT=60

while [ $# -gt 0 ]; do
    case "$1" in
        --insecure) INSECURE=1 ;;
        --timeout) shift; PROCESS_TIMEOUT="${1:-60}" ;;
        -h|--help) sed -n '2,23p' "$0"; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 2 ;;
        *) BASE="$1" ;;
    esac
    shift
done

if [ -z "$BASE" ]; then
    echo "Usage: bash scripts/smoke-test.sh <base-url> [--insecure] [--timeout seconds]" >&2
    exit 2
fi

BASE="${BASE%/}"

for tool in curl python3 cmp; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "Missing required tool: $tool" >&2
        exit 2
    fi
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

CURL=(curl -sS --max-time 60)
if [ "$INSECURE" -eq 1 ]; then
    CURL+=(-k)
fi

PASSED=0
FAILED=0
WARNED=0

ok()   { PASSED=$((PASSED + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAILED=$((FAILED + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; return 0; }
warn() { WARNED=$((WARNED + 1)); printf '  \033[33mWARN\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; return 0; }
step() { printf '\n%s\n' "$1"; }

# request <name> <curl args...>   -> sets STATUS, writes $WORK/<name>.body and $WORK/<name>.head
request() {
    local name="$1"; shift
    STATUS="$("${CURL[@]}" -o "$WORK/$name.body" -D "$WORK/$name.head" -w '%{http_code}' "$@" 2>"$WORK/$name.err")" || STATUS="000"
    tr -d '\r' < "$WORK/$name.head" > "$WORK/$name.head.clean" 2>/dev/null || : > "$WORK/$name.head.clean"
}

# header <name> <Header-Name>  -> prints the header value (empty if missing)
header() {
    grep -i "^$2:" "$WORK/$1.head.clean" 2>/dev/null | tail -1 | sed -e 's/^[^:]*:[[:space:]]*//'
}

# jget <file> <dotted.path>  -> prints the value, exits 1 if missing
jget() {
    python3 - "$1" "$2" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as handle:
        data = json.load(handle)
    for key in sys.argv[2].split('.'):
        data = data[int(key)] if isinstance(data, list) else data[key]
    print(json.dumps(data) if isinstance(data, (dict, list)) else data)
except Exception:
    sys.exit(1)
PY
}

# list_has <file> <list.path> <field> <value>  -> exit 0 if some item has field == value
list_has() {
    python3 - "$1" "$2" "$3" "$4" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as handle:
        data = json.load(handle)
    for key in sys.argv[2].split('.'):
        data = data[key]
    sys.exit(0 if any(str(item.get(sys.argv[3])) == sys.argv[4] for item in data) else 1)
except Exception:
    sys.exit(1)
PY
}

# path_of <url>  -> prints /path?query of an absolute URL
path_of() {
    python3 -c 'import sys; from urllib.parse import urlparse; u = urlparse(sys.argv[1]); print(u.path + ("?" + u.query if u.query else ""))' "$1"
}

# authority_of <url>  -> prints host[:port] in lowercase, default ports removed
authority_of() {
    python3 -c '
import sys
from urllib.parse import urlparse
u = urlparse(sys.argv[1])
port = u.port
default = {"http": 80, "https": 443}.get(u.scheme)
print((u.hostname or "").lower() + (":" + str(port) if port and port != default else ""))
' "$1"
}

# --- test files --------------------------------------------------------------

# 160x160 PNG, 464 bytes
python3 - "$WORK/photo.png" <<'PY'
import base64, sys
data = "iVBORw0KGgoAAAANSUhEUgAAAKAAAACgCAIAAAAErfB6AAABl0lEQVR42u3csQkAIAxFwSzhCE7mum7iBlbZIUVAObD/8K4Nxt2j/OY65We3ZzeEBiw0YKEBAwZsF7BdwHYBAxYasNCAhQZsF7BdwHYBAxYasNCAhQYMuBVYrL93AQMWGrDQgAEDtgvYLmC7gAELDVhowEIDtgvYLmC7gAELDVhowEIDBtwLLJarSqEBCw0YMGC7gO0CtgsYsNCAhQYsNGC7gO0CtgsYsNCAhQYsNGDAgO36J8uuq0q7gAELDVhowEIDtgvYLmC7gAELDVhowEIDtgvYLmC7gAELDVhowEL7JwuSq0q7gO0CtgsYsNCAhQYsNGC7gO0CtgsYsNCAhQYsNGDAgO0CtgsYsND+yRLLVaXQgAEDtgvYLmC7gAELDVhowEIDtgvYLmC7gAELDVhowEIDBgzYLmC7/smy66oSsNCAhQYsNGC7gO0CtgsYsNCAhQYsNGC7gO0CtgsYsNCAhQYsNGDA/smy66rSLmC7gAELDVhowEIDtgvYLmC7gAELDVhowEIDtgvYLmC7gAELDVjoZ3cTmC9FrMO9nrkAAAAASUVORK5CYII="
with open(sys.argv[1], "wb") as handle:
    handle.write(base64.b64decode(data))
PY

printf 'this is not an image' > "$WORK/fake.jpg"

printf 'Photo gallery smoke test\nTarget: %s\n' "$BASE"

# --- 1. server is up ---------------------------------------------------------

step "1. Server"

request health "$BASE/up"
if [ "$STATUS" = "200" ]; then
    ok "health check /up answers 200"
else
    bad "health check /up answers 200" "got HTTP $STATUS. $(cat "$WORK/health.err" 2>/dev/null)"
    printf '\nThe app is not reachable, so the remaining checks are skipped.\n'
    exit 1
fi

request home "$BASE/"
if [ "$STATUS" = "200" ] && grep -q 'id="root"' "$WORK/home.body"; then
    ok "front page serves the React app"
else
    bad "front page serves the React app" "got HTTP $STATUS"
fi

# --- 2. gallery API ----------------------------------------------------------

step "2. Gallery API"

request list "$BASE/api/photos" -H 'Accept: application/json'
CURSOR_BEFORE=""
if [ "$STATUS" = "200" ] && jget "$WORK/list.body" data >/dev/null && CURSOR_BEFORE="$(jget "$WORK/list.body" updates_cursor)"; then
    ok "GET /api/photos returns a data list and updates_cursor"
else
    bad "GET /api/photos returns a data list and updates_cursor" "got HTTP $STATUS"
fi

NEXT_LINK="$(jget "$WORK/list.body" links.next || true)"
case "$NEXT_LINK" in
    None|"") ok "pagination link is a path (only one page, nothing to check)" ;;
    /api/photos\?cursor=*) ok "pagination link is a path without host" ;;
    *) bad "pagination link is a path without host" "got $NEXT_LINK" ;;
esac

if [ "$(header list x-content-type-options)" = "nosniff" ]; then
    ok "security headers are present"
else
    bad "security headers are present" "X-Content-Type-Options: nosniff is missing"
fi

request updates_bad "$BASE/api/photos/updates" -H 'Accept: application/json'
if [ "$STATUS" = "422" ]; then
    ok "GET /api/photos/updates without a cursor is rejected (422)"
else
    bad "GET /api/photos/updates without a cursor is rejected (422)" "got HTTP $STATUS"
fi

request updates_forged "$BASE/api/photos/updates?cursor=eyJhZnRlcl9pZCI6MH0" -H 'Accept: application/json'
if [ "$STATUS" = "422" ]; then
    ok "GET /api/photos/updates with a forged cursor is rejected (422)"
else
    bad "GET /api/photos/updates with a forged cursor is rejected (422)" "got HTTP $STATUS"
fi

request unknown "$BASE/api/photos/00000000-0000-4000-8000-000000000000" -H 'Accept: application/json'
if [ "$STATUS" = "404" ]; then
    ok "unknown photo id answers 404"
else
    bad "unknown photo id answers 404" "got HTTP $STATUS"
fi

# --- 3. upload validation ----------------------------------------------------

step "3. Upload validation"

request fake -X POST -H 'Accept: application/json' -F "photo=@$WORK/fake.jpg;type=image/jpeg" "$BASE/api/photos"
if [ "$STATUS" = "422" ]; then
    ok "a text file pretending to be a JPEG is rejected (422)"
else
    bad "a text file pretending to be a JPEG is rejected (422)" "got HTTP $STATUS"
fi

request nofile -X POST -H 'Accept: application/json' "$BASE/api/photos"
if [ "$STATUS" = "422" ]; then
    ok "an upload without a file is rejected (422)"
else
    bad "an upload without a file is rejected (422)" "got HTTP $STATUS"
fi

# --- 4. real upload and processing -------------------------------------------

step "4. Upload and processing"

request upload -X POST -H 'Accept: application/json' -F "photo=@$WORK/photo.png;type=image/png" "$BASE/api/photos"
UUID=""
if [ "$STATUS" = "202" ] && UUID="$(jget "$WORK/upload.body" uuid)" && [ -n "$UUID" ]; then
    ok "upload accepted with HTTP 202 (uuid $UUID)"
    REMAINING="$(header upload x-ratelimit-remaining)"
    [ -n "$REMAINING" ] && printf '        uploads left for this IP this hour: %s\n' "$REMAINING"
elif [ "$STATUS" = "429" ]; then
    bad "upload accepted with HTTP 202" "rate limit reached (HTTP 429). Wait, or raise PHOTOS_UPLOAD_RATE_LIMIT in .env"
elif [ "$STATUS" = "413" ]; then
    bad "upload accepted with HTTP 202" "HTTP 413: nginx client_max_body_size is too small somewhere in the chain"
else
    bad "upload accepted with HTTP 202" "got HTTP $STATUS: $(head -c 300 "$WORK/upload.body")"
fi

if [ -z "$UUID" ]; then
    printf '\nUpload failed, so the processing and download checks are skipped.\n'
else
    START="$(date +%s)"
    PROCESSED=0
    while :; do
        request photo "$BASE/api/photos/$UUID" -H 'Accept: application/json'
        if [ "$STATUS" = "200" ]; then
            PROCESSED=1
            break
        fi
        if [ "$STATUS" != "404" ]; then
            break
        fi
        if [ $(( $(date +%s) - START )) -ge "$PROCESS_TIMEOUT" ]; then
            break
        fi
        sleep 1
    done
    ELAPSED=$(( $(date +%s) - START ))

    if [ "$PROCESSED" -eq 1 ]; then
        ok "queue worker processed the photo in about ${ELAPSED}s"
    elif [ "$STATUS" = "404" ]; then
        bad "queue worker processed the photo" "still pending after ${PROCESS_TIMEOUT}s. Is the worker running? docker compose logs app --tail=50"
    else
        bad "queue worker processed the photo" "got HTTP $STATUS while waiting"
    fi

    if [ "$PROCESSED" -eq 1 ]; then
        THUMB_URL="$(jget "$WORK/photo.body" data.thumb_url)"
        WEB_URL="$(jget "$WORK/photo.body" data.web_url)"
        WIDTH="$(jget "$WORK/photo.body" data.width)"

        if [ "$WIDTH" = "160" ]; then
            ok "stored width matches the uploaded image (160 px)"
        else
            bad "stored width matches the uploaded image (160 px)" "got width '$WIDTH'"
        fi

        EXPECTED_AUTHORITY="$(authority_of "$BASE")"
        ACTUAL_AUTHORITY="$(authority_of "$WEB_URL")"
        if [ -z "$ACTUAL_AUTHORITY" ]; then
            ok "image URLs are root-relative and work on any host"
        elif [ "$EXPECTED_AUTHORITY" = "$ACTUAL_AUTHORITY" ]; then
            ok "image URLs point at the address you are testing ($ACTUAL_AUTHORITY)"
        else
            warn "image URLs point at a different address" "tested $EXPECTED_AUTHORITY but the app builds links for $ACTUAL_AUTHORITY. If guests use $EXPECTED_AUTHORITY, fix APP_URL in .env"
        fi

        for kind in thumb web; do
            if [ "$kind" = "thumb" ]; then URL="$THUMB_URL"; else URL="$WEB_URL"; fi
            request "img_$kind" "$BASE$(path_of "$URL")"
            TYPE="$(header "img_$kind" content-type)"
            if [ "$STATUS" = "200" ] && [ "${TYPE#image/webp}" != "$TYPE" ]; then
                ok "$kind image loads through nginx as WebP"
            else
                bad "$kind image loads through nginx as WebP" "HTTP $STATUS, content-type '$TYPE', path $(path_of "$URL")"
            fi
        done

        # --- 5. appears in gallery and live updates ----------------------------

        step "5. Gallery and live updates"

        request list2 "$BASE/api/photos" -H 'Accept: application/json'
        if list_has "$WORK/list2.body" data uuid "$UUID"; then
            ok "the photo shows up in the gallery list"
        else
            bad "the photo shows up in the gallery list"
        fi

        request updates "$BASE/api/photos/updates" --get --data-urlencode "cursor=$CURSOR_BEFORE" -H 'Accept: application/json'
        if [ "$STATUS" = "200" ] && list_has "$WORK/updates.body" data uuid "$UUID"; then
            ok "the photo shows up in the live-update feed"
        else
            bad "the photo shows up in the live-update feed" "HTTP $STATUS"
        fi

        ETAG="$(header updates etag)"
        if [ -n "$ETAG" ]; then
            request updates304 "$BASE/api/photos/updates" --get --data-urlencode "cursor=$CURSOR_BEFORE" -H 'Accept: application/json' -H "If-None-Match: $ETAG"
            if [ "$STATUS" = "304" ]; then
                ok "unchanged live-update feed answers 304 (saves bandwidth)"
            else
                bad "unchanged live-update feed answers 304" "got HTTP $STATUS"
            fi
        else
            bad "live-update feed sends an ETag"
        fi

        # --- 6. download -----------------------------------------------------

        step "6. Download"

        request download "$BASE/api/photos/$UUID/download"
        DISPOSITION="$(header download content-disposition)"
        if [ "$STATUS" = "200" ]; then
            ok "download endpoint answers 200"
        else
            bad "download endpoint answers 200" "got HTTP $STATUS: $(head -c 200 "$WORK/download.body")"
        fi

        case "$DISPOSITION" in
            attachment*) ok "download is sent as an attachment ($DISPOSITION)" ;;
            *) bad "download is sent as an attachment" "Content-Disposition was '$DISPOSITION'" ;;
        esac

        # By default the server hands out a high quality JPEG copy; with
        # PHOTOS_KEEP_ORIGINALS=true it hands out the untouched upload (.png here).
        MAGIC="$(head -c 3 "$WORK/download.body" | od -An -tx1 | tr -d ' \n')"

        if cmp -s "$WORK/photo.png" "$WORK/download.body"; then
            case "$DISPOSITION" in
                *.png*) ok "download is the untouched original (originals are kept)" ;;
                *) bad "download file name has the right extension (.png)" "Content-Disposition was '$DISPOSITION'" ;;
            esac
        elif [ "$MAGIC" = "ffd8ff" ]; then
            case "$DISPOSITION" in
                *.jpg*) ok "download is a JPEG copy with a .jpg name" ;;
                *) bad "download JPEG has a .jpg name" "Content-Disposition was '$DISPOSITION'" ;;
            esac
        else
            bad "download is the original or a JPEG copy" "the file starts with bytes '$MAGIC', which is neither the upload nor a JPEG"
        fi
    fi
fi

request download_missing "$BASE/api/photos/00000000-0000-4000-8000-000000000000/download" -H 'Accept: application/json'
if [ "$STATUS" = "404" ]; then
    ok "downloading an unknown photo answers 404"
else
    bad "downloading an unknown photo answers 404" "got HTTP $STATUS"
fi

# --- summary -----------------------------------------------------------------

printf '\n----------------------------------------\n'
printf 'Passed: %s   Failed: %s   Warnings: %s\n' "$PASSED" "$FAILED" "$WARNED"
if [ -n "${UUID:-}" ]; then
    printf 'Test photo left in the gallery: %s\n' "$UUID"
fi

if [ "$FAILED" -gt 0 ]; then
    printf '\033[31mSMOKE TEST FAILED\033[0m\n'
    exit 1
fi

printf '\033[32mSMOKE TEST PASSED\033[0m\n'
