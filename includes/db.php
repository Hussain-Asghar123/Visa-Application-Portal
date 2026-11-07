<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Shared PDO connection (real prepared statements, exceptions on error). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = (string) config('db.host', '');
    $name = (string) config('db.name', '');
    if ($host === '' || $name === '') {
        throw new RuntimeException('Database is not configured (db.host / db.name are empty).');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int) config('db.port', 3306),
        $name
    );
    try {
        $pdo = new PDO($dsn, (string) config('db.user', ''), (string) config('db.pass', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    } catch (PDOException $e) {
        // Never leak credentials/hostnames: log the detail, throw a generic error.
        app_log('error', 'Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Check the db settings in config.php.');
    }
    // Keep MySQL timestamps aligned with PHP's configured timezone.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

/** Run a prepared statement and return it. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []): mixed
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    foreach ($cols as $c) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $c)) {
            throw new InvalidArgumentException('Invalid column name');
        }
    }
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    db_run($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** UPDATE helper. $where is a fixed SQL fragment with placeholders, e.g. 'id = ?'. */
function db_update(string $table, array $data, string $where, array $whereParams): int
{
    $sets = [];
    foreach (array_keys($data) as $c) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $c)) {
            throw new InvalidArgumentException('Invalid column name');
        }
        $sets[] = '`' . $c . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return db_run($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Execute one of the app's own .sql files (simple splitter; the files contain no procedures). */
function db_run_sql_file(string $path, ?PDO $pdo = null): int
{
    $pdo ??= db();
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $count = 0;
    foreach (preg_split('/;\s*(\r?\n|$)/', (string) $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
            $count++;
        }
    }
    return $count;
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = db_value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, ?string $value, ?int $userId): void
{
    db_run(
        'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$key, $value, $userId]
    );
}
