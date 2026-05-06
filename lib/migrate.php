<?php

require_once __DIR__ . '/bootstrap.php';

db()->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename TEXT PRIMARY KEY,
    run_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    $stmt = db()->prepare('SELECT filename FROM schema_migrations WHERE filename = ?');
    $stmt->execute([$name]);
    if (!$stmt->fetch()) {
        db()->exec(file_get_contents($file));
        $stmt = db()->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        echo "Migration ran: $name\n";
    }
}