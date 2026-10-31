#!/usr/bin/env python3
"""Courier a finished proposal PDF to the hub.

    python3 send.py proposal.pdf <courier_key_hex>

Encrypts the PDF (AES-256-GCM, key = app_secrets.qb_courier_key, file = 12-byte IV + ciphertext+tag), commits it to the
qb-outbox branch of brandforgeos/mk-driveways-site and pushes. Prints JSON {blob_path, sha256, file_name} to pass to
public.qb_upload_from_blob(brief, file_name, sha256, blob_path), then public.qb_store(upload_id).
The edge function qb-store fetches, decrypts, checks the SHA-256 and saves it in the private documents bucket.
"""
import hashlib, json, os, subprocess, sys, uuid
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

REPO = 'https://github.com/brandforgeos/mk-driveways-site'
WORK = os.path.expanduser('~/qb-outbox')


def git(*a, cwd=WORK):
    return subprocess.run(['git', *a], cwd=cwd, check=True, capture_output=True, text=True).stdout


def main(pdf, key_hex):
    data = open(pdf, 'rb').read()
    assert data[:5] == b'%PDF-', 'not a PDF'
    key = bytes.fromhex(key_hex.strip())
    assert len(key) == 32, 'courier key must be 32 bytes (64 hex characters)'
    iv = os.urandom(12)
    blob = iv + AESGCM(key).encrypt(iv, data, None)
    if not os.path.isdir(os.path.join(WORK, '.git')):
        subprocess.run(['git', 'clone', '--depth', '1', '--branch', 'qb-outbox', '--single-branch', REPO, WORK], check=True, capture_output=True, timeout=600)
    git('pull', '-q', '--rebase', 'origin', 'qb-outbox')
    name = f'{uuid.uuid4().hex}.bin'
    os.makedirs(os.path.join(WORK, 'outbox'), exist_ok=True)
    open(os.path.join(WORK, 'outbox', name), 'wb').write(blob)
    git('add', 'outbox/' + name)
    git('-c', 'user.name=Claude', '-c', 'user.email=noreply@anthropic.com', 'commit', '-qm', 'quote builder outbox')
    for _ in range(3):
        try:
            git('push', '-q', 'origin', 'qb-outbox'); break
        except subprocess.CalledProcessError:
            git('pull', '-q', '--rebase', 'origin', 'qb-outbox')
    else:
        raise SystemExit('push failed')
    print(json.dumps({'blob_path': 'outbox/' + name, 'sha256': hashlib.sha256(data).hexdigest(), 'file_name': os.path.basename(pdf), 'bytes': len(data)}))


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    main(sys.argv[1], sys.argv[2])
