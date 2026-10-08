CREATE TABLE IF NOT EXISTS customer_tags (
 id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER NOT NULL REFERENCES organizations(id),
 name TEXT NOT NULL, color TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active',
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 UNIQUE(org_id,name)
);
CREATE TABLE IF NOT EXISTS customer_tag_links (
 customer_id INTEGER NOT NULL REFERENCES customers(id), tag_id INTEGER NOT NULL REFERENCES customer_tags(id),
 created_by INTEGER NOT NULL REFERENCES users(id), created_at INTEGER NOT NULL, PRIMARY KEY(customer_id,tag_id)
);
CREATE TABLE IF NOT EXISTS agency_monthly_targets (
 org_id INTEGER NOT NULL REFERENCES organizations(id), month TEXT NOT NULL, target_amount_cents INTEGER NOT NULL,
 updated_by INTEGER NOT NULL REFERENCES users(id), updated_at INTEGER NOT NULL, PRIMARY KEY(org_id,month)
);
CREATE TABLE IF NOT EXISTS boat_locations (
 boat_id INTEGER PRIMARY KEY REFERENCES boats(resource_id), latitude REAL NOT NULL, longitude REAL NOT NULL,
 CHECK(latitude>=-90 AND latitude<=90), CHECK(longitude>=-180 AND longitude<=180)
);
