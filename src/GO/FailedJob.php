<?php namespace GO;

use Throwable;

class FailedJob
{
    /**
     * @var Job
     */
    private Job $job;

    /**
     * @var Throwable
     */
    private Throwable $exception;

    public function __construct(Job $job, Throwable $exception)
    {
        $this->job = $job;
        $this->exception = $exception;
    }

    /**
     * Get the job which was failed.
     *
     * @return Job
     */
    public function getJob(): Job
    {
        return $this->job;
    }

    /**
     * Get the exception that failed the job.
     *
     * @return Throwable
     */
    public function getException(): Throwable
    {
        return $this->exception;
    }
}
