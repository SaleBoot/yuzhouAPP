CREATE TABLE IF NOT EXISTS organizations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(200) NOT NULL, kind VARCHAR(30) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', payload TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 nickname VARCHAR(120) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'consumer', org_id INTEGER,
 status VARCHAR(30) NOT NULL DEFAULT 'active', member_until INTEGER NOT NULL DEFAULT 0,
 growth_points INTEGER NOT NULL DEFAULT 0, identity_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, FOREIGN KEY(org_id) REFERENCES organizations(id)
);
CREATE TABLE IF NOT EXISTS tokens (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
 expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS auth_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_key VARCHAR(64) NOT NULL, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS auth_attempt_key ON auth_attempts(attempt_key,created_at);
CREATE TABLE IF NOT EXISTS uploads (
 id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INTEGER NOT NULL, org_id INTEGER,
 storage_name VARCHAR(100) NOT NULL UNIQUE, original_name VARCHAR(200) NOT NULL, mime VARCHAR(100) NOT NULL,
 size_bytes INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, is_private INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
);
CREATE TABLE IF NOT EXISTS merchants (
 credit_code CHAR(18) NOT NULL PRIMARY KEY, organization_id INTEGER NOT NULL UNIQUE,
 legal_name VARCHAR(200) NOT NULL, license_file_id INTEGER, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 created_at INTEGER NOT NULL, FOREIGN KEY(organization_id) REFERENCES organizations(id), FOREIGN KEY(license_file_id) REFERENCES uploads(id)
);
CREATE TABLE IF NOT EXISTS resources (
 id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(30) NOT NULL, org_id INTEGER, merchant_credit_code CHAR(18),
 owner_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 payload TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code),
 FOREIGN KEY(owner_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS resource_kind_status ON resources(kind,status,org_id);
CREATE TABLE IF NOT EXISTS boat_types (
 resource_id INTEGER PRIMARY KEY, merchant_credit_code CHAR(18), category VARCHAR(60) NOT NULL,
 length_m VARCHAR(30), capacity INTEGER NOT NULL, daily_price_cents INTEGER NOT NULL, deposit_cents INTEGER NOT NULL,
 specs_json TEXT NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
);
CREATE TABLE IF NOT EXISTS boats (
 resource_id INTEGER PRIMARY KEY, boat_type_id INTEGER NOT NULL, merchant_credit_code CHAR(18),
 serial_number VARCHAR(100) NOT NULL UNIQUE, price_cents INTEGER NOT NULL, location VARCHAR(200) NOT NULL,
 unavailable INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(resource_id) REFERENCES resources(id),
 FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
);
CREATE TABLE IF NOT EXISTS upgrade_schemes (
 resource_id INTEGER PRIMARY KEY, boat_type_id INTEGER, merchant_credit_code CHAR(18), price_cents INTEGER NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
);
CREATE TABLE IF NOT EXISTS maintenance_plans (
 resource_id INTEGER PRIMARY KEY, boat_type_id INTEGER, merchant_credit_code CHAR(18), price_cents INTEGER NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(boat_type_id) REFERENCES boat_types(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
);
CREATE TABLE IF NOT EXISTS products (
 resource_id INTEGER PRIMARY KEY, price_cents INTEGER NOT NULL, stock INTEGER NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS events (
 resource_id INTEGER PRIMARY KEY, price_cents INTEGER NOT NULL, capacity INTEGER NOT NULL,
 starts_at INTEGER NOT NULL, location VARCHAR(200) NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS courses (
 resource_id INTEGER PRIMARY KEY, pass_score INTEGER NOT NULL DEFAULT 60, FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS course_lessons (
 id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL, lesson_key VARCHAR(100) NOT NULL,
 title VARCHAR(200) NOT NULL, video_url TEXT NOT NULL, duration_seconds INTEGER NOT NULL,
 sort_order INTEGER NOT NULL, UNIQUE(course_id,lesson_key), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
);
CREATE TABLE IF NOT EXISTS course_questions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL, question_index INTEGER NOT NULL,
 question TEXT NOT NULL, options_json TEXT NOT NULL, answer_index INTEGER NOT NULL,
 UNIQUE(course_id,question_index), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
);
CREATE TABLE IF NOT EXISTS coupons (
 resource_id INTEGER PRIMARY KEY, discount_cents INTEGER NOT NULL, min_amount_cents INTEGER NOT NULL,
 expires_at INTEGER NOT NULL, stock INTEGER NOT NULL, order_types_json TEXT NOT NULL, FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_no VARCHAR(60) NOT NULL UNIQUE, type VARCHAR(30) NOT NULL,
 customer_id INTEGER NOT NULL, creator_id INTEGER NOT NULL, broker_id INTEGER, org_id INTEGER, merchant_credit_code CHAR(18),
 resource_id INTEGER NOT NULL, status VARCHAR(30) NOT NULL, amount_cents INTEGER NOT NULL,
 original_amount_cents INTEGER NOT NULL, discount_cents INTEGER NOT NULL DEFAULT 0, paid_cents INTEGER NOT NULL DEFAULT 0,
 refund_cents INTEGER NOT NULL DEFAULT 0, profit_cents INTEGER, profit_status VARCHAR(30) NOT NULL DEFAULT 'unconfirmed', profit_revision INTEGER NOT NULL DEFAULT 0,
 coupon_claim_id INTEGER, quote_expires_at INTEGER NOT NULL DEFAULT 0, items TEXT NOT NULL, notes TEXT NOT NULL,
 rule_snapshot TEXT, contract_version INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, completed_at INTEGER,
 FOREIGN KEY(customer_id) REFERENCES users(id), FOREIGN KEY(creator_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id),
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code),
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(coupon_claim_id) REFERENCES coupon_claims(id)
);
CREATE INDEX IF NOT EXISTS order_customer_org ON orders(customer_id,org_id,broker_id);
CREATE TABLE IF NOT EXISTS rental_reservations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, boat_id INTEGER NOT NULL, rental_date VARCHAR(10) NOT NULL,
 order_id INTEGER NOT NULL, created_at INTEGER NOT NULL, UNIQUE(boat_id,rental_date),
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(boat_id) REFERENCES boats(resource_id)
);
CREATE TABLE IF NOT EXISTS order_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, actor_id INTEGER NOT NULL,
 action VARCHAR(60) NOT NULL, payload TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS contracts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, version INTEGER NOT NULL,
 file_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL, file_sha256 VARCHAR(64) NOT NULL,
 creator_id INTEGER NOT NULL, review_status VARCHAR(30) NOT NULL DEFAULT 'pending', reviewer_id INTEGER, reviewed_at INTEGER,
 review_notes TEXT NOT NULL, signed_by INTEGER, signed_at INTEGER, created_at INTEGER NOT NULL,
 UNIQUE(order_id,version), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(file_id) REFERENCES uploads(id),
 FOREIGN KEY(creator_id) REFERENCES users(id), FOREIGN KEY(reviewer_id) REFERENCES users(id), FOREIGN KEY(signed_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, amount_cents INTEGER NOT NULL,
 channel VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, idempotency_key VARCHAR(100) NOT NULL UNIQUE,
 provider_reference VARCHAR(200), created_at INTEGER NOT NULL, FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, amount_cents INTEGER NOT NULL,
 reason TEXT NOT NULL, channel VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL,
 idempotency_key VARCHAR(100) NOT NULL UNIQUE, provider_reference VARCHAR(200), created_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS settings (
 id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key VARCHAR(100) NOT NULL UNIQUE, value_json TEXT NOT NULL,
 version INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS ledger (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, order_id INTEGER NOT NULL,
 beneficiary_role VARCHAR(30) NOT NULL, entry_key VARCHAR(150) NOT NULL UNIQUE, amount_cents INTEGER NOT NULL,
 state VARCHAR(30) NOT NULL, available_at INTEGER NOT NULL, reversal_of INTEGER, created_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(reversal_of) REFERENCES ledger(id)
);
CREATE INDEX IF NOT EXISTS ledger_user_state ON ledger(user_id,state,available_at);
CREATE TABLE IF NOT EXISTS withdrawals (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, amount_cents INTEGER NOT NULL, idempotency_key TEXT,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', notes TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS enrollments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, order_id INTEGER,
 status VARCHAR(30) NOT NULL, ticket_code VARCHAR(100) NOT NULL UNIQUE, created_at INTEGER NOT NULL,
 checked_in_at INTEGER, UNIQUE(user_id,event_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(event_id) REFERENCES events(resource_id), FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS learning_progress (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, course_id INTEGER NOT NULL, lesson_id VARCHAR(100) NOT NULL,
 position_seconds INTEGER NOT NULL, completed INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL,
 UNIQUE(user_id,course_id,lesson_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(course_id,lesson_id) REFERENCES course_lessons(course_id,lesson_key)
);
CREATE TABLE IF NOT EXISTS exam_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, course_id INTEGER NOT NULL, score INTEGER NOT NULL,
 passed INTEGER NOT NULL, answers TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(course_id) REFERENCES courses(resource_id)
);
CREATE TABLE IF NOT EXISTS growth_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event_key VARCHAR(150) NOT NULL UNIQUE,
 points INTEGER NOT NULL, description VARCHAR(200) NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS coupon_claims (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, coupon_id INTEGER NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'available', order_id INTEGER, created_at INTEGER NOT NULL,
 UNIQUE(user_id,coupon_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id), FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS comments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 content TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL,
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS customers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, broker_id INTEGER NOT NULL, org_id INTEGER NOT NULL,
 name VARCHAR(120) NOT NULL, notes TEXT NOT NULL, stage VARCHAR(30) NOT NULL DEFAULT 'new',
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(user_id,broker_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
);
CREATE TABLE IF NOT EXISTS applications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind VARCHAR(30) NOT NULL,
 org_id INTEGER, status VARCHAR(30) NOT NULL DEFAULT 'pending', payload TEXT NOT NULL, file_ids TEXT NOT NULL,
 review_notes TEXT NOT NULL, reviewer_id INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(reviewer_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS audit_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER NOT NULL, org_id INTEGER,
 action VARCHAR(100) NOT NULL, object_type VARCHAR(100) NOT NULL, object_id INTEGER NOT NULL,
 payload TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id)
);
CREATE TABLE IF NOT EXISTS order_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, resource_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL, unit_price_cents INTEGER NOT NULL, amount_cents INTEGER NOT NULL,
 start_date VARCHAR(10), end_date VARCHAR(10), FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS cart_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(user_id,product_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(product_id) REFERENCES products(resource_id)
);
CREATE TABLE IF NOT EXISTS application_files (
 application_id INTEGER NOT NULL, file_id INTEGER NOT NULL, PRIMARY KEY(application_id,file_id),
 FOREIGN KEY(application_id) REFERENCES applications(id), FOREIGN KEY(file_id) REFERENCES uploads(id)
);
CREATE TABLE IF NOT EXISTS event_participants (
 event_id INTEGER NOT NULL, merchant_credit_code CHAR(18) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 reviewer_id INTEGER, reviewed_at INTEGER, notes TEXT NOT NULL, created_at INTEGER NOT NULL,
 PRIMARY KEY(event_id,merchant_credit_code), FOREIGN KEY(event_id) REFERENCES events(resource_id),
 FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code), FOREIGN KEY(reviewer_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS resource_options (
 id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER NOT NULL, option_key VARCHAR(100) NOT NULL,
 title VARCHAR(200) NOT NULL, price_cents INTEGER NOT NULL, UNIQUE(resource_id,option_key),
 FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS profit_confirmations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, revision INTEGER NOT NULL,
 profit_cents INTEGER NOT NULL, notes TEXT NOT NULL, actor_id INTEGER NOT NULL, created_at INTEGER NOT NULL,
 UNIQUE(order_id,revision), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS refund_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, customer_id INTEGER NOT NULL,
 amount_cents INTEGER NOT NULL, reason TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 reviewed_by INTEGER, review_notes TEXT NOT NULL, refund_id INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(customer_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id), FOREIGN KEY(refund_id) REFERENCES refunds(id)
);
CREATE TABLE IF NOT EXISTS invitations (
 user_id INTEGER PRIMARY KEY, code VARCHAR(16) NOT NULL UNIQUE, referrer_id INTEGER, created_at INTEGER NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(referrer_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS course_rewards (
 course_id INTEGER NOT NULL, coupon_id INTEGER NOT NULL, PRIMARY KEY(course_id,coupon_id),
 FOREIGN KEY(course_id) REFERENCES courses(resource_id), FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id)
);
