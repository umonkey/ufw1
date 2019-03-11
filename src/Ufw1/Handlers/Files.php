<?php
/**
 * File related functions.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Files extends CommonHandler
{
    /**
     * Lists all uploaded files.
     **/
    public function onList(Request $request, Response $response, array $args)
    {
        $recent = $this->node->where("1 ORDER BY `created` DESC", [], function ($em) {
            return [
                "id" => (int)$em["id"],
                "name" => $em["name"],
                "thumbnail" => "/i/{$em["id"]}.jpg",
                "download" => "/files/{$em["id"]}/download",
            ];
        });

        return $this->render($request, "files.twig", [
            "files" => $recent,
        ]);
    }

    public function onGet(Request $request, Response $response, array $args)
    {
        if ($id = $request->getParam("file")) {
            return $this->onGetFile($request, $response, ["id" => $id]);
        }

        $folder = (int)@$_GET["folder"];
        $folders = $this->dbGetFolders($folder);
        $files = $this->dbGetFilesByFolder($folder);

        return $this->render($response, "admin-files.twig", [
            "folder_id" => $folder,
            "folders" => $folders,
            "files" => $files,
            "breadcrumbs" => [
                ["/", "Главная"],
                ["/admin", "Управление"],
                ["/admin/files", "Файлы"],
            ],
        ]);
    }

    public function onPost(Request $request, Response $response, array $args)
    {
        $action = $request->getParam("action");

        switch ($action) {
            case "upload":
                return $this->onUpload($request, $response, $args);
            case "editfile":
                $id = $request->getParam("id");
                return $this->onSaveFile($request, $response, ["id" => $id]);
            case "addfolder":
                return $this->onAddFolder($request, $response, $args);
        }
    }

    public function onGetPhoto(Request $request, Response $response, array $args)
    {
        $bydate = [];

        $rows = $this->node->where("`type` = 'file' ORDER BY `created` DESC", [], function ($em) {
            return [
                "link" => "/admin/files?file={$em["id"]}",
                "label" => $em["name"],
                "image" => "/i/thumbnails/{$em["id"]}.jpg",
                "large" => "/admin/files?download={$em["id"]}",
                "date" => substr($em["created"], 0, 10),
            ];
        });

        foreach ($rows as $row) {
            $bydate[$row["date"]][] = $row;
        }

        return $this->render($response, "admin-photos.twig", [
            "photos" => $bydate,
        ]);
    }

    protected function onGetFile(Request $request, Response $response, array $args)
    {
        $id = $args["id"];
        $file = $this->node->get($id);

        return $this->render($response, "admin-file.twig", [
            "file" => $file,
        ]);
    }

    protected function onAddFolder(Request $request, Response $response, array $args)
    {
        $name = $request->getParam("name");
        $parent = $request->getParam("parent");

        $old = $parent
            ? $this->db->fetchOne("SELECT `id` FROM `folders` WHERE `parent` = ? AND `name` = ?", [$parent, $name])
            : $this->db->fetchOne("SELECT `id` FROM `folders` WHERE `parent` IS NULL AND `name` = ?", [$name]);

        if (!$old) {
            $this->db->insert("folders", [
                "parent" => $parent,
                "name" => $name,
            ]);
        }

        return $response->withJSON([
            "refresh" => true,
        ]);
    }

    protected function onUpload(Request $request, Response $response, array $args)
    {
        if (!($folder = (int)$request->getParam("folder_id")))
            $folder = null;  // 0 => null

        $files = $request->getUploadedFiles();

        foreach ($files["files"] as $file) {
            $name = $file->getClientFilename();
            $type = $file->getClientMediaType();
            $data = $file->getStream()->getContents();
            $hash = md5($data);

            if (!$this->checkFileExists($hash)) {
                $res = $this->dbInsertFile($folder, $name, $type, $data, $hash);
            }
        }

        return $response->withJSON([
            "refresh" => true,
        ]);
    }

    protected function onSaveFile(Request $request, Response $response, array $args)
    {
        $id = $args["id"];
        $name = $request->getParam("name");
        $kind = $request->getParam("kind");
        $type = $request->getParam("mime_type");
        $created = $request->getParam("created");

        $res = $this->db->update("files", [
            "name" => $name,
            "kind" => $kind,
            "mime_type" => $type,
            "created" => $created,
        ], [
            "id" => $id,
        ]);

        $file = $this->dbGetFile($id);
        $next = $file["folder_id"] ? "/admin/files?folder={$file["folder_id"]}" : "/admin/files";

        return $response->withJSON([
            "redirect" => $next,
        ]);
    }

    public function onGetRecent(Request $request, Response $response, array $args)
    {
        $kind = @$_GET["kind"];

        $nodes = $this->node->where("`type` = 'file' ORDER BY `created` DESC");

        if ($kind == "photo") {
            $nodes = array_filter($nodes, function ($node) {
                return $node["kind"] == "photo";
            });
        } else {
            $kind = "other";
        }

        $recent = array_map(function ($em) {
            $em["link"] = "/files/{$em["id"]}";
            $em["thumbnail"] = "/i/thumbnails/{$em["id"]}.jpg";
            $em["created"] = strftime("%Y-%m-%d", $em["created"]);
            return $em;
        }, $recent);

        $dates = [];
        foreach ($recent as $em) {
            $dates[$em["created"]][] = $em;
        }

        krsort($dates);

        return $this->render($response, "files-recent.twig", [
            "kind" => $kind,
            "recent" => $recent,
            "bydate" => $dates,
        ]);
    }

    public function onShowFile(Request $request, Response $response, array $args)
    {
        $id = $args["id"];
        $file = $this->node->get($id);

        if (empty($file) or $file["type"] != "file")
            return $this->notfound($response);

        return $this->render($response, "files-show.twig", [
            "file" => $file,
        ]);
    }

    public function onDownload(Request $request, Response $response, array $args)
    {
        $id = $args["id"];
        $file = $this->node->get($id);

        if (empty($file) or $file["type"] != "file")
            return $this->notfound($response);

        if (!($body = $this->file->getBody($file)))
            return $this->notfound($response);

        if (preg_match('@^image/@', $file["mime_type"])) {
            $dispo = "inline";
        } else {
            $dispo = "attachment; filename=\"" . urlencode($file["name"]) . "\"";
        }

        $response = $response->withHeader("Content-Type", $file["mime_type"])
            ->withHeader("Content-Length", $file["length"])
            ->withHeader("ETag", "\"{$file["hash"]}\"")
            ->withHeader("Cache-Control", "public, max-age=31536000")
            ->withHeader("Content-Disposition", $dispo);

        $response->getBody()->write($body);
        return $response;
    }

    public function onThumbnail(Request $request, Response $response, array $args)
    {
        return $this->sendFromCache($request, function () use ($request, $args) {
            $id = $args["id"];
            $file = $this->node->get($id);

            if (empty($file) or $file["type"] != "file")
                return $this->notfound($response);

            if (!($body = $this->file->getBody($file)))
                return $this->notfound($response);

            $img = imagecreatefromstring($body);
            if ($img === false)
                return $this->notfound($response);

            if ($body = $this->getImage($img))
                return ["image/jpeg", $body];

            return $this->notfound($response);
        }, $request->getUri()->getPath());
    }

    public function onPhoto(Request $request, Response $response, array $args)
    {
        $id = $args["id"];
        $file = $this->file->get($id);

        if (empty($file))
            return $this->notfound($response);

        if (!($body = $this->file->getBody($file)))
            return $this->notfound($response);

        $dst = $_SERVER["DOCUMENT_ROOT"] . $request->getUri()->getPath();
        if (is_dir($dir = dirname($dst)) and is_writable($dir))
            file_put_contents($dst, $body);

        $response = $response->withHeader("Content-Type", $file["mime_type"])
            ->withHeader("Content-Length", strlen($body))
            ->withHeader("Cache-Control", "public, max-age=31536000");
        $response->getBody()->write($body);

        return $response;
    }

    protected function getImage($img)
    {
        $img = $this->scaleImage($img, [
            "width" => 500,
        ]);

        $img = $this->sharpenImage($img);

        ob_start();
        imagejpeg($img, null, 85);
        return ob_get_clean();
    }

    protected function scaleImage($img, array $options)
    {
        $options = array_merge([
            "width" => null,
            "height" => null,
        ], $options);

        $iw = imagesx($img);
        $ih = imagesy($img);

        if ($options["width"] and !$options["height"]) {
            if ($options["width"] < $iw) {
                $r = $iw / $ih;
                $nw = $options["width"];
                $nh = round($nw / $r);

                $dst = imagecreatetruecolor($nw, $nh);

                $res = imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $iw, $ih);
                if (false === $res)
                    throw new \RuntimeException("could not resize the image");

                imagedestroy($img);
                $img = $dst;
            }
        } else {
            throw new \RuntimeException("unsupported thumbnail size");
        }

        return $img;
    }

    protected function sharpenImage($img)
    {
        $sharpenMatrix = array(
            array(-1.2, -1, -1.2),
            array(-1, 20, -1),
            array(-1.2, -1, -1.2),
        );

        // calculate the sharpen divisor
        $divisor = array_sum(array_map('array_sum', $sharpenMatrix));

        $offset = 0;

        imageConvolution($img, $sharpenMatrix, $divisor, $offset);

        return $img;
    }

    protected function dbGetFile($id)
    {
        $node = $this->node->get($id);
        if ($node and $node["type"] == "file")
            return $node;
    }

    protected function dbGetFolders($folder)
    {
        if ($folder)
            $rows = $this->db->fetch("SELECT `id`, `name` FROM `folders` WHERE `parent` = ? ORDER BY `name`", [$folder]);
        else
            $rows = $this->db->fetch("SELECT `id`, `name` FROM `folders` WHERE `parent` IS NULL ORDER BY `name`");

        if ($folder) {
            $row = $this->db->fetchOne("SELECT `parent` FROM `folders` WHERE `id` = ?", [$folder]);
            $parent = [
                "id" => $row["parent"],
                "name" => "..",
            ];
            array_unshift($rows, $parent);
        }

        $rows = array_map(function ($em) {
            return [
                "link" => $em["id"] ? "/admin/files?folder={$em["id"]}" : "/admin/files",
                "label" => $em["name"],
                "image" => "/images/folder.png",
            ];
        }, $rows);

        return $rows;
    }

    protected function dbGetFilesByFolder($folder)
    {
        // FIXME: use nodes and node__rel

        if ($folder)
            $rows = $this->db->fetch("SELECT `id`, `name`, `kind`, `length`, `created` FROM `files` WHERE `folder_id` = ?", [$folder]);
        else
            $rows = $this->db->fetch("SELECT `id`, `name`, `kind`, `length`, `created` FROM `files` WHERE `folder_id` IS NULL");

        $rows = array_map(function ($em) {
            $image = "/images/file.png";

            if ($em["kind"] == "photo")
                $image = "/i/thumbnails/{$em["id"]}.jpg";

            return [
                "link" => "/admin/files?file={$em["id"]}",
                "label" => $em["name"],
                "image" => $image,
            ];
        }, $rows);

        return $rows;
    }

    protected function checkFileExists($hash)
    {
        return $this->file->getByHash($hash) ? true : false;
    }

    /**
     * Insert the file into the database.
     *
     * @return int New file id.
     **/
    protected function dbInsertFile($folder, $name, $type, $data, $hash)
    {
        $ts = strftime("%Y-%m-%d %H:%M:%S");

        $parts = explode("/", $type);
        if ($parts[0] == "video")
            $kind = "video";
        elseif ($parts[0] == "image")
            $kind = "photo";
        else
            $kind = "other";

        $res = $this->db->insert("files", [
            "folder_id" => $folder,
            "name" => $name,
            "mime_type" => $type,
            "kind" => $kind,
            "hash" => $hash,
            "length" => strlen($data),
            "body" => $data,
            "created" => $ts,
            "uploaded" => $ts,
        ]);

        return $res;
    }

    public function onFormUpload(Request $request, Response $response, array $args)
    {
        $res = [];

        if ($files = $request->getUploadedFiles()) {
            foreach ($files["files"] as $file) {
                if ($file->getError())
                    continue;

                $name = $file->getClientFilename();
                $type = $file->getClientMediaType();
                $data = $file->getStream()->getContents();
                $hash = md5($data);

                $old = $this->node->getByKey($hash);
                if ($old)
                    $id = $old["id"];
                else
                    $id = $this->dbInsertFile(null, $name, $type, $data, $hash);

                $res[] = [
                    "id" => $id,
                    "image" => "/i/thumbnails/{$id}.jpg",
                ];
            }
        }

        if ($link = $request->getParam("link")) {
            $f = \Ufw1\Util::fetchFile($link);
            if (preg_match('@^image/@', $f["type"])) {
                $name = "unnamed.jpg";
                $type = $f["type"];
                $data = $f["data"];
                $hash = md5($data);

                $old = $this->file->getByHash($hash);
                if ($old)
                    $id = $old["id"];
                else
                    $id = $this->dbInsertFile(null, $name, $type, $data, $hash);

                $res[] = [
                    "id" => $id,
                    "image" => "/i/thumbnails/{$id}.jpg",
                ];
            }
        }

        return $response->withJSON([
            "images" => $res,
            "callback" => "on_upload",
            "callback_args" => $res,
        ]);
    }

    /**
     * Dump files from database to the data folder.
     **/
    public function onDump(Request $request, Response $response, array $args)
    {
        $settings = $this->container->get("settings")["files"] ?? [];

        $folder = $settings["path"];
        $dmode = $settings["dmode"] ?? 0777;
        $fmode = $settings["fmode"] ?? 0666;

        $files = $this->db->fetch("SELECT `id`, name, mime_type, kind, length, created, uploaded, hash FROM `files`");
        foreach ($files as $file) {
            $b1 = substr($file["hash"], 0, 1);
            $b2 = substr($file["hash"], 1, 2);
            $fname = $b1 . "/" . $b2 . "/" . $file["hash"];
            $fpath = $folder . "/" . $fname;

            if (file_exists($fpath)) {
                $this->logger->debug("files: file {name} exists.", [
                    "name" => $fname,
                ]);
            }

            else {
                $fdir = dirname($fpath);
                if (!is_dir($fdir)) {
                    $res = @mkdir($fdir, $dmode, true);

                    if (false === $res) {
                        $this->logger->error("files: could not create folder {dir}", [
                            "dir" => $fdir,
                        ]);

                        throw new \RuntimeException("could not create folder");
                    } else {
                        $this->logger->debug("files: created folder {dir}", [
                            "dir" => $fdir,
                        ]);
                    }
                }

                $body = $this->db->fetchcell("SELECT body FROM files WHERE id = ?", [$file["id"]]);

                file_put_contents($fpath, $body);
                chmod($fpath, $fmode);

                $this->logger->debug("files: file {name} created.", [
                    "name" => $fname,
                ]);
            }

            if ($node = $this->node->getByKey($file["hash"])) {
                $this->logger->debug("files: node with key={key} already exists.", [
                    "key" => $file["hash"],
                ]);
            }

            else {
                $node = [
                    "id" => $file["id"],
                    "type" => "file",
                    "key" => $file["hash"],
                    "name" => $file["name"],
                    "fname" => $fname,
                    "kind" => $file["kind"],
                    "mime_type" => $file["mime_type"],
                    "length" => (int)$file["length"],
                    "created" => strftime("%Y-%m-%d %H:%M:%S", $file["created"]),
                    "uploaded" => strftime("%Y-%m-%d %H:%M:%S", $file["uploaded"]),
                    "hash" => $file["hash"],
                    "published" => 1,
                ];

                $node = $this->node->save($node);
            }
        }

        return "Done.";
    }
}
