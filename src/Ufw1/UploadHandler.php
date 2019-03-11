<?php
/**
 * Handle file uploads.
 *
 * Stores photos in the photo folder, creates smaller versions (400px wide).
 *
 * This is used by the photo upload script in the page editor.
 **/

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;

class UploadHandler extends Handlers
{
    public function onPost(Request $request, Response $response)
    {
        $files = $request->getUploadedFiles();
        foreach ($files as $file) {
            if ($file->getError() == UPLOAD_ERR_OK) {
                $info = $this->receiveFile($file);

                $fi = pathinfo($info["name"]);
                $tn = $fi["filename"] . "_small." . $fi["extension"];

                $code = "<a class='image' href='/files/{$info["name"]}' data-fancybox='gallery' data-caption='No description'><img src='/thumbnail/{$tn}' alt='{$info["real_name"]}'/></a>";

                return $response->withJSON(array(
                    "code" => $code,
                    ));
            }
        }
    }

    /**
     * Receive the uploaded file and save it.
     **/
    protected function receiveFile(UploadedFile $file)
    {
        $res = array(
            "name" => null,  // generate me
            "real_name" => $file->getClientFilename(),
            "type" => $file->getClientMediaType(),
            "length" => $file->getSize(),
            "created" => time(),
            "body" => null,  // fill me in
            );

        $ext = mb_strtolower(pathinfo($res["real_name"], PATHINFO_EXTENSION));
        if (!in_array($ext, ["jpg", "jpeg", "png", "gif"]))
            throw new RuntimeException("file of unsupported type");

        $tmp = tempnam($_SERVER["DOCUMENT_ROOT"], "upload_");
        $file->moveTo($tmp);

        $res["body"] = file_get_contents($tmp);
        unlink($tmp);

        $hash = md5($res["body"]);
        if ($file = $this->db->getFileByHash($hash))
            return $file;

        $part1 = substr(sha1($_SERVER["DOCUMENT_ROOT"]), 0, 10);
        $part2 = substr(sha1($res["body"]), 0, 10);
        $part3 = sprintf("%x", time());

        $res["name"] = sprintf("%s_%s_%s.%s", $part1, $part2, $part3, $ext);

        $this->db->saveFile($res);

        return $res;
    }
}
