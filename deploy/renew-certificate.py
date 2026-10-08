#!/usr/bin/env python3
"""Run with BaoTa's Python; renew only yuzhou.ourideas.top."""
import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys

DOMAIN = 'yuzhou.ourideas.top'
ROOT = Path('/www/wwwroot/yuzhou')
PANEL = Path('/www/server/panel')

def main():
    os.chdir(str(PANEL))
    sys.path.insert(0, str(PANEL / 'class'))
    from acme_v2 import acme_v2
    certs = ROOT / 'certs'
    certs.mkdir(mode=0o700, exist_ok=True)
    current = certs / 'fullchain.pem'
    if current.exists():
        valid = subprocess.run(['openssl', 'x509', '-checkend', str(30 * 86400), '-noout', '-in', str(current)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if valid.returncode == 0:
            print(json.dumps({'domain': DOMAIN, 'renewed': False, 'valid_for_30_days': True}))
            return
    # Panel's return value contains a private key; keep it out of logs.
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        result = acme_v2().apply_cert([DOMAIN], 'http', str(ROOT / 'acme'))
    if result.get('status') is not True:
        raise RuntimeError('Certificate issuance failed; inspect the protected BaoTa ACME log on the server')
    chain = certs / 'fullchain.pem.new'
    key = certs / 'privkey.pem.new'
    chain.write_text(result['cert'] + result['root'])
    key.write_text(result['private_key'])
    key.chmod(0o600)
    subprocess.run(['openssl', 'x509', '-in', str(chain), '-noout', '-checkhost', DOMAIN], check=True, stdout=subprocess.DEVNULL)
    cert_mod = subprocess.check_output(['openssl', 'x509', '-in', str(chain), '-noout', '-modulus'])
    key_mod = subprocess.check_output(['openssl', 'rsa', '-in', str(key), '-noout', '-modulus'], stderr=subprocess.DEVNULL)
    if cert_mod != key_mod:
        raise RuntimeError('Certificate and private key do not match')
    chain.replace(current)
    key.replace(certs / 'privkey.pem')
    subprocess.run(['/www/server/nginx/sbin/nginx', '-t'], check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['/www/server/nginx/sbin/nginx', '-s', 'reload'], check=True)
    print(json.dumps({'domain': DOMAIN, 'renewed': True, 'private_key_logged': False}))

if __name__ == '__main__':
    main()
