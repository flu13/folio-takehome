ALTER TABLE documents ADD COLUMN readable_id TEXT;

UPDATE documents SET readable_id = 'doc-' || CAST(id AS TEXT) WHERE readable_id IS NULL;

CREATE TABLE documents_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    publish_at TEXT,
    readable_id TEXT NOT NULL UNIQUE,
    FOREIGN KEY (created_by) REFERENCES staff(id)
);

INSERT INTO documents_new (id, title, body, created_by, created_at, publish_at, readable_id)
SELECT id, title, body, created_by, created_at, publish_at, readable_id FROM documents;

DROP TABLE documents;

ALTER TABLE documents_new RENAME TO documents;

