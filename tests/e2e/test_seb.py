"""SEB-Datei herunterladen: unverschlüsselt (XML-plist) und mit Dateipasswort (Präfix pswd).

Die verschlüsselte Datei wird hier unabhängig vom PHP-Code entschlüsselt (hashlib, hmac und das
openssl-Kommando), nach dem Format von SEB für Windows (RNCryptor v2).
"""
from __future__ import annotations

import gzip
import hashlib
import hmac
import shutil
import subprocess

import pytest

from conftest import BASE, TEACHER, login, new_context

EXAM = 3


def download(page, settings_password: str = "") -> bytes:
    page.goto(f"{BASE}/klausuren/{EXAM}/seb")
    if settings_password:
        page.fill("#settings_password", settings_password)
    with page.expect_download() as info:
        page.get_by_role("button", name="seb-Datei herunterladen").click()
    with open(info.value.path(), "rb") as handle:
        return handle.read()


def decrypt(data: bytes, password: str) -> str:
    outer = gzip.decompress(data)
    assert outer[:4] == b"pswd"
    message = outer[4:]
    assert message[:2] == b"\x02\x01"
    pw = password.encode("utf-8")
    body, mac = message[:-32], message[-32:]
    mac_key = hashlib.pbkdf2_hmac("sha1", pw, message[10:18], 10000, 32)
    assert hmac.compare_digest(hmac.new(mac_key, body, hashlib.sha256).digest(), mac), "HMAC passt nicht"
    enc_key = hashlib.pbkdf2_hmac("sha1", pw, message[2:10], 10000, 32)
    plain = subprocess.run(["openssl", "enc", "-d", "-aes-256-cbc", "-K", enc_key.hex(), "-iv", message[18:34].hex()],
                           input=body[34:], capture_output=True, check=True).stdout
    return gzip.decompress(plain).decode("utf-8")


def test_seb_datei_unverschluesselt(browser):
    page = login(new_context(browser), *TEACHER)
    xml = download(page).decode("utf-8")
    assert xml.startswith("<?xml")
    assert f"/klausur/{EXAM}" in xml


@pytest.mark.skipif(shutil.which("openssl") is None, reason="openssl-Kommando fehlt")
def test_seb_datei_mit_passwort(browser):
    page = login(new_context(browser), *TEACHER)
    data = download(page, "Dateipass-Ä1")
    assert data[:2] == b"\x1f\x8b"
    assert b"startURL" not in gzip.decompress(data)
    xml = decrypt(data, "Dateipass-Ä1")
    assert xml.startswith("<?xml")
    assert f"/klausur/{EXAM}" in xml
