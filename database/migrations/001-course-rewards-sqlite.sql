CREATE TABLE IF NOT EXISTS course_reward_definitions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL, reward_key VARCHAR(100) NOT NULL,
 reward_type VARCHAR(30) NOT NULL, title VARCHAR(200) NOT NULL, points INTEGER NOT NULL DEFAULT 0,
 coupon_id INTEGER, event_id INTEGER, active INTEGER NOT NULL DEFAULT 1, UNIQUE(course_id,reward_key),
 FOREIGN KEY(course_id) REFERENCES courses(resource_id), FOREIGN KEY(coupon_id) REFERENCES coupons(resource_id),
 FOREIGN KEY(event_id) REFERENCES events(resource_id)
);
CREATE TABLE IF NOT EXISTS course_reward_claims (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reward_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 status VARCHAR(30) NOT NULL, reward_snapshot TEXT NOT NULL, notes TEXT NOT NULL,
 reviewed_by INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(reward_id,user_id),
 FOREIGN KEY(reward_id) REFERENCES course_reward_definitions(id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
);
