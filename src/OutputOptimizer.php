<?php


namespace dreadkopp\HTML_OutputOptimizer;

use dreadkopp\HTML_OutputOptimizer\Handler\CssMinify;
use dreadkopp\HTML_OutputOptimizer\Handler\HtmlMinify;
use dreadkopp\HTML_OutputOptimizer\Handler\ImageOptimizer;
use dreadkopp\HTML_OutputOptimizer\Handler\JSMinify;
use Exception;
use Predis\Client;
use Predis\Connection\Parameters;


class OutputOptimizer
{
    
    
    const CACHETIME              = 3600;
    const LOAD_THRESHOLD_PERCENT = 60;
    private $inline_js          = '';
    private $localjs            = [];
    private $root_dir           = '';
    private $image_root_fs      = '';
    private $cache_dir          = '';
    private $public_cache_dir   = '';
    private $extra              = '';
    private $skip_x_lazy_images = 0;
    private $skip_counter       = 0;
    private $js_version         = '1';
    
    /**
     * OutputOptimizer constructor.
     * @param Client         $cache
     * @param                $root_dir
     * @param                $cache_dir
     * @param string         $public_cache_dir
     */
    public function __construct($root_dir, $cache_dir, $public_cache_dir = '', $image_root_fs = '', $skip_x_lazy_images = 0)
    {
        $this->skip_x_lazy_images = $skip_x_lazy_images;
        $this->root_dir = $root_dir;
        $this->cache_dir = $cache_dir;
        $this->public_cache_dir = $public_cache_dir ?: $cache_dir;
        $this->image_root_fs = $image_root_fs ?: $root_dir;
    }
    
    /**
     * Add extra strings to add to bottom of output
     * @param $extra
     */
    public function setExtra($extra)
    {
        $this->extra = $extra;
    }
    
    public function setJSVersion($tag)
    {
        $this->js_version = $tag;
    }
    
    public function addLocalJSPath($path)
    {
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
        $buffer = preg_replace_callback($searchimage, function ($matches) {
            return ImageOptimizer::optimizeAndCacheImages(
                $matches,
                $this->image_root_fs,
                $this->root_dir,
                $this->cache_dir,
                $this->skip_counter,
                $this->skip_x_lazy_images,
                $this->public_cache_dir,
			);
        }, $buffer
        );
        
        //minify and Cache JS
        $buffer = JSMinify::minify($buffer, $this->root_dir, $this->cache_dir, $this->inline_js, $this->public_cache_dir, $this->js_version, $this->localjs);
        
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
    
    protected function dispatchAsyncJobs()
    {
        /** @var Parameters $redis_params */
        $cmd = 'php ' . __DIR__ . '/Async_helper.php "' .
            $this->root_dir . '"';
        $this->executeAsyncShellCommand($cmd);
    }
    
    /**
     * Execute a command on host for asyncronity
     *
     * @param null $comando
     * @throws Exception
     */
    private function executeAsyncShellCommand($comando = null)
    {
        if (!$comando) {
            throw new Exception("No command given");
        }
        @exec("/usr/bin/nohup " . $comando . " > /dev/null 2>&1 &");
    }
    
    
}
