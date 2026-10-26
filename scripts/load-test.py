#!/usr/bin/env python3
"""
Load test for a RUNNING photo gallery: many phones upload at the same moment
while many guests keep the gallery open, like the cake moment at a party.

Each fake phone keeps its own device cookie (as a real phone does), uploads
its photos one after another with an upload id, and the test then waits until
the server has processed every photo. Meanwhile the viewers poll the gallery
every 5 seconds like open pages do.

Usage (ROOM is the code at the end of a room link, /r/ROOM; make a test
event in /admin for it and delete it afterwards):
  python3 scripts/load-test.py https://your-server --room ROOM --photo ~/Pictures/phone.jpg
  python3 scripts/load-test.py https://your-server --room ROOM --photo phone.jpg --phones 100 --photos-per-phone 2 --viewers 200
  python3 scripts/load-test.py https://your-server --room ROOM --photo phone.jpg --admin-token TOKEN   # deletes its photos afterwards

  --room CODE         The room to upload into (required).
  --photo FILE        A real phone photo, ideally a JPEG of about 4000 px (what
                      phones send after preparing). Without it a generated
                      1600x1200 PNG is used, which is lighter to process.
  --phones N          Phones uploading at the same time (default 50, max 200).
  --photos-per-phone  Photos each phone uploads (default 3).
  --viewers N         Open galleries polling every 5 seconds (default 100).
  --admin-token T     Delete every uploaded test photo at the end. Without it
                      the test photos stay in the gallery.
  --insecure          Skip the certificate check.

What limits the result:
  - All fake phones come from this computer's IP, so the per-IP safety nets
    apply: 1000 uploads and 200 new phones per hour. The test refuses more.
  - From your own computer, your internet upload speed is usually the limit,
    not the server. Run it once on the server itself against the local port
    (for example http://127.0.0.1:9002) to see what the server alone can do,
    and once from outside to see the whole path.

Needs only python3.
"""

import argparse
import json
import os
import random
import ssl
import statistics
import struct
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

POLL_INTERVAL_S = 5
PROCESS_TIMEOUT_S = 15 * 60
MAX_PHONES = 200
MAX_UPLOADS = 1000

lock = threading.Lock()
results = {"uploads": [], "polls": [], "poll_errors": {}, "upload_errors": {}}
uploaded = []
stop_viewers = threading.Event()


def generated_png(width=1600, height=1200):
    """A noisy PNG that does not compress, so it has a realistic file size."""
    rows = b"".join(b"\x00" + os.urandom(width * 3) for _ in range(height))

    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header) + chunk(b"IDAT", zlib.compress(rows, 1)) + chunk(b"IEND", b"")


def request(context, method, url, body=None, headers=None, timeout=120):
    """Returns (status, headers, body bytes). Status 0 means no answer."""
    req = urllib.request.Request(url, data=body, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=context) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()
    except Exception as error:  # noqa: BLE001 - timeouts, resets, DNS: all count as "no answer"
        return 0, {}, str(error).encode()


def multipart(photo, name, mime, upload_id):
    boundary = uuid.uuid4().hex
    parts = [
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"upload_id\"\r\n\r\n{upload_id}\r\n".encode(),
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"{name}\"\r\n"
        f"Content-Type: {mime}\r\n\r\n".encode(),
        photo,
        f"\r\n--{boundary}--\r\n".encode(),
    ]
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


def phone(args, context, photo, name, mime, start):
    cookie = None
    start.wait()

    for _ in range(args.photos_per_phone):
        body, content_type = multipart(photo, name, mime, str(uuid.uuid4()))
        headers = {"Accept": "application/json", "Content-Type": content_type}
        if cookie:
            headers["Cookie"] = cookie

        began = time.monotonic()
        status, response_headers, response_body = request(context, "POST", f"{args.url}/api/rooms/{args.room}/photos", body, headers)
        took = time.monotonic() - began

        # The cookie is kept by hand: it is marked Secure, and a test against
        # the local http port would otherwise drop it and look like a new phone each time.
        set_cookies = response_headers.get_all("Set-Cookie") if hasattr(response_headers, "get_all") else None
        for value in set_cookies or []:
            if value.startswith("photo_device="):
                cookie = value.split(";", 1)[0]

        with lock:
            results["uploads"].append(took)
            if status == 202:
                uploaded.append(json.loads(response_body)["uuid"])
            else:
                key = f"HTTP {status}" if status else f"no answer ({response_body.decode()[:60]})"
                results["upload_errors"][key] = results["upload_errors"].get(key, 0) + 1


def viewer(args, context):
    time.sleep(random.uniform(0, POLL_INTERVAL_S))
    status, _, body = request(context, "GET", f"{args.url}/api/rooms/{args.room}/photos", headers={"Accept": "application/json"}, timeout=30)
    if status != 200:
        with lock:
            results["poll_errors"][f"gallery HTTP {status}"] = results["poll_errors"].get(f"gallery HTTP {status}", 0) + 1
        return

    cursor = json.loads(body)["updates_cursor"]

    while not stop_viewers.wait(POLL_INTERVAL_S):
        began = time.monotonic()
        url = f"{args.url}/api/rooms/{args.room}/photos/updates?cursor={urllib.parse.quote(cursor, safe='')}"
        status, _, body = request(context, "GET", url, headers={"Accept": "application/json"}, timeout=30)
        took = time.monotonic() - began

        with lock:
            results["polls"].append(took)
            if status == 200:
                cursor = json.loads(body)["cursor"]
            elif status != 304:
                results["poll_errors"][f"HTTP {status}"] = results["poll_errors"].get(f"HTTP {status}", 0) + 1


