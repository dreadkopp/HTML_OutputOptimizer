<?php


namespace dreadkopp\HTML_OutputOptimizer;


use Exception;
use Exceptions\MissingDependencyException;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Symfony\Component\Process\Process;

class AsyncProcessStore
{
	const CHUNKSIZE = 8;
	
	private static $_instance = null;
	private static $PREFIX = 'ASYNC_PROCESSES:';
	private $client;
	private $running = [];
	
	private function __construct(Client $client)
	{
		$this->client = $client;
	}
	
	public static function setCache(Client $client)
	{
		self::getInstance($client);
	}
	
	public static function getInstance(Client $client = null)
	{
		if (null === self::$_instance) {
			if (null === $client) {
				throw new Exception('no redis cache given');
			}
			self::$_instance = new self($client);
		}
		return self::$_instance;
	}
	
	public function dispatchChunk()
	{
		$processes = $this->getProcesses() ?? [];
		if (empty($processes)) {
			return;
		}
		$chunk = array_chunk($processes, self::CHUNKSIZE);
		foreach ($chunk[0] as $process) {
			/** @var Process $process */
			$key = md5(json_encode($process->getCommandLine()));
			$process->start();
			$this->running[] = $process;
			$this->client->del(self::$PREFIX . $key);
		}
		while ($this->stillRunning()) {
			usleep(50000);
		}
	}
	
	public function getProcesses()
	{
		$keys = $this->client->keys(self::$PREFIX . '*');
		$processes = [];
		foreach ($keys as $key) {
			$processes[] = unserialize($this->client->get($key), ['allowed_classes' => [Process::class]]);
		}
		return $processes;
	}
	
	public function stillRunning()
	{
		
		foreach ($this->running as $i => $process) {
			/** @var Process $process */
			if (!$process->isRunning()) {
				unset($this->running[$i]);
			}
		}
		return (bool)count($this->running);
	}
	
	public function startStack()
	{
		$processes = $this->getProcesses();
		foreach ($processes as $process) {
			$process->start();
			$this->running[] = $process;
		}
		$this->clearStack();
	}
	
	public function clearStack()
	{
		$keys = $this->client->keys(self::$PREFIX . '*');
		$this->client->del($keys);
	}
	
	public function addProcess(Process $process)
	{
		if ($process->isStarted()) {
			throw new Exception('only processes that haven\'t been started yet can be added');
		}
		$this->client->set(self::$PREFIX . md5(json_encode($process->getCommandLine())), serialize($process));
	}
	
}
