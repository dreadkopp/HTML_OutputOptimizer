<?php


namespace dreadkopp\HTML_OutputOptimizer;

use dreadkopp\HTML_OutputOptimizer\Handler\HtmlMinify;
use dreadkopp\HTML_OutputOptimizer\Handler\ImageOptimizer;
use dreadkopp\HTML_OutputOptimizer\Library\Lazyload;
use Predis\Connection\Parameters;
use Symfony\Component\Process\Process;


class OutputOptimizer
{



    private $cache = null;
    private $redis_pass = null;
    private $redis_db = null;
    private $combined_js = '';
    private $inline_js = '';
    private $inline_style = '';
    private $localjs = [];
    private $root_dir = '';
    private $image_root_fs = '';
    private $cache_dir = '';
    private $public_cache_dir = '';
    private $redis_host = '';
    private $redis_port = '';
    private $extra = '';
    private $use_b64_images = true;
    private $skip_x_lazy_images = 0;
    private $skip_counter = 0;
    private $js_version = '1';

    const CACHETIME = 3600;
    const ADD_LOCAL_JS = true;
    const LOAD_THRESHOLD_PERCENT = 60;



    

    /**
     * Add extra strings to add to bottom of output
     * @param $extra
     */
    public function setExtra($extra) {
        $this->extra = $extra;
    }

    public function setJSVersion($tag) {
        $this->js_version = $tag;
    }


    /**
     * OutputOptimizer constructor.
     * @param \Predis\Client $cache
     * @param $root_dir
     * @param $cache_dir
     * @param string $public_cache_dir
     */
    public function __construct(\Predis\Client $cache, $root_dir, $cache_dir, $public_cache_dir = '', $image_root_fs = '',$use_b64 = true, $skip_x_lazy_images = 0 )
    {
        $this->skip_x_lazy_images = $skip_x_lazy_images;
        $this->use_b64_images = $use_b64;
        $this->cache = $cache;
        $redis_params = $cache->getConnection()->getParameters()->toArray();
        $this->redis_pass = $redis_params['password'];
        $this->redis_db = $redis_params['database'];
        $this->redis_host = $redis_params['host'];
        $this->redis_port = $redis_params['port'];
        $this->root_dir =  $root_dir;
        $this->cache_dir = $cache_dir;
        $this->public_cache_dir = $public_cache_dir?:$cache_dir;
        $this->image_root_fs = $image_root_fs?:$root_dir;
    }

    public function addLocalJSPath ($path) {
        $this->localjs[] = $path;
    }

