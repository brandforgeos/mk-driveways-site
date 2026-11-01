#!/usr/bin/env python3
"""Fetch a quote brief's photos so you can look at them.

    python3 photos.py <brief_uuid> [out_dir]

No key needed: makes a one-off key pair, sends the public half with the request; the hub seals the photos to it.

Pushes requests/<brief>.json to the qb-outbox branch; the "Quote builder photos" GitHub Actions workflow downloads the
brief's photos from the hub (already AES-256-GCM encrypted by the hub) to inbox/<brief>.bin. This waits for that file
(up to ~6 minutes), decrypts it and writes, per photo, <n>-marked.jpg (Lewis's mark-up, or the photo if none),
<n>-original.jpg when marked, plus photos.json with each photo's note and which message it came with.
Director-approved before/after visuals land in visuals/ (NN-before.jpg, NN-after.jpg, visuals.json with each title).
Then Read each image. The marked copy shows what Lewis drew; his note says what it means.
"""
import base64, json, os, re, subprocess, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from qbcrypto import new_keypair, open_sealed

REPO = 'https://github.com/brandforgeos/mk-driveways-site'
WORK = os.path.expanduser('~/qb-outbox')


def git(*a):
    return subprocess.run(['git', *a], cwd=WORK, check=True, capture_output=True, text=True).stdout


def main(brief, out):
    priv, pub = new_keypair()
    if not os.path.isdir(os.path.join(WORK, '.git')):
        subprocess.run(['git', 'clone', '--depth', '1', '--branch', 'qb-outbox', '--single-branch', REPO, WORK], check=True, capture_output=True, timeout=600)
    git('pull', '-q', '--rebase', 'origin', 'qb-outbox')
    inbox = os.path.join(WORK, 'inbox', brief + '.bin')
    before = os.path.getmtime(inbox) if os.path.exists(inbox) else 0
    old = open(inbox, 'rb').read() if os.path.exists(inbox) else None
    os.makedirs(os.path.join(WORK, 'requests'), exist_ok=True)
    open(os.path.join(WORK, 'requests', brief + '.json'), 'w').write(json.dumps({'brief': brief, 'pub': pub, 'at': time.time()}))
    git('add', 'requests/' + brief + '.json')
    git('-c', 'user.name=Claude', '-c', 'user.email=noreply@anthropic.com', 'commit', '-qm', 'qb-photos request')
    for _ in range(3):
        try:
            git('push', '-q', 'origin', 'qb-outbox'); break
        except subprocess.CalledProcessError:
            git('pull', '-q', '--rebase', 'origin', 'qb-outbox')
    deadline = time.time() + 400
    while time.time() < deadline:
        time.sleep(15)
        git('pull', '-q', '--rebase', 'origin', 'qb-outbox')
        req_gone = not os.path.exists(os.path.join(WORK, 'requests', brief + '.json'))
        if req_gone and os.path.exists(inbox) and open(inbox, 'rb').read() != old:
            break
    else:
        raise SystemExit('Timed out waiting for the photos workflow (check the "Quote builder photos" Action on qb-outbox).')
    blob = open(inbox, 'rb').read()
    data = json.loads(open_sealed(blob, priv))
    os.makedirs(out, exist_ok=True)
    meta = []
    for i, f in enumerate(data['files'], 1):
        ext = 'png' if 'png' in (f.get('mime') or '') else 'jpg'
        p = os.path.join(out, f'{i:02d}-marked.{ext}')
        open(p, 'wb').write(base64.b64decode(f['data_b64']))
        o = None
        if f.get('original_b64'):
            o = os.path.join(out, f'{i:02d}-original.{ext}')
            open(o, 'wb').write(base64.b64decode(f['original_b64']))
        meta.append({'n': i, 'file': p, 'original': o, 'marked': f.get('marked'), 'note': f.get('note'), 'message_id': f.get('message_id'), 'name': f.get('name'), 'created_at': f.get('created_at')})
    json.dump(meta, open(os.path.join(out, 'photos.json'), 'w'), indent=1)
    # director-APPROVED before/after visuals only (the hub never sends unapproved ones) -> visuals/NN-before.jpg / NN-after.jpg
    vis = []
    if data.get('visuals'):
        vd = os.path.join(out, 'visuals'); os.makedirs(vd, exist_ok=True)
        for i, v in enumerate(data['visuals'], 1):
            b = os.path.join(vd, f'{i:02d}-before.jpg'); a = os.path.join(vd, f'{i:02d}-after.jpg')
            open(b, 'wb').write(base64.b64decode(v['before_b64'])); open(a, 'wb').write(base64.b64decode(v['after_b64']))
            vis.append({'n': i, 'title': v.get('title'), 'before': b, 'after': a, 'round': v.get('round'), 'approved_at': v.get('approved_at')})
        json.dump(vis, open(os.path.join(vd, 'visuals.json'), 'w'), indent=1)
    print(json.dumps({'photos': meta, 'approved_before_after': vis}, indent=1))


if __name__ == '__main__':
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    args = [a for a in sys.argv[2:] if not re.fullmatch(r'[0-9a-fA-F]{64}', a)]   # an old-style key argument is ignored
    main(sys.argv[1], args[0] if args else os.path.join(os.getcwd(), 'photos', sys.argv[1]))
