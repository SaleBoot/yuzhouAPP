CREATE TABLE IF NOT EXISTS conversations (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, context_key VARCHAR(100) NOT NULL UNIQUE,
 order_id BIGINT, resource_id BIGINT, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(resource_id) REFERENCES resources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS conversation_members (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, conversation_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
 read_message_id BIGINT NOT NULL DEFAULT 0, UNIQUE(conversation_id,user_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS messages (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, conversation_id BIGINT NOT NULL, sender_id BIGINT NOT NULL,
 client_id VARCHAR(100) NOT NULL, content TEXT NOT NULL, created_at BIGINT NOT NULL,
 UNIQUE(conversation_id,sender_id,client_id), FOREIGN KEY(conversation_id) REFERENCES conversations(id), FOREIGN KEY(sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX message_conversation ON messages(conversation_id,id);
CREATE TABLE IF NOT EXISTS appointments (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, customer_id BIGINT NOT NULL, broker_id BIGINT,
 org_id BIGINT, merchant_credit_code CHAR(18), resource_id BIGINT NOT NULL, scheduled_at BIGINT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', notes TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 FOREIGN KEY(customer_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX appointment_owner ON appointments(customer_id,broker_id,org_id);
CREATE TABLE IF NOT EXISTS user_records (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, user_id BIGINT NOT NULL, kind VARCHAR(30) NOT NULL,
 resource_id BIGINT, order_id BIGINT, title VARCHAR(200) NOT NULL, payload TEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'active', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 UNIQUE(order_id,kind), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX records_owner ON user_records(user_id,kind,status);
CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, user_id BIGINT NOT NULL, title VARCHAR(200) NOT NULL,
 content TEXT NOT NULL, object_type VARCHAR(60) NOT NULL, object_id BIGINT NOT NULL,
 read_at BIGINT, created_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX notifications_owner ON notifications(user_id,id);
