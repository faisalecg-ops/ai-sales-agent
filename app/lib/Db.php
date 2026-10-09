<?php
declare(strict_types=1);

/**
 * Thin MySQLi wrapper. Every query goes through a prepared statement.
 * There is no interface here for concatenating values into SQL.
 */
final class Db
{
    private static ?Db $instance = null;
    private mysqli $link;
    private int $txDepth = 0;

    private function __construct()
    {
        $host = (string)cfg('db.host', 'localhost');
        $name = (string)cfg('db.name', '');
        $user = (string)cfg('db.user', '');
        $pass = (string)cfg('db.pass', '');
        $port = (int)cfg('db.port', 3306);
        $sock = cfg('db.socket');

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->link = $sock
                ? new mysqli($host, $user, $pass, $name, $port, (string)$sock)
                : new mysqli($host, $user, $pass, $name, $port);
        } catch (Throwable $ex) {
            app_log('db', 'connection failed', ['error' => $ex->getMessage()]);
            throw new RuntimeException('database_unavailable', 0, $ex);
        }
        $this->link->set_charset((string)cfg('db.charset', 'utf8mb4'));
        $this->link->query("SET time_zone = '+00:00'");
        $this->link->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    public static function get(): Db
    {
        return self::$instance ??= new Db();
    }

    public function raw(): mysqli
    {
        return $this->link;
    }

    /**
     * Run a prepared statement. Params are bound by inferred type.
     */
    public function run(string $sql, array $params = []): mysqli_stmt
    {
        $stmt = $this->link->prepare($sql);
        if ($params) {
            $types = '';
            foreach ($params as $p) {
                $types .= match (true) {
                    is_int($p)   => 'i',
                    is_float($p) => 'd',
                    is_null($p)  => 's',
                    default      => 's',
                };
            }
            $stmt->bind_param($types, ...array_values($params));
        }
        $stmt->execute();
        return $stmt;
    }

    /** Fetch a single row or null. */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $res  = $stmt->get_result();
        $row  = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    /** Fetch all rows. */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $res  = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** Fetch a single scalar value. */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row ? reset($row) : null;
    }

    /** Execute a write; returns affected rows. */
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $n    = $stmt->affected_rows;
        $stmt->close();
        return $n;
    }

    /** Insert and return the new id. */
    public function insert(string $table, array $data): int
    {
        $cols  = array_keys($data);
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $sql   = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $marks . ')';
        $this->exec($sql, array_values($data));
        return (int)$this->link->insert_id;
    }

    /** Update rows matching a where clause. */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = '`' . $col . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
        return $this->exec($sql, array_merge(array_values($data), $whereParams));
    }

    public function lastId(): int
    {
        return (int)$this->link->insert_id;
    }

    // -----------------------------------------------------------------
    // Transactions (nestable via savepoint-free depth counting)
    // -----------------------------------------------------------------
    public function begin(): void
    {
        if ($this->txDepth === 0) {
            $this->link->begin_transaction();
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth > 0) {
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->link->commit();
            }
        }
    }

    public function rollback(): void
    {
        if ($this->txDepth > 0) {
            $this->txDepth = 0;
            $this->link->rollback();
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }
}
