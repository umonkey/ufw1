<?php
/**
 * Generates a thumbnail for the file.
 *
 * Generated thumbnails are saved in the database.
 **/

namespace App\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use App\Handlers;

class Thumbnail extends Handlers
{
    public function onGet(Request $request, Response $response, array $args)
    {
        list($name, $type, $options) = $this->parseName($args["name"]);

        if (!($tn = $this->loadThumbnail($name, $type)))
            $tn = $this->prepareThumbnail($name, $type, $options);

        $mimeType = "image/jpeg";
        $length = strlen($tn["body"]);

        // Make a copy to serve with Nginx later, if the folder exists.
        $dst = $_SERVER["DOCUMENT_ROOT"] . $request->getUri()->getPath();
        if (is_dir($dir = dirname($dst)))
            file_put_contents($dst, $tn["body"]);

        $response = $response->withHeader("Content-Type", $mimeType)
            ->withHeader("Content-Length", $length)
            ->withHeader("ETag", "\"{$tn["hash"]}\"")
            ->withHeader("Cache-Control", "public, max-age=31536000");
        $response->getBody()->write($tn["body"]);

        return $response;
    }

    protected function loadThumbnail($name, $type)
    {
        $res = $this->db->getThumbnail($name, $type);
        return $res;
    }

    protected function prepareThumbnail($name, $type, array $options)
    {
        $file = $this->db->getFileByName($name);
        if (is_null($file))
            throw new \RuntimeException("file not found");

        $img = imageCreateFromString($file["body"]);
        if (false === $img)
            throw new \RuntimeException("not supported file format");

        $img = $this->scaleImage($img, $options);

        ob_start();
        imagejpeg($img, null, 85);
        $body = ob_get_clean();

        $this->db->saveThumbnail($name, $type, $body);

        $hash = md5($body);

        return [
            "body" => $body,
            "hash" => md5($body),
        ];
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
            if ($options["width"] != $iw) {
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

        return $this->sharpenImage($img);
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

    protected function parseName($name)
    {
        if (!preg_match('@^(.+)_([^_.]+)\.(.+)$@', $name, $m))
            throw new \RuntimeException("bad file name");

        $name = $m[1] . "." . $m[3];
        $key = $m[2];

        $set = $this->container->get("settings")["thumbnails"];
        if (!array_key_exists($key, $set))
            throw new \RuntimeException("unknown thumbnail format");

        return [$name, $key, $set[$key]];
    }
}
