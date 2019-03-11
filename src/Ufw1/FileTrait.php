<?php
/**
 * File related functions.
 *
 * Moved to separate file for easier sharing across projects.
 **/

namespace Ufw1;


trait FileTrait
{
    protected function fileGet($id)
    {
        $node = $this->nodeGet($id);
        if (empty($node) or $node["type"] != "file")
            return null;

        return $this->fileFix($node);
    }

    protected function fileGetByHash($hash)
    {
        $node = $this->nodeGetByHash($hash);
        if (empty($node) or $node["type"] != "file")
            return null;

        return $this->fileFix($node);
    }

    protected function fileGetBody(array $node)
    {
        if ($node["type"] != "file")
            return false;

        $fpath = $this->fileGetBodyPath($node);
        if (!file_exists($fpath)) {
            $this->logger->warning("files: file {name} does not exist.", [
                "name" => $node["fname"],
            ]);

            return false;
        }

        return file_get_contents($fpath);
    }

    protected function fileAdd($name, $type, $body)
    {
        $hash = md5($body);

        if ($old = $this->nodeGetByKey($hash)) {
            $this->logger->info("files: file {id} reused from {name}", [
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

        $node = [
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
        ];

        $settings = $this->fileGetSettings();

        $fpath = $settings["path"] . "/" . $node["fname"];

        $fdir = dirname($fpath);
        if (!is_dir($fdir)) {
            $res = @mkdir($fdir, $settings["dmode"], true);
            if ($res === false) {
                $this->logger->error("files: error creating folder {dir}", [
                    "dir" => $fdir,
                ]);
                throw new \RuntimeException("error saving file");
            }
        }

        $res = @file_put_contents($fpath, $body);
        if ($res === false) {
            $this->logger->error("files: error creating file {name}", [
                "name" => $fpath,
            ]);
            throw new \RuntimeException("error saving file");
        }

        chmod($fpath, $settings["fmode"]);

        $node = $this->nodeSave($node);

        $this->logger->info("files: file {id} saved as {name}", [
            "id" => $node["id"],
            "name" => $node["fname"],
        ]);

        return $node;
    }

    protected function fileFix(array $node)
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

    protected function fileGetBodyPath(array $node)
    {
        $settings = $this->container->get("settings")["files"];
        $fpath = $settings["path"] . "/" . $node["fname"];
        return $fpath;
    }

    protected function fileGetStoragePath()
    {
        $settings = $this->fileGetSettings();

        if (empty($settings["path"]))
            throw new \RuntimeException("file storage path not set");

        $path = $settings["path"];

        if (!is_dir($path))
            throw new \RuntimeException("file storage does not exist");
        elseif (!is_writable($path))
            throw new \RuntimeException("file storage is not writable");

        return $path;
    }

    protected function fileGetSettings()
    {
        static $settings = null;

        if ($settings === null) {
            $settings = $this->container->get("settings")["files"] ?? [];

            $settings = array_merge([
                "path" => $_SERVER["DOCUMENT_ROOT"] . "/../data",
                "dmode" => 0775,
                "fmode" => 0664,
            ], $settings);
        }

        return $settings;
    }
}