    /**
     * MINIFIES HTML AND CACHES+OPTIMIZES IMAGES
     *
     * @param $buffer
     * @return null|string|string[]
     */
    public function sanitize_output($buffer)
    {
        $time_start = microtime(true);
       

//optimize and Cache images
        $searchimage = '/data-src\s*=\s*"(.+?)"/';
        $buffer = preg_replace_callback($searchimage, function($matches){
                return $this->optimizeAndCacheImages($matches, $this->redis_pass, $this->redis_db);
        }, $buffer);

//minify and Cache JS
        //1. check if we already got a cached version

        $cachepath = $this->root_dir . $this->cache_dir;
        if (!file_exists($cachepath)) {
            mkdir($cachepath, 0770, true);
        }

        $filename = hash('md5', $_SERVER['REQUEST_URI']);
        $cachedAndOptimizedName = $filename . '.js';
        $path = $cachepath . $cachedAndOptimizedName;

        //if we have a saved version, use that one
        if (file_exists($path) && (time()-filemtime($path) < self::CACHETIME - 10)) {
            
            //gather inline js
            $dom = new \DOMDocument();
            @$dom->loadHTML($buffer);
            $script = $dom->getElementsByTagName('script');
            foreach ($script as $js){
                $js = $js->nodeValue;
                $js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
                $this->inline_js .= $js ;
            }

            //remove old script apperances
            $buffer = preg_replace( '#<script(.*?)>(.*?)</script>#is','',$buffer);

            //put all the JS on bottom
            $relative_path = $this->public_cache_dir . $cachedAndOptimizedName . '?v='.$this->js_version;
            $buffer .=  '<script src="'. $relative_path . '"></script>';
            $buffer .= '<script>' . $this->inline_js .'</script>';

        } else {
            
            if  (file_exists($path)) {
                unlink($path);
            }


             //1. add local js
            if (self::ADD_LOCAL_JS) {
                foreach ($this->localjs as $local_js_path) {
                    $minified_js = file_get_contents($local_js_path);
                    $this->combined_js = $this->combined_js . $minified_js;
                }
            }

            //2. find js sources and collect
            $dom = new \DOMDocument();
            @$dom->loadHTML($buffer);
            $script = $dom->getElementsByTagName('script');
            foreach ($script as $js){
                $src = $js->getAttribute('src');
                $ch = curl_init($src);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
                $raw = curl_exec($ch);
                curl_close($ch);
                $this->combined_js .= $raw . ';';
            }

            //3. add lazyload js .... needs jquery being imported in externals or locals before ...
            //TODO: add logic to check if jquery is present, else import
            $this->combined_js .= Lazyload::LAZYLOADJS;


            //4. find all inline js and collect
            $dom = new \DOMDocument();
            @$dom->loadHTML($buffer);
            $script = $dom->getElementsByTagName('script');
            foreach ($script as $js){
                $js = $js->nodeValue;
                $js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
                $this->inline_js .= $js ;
            }

            //remove old script apperances
            $buffer = preg_replace( '#<script(.*?)>(.*?)</script>#is','',$buffer);

            if (file_exists($path)) {
                unlink($path);
            }
            $fp = fopen($path, 'x');
            if (fwrite($fp, $this->combined_js)) {
                fclose($fp);
            }


            //put all the JS on bottom
            $relative_path = $this->public_cache_dir . $cachedAndOptimizedName. '?v='.$this->js_version;
            $buffer .=  '<script src="'. $relative_path . '"></script>';
            $buffer .= '<script>' .$this->inline_js .'</script>';
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML($buffer);
        $style = $dom->getElementsByTagName('style');
        foreach ($style as $s){
            $s = $s->nodeValue;
            $s = preg_replace('/<!--(.*)-->/Uis', '$1', $s);
            $this->inline_style .= $s ;
        }


        //remove old style apperances
        $buffer = preg_replace( '#<style(.*?)>(.*?)</style>#is','',$buffer);



        //insert inline css in head
        $buffer_exloded = explode('<head>',$buffer,2);
        $buffer_exloded[1] = $buffer_exloded[1]??'';
        $buffer = $buffer_exloded[0] . '<head><style>' .$this->inline_style .'</style>' . $buffer_exloded[1];


        // remove comments ...
        $buffer = preg_replace('/<!--(.*)-->/Uis', '', $buffer);

        //minify buffer
        $buffer = HtmlMinify::minify($buffer);

        //add extra
        $buffer .= $this->extra;


        $time_end = microtime(true);
        $execution_time = ($time_end - $time_start);

        $buffer .= '<!--optimized by HTMLOutputOptimizer. Optimizing process took: ' . $execution_time . ' seconds -->';
		$this->dispatchAsyncJobs();
        return $buffer;
    }

    private function dispatchAsyncJobs() {
		/** @var Parameters $redis_params */
		$redis_params = $this->cache->getConnection()->getParameters();
		$redis_host = $redis_params->host;
		$redis_db = $redis_params->database;
		$redis_port = $redis_params->port;
		$redis_pass = $redis_params->password;
		$cmd = 'php ' . __DIR__ . '/Async_helper.php "' .
			$redis_host . '" "' .
			$redis_port . '" "' .
			$redis_pass . '" "' .
			$redis_db . '" "' .
			$this->root_dir . '"';
		$this->executeAsyncShellCommand($cmd);
	}

    /**
     * MOVE EACH IMAGE WE FIND IN THE HTML IN A CACHE FOLDER AND OPTIMIZE IF POSSIBLE
     *
     * @param $source
     * @return string
     */
    private function optimizeAndCacheImages($source, $redis_pass, $redis_db)
    {

        return ImageOptimizer::optimizeAndCacheImages(
        	$source,
			$this->image_root_fs,
			$this->root_dir,
			$this->cache_dir,
			$this->skip_counter,
			$this->skip_x_lazy_images,
			$this->public_cache_dir,
			$this->use_b64_images,
			$this->cache
		);
		
    }
	
	/**
	 * Execute a command on host for asyncronity
	 *
	 * @param null $comando
	 * @throws Exception
	 */
	private function executeAsyncShellCommand($comando = null){
		if(!$comando){
			throw new \Exception("No command given");
		}
		@exec("/usr/bin/nohup ".$comando." > /dev/null 2>&1 &");
	}

 

 




}
