<?php

declare(strict_types=1);

namespace Ufw1\Services;

use Ufw1\Services\Database;
use Psr\Log\LoggerInterface;

class NodeFactory
{
    /**
     * @var Database
     **/
    protected $database;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    public function __construct(array $settings, Database $database, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->database = $database;
        $this->logger = $logger;
    }

    public function where(string $conditions, array $params = [], $callback = null): array
    {
        $query = "SELECT * FROM `nodes` WHERE " . $conditions;

        $res = $this->database->fetch($query, $params, function (array $row) {
            $row = $this->unpack($row);
            return $row;
        });

        if ($callback) {
            $res = array_map($callback, $res);
        }

        return $res;
    }

    public function get(int $id): ?array
    {
        $row = $this->database->fetchone("SELECT * FROM `nodes` WHERE `id` = ?", [$id]);
        if ($row) {
            return $this->unpack($row);
        } else {
            return null;
        }
    }

    public function getByKey(string $key): ?array
    {
        $tmp = $this->database->fetchOne("SELECT * FROM `nodes` WHERE `key` = ? ORDER BY `id` LIMIT 1", [$key]);
        if ($tmp) {
            return $this->unpack($tmp);
        } else {
            return null;
        }
    }

    public function save(array $node): array
    {
        $db = $this->database;
        $logger = $this->logger;

        $this->saveCurrent($node);

        $now = strftime("%Y-%m-%d %H:%M:%S");

        if (empty($node["created"])) {
            $node["created"] = $now;
        }
        $node["updated"] = $now;

        $node['deleted'] = empty($node['deleted']) ? 0 : 1;

        if (empty($node["lb"])) {
            $last = (int)$db->fetchcell("SELECT MAX(`rb`) FROM `nodes`");
            $node["lb"] = $last + 1;
            $node["rb"] = $last + 2;
        }

        $row = $this->packNode($node);

        if (!empty($node["id"])) {
            $_row = $row;
            $id = $_row["id"];
            unset($_row["id"]);

            $count = $db->update("nodes", $_row, [
                "id" => $id,
            ]);

            if ($count != 0) {
                $this->indexUpdate($node);
                $logger->debug("node {id} updated.", [
                    "id" => $node["id"],
                ]);
                return $node;
            }
        } else {
            $node["id"] = $db->insert("nodes", $row);
        }

        $this->indexUpdate($node);

        $logger->debug("node {id} created.", [
            "id" => $node["id"],
        ]);

        return $node;
    }

    /**
     * Save current node to the history table.
     *
     * If the node already exists, and its type is within the configured list,
     * then the node will be read, re-packed and saved in the nodes_history table.
     *
     * @param array $node Node to be saved.
     * @return void
     **/
    protected function saveCurrent(array $node): void
    {
        if (empty($node['id']) or empty($node['type'])) {
            return;
        }

        $st = $this->settings['history'] ?? [];
        if (empty($st['types'])) {
            return;
        }

        $types = $st['types'];
        $compression = $st['compression'] ?? null;

        if ($types != '*' and !in_array($node['type'], $types)) {
            return;
        }

        if ($compression == 'bzip' and !function_exists('bzcompress')) {
            $compression = null;
        }

        $old = $this->get($node['id']);

        if ($compression == 'gzip') {
            $item = 'g' . gzcompress(serialize($old));
        } elseif ($compression == 'bzip') {
            $item = 'b' . bzcompress(serialize($old));
        } else {
            $item = '-' . serialize($old);
        }

        $db->insert('nodes_history', [
            'id' => $node['id'],
            'updated' => $old['updated'],
            'contents' => $item,
        ]);
    }

    public function unpack(array $row): array
    {
        if (array_key_exists("more", $row)) {
            $more = unserialize($row["more"]);
            unset($row["more"]);
            if (is_array($more)) {
                $row = array_merge($row, $more);
            }
        }

        return $row;
    }

    protected function packNode(array $row): array
    {
        return $this->pack($row, [
            "id",
            "parent",
            "lb",
            "rb",
            "type",
            "created",
            "updated",
            "key",
            "deleted",
            "published",
        ]);
    }

    protected function pack(array $row, array $fields): array
    {
        $more = [];

        foreach ($row as $k => $v) {
            if ($v === "") {
                $v = null;
            }

            if (!in_array($k, $fields)) {
                $more[$k] = $v;
                unset($row[$k]);
            }
        }

        $row["more"] = $more ? serialize($more) : null;

        return $row;
    }

    /**
     * Update the related index entry.
     *
     * @param array $node Node to reindex.
     **/
    protected function indexUpdate(array $node): void
    {
        $db = $this->database;
        $settings = $this->settings;
        $logger = $this->logger;

        $type = $node["type"];
        if (!empty($settings["indexes"][$type])) {
            $fields = $settings["indexes"][$type];

            if (!is_array($fields)) {
                $logger->warning("node: nodes_idx for type {type} is not an array.", [
                    "type" => $type,
                ]);

                return;
            }

            $row = [
                "id" => $node["id"],
            ];

            foreach ($fields as $field) {
                $value = $node[$field] ?? null;
                $row[$field] = $value;
            }

            $table = "nodes_{$type}_idx";

            $db->query("DELETE FROM `{$table}` WHERE `id` = ?", [$row["id"]]);
            $db->insert($table, $row);
        }
    }
}