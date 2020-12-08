<?php


namespace dreadkopp\HTML_OutputOptimizer\Handler;


use dreadkopp\HTML_OutputOptimizer\AsyncProcessStore;
use dreadkopp\HTML_OutputOptimizer\OutputOptimizer;
use http\Client;
use Predis\Connection\Parameters;
use Symfony\Component\Process\Process;

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
    	$count_cpu = shell_exec('cat /proc/cpuinfo | grep processor | wc -l');
    	$current_load = sys_getloadavg()[0];
    	$threshold_percent = OutputOptimizer::LOAD_THRESHOLD_PERCENT;
    	if ($current_load/$count_cpu > $threshold_percent/100) {
    		echo 'skipping due to high load';
		}

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
    
    public static function optimizeAndCacheImages(
    	$source,
		$image_root_fs,
		$root_dir,
		$cache_dir,
		&$skip_counter,
		$skip_x_lazy_images,
		$public_cache_dir,
		$use_b64_images = false,
		\Predis\Client $cache
	)
	{
		/** @var Parameters $redis_params */
		$redis_params = $cache->getConnection()->getParameters();
		$redis_host = $redis_params->host;
		$redis_db = $redis_params->database;
		$redis_port = $redis_params->port;
		$redis_pass = $redis_params->password;
		
	
		$returnstring = 'data-src="' . $source[1] . '"';
	
		if ((strpos($source[1], 'cache') !== false)) {
			return $returnstring;
		}
	
		$tmp = explode('.', $source[1]);
		$filetype = end($tmp);
		$filename = hash('md5', $source[1]);
		$cachepath = $root_dir . $cache_dir;
		if (!file_exists($cachepath)) {
			mkdir($cachepath, 0770, true);
		}
		$cachedAndOptimizedName = $filename . '.' . $filetype;
		$path = $cachepath . $cachedAndOptimizedName;
	
		//if cached file exists and is not older than expire time, else create/update image in cache and update b64
		if (file_exists($path) && (time()-filemtime($path) < OutputOptimizer::CACHETIME - 10)) {
		
			if( strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false && file_exists($cachepath.$filename . '.webp')) {
				$cachedAndOptimizedName = $filename . '.webp';
			}
		
			if ($use_b64_images) {
				if ($skip_counter >= $skip_x_lazy_images) {
					$base64data = self::getBase64Image($cachedAndOptimizedName,$cache);
					$returnstring = ' src="' . $base64data . '"' .' data-src="' . $public_cache_dir . $cachedAndOptimizedName . '"';
				} else {
					$skip_counter++;
					$returnstring = ' src="' . $public_cache_dir . $cachedAndOptimizedName . '"';
				}
			} else {
				if ($skip_counter >= $skip_x_lazy_images) {
					$returnstring = ' data-src="' . $public_cache_dir . $cachedAndOptimizedName . '"';
				} else {
					$skip_counter++;
					$returnstring = ' src="' . $public_cache_dir . $cachedAndOptimizedName . '"';
				
				}
			}
		
		} else {
		
		
		
			if (file_exists($path)){
				@unlink($path);
				@unlink($cachepath.$filename. '.webp');
			}
			$cmd = 'php ' . __DIR__ . '/ImageOptimizer_helper.php "' .
				$source[1] . '" "' .
				$path . '" "' .
				$cachedAndOptimizedName . '" "' .
				$root_dir . '" "' .
				$image_root_fs . '" "' .
				$redis_pass . '" "' .
				$redis_db . '" "' .
				OutputOptimizer::CACHETIME . '" "' .
				$redis_host. '" "' .
				$redis_port . '"';
		
		
				//new self($source[1], $path, $cachedAndOptimizedName, $cache, OutputOptimizer::CACHETIME, $root_dir, $image_root_fs);
				   $process = new Process(
						[
							'php',
							__DIR__ . '/../ImageOptimizer_helper.php',
							$source[1],
							$path,
							$cachedAndOptimizedName,
							$root_dir,
							$image_root_fs,
							$redis_pass,
							$redis_db,
							OutputOptimizer::CACHETIME,
							$redis_host,
							$redis_port,
						]
				   );
				   $process->start();
				   $store = AsyncProcessStore::getInstance($cache);
				   $store->addProcess($process);
		
			//self::executeAsyncShellCommand($cmd);
			$returnstring = 'src="' . $source[1] . '"';
		}
	
		return $returnstring;
	
	}
	
	/**
	 * Converts an image to base64 and puts that info in redis cache
	 *
	 * @param $file
	 * @param $cachedFileName
	 * @return string
	 */
	private static function getBase64Image($cachedFileName, $cache) {
		
		$cachekey = 'B64IMAGE:'.str_replace(".","",$cachedFileName);
		/** @var $cache Predis\Client */
		$cacheddata = $cache->get($cachekey);
		if ($cacheddata) {
			return $cacheddata;
		} else {
			return '';
		}
		
	}
	
	/**
	 * Execute a command on host for asyncronity
	 *
	 * @param null $comando
	 * @throws Exception
	 */
	private static function executeAsyncShellCommand($comando = null){
		if(!$comando){
			throw new \Exception("No command given");
		}
		@exec("/usr/bin/nohup ".$comando." > /dev/null 2>&1 &");
	}
}
