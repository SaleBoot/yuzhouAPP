#!/usr/bin/env python3
"""Synthetic HTTPS acceptance for the one deployed test site, using only stdlib.

Run after deployment:
  python3 tests/deployment/https.py --accounts /private/accounts.json --allow-test-writes

Accounts JSON: {"environment":"test","password":"...","users":["platform.demo", ...]}.
This checks the existing full-payment flow, not staged payments or real providers.
It never changes global settings, old business fixtures, or membership expiry dates.
"""
import argparse
import datetime
import hashlib
import json
import pathlib
import re
import secrets
import ssl
import stat
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = "https://yuzhou.ourideas.top"
ROLES = ("platform", "merchant", "agency", "broker", "consumer")


class AcceptanceFailure(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        # In particular, never forward a bearer token or password through redirects.
        return None


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context())
        )
        self.checks = 0
        self.stage = "safety preflight"

    def check(self, condition, label):
        if not condition:
            raise AcceptanceFailure(label)
        self.checks += 1

    def request(self, path, method="GET", body=None, token=None, headers=None):
        if not isinstance(path, str) or not path.startswith("/") or path.startswith("//"):
            raise AcceptanceFailure("Only same-origin paths are permitted")
        destination = BASE_URL + path
        parsed = urllib.parse.urlsplit(destination)
        if parsed.scheme != "https" or parsed.netloc != "yuzhou.ourideas.top":
            raise AcceptanceFailure("Destination rejected")
        request_headers = dict(headers or {})
        if isinstance(body, dict):
            body = json.dumps(body, ensure_ascii=False).encode("utf-8")
            request_headers["Content-Type"] = "application/json"
        if token:
            request_headers["Authorization"] = "Bearer " + token
        request = urllib.request.Request(destination, data=body, method=method, headers=request_headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            data = response.read(5 * 1024 * 1024 + 1)
            if len(data) > 5 * 1024 * 1024:
                raise AcceptanceFailure("Response exceeds acceptance bound")
            return response.status, response.headers, data

    def api(self, path, method="GET", body=None, token=None, expected=200):
        status, _, raw = self.request("/api/v1/" + path, method, body, token)
        self.check(status == expected, "Unexpected API HTTP status")
        try:
            response = json.loads(raw)
        except (ValueError, UnicodeDecodeError):
            raise AcceptanceFailure("API envelope is not valid JSON") from None
        self.check(isinstance(response, dict) and "code" in response and "data" in response, "API envelope missing")
        self.check(response["code"] == 0 if expected == 200 else response["code"] != 0, "API result code mismatch")
        return response["data"]

    def upload_pdf(self, token, tag):
        pdf = synthetic_pdf(tag)
        boundary = "YuzhouAcceptance" + secrets.token_hex(16)
        body = (
            f'--{boundary}\r\nContent-Disposition: form-data; name="private"\r\n\r\n1\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{tag}.pdf"\r\n'
            "Content-Type: application/pdf\r\n\r\n"
        ).encode("ascii") + pdf + f"\r\n--{boundary}--\r\n".encode("ascii")
        status, _, raw = self.request("/api/v1/uploads", "POST", body, token,
                                      {"Content-Type": "multipart/form-data; boundary=" + boundary})
        self.check(status == 200, "Synthetic private upload failed")
        result = json.loads(raw)
        self.check(result["code"] == 0, "Private upload envelope failed")
        uploaded = result["data"]
        self.check(uploaded["private"] and uploaded["mime"] == "application/pdf"
                   and uploaded["sha256"] == hashlib.sha256(pdf).hexdigest(), "Private PDF metadata mismatch")
        self.check(re.fullmatch(r"/api/v1/files/[0-9]+", uploaded["url"]) is not None,
                   "File URL is outside the private API")
        return uploaded, pdf

    def order_ledger(self, token, user_id, order_id):
        # The current API filters beneficiary, not order; inspect only that authorized scope.
        found = []
        for page in range(1, 101):
            result = self.api(f"ledger?user_id={user_id}&page_size=100&page={page}", token=token)
            found.extend(row for row in result["items"] if int(row["order_id"]) == order_id)
            if page * int(result["page_size"]) >= int(result["total"]):
                return found
        raise AcceptanceFailure("Ledger scope exceeds acceptance pagination bound")

    def upload_video(self, token, tag):
        video = (pathlib.Path(__file__).parent.parent / "fixtures/black-1s.mp4").read_bytes()
        boundary = "YuzhouMedia" + secrets.token_hex(16)
        body = (
            f'--{boundary}\r\nContent-Disposition: form-data; name="private"\r\n\r\n0\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{tag}.mp4"\r\n'
            "Content-Type: video/mp4\r\n\r\n"
        ).encode("ascii") + video + f"\r\n--{boundary}--\r\n".encode("ascii")
        status, _, raw = self.request("/api/v1/uploads", "POST", body, token,
                                      {"Content-Type": "multipart/form-data; boundary=" + boundary})
        self.check(status == 200, "Synthetic media upload failed")
        response = json.loads(raw)
        self.check(response["code"] == 0, "Synthetic media upload envelope failed")
        result = response["data"]
        self.check(result["private"] is False and result["mime"] == "video/mp4"
                   and result["sha256"] == hashlib.sha256(video).hexdigest(), "Public video upload metadata mismatch")
        self.check(re.fullmatch(r"/api/v1/files/[1-9][0-9]*", result["url"]) is not None, "Noncanonical uploaded video URL")
        return result, video


def synthetic_pdf(tag):
    text = "Synthetic Yuzhou HTTPS acceptance only - not a legal contract. " + tag
    stream = f"BT /F1 10 Tf 36 760 Td ({text}) Tj ET\n".encode("ascii")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        b"<< /Length " + str(len(stream)).encode("ascii") + b" >>\nstream\n" + stream + b"endstream",
    ]
    document = b"%PDF-1.4\n"
    offsets = [0]
    for index, obj in enumerate(objects, 1):
        offsets.append(len(document))
        document += str(index).encode("ascii") + b" 0 obj\n" + obj + b"\nendobj\n"
    xref = len(document)
    document += b"xref\n0 6\n0000000000 65535 f \n"
    document += b"".join(f"{offset:010d} 00000 n \n".encode("ascii") for offset in offsets[1:])
    return document + f"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode("ascii")


