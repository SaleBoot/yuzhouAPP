CREATE UNIQUE INDEX IF NOT EXISTS withdrawal_user_key ON withdrawals(user_id,idempotency_key);
