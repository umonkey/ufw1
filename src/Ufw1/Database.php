<?php
/**
 * Database interface class.
 *
 * No active records, cursors or other stuff.  Just a PDO wrapper.
 *
 * This class should be accessed by handlers (based on \Ufw1\Handler)
 * using $this->db.
 **/

namespace Ufw1;

class Database {
    /**
     * Data source name (connection information).
     * An array of connection info in PDO format, keys: name, user, password.
     **/
    protected $dsn;

    /**
     * PDO instance.
     **/
    protected $conn;

    /**
     * Prepares the database connection.
     *
     * Does not actually connect.  This method is usually called during the application setup
     * process, when exception handling might not yet have been configured.  We'll connect later,
     * in a lazy manner.
     *
     * @param Container $container We extract settings from this.
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

    public function getThumbnail($name, $type)
    {
        $rows = $this->fetch("SELECT * FROM `thumbnails` WHERE `name` = ? AND `type` = ?", [$name, $type]);
        return $rows ? $rows[0] : null;
    }

    public function saveThumbnail($name, $type, $body)
    {
        $this->query("INSERT INTO `thumbnails` (`name`, `type`, `body`, `hash`) VALUES (?, ?, ?, ?)", [$name, $type, $body, md5($body)]);
    }

    /**
     * Connect to the database.
     *
     * @return PDO Database connection.
     **/
    protected function connect()
    {
        if (is_null($this->conn)) {
            if (!is_array($this->dsn))
                throw new \RuntimeException("database not configured");
            $this->conn = new \PDO($this->dsn["name"], $this->dsn["user"], $this->dsn["password"]);
            $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
			$this->conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            // Perform initialization stuff, like SET NAMES utf8.
            if (!empty($this->dsn["bootstrap"])) {
                foreach ($this->dsn["bootstrap"] as $query) {
                    $this->conn->query($query);
                }
            }
        }

        return $this->conn;
    }

    public function beginTransaction()
    {
        $this->connect()->beginTransaction();
    }

    public function commit()
    {
        $this->connect()->commit();
    }

    public function rollback()
    {
        $this->connect()->rollback();
    }

    public function fetch($query, array $params = array(), $callback = null)
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);

        $res = $sth->fetchAll(\PDO::FETCH_ASSOC);

        if ($callback)
            $res = array_filter(array_map($callback, $res));

        return $res;
    }

    public function fetchkv($query, array $params = [])
    {
        $rows = $this->fetch($query, $params);

        $res = [];
        foreach ($rows as $row) {
            $row = array_values($row);
                $res[$row[0]] = $row[1];
        }

        return $res;
    }

    public function fetchOne($query, array $params = array())
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);
        return $sth->fetch(\PDO::FETCH_ASSOC);
    }

    public function fetchcell($query, array $params = array())
    {
        $db = $this->connect();
        $sth = $db->prepare($query);
        $sth->execute($params);

        return $sth->fetchColumn(0);
    }

    public function query($query, array $params = [])
    {
        try {
            $db = $this->connect();
            $sth = $db->prepare($query);
            $sth->execute($params);
            return $sth;
        } catch (\PDOException $e) {
            $_m = $e->getMessage();
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

    public function prepare($query)
    {
        return $this->connect()->prepare($query);
    }

    public function getConnectionType()
    {
        $this->connect();
        return $this->conn->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function insert($tableName, array $fields)
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

        return $this->conn->lastInsertId();
    }

    public function update($tableName, array $fields, array $where)
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
        $_where = implode(" AND ", $_where);

        $query = "UPDATE `{$tableName}` SET {$_set} WHERE {$_where}";
        $sth = $this->query($query, $_params);
        return $sth->rowCount();
    }

    public function cacheSet($key, $value)
    {
        $now = time();
        $this->query("REPLACE INTO `cache` (`key`, `added`, `value`) VALUES (?, ?, ?)", [$key, $now, $value]);
    }

    public function cacheGet($key)
    {
        return $this->fetchCell("SELECT `value` FROM `cache` WHERE `key` = ?", [$key]);
    }
}