def private_accounts(path):
    if not path.is_absolute() or path.is_symlink():
        raise AcceptanceFailure("Accounts path must be an absolute private regular file")
    metadata = path.stat()
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_mode & 0o077 or metadata.st_size > 65536:
        raise AcceptanceFailure("Accounts file must be private and bounded")
    accounts = json.loads(path.read_text(encoding="utf-8"))
    expected_users = {role + ".demo" for role in ROLES}
    if (accounts.get("environment") != "test" or not isinstance(accounts.get("password"), str)
            or len(accounts["password"]) < 12 or not isinstance(accounts.get("users"), list)
            or len(accounts["users"]) != len(ROLES) or not all(isinstance(name, str) for name in accounts["users"])
            or set(accounts["users"]) != expected_users):
        raise AcceptanceFailure("Test accounts configuration rejected")
    return accounts


def html_and_secret_boundaries(client):
    client.stage = "HTML5 and secret path boundaries"
    assets = set()
    for path in ("/consumer/", "/broker/", "/admin/platform", "/admin/merchant", "/admin/agency"):
        status, headers, raw = client.request(path)
        client.check(status == 200 and "text/html" in headers.get("Content-Type", ""), "HTML5 entry missing")
        html = raw.decode("utf-8")
        scripts = re.findall(r'<script\b[^>]*\bsrc=["\']([^"\']+)["\']', html, re.I)
        client.check(bool(scripts), "HTML5 entry has no compiled script")
        for script in scripts:
            target = urllib.parse.urlsplit(urllib.parse.urljoin(BASE_URL + path, script))
            if target.scheme == "https" and target.netloc == "yuzhou.ourideas.top":
                assets.add(target.path + ("?" + target.query if target.query else ""))
    for path in sorted(assets):
        status, headers, raw = client.request(path)
        client.check(status == 200 and len(raw) > 100 and "html" not in headers.get("Content-Type", ""),
                     "Compiled entry asset unavailable")
    for path in ("/.env", "/.git/config", "/backend/.env", "/backend/storage/dev-accounts.json",
                 "/storage/development.sqlite", "/thinkphp/base.php", "/index.php"):
        status, _, _ = client.request(path)
        client.check(status in (403, 404), "Secret or source path exposed")


