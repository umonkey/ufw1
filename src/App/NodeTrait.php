<?php
/**
 * Node related functions.
 *
 * Moved to separate file for easier sharing across projects.
 **/

namespace App;


trait NodeTrait
{
    protected function nodeGet($id)
    {
        $tmp = $this->db->fetchOne("SELECT * FROM `nodes` WHERE `id` = ?", [$id]);
        if ($tmp)
            return $this->unpack($tmp);
    }

    protected function nodeGetByKey($key)
    {
        $tmp = $this->db->fetchOne("SELECT * FROM `nodes` WHERE `key` = ? ORDER BY `id` LIMIT 1", [$key]);
        if ($tmp)
            return $this->unpack($tmp);
    }

    protected function unpack(array $row)
    {
        if (array_key_exists("more", $row)) {
            if ($row["more"][0] == '{')
                $more = json_decode($row["more"], true);
            else
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

    protected function nodeSave(array $node)
    {
        $now = strftime("%Y-%m-%d %H:%M:%S");

        if (empty($node["create"]))
            $node["created"] = $now;
        $node["updated"] = $now;

        if (empty($node["lb"])) {
            $last = (int)$this->db->fetchcell("SELECT MAX(`rb`) FROM `nodes`");
            $node["lb"] = $last + 1;
            $node["rb"] = $last + 2;
        }

        $row = $this->packNode($node);

        if (!empty($node["id"])) {
            $_row = $row;
            $id = $_row["id"];
            unset($_row["id"]);

            $count = $this->db->update("nodes", $_row, [
                "id" => $id,
            ]);

            if ($count != 0)
                return $node;
        }

        $node["id"] = $this->db->insert("nodes", $row);

        $this->nodeUpdateIndex($node);

        return $node;
    }

    /**
     * Update the related index entry.
     *
     * @param array $node Node to reindex.
     **/
    protected function nodeUpdateIndex(array $node)
    {
        $settings = $this->container->get("settings");

        $type = $node["type"];
        if (!empty($settings["nodes_idx"][$type])) {
            $fields = $settings["nodes_idx"][$type];

            if (!is_array($fields)) {
                $this->logger->warning("node: nodes_idx for type {type} is not an array.", [
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

            $this->db->query("DELETE FROM `{$table}` WHERE `id` = ?", [$row["id"]]);
            $this->db->insert($table, $row);
        }
    }

    protected function nodeFetch($query, array $params = [], $callback = null)
    {
        $rows = $this->db->fetch($query, $params, function ($row) use ($callback) {
            $node = $this->unpack($row);
            if ($callback)
                $node = $callback($node);
            return $node;
        });

        if ($callback)
            $rows = array_filter($rows);

        return $rows;
    }
}
