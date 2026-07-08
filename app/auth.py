"""Autenticação: hash de senha (PBKDF2) e tokens de sessão."""
import hashlib
import hmac
import secrets

_ITERATIONS = 200_000


def hash_password(senha: str) -> tuple[str, str]:
    """Retorna (salt_hex, hash_hex)."""
    salt = secrets.token_bytes(16)
    dk = hashlib.pbkdf2_hmac("sha256", senha.encode("utf-8"), salt, _ITERATIONS)
    return salt.hex(), dk.hex()


def verify_password(senha: str, salt_hex: str, hash_hex: str) -> bool:
    dk = hashlib.pbkdf2_hmac("sha256", senha.encode("utf-8"), bytes.fromhex(salt_hex), _ITERATIONS)
    return hmac.compare_digest(dk.hex(), hash_hex)


def new_token() -> str:
    return secrets.token_urlsafe(32)
