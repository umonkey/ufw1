<?php

/**
 * Alternative thumbnailer, based on Imagick.
 * https://www.php.net/imagick
 **/

namespace Ufw1\Services;

class Thumbnailer2 extends Thumbnailer
{
    protected function readImageFromString($blob)
    {
        $img = new \Imagick();
        $res = $img->readImageBlob($blob);
        if ($res === false) {
            throw new \RuntimeException('error reading image');
        }
        return $img;
    }

    protected function readImageFromFile($src)
    {
        $img = new \Imagick();
        $res = $img->readImage($src);
        if ($res === false) {
            throw new \RuntimeException('error reading image');
        }
        return $img;
    }

    protected function getImagePNG($img)
    {
        return $img->getImageBlob();
    }

    protected function getImageJPEG($img)
    {
        return $img->getImageBlob();
    }

    protected function getWebP($img)
    {
        // https://www.gauntface.com/blog/2014/09/02/webp-support-with-imagemagick-and-php
        $img->setImageFormat('webp');
        return $img->getImageBlob();
    }

    protected function getImageSize($img)
    {
        $geo = $img->getImageGeometry();
        return [$geo['width'], $geo['height']];
    }

    protected function resizeImage($img, $nw, $nh)
    {
        $sz = $this->getImageSize($img);
        if ($sz[0] * $sz[1] >= 4000000) {
            $filter = \Imagick::FILTER_POINT;
            $this->logger->debug('thumbnailer: source is too big, using point filter');
        } else {
            $filter = \Imagick::FILTER_CATROM;
        }

        $res = $img->resizeImage($nw, $nh, $filter, 1);
        if ($res === false) {
            throw new \RuntimeException('error resizing image');
        }
        return $img;
    }

    protected function sharpenImage($img)
    {
        return $img;
    }

    protected function destroyImage($img)
    {
        $img->destroy();
    }
}
