<?php namespace GO;

use DateTime;
use Exception;
use InvalidArgumentException;
use Throwable;

class Scheduler
{
    /**
     * The queued jobs.
     *
     * @var Job[]
     */
    private array $jobs = [];

    /**
     * Successfully executed jobs.
     *
     * @var Job[]
     */
    private array $executedJobs = [];

    /**
     * Failed jobs.
     *
     * @var FailedJob[]
     */
    private array $failedJobs = [];

    /**
     * The verbose output of the scheduled jobs.
     *
     * @var string[]
     */
    private array $outputSchedule = [];

    /**
     * @var array
     */
    private array $config;

    /**
     * Create new instance.
     *
     * @param  array  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Queue a job for execution in the correct queue.
     *
     * @param  Job  $job
     * @return void
     */
    private function queueJob(Job $job)
    {
        $this->jobs[] = $job;
    }

    /**
     * Prioritise jobs in background.
     *
     * @return Job[]
     */
    private function prioritiseJobs(): array
    {
        $background = [];
        $foreground = [];

        foreach ($this->jobs as $job) {
            if ($job->canRunInBackground()) {
                $background[] = $job;
            } else {
                $foreground[] = $job;
            }
        }

        return array_merge($background, $foreground);
    }

    /**
     * Get the queued jobs.
     *
     * @return Job[]
     */
    public function getQueuedJobs(): array
    {
        return $this->prioritiseJobs();
    }

    /**
     * Queues a function execution.
     *
     * @param callable    $fn   The function to execute
     * @param array       $args Optional arguments to pass to the php script
     * @param string|null $id   Optional custom identifier
     * @return Job
     */
    public function call(callable $fn, array $args = [], string $id = null): Job
    {
        $job = new Job($fn, $args, $id);

        $this->queueJob($job->configure($this->config));

        return $job;
    }

    /**
     * Queues a php script execution.
     *
     * @param string      $script The path to the php script to execute
     * @param string|null $bin    Optional path to the php binary
     * @param array       $args   Optional arguments to pass to the php script
     * @param string|null $id     Optional custom identifier
     * @return Job
     */
    public function php(string $script, string $bin = null, array $args = [], string $id = null): Job
    {
        $bin = is_string($bin) && file_exists($bin) ?
            $bin : (PHP_BINARY === '' ? '/usr/bin/php' : PHP_BINARY);

        $job = new Job($bin . ' ' . $script, $args, $id);

        if (!file_exists($script)) {
            $this->pushFailedJob(
                $job,
                new InvalidArgumentException('The script should be a valid path to a file.')
            );
        }

        $this->queueJob($job->configure($this->config));

        return $job;
    }

    /**
     * Queue a raw shell command.
     *
     * @param string      $command The command to execute
     * @param array       $args    Optional arguments to pass to the command
     * @param string|null $id      Optional custom identifier
     * @return Job
     */
    public function raw(string $command, array $args = [], string $id = null): Job
    {
        $job = new Job($command, $args, $id);

        $this->queueJob($job->configure($this->config));

        return $job;
    }

    /**
     * Run the scheduler.
     *
     * @param DateTime|null $runTime Optional, run at specific moment
     * @return Job[]  Executed jobs
     */
    public function run(Datetime $runTime = null): array
    {
        $jobs = $this->getQueuedJobs();

        if (is_null($runTime)) {
            $runTime = new DateTime('now');
        }

        foreach ($jobs as $job) {
            if ($job->isDue($runTime)) {
                try {
                    $job->run();
                    $this->pushExecutedJob($job);
                } catch (Throwable $e) {
                    $this->pushFailedJob($job, $e);
                }
            }
        }

        return $this->getExecutedJobs();
    }

    /**
     * Reset all collected data of last run.
     *
     * Call before run() if you call run() multiple times.
     */
    public function resetRun(): Scheduler
    {
        // Reset collected data of last run
        $this->executedJobs = [];
        $this->failedJobs = [];
        $this->outputSchedule = [];

        return $this;
    }

    /**
     * Add an entry to the scheduler verbose output array.
     *
     * @param string $string
     * @return void
     */
    private function addSchedulerVerboseOutput(string $string)
    {
        $now = '[' . (new DateTime('now'))->format('c') . '] ';
        $this->outputSchedule[] = $now . $string;

        // Print to stdoutput in light gray
        // echo "\033[37m{$string}\033[0m\n";
    }

    /**
     * Push a successfully executed job.
     *
     * @param  Job  $job
     * @return void
     */
    private function pushExecutedJob(Job $job): void
    {
        $this->executedJobs[] = $job;

        $compiled = $job->compile();

        // If callable, log the string Closure
        if (is_callable($compiled)) {
            $compiled = 'Closure';
        }

        $this->addSchedulerVerboseOutput("Executing $compiled");
    }

    /**
     * Get the executed jobs.
     *
     * @return array
     */
    public function getExecutedJobs(): array
    {
        return $this->executedJobs;
    }

    /**
     * Push a failed job.
     *
     * @param  Job  $job
     * @param  Exception  $e
     * @return void
     */
    private function pushFailedJob(Job $job, Exception $e): void
    {
        $this->failedJobs[] = new FailedJob($job, $e);

        $compiled = $job->compile();

        // If callable, log the string Closure
        if (is_callable($compiled)) {
            $compiled = 'Closure';
        }

        $this->addSchedulerVerboseOutput("{$e->getMessage()}: $compiled");
    }

    /**
     * Get the failed jobs.
     *
     * @return FailedJob[]
     */
    public function getFailedJobs(): array
    {
        return $this->failedJobs;
    }

    /**
     * Get the scheduler verbose output.
     *
     * @param string $type Allowed: text, html, array
     * @return string[]|string  The return depends on the requested $type
     */
    public function getVerboseOutput(string $type = 'text')
    {
        switch ($type) {
            case 'text':
                return implode("\n", $this->outputSchedule);
            case 'html':
                return implode('<br>', $this->outputSchedule);
            case 'array':
                return $this->outputSchedule;
            default:
                throw new InvalidArgumentException('Invalid output type');
        }
    }

    /**
     * Remove all queued Jobs.
     */
    public function clearJobs(): Scheduler
    {
        $this->jobs = [];

        return $this;
    }

    /**
     * Start a worker.
     *
     * @param array $seconds - When the scheduler should run
     */
    public function work(array $seconds = [0])
    {
        while (true) {
            if (in_array((int) date('s'), $seconds)) {
                $this->run();
                sleep(1);
            }
        }
    }
}
