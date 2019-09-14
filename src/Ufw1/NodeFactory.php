<?php

namespace Ufw1;

class NodeFactory
{
    protected $container;

    public function __construct($c)
    {
        $this->container = $c;
    }

    public function where($conditions, array $params = [], $callback = null)
    {
        $db = $this->container->get("database");

        $query = "SELECT * FROM `nodes` WHERE " . $conditions;

        $res = $db->fetch($query, $params, function (array $row) {
            $row = $this->unpack($row);
            return $row;
        });

        if ($callback)
            $res = array_map($callback, $res);

        return $res;
    }

    public function get($id)
    {
        $db = $this->container->get("database");

        $row = $db->fetchone("SELECT * FROM `nodes` WHERE `id` = ?", [$id]);
        if ($row)
            return $this->unpack($row);
    }

    public function getByKey($key)
    {
        $db = $this->container->get("database");

        $tmp = $db->fetchOne("SELECT * FROM `nodes` WHERE `key` = ? ORDER BY `id` LIMIT 1", [$key]);
        if ($tmp)
            return $this->unpack($tmp);
    }

    public function save(array $node)
    {
        $db = $this->container->get("database");
        $logger = $this->container->get("logger");

        $now = strftime("%Y-%m-%d %H:%M:%S");

        if (empty($node["create"]))
            $node["created"] = $now;
        $node["updated"] = $now;

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
        }

        $node["id"] = $db->insert("nodes", $row);

        $this->indexUpdate($node);

        $logger->debug("node {id} created.", [
            "id" => $node["id"],
        ]);

        return $node;
    }

    public function unpack(array $row)
    {
        if (array_key_exists("more", $row)) {
            $more = unserialize($row["more"]);
            unset($row["more"]);
            if (is_array($more))
                $row = array_merge($row, $more);
        }

        return $row;
    }

    protected function packNode(array $row)
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
            "published",
        ]);
    }

    protected function pack(array $row, array $fields)
    {
        $more = [];

        foreach ($row as $k => $v) {
            if ($v === "")
                $v = null;

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
    protected function indexUpdate(array $node)
    {
        $db = $this->container->get("database");
        $settings = $this->container->get("settings");
        $logger = $this->container->get("logger");

        $type = $node["type"];
        if (!empty($settings["nodes_idx"][$type])) {
            $fields = $settings["nodes_idx"][$type];

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
