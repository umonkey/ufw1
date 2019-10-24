<?php
/**
 * Alternative thumbnailer, based on Imagick.
 * https://www.php.net/imagick
 **/

namespace Ufw1;

class Thumbnailer2 extends Thumbnailer
{
    protected function readImageFromString($blob)
    {
        $img = new \Imagick();
        $res = $img->readImageBlob($blob);
        if ($res === false)
            throw new \RuntimeException('error reading image');
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

    protected function getImageSize($img)
    {
        $geo = $img->getImageGeometry();
        return [$geo['width'], $geo['height']];
    }

    protected function resizeImage($img, $nw, $nh)
    {
        $res = $img->resizeImage($nw, $nh, \Imagick::FILTER_CATROM, 1);
        if ($res === false)
            throw new \RuntimeException('error resizing image');
        return $img;
    }

    protected function sharpenImage($img)
    {
        return $img;
    }
}
