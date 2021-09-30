<?php


namespace dreadkopp\HTML_OutputOptimizer\Handler;


use dreadkopp\HTML_OutputOptimizer\AsyncProcessStore;
use dreadkopp\HTML_OutputOptimizer\OutputOptimizer;
use Exception;
use Imagick;
use ImagickException;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImageOptimizer
{
    private $root_dir      = '';
    private $image_root_fs = '';
    
    public function __construct($source, $cachepath, $root_dir, $image_root_fs)
    {
        $this->root_dir = $root_dir;
        $this->image_root_fs = $image_root_fs;
        $this->optimize($source, $cachepath);
    }
    
    /**
     *
     * @param $source
     * @param $cachepath
     * @param $hashed_name
     */
    private function optimize($source, $cachepath)
    {
        $count_cpu = shell_exec('cat /proc/cpuinfo | grep processor | wc -l');
        $current_load = sys_getloadavg()[0];
        $threshold_percent = OutputOptimizer::LOAD_THRESHOLD_PERCENT;
        if (($current_load / $count_cpu) > ($threshold_percent / 100)) {
            //bail due to high load
            return;
        }
        
        //if takes longer than this something has gone south badly
        set_time_limit(10);
        
        if ($this->cache_image($source, $cachepath)) {
            //1320 is xxl width of biggest bootstrap container... sound like a reasonable max width
            $this->handleImage($cachepath, 1320, true);
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


        //strip query parameters
        $url = explode('?',$url)[0];
        
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
    
    /**
     * Resizes and stores (optional) an image
     *
     * @param      $path
     * @param      $width
     * @param null $save
     * @return string
     * @throws ImagickException
     */
    private function handleImage($path, $width, $save = null)
    {
        
        try {
            $image = new Imagick($path);
            $image->optimizeImageLayers();
            $image->setImageCompressionQuality(80);
            if ($image->getImageWidth() > $width) {
                //that should really be enough, however make it configurable as well in the future
                $image->resizeImage($width, 1920, Imagick::FILTER_LANCZOS, 1, true);
            }
            if ($save) {
                $path_wo_filetype = preg_replace("/\.[^.]+$/", "", $path);
                if (file_exists($path)) {
                    @unlink($path);
                    @unlink($path_wo_filetype . '.webp');
                }
                $image->writeImage($path);
                $webp_cmd = '/usr/bin/cwebp ' . $path . ' -o ' . $path_wo_filetype . '.webp';
                exec($webp_cmd);
            } else {
                return $image->getImageBlob();
            }
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }
        
    }
    
    public static function optimizeAndCacheImages(
        $source,
        $image_root_fs,
        $root_dir,
        $cache_dir,
        &$skip_counter,
        $skip_x_lazy_images,
        $public_cache_dir
    )
    {
        
        $cache_dir .= 'img/';
        
        
        $returnstring = 'data-src="' . $source[1] . '"';
        
        if ((strpos($source[1], 'cache') !== false)) {
            return $returnstring;
        }
        
        $tmp = explode('.', $source[1]);
        $filetype = end($tmp);
        $filename = hash('md5', $source[1]);
        $cachepath = $root_dir . $cache_dir;
        if (!is_dir($cachepath)) {
            if (!mkdir($cachepath, 0770, true) && !is_dir($cachepath)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $cachepath));
            }
        }
        $hashed_name = $filename . '.' . $filetype;
        $path = $cachepath . $hashed_name;
        
        //if cached file exists and is not older than expire time, else create/update image in cache
        if (file_exists($path) && (time() - filemtime($path) < OutputOptimizer::CACHETIME - 10)) {
            
            if (
                isset($_SERVER['HTTP_ACCEPT']) &&
                strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false &&
                file_exists($cachepath . $filename . '.webp')
            ) {
                $hashed_name = $filename . '.webp';
            }
            
            $public_cache_dir .= 'img/';
            
            if ($skip_counter >= $skip_x_lazy_images) {
                $returnstring = ' data-src="' . $public_cache_dir . $hashed_name . '"';
            } else {
                $skip_counter++;
                $returnstring = ' src="' . $public_cache_dir . $hashed_name . '"';
                
            }
            
        } else {
            
            $process = new Process(
                [
                    'php',
                    dirname(__DIR__) . '/ImageOptimizer_helper.php',
                    $source[1],
                    $path,
                    $hashed_name,
                    $root_dir,
                    $image_root_fs,
                ]
            );
            
            /** @var AsyncProcessStore $store */
            $store = AsyncProcessStore::getInstance();
            $store->addProcess($process);
            
            $returnstring = 'src="' . $source[1] . '"';
        }
        
        return $returnstring;
        
    }
    
    
}
