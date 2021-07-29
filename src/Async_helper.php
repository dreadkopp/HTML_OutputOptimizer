<?php

use dreadkopp\HTML_OutputOptimizer\AsyncProcessStore;

$root = $argv[1] . '/';


require_once($root . 'vendor/autoload.php');


/** @var AsyncProcessStore $store */
$store = AsyncProcessStore::getInstance();
$store->dispatchChunk();
