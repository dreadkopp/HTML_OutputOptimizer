
# HTML_OutputOptimizer
Tool to rearrange you JS, cache and optimize your Images and minify your whole HTML output


Required:

Redis Cache
Webp
PHP-Imagick
PHP-curl
PHP >=5.6
ImageOtimizer "ps/image-optimizer"
nohub

What it does:

* gathers all your external JS as well as inline JS and adds a combined js file per page in the cache_dir
* optimizes your Images which are tagged with 'data-src' instead of 'src'
  - puts all images in cache_dir
  - resizes, optimizes and caches all images in cache_dir
  - creates .webp variants of those images which will be served if browser supports it
  - image optimizations happens in a disattached process, first encounter of the page will serve the unoptimized images
  
* minifies the whole output HTML

Using these optimizations Pagesize and Loadtime (as well as Pagespeedscore for Google) can be drastically reduced


Installation:

... to come composer package will come in the future


Usage:

//if you run this tool on the whole HTML output, echo DOCTYPE first since it will be stripped otherwise
echo '<!DOCTYPE html>' . PHP_EOL;


//create a RedisCache Client
$cache = new Predis\Client(
    [
        'scheme'   => 'tcp',
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'database' => <$redis_db>,
        'password' => <$redis_pass>,
    ]
);

//create a Instance of the Optimizer

$optimizer = new OutputOptimizer($cache, <root_dir>, <cache_dir>, <redis_pass>, <redis_db>, <redis_host>, <redis_port>);
//optimal add local JS files
$optimizer->addLocalJSPath(<path_to_local_js_file);


//use $optimizer in Outputbuffer or your Template Compiler
$optimizer->sanitize_output(<output_html>);



WHY?

* to optimize Images which are added dynamically (for example in a webshop) or those that are served from a external source without optimization

* to reduce requests by combining all JS into one

* to re-arrange your JS to bottom if you need to use a template system without the possibility to define sections

* to reduce your page size overall.
