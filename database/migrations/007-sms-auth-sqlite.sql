CREATE TABLE IF NOT EXISTS phone_identities (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL UNIQUE,
 phone_hash CHAR(64) NOT NULL UNIQUE, verified_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS sms_challenges (
 id INTEGER PRIMARY KEY AUTOINCREMENT, challenge_id CHAR(64) NOT NULL UNIQUE,
 phone_hash CHAR(64) NOT NULL, purpose VARCHAR(20) NOT NULL, code_hash CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL,
 created_at INTEGER NOT NULL, consumed_at INTEGER, user_id INTEGER,
 provider_request_id VARCHAR(128), provider_biz_id VARCHAR(128), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS sms_challenge_phone ON sms_challenges(phone_hash,purpose,status);
CREATE INDEX IF NOT EXISTS sms_challenge_expiry ON sms_challenges(expires_at);
CREATE TABLE IF NOT EXISTS sms_rate_limits (
 bucket_key CHAR(64) NOT NULL PRIMARY KEY, window_start INTEGER NOT NULL,
 attempt_count INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS sms_rate_updated ON sms_rate_limits(updated_at);
