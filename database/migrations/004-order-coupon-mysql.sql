SET @yuzhou_coupon_fk_sql = IF((SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='coupon_claim_id' AND REFERENCED_TABLE_NAME='coupon_claims') > 0, 'SELECT 1', 'ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon_claim FOREIGN KEY(coupon_claim_id) REFERENCES coupon_claims(id)');
PREPARE yuzhou_coupon_fk_stmt FROM @yuzhou_coupon_fk_sql;
EXECUTE yuzhou_coupon_fk_stmt;
DEALLOCATE PREPARE yuzhou_coupon_fk_stmt;
