SET @yuzhou_withdraw_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='withdrawals' AND COLUMN_NAME='idempotency_key') > 0, 'SELECT 1', 'ALTER TABLE withdrawals ADD COLUMN idempotency_key VARCHAR(100) NULL');
PREPARE yuzhou_withdraw_column_stmt FROM @yuzhou_withdraw_column_sql;
EXECUTE yuzhou_withdraw_column_stmt;
DEALLOCATE PREPARE yuzhou_withdraw_column_stmt;
SET @yuzhou_withdraw_key_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='withdrawals' AND INDEX_NAME='withdrawal_user_key') > 0, 'SELECT 1', 'ALTER TABLE withdrawals ADD UNIQUE KEY withdrawal_user_key(user_id,idempotency_key)');
PREPARE yuzhou_withdraw_key_stmt FROM @yuzhou_withdraw_key_sql;
EXECUTE yuzhou_withdraw_key_stmt;
DEALLOCATE PREPARE yuzhou_withdraw_key_stmt;
