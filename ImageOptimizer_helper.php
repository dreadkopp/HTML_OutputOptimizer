<?php


$source = $argv[1];
$path = $argv[2];
$name = $argv[3];
$root = $argv[4];
$redis_pass = $argv[5];
$redis_db = $argv[6];
$cachetime = $argv[7];
$redis_host = array_key_exists(8, $argv) ? $argv[8] : null;
$redis_port = array_key_exists(9, $argv) ? $argv[9] : null;

require_once($root . 'vendor/dreadkopp/HTML_OutputOptimizer/ImageOptimizer.php');
require_once($root . 'vendor/autoload.php');

if (!$redis_host) {
    $redis_host = '127.0.0.1';
}

if (!$redis_port) {
    $redis_port = 6379;
}

$cache = new Predis\Client(
    [
        'scheme' => 'tcp',
        'host' => $redis_host,
        'port' => $redis_port,
        'database' => $redis_db,
        'password' => $redis_pass,
    ]
);


new vendor\dreadkopp\HTML_OutputOptimizer\ImageOptimizer($source, $path, $name, $cache, $cachetime, $root);