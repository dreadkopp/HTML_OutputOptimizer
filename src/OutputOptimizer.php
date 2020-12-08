<?php


namespace dreadkopp\HTML_OutputOptimizer;

use dreadkopp\HTML_OutputOptimizer\Handler\CssMinify;
use dreadkopp\HTML_OutputOptimizer\Handler\HtmlMinify;
use dreadkopp\HTML_OutputOptimizer\Handler\ImageOptimizer;
use dreadkopp\HTML_OutputOptimizer\Handler\JSMinify;
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
                return  ImageOptimizer::optimizeAndCacheImages(
					$matches,
					$this->image_root_fs,
					$this->root_dir,
					$this->cache_dir,
					$this->skip_counter,
					$this->skip_x_lazy_images,
					$this->public_cache_dir,
					$this->use_b64_images,
					$this->cache
				);
        }, $buffer);

		//minify and Cache JS
       $buffer = JSMinify::minify($buffer,$this->root_dir,$this->cache_dir,$this->inline_js,$this->public_cache_dir,$this->js_version,$this->localjs);

        //re-order CSS
		$buffer = CssMinify::minify($buffer);
		
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

    protected function dispatchAsyncJobs() {
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
