<?php

/**
 * Database interface class.
 *
 * No active records, cursors or other stuff.  Just a PDO wrapper.
 *
 * This class should be accessed by handlers (based on \Ufw1\Handler)
 * using $this->db.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use PDO;
use PDOStatement;

class Database
{
    /**
     * Data source name (connection information).
     * An array of connection info in PDO format, keys: name, user, password.
     *
     * @var array
     **/
    protected $dsn;

    /**
     * PDO instance.
     *
     * @var PDO
     **/
    protected $conn;

    /**
     * Prepares the database connection.
     *
     * Does not actually connect.  This method is usually called during the application setup
     * process, when exception handling might not yet have been configured.  We'll connect later,
     * in a lazy manner.
     *
     * @param array $dsn Connection parameters.
     **/
    public function __construct(array $dsn)
    {
        $this->conn = null;

        $this->dsn = $dsn;
    }

    public function transact($callback)
    {
        $this->beginTransaction();

        try {
            $res = $callback($this);
            $this->commit();
            return $res;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Connect to the database.
     *
     * @return PDO Database connection.
     **/
    protected function connect(): PDO
    {
        if (is_null($this->conn)) {
            if (!is_array($this->dsn)) {
                throw new \RuntimeException("database not configured");
            }

            $this->conn = new PDO($this->dsn["name"], $this->dsn["user"] ?? null, $this->dsn["password"] ?? null);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            if (0 === strpos($this->dsn['name'], 'mysql:')) {
                $this->conn->query('SET NAMES utf8');
            }

            // Perform initialization stuff, like SET NAMES utf8.
            if (!empty($this->dsn["bootstrap"])) {
                foreach ($this->dsn["bootstrap"] as $query) {
                    $this->conn->query($query);
                }
            }
        }

        return $this->conn;
    }

    public function beginTransaction(): void
    {
        $this->connect()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connect()->commit();
    }

    public function rollback(): void
    {
        $this->connect()->rollback();
    }

    public function fetch(string $query, array $params = array(), $callback = null): array
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);

        $res = $sth->fetchAll(PDO::FETCH_ASSOC);

        if ($callback) {
            $res = array_filter(array_map($callback, $res));
        }

        return $res;
    }

    public function fetchk(string $query, array $params = []): array
    {
        $rows = $this->fetch($query, $params);

        $res = [];
        foreach ($rows as $row) {
            $row = array_values($row);
            $key = array_shift($row);
            $res[$key] = $row;
        }

        return $res;
    }

    public function fetchkv(string $query, array $params = []): array
    {
        $rows = $this->fetch($query, $params);

        $res = [];
        foreach ($rows as $row) {
            $row = array_values($row);
            $res[$row[0]] = $row[1];
        }

        return $res;
    }

    public function fetchOne(string $query, array $params = array()): ?array
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);
        return $sth->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function fetchCell(string $query, array $params = array())
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);

        return $sth->fetchColumn(0);
    }

    public function query(string $query, array $params = []): PDOStatement
    {
        try {
            $db = $this->connect();
            $sth = $db->prepare($query);
            $sth->execute($params);
            return $sth;
        } catch (PDOException $e) {
            $_m = $e->getMessage();

            // Server gone away.
            if ($_m == 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away') {
                $this->conn = $this->connect();
                return $this->query($query, $params);
            }

            if ($_m = "SQLSTATE[HY000]: General error: 8 attempt to write a readonly database") {
                if (0 === strpos($this->dsn["name"], "sqlite:")) {
                    $fn = substr($this->dsn["name"], 7);
                    if (!is_writable($fn)) {
                        throw new \RuntimeException("SQLite database is not writable.");
                    } elseif (!is_writable(dirname($fn))) {
                        throw new \RuntimeException("SQLite database folder is not writable.");
                    }
                }
            }
            throw $e;
        }
    }

    public function prepare(string $query): PDOStatement
    {
        return $this->connect()->prepare($query);
    }

    public function getConnectionType(): string
    {
        $this->connect();
        return $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function insert(string $tableName, array $fields): ?int
    {
        $_fields = [];
        $_marks = [];
        $_params = [];

        foreach ($fields as $k => $v) {
            $_fields[] = "`{$k}`";
            $_params[] = $v;
            $_marks[] = "?";
        }

        $_fields = implode(", ", $_fields);
        $_marks = implode(", ", $_marks);

        $query = "INSERT INTO `{$tableName}` ({$_fields}) VALUES ({$_marks})";
        $sth = $this->query($query, $_params);

        return (int)$this->conn->lastInsertId();
    }

    public function update(string $tableName, array $fields, array $where): int
    {
        $_set = [];
        $_where = [];
        $_params = [];

        foreach ($fields as $k => $v) {
            $_set[] = "`{$k}` = ?";
            $_params[] = $v;
        }

        foreach ($where as $k => $v) {
            $_where[] = "`{$k}` = ?";
            $_params[] = $v;
        }

        $_set = implode(", ", $_set);

        $query = "UPDATE `{$tableName}` SET {$_set}";

        if (!empty($_where)) {
            $_where = implode(" AND ", $_where);
            $query .= " WHERE {$_where}";
        }

        $sth = $this->query($query, $_params);
        return $sth->rowCount();
    }

    /**
     * Returns basic statistics on database tables.
     *
     * @return array Table info.
     **/
    public function getStats(): array
    {
        switch ($this->getConnectionType()) {
            case "sqlite":
                $tables = [];

                $rows = $this->fetch("select name FROM sqlite_master WHERE `type` = 'table' ORDER BY name");
                foreach ($rows as $row) {
                    $tmp = $this->fetchOne("SELECT COUNT(1) AS `count` FROM `{$row["name"]}`");
                    $tables[] = [
                        "name" => $row["name"],
                        "row_count" => (int)$tmp["count"],
                    ];
                }

                break;

            case "mysql":
                $name = $this->fetchcell("SELECT DATABASE()");

                $tables = $this->fetch("SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY `table_name`", [$name], function ($row) {
                    return [
                        "name" => $row["TABLE_NAME"],
                        "row_count" => (int)$row["TABLE_ROWS"],
                        "length" => (int)$row["DATA_LENGTH"] + (int)$row["INDEX_LENGTH"],
                    ];
                });

                break;
        }

        return $tables;
    }
}
