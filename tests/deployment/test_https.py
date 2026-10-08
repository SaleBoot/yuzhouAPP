#!/usr/bin/env python3
"""Offline safety checks: these tests perform no network requests."""
import importlib.util
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("yuzhou_https_acceptance", pathlib.Path(__file__).with_name("https.py"))
HTTPS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HTTPS)


class OfflineSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="yuzhou-https-safety-")
        self.addCleanup(self.temp.cleanup)
        self.accounts = pathlib.Path(self.temp.name) / "accounts.json"
        self.values = {"environment": "test", "password": "synthetic-test-password-only",
                       "users": [role + ".demo" for role in HTTPS.ROLES]}
        self.save()

    def save(self):
        self.accounts.write_text(json.dumps(self.values), encoding="utf-8")
        self.accounts.chmod(0o600)

    def test_private_test_accounts(self):
        self.assertEqual(HTTPS.private_accounts(self.accounts), self.values)

    def test_public_account_permissions_reject(self):
        self.accounts.chmod(0o644)
        with self.assertRaises(HTTPS.AcceptanceFailure):
            HTTPS.private_accounts(self.accounts)

    def test_symlink_and_relative_accounts_reject(self):
        link = self.accounts.with_name("link.json")
        link.symlink_to(self.accounts)
        for path in (link, pathlib.Path("accounts.json")):
            with self.assertRaises(HTTPS.AcceptanceFailure):
                HTTPS.private_accounts(path)

    def test_production_or_incomplete_accounts_reject(self):
        for change in ({"environment": "production"}, {"users": ["platform.demo"]}, {"password": "short"}):
            previous = self.values.copy()
            self.values.update(change)
            self.save()
            with self.assertRaises(HTTPS.AcceptanceFailure):
                HTTPS.private_accounts(self.accounts)
            self.values = previous

    def test_oversized_accounts_reject(self):
        self.accounts.write_bytes(b" " * 65537)
        with self.assertRaises(HTTPS.AcceptanceFailure):
            HTTPS.private_accounts(self.accounts)

    def test_missing_write_switch_never_reads_accounts_or_opens_network(self):
        with patch.object(HTTPS.sys, "argv", ["https.py", "--accounts", str(self.accounts)]), \
                patch.object(HTTPS, "private_accounts") as read_accounts, \
                patch.object(HTTPS.urllib.request.OpenerDirector, "open") as open_network, \
                patch.object(HTTPS.sys, "stderr"):
            self.assertEqual(HTTPS.main(), 1)
            read_accounts.assert_not_called()
            open_network.assert_not_called()

    def test_foreign_destinations_reject_without_network(self):
        client = HTTPS.Client()
        with patch.object(client.opener, "open") as network:
            for path in ("//example.com/", "http://example.com/", "https://example.com/", "no-leading-slash"):
                with self.assertRaises(HTTPS.AcceptanceFailure):
                    client.request(path, token="synthetic-token")
            network.assert_not_called()

    def test_redirects_never_forward_authorization(self):
        self.assertIsNone(HTTPS.NoRedirect().redirect_request(None, None, 302, "", {}, "https://example.com/"))

    def test_environment_preflight_blocks_before_mutations(self):
        base = {"environment": "test", "is_test_environment": True, "test_payments_enabled": True,
                "services": {"payment": "unconfigured"}}
        for change in ({"environment": "production"}, {"is_test_environment": False},
                       {"test_payments_enabled": False}, {"services": {"payment": "configured"}}):
            client = HTTPS.Client()
            with patch.object(client, "api", return_value=dict(base, **change)) as api:
                with self.assertRaises(HTTPS.AcceptanceFailure):
                    HTTPS.run(self.values, client)
                api.assert_called_once_with("bootstrap")

    def test_synthetic_pdf_offsets_are_valid(self):
        pdf = HTTPS.synthetic_pdf("synthetic_safety_test")
        self.assertTrue(pdf.startswith(b"%PDF-1.4\n"))
        self.assertIn(b"not a legal contract", pdf)
        xref = int(pdf.split(b"startxref\n")[1].splitlines()[0])
        self.assertTrue(pdf[xref:].startswith(b"xref\n0 6\n"))
        rows = pdf[xref:].splitlines()[3:8]
        for index, row in enumerate(rows, 1):
            offset = int(row.split()[0])
            self.assertTrue(pdf[offset:].startswith(f"{index} 0 obj\n".encode("ascii")))

    def test_zero_rounded_commission_blocks_before_business_writes(self):
        for bps in (0, 1, 2):
            client = HTTPS.Client()
            def response(path, method="GET", body=None, token=None):
                if path == "bootstrap":
                    return {"environment": "test", "is_test_environment": True, "test_payments_enabled": True,
                            "services": {"payment": "unconfigured"}}
                if path == "health": return {"database": "mysql", "framework": "ThinkPHP 5.1.41 LTS"}
                if path == "auth/login":
                    return {"user": {"id": 1, "role": body["username"].split(".")[0], "org_id": 1}, "token": "synthetic"}
                if path == "settings": return {"version": 1, "distribution": {"broker_bps": bps}}
                self.fail("Business write occurred before distribution preflight")
            with patch.object(client, "api", side_effect=response):
                with self.assertRaises(HTTPS.AcceptanceFailure): HTTPS.run(self.values, client)


if __name__ == "__main__":
    unittest.main()
