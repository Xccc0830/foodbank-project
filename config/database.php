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
    $debug = $debugEnv === false ? false : filter_var($debugEnv, FILTER_VALIDATE_BOOLEAN);
    define('APP_DEBUG', $debug);
}

// Browser key for Maps JavaScript API + Places; restrict it by HTTP referrer in Google Cloud Console.
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}

if (!defined('DB_PORT')) {
    $defaultPort = (getenv('DATABASE_URL') || getenv('DB_DRIVER') === 'pgsql') ? 5432 : 3306;
    define('DB_PORT', (int) (getenv('DB_PORT') ?: $defaultPort));
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

if (!defined('DB_DRIVER')) {
    define('DB_DRIVER', getenv('DB_DRIVER') ?: (getenv('DATABASE_URL') ? 'pgsql' : 'mysql'));
}

if (!defined('DB_SCHEMA')) {
    define('DB_SCHEMA', getenv('DB_SCHEMA') ?: 'shinigyi_foodbank');
}

class PostgresResult
{
    private $rows;
    private $position = 0;
    public $num_rows;

    public function __construct(PDOStatement $statement)
    {
        $this->rows = $statement->columnCount() > 0
            ? $statement->fetchAll(PDO::FETCH_ASSOC)
            : [];
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc()
    {
        return $this->rows[$this->position++] ?? null;
    }

    public function fetch_row()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    public function fetch_all($mode = MYSQLI_ASSOC)
    {
        $rows = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);
        if ($mode === MYSQLI_NUM) {
            return array_map('array_values', $rows);
        }
        if ($mode === MYSQLI_BOTH) {
            return array_map(static function ($row) {
                $combined = $row;
                foreach (array_values($row) as $index => $value) {
                    $combined[$index] = $value;
                }
                return $combined;
            }, $rows);
        }
        return $rows;
    }

    public function free()
    {
        $this->rows = [];
        $this->position = 0;
        $this->num_rows = 0;
    }
}

class PostgresStatement
{
    private $connection;
    private $statement;
    private $parameters = [];
    private $sql;
    public $error = '';
    public $affected_rows = 0;

    public function __construct(PostgresConnection $connection, PDOStatement $statement, $sql)
    {
        $this->connection = $connection;
        $this->statement = $statement;
        $this->sql = $sql;
    }

    public function bind_param($types, &...$values)
    {
        $this->parameters = [];
        foreach ($values as &$value) {
            $this->parameters[] = &$value;
        }
        return true;
    }

    public function execute()
    {
        $success = $this->statement->execute($this->parameters);
        if (!$success) {
            $this->error = $this->connection->error = $this->statement->errorInfo()[2] ?? 'Database statement failed.';
            error_log('PostgreSQL statement failed: ' . $this->error);
            return false;
        }
        $this->error = '';
        $this->connection->recordStatement($this->statement, $this->sql);
        $this->affected_rows = $this->statement->rowCount();
        return true;
    }

    public function get_result()
    {
        return new PostgresResult($this->statement);
    }

    public function close()
    {
        $this->statement->closeCursor();
        return true;
    }
}

class PostgresConnection
{
    private $pdo;
    public $error = '';
    public $insert_id = 0;
    public $affected_rows = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function query($sql)
    {
        $sql = $this->translateSql($sql);
        if ($sql === null) {
            return false;
        }

        $statement = $this->pdo->query($sql);
        if (!$statement) {
            $this->setError($this->pdo->errorInfo()[2] ?? 'Database query failed.');
            return false;
        }

        $this->recordStatement($statement, $sql);
        return $statement->columnCount() > 0 ? new PostgresResult($statement) : true;
    }

    public function prepare($sql)
    {
        $sql = $this->translateSql($sql);
        if ($sql === null) {
            return false;
        }

        $statement = $this->pdo->prepare($sql);
        if (!$statement) {
            $this->setError($this->pdo->errorInfo()[2] ?? 'Database statement preparation failed.');
            return false;
        }
        return new PostgresStatement($this, $statement, $sql);
    }

    public function real_escape_string($value)
    {
        $value = (string) $value;
        if (strpos($value, "\0") !== false) {
            throw new InvalidArgumentException('PostgreSQL text values cannot contain a NUL byte.');
        }
        return str_replace("'", "''", $value);
    }

    public function begin_transaction()
    {
        return $this->runTransactionMethod('beginTransaction');
    }

    public function commit()
    {
        return $this->runTransactionMethod('commit');
    }

    public function rollback()
    {
        return $this->runTransactionMethod('rollBack');
    }

    public function close()
    {
        $this->pdo = null;
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function recordStatement(PDOStatement $statement, $sql = '')
    {
        $this->affected_rows = $statement->rowCount();
        $this->insert_id = 0;
        if ($sql !== '' && preg_match('/^\s*INSERT\s+INTO\s+(?:[a-zA-Z0-9_]+\.)?([a-zA-Z0-9_]+)/i', $sql, $match)) {
            $table = $match[1];
            $sequence = $this->pdo->prepare(
                "SELECT pg_get_serial_sequence(quote_ident(current_schema()) || '.' || quote_ident(?), column_name)
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ?
                   AND (column_default LIKE 'nextval(%' OR is_identity = 'YES')
                 ORDER BY ordinal_position
                 LIMIT 1"
            );
            if ($sequence && $sequence->execute([$table, $table])) {
                $sequenceName = $sequence->fetchColumn();
                if ($sequenceName) {
                    $lastId = $this->pdo->prepare('SELECT currval(CAST(? AS regclass))');
                    if ($lastId && $lastId->execute([$sequenceName])) {
                        $this->insert_id = (int) $lastId->fetchColumn();
                    }
                }
            }
        }
        $this->error = '';
    }

    private function runTransactionMethod($method)
    {
        $success = $this->pdo->{$method}();
        if (!$success) {
            $this->setError('PostgreSQL transaction operation failed.');
        }
        return $success;
    }

    private function setError($message)
    {
        $this->error = $message;
        error_log('PostgreSQL query failed: ' . $message);
    }

    private function translateSql($sql)
    {
        if (preg_match("/^\\s*SHOW\\s+COLUMNS\\s+FROM\\s+([a-zA-Z0-9_]+)\\s+LIKE\\s+'([^']+)'/i", $sql, $match)) {
            $table = $this->quoteIdentifier($match[1]);
            $column = $this->real_escape_string($match[2]);
            return "SELECT column_name AS \"Field\", data_type AS \"Type\" FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$table}' AND column_name = '{$column}'";
        }
        if (preg_match("/^\\s*SHOW\\s+TABLES\\s+LIKE\\s+'([^']+)'/i", $sql, $match)) {
            $table = $this->real_escape_string($match[1]);
            return "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name = '{$table}'";
        }
        if (preg_match("/^\\s*SHOW\\s+INDEX\\s+FROM\\s+([a-zA-Z0-9_]+)\\s+WHERE\\s+Key_name\\s*=\\s*'([^']+)'/i", $sql, $match)) {
            $table = $this->real_escape_string($match[1]);
            $index = $this->real_escape_string($match[2]);
            return "SELECT indexname AS \"Key_name\" FROM pg_indexes WHERE schemaname = current_schema() AND tablename = '{$table}' AND indexname = '{$index}'";
        }

        if (preg_match('/^\\s*CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+([a-zA-Z0-9_]+)/i', $sql, $match)) {
            $table = $this->real_escape_string($match[1]);
            $check = $this->pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = '{$table}'");
            if (!$check || !$check->fetchColumn()) {
                $this->setError("Required PostgreSQL table '{$table}' is missing; migrate the schema before deploying.");
                return null;
            }
            return 'SELECT 1';
        }

        $sql = preg_replace('/DATE_ADD\\(NOW\\(\\),\\s*INTERVAL\\s+([0-9]+)\\s+(SECOND|MINUTE|HOUR|DAY)\\)/i', "(CURRENT_TIMESTAMP + INTERVAL '\\1 \\2')", $sql);
        $sql = preg_replace('/DATE_FORMAT\\(\\s*([a-zA-Z0-9_.]+)\\s*,\\s*\'%Y-%m\'\\s*\\)/i', "TO_CHAR(\\1, 'YYYY-MM')", $sql);
        $sql = preg_replace('/DATE_FORMAT\\(\\s*([a-zA-Z0-9_.]+)\\s*,\\s*\'%Y%m%d\'\\s*\\)/i', "TO_CHAR(\\1, 'YYYYMMDD')", $sql);
        $sql = preg_replace('/\\bCURDATE\\(\\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/\\bNOW\\(\\)/i', 'CURRENT_TIMESTAMP', $sql);
        $sql = preg_replace('/\\s+AFTER\\s+[a-zA-Z0-9_]+/i', '', $sql);
        $sql = preg_replace('/\\bDATETIME\\b/i', 'TIMESTAMP', $sql);
        $sql = preg_replace('/\\s+UNSIGNED\\b/i', '', $sql);
        $sql = preg_replace('/\\bADD\\s+([a-zA-Z0-9_]+)\\s+ENUM\\(([^)]*)\\)/i', 'ADD COLUMN \\1 VARCHAR(255)', $sql);
        $sql = preg_replace('/\\bADD\\s+COLUMN\\s+([a-zA-Z0-9_]+)\\s+ENUM\\(([^)]*)\\)/i', 'ADD COLUMN \\1 VARCHAR(255)', $sql);
        $sql = preg_replace('/\\bADD\\s+UNIQUE\\s+KEY\\s+([a-zA-Z0-9_]+)\\s*\\(([^)]+)\\)/i', 'ADD CONSTRAINT \\1 UNIQUE (\\2)', $sql);
        $sql = preg_replace('/\\bDELETE\\s+FROM\\s+([a-zA-Z0-9_]+)(\\s+WHERE\\s+.+?)\\s+LIMIT\\s+\\d+\\s*$/is', 'DELETE FROM \\1\\2', $sql);
        $sql = preg_replace('/\\bUPDATE\\s+([a-zA-Z0-9_]+)(\\s+SET\\s+.+?)\\s+WHERE\\s+(.+?)\\s+LIMIT\\s+\\d+\\s*$/is', 'UPDATE \\1\\2 WHERE \\3', $sql);
        $sql = preg_replace('/\\bORDER\\s+BY\\s+FIELD\\(a\\.status,\\s*\'pending\',\\s*\'approved\',\\s*\'rejected\'\\)/i', "ORDER BY CASE a.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END", $sql);
        $sql = preg_replace('/\\bORDER\\s+BY\\s+FIELD\\(u\\.status,\\s*\'inactive\',\\s*\'active\',\\s*\'suspended\'\\)/i', "ORDER BY CASE u.status WHEN 'inactive' THEN 1 WHEN 'active' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END", $sql);
        $sql = preg_replace('/SUM\\(([^()]+)\\s*=\\s*\'([^\']+)\'\\)/i', "SUM(CASE WHEN \\1 = '\\2' THEN 1 ELSE 0 END)", $sql);
        $sql = preg_replace('/SUM\\(([^()]+)\\s+NOT\\s+IN\\s*\\(([^)]+)\\)\\)/i', 'SUM(CASE WHEN \\1 NOT IN (\\2) THEN 1 ELSE 0 END)', $sql);
        $sql = preg_replace('/\\bGET_LOCK\\(\\?,\\s*\\d+\\)/i', 'pg_try_advisory_lock(hashtext(?))', $sql);
        $sql = preg_replace('/\\bRELEASE_LOCK\\(\\?\\)/i', 'pg_advisory_unlock(hashtext(?))', $sql);
        $sql = preg_replace('/ON\\s+DUPLICATE\\s+KEY\\s+UPDATE\\s+receipt_id\\s*=\\s*receipt_id/i', 'ON CONFLICT DO NOTHING', $sql);

        return $sql;
    }

    private function quoteIdentifier($identifier)
    {
        return str_replace('"', '""', $identifier);
    }
}

class PostgresSessionHandler implements SessionHandlerInterface
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $created = $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_sessions (
                session_id VARCHAR(128) PRIMARY KEY,
                session_data TEXT NOT NULL,
                expires_at BIGINT NOT NULL
            )'
        );
        if ($created === false) {
            $message = $this->pdo->errorInfo()[2] ?? 'Could not initialize session storage.';
            error_log('PostgreSQL session storage initialization failed: ' . $message);
            throw new RuntimeException('Could not initialize PostgreSQL session storage.');
        }
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        $statement = $this->pdo->prepare('SELECT session_data FROM app_sessions WHERE session_id = ? AND expires_at > ?');
        if (!$statement || !$statement->execute([$id, time()])) {
            $this->logFailure('read');
            return false;
        }
        $data = $statement->fetchColumn();
        return $data === false ? '' : $data;
    }

    public function write($id, $data): bool
    {
        $expires = time() + (int) ini_get('session.gc_maxlifetime');
        $statement = $this->pdo->prepare(
            'INSERT INTO app_sessions (session_id, session_data, expires_at)
             VALUES (?, ?, ?)
             ON CONFLICT (session_id) DO UPDATE
             SET session_data = EXCLUDED.session_data, expires_at = EXCLUDED.expires_at'
        );
        if (!$statement || !$statement->execute([$id, $data, $expires])) {
            $this->logFailure('write');
            return false;
        }
        return true;
    }

    public function destroy($id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM app_sessions WHERE session_id = ?');
        if (!$statement || !$statement->execute([$id])) {
            $this->logFailure('destroy');
            return false;
        }
        return true;
    }

    public function gc($max_lifetime): int|false
    {
        $statement = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at <= ?');
        if (!$statement || !$statement->execute([time()])) {
            $this->logFailure('garbage collection');
            return false;
        }
        return $statement->rowCount();
    }

    private function logFailure($operation)
    {
        $message = $this->pdo->errorInfo()[2] ?? 'Unknown database error.';
        error_log("PostgreSQL session {$operation} failed: {$message}");
    }
}

