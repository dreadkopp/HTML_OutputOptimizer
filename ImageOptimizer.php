<?php


namespace vendor\dreadkopp\HTML_OutputOptimizer;


class ImageOptimizer
{
    private $cache = null;
    private $root_dir = '';
    private $image_root_fs = '';

    public function __construct($source, $cachepath, $cachedAndOptimizedName, $cache, $cachetime, $root_dir, $image_root_fs)
    {
        $this->cache = $cache;
        $this->root_dir = $root_dir;
        $this->image_root_fs = $image_root_fs;
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

        //if takes longer than this something has gone south badly
        set_time_limit(10);

        if ($this->cache_image($source, $cachepath)) {
            //optimize
            $cachekey = 'B64IMAGE:'.str_replace(".", "", $cachedAndOptimizedName);
            $type = pathinfo($cachepath, PATHINFO_EXTENSION);

            $small_image_data = $this->handleImage($cachepath,96);

            $base64 = 'data:image/' . $type . ';base64,' . base64_encode($small_image_data);
            $this->cache->set($cachekey, $base64);
            $this->cache->expire($cachekey, $cachetime);
            //1320 is xxl width of biggest bootstrap container... sound like a reasonable max width
            $this->handleImage($cachepath,1320,true);

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
            $image->setImageCompressionQuality(80);
            if ($image->getImageWidth() > $width ) {
                //that should really be enough, however make it configurable as well in the future
                $image->resizeImage($width,1920,\Imagick::FILTER_LANCZOS,1,true);
            }
            if ($save) {
                $image->writeImage($path);
                $path_wo_filetype = preg_replace("/\.[^.]+$/", "", $path);
                $webp_cmd = '/usr/bin/cwebp ' . $path . ' -o ' . $path_wo_filetype . '.webp';
                exec($webp_cmd);
            } else {
                return $image->getImageBlob();
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
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
            $baseurl = $this->image_root_fs . rtrim($url, "/");
            $baseurl = realpath($baseurl);
            if (!file_exists($baseurl)) {
                $baseurl = $this->root_dir . rtrim($url, "/");
                $baseurl = realpath($baseurl);
            }
            if ($baseurl) {
                echo "copy " . $baseurl . ' tot ' . '$saveto';
                return copy($baseurl, $saveto);
            } else {
                return false;
            }
        } else {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $raw = curl_exec($ch);

            curl_close($ch);


            if (strpos($raw, '<!DOCTYPE HTML') !== false) {
                return false;
            }
            if (!$raw) {
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
