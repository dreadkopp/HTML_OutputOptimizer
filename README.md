
# HTML_OutputOptimizer
Tool to rearrange you JS, cache and optimize your Images and minify your whole HTML output


# Required:

Redis Cache
Webp
PHP-Imagick
PHP-curl
PHP >=7
nohub

# What it does:

* gathers all your external JS as well as inline JS and adds a combined js file per page in the cache_dir
* optimizes your Images which are tagged with 'data-src' instead of 'src'
  - puts all images in cache_dir
  - resizes, optimizes and caches all images in cache_dir
  - creates .webp variants of those images which will be served if browser supports it
  - image optimizations happens in a disattached process, first encounter of the page will serve the unoptimized images
  
* minifies the whole output HTML

Using these optimizations Pagesize and Loadtime (as well as Pagespeedscore for Google) can be drastically reduced


# Installation:

add to your composer.json repositories:

"repositories": [
 ...
{
"type": "git",
"url" : "https://github.com/dreadkopp/HTML_OutputOptimizer.git"
}
...
]

then
```composer require dreadkopp/html_outputoptimizer```


# Usage:


### create a RedisCache Client (or use a existing one)
$cache = new Predis\Client(
    [
        'scheme'   => 'tcp',
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'database' => <$redis_db>,
        'password' => <$redis_pass>,
    ]
);

### create a Instance of the Optimizer

$optimizer = new OutputOptimizer($cache, <root_dir>, <cache_dir>, <?public_cache_dir>, <?public_image_dir>, <?use_base64_images>, <?skip_first_x_images> );

### (optional) add local JS files
$optimizer->addLocalJSPath(<path_to_local_js_file>);


### use $optimizer in Outputbuffer or your Template Compiler for example in output buffer
ob_start(array($optimizer, 'sanitize_output'));

### (optional) Extend handling of async Jobs
for image optimization OutputOptimizer dispatches async jobs to re-render the images in background

if you got a asynchronous worker for that (i.e via supervisord), you might want to extend the OutputOptimizer::dispatchAsyncJobs() to do nothing
and have a worker instance that uses AsyncProcessStore::startStack() or ArrayProcessStore::dispatchChunk()


# WHY?

* to optimize Images which are added dynamically (for example in a webshop) or those that are served from a external source without optimization

* to reduce requests by combining all JS into one

* to re-arrange your JS to bottom if you need to use a template system without the possibility to define sections

* to reduce your page size overall.
