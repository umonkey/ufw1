<?php
/**
 * Prepare file thumbnails.
 *
 * TODO: read profiles from settings.
 **/

namespace Ufw1;

class Thumbnailer
{
    protected $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * Add missing thumbnails to a file node.
     *
     * @param array $node Source file node.
     * @return array Modified node.
     **/
    public function updateNode(array $node, $force = false)
    {
        if ($node["type"] != "file")
            return $node;

        if (empty($node["files"]["original"]))
            return $node;

        if (!($config = @$this->container->get("settings")["thumbnails"]))
            return $node;

        if (empty($node['files']['original']['storage'])) {
            $this->container->get('logger')->debug('thumbnailer: node {0} has no original section.', [$node['id']]);
            return $node;
        }

        $original = $this->getSource($node['files']['original']);

        foreach ($config as $key => $options) {
            if ($key == 'original')
                continue;

            if ($force or empty($node["files"][$key])) {
                $img = @imageCreateFromString($original);
                if (false === $img)
                    throw new \RuntimeException("error parsing image");

                $img = $this->scaleImage($img, $options);

                $type = $node['files']['original']['type'];
                if ($type == 'image/png') {
                    ob_start();
                    imagepng($img, null, 9);
                    $res = ob_get_clean();
                } else {
                    ob_start();
                    imagejpeg($img, null, 85);
                    $res = ob_get_clean();
                    $type = 'image/jpeg';
                }

                $path = $this->filePut($res);

                $node["files"][$key] = [
                    "type" => $type,
                    "length" => strlen($res),
                    "storage" => "local",
                    "path" => $path,
                    "width" => imagesx($img),
                    "height" => imagesy($img),
                    "url" => "/node/{$node['id']}/download/{$key}",
                ];
            }
        }

        return $node;
    }

    public function createDefault($imageBody)
    {
        $img = @imageCreateFromString($imageBody);
        if (false === $img)
            throw new \RuntimeException("error parsing image");

        $img = $this->scaleImage($img, [
            "width" => 300,
            "height" => 200,
        ]);

        ob_start();
        imageJpeg($img, null, 85);
        $res = ob_get_clean();

        return $res;
    }

    /**
     * Downscale the image.
     *
     * Scales the image according to the specified size limits.  Never enlarges.
     *
     * @param resource $img Source image.
     * @param array $options Scale options: width, height, sharpen.
     * @return resource New image.
     **/
    protected function scaleImage($img, array $options)
    {
        $options = array_merge([
            "width" => null,
            "height" => null,
            "sharpen" => false,
        ], $options);

        $iw = imagesx($img);
        $ih = imagesy($img);

        $scale = 1;

        if ($options['width'] and $options['width'] < $iw)
            $scale = $options['width'] / $iw;

        if ($options['height'] and $options['height'] < $ih)
            $scale = min($scale, $options['height'] / $ih);

        $nw = round($iw * $scale);
        $nh = round($ih * $scale);

        $dst = imagecreatetruecolor($nw, $nh);

        $res = imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $iw, $ih);
        if (false === $res)
            throw new \RuntimeException("could not resize the image");

        imagedestroy($img);
        $img = $dst;

        if ($options['sharpen'])
            $img = $this->sharpenImage($img);

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

    /**
     * Returns the source image body.
     *
     * Handles different storage types.
     *
     * @param array $file File description, with keys: storage, path.
     **/
    protected function getSource(array $file)
    {
        if ($file["storage"] == "local") {
            $st = $this->container->get("settings")["files"]["path"];
            $src = $st . "/" . $file["path"];
            if (!is_readable($src))
                throw new \RuntimeException("source file {$file['path']} does not exist");
            return file_get_contents($src);
        }

        elseif ($file["storage"] == "s3") {
            $st = $this->container->get("settings")["S3"];
            $bucket = $st["bucket"];
            $endpoint = $st["endpoint"];
            // $url = "https://{$bucket}.{$endpoint}/{$file["path"]}";
            $url = $file['url'];
            $data = @file_get_contents($url);
            return $data;
        }

        else {
            throw new \RuntimeException("unsupported storage type: {$file["storage"]}");
        }
    }

    protected function filePut($body)
    {
        $st = array_merge([
            "path" => null,
            "dmode" => 0750,
            "fmode" => 0640,
        ], $this->container->get("settings")["files"]);

        $hash = md5($body);
        $path = substr($hash, 0, 1) . "/" . substr($hash, 1, 2) . "/" . $hash;

        $dst = $st["path"] . "/" . $path;

        $dir = dirname($dst);
        if (!is_dir($dir))
            mkdir($dir, $st["dmode"], true);

        $res = file_put_contents($dst, $body);
        if ($res === false) {
            throw new \RuntimeException("error saving a file as {$path}");
        } else {
            chmod($dst, $st["fmode"]);
        }

        return $path;
    }
}
