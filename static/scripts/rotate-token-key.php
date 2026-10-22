<?php
/**
 * F5 — TOKEN_ENCRYPTION_KEY rotation utility.
 *
 * Re-encrypts every character's esiAccessToken / esiRefreshToken from the old
 * key to the new key. Intended for offline use during a maintenance window:
 * stop the pf container or scale it to 0, run this script with both keys in
 * env, then start the container back up with the new key in .env.
 *
 * Without re-encryption, rotating TOKEN_ENCRYPTION_KEY makes all stored tokens
 * unusable (MAC failure) and forces every user to re-login via SSO.
 *
 * USAGE
 *   docker compose run --rm \
 *     -e OLD_TOKEN_ENCRYPTION_KEY=<old hex> \
 *     -e NEW_TOKEN_ENCRYPTION_KEY=<new hex> \
 *     pf php /usr/local/bin/rotate-token-key.php
 *
 * Add --dry-run as a positional argument to scan without writing.
 *
 * EXIT CODES
 *   0  success
 *   1  bad arguments / missing env
 *   2  decrypt failure on at least one row (skipped; user will re-login)
 */

const VERSION_PREFIX = 'v1:';

function fail(string $msg, int $code = 1) : never {
    fwrite(STDERR, "rotate-token-key: $msg\n");
    exit($code);
}

function loadKey(string $envVar) : string {
    $hex = getenv($envVar);
    if ($hex === false || $hex === '') {
        fail("$envVar is not set");
    }
    try {
        $key = sodium_hex2bin($hex);
    } catch (\SodiumException $e) {
        fail("$envVar is not valid hex");
    }
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        fail("$envVar must decode to " . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . " bytes (64 hex chars)");
    }
    return $key;
}

function decryptWithKey(string $blob, string $key) : ?string {
    if ($blob === '') return '';
    if (strncmp($blob, VERSION_PREFIX, strlen(VERSION_PREFIX)) !== 0) {
        // legacy plaintext — pass through, will be encrypted with new key
        return $blob;
    }
    $raw = base64_decode(substr($blob, strlen(VERSION_PREFIX)), true);
    $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    if ($raw === false || strlen($raw) < $min) return null;
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $pt    = sodium_crypto_secretbox_open($ct, $nonce, $key);
    return $pt === false ? null : $pt;
}

function encryptWithKey(string $plaintext, string $key) : string {
    if ($plaintext === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct    = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return VERSION_PREFIX . base64_encode($nonce . $ct);
}

// --- main ----------------------------------------------------------------

$dryRun = in_array('--dry-run', $argv, true);

$oldKey = loadKey('OLD_TOKEN_ENCRYPTION_KEY');
$newKey = loadKey('NEW_TOKEN_ENCRYPTION_KEY');

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
    fail("DB connect: " . $e->getMessage());
}

$select = $pdo->query('SELECT id, esiAccessToken, esiRefreshToken FROM `character` WHERE esiAccessToken <> "" OR esiRefreshToken <> ""');
$update = $pdo->prepare('UPDATE `character` SET esiAccessToken = :a, esiRefreshToken = :r WHERE id = :id');

$total = 0;
$rotated = 0;
$failed = 0;
$skipped = 0;

while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $total++;
    $id = (int)$row['id'];

    $accessPlain  = decryptWithKey((string)$row['esiAccessToken'],  $oldKey);
    $refreshPlain = decryptWithKey((string)$row['esiRefreshToken'], $oldKey);

    if ($accessPlain === null || $refreshPlain === null) {
        fwrite(STDERR, "char $id: decrypt failed — leaving row as-is (user will re-login)\n");
        $failed++;
        continue;
    }

    $newAccess  = encryptWithKey((string)$accessPlain,  $newKey);
    $newRefresh = encryptWithKey((string)$refreshPlain, $newKey);

    if ($newAccess === $row['esiAccessToken'] && $newRefresh === $row['esiRefreshToken']) {
        $skipped++;
        continue;
    }

    if (!$dryRun) {
        $update->execute([':a' => $newAccess, ':r' => $newRefresh, ':id' => $id]);
    }
    $rotated++;
}

sodium_memzero($oldKey);
sodium_memzero($newKey);

printf(
    "rotate-token-key: total=%d rotated=%d skipped=%d failed=%d%s\n",
    $total, $rotated, $skipped, $failed, $dryRun ? ' (dry-run)' : ''
);

exit($failed > 0 ? 2 : 0);
