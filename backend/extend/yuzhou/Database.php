<?php
namespace yuzhou;

class Database
{
    public $pdo;
    public $driver;
    private $depth = 0;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if ($this->driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec('PRAGMA busy_timeout=5000');
        } else {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    public static function connect()
    {
        $driver = getenv('DB_DRIVER') ?: 'mysql';
        if ($driver === 'sqlite') {
            if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)) {
                throw new ApiError('SQLite is restricted to development and test.', 503, 50301);
            }
            $path = getenv('DB_SQLITE_PATH') ?: dirname(dirname(__DIR__)) . '/storage/development.sqlite';
            return new self(new \PDO('sqlite:' . $path));
        }
        if ($driver !== 'mysql' || !getenv('DB_DATABASE') || !getenv('DB_USERNAME')) {
            throw new ApiError('Database is not configured.', 503, 50302);
        }
        $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306')
            . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4';
        return new self(new \PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD') ?: ''));
    }

    public function run($sql, array $params = [])
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
        $statement->execute();
        return $statement;
    }

    public function one($sql, array $params = []) { return $this->run($sql, $params)->fetch() ?: null; }
    public function all($sql, array $params = []) { return $this->run($sql, $params)->fetchAll(); }
    public function scalar($sql, array $params = []) { return $this->run($sql, $params)->fetchColumn(); }
    public function lock() { return $this->driver === 'mysql' ? ' FOR UPDATE' : ''; }

    public function insert($table, array $data)
    {
        $this->identifier($table);
        foreach (array_keys($data) as $column) $this->identifier($column);
        $this->run('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES ('
            . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update($table, $id, array $data)
    {
        $this->identifier($table);
        $sets = [];
        foreach ($data as $key => $value) { $this->identifier($key); $sets[] = $key . '=?'; }
        $params = array_values($data); $params[] = $id;
        return $this->run('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE id=?', $params)->rowCount();
    }

    private function identifier($value)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/D', $value)) throw new \LogicException('Invalid database identifier.');
    }

    public function transaction(callable $callback)
    {
        if ($this->depth > 0) return $callback();
        if ($this->driver === 'sqlite') $this->pdo->exec('BEGIN IMMEDIATE');
        else $this->pdo->beginTransaction();
        $this->depth++;
        try {
            $result = $callback();
            if ($this->driver === 'sqlite') $this->pdo->exec('COMMIT'); else $this->pdo->commit();
            $this->depth--;
            return $result;
        } catch (\Throwable $error) {
            // InnoDB can already have rolled back a deadlock victim; keep the original error for retries.
            if ($this->driver === 'sqlite') $this->pdo->exec('ROLLBACK');
            elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->depth--;
            throw $error;
        }
    }

    public function paginate($table, $where, array $params, array $query, $order = 'id DESC')
    {
        $this->identifier($table);
        $page = max(1, min(100000, (int)($query['page'] ?? 1)));
        $size = max(1, min(100, (int)($query['page_size'] ?? 20)));
        return ['items' => $this->all('SELECT * FROM ' . $table . ' WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $params),
            'total' => (int)$this->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $params), 'page' => $page, 'page_size' => $size];
    }
}
