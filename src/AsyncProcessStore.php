<?php


namespace dreadkopp\HTML_OutputOptimizer;


use Exceptions\MissingDependencyException;
use Symfony\Component\Process\Process;

class AsyncProcessStore
{
	private static $_instance = null;
	
	private $runningProcesses = [];
	
	public static function getInstance()
	{
		if (null === self::$_instance) {
			self::$_instance = new self();
		}
		
		return self::$_instance;
	}
	
	private function __construct() {
	}
	
	public function addProcess(Process $process) {
		$this->runningProcesses[] = $process;
	}
	
	public function getRunningProcesses() {
		return $this->runningProcesses;
	}
	
	public function stillRunning() {
		foreach ($this->runningProcesses as $i => $process) {
			/** @var Process $process */
			if (!$process->isRunning()) {
				unset($this->runningProcesses[$i]);
			}
		}
		return (bool)count($this->runningProcesses);
	}

}
