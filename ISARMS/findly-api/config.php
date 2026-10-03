<?php
declare(strict_types=1);

$localConfig = [];
$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $loadedConfig = require $localConfigPath;
    if (is_array($loadedConfig)) $localConfig = $loadedConfig;
}
$configValue = static function (string $environmentKey, string $localKey, string $default = '') use ($localConfig): string {
    $environmentValue = getenv($environmentKey);
    return $environmentValue !== false && $environmentValue !== ''
        ? $environmentValue
        : (string)($localConfig[$localKey] ?? $default);
};

define('DB_HOST', $configValue('FINDLY_DB_HOST', 'host', '127.0.0.1'));
define('DB_PORT', $configValue('FINDLY_DB_PORT', 'port', '3306'));
define('DB_NAME', $configValue('FINDLY_DB_NAME', 'name', 'findly'));
define('DB_USER', $configValue('FINDLY_DB_USER', 'user'));
define('DB_PASS', $configValue('FINDLY_DB_PASS', 'pass'));

if (DB_USER === '' || DB_PASS === '') {
    throw new RuntimeException('Findly database credentials are not configured.');
}

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
