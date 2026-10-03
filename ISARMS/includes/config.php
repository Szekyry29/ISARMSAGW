<?php
/**
 * ISARMS - Database Configuration
 * NEUST Talavera Off-Campus
 */

$localDbConfig = [];
$localDbConfigPath = __DIR__ . '/config.local.php';
if (is_file($localDbConfigPath)) {
    $loadedDbConfig = require $localDbConfigPath;
    if (is_array($loadedDbConfig)) {
        $localDbConfig = $loadedDbConfig;
    }
}
$databaseConfigValue = static function (string $environmentKey, string $localKey, string $default = '') use ($localDbConfig): string {
    $environmentValue = getenv($environmentKey);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }
    return (string)($localDbConfig[$localKey] ?? $default);
};

define('DB_HOST',     $databaseConfigValue('ISARMS_DB_HOST', 'host', '127.0.0.1'));
define('DB_PORT',     $databaseConfigValue('ISARMS_DB_PORT', 'port', '3306'));
define('DB_USER',     $databaseConfigValue('ISARMS_DB_USER', 'user'));
define('DB_PASS',     $databaseConfigValue('ISARMS_DB_PASS', 'pass'));
define('DB_NAME',     $databaseConfigValue('ISARMS_DB_NAME', 'name', 'isarms_db'));
define('DB_CHARSET',  'utf8mb4');

if (DB_USER === '' || DB_PASS === '') {
    error_log('ISARMS database credentials are not configured.');
    http_response_code(500);
    exit('Database configuration is required.');
}

define('APP_NAME',    'ISARMS');
define('APP_TITLE',   'Integrated Student Academic Records Management System');
define('APP_CAMPUS',  'NEUST Talavera Off-Campus');
define('APP_VERSION', '1.0.0');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = $scriptPath;
if (preg_match('#/modules/.+$#', $scriptPath)) {
    $basePath = preg_replace('#/modules/.*$#', '', $scriptPath);
} else {
    $basePath = preg_replace('#/[^/]+\.php$#', '', $scriptPath);
}
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
}
$baseUrl = rtrim($scheme . '://' . $host . $basePath, '/') . '/';
define('BASE_URL', $baseUrl);
// Session timeout in seconds (30 minutes)
define('SESSION_TIMEOUT', 1800);

// GWA Grade Scale (NEUST 5-point system)
define('GRADE_PASSING',   3.00);  // Highest numeric = worst
define('GRADE_PERFECT',   1.00);  // Lowest numeric = best

// Latin Honors Thresholds
define('SUMMA_MIN',        1.00);
define('SUMMA_MAX',        1.20);
define('MAGNA_MIN',        1.21);
define('MAGNA_MAX',        1.45);
define('CUMLAUD_MIN',      1.46);
define('CUMLAUD_MAX',      1.75);
define('DEAN_MIN',         1.00);
define('DEAN_MAX',         1.75);
define('HONORS_NO_BELOW',  2.00);  // No single grade below this for honors
define('YEAR4_HIGH_HONORS_MAX', 2.25);  // Year 4: GWA must be below this for Cum Laude+

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        // ✅ Added port in DSN
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }

    /**
     * Execute a prepared statement and return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Insert and return last insert ID
     */
    public function insert(string $sql, array $params = []): int {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void            { $this->pdo->commit(); }
    public function rollback(): void          { $this->pdo->rollBack(); }
}