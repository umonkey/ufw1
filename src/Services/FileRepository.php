<?php
/**
 * File storage interface.
 *
 * Files are stored as nodes, in the database.  File bodies are either stored in the local file system,
 * or uploaded to S3 and urls are stored locally.
 *
 * Most used methods:
 * - get()
 * - getByHash()
 * - add()
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class FileRepository
{
    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var array
     **/
    protected $settings;

    public function __construct(LoggerInterface $logger, NodeRepository $node, array $settings)
    {
        $this->logger = $logger;

        $this->node = $node;

        $this->settings = array_replace([
            'dmode' => 0775,
            'fmode' => 0664,
        ], $settings);
    }

    /**
     * Get one file node by id.
     *
     * @param int $id File id.
     *
     * @return array File node or null if node not found.
     **/
    public function get(int $id): ?array
    {
        $node = $this->node->get($id);
        if (empty($node) or $node["type"] != "file") {
            return null;
        }

        // Upgrade really old nodes.
        return $this->fix($node);
    }

    /**
     * Find file node by source file hash.
     *
     * The hash is used in the `key` node field and is calculated as
     * MD5 of the source file contents.
     *
     * Use this to find duplicates before creating new files.
     *
     * @param string $hash Source file hash.
     * @return array File or null, if not found.
     **/
    public function getByHash(string $hash): ?array
    {
        $node = $this->node->getByKey($hash);
        if (empty($node) or $node["type"] != "file") {
            return null;
        }

        return $this->fix($node);
    }

    /**
     * Get source file contents.
     *
     * Finds the file in the local storage.
     **/
    public function getBody(array $node): ?string
    {
        if ($node["type"] != "file") {
            return null;
        }

        $body = $this->fsget($node['fname']);
        return $body;
    }

    /**
     * Adds a new file to the database.
     *
     * Creates the node only.
     * Does not prepare thumbnails or upload to S3: use taskq for that.
     *
     * If the file already exists -- reuses the old one (found by the `key` field),
     * otherwise a new node with type=file is created.  If the existing file is
     * deleted -- it's ignored.
     *
     * @param string $name  Source file name, e.g. "DCIM123.jpg"
     * @param string $type  Source file type, e.g. "image/jpeg".
     * @param string $body  Source file contents.
     * @param array  $props Additional node properties.
     *
     * @return array Saved node contents.
     **/
    public function add(string $name, string $type, string $body, array $props = []): array
    {
        $hash = md5($body);

        $now = strftime("%Y-%m-%d %H:%M:%S");

        if ($old = $this->node->getByKey($hash) and $old['deleted'] == 0) {
            $this->logger->info("files: file {id} reused.", [
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

            if (isset($props['width'])) {
                $node['files']['original']['width'] = $props['width'];
            }
            if (isset($props['height'])) {
                $node['files']['original']['height'] = $props['height'];
            }
        }

        $node = $this->node->save($node);

        return $node;
    }

    private function shouldReplaceOriginal(array $node): bool
    {
        if (empty($node['id'])) {
            return true;
        }

        return true;
    }

    private function getKindByType(string $type): string
    {
        $kind = "other";

        if (0 === strpos($type, "image/")) {
            $kind = "photo";
        } elseif (0 === strpos($type, "video/")) {
            $kind = "video";
        }

        return $kind;
    }

    protected function fix(array $node): array
    {
        if (empty($node["kind"])) {
            if (0 === strpos($node["mime_type"], "image/")) {
                $node["kind"] = "photo";
            } elseif (0 === strpos($node["mime_type"], "video/")) {
                $node["kind"] = "video";
            } else {
                $node["kind"] = "other";
            }
        }

        return $node;
    }

    /**
     * Returns the full path to the file storage.
     *
     * The path is set in $settings['file']['path'].
     *
     * Does NOT check if the folder exists.  This sould NOT be done here, only during
     * the real file access.
     **/
    public function getStoragePath(): string
    {
        $path = $this->settings['path'] ?? null;

        if (empty($path)) {
            throw new \RuntimeException("file storage path not set");
        }

        return $path;
    }

    /**
     * Returns the amount of available disk space.
     *
     * @return int Free disk space.
     **/
    public function getStorageSize(): int
    {
        return (int)disk_free_space($this->getStoragePath());
    }

    /**
     * Read contents of the specified file.
     *
     * @param string $path Local file path, e.g. '1/b9/1b9a744e14005e033fae6a45fd24f612'
     * @return string File body, or false if it doesn't exist.
     **/
    public function fsget(string $path): ?string
    {
        $fpath = $this->fsgetpath($path);

        if (!file_exists($fpath)) {
            return null;
        }

        return file_get_contents($fpath);
    }

    /**
     * Save file body to a file, return local path.
     *
     * @param string $body File contents.
     *
     * @return string Local path to the file, e.g.: '1/b9/1b9a744e14005e033fae6a45fd24f612'
     **/
    public function fsput(string $body): string
    {
        $hash = md5($body);
        $fname = substr($hash, 0, 1) . '/' . substr($hash, 1, 2) . '/' . $hash;
        $fpath = $this->fsgetpath($fname);

        if (!is_dir($dir = dirname($fpath))) {
            $res = mkdir($dir, 0775, true);
            if ($res === false) {
                throw new \RuntimeException('could not create file folder');
            }
        }

        $res = @file_put_contents($fpath, $body);
        if ($res === false) {
            throw new \RuntimeException('error writing file');
        }

        return $fname;
    }

    /**
     * Returns absolute path to the specified storage-local one.
     *
     * @param string $path Storage-local path.
     * @return string Absolute path.
     **/
    public function fsgetpath(string $path): string
    {
        $storage = $this->getStoragePath();
        $fpath = $storage . '/' . $path;
        return $fpath;
    }
}
