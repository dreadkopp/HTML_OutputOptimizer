<?php


namespace vendor\dreadkopp\HTML_OutputOptimizer;


class ImageOptimizer
{
    private $cache = null;
    private $root_dir = '';

    public function __construct($source, $cachepath, $cachedAndOptimizedName, $cache, $cachetime, $root_dir)
    {


        $this->cache = $cache;
        $this->root_dir = $root_dir;
        $this->optimize($source, $cachepath, $cachedAndOptimizedName, $cachetime);
    }

    /**
     *
     * @param $source
     * @param $cachepath
     * @param $cachedAndOptimizedName
     */
    private function optimize($source, $cachepath, $cachedAndOptimizedName, $cachetime)
    {


        if ($this->cache_image($source, $cachepath)) {
            //optimize
            $cachekey = str_replace(".", "", $cachedAndOptimizedName);
            $type = pathinfo($cachepath, PATHINFO_EXTENSION);

            $small_image_data = $this->handleImage($cachepath,96);

            $base64 = 'data:image/' . $type . ';base64,' . base64_encode($small_image_data);
            $this->cache->set($cachekey, $base64);
            $this->cache->expire($cachekey, $cachetime);
            $this->handleImage($cachepath,1024,true);

        }
    }


    /**
     * Resizes and stores (optional) an image
     *
     * @param $path
     * @param $width
     * @param null $save
     * @return string
     * @throws \ImagickException
     */
    private function handleImage($path,$width,$save = null) {

        try {
            $image = new \Imagick($path);
            $image->optimizeImageLayers();
            $image->setImageCompressionQuality(85);
            if ($image->getImageWidth() > $width ) {
                //that should really be enough, however make it configurable as well in the future
                $image->resizeImage($width,1920,\Imagick::FILTER_LANCZOS,1,true);
            }
            if ($save) {
                $image->writeImage($path);
                $webp_cmd = '/usr/bin/cwebp ' . $path . ' -o ' . $path . '.webp';
                exec($webp_cmd);
            } else {
                return $image->getImageBlob();
            }

        } catch (\Exception $e) {
            //well... some images are just broken....
        }

    }

    /**
     * if local, copy, if remote, fetch image and put it in destination
     *
     * @param $url
     * @param $saveto
     * @return bool
     */
    private function cache_image($url, $saveto)
    {

        if (file_exists($saveto)) {
            return true;
        }

        if (!(strpos($url, 'http') !== false)) {
            $url = $this->root_dir . rtrim($url, "/");
            $url = realpath($url);
            if ($url) {
                return copy($url, $saveto);
            } else {
                return false;
            }
        } else {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            $raw = curl_exec($ch);

            curl_close($ch);


            if (strpos($raw, '<!DOCTYPE HTML') !== false) {
                return false;
            }

            if (file_exists($saveto)) {
                unlink($saveto);
            }
            $fp = fopen($saveto, 'x');
            if (fwrite($fp, $raw)) {
                fclose($fp);
                return true;
            } else {
                return false;
            }
        }
    }
}