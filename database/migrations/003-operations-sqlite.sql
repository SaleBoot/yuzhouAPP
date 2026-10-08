CREATE TABLE IF NOT EXISTS growth_products (
 resource_id INTEGER PRIMARY KEY, points_price INTEGER NOT NULL, stock INTEGER NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS point_redemptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 quantity INTEGER NOT NULL, points INTEGER NOT NULL, idempotency_key VARCHAR(100) NOT NULL,
 status VARCHAR(30) NOT NULL, item_snapshot TEXT NOT NULL, notes TEXT NOT NULL, reviewed_by INTEGER,
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(user_id,idempotency_key),
 FOREIGN KEY(product_id) REFERENCES growth_products(resource_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS banners (
 resource_id INTEGER PRIMARY KEY, placement VARCHAR(30) NOT NULL, target_url VARCHAR(500) NOT NULL,
 sort_order INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS post_metadata (
 post_id INTEGER PRIMARY KEY, post_type VARCHAR(20) NOT NULL, is_pinned INTEGER NOT NULL DEFAULT 0,
 FOREIGN KEY(post_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS post_likes (
 post_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(post_id,user_id),
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS post_official_replies (
 id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, author_id INTEGER NOT NULL,
 content TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(author_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS management_roles (
 id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, base_role VARCHAR(30) NOT NULL,
 name VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_by INTEGER NOT NULL,
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS management_role_permissions (
 role_id INTEGER NOT NULL, permission_key VARCHAR(40) NOT NULL, PRIMARY KEY(role_id,permission_key),
 FOREIGN KEY(role_id) REFERENCES management_roles(id)
);
CREATE TABLE IF NOT EXISTS management_user_roles (
 user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, PRIMARY KEY(user_id,role_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(role_id) REFERENCES management_roles(id)
);
CREATE TABLE IF NOT EXISTS order_service_schedules (
 order_id INTEGER PRIMARY KEY, scheduled_at INTEGER NOT NULL, location VARCHAR(300) NOT NULL,
 updated_by INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(updated_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS order_progress (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, actor_id INTEGER NOT NULL,
 stage VARCHAR(30) NOT NULL, percent INTEGER NOT NULL, notes TEXT NOT NULL, created_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
);
