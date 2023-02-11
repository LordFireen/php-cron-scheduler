<?php namespace GO;

use Exception;

class FailedJob
{
    /**
     * @var Job
     */
    private Job $job;

    /**
     * @var Exception
     */
    private Exception $exception;

    public function __construct(Job $job, Exception $exception)
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
     * @return Exception
     */
    public function getException(): Exception
    {
        return $this->exception;
    }
}
