<?php
/**
 * Database and application configuration.
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', getenv('APP_NAME') ?: '食物銀行管理系統');
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', getenv('APP_VERSION') ?: '1.0.0');
}

if (!defined('APP_URL')) {
    $appUrlEnv = getenv('APP_URL');
    if ($appUrlEnv !== false && $appUrlEnv !== '') {
        define('APP_URL', rtrim($appUrlEnv, '/'));
    } else {
        $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '';
        if ($scriptDir === '\\' || $scriptDir === '.') {
            $scriptDir = '';
        }

        define('APP_URL', rtrim(str_replace('\\', '/', $scriptDir), '/'));
    }
}

if (!defined('APP_DEBUG')) {
    $debugEnv = getenv('APP_DEBUG');
    $debug = $debugEnv === false ? true : filter_var($debugEnv, FILTER_VALIDATE_BOOLEAN);
    define('APP_DEBUG', $debug);
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}

if (!defined('DB_PORT')) {
    define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
}

if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'shinigyi_foodbank');
}

if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}

if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') === false ? '' : getenv('DB_PASS'));
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
}

class Database
{
    /** @var mysqli|null */
    private $connection = null;

    public function __construct()
    {
        $this->connect();
    }

    public function getConnection()
    {
        if (!($this->connection instanceof mysqli)) {
            $this->connect();
        }

        return $this->connection;
    }

    public function query($sql)
    {
        return $this->getConnection()->query($sql);
    }

    private function connect()
    {
        if (!class_exists('mysqli')) {
            $this->abort('PHP mysqli extension is not enabled.');
        }

        mysqli_report(MYSQLI_REPORT_OFF);

        $this->connection = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

        if ($this->connection->connect_errno) {
            $this->abort('Database connection failed: ' . $this->connection->connect_error);
        }

        if (!$this->connection->set_charset(DB_CHARSET)) {
            $this->abort('Failed to set database charset: ' . DB_CHARSET);
        }
    }

    private function abort($detail)
    {
        http_response_code(500);

        if (APP_DEBUG) {
            exit($detail);
        }

        exit('系統暫時無法連線資料庫，請稍後再試。');
    }
}

$db = new Database();
