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

        $body = $this->fsget($node['fname']);
        return $body;
    }

    /**
     * Adds a new file to the database.
     *
     * Creates the node only.
     * Does not prepare thumbnails or upload to S3: use taskq for that.
     **/
    public function add($name, $type, $body, array $props = [])
    {
        $nodes = $this->container->get("node");
        $logger = $this->container->get("logger");

        $hash = md5($body);

        $now = strftime("%Y-%m-%d %H:%M:%S");

        if ($old = $nodes->getByKey($hash) and $old['deleted'] == 0) {
            $logger->info("files: file {id} reused.", [
                "id" => $old["id"],
            ]);

            $node = $old;
        } else {
            $node = array_merge($props, [
                "type" => "file",
                "key" => $hash,
                "name" => $name,
                "kind" => 'other',
                "mime_type" => $type,
                "length" => strlen($body),
                "created" => $now,
                "uploaded" => $now,
                "hash" => $hash,
                "published" => 1,
                "files" => [],
            ]);
        }

        if ($this->shouldReplaceOriginal($node)) {
            $fname = $this->fsput($body);

            $node['mime_type'] = $type;
            $node['kind'] = $this->getKindByType($type);
            $node['length'] = strlen($body);
            $node['updated'] = $now;

            $node['files'] = ['original' => [
                'type' => $type,
                'length' => strlen($body),
                'storage' => 'local',
                'path' => $fname,
            ]];

            if (isset($props['width']))
                $node['files']['original']['width'] = $props['width'];
            if (isset($props['height']))
                $node['files']['original']['height'] = $props['height'];
        }

        $node = $nodes->save($node);

        return $node;
    }

    private function shouldReplaceOriginal(array $node)
    {
        if (empty($node['id'])) {
            return true;
        }

        return true;
    }

    private function getKindByType($type)
    {
        $kind = "other";

        if (0 === strpos($type, "image/"))
            $kind = "photo";
        elseif (0 === strpos($type, "video/"))
            $kind = "video";

        return $kind;
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

    /**
     * Read contents of the specified file.
     *
     * @param string $path Local file path, e.g. '1/b9/1b9a744e14005e033fae6a45fd24f612'
     * @return string File body, or false if it doesn't exist.
     **/
    public function fsget($path)
    {
        $fpath = $this->fsgetpath($path);
        if (!file_exists($fpath))
            return false;
        return file_get_contents($fpath);
    }

    /**
     * Save file body to a file, return local path.
     *
     * @param string $body File contents.
     * @return string Local path to the file, e.g.: '1/b9/1b9a744e14005e033fae6a45fd24f612'
     **/
    public function fsput($body)
    {
        $st = $this->container->get('settings');
        $storage = $st['files']['path'] ?? $_SERVER['DOCUMENT_ROOT'] . '/../data/files';

        $hash = md5($body);
        $fname = substr($hash, 0, 1) . '/' . substr($hash, 1, 2) . '/' . $hash;

        $fpath = $storage . '/' . $fname;
        if (!is_dir($dir = dirname($fpath))) {
            $res = mkdir($dir, 0775, true);
            if ($res === false)
                throw new \RuntimeException('could not create file folder');
        }

        $res = @file_put_contents($fpath, $body);
        if ($res === false)
            throw new \RuntimeException('error writing file');

        return $fname;
    }

    /**
     * Returns absolute path to the specified storage-local one.
     *
     * @param string $path Storage-local path.
     * @return string Absolute path.
     **/
    public function fsgetpath($path)
    {
        $st = $this->container->get('settings');
        $storage = $st['files']['path'] ?? $_SERVER['DOCUMENT_ROOT'] . '/../data/files';

        $fpath = $storage . '/' . $path;

        return $fpath;
    }
}
