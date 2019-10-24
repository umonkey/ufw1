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
        $logger = $this->container->get('logger');

        if ($node["type"] != "file") {
            $logger->debug('thumbnailer: node {0} is not a file.', [$node['id']]);
            return $node;
        }

        if (empty($node["files"]["original"])) {
            $logger->debug('thumbnailer: node {0} has no original.', [$node['id']]);
            return $node;
        }

        if (!($config = @$this->container->get("settings")["thumbnails"])) {
            $logger->debug('thumbnailer: not configured.');
            return $node;
        }

        if (empty($node['files']['original']['storage'])) {
            $logger->debug('thumbnailer: node {0} has no original section.', [$node['id']]);
            return $node;
        }

        $original = null;

        foreach ($config as $key => $options) {
            if ($key == 'original')
                continue;

            if ($force or empty($node["files"][$key])) {
                if ($original === null) {
                    $original = $this->getSource($node['files']['original']);
                    if (empty($original)) {
                        $logger->debug('thumbnailer: could not read original of node {0}.', [$node['id']]);
                        throw new \RuntimeException('source file not found');
                    }
                }

                $img = $this->readImageFromString($original);
                $img = $this->scaleImage($img, $options);

                $type = $node['files']['original']['type'];
                if ($type == 'image/png') {
                    $res = $this->getImagePNG($img);
                } else {
                    $res = $this->getImageJPEG($img);
                    $type = 'image/jpeg';
                }

                list($w, $h) = $this->getImageSize($img);
                $this->destroyImage($img);

                $path = $this->container->get('file')->fsput($res);

                $node["files"][$key] = [
                    "type" => $type,
                    "length" => strlen($res),
                    "storage" => "local",
                    "path" => $path,
                    "width" => $w,
                    "height" => $h,
                    "url" => "/node/{$node['id']}/download/{$key}",
                ];
            } else {
                $logger->debug('thumbnailer: node {0} already has file {1}.', [$node['id'], $key]);
            }
        }

        $logger->debug('thumbnailer: node {0} updated, files={1}', [$node['id'], $node['files']]);

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

    protected function readImageFromString($blob)
    {
        $img = @imagecreatefromstring($blob);

        if (false === $img)
            throw new \RuntimeException('error parsing image');

        return $img;
    }

    protected function getImagePNG($img)
    {
        ob_start();
        imagepng($img, null, 9);
        $res = ob_get_clean();
        return $res;
    }

    protected function getImageJPEG($img)
    {
        ob_start();
        imagejpeg($img, null, 85);
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

        list($iw, $ih) = $this->getImageSize($img);

        $scale = 1;

        if ($options['width'] and $options['width'] < $iw)
            $scale = $options['width'] / $iw;

        if ($options['height'] and $options['height'] < $ih)
            $scale = min($scale, $options['height'] / $ih);

        $nw = round($iw * $scale);
        $nh = round($ih * $scale);

        $img = $this->resizeImage($img, $nw, $nh);

        if ($options['sharpen'])
            $img = $this->sharpenImage($img);

        return $img;
    }

    protected function getImageSize($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);

        return [$w, $h];
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
            return $this->container->get('file')->fsget($file['path']);
        }

        elseif ($file["storage"] == "s3") {
            $url = $file['url'];
            $this->container->get('logger')->debug('thumbnailer: fetching {0}', [$url]);
            $data = @file_get_contents($url);
            $this->container->get('logger')->debug('thumbnailer: read {0} bytes.', [strlen($data)]);
            return $data;
        }

        else {
            throw new \RuntimeException("unsupported storage type: {$file["storage"]}");
        }
    }

    protected function resizeImage($img, $nw, $nh)
    {
        list($iw, $ih) = $this->getImageSize($img);

        $dst = imagecreatetruecolor($nw, $nh);

        $res = imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $iw, $ih);
        if (false === $res)
            throw new \RuntimeException('could not resize the image');

        imagedestroy($img);
        $img = $dst;

        return $img;
    }

    protected function destroyImage($img)
    {
        imagedestroy($img);
    }
}
