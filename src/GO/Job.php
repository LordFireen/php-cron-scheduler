<?php namespace GO;

use Cron\CronExpression;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

class Job
{
    use Traits\Interval;
    use Traits\Mailer;

    /**
     * Job schedule time.
     *
     * @var CronExpression
     */
    protected $executionTime;

    /**
     * Job schedule year.
     *
     * @var string
     */
    protected $executionYear = null;

    /**
     * Job identifier.
     *
     * @var string
     */
    private string $id;

    /**
     * Command to execute.
     *
     * @var mixed
     */
    private $command;

    /**
     * Arguments to be passed to the command.
     *
     * @var array
     */
    private array $args;

    /**
     * Defines if the job should run in background.
     *
     * @var bool
     */
    private bool $runInBackground = true;

    /**
     * Creation time.
     *
     * @var DateTime
     */
    private DateTime $creationTime;

    /**
     * Time when the job was ordered to perform.
     *
     * @var DateTimeInterface
     */
    private DateTimeInterface $runTime;

    /**
     * Real time when the job started to run.
     *
     * @var DateTime
     */
    private DateTime $realRunTime;

    /**
     * Time, when job was finished or failed.
     *
     * @var DateTime
     */
    private DateTime $finishTime;

    /**
     * Temporary directory path for
     * lock files to prevent overlapping.
     *
     * @var string
     */
    private string $tempDir;

    /**
     * Path to the lock file.
     *
     * @var string
     */
    private string $lockFile = '';

    /**
     * This could prevent the job to run.
     * If true, the job will run (if due).
     *
     * @var bool
     */
    private bool $truthTest = true;

    /**
     * The output of the executed job.
     *
     * @var mixed
     */
    private $output;

    /**
     * The return code of the executed job.
     *
     * @var int
     */
    private int $returnCode = 0;

    /**
     * Files to write the output of the job.
     *
     * @var array
     */
    private array $outputTo = [];

    /**
     * Email addresses where the output should be sent to.
     *
     * @var array
     */
    private array $emailTo = [];

    /**
     * Configuration for email sending.
     *
     * @var array
     */
    private array $emailConfig = [];

    /**
     * A function to execute before the job is executed.
     *
     * @var callable
     */
    private $before;

    /**
     * A function to execute after the job is executed.
     *
     * @var callable
     */
    private $after;

    /**
     * A function to ignore an overlapping job.
     * If true, the job will run also if it's overlapping.
     *
     * @var callable
     */
    private $whenOverlapping;

    /**
     * @var string
     */
    private string $outputMode;

    /**
     * Create a new Job instance.
     *
     * @param string|callable $command
     * @param array           $args
     * @param string|null     $id
     */
    public function __construct($command, array $args = [], string $id = null)
    {
        if (is_string($id)) {
            $this->id = $id;
        } else {
            if (is_string($command)) {
                $this->id = md5($command);
            } elseif (is_array($command)) {
                $this->id = md5(serialize($command));
            } else {
                /* @var object $command */
                $this->id = spl_object_hash($command);
            }
        }

        $this->creationTime = new DateTime('now');

        // initialize the directory path for lock files
        $this->tempDir = sys_get_temp_dir();

        $this->command = $command;
        $this->args = $args;
    }

    /**
     * Get the Job id.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Check if the Job is due to run.
     * It accepts as input a DateTime used to check if
     * the job is due. Defaults to job creation time.
     * It also defaults the execution time if not previously defined.
     *
     * @param DateTimeInterface|null $date
     * @return bool
     */
    public function isDue(DateTimeInterface $date = null): bool
    {
        // The execution time is being defaulted if not defined
        if (! $this->executionTime) {
            $this->executionTime = self::getDefaultExpression();
        }

        $date = $date !== null ? $date : $this->creationTime;

        if ($this->executionYear && $this->executionYear !== $date->format('Y')) {
            return false;
        }

        return $this->executionTime->isDue($date);
    }

    /**
     * Check if the Job is overlapping.
     *
     * @return bool
     */
    public function isOverlapping(): bool
    {
        return $this->lockFile &&
               file_exists($this->lockFile) &&
               call_user_func($this->whenOverlapping, filemtime($this->lockFile)) === false;
    }

    /**
     * Force the Job to run in foreground.
     *
     * @return self
     */
    public function inForeground(): Job
    {
        $this->runInBackground = false;

        return $this;
    }

