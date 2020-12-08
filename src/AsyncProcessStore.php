<?php


namespace dreadkopp\HTML_OutputOptimizer;


use Exceptions\MissingDependencyException;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Symfony\Component\Process\Process;

class AsyncProcessStore
{
	const CHUNKSIZE = 8;
	
	private static $_instance = null;
	private $client;
	private static $KEY = 'ASYNC_PROCESSES';
	private $running = [];
	
	
	public static function setCache(Client $client) {
		self::getInstance($client);
	}
	
	public static function getInstance(Client $client = null)
	{
		if (null === self::$_instance) {
			if (null === $client) {
				throw new \Exception('no redis cache given');
			}
			self::$_instance = new self($client);
		}
		return self::$_instance;
	}
	
	private function __construct(Client $client) {
		$this->client = $client;
	}
	
	public function clearStack() {
		$this->client->del(self::$KEY);
	}
	
	public function dispatchChunk() {
		$processes = $this->getProcesses()?? [];
		if (empty($processes)){
			return;
		}
		$chunk = array_chunk($processes,self::CHUNKSIZE);
		foreach ($chunk[0] as $process) {
			/** @var Process $process */
			$key = md5(json_encode($process->getCommandLine()));
			$process->start();
			$this->running[] = $process;
			unset($processes[$key]);
		}
		$this->client->set(self::$KEY,serialize($processes));
		while ($this->stillRunning()) {
			usleep(50000);
		}
	}
	
	public function startStack() {
		foreach ($this->getProcesses() as $process) {
			$process->start();
			$this->running[] = $process;
		}
		$this->client->del(self::$KEY);
	}
	
	public function addProcess(Process $process) {
		$processes = $this->getProcesses()?? [];
		$processes[md5(json_encode($process->getCommandLine()))] = $process;
		$this->client->set(self::$KEY,serialize($processes));
	}
	
	public function getProcesses() {
		return unserialize($this->client->get(self::$KEY))?? [];
	}
	
	public function stillRunning() {
		
		foreach ($this->running as $i => $process) {
			/** @var Process $process */
			if (!$process->isRunning()) {
				unset($this->running[$i]);
			}
		}
		return (bool)count($this->running);
	}
	
}
