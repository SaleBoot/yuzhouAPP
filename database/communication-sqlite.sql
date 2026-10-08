CREATE TABLE IF NOT EXISTS conversations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, context_key VARCHAR(100) NOT NULL UNIQUE,
 order_id INTEGER, resource_id INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(resource_id) REFERENCES resources(id)
);
CREATE TABLE IF NOT EXISTS conversation_members (
 id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 read_message_id INTEGER NOT NULL DEFAULT 0, UNIQUE(conversation_id,user_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id), FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, sender_id INTEGER NOT NULL,
 client_id VARCHAR(100) NOT NULL, content TEXT NOT NULL, created_at INTEGER NOT NULL,
 UNIQUE(conversation_id,sender_id,client_id), FOREIGN KEY(conversation_id) REFERENCES conversations(id), FOREIGN KEY(sender_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS message_conversation ON messages(conversation_id,id);
CREATE TABLE IF NOT EXISTS appointments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, broker_id INTEGER,
 org_id INTEGER, merchant_credit_code CHAR(18), resource_id INTEGER NOT NULL, scheduled_at INTEGER NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending', notes TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 FOREIGN KEY(customer_id) REFERENCES users(id), FOREIGN KEY(broker_id) REFERENCES users(id), FOREIGN KEY(org_id) REFERENCES organizations(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(merchant_credit_code) REFERENCES merchants(credit_code)
);
CREATE INDEX IF NOT EXISTS appointment_owner ON appointments(customer_id,broker_id,org_id);
CREATE TABLE IF NOT EXISTS user_records (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, kind VARCHAR(30) NOT NULL,
 resource_id INTEGER, order_id INTEGER, title VARCHAR(200) NOT NULL, payload TEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'active', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 UNIQUE(order_id,kind), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(resource_id) REFERENCES resources(id), FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE INDEX IF NOT EXISTS records_owner ON user_records(user_id,kind,status);
CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL,
 content TEXT NOT NULL, object_type VARCHAR(60) NOT NULL, object_id INTEGER NOT NULL,
 read_at INTEGER, created_at INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS notifications_owner ON notifications(user_id,id);
