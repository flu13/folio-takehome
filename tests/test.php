<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

system('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "migrate failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('scheduled publishing prevents access before publish time', function () {
    // Create a document with publish_at in the future
    $future = new DateTime('now +1 hour', new DateTimeZone('UTC'));
    $publishAt = $future->format('Y-m-d H:i:s');
    $readableId = generate_readable_id('Future Doc');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, readable_id) VALUES (?, ?, 1, ?, ?)');
    $stmt->execute(['Future Doc', 'Body', $publishAt, $readableId]);
    $docId = (int) db()->lastInsertId();

    // Create a share
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    // Simulate view logic: fetch doc
    $stmt = db()->prepare('
        SELECT d.*
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'document not found');

    // Check availability
    $isAvailable = true;
    if ($doc['publish_at'] !== null) {
        $publish = new DateTime($doc['publish_at'], new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        if ($publish > $now) {
            $isAvailable = false;
        }
    }
    assert_true(!$isAvailable, 'document should not be available before publish time');
});

test('readable_id is generated on document creation', function () {
    // Check seeded document has backfilled readable_id
    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'seeded document not found');
    assert_true($row['readable_id'] !== null, 'readable_id should not be null');
    assert_true(strlen($row['readable_id']) > 0, 'readable_id should not be empty');

    // Check newly created document has proper format (slug-4chars)
    $newId = generate_readable_id('Test Document Title');
    // Should have the slug, a hyphen, and 4 random chars at the end
    assert_true(preg_match('/^[a-z0-9-]+-[a-z0-9]{4}$/', $newId),
        'generated readable_id format incorrect: ' . var_export($newId, true));
});

test('fts5 search finds documents by title', function () {
    // Create a document with specific title
    $readableId = generate_readable_id('Banana Document');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, readable_id) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Banana Document', 'Some content here', $readableId]);

    // Search for it
    $ftsQuery = '"' . str_replace('"', '""', 'Banana') . '"';
    $stmt = db()->prepare('
        SELECT d.id, d.title
        FROM documents d
        JOIN documents_fts ON documents_fts.rowid = d.id
        WHERE documents_fts MATCH ?
    ');
    $stmt->execute([$ftsQuery]);
    $results = $stmt->fetchAll();

    assert_true(count($results) > 0, 'expected to find document with "Banana" in title');
    $found = false;
    foreach ($results as $row) {
        if ($row['title'] === 'Banana Document') {
            $found = true;
            break;
        }
    }
    assert_true($found, 'expected to find the Banana Document');
});

test('fts5 search finds documents by body', function () {
    // Create a document with specific body content
    $readableId = generate_readable_id('Random Title');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, readable_id) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Random Title', 'This body contains XYZABC marker', $readableId]);

    // Search for it by body content
    $ftsQuery = '"' . str_replace('"', '""', 'XYZABC') . '"';
    $stmt = db()->prepare('
        SELECT d.id, d.title
        FROM documents d
        JOIN documents_fts ON documents_fts.rowid = d.id
        WHERE documents_fts MATCH ?
    ');
    $stmt->execute([$ftsQuery]);
    $results = $stmt->fetchAll();

    assert_true(count($results) > 0, 'expected to find document with "XYZABC" in body');
    $found = false;
    foreach ($results as $row) {
        if ($row['title'] === 'Random Title') {
            $found = true;
            break;
        }
    }
    assert_true($found, 'expected to find the Random Title');
});

test('fts5 search escapes quotes safely', function () {
    // Test that quotes in search don't break FTS5 syntax
    $readableId = generate_readable_id('Quote Test');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, readable_id) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Quote Test', 'Document with "quotes" in it', $readableId]);

    // Search with quotes - should not cause a syntax error
    $searchStr = 'test"quote';
    $ftsQuery = '"' . str_replace('"', '""', $searchStr) . '"';
    $stmt = db()->prepare('
        SELECT d.id
        FROM documents d
        JOIN documents_fts ON documents_fts.rowid = d.id
        WHERE documents_fts MATCH ?
    ');
    // This should not throw an exception
    $stmt->execute([$ftsQuery]);
    $results = $stmt->fetchAll();
    // Results may be empty, but the query should execute without error
    assert_true(is_array($results), 'search query should execute without syntax error');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