    /**
     * Check if the Job can run in background.
     *
     * @return bool
     */
    public function canRunInBackground(): bool
    {
        if (is_callable($this->command) || $this->runInBackground === false) {
            return false;
        }

        return true;
    }

    /**
     * This will prevent the Job from overlapping.
     * It prevents another instance of the same Job of
     * being executed if the previous is still running.
     * The job id is used as a filename for the lock file.
     *
     * @param string|null   $tempDir         The directory path for the lock files
     * @param callable|null $whenOverlapping A callback to ignore job overlapping
     * @return self
     */
    public function onlyOne(string $tempDir = null, callable $whenOverlapping = null): Job
    {
        if ($tempDir === null || ! is_dir($tempDir)) {
            $tempDir = $this->tempDir;
        }

        $this->lockFile = implode('/', [
            trim($tempDir),
            trim($this->id) . '.lock',
        ]);

        if ($whenOverlapping) {
            $this->whenOverlapping = $whenOverlapping;
        } else {
            $this->whenOverlapping = function () {
                return false;
            };
        }

        return $this;
    }

    /**
     * Compile the Job command.
     *
     * @return callable|object|string
     */
    public function compile()
    {
        $compiled = $this->command;

        // If callable, return the function itself
        if (is_callable($compiled)) {
            return $compiled;
        }

        // Augment with any supplied arguments
        foreach ($this->args as $key => $value) {
            $compiled .= ' ' . escapeshellarg($key);
            if ($value !== null) {
                $compiled .= ' ' . escapeshellarg($value);
            }
        }

        // Add the boilerplate to redirect the output to file/s
        if (count($this->outputTo) > 0) {
            $compiled .= ' | tee ';
            $compiled .= $this->outputMode === 'a' ? '-a ' : '';
            foreach ($this->outputTo as $file) {
                $compiled .= $file . ' ';
            }

            $compiled = trim($compiled);
        }

        // Add boilerplate to remove lockfile after execution
        if ($this->lockFile) {
            $compiled .= '; rm ' . $this->lockFile;
        }

        // Add boilerplate to run in background
        if ($this->canRunInBackground()) {
            // Parentheses are need execute the chain of commands in a subshell
            // that can then run in background
            $compiled = '(' . $compiled . ') > /dev/null 2>&1 &';
        }

        return trim($compiled);
    }

    /**
     * Configure the job.
     *
     * @param  array  $config
     * @return self
     */
    public function configure(array $config = []): Job
    {
        if (isset($config['email'])) {
            if (! is_array($config['email'])) {
                throw new InvalidArgumentException('Email configuration should be an array.');
            }
            $this->emailConfig = $config['email'];
        }

        // Check if config has defined a tempDir
        if (isset($config['tempDir']) && is_dir($config['tempDir'])) {
            $this->tempDir = $config['tempDir'];
        }

        return $this;
    }

    /**
     * Truth test to define if the job should run if due.
     *
     * @param  callable  $fn
     * @return self
     */
    public function when(callable $fn): Job
    {
        $this->truthTest = $fn();

        return $this;
    }

    /**
     * Run the job.
     *
     * @param DateTimeInterface|null $runTime Time when job run was run.
     *
     * @throws Throwable
     * @return bool
     */
    public function run(DateTimeInterface $runTime = null): bool
    {
        // Run time will be added when we are sure that we need to run.
        $runTime = $runTime ?: new DateTime('now');

        // If the truthTest failed, don't run
        if ($this->truthTest !== true) {
            return false;
        }

        // If overlapping, don't run
        if ($this->isOverlapping()) {
            return false;
        }
        $this->runTime = $runTime;
        $compiled = $this->compile();

        // Write lock file if necessary
        $this->createLockFile();

        try {
            if (is_callable($this->before)) {
                call_user_func($this->before);
            }
        } catch (Throwable $e) {
            $this->removeLockFile();
            throw $e;
        }


        $this->realRunTime = new DateTime('now');
        try {
            if (is_callable($compiled)) {
                $this->output = $this->exec($compiled);
            } else {
                exec($compiled, $this->output, $this->returnCode);
            }
        } finally {
            $this->finishTime = new DateTime('now');
        }

        $this->finalise();

        return true;
    }

    /**
     * Create the job lock file.
     *
     * @param  mixed  $content
     * @return void
     */
    private function createLockFile($content = null)
    {
        if ($this->lockFile) {
            if (!is_string($content)) {
                $content = $this->getId();
            }

            file_put_contents($this->lockFile, $content);
        }
    }

