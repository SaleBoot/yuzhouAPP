CREATE TABLE IF NOT EXISTS reminders (
 record_id INTEGER PRIMARY KEY, due_date DATE NOT NULL, last_notified_due_date DATE,
 FOREIGN KEY(record_id) REFERENCES user_records(id)
);
CREATE INDEX IF NOT EXISTS reminder_due ON reminders(due_date);
