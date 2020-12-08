<?php


namespace dreadkopp\HTML_OutputOptimizer;


use Exceptions\MissingDependencyException;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Symfony\Component\Process\Process;

class AsyncProcessStore
{
	private static $_instance = null;
	private $client;
	private static $KEY = 'ASYNC_PROCESSES';
	
	
	public static function setCache(Client $client) {
		self::getInstance($client);
	}
	
	public static function getInstance(Client $client = null)
	{
		if (null === self::$_instance) {
			if (null === $client) {
				throw new ConnectionException('no redis cache given');
			}
			self::$_instance = new self($client);
		}
		
		return self::$_instance;
	}
	
	private function __construct(Client $client) {
		$this->client = $client;
	}
	
	public function addProcess(Process $process) {
		$processes = $this->client->get(self::$KEY)?? [];
		$processes[] = $process;
		$this->client->set(self::$KEY,$processes);
	}
	
	public function getRunningProcesses() {
		return $this->client->get(self::$KEY)?? [];
	}
	
	public function stillRunning() {
		$running_procs = $this->getRunningProcesses();
		foreach ($running_procs as $i => $process) {
			/** @var Process $process */
			if (!$process->isRunning()) {
				unset($running_procs[$i]);
			}
		}
		$this->client->set(self::$KEY,$running_procs);
		return (bool)count($running_procs);
	}

}