def run(accounts, client):
    bootstrap = client.api("bootstrap")
    client.check(bootstrap.get("environment") == "test" and bootstrap.get("is_test_environment") is True
                 and bootstrap.get("test_payments_enabled") is True, "Only explicit deployed test payment mode is allowed")
    client.check(bootstrap.get("services", {}).get("payment") == "unconfigured", "Real provider configuration is outside this script")
    client.check(bootstrap.get("services", {}).get("sms") == "unconfigured", "Configured SMS is outside this synthetic script")
    health = client.api("health")
    client.check(health.get("database") == "mysql" and health.get("framework") == "ThinkPHP 5.1.41 LTS", "Deployed runtime baseline mismatch")
    client.stage = "five demo role authentication"
    sessions = {}
    for role in ROLES:
        session = client.api("auth/login", "POST", {"username": role + ".demo", "password": accounts["password"]})
        client.check(session["user"]["role"] == role and isinstance(session["token"], str), "Demo role authentication mismatch")
        client.check(isinstance(session["user"].get("phone_bound"), bool), "Verified phone status is missing")
        sessions[role] = session
    platform, merchant, agency, broker = (sessions[role]["token"] for role in ROLES[:4])
    outsider = sessions["consumer"]["token"]
    broker_id = int(sessions["broker"]["user"]["id"])
    client.check(sessions["broker"]["user"]["org_id"] is not None
                 and sessions["broker"]["user"]["org_id"] == sessions["agency"]["user"]["org_id"],
                 "Broker and agency demo scopes differ")
    settings = client.api("settings", token=platform)
    bps = int(settings["distribution"]["broker_bps"])
    client.check(int(settings["version"]) > 0 and 0 < bps <= 10000 and 4000 * bps // 10000 > 0,
                 "An existing test rule yielding commission on 4000 cents is required; script does not configure it")
    client.stage = "unconfigured SMS refuses send and verification"
    for endpoint, body in (
        ("auth/sms", {"phone": "13900000000", "purpose": "login"}),
        ("auth/sms/verify", {"phone": "13900000000", "purpose": "login", "challenge_id": "a" * 64, "code": "000001"}),
    ):
        client.check(client.api(endpoint, "POST", body, expected=503) is None, "Unconfigured SMS returned a challenge or session")
    html_and_secret_boundaries(client)

    # All business mutations below create or update only this new random fixture family.
    tag = "https_synth_" + secrets.token_hex(8)
    customer_name = tag + ".customer"
    customer_password = secrets.token_urlsafe(24)
    client.stage = "new synthetic customer and private PDF"
    customer = client.api("auth/register", "POST", {"username": customer_name, "password": customer_password,
                                                    "nickname": "合成HTTPS验收消费者 " + tag})
    customer_token, customer_id = customer["token"], int(customer["user"]["id"])
    client.stage = "canonical public media publication and range delivery"
    video_file, video = client.upload_video(customer_token, tag + "_black_video")
    status, _, _ = client.request(video_file["url"])
    client.check(status == 403, "Unpublished public video is anonymously readable")
    client.api("resources/posts", "POST", {"title": "Synthetic inaccessible media rejection " + tag,
               "payload": {"content": "Synthetic media boundary", "video_url": video_file["url"]}}, outsider, expected=403)
    pending_post = client.api("resources/posts", "POST", {"title": "合成HTTPS视频 " + tag,
                              "payload": {"content": "One-second black synthetic acceptance clip", "video_url": video_file["url"]}}, customer_token)
    client.check(pending_post["status"] == "pending", "Consumer video bypassed moderation")
    status, _, _ = client.request(video_file["url"])
    client.check(status == 403, "Pending video became publicly readable")
    published_post = client.api(f"resources/posts/{pending_post['id']}", "PATCH",
                               {"version": pending_post["version"], "status": "published"}, platform)
    status, headers, raw = client.request(video_file["url"])
    client.check(status == 200 and raw == video and "video/mp4" in headers.get("Content-Type", ""),
                 "Published canonical video bytes are unavailable")
    status, headers, raw = client.request(video_file["url"], headers={"Range": "bytes=0-15"})
    client.check(status == 206 and raw == video[:16] and headers.get("Content-Range") == f"bytes 0-15/{len(video)}",
                 "Published video range delivery failed")
    client.api(f"resources/posts/{pending_post['id']}", "PATCH",
               {"version": published_post["version"], "status": "archived"}, platform)
    status, _, _ = client.request(video_file["url"])
    client.check(status == 403, "Archived video retains anonymous access")
    client.stage = "new synthetic customer and private PDF"
    uploaded, pdf = client.upload_pdf(broker, tag + "_contract")
    for token, expected in ((broker, 200), (None, 403), (outsider, 403), (merchant, 403)):
        status, headers, raw = client.request(uploaded["url"], token=token)
        client.check(status == expected, "Private PDF access boundary failed")
        if expected == 200:
            client.check(raw == pdf and "application/pdf" in headers.get("Content-Type", ""), "Private PDF content mismatch")
    status, headers, raw = client.request(uploaded["url"], token=broker, headers={"Range": "bytes=0-7"})
    client.check(status == 206 and raw == pdf[:8] and headers.get("Content-Range") == f"bytes 0-7/{len(pdf)}",
                 "Authorized private range download failed")
    lead = client.api("customers", "POST", {"user_id": customer_id, "name": "合成HTTPS客户 " + tag,
                                            "notes": "Synthetic acceptance only " + tag}, broker)
    client.check(int(lead["broker_id"]) == broker_id, "Synthetic customer assignment mismatch")
    service = client.api("resources/maintenance", "POST", {"title": "合成HTTPS服务 " + tag, "price_cents": 10000,
                                                           "payload": {"description": "Synthetic acceptance only " + tag}}, merchant)
    client.check(service["status"] == "pending", "Merchant service bypassed publication review")
    service = client.api(f"resources/maintenance/{service['id']}", "PATCH", {"version": service["version"], "status": "published"}, platform)
    client.check(service["status"] == "published" and service["merchant_credit_code"] is not None, "New service publication lost supplier scope")

    client.stage = "broker order quote and private contract"
    order = client.api("orders", "POST", {"type": "maintenance", "resource_id": int(service["id"]),
                                          "customer_id": customer_id, "notes": "Synthetic acceptance only " + tag}, broker)
    order_id = int(order["id"])
    client.check(order["status"] == "awaiting_quote" and order["broker_id"] == broker_id, "Broker order not created in expected scope")

    def supplier_denied():
        client.api(f"orders/{order_id}", token=merchant, expected=404)
        client.api(f"orders/{order_id}/contracts", token=merchant, expected=404)
        status, _, _ = client.request(uploaded["url"], token=merchant)
        client.check(status == 403, "Supplier can read private broker contract")

    supplier_denied()
    client.api(f"orders/{order_id}/quote", "POST", {"amount_cents": 10000, "notes": "Synthetic confirmed quote " + tag}, broker)
    client.api(f"orders/{order_id}/accept-quote", "POST", {}, customer_token)
    contract = client.api(f"orders/{order_id}/contract", "POST", {"file_id": int(uploaded["id"]), "title": "Synthetic contract " + tag}, broker)
    client.api(f"orders/{order_id}/contract-review", "POST", {"version": int(contract["version"]), "status": "approved", "notes": "Synthetic material review only"}, platform)
    client.api(f"orders/{order_id}/sign", "POST", {"version": int(contract["version"]), "accepted": True}, customer_token)
    status, _, raw = client.request(uploaded["url"], token=customer_token)
    client.check(status == 200 and raw == pdf, "Order customer cannot read current private contract")
    supplier_denied()
    client.api(f"orders/{order_id}/pay", "POST", {"channel": "wechat", "idempotency_key": tag + "_unconfigured_pay"}, customer_token, expected=503)
    rejected_payment = client.api(f"orders/{order_id}", token=customer_token)
    client.check(rejected_payment["paid_cents"] == 0, "Unconfigured provider created a payment")
    paid = client.api(f"orders/{order_id}/pay", "POST", {"channel": "test", "idempotency_key": tag + "_pay"}, customer_token)
    client.check(paid["order"]["paid_cents"] == 10000 and paid["payment"]["channel"] == "test", "Guarded full payment mismatch")
    replay = client.api(f"orders/{order_id}/pay", "POST", {"channel": "test", "idempotency_key": tag + "_pay"}, customer_token)
    client.check(replay["payment"]["id"] == paid["payment"]["id"], "Payment replay did not retain one receipt")
    supplier_denied()
    client.api(f"orders/{order_id}/fulfill", "POST", {"status": "fulfilling", "notes": "Synthetic agency fulfillment"}, agency)
    client.api(f"orders/{order_id}/fulfill", "POST", {"status": "completed", "notes": "Synthetic agency completion"}, agency)

    client.stage = "manual profit and successful partial refund freeze"
    profit = client.api(f"orders/{order_id}/profit-confirm", "POST", {"profit_cents": 4000, "notes": "Synthetic manually confirmed profit",
                        "beneficiaries": [{"user_id": broker_id, "role": "broker"}]}, platform)
    snapshot = profit["rule_snapshot"]
    ledger = client.order_ledger(platform, broker_id, order_id)
    client.check(sum(int(row["amount_cents"]) for row in ledger) == 4000 * bps // 10000, "Initial profit distribution mismatch")
    request = client.api(f"orders/{order_id}/refund-request", "POST", {"amount_cents": 2000, "reason": "Synthetic partial cancellation"}, customer_token)
    client.api(f"orders/{order_id}/refund", "POST", {"request_id": int(request["id"]), "amount_cents": 2000, "reason": "Synthetic provider refusal",
               "channel": "wechat", "idempotency_key": tag + "_real_refund"}, platform, expected=503)
    rejected_refund = client.api(f"orders/{order_id}", token=customer_token)
    client.check(rejected_refund["refund_cents"] == 0 and any(int(row["id"]) == int(request["id"])
                 and row["status"] == "pending" for row in rejected_refund["refund_requests"]),
                 "Unconfigured provider processed the refund request")
    partial_body = {"request_id": int(request["id"]), "amount_cents": 2000, "reason": "Synthetic guarded refund", "channel": "test", "idempotency_key": tag + "_partial"}
    partial = client.api(f"orders/{order_id}/refund", "POST", partial_body, platform)
    client.check(partial["order"]["profit_status"] == "needs_reconfirmation" and partial["order"]["profit_cents"] is None,
                 "Partial refund did not invalidate profit")
    frozen = client.order_ledger(platform, broker_id, order_id)
    client.check(bool(frozen) and all(row["state"] == "frozen" for row in frozen), "Partial refund ledger is not frozen")
    replay = client.api(f"orders/{order_id}/refund", "POST", partial_body, platform)
    client.check(replay["refund"]["id"] == partial["refund"]["id"] and replay["order"]["refund_cents"] == 2000, "Refund replay changed cumulative money")
    supplier_denied()

    client.stage = "manual remaining profit and full refund reversal"
    remaining = client.api(f"orders/{order_id}/profit-confirm", "POST", {"profit_cents": 1750, "notes": "Synthetic nonproportional remaining profit"}, platform)
    client.check(remaining["rule_snapshot"] == snapshot and int(remaining["revision"]) == 2, "Remaining profit did not retain original snapshot")
    adjusted = client.order_ledger(platform, broker_id, order_id)
    client.check(sum(int(row["amount_cents"]) for row in adjusted) == 1750 * bps // 10000
                 and all(row["state"] != "frozen" for row in adjusted), "Manual profit difference ledger mismatch")
    full_request = client.api(f"orders/{order_id}/refund-request", "POST", {"amount_cents": 8000, "reason": "Synthetic remaining cancellation"}, customer_token)
    full_body = {"request_id": int(full_request["id"]), "amount_cents": 8000, "reason": "Synthetic full refund", "channel": "test", "idempotency_key": tag + "_full"}
    full = client.api(f"orders/{order_id}/refund", "POST", full_body, platform)
    client.check(full["order"]["status"] == "refunded" and full["order"]["refund_cents"] == 10000, "Full synthetic refund mismatch")
    reversed_rows = client.order_ledger(platform, broker_id, order_id)
    client.check(sum(int(row["amount_cents"]) for row in reversed_rows) == 0, "Full refund did not reverse commission")
    replay = client.api(f"orders/{order_id}/refund", "POST", full_body, platform)
    client.check(replay["refund"]["id"] == full["refund"]["id"], "Full refund replay changed receipt")
    client.check(len(client.order_ledger(platform, broker_id, order_id)) == len(reversed_rows), "Full refund replay appended money twice")
    supplier_denied()

    client.stage = "activity identity materials and membership qualification"
    starts = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))) + datetime.timedelta(days=14)
    event = client.api("resources/events", "POST", {"title": "合成HTTPS免费活动 " + tag,
                       "payload": {"capacity": 1, "starts_at": starts.strftime("%Y-%m-%d %H:%M:%S"), "description": "Synthetic acceptance only"}}, platform)
    client.api(f"events/{event['id']}/enroll", "POST", {}, customer_token, expected=403)
    identity_file, _ = client.upload_pdf(customer_token, tag + "_identity_material")
    application = client.api("applications", "POST", {"kind": "identity", "payload": {"statement": "Synthetic acceptance materials, not a real identity"},
                            "file_ids": [int(identity_file["id"])]}, customer_token)
    client.api(f"applications/{application['id']}/review", "POST", {"status": "approved", "notes": "Synthetic manual material review only"}, platform)
    # Manual review revokes old sessions; it is not an external identity-provider response.
    customer = client.api("auth/login", "POST", {"username": customer_name, "password": customer_password})
    customer_token = customer["token"]
    client.check(customer["user"]["identity_status"] == "verified" and not customer["user"]["is_member"], "Manual identity review or inactive membership mismatch")
    client.api(f"events/{event['id']}/enroll", "POST", {}, customer_token, expected=403)
    membership = client.api(f"admin/users/{customer_id}", "PATCH", {"grant_membership": True}, platform)
    client.check(membership["is_member"] and int(membership["member_until"]) > int(time.time()), "Six-month platform grant is not effective")
    enrollment = client.api(f"events/{event['id']}/enroll", "POST", {}, customer_token)["enrollment"]
    client.check(enrollment["status"] == "active", "Qualified free activity did not enroll")
    client.api(f"enrollments/{enrollment['id']}/cancel", "POST", {"ticket_code": enrollment["ticket_code"], "reason": "Synthetic free cancellation"}, outsider, expected=404)
    cancelled = client.api(f"enrollments/{enrollment['id']}/cancel", "POST", {"ticket_code": enrollment["ticket_code"], "reason": "Synthetic free cancellation"}, customer_token)
    client.check(cancelled["status"] == "cancelled", "Free cancellation did not release enrollment")
    reenrolled = client.api(f"events/{event['id']}/enroll", "POST", {}, customer_token)["enrollment"]
    client.check(reenrolled["status"] == "active" and reenrolled["ticket_code"] != enrollment["ticket_code"], "Freed seat or ticket replacement failed")
    client.api(f"enrollments/{enrollment['id']}/cancel", "POST", {"ticket_code": enrollment["ticket_code"]}, customer_token, expected=409)
    client.api(f"enrollments/{reenrolled['id']}/cancel", "POST", {"ticket_code": reenrolled["ticket_code"], "reason": "Synthetic final free cancellation"}, customer_token)
    # Do not PATCH an arbitrary expiry or impersonate a six-month wait. Expiry is covered by events.php.

    print(f"HTTPS synthetic checks: {client.checks} passed.")
    print("Scope: deployed test site, current full-payment flow, inactive/active membership; no real provider, staged payment or native-device acceptance. Membership expiry remains covered by the isolated backend suite.")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--accounts", type=pathlib.Path, required=True, help="Absolute path to a private test-accounts JSON")
    parser.add_argument("--allow-test-writes", action="store_true", help="Explicitly allow new random synthetic test fixtures")
    arguments = parser.parse_args()
    client = Client()
    try:
        if not arguments.allow_test_writes:
            raise AcceptanceFailure("Explicit synthetic-write flag is required; no request performed")
        accounts = private_accounts(arguments.accounts)
        run(accounts, client)
        return 0
    except Exception as error:
        # Never print exception bodies, response JSON, credentials, tokens, URLs or fixture data.
        print(f"HTTPS synthetic acceptance failed during {client.stage}; checks completed: {client.checks}; error class: {type(error).__name__}.", file=sys.stderr)
        if isinstance(error, AcceptanceFailure):
            print(str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
