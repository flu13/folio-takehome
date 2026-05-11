<?php

$pdo = new PDO('sqlite:' . __DIR__ . '/db.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = OFF');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        executed_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
");

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

foreach ($files as $path) {
    $filename = basename($path);

    $stmt = $pdo->prepare('SELECT id FROM migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) {
        echo "Skipping $filename (already applied)\n";
        continue;
    }

    $sql = file_get_contents($path);
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
        $pdo->commit();
        echo "Applied $filename\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Failed to apply $filename: " . $e->getMessage() . "\n";
        exit(1);
    }
}

$pdo->exec('PRAGMA foreign_keys = ON');

