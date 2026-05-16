<?php
/**
 * Setup script called by entrypoint.sh on first boot.
 * Handles: post-seed SQL patches, channel hostname update.
 * Uses direct PDO to avoid Symfony container init overhead.
 */

$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    echo "[setup] DATABASE_URL not set, skipping\n";
    exit(0);
}

// Parse DATABASE_URL: pgsql://user:pass@host:port/dbname?...
preg_match('#://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', $dbUrl, $m);
if (count($m) < 6) {
    echo "[setup] Could not parse DATABASE_URL\n";
    exit(0);
}

try {
    $pdo = new PDO('pgsql:host=' . $m[3] . ';port=' . $m[4] . ';dbname=' . $m[5], $m[1], $m[2]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo "[setup] DB connection failed: " . $e->getMessage() . "\n";
    exit(0);
}

// Task 1: Apply post-seed SQL (on first run only — when called with 'post-seed' arg)
if (in_array('post-seed', $argv ?? [])) {
    $sqlFile = __DIR__ . '/../scripts/phase2-post-seed.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (Exception $e) {
                echo "[setup] SQL warning: " . $e->getMessage() . "\n";
            }
        }
        echo "[setup] Post-seed SQL applied\n";
    }
}

// Task 2: Count channels (for seeding check)
if (in_array('count-channels', $argv ?? [])) {
    $row = $pdo->query('SELECT COUNT(*) FROM sylius_channel')->fetchColumn();
    echo intval($row);
    exit(0);
}

// Task 3: Update channel hostname (always run)
if (in_array('hostname', $argv ?? [])) {
    $defaultUri = getenv('DEFAULT_URI') ?: 'http://localhost';
    $hostname = str_replace(array('https://', 'http://'), '', $defaultUri);
    $stmt = $pdo->prepare('UPDATE sylius_channel SET hostname = ? WHERE code = ?');
    $stmt->execute(array($hostname, 'LITTLE_CHAPTERS_BE_FR'));
    echo "[setup] Channel hostname set to: " . $hostname . "\n";
}
