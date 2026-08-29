<?php

declare(strict_types=1);

// Stands in for public/index.php in test.bats: a project with no TYPO3, whose
// only job is to report whether the webserver forwarded the test ID header to
// PHP and whether a per-test database can be reached from there.

header('Content-Type: text/plain');

$testId = trim((string) ($_SERVER['HTTP_X_PLAYWRIGHT_TEST_ID'] ?? ''));
echo 'HTTP_X_PLAYWRIGHT_TEST_ID=' . $testId . "\n";

if ('' === $testId) {
    echo "RESULT=no-test-id\n";
    exit;
}

if (1 !== preg_match('/^[A-Z0-9]{16}$/', $testId)) {
    echo "RESULT=invalid-test-id\n";
    exit;
}

$dbName = 'db' . $testId;
$adminDsn = 'pgsql:host=db-test;port=5432;dbname=postgres';

try {
    $admin = new PDO($adminDsn, 'db', 'db', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exists = $admin->query(
        "SELECT 1 FROM pg_database WHERE datname = " . $admin->quote($dbName)
    )->fetchColumn();
    if (false === $exists) {
        $admin->exec(sprintf('CREATE DATABASE "%s" WITH OWNER db', $dbName));
    }

    $probe = new PDO(
        'pgsql:host=db-test;port=5432;dbname=' . $dbName,
        'db',
        'db',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $current = $probe->query('SELECT current_database()')->fetchColumn();
    echo 'CONNECTED_DB=' . $current . "\n";
    echo 'RESULT=ok' . "\n";
} catch (\Throwable $e) {
    echo 'RESULT=error: ' . $e->getMessage() . "\n";
}
