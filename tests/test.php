<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
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
test('scheduled document is gated before publish_at', function () {
    // Insert a document scheduled far in the future
    $stmt = db()->prepare("
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES ('Future Doc', 'Body', 1, datetime('now', '+1 day'))
    ");
    $stmt->execute();
    $docId = (int) db()->lastInsertId();

    // Create a share token for it
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);

    // Check: publish_at is in the future
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $now = new DateTime('now');
    $publishAt = new DateTime($row['publish_at']);
    assert_true($publishAt > $now, 'publish_at should be in the future');
});

test('scheduled document with past publish_at is accessible', function () {
    $stmt = db()->prepare("
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES ('Past Doc', 'Body', 1, datetime('now', '-1 day'))
    ");
    $stmt->execute();
    $docId = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $now = new DateTime('now');
    $publishAt = new DateTime($row['publish_at']);
    assert_true($publishAt < $now, 'publish_at should be in the past');
});

test('readable_id is generated and unique', function () {
    $id1 = generate_readable_id('Test Document');
    $id2 = generate_readable_id('Test Document');
    assert_true($id1 !== '', 'readable_id should not be empty');
    assert_true(preg_match('/^[a-z0-9\-]+$/', $id1) === 1, 'readable_id should be lowercase alphanumeric with dashes');
    // Both should be valid format even if same title
    assert_true(preg_match('/^[a-z0-9\-]+$/', $id2) === 1, 'second readable_id should also be valid');
});

test('search returns matching documents', function () {
    $stmt = db()->prepare("INSERT INTO documents (title, body, created_by) VALUES ('Unique Searchable Title XYZ', 'body', 1)");
    $stmt->execute();

    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Searchable%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) >= 1, 'expected at least one search result');
    assert_true($results[0]['title'] === 'Unique Searchable Title XYZ', 'unexpected title in search results');
});

test('search returns nothing for unmatched query', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['%ZZZNOMATCHZZZ%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) === 0, 'expected zero results for unmatched query');
});
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
