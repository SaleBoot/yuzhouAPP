CREATE TABLE IF NOT EXISTS growth_products (
 resource_id BIGINT PRIMARY KEY, points_price BIGINT NOT NULL, stock BIGINT NOT NULL,
 FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS point_redemptions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
 quantity BIGINT NOT NULL, points BIGINT NOT NULL, idempotency_key VARCHAR(100) NOT NULL,
 status VARCHAR(30) NOT NULL, item_snapshot TEXT NOT NULL, notes TEXT NOT NULL, reviewed_by BIGINT,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(user_id,idempotency_key),
 FOREIGN KEY(product_id) REFERENCES growth_products(resource_id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS banners (
 resource_id BIGINT PRIMARY KEY, placement VARCHAR(30) NOT NULL, target_url VARCHAR(500) NOT NULL,
 sort_order BIGINT NOT NULL DEFAULT 0, FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS post_metadata (
 post_id BIGINT PRIMARY KEY, post_type VARCHAR(20) NOT NULL, is_pinned BIGINT NOT NULL DEFAULT 0,
 FOREIGN KEY(post_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS post_likes (
 post_id BIGINT NOT NULL, user_id BIGINT NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(post_id,user_id),
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS post_official_replies (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, post_id BIGINT NOT NULL, author_id BIGINT NOT NULL,
 content TEXT NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(post_id) REFERENCES resources(id), FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS management_roles (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, org_id BIGINT, base_role VARCHAR(30) NOT NULL,
 name VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_by BIGINT NOT NULL,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS management_role_permissions (
 role_id BIGINT NOT NULL, permission_key VARCHAR(40) NOT NULL, PRIMARY KEY(role_id,permission_key),
 FOREIGN KEY(role_id) REFERENCES management_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS management_user_roles (
 user_id BIGINT NOT NULL, role_id BIGINT NOT NULL, PRIMARY KEY(user_id,role_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(role_id) REFERENCES management_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_service_schedules (
 order_id BIGINT PRIMARY KEY, scheduled_at BIGINT NOT NULL, location VARCHAR(300) NOT NULL,
 updated_by BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS order_progress (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, actor_id BIGINT NOT NULL,
 stage VARCHAR(30) NOT NULL, percent BIGINT NOT NULL, notes TEXT NOT NULL, created_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
