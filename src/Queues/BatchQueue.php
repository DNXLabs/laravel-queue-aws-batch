<?php
/**
 * Laravel Queue for AWS Batch.
 *
 * @author    Luke Waite <lwaite@gmail.com>
 * @copyright 2017 Luke Waite
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @link      https://github.com/lukewaite/laravel-queue-aws-batch
 */

namespace LukeWaite\LaravelQueueAwsBatch\Queues;

use Aws\Batch\BatchClient;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use LukeWaite\LaravelQueueAwsBatch\Exceptions\JobNotFoundException;
use LukeWaite\LaravelQueueAwsBatch\Exceptions\UnsupportedException;
use LukeWaite\LaravelQueueAwsBatch\Jobs\BatchJob;

class BatchQueue extends DatabaseQueue
{
    /**
     * The AWS Batch client.
     *
     * @var BatchClient
     */
    protected $batch;

    protected $jobDefinition;

    public function __construct(
        Connection $database,
        $table,
        $default,
        $expire,
        $jobDefinition,
        BatchClient $batch
    ) {
        $this->jobDefinition = $jobDefinition;
        $this->batch = $batch;
        parent::__construct($database, $table, $default, $expire);
    }

    public function push($job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $data);
        return $this->pushToBatch($queue, $payload, $this->getDisplayName($job));
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->pushToBatch($queue, $payload, 'raw-job');
    }

    /**
     * Get the display name for the given job.
     *
     * @param  mixed  $job
     * @return string
     */
    protected function getDisplayName($job)
    {
        if (is_object($job)) {
            return method_exists($job, 'displayName')
                ? $job->displayName() : get_class($job);
        } else {
            return is_string($job) ? explode('@', $job)[0] : null;
        }
    }

    /**
     * Push a raw payload to the database, then to AWS Batch, with a given delay.
     *
     * @param string|null $queue
     * @param string      $payload
     * @param string      $jobName
     *
     * @return mixed
     */
    protected function pushToBatch($queue, $payload, $jobName)
    {
        $jobId = $this->pushToDatabase(0, $queue, $payload);

        return $this->batch->submitJob([
            'jobDefinition' => $this->jobDefinition,
            'jobName'       => $jobName,
            'jobQueue'      => $this->getQueue($queue),
            'parameters'    => [
                'jobId' => $jobId,
            ]
        ]);
    }

    public function getJobById($id, $queue)
    {
        $job = $this->database->table($this->table)->where('id', $id)->first();
        if (!isset($job)) {
            throw new JobNotFoundException('Could not find the job');
        }

        return new BatchJob(
            $this->container, $this, $job, $queue
        );
    }

    /**
     * Release the job, without deleting first from the Queue
     *
     * @param string $queue
     * @param \StdClass $job
     * @param int $delay
     *
     * @return int
     * @throws UnsupportedException
     */
    public function release($queue, $job, $delay)
    {
        if ($delay != 0) {
            throw new UnsupportedException('The BatchJob does not support releasing back onto the queue with a delay');
        }

        $attributes = [
            'id'          => $job->id,
            'attempts'    => $job->attempts,
            'reserved'    => 0,
            'reserved_at' => null,
        ];

        return $this->database->table($this->table)->update($attributes);
    }

    public function pop($queue = null)
    {
        throw new UnsupportedException('The BatchQueue does not support running via a regular worker. Instead, you should use the queue:batch-work command with a job id.');
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        // This could be implemented, but it's not in first pass.
        throw new UnsupportedException('The BatchQueue does not currently support the bulk() operation.');
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        throw new UnsupportedException('The BatchQueue does not support the later() operation.');
    }
}
