<?php


$source = $argv[1];
$path = $argv[2];
$name = $argv[3];
$root = $argv[4].'/';
$image_root_fs = $argv[5];

require_once($root . 'vendor/autoload.php');



new dreadkopp\HTML_OutputOptimizer\Handler\ImageOptimizer(
	$source,
	$path,
	$name,
	$root,
	$image_root_fs
);
