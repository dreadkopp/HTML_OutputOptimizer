<?php


namespace dreadkopp\HTML_OutputOptimizer;


use Exception;
use Exceptions\MissingDependencyException;
use Symfony\Component\Process\Process;

class AsyncProcessStore
{
    const CHUNKSIZE = 8;
    
    private static $_instance      = null;
    private        $running        = [];
    private        $queue_location = '/tmp/hoo_async_queue.queue';
    
    protected function __construct(string $queue_location = null)
    {
        if ($queue_location) {
            $this->queue_location = $queue_location;
        }
        
        if (!file_exists($this->queue_location)) {
            file_put_contents($this->queue_location,'');
        }
    }
    
    
    public static function getInstance(string $queue_location = null)
    {
        if (null === self::$_instance) {
            self::$_instance = new self($queue_location);
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
            $this->removeProcessFromQueue($key);
        }
        while ($this->stillRunning()) {
            usleep(50000);
        }
    }
    
    public function getProcesses()
    {
        $queue = json_decode(file_get_contents($this->queue_location), true);
        $processes = [];
        foreach ($queue as $serialized) {
            $processes[] = unserialize($serialized, ['allowed_classes' => [Process::class]]);
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
        file_put_contents($this->queue_location, '');
    }
    
    public function addProcess(Process $process)
    {
        if ($process->isStarted()) {
            throw new Exception('only processes that haven\'t been started yet can be added');
        }
        
        $queue = json_decode(file_get_contents($this->queue_location), true);
        $queue[md5(json_encode($process->getCommandLine()))] = serialize($process);
        file_put_contents($this->queue_location, json_encode($queue));
    }
    
    public function removeProcessFromQueue(string $key) {
        $queue = json_decode(file_get_contents($this->queue_location), true);
        unset($queue[$key]);
        file_put_contents($this->queue_location, json_encode($queue));
    }
    
}