def wait_for_processing(args, context):
    pending = set(uploaded)
    failed = 0
    began = time.monotonic()

    while pending and time.monotonic() - began < PROCESS_TIMEOUT_S:
        for photo_uuid in list(pending):
            status, _, body = request(context, "GET", f"{args.url}/api/photos/{photo_uuid}/status", timeout=30)
            if status == 200:
                state = json.loads(body)["status"]
                if state != "pending":
                    pending.discard(photo_uuid)
                    failed += state == "failed"
        if pending:
            print(f"  still processing: {len(pending)}", flush=True)
            time.sleep(3)

    return time.monotonic() - began, len(pending), failed


def percentile(values, share):
    ordered = sorted(values)
    return ordered[min(len(ordered) - 1, int(len(ordered) * share))] if ordered else 0


def main():
    parser = argparse.ArgumentParser(description="Load test for the photo gallery.", add_help=False)
    parser.add_argument("url", nargs="?")
    parser.add_argument("--room")
    parser.add_argument("--photo")
    parser.add_argument("--phones", type=int, default=50)
    parser.add_argument("--photos-per-phone", type=int, default=3)
    parser.add_argument("--viewers", type=int, default=100)
    parser.add_argument("--admin-token")
    parser.add_argument("--insecure", action="store_true")
    parser.add_argument("-h", "--help", action="store_true")
    args = parser.parse_args()

    if args.help or not args.url or not args.room:
        print(__doc__)
        return 0 if args.help else 2

    args.url = args.url.rstrip("/")
    total = args.phones * args.photos_per_phone

    if args.phones > MAX_PHONES or total > MAX_UPLOADS:
        print(f"At most {MAX_PHONES} phones and {MAX_UPLOADS} uploads per hour come from one IP (the server's safety net).")
        return 2

    context = ssl._create_unverified_context() if args.insecure else ssl.create_default_context()

    if args.photo:
        with open(os.path.expanduser(args.photo), "rb") as handle:
            photo = handle.read()
        name = os.path.basename(args.photo)
        mime = "image/png" if name.lower().endswith(".png") else "image/webp" if name.lower().endswith(".webp") else "image/jpeg"
    else:
        print("No --photo given: using a generated 1600x1200 PNG. A real 4000 px phone JPEG is heavier to process.")
        photo, name, mime = generated_png(), "load-test.png", "image/png"

    print(f"Target: {args.url}")
    print(f"{args.phones} phones x {args.photos_per_phone} photos = {total} uploads of {len(photo) / 1e6:.1f} MB "
          f"({total * len(photo) / 1e6:.0f} MB in total), {args.viewers} open galleries\n")

    viewers = [threading.Thread(target=viewer, args=(args, context), daemon=True) for _ in range(args.viewers)]
    for thread in viewers:
        thread.start()

    start = threading.Event()
    phones = [threading.Thread(target=phone, args=(args, context, photo, name, mime, start)) for _ in range(args.phones)]
    for thread in phones:
        thread.start()

    print("Uploading...", flush=True)
    began = time.monotonic()
    start.set()
    for thread in phones:
        thread.join()
    upload_time = time.monotonic() - began
    print(f"  all uploads answered after {upload_time:.0f} s\n\nWaiting for processing...", flush=True)

    drain_time, stuck, failed = wait_for_processing(args, context)
    stop_viewers.set()

    uploads = results["uploads"]
    polls = results["polls"]
    accepted = len(uploaded)

    print("\n================ RESULT ================")
    print(f"Uploads accepted:     {accepted} of {total}")
    for key, count in sorted(results["upload_errors"].items()):
        print(f"  refused / failed:   {count} x {key}")
    print(f"Upload time per photo: median {statistics.median(uploads):.1f} s, 95% under {percentile(uploads, 0.95):.1f} s, slowest {max(uploads):.1f} s")
    print(f"Throughput:           {accepted * len(photo) * 8 / 1e6 / upload_time:.0f} Mbit/s for {upload_time:.0f} s")
    print(f"Processing:           all done {drain_time:.0f} s after the last upload, {failed} failed, {stuck} not done after {PROCESS_TIMEOUT_S // 60} min")
    if polls:
        print(f"Gallery polls:        {len(polls)}, median {statistics.median(polls) * 1000:.0f} ms, 95% under {percentile(polls, 0.95) * 1000:.0f} ms")
    for key, count in sorted(results["poll_errors"].items()):
        print(f"  poll errors:        {count} x {key}")
    print("========================================")

    if args.admin_token:
        deleted = sum(
            request(context, "DELETE", f"{args.url}/api/photos/{photo_uuid}", headers={"X-Admin-Token": args.admin_token})[0] == 204
            for photo_uuid in uploaded
        )
        print(f"Deleted {deleted} of {accepted} test photos.")
    elif accepted:
        print(f"{accepted} test photos stay in the gallery. Pass --admin-token to delete them automatically.")

    ok = accepted == total and stuck == 0 and failed == 0 and not results["poll_errors"]
    print("LOAD TEST PASSED" if ok else "LOAD TEST HAD PROBLEMS (see above)")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
