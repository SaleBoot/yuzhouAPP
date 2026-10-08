CREATE TABLE IF NOT EXISTS course_reward_definitions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id BIGINT NOT NULL, reward_key VARCHAR(100) NOT NULL,
 reward_type VARCHAR(30) NOT NULL, title VARCHAR(200) NOT NULL, points BIGINT NOT NULL DEFAULT 0,
 coupon_id BIGINT, event_id BIGINT, active BIGINT NOT NULL DEFAULT 1, UNIQUE(course_id,reward_key),
 FOREIGN KEY(course_id) REFERENCES courses(resource_id), FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id),
 FOREIGN KEY(event_id) REFERENCES events(resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_reward_claims (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, reward_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
 status VARCHAR(30) NOT NULL, reward_snapshot TEXT NOT NULL, notes TEXT NOT NULL,
 reviewed_by BIGINT, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(reward_id,user_id),
 FOREIGN KEY(reward_id) REFERENCES course_reward_definitions(id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
