<?php

/**
 * Prepare file thumbnails.
 *
 * TODO: read profiles from settings.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class Thumbnailer
{
    /**
     * @var LoggerInterface
     **/
    protected $logger;

    protected $file;

    /**
     * @var array
     **/
    protected $config;

    public function __construct(array $config, LoggerInterface $logger, FileRepository $file)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->file = $file;
    }

    /**
     * Add missing thumbnails to a file node.
     *
     * @param array $node Source file node.
     * @return array Modified node.
     **/
    public function updateNode(array $node, bool $force = false): array
    {
        $logger = $this->logger;

        if ($node["type"] != "file") {
            $logger->debug('thumbnailer: node {0} is not a file.', [$node['id']]);
            return $node;
        }

        if (empty($node["files"]["original"])) {
            $logger->debug('thumbnailer: node {0} has no original.', [$node['id']]);
            return $node;
        }

        if (!($config = $this->config)) {
            $logger->debug('thumbnailer: not configured.');
            return $node;
        }

        if (empty($node['files']['original']['storage'])) {
            $logger->debug('thumbnailer: node {0} has no original section.', [$node['id']]);
            return $node;
        }

        $src = null;
        $updateCount = 0;

        foreach ($config as $key => $options) {
            if ($key == 'original') {
                continue;
            }

            $missing = empty($node['files'][$key]);

            if (isset($node['files'][$key]) and $node['files'][$key]['storage'] == 'local') {
                $dst = $this->file->fsgetpath($node['files'][$key]['path']);
                if (!file_exists($dst)) {
                    $missing = true;
                }
            }

            if ($force or $missing) {
                $srckey = $options['from'] ?? 'original';
                $src = $this->getSourcePath($node, $srckey);

                $logger->debug('thumbnailer: reading {0} image from file {1}', [$srckey, $src]);
                $img = $this->readImageFromFile($src);

                $img = $this->scaleImage($img, $options);

                $logger->debug('thumbnailer: getting image blob');

                $format = $options['format'] ?? null;

                if ($format == 'webp') {
                    $res = $this->getImageWebP($img);
                    $type = 'image/webp';
                } elseif ($node['files']['original']['type'] == 'image/png') {
                    $res = $this->getImagePNG($img);
                    $type = 'image/png';
                } else {
                    $res = $this->getImageJPEG($img);
                    $type = 'image/jpeg';
                }

                list($w, $h) = $this->getImageSize($img);

                $logger->debug('thumbnailer: destroying the image');
                $this->destroyImage($img);

                $path = $this->file->fsput($res);

                $node["files"][$key] = [
                    "type" => $type,
                    "length" => strlen($res),
                    "storage" => "local",
                    "path" => $path,
                    "width" => $w,
                    "height" => $h,
                ];

                $logger->debug('thumbnailer: done with {0}', [$key]);

                $updateCount++;
            } else {
                $logger->debug('thumbnailer: node {0} already has file {1}.', [$node['id'], $key]);
            }
        }

        if ($updateCount) {
            $logger->debug('thumbnailer: node {0} updated, files={1}', [$node['id'] ?? null, $node['files']]);
        }

        return $node;
    }

    public function createDefault(string $imageBody): string
    {
        $img = @imageCreateFromString($imageBody);
        if (false === $img) {
            throw new \RuntimeException("error parsing image");
        }

        $img = $this->scaleImage($img, [
            "width" => 300,
            "height" => 200,
        ]);

        ob_start();
        imageJpeg($img, null, 85);
        $res = ob_get_clean();

        return $res;
    }

    protected function readImageFromString(string $blob)
    {
        $img = @imagecreatefromstring($blob);

        if (false === $img) {
            throw new \RuntimeException('error parsing image');
        }

        return $img;
    }

    protected function readImageFromFile(string $src)
    {
        $data = file_get_contents($src);
        $img = imagecreatefromstring($data);
        unset($data);
        return $img;
    }

    protected function getImagePNG($img): string
    {
        ob_start();
        imagepng($img, null, 9);
        $res = ob_get_clean();
        return $res;
    }

    protected function getImageJPEG($img): string
    {
        ob_start();
        imagejpeg($img, null, 85);
        $res = ob_get_clean();
        return $res;
    }

    protected function getImageWebP($img): string
    {
        ob_start();
        imagewebp($img, null);
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

        if ($options['width'] and $options['width'] < $iw) {
            $scale = $options['width'] / $iw;
        }

        if ($options['height'] and $options['height'] < $ih) {
            $scale = min($scale, $options['height'] / $ih);
        }

        $nw = (int)round($iw * $scale);
        $nh = (int)round($ih * $scale);

        $this->logger->debug('thumbnailer: resizing image from {0}x{1} to {2}x{3}', [$iw, $ih, $nw, $nh]);

        // Never upscale.
        if ($nw < $iw and $nh < $ih) {
            $img = $this->resizeImage($img, $nw, $nh);
        }

        if ($options['sharpen']) {
            $this->logger->debug('thumbnailer: sharpening');
            $img = $this->sharpenImage($img);
        }

        return $img;
    }

    protected function getImageSize($img): array
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
            return $this->file->fsget($file['path']);
        } elseif ($file["storage"] == "s3") {
            $url = $file['url'];
            $this->logger->debug('thumbnailer: fetching {0}', [$url]);
            $data = @file_get_contents($url);
            $this->logger->debug('thumbnailer: read {0} bytes.', [strlen($data)]);
            return $data;
        } else {
            throw new \RuntimeException("unsupported storage type: {$file["storage"]}");
        }
    }

    protected function resizeImage($img, int $nw, int $nh)
    {
        list($iw, $ih) = $this->getImageSize($img);

        $dst = imagecreatetruecolor($nw, $nh);

        $res = imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $iw, $ih);
        if (false === $res) {
            throw new \RuntimeException('could not resize the image');
        }

        imagedestroy($img);
        $img = $dst;

        return $img;
    }

    protected function destroyImage($img): void
    {
        imagedestroy($img);
    }

    protected function getSourcePath(array $file, string $key): string
    {
        $logger = $this->logger;

        if (empty($file['files'][$key])) {
            throw new \RuntimeException('file version not found: ' . $key);
        }

        $f = $file['files'][$key];
        if (empty($f['path'])) {
            throw new \RuntimeException('file version has no path: ' . $key);
        }

        $src = $this->file->fsgetpath($f['path']);
        if (file_exists($src)) {
            return $src;
        }

        if ($f['storage'] == 's3') {
            $url = $f['url'];
            $logger->debug('thumbnailer: pulling {0} from {1}', [$src, $url]);

            if (false === ($body = file_get_contents($url))) {
                $logger->error('thumbnailer: could not fetch {0}', [$url]);
                throw new \RuntimeException('error fetching remote file');
            }

            if (!is_dir($dir = dirname($src))) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($src, $body);
            unset($body);

            $logger->debug('thumbnailer: file {0} successfully saved.');
        }

        return $src;
    }
}
