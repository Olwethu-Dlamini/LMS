<?php
/**
 * Database Connection Wrapper using PDO
 *
 * constants.php is loaded first, and deliberately, because it loads
 * config/local.php - which is where a server may set the connection details with
 * putenv(). The defines below read the environment at the moment this file is
 * loaded, so a caller that required this file before constants.php would read
 * the environment before local.php had written to it, fall through to the
 * development defaults, and try to connect as root.
 *
 * That failure was invisible for a long time because web requests get their
 * DB_* values from the PHP-FPM pool, which is set before PHP starts. Only the
 * command-line tools, which have no pool, ever saw it: the site worked and
 * `php tools/send_queued_email.php` reported access denied for root. Requiring
 * constants.php here rather than trusting every caller to order two lines
 * correctly means load order stops being something anybody has to remember.
 */
require_once __DIR__ . '/constants.php';

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'lms_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    return $pdo;
}
