"""Sealed boxes for the quote builder ("QB2"): X25519 + HKDF-SHA256 + AES-256-GCM.
Blob = b"QB2" | ephemeral public key (32) | IV (12) | ciphertext+tag. Matches the hub's edge functions (seal.ts).
Sealing needs only the recipient's PUBLIC key, so the scheduled run never has to read a secret from the hub."""
import os
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey, X25519PublicKey
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives import hashes, serialization as S

MAGIC = b'QB2'
_raw = lambda k: k.public_bytes(S.Encoding.Raw, S.PublicFormat.Raw)


def _key(shared, eph_pub, rcpt_pub):
    return HKDF(algorithm=hashes.SHA256(), length=32, salt=eph_pub + rcpt_pub, info=b'bbhub-qb-v2').derive(shared)


def seal(plain: bytes, rcpt_pub_hex: str) -> bytes:
    rcpt = bytes.fromhex(rcpt_pub_hex.strip())
    eph = X25519PrivateKey.generate(); eph_pub = _raw(eph.public_key())
    k = _key(eph.exchange(X25519PublicKey.from_public_bytes(rcpt)), eph_pub, rcpt)
    iv = os.urandom(12)
    return MAGIC + eph_pub + iv + AESGCM(k).encrypt(iv, plain, None)


def new_keypair():
    """A one-off key pair for this run (the private half never leaves this machine)."""
    k = X25519PrivateKey.generate()
    return k, _raw(k.public_key()).hex()


def open_sealed(blob: bytes, priv: X25519PrivateKey) -> bytes:
    assert blob[:3] == MAGIC, 'not a sealed box'
    eph_pub, iv, ct = blob[3:35], blob[35:47], blob[47:]
    k = _key(priv.exchange(X25519PublicKey.from_public_bytes(eph_pub)), eph_pub, _raw(priv.public_key()))
    return AESGCM(k).decrypt(iv, ct, None)
