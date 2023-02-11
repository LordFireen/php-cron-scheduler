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

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getException(): Exception
    {
        return $this->exception;
    }
}
