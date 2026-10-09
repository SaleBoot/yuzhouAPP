CREATE TABLE IF NOT EXISTS phone_identities (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL UNIQUE,
 phone_hash CHAR(64) NOT NULL UNIQUE, verified_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sms_challenges (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, challenge_id CHAR(64) NOT NULL UNIQUE,
 phone_hash CHAR(64) NOT NULL, purpose VARCHAR(20) NOT NULL, code_hash CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL, attempts BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL,
 created_at BIGINT NOT NULL, consumed_at BIGINT, user_id BIGINT,
 provider_request_id VARCHAR(128), provider_biz_id VARCHAR(128), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sms_rate_limits (
 bucket_key CHAR(64) NOT NULL PRIMARY KEY, window_start BIGINT NOT NULL,
 attempt_count BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @yuzhou_sms_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_challenges' AND INDEX_NAME='sms_challenge_phone') > 0, 'SELECT 1', 'CREATE INDEX sms_challenge_phone ON sms_challenges(phone_hash,purpose,status)');
PREPARE yuzhou_sms_index_stmt FROM @yuzhou_sms_index_sql;
EXECUTE yuzhou_sms_index_stmt;
DEALLOCATE PREPARE yuzhou_sms_index_stmt;
SET @yuzhou_sms_expiry_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_challenges' AND INDEX_NAME='sms_challenge_expiry') > 0, 'SELECT 1', 'CREATE INDEX sms_challenge_expiry ON sms_challenges(expires_at)');
PREPARE yuzhou_sms_expiry_stmt FROM @yuzhou_sms_expiry_sql;
EXECUTE yuzhou_sms_expiry_stmt;
DEALLOCATE PREPARE yuzhou_sms_expiry_stmt;
SET @yuzhou_sms_rate_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_rate_limits' AND INDEX_NAME='sms_rate_updated') > 0, 'SELECT 1', 'CREATE INDEX sms_rate_updated ON sms_rate_limits(updated_at)');
PREPARE yuzhou_sms_rate_stmt FROM @yuzhou_sms_rate_sql;
EXECUTE yuzhou_sms_rate_stmt;
DEALLOCATE PREPARE yuzhou_sms_rate_stmt;
