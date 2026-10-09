<?php
declare(strict_types=1);

/**
 * Thin MySQLi wrapper. Every query is a prepared statement.
 *
 * Table names are written as {p}name in SQL; {p} becomes the table prefix
 * chosen at installation (default "blog_"), so the blog can share a
 * database with a main website on shared hosting without any clash.
 */
final class Db
{
    private static ?Db $instance = null;
    private mysqli $link;
    private string $prefix;
    private int $depth = 0;

    private function __construct()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->link = new mysqli(
                (string)cfg('db.host', 'localhost'),
                (string)cfg('db.user', ''),
                (string)cfg('db.pass', ''),
                (string)cfg('db.name', ''),
                (int)cfg('db.port', 3306)
            );
        } catch (Throwable $ex) {
            blog_log('db', 'connection failed', ['error' => $ex->getMessage()]);
            throw new RuntimeException('database_unavailable', 0, $ex);
        }
        $this->link->set_charset('utf8mb4');
        $this->link->query("SET time_zone = '+00:00'");
        $this->link->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->prefix = self::cleanPrefix((string)cfg('prefix', 'blog_'));
    }

    public static function get(): Db
    {
        return self::$instance ??= new Db();
    }

    /** Forget the connection (tests and the installer switch databases). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function cleanPrefix(string $prefix): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) ?? 'blog_';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Full table name for a short name, e.g. t('articles') = blog_articles. */
    public function t(string $name): string
    {
        return $this->prefix . $name;
    }

    private function sql(string $sql): string
    {
        return str_replace('{p}', $this->prefix, $sql);
    }

    public function raw(): mysqli
    {
        return $this->link;
    }

    public function run(string $sql, array $params = []): mysqli_stmt
    {
        $stmt = $this->link->prepare($this->sql($sql));
        if ($params) {
            $types = '';
            foreach ($params as $p) {
                $types .= is_int($p) || is_bool($p) ? 'i' : (is_float($p) ? 'd' : 's');
            }
            $values = array_map(static fn($p) => is_bool($p) ? (int)$p : $p, array_values($params));
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row ? reset($row) : null;
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $n = $stmt->affected_rows;
        $stmt->close();
        return $n;
    }

    /** Run a script of several statements (installer and upgrades only). */
    public function script(string $sql): void
    {
        $sql = $this->sql($sql);
        // Remove comment lines, then split on semicolons at line ends.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $this->link->query($statement);
            }
        }
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $this->t($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->exec($sql, array_values($data));
        return (int)$this->link->insert_id;
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = '`' . $col . '` = ?';
        }
        return $this->exec('UPDATE `' . $this->t($table) . '` SET ' . implode(', ', $set) . ' WHERE ' . $where,
            array_merge(array_values($data), $whereParams));
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->exec('DELETE FROM `' . $this->t($table) . '` WHERE ' . $where, $params);
    }

    public function tableExists(string $name): bool
    {
        return (bool)$this->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$this->t($name)]);
    }

    public function begin(): void
    {
        if ($this->depth++ === 0) {
            $this->link->begin_transaction();
        }
    }

    public function commit(): void
    {
        if ($this->depth > 0 && --$this->depth === 0) {
            $this->link->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->depth > 0) {
            $this->depth = 0;
            $this->link->rollback();
        }
    }
}
