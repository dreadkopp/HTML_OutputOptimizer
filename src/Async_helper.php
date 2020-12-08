<?php

$redis_host = $argv[1];
$redis_port = $argv[2];
$redis_pass = $argv[3];
$redis_db = $argv[4];
$root = $argv[5];


require_once($root . 'vendor/autoload.php');



$cache = new Predis\Client(
	[
		'scheme' => 'tcp',
		'host' => $redis_host,
		'port' => $redis_port,
		'database' => $redis_db,
		'password' => $redis_pass,
	]
);
$store = \dreadkopp\HTML_OutputOptimizer\AsyncProcessStore::getInstance($cache);
$store->startStack();
while($store->stillRunning()) {
	
	dump('still processes running');
	dump($store->getRunningProcesses());
	sleep(.5);
}