class Database
{
    /** @var mysqli|PostgresConnection|null */
    private $connection = null;

    public function __construct()
    {
        $this->connect();
    }

    public function getConnection()
    {
        if ($this->connection === null) {
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
        if (DB_DRIVER === 'pgsql') {
            $this->connectPostgres();
            return;
        }

        if (DB_DRIVER !== 'mysql') {
            $this->abort('Unsupported DB_DRIVER. Use mysql or pgsql.');
        }

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

    private function connectPostgres()
    {
        if (!class_exists('PDO') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            $this->abort('PHP pdo_pgsql extension is not enabled.');
        }

        $url = getenv('DATABASE_URL');
        if ($url) {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host']) || empty($parts['path'])) {
                $this->abort('DATABASE_URL must be a valid PostgreSQL connection URL.');
            }
            $host = $parts['host'];
            $port = $parts['port'] ?? 5432;
            $database = ltrim($parts['path'], '/');
            $user = rawurldecode($parts['user'] ?? '');
            $password = rawurldecode($parts['pass'] ?? '');
            parse_str($parts['query'] ?? '', $query);
            $sslMode = $query['sslmode'] ?? 'require';
        } else {
            $host = DB_HOST;
            $port = DB_PORT;
            $database = DB_NAME;
            $user = DB_USER;
            $password = DB_PASS;
            $sslMode = getenv('DB_SSLMODE') ?: 'require';
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', DB_SCHEMA)) {
            $this->abort('DB_SCHEMA contains unsupported characters.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode);
        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('SET search_path TO "' . DB_SCHEMA . '", public');
            $this->connection = new PostgresConnection($pdo);
        } catch (PDOException $exception) {
            $this->abort('Database connection failed: ' . $exception->getMessage());
        }
    }

    private function abort($detail)
    {
        error_log('Database initialization failed: ' . $detail);
        http_response_code(500);

        if (APP_DEBUG) {
            exit($detail);
        }

        exit('系統暫時無法連線資料庫，請稍後再試。');
    }
}

$db = new Database();
