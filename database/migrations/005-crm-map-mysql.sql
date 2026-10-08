CREATE TABLE IF NOT EXISTS customer_tags (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, org_id BIGINT NOT NULL, name VARCHAR(80) NOT NULL,
 color VARCHAR(7) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_by BIGINT NOT NULL,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(org_id,name),
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS customer_tag_links (
 customer_id BIGINT NOT NULL, tag_id BIGINT NOT NULL, created_by BIGINT NOT NULL, created_at BIGINT NOT NULL,
 PRIMARY KEY(customer_id,tag_id), FOREIGN KEY(customer_id) REFERENCES customers(id),
 FOREIGN KEY(tag_id) REFERENCES customer_tags(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agency_monthly_targets (
 org_id BIGINT NOT NULL, month CHAR(7) NOT NULL, target_amount_cents BIGINT NOT NULL,
 updated_by BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY(org_id,month),
 FOREIGN KEY(org_id) REFERENCES organizations(id), FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS boat_locations (
 boat_id BIGINT PRIMARY KEY, latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL,
 FOREIGN KEY(boat_id) REFERENCES boats(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
