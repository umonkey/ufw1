<?php

/**
 * Handle file uploads.
 *
 * Stores photos in the photo folder, creates smaller versions (400px wide).
 *
 * This is used by the photo upload script in the page editor.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;
use Ufw1\CommonHandler;
use Ufw1\Util;

class UploadController extends CommonHandler
{
    public function onGet(Request $request, Response $response, array $args): Response
    {
        $this->auth->requireAdmin($request);

        return $this->template->render($response, 'pages/upload.twig');
    }

    public function onPost(Request $request, Response $response, array $args): Response
    {
        $this->auth->requireAdmin($request);

        $info = $this->getFile($request);
        if ($info === false) {
            return $response->withJSON([
                "message" => "Не удалось получить файл.",
            ]);
        }

        $name = "File:{$info["id"]}";
        $text = "# Файл {$info["name"]}\n\n";
        $text .= "Описание файла отсутствует.\n\n";

        $this->db->updatePage($name, $text);
        return $response->withJSON([
            "redirect" => "/wiki?name=" . urlencode($name),
        ]);
    }

    protected function getFile(Request $request): ?array
    {
        $link = $request->getParam("link");
        if (!empty($link)) {
            $file = Util::fetch($link);
            if ($file["status"] == 200) {
                $real_name = $this->getFileName($link, $file);

                $res = [
                    "name" =>  $real_name,
                    "mime_type" => $file["headers"]["content-type"],
                    "length" => strlen($file["data"]),
                    "created" => time(),
                    "body" => $file["data"],
                ];

                $res["id"] = $this->db->saveFile($res);
                return $res;
            }
        } elseif ($files = $request->getUploadedFiles()) {
            if (!empty($files["file"])) {
                return $this->receiveFile($files["file"]);
            }
        }

        return null;
    }

    protected function getFileName($link, array $file): string
    {
        if (!empty($file["headers"]["content-disposition"])) {
            if (preg_match('@filename="([^"]+)"@', $file["headers"]["content-disposition"], $m)) {
                return $m[1];
            }
        }

        $url = parse_url($link);
        $name = basename($url["path"]);

        if (!($ext = pathinfo($name, PATHINFO_EXTENSION))) {
            switch ($file["headers"]["content-type"]) {
                case "image/png":
                    $name .= ".png";
                    break;
                case "image/jpg":
                case "image/jpeg":
                    $name .= ".jpg";
                    break;
                case "image/gif":
                    $name .= ".gif";
                    break;
            }
        }

        return $name;
    }

    /**
     * Receive the uploaded file and save it.
     **/
    protected function receiveFile(UploadedFile $file): array
    {
        $res = array(
            "name" => $file->getClientFilename(),
            "mime_type" => $file->getClientMediaType(),
            "created" => time(),
            "body" => null,  // fill me in
            );

        $ext = mb_strtolower(pathinfo($res["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, ["jpg", "jpeg", "png", "gif"])) {
            throw new \RuntimeException("file of unsupported type: {$ext}");
        }

        $tmp = tempnam($_SERVER["DOCUMENT_ROOT"], "upload_");
        $file->moveTo($tmp);

        $res["body"] = file_get_contents($tmp);
        unlink($tmp);

        $hash = md5($res["body"]);
        if ($file = $this->db->getFileByHash($hash)) {
            return $file;
        }

        $id = $this->db->saveFile($res);
        $res["id"] = $id;

        return $res;
    }
}