    /**
     * Remove the job lock file.
     *
     * @return void
     */
    private function removeLockFile()
    {
        if ($this->lockFile && file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Execute a callable job.
     *
     * @param  callable  $fn
     * @throws Throwable
     * @return string
     */
    private function exec(callable $fn): string
    {
        ob_start();

        try {
            $returnData = call_user_func_array($fn, $this->args);
        } catch (Throwable $e) {
            ob_end_clean();
            $this->removeLockFile();
            throw $e;
        }

        $outputBuffer = ob_get_clean();

        foreach ($this->outputTo as $filename) {
            if ($outputBuffer) {
                file_put_contents($filename, $outputBuffer, $this->outputMode === 'a' ? FILE_APPEND : 0);
            }

            if ($returnData) {
                file_put_contents($filename, $returnData, FILE_APPEND);
            }
        }

        $this->removeLockFile();

        return $outputBuffer . (is_string($returnData) ? $returnData : '');
    }

    /**
     * Set the file/s where to write the output of the job.
     *
     * @param string|array $filename
     * @param bool         $append
     * @return self
     */
    public function output($filename, bool $append = false): Job
    {
        $this->outputTo = is_array($filename) ? $filename : [$filename];
        $this->outputMode = $append === false ? 'w' : 'a';

        return $this;
    }

    /**
     * Get the job output.
     *
     * @return mixed
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Set the emails where the output should be sent to.
     * The Job should be set to write output to a file
     * for this to work.
     *
     * @param  string|array  $email
     * @return self
     */
    public function email($email): Job
    {
        if (! is_string($email) && ! is_array($email)) {
            throw new InvalidArgumentException('The email can be only string or array');
        }

        $this->emailTo = is_array($email) ? $email : [$email];

        // Force the job to run in foreground
        $this->inForeground();

        return $this;
    }

    /**
     * Finalise the job after execution.
     *
     * @return void
     */
    private function finalise()
    {
        // Send output to email
        $this->emailOutput();

        // Call any callback defined
        if (is_callable($this->after)) {
            call_user_func($this->after, $this->output, $this->returnCode);
        }
    }

    /**
     * Email the output of the job, if any.
     *
     * @return bool
     */
    private function emailOutput(): bool
    {
        if (! count($this->outputTo) || ! count($this->emailTo)) {
            return false;
        }

        if (isset($this->emailConfig['ignore_empty_output']) &&
            $this->emailConfig['ignore_empty_output'] === true &&
            empty($this->output)
        ) {
            return false;
        }

        $this->sendToEmails($this->outputTo);

        return true;
    }

    /**
     * Set function to be called before job execution
     * Job object is injected as a parameter to callable function.
     *
     * @param callable $fn
     * @return self
     */
    public function before(callable $fn): Job
    {
        $this->before = $fn;

        return $this;
    }

    /**
     * Set a function to be called after job execution.
     * By default, this will force the job to run in foreground
     * because the output is injected as a parameter of this
     * function, but it could be avoided by passing true as a
     * second parameter. The job will run in background if it
     * meets all the other criteria.
     *
     * @param callable $fn
     * @param bool     $runInBackground
     * @return self
     */
    public function then(callable $fn, bool $runInBackground = false): Job
    {
        $this->after = $fn;

        // Force the job to run in foreground
        if ($runInBackground === false) {
            $this->inForeground();
        }

        return $this;
    }

    /**
     * Get time, when job was created.
     *
     * @return DateTime
     */
    public function getCreationTime(): DateTime
    {
        return clone $this->creationTime;
    }

    /**
     * Get Time when the job was ordered to perform.
     *
     * @return DateTimeInterface|null
     */
    public function getRunTime():? DateTimeInterface
    {
        return isset($this->runTime) ? clone $this->runTime : null;
    }

    /**
     * Get real time when the job started to run.
     *
     * @return DateTime|null
     */
    public function getRealRunTime():? DateTime
    {
        return isset($this->realRunTime) ? clone $this->realRunTime : null;
    }

    /**
     * Get time, when job was finished or failed.
     *
     * @return DateTime|null
     */
    public function getFinishTime():? DateTime
    {
        return isset($this->finishTime) ? clone $this->finishTime : null;
    }

    /**
     * The default CRON expression that can determine whether the job should be run.
     *
     * @return CronExpression
     */
    public static function getDefaultExpression(): CronExpression
    {
        return new CronExpression('* * * * *');
    }
}