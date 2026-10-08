CREATE TABLE IF NOT EXISTS reminders (
 record_id BIGINT PRIMARY KEY, due_date DATE NOT NULL, last_notified_due_date DATE,
 INDEX reminder_due(due_date), FOREIGN KEY(record_id) REFERENCES user_records(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
