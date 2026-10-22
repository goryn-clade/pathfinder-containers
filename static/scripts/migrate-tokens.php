<?php
/**
 * F5 — one-shot bulk migration: encrypt all legacy plaintext ESI tokens.
 *
 * On a fresh v3 deploy, existing character rows hold plaintext refresh and
 * access tokens. Lazy migration (handled inside the app) re-encrypts each
 * row the next time that character refreshes a token — fine for active
 * users, but inactive users' plaintext sits in the DB until they next log
 * in. This script eagerly encrypts every legacy row in one pass.
 *
 * Idempotent: rows already prefixed with "v1:" are skipped, so it is safe
 * to re-run.
 *
 * USAGE
 *   docker compose run --rm \
 *     -e TOKEN_ENCRYPTION_KEY=<hex> \
 *     pf php /usr/local/bin/migrate-tokens.php
 *
 * Add --dry-run to scan without writing.
 *
 * EXIT CODES
 *   0  success
 *   1  bad arguments / missing env
 */

const VERSION_PREFIX = 'v1:';

function fail(string $msg, int $code = 1) : never {
    fwrite(STDERR, "migrate-tokens: $msg\n");
    exit($code);
}

function loadKey() : string {
    $hex = getenv('TOKEN_ENCRYPTION_KEY');
    if ($hex === false || $hex === '') {
        fail('TOKEN_ENCRYPTION_KEY is not set');
    }
    try {
        $key = sodium_hex2bin($hex);
    } catch (\SodiumException $e) {
        fail('TOKEN_ENCRYPTION_KEY is not valid hex');
    }
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        fail('TOKEN_ENCRYPTION_KEY must decode to ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes (64 hex chars)');
    }
    return $key;
}

function encryptWithKey(string $plaintext, string $key) : string {
    if ($plaintext === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct    = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return VERSION_PREFIX . base64_encode($nonce . $ct);
}

function needsMigration(string $val) : bool {
    return $val !== '' && strncmp($val, VERSION_PREFIX, strlen(VERSION_PREFIX)) !== 0;
}

// --- main ----------------------------------------------------------------

$dryRun = in_array('--dry-run', $argv, true);
$key    = loadKey();

$dbHost = getenv('MYSQL_HOST') ?: 'pf-db';
$dbPort = getenv('MYSQL_PORT') ?: '3306';
$dbName = getenv('MYSQL_PF_DB_NAME') ?: 'pathfinder';
$dbUser = getenv('MYSQL_USER') ?: 'root';
$dbPass = getenv('MYSQL_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\PDOException $e) {
    fail('DB connect: ' . $e->getMessage());
}

$select = $pdo->query('SELECT id, esiAccessToken, esiRefreshToken FROM `character`');
$update = $pdo->prepare('UPDATE `character` SET esiAccessToken = :a, esiRefreshToken = :r WHERE id = :id');

$total   = 0;
$migrated = 0;
$already = 0;
$empty   = 0;

while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $total++;
    $access  = (string)$row['esiAccessToken'];
    $refresh = (string)$row['esiRefreshToken'];

    $needA = needsMigration($access);
    $needR = needsMigration($refresh);

    if (!$needA && !$needR) {
        if ($access === '' && $refresh === '') {
            $empty++;
        } else {
            $already++;
        }
        continue;
    }

    $newAccess  = $needA ? encryptWithKey($access,  $key) : $access;
    $newRefresh = $needR ? encryptWithKey($refresh, $key) : $refresh;

    if (!$dryRun) {
        $update->execute([':a' => $newAccess, ':r' => $newRefresh, ':id' => (int)$row['id']]);
    }
    $migrated++;
}

sodium_memzero($key);

printf(
    "migrate-tokens: total=%d migrated=%d already_encrypted=%d empty=%d%s\n",
    $total, $migrated, $already, $empty, $dryRun ? ' (dry-run)' : ''
);

exit(0);
