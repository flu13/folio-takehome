CREATE VIRTUAL TABLE documents_fts USING fts5(
    title,
    body,
    content='documents',
    content_rowid='id'
);

INSERT INTO documents_fts (rowid, title, body)
SELECT id, title, body FROM documents;

CREATE TRIGGER documents_ai AFTER INSERT ON documents BEGIN
    INSERT INTO documents_fts (rowid, title, body) VALUES (new.id, new.title, new.body);
END;

CREATE TRIGGER documents_ad AFTER DELETE ON documents BEGIN
    INSERT INTO documents_fts (documents_fts, rowid, title, body) VALUES ('delete', old.id, old.title, old.body);
END;

CREATE TRIGGER documents_au AFTER UPDATE ON documents BEGIN
    INSERT INTO documents_fts (documents_fts, rowid, title, body) VALUES ('delete', old.id, old.title, old.body);
    INSERT INTO documents_fts (rowid, title, body) VALUES (new.id, new.title, new.body);
END;
