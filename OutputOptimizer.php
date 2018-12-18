<?php


namespace vendor\dreadkopp\HTML_OutputOptimizer;


class OutputOptimizer
{



    private $cache = null;
    private $redis_pass = null;
    private $redis_db = null;
    private $combined_js = '';
    private $localjs = [];
    private $root_dir = '';
    private $cache_dir = '';
    private $redis_host = '';
    private $redis_port = '';

    const USE_B64_ENCODED_IMAGES = true;
    const CACHETIME = 3600;
    const ADD_LOCAL_JS = true;



    const LAZYLOADJS = ' //Lazy loading, refine to check / show per element
    $(window).on("resize scroll load", function () {
        var images = $("img[data-src]").not(\'.owl-lazy\');
        if (images) {
            images.each(function (el, img) {
                if ($(this).optimisticIsInViewport()) {
                    img.setAttribute("src", img.getAttribute("data-src"));
                    img.onload = function () {
                        img.removeAttribute("data-src");
                    };
                }
            });
        }
        var images_back = $("img[data-background]");
        if (images_back) {
            images_back.each(function (el, img) {
                if ($(this).optimisticIsInViewport()) {
                    $(img).css("background-image", \'url(\' + img.getAttribute("data-background") + \')\');
                    img.onload = function () {
                        img.removeAttribute("data-background");
                    };
                }
            });
        }
    });

    //Lazy loading iframes (google maps mostly), refine to check / show per element
    $(window).on("resize scroll load", function () {
        var iframes = $("iframe[data-src]");
        if (iframes) {
            iframes.each(function (el, iframe) {
                if ($(iframe).optimisticIsInViewport()) {
                    iframe.setAttribute("src", iframe.getAttribute("data-src"));
                    iframe.onload = function () {
                        iframe.removeAttribute("data-src");
                    };
                }
            });
        }
    });

    $.fn.isInViewport = function () {
        if (typeof $(this).offset() !== "undefined") {
            var elementTop = $(this).offset().top;
            var elementBottom = elementTop + $(this).outerHeight();

            var viewportTop = $(window).scrollTop();
            var viewportBottom = viewportTop + window.innerHeight;

            return elementBottom > viewportTop && elementTop < viewportBottom;
        }
    };

    $.fn.optimisticIsInViewport = function () {
        if (typeof $(this).offset() !== "undefined") {
            var elementTop = $(this).offset().top;
            var elementBottom = elementTop + $(this).outerHeight();

            var viewportTop = $(window).scrollTop();
            var viewportBottom = viewportTop + 2 * window.innerHeight;

            return elementBottom > viewportTop && elementTop < viewportBottom;
        }
    };';



    /**
     * OutputOptimizer constructor.
     * @param Predis\Client $cache
     */
    public function __construct(\Predis\Client $cache, $root_dir, $cache_dir )
    {
        $this->cache = $cache;
        $redis_params = $cache->getConnection()->getParameters()->toArray();
        $this->redis_pass = $redis_params['password'];
        $this->redis_db = $redis_params['database'];
        $this->redis_host = redis_params['host'];
        $this->redis_port = $redis_params['port'];
        $this->root_dir =  $root_dir;
        $this->cache_dir = $cache_dir;
    }

    public function addLocalJSPath ($path) {
        array_push($this->localjs, $path);
    }

    /**
     * MINIFIES HTML AND CACHES+OPTIMIZES IMAGES
     *
     * @param $buffer
     * @return null|string|string[]
     */
    public function sanitize_output($buffer)
    {
        //TODO: replace all <img .... src=" .... " ... /> with <img .... data-src=" .... " ... />
        $search = array(
            '/\>[^\S ]+/s',  // strip whitespaces after tags, except space
            '/[^\S ]+\</s',  // strip whitespaces before tags, except space
            '/(\s)+/s',       // shorten multiple whitespace sequences
            '/<!DOCTYPE html>/', //initial DOCTYPE which would be minified as well... no worries, we add it later

        );
        $replace = array(
            '>',
            '<',
            '\\1',
            '',

        );

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

            $js = file_get_contents($path);

            //remove old script apperances
            $buffer = preg_replace( '#<script(.*?)>(.*?)</script>#is','',$buffer);

            //put all the JS on bottom
            $relative_path = str_replace($this->root_dir , '', $path);
            $buffer =  $buffer . '<script async src="'. $relative_path . '"></script>';

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
            $searchinlineJS = '/<script\\b[^>]* src\s*=\s*"(.+?)">*<\\/script>/';
            $buffer = preg_replace_callback($searchinlineJS, function($matches){
                return $this->cacheExternalJS($matches, $this->redis_pass, $this->redis_db);
            }, $buffer);


            //3. add lazyload js .... needs jquery being imported in externals or locals before ...
            //TODO: add logic to check if jquery is present, else import
            $this->combined_js .= self::LAZYLOADJS;


            //4. find all inline js and collect
            $dom = new \DOMDocument();
            @$dom->loadHTML($buffer);
            $script = $dom->getElementsByTagName('script');
            foreach ($script as $js){
                $js = $js->nodeValue;
                $js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
                $this->combined_js .= $js ;
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
            $relative_path = str_replace($this->root_dir , '', $path);
            $buffer =  $buffer . '<script async src="'. $relative_path . '"></script>';
        }

        //minify buffer
        $buffer = preg_replace($search, $replace, $buffer);

        // remove comments ...
        $buffer = preg_replace('/<!--(.*)-->/Uis', '', $buffer);
        
        return $buffer;
    }

    private function cacheExternalJS($source) {

        $src = explode('"', $source[1]);
        $src = $src[0];

        $ch = curl_init($src);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
        $raw = curl_exec($ch);
        curl_close($ch);
        $this->combined_js .= $raw . ';';

    }

    /**
     * MOVE EACH IMAGE WE FIND IN THE HTML IN A CACHE FOLDER AND OPTIMIZE IF POSSIBLE
     *
     * @param $source
     * @return string
     */
    private function optimizeAndCacheImages($source, $redis_pass, $redis_db)
    {

        $returnstring = 'data-src="' . $source[1] . '"';

        if ((strpos($source[1], 'cache') !== false)) {
            return $returnstring;
        }

        $tmp = explode('.', $source[1]);
        $filetype = end($tmp);
        $filename = hash('md5', $source[1]);
        $cachepath = $this->root_dir . $this->cache_dir;
        if (!file_exists($cachepath)) {
            mkdir($cachepath, 0770, true);
        }
        $cachedAndOptimizedName = $filename . '.' . $filetype;
        $path = $cachepath . $cachedAndOptimizedName;

        //if cached file exists and is not older than expire time, else create/update image in cache and update b64
        if (file_exists($path) && (time()-filemtime($path) < self::CACHETIME - 10)) {

            if( strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false ) {
                $cachedAndOptimizedName = $cachedAndOptimizedName . '.webp';
            }

            $base64data = $this->getBase64Image($cachedAndOptimizedName);
            if (self::USE_B64_ENCODED_IMAGES) {
                $returnstring = ' src="' . $base64data . '"' .' data-src="' . $this->cache_dir . $cachedAndOptimizedName . '"';
            } else {
                $returnstring = ' data-src="' . $this->cache_dir . $cachedAndOptimizedName . '"';
            }

        } else {
            
            if (file_exists($path)){
                unlink($path);
                unlink($path. '.webp');
            }
            
            $cmd = 'php ' . __DIR__ . '/ImageOptimizer_helper.php ' . $source[1] . ' ' . $path . ' ' . $cachedAndOptimizedName . ' ' . $this->root_dir . ' ' . $redis_pass . ' ' . $redis_db . ' ' . self::CACHETIME . ' ' . $this->redis_host. ' ' . $this->redis_port;
            //  for DBG
            //require_once ($this->root_dir . 'vendor/dreadkopp/HTML_OutputOptimizer/ImageOptimizer.php');
            //new ImageOptimizer($source[1], $path, $cachedAndOptimizedName, $this->cache, self::CACHETIME, $this->root_dir);
            $this->executeAsyncShellCommand($cmd);
        }

        return $returnstring;

    }

    /**
     * Execute a command on host for asyncronity
     *
     * @param null $comando
     * @throws Exception
     */
    private function executeAsyncShellCommand($comando = null){
        if(!$comando){
            throw new Exception("No command given");
        }
        @exec("/usr/bin/nohup ".$comando." > /dev/null 2>&1 &");
    }


    /**
     * Converts an image to base64 and puts that info in redis cache
     *
     * @param $file
     * @param $cachedFileName
     * @return string
     */
    private function getBase64Image($cachedFileName) {

            $cachekey = str_replace(".","",$cachedFileName);
            /** @var $this->>cache Predis\Client */
            $cacheddata = $this->cache->get($cachekey);
            if ($cacheddata) {
                return $cacheddata;
            } else {
                return '';
            }

    }




}
