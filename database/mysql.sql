CREATE TABLE IF NOT EXISTS organizations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, kind VARCHAR(30) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', payload TEXT NOT NULL, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 nickname VARCHAR(120) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'consumer', org_id BIGINT,
 status VARCHAR(30) NOT NULL DEFAULT 'active', member_until BIGINT NOT NULL DEFAULT 0,
 growth_points BIGINT NOT NULL DEFAULT 0, identity_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY(org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tokens (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
 expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS auth_attempts (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, attempt_key VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX auth_attempt_key ON auth_attempts(attempt_key,created_at);
CREATE TABLE IF NOT EXISTS uploads (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, owner_id BIGINT NOT NULL, org_id BIGINT,
 storage_name VARCHAR(100) NOT NULL UNIQUE, original_name VARCHAR(200) NOT NULL, mime VARCHAR(100) NOT NULL,
 size_bytes BIGINT NOT NULL, sha256 VARCHAR(64) NOT NULL, is_private BIGINT NOT NULL DEFAULT 1, created_at BIGINT NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS merchants (
 credit_code CHAR(18) PRIMARY KEY, organization_id BIGINT NOT NULL UNIQUE,
 legal_name VARCHAR(200) NOT NULL, license_file_id BIGINT, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 created_at BIGINT NOT NULL, FOREIGN KEY(organization_id) REFERENCES organizations(id), FOREIGN KEY(license_file_id) REFERENCES uploads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS resources (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(30) NOT NULL, org_id BIGINT, merchant_credit_code CHAR(18),
 owner_id BIGINT NOT NULL, title VARCHAR(200) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 payload TEXT NOT NULL, version BIGINT NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code),
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX resource_kind_status ON resources(kind,status,org_id);
CREATE TABLE IF NOT EXISTS boat_types (
 resource_id BIGINT PRIMARY KEY, merchant_credit_code CHAR(18), category VARCHAR(60) NOT NULL,
 length_m VARCHAR(30), capacity BIGINT NOT NULL, daily_price_cents BIGINT NOT NULL, deposit_cents BIGINT NOT NULL,
 specs_json TEXT NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS boats (
 resource_id BIGINT PRIMARY KEY, boat_type_id BIGINT NOT NULL, merchant_credit_code CHAR(18),
 serial_number VARCHAR(100) NOT NULL UNIQUE, price_cents BIGINT NOT NULL, location VARCHAR(200) NOT NULL,
 unavailable BIGINT NOT NULL DEFAULT 0, FOREIGN KEY(resource_id) REFERENCES resources(id),
 FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS upgrade_schemes (
 resource_id BIGINT PRIMARY KEY, boat_type_id BIGINT, merchant_credit_code CHAR(18), price_cents BIGINT NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS maintenance_plans (
 resource_id BIGINT PRIMARY KEY, boat_type_id BIGINT, merchant_credit_code CHAR(18), price_cents BIGINT NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS products (
 resource_id BIGINT PRIMARY KEY, price_cents BIGINT NOT NULL, stock BIGINT NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS events (
 resource_id BIGINT PRIMARY KEY, price_cents BIGINT NOT NULL, capacity BIGINT NOT NULL,
 starts_at BIGINT NOT NULL, location VARCHAR(200) NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS courses (
 resource_id BIGINT PRIMARY KEY, pass_score BIGINT NOT NULL DEFAULT 60, FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_lessons (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id BIGINT NOT NULL, lesson_key VARCHAR(100) NOT NULL,
 title VARCHAR(200) NOT NULL, video_url TEXT NOT NULL, duration_seconds BIGINT NOT NULL,
 sort_order BIGINT NOT NULL, UNIQUE(course_id,lesson_key), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_questions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id BIGINT NOT NULL, question_index BIGINT NOT NULL,
 question TEXT NOT NULL, options_json TEXT NOT NULL, answer_index BIGINT NOT NULL,
 UNIQUE(course_id,question_index), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coupons (
 resource_id BIGINT PRIMARY KEY, discount_cents BIGINT NOT NULL, min_amount_cents BIGINT NOT NULL,
 expires_at BIGINT NOT NULL, stock BIGINT NOT NULL, order_types_json TEXT NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS orders (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(60) NOT NULL UNIQUE, type VARCHAR(30) NOT NULL,
 customer_id BIGINT NOT NULL, creator_id BIGINT NOT NULL, broker_id BIGINT, org_id BIGINT, merchant_credit_code CHAR(18),
 resource_id BIGINT NOT NULL, status VARCHAR(30) NOT NULL, amount_cents BIGINT NOT NULL,
 original_amount_cents BIGINT NOT NULL, discount_cents BIGINT NOT NULL DEFAULT 0, paid_cents BIGINT NOT NULL DEFAULT 0,
 refund_cents BIGINT NOT NULL DEFAULT 0, profit_cents BIGINT, profit_status VARCHAR(30) NOT NULL DEFAULT 'unconfirmed', profit_revision BIGINT NOT NULL DEFAULT 0,
 coupon_claim_id BIGINT, quote_expires_at BIGINT NOT NULL DEFAULT 0, items TEXT NOT NULL, notes TEXT NOT NULL,
 rule_snapshot TEXT, contract_version BIGINT, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, completed_at BIGINT,
 FOREIGN KEY(customer_id) REFERENCES users(id), FOREIGN KEY(creator_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id),
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code),
 FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX order_customer_org ON orders(customer_id,org_id,broker_id);
CREATE TABLE IF NOT EXISTS rental_reservations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, boat_id BIGINT NOT NULL, rental_date VARCHAR(10) NOT NULL,
 order_id BIGINT NOT NULL, created_at BIGINT NOT NULL, UNIQUE(boat_id,rental_date),
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(boat_id) REFERENCES boats(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, actor_id BIGINT NOT NULL,
 action VARCHAR(60) NOT NULL, payload TEXT NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contracts (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, version BIGINT NOT NULL,
 file_id BIGINT NOT NULL, title VARCHAR(200) NOT NULL, file_sha256 VARCHAR(64) NOT NULL,
 creator_id BIGINT NOT NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'pending', reviewer_id BIGINT, reviewed_at BIGINT,
 review_notes TEXT NOT NULL, signed_by BIGINT, signed_at BIGINT, created_at BIGINT NOT NULL,
 UNIQUE(order_id,version), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(file_id) REFERENCES uploads(id),
 FOREIGN KEY(creator_id) REFERENCES users(id), FOREIGN KEY(reviewer_id) REFERENCES users(id), FOREIGN KEY(signed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
 channel VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, idempotency_key VARCHAR(100) NOT NULL UNIQUE,
 provider_reference VARCHAR(200), created_at BIGINT NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS refunds (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
 reason TEXT NOT NULL, channel VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL,
 idempotency_key VARCHAR(100) NOT NULL UNIQUE, provider_reference VARCHAR(200), created_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, value_json TEXT NOT NULL,
 version BIGINT NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ledger (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, order_id BIGINT NOT NULL,
 beneficiary_role VARCHAR(30) NOT NULL, entry_key VARCHAR(150) NOT NULL UNIQUE, amount_cents BIGINT NOT NULL,
 state VARCHAR(30) NOT NULL, available_at BIGINT NOT NULL, reversal_of BIGINT, created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(reversal_of) REFERENCES ledger(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ledger_user_state ON ledger(user_id,state,available_at);
CREATE TABLE IF NOT EXISTS withdrawals (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', notes TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS enrollments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, event_id BIGINT NOT NULL, order_id BIGINT,
 status VARCHAR(30) NOT NULL, ticket_code VARCHAR(100) NOT NULL UNIQUE, created_at BIGINT NOT NULL,
 checked_in_at BIGINT, UNIQUE(user_id,event_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(event_id) REFERENCES events(resource_id), FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS learning_progress (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, course_id BIGINT NOT NULL, lesson_id VARCHAR(100) NOT NULL,
 position_seconds BIGINT NOT NULL, completed BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL,
 UNIQUE(user_id,course_id,lesson_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(course_id,lesson_id) REFERENCES course_lessons(course_id,lesson_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS exam_attempts (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, course_id BIGINT NOT NULL, score BIGINT NOT NULL,
 passed BIGINT NOT NULL, answers TEXT NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS growth_logs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, event_key VARCHAR(150) NOT NULL UNIQUE,
 points BIGINT NOT NULL, description VARCHAR(200) NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coupon_claims (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, coupon_id BIGINT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'available', order_id BIGINT, created_at BIGINT NOT NULL,
 UNIQUE(user_id,coupon_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id), FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS comments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, post_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
 content TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', created_at BIGINT NOT NULL,
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS customers (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, broker_id BIGINT NOT NULL, org_id BIGINT NOT NULL,
 name VARCHAR(120) NOT NULL, notes TEXT NOT NULL, stage VARCHAR(30) NOT NULL DEFAULT 'new',
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(user_id,broker_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS applications (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, kind VARCHAR(30) NOT NULL,
 org_id BIGINT, status VARCHAR(30) NOT NULL DEFAULT 'pending', payload TEXT NOT NULL, file_ids TEXT NOT NULL,
 review_notes TEXT NOT NULL, reviewer_id BIGINT, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT NOT NULL, org_id BIGINT,
 action VARCHAR(100) NOT NULL, object_type VARCHAR(100) NOT NULL, object_id BIGINT NOT NULL,
 payload TEXT NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_items (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, resource_id BIGINT NOT NULL,
 quantity BIGINT NOT NULL, unit_price_cents BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
 start_date VARCHAR(10), end_date VARCHAR(10), FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cart_items (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, product_id BIGINT NOT NULL,
 quantity BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(user_id,product_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(product_id) REFERENCES products(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS application_files (
 application_id BIGINT NOT NULL, file_id BIGINT NOT NULL, PRIMARY KEY(application_id,file_id),
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(file_id) REFERENCES uploads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_participants (
 event_id BIGINT NOT NULL, merchant_credit_code CHAR(18) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 reviewer_id BIGINT, reviewed_at BIGINT, notes TEXT NOT NULL, created_at BIGINT NOT NULL,
 PRIMARY KEY(event_id,merchant_credit_code), FOREIGN KEY(event_id) REFERENCES events(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code), FOREIGN KEY(reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS resource_options (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, resource_id BIGINT NOT NULL, option_key VARCHAR(100) NOT NULL,
 title VARCHAR(200) NOT NULL, price_cents BIGINT NOT NULL, UNIQUE(resource_id,option_key),
 FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS profit_confirmations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, revision BIGINT NOT NULL,
 profit_cents BIGINT NOT NULL, notes TEXT NOT NULL, actor_id BIGINT NOT NULL, created_at BIGINT NOT NULL,
 UNIQUE(order_id,revision), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS refund_requests (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, customer_id BIGINT NOT NULL,
 amount_cents BIGINT NOT NULL, reason TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 reviewed_by BIGINT, review_notes TEXT NOT NULL, refund_id BIGINT, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(customer_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id), FOREIGN KEY(refund_id) REFERENCES refunds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invitations (
 user_id BIGINT PRIMARY KEY, code VARCHAR(16) NOT NULL UNIQUE, referrer_id BIGINT, created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(referrer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_rewards (
 course_id BIGINT NOT NULL, coupon_id BIGINT NOT NULL, PRIMARY KEY(course_id,coupon_id),
 FOREIGN KEY(course_id) REFERENCES courses(resource_id), FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon_claim FOREIGN KEY(coupon_claim_id) REFERENCES coupon_claims(id);
