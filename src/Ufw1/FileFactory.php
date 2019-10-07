<?php

namespace Ufw1;

class FileFactory
{
    protected $container;

    public function __construct($c)
    {
        $this->container = $c;
    }

    public function get($id)
    {
        $nodes = $this->container->get("node");

        $node = $nodes->get($id);
        if (empty($node) or $node["type"] != "file")
            return null;

        return $this->fix($node);
    }

    public function getByHash($hash)
    {
        $nodes = $this->container->get("node");

        $node = $nodes->getByKey($hash);
        if (empty($node) or $node["type"] != "file")
            return null;

        return $this->fix($node);
    }

    public function getBody(array $node)
    {
        $logger = $this->container->get("logger");

        if ($node["type"] != "file")
            return false;

        $fpath = $this->getBodyPath($node);
        if (!file_exists($fpath)) {
            $logger->warning("files: file {name} does not exist.", [
                "name" => $node["fname"],
            ]);

            return false;
        }

        return file_get_contents($fpath);
    }

    public function add($name, $type, $body, array $props = [])
    {
        $nodes = $this->container->get("node");
        $logger = $this->container->get("logger");

        $hash = md5($body);

        if ($old = $nodes->getByKey($hash)) {
            $logger->info("files: file {id} reused from {name}", [
                "id" => $old["id"],
                "name" => $old["fname"],
            ]);

            return $old;
        }

        $kind = "other";
        if (0 === strpos($type, "image/"))
            $kind = "photo";
        elseif (0 === strpos($type, "video/"))
            $kind = "video";

        $now = strftime("%Y-%m-%d %H:%M:%S");

        $fname = substr($hash, 0, 1) . "/" . substr($hash, 1, 2) . "/" . $hash;

        $node = array_merge($props, [
            "type" => "file",
            "key" => $hash,
            "name" => $name,
            "fname" => $fname,
            "kind" => $kind,
            "mime_type" => $type,
            "length" => strlen($body),
            "created" => $now,
            "uploaded" => $now,
            "hash" => $hash,
            "published" => 1,
            "files" => [],
        ]);

        $node["files"]["original"] = [
            "type" => $type,
            "length" => strlen($body),
            "storage" => "local",
            "path" => $fname,
        ];

        $settings = $this->getSettings();

        $storage = $this->getStoragePath();
        $fpath = $storage . "/" . $node["fname"];

        $fdir = dirname($fpath);
        if (!is_dir($fdir)) {
            $res = @mkdir($fdir, $settings["dmode"], true);
            if ($res === false) {
                $logger->error("files: error creating folder {dir}", [
                    "dir" => $fdir,
                ]);
                throw new \RuntimeException("error saving file");
            }
        }

        $res = @file_put_contents($fpath, $body);
        if ($res === false) {
            $logger->error("files: error creating file {name}", [
                "name" => $fpath,
            ]);
            throw new \RuntimeException("error saving file");
        }

        chmod($fpath, $settings["fmode"]);

        $node = $nodes->save($node);

        $logger->info("files: file {id} saved as {name}", [
            "id" => $node["id"],
            "name" => $node["fname"],
        ]);

        return $node;
    }

    protected function fix(array $node)
    {
        if (empty($node["kind"])) {
            if (0 === strpos($node["mime_type"], "image/"))
                $node["kind"] = "photo";
            elseif (0 === strpos($node["mime_type"], "video/"))
                $node["kind"] = "video";
            else
                $node["kind"] = "other";
        }

        return $node;
    }

    protected function getBodyPath(array $node)
    {
        $storage = $this->getStoragePath();
        $fpath = $storage . "/" . $node["fname"];
        return $fpath;
    }

    public function getStoragePath()
    {
        $settings = $this->getSettings();

        if (empty($settings["path"]))
            throw new \RuntimeException("file storage path not set");

        $path = $settings["path"];

        if (!is_dir($path)) {
            $res = mkdir($path, 0775, true);
            if ($res === false)
                throw new \RuntimeException("file storage does not exist and could not be created: {$path}");
        } elseif (!is_writable($path)) {
            throw new \RuntimeException("file storage is not writable: {$path}");
        }

        return $path;
    }

    public function getStorageSize()
    {
        return disk_free_space($this->getStoragePath());
    }

    protected function getSettings()
    {
        static $settings = null;

        if ($settings === null) {
            $settings = $this->container->get("settings")["files"] ?? [];

            $host = $_SERVER["SERVER_NAME"];

            $settings = array_merge([
                "path" => dirname($_SERVER["DOCUMENT_ROOT"]) . "/data/files/" . $host,
                "dmode" => 0775,
                "fmode" => 0664,
            ], $settings);
        }

        return $settings;
    }
}
