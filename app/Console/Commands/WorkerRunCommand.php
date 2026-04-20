<?php

namespace App\Console\Commands;

use App\Services\Execution\WorkerProcessPoolService;
use App\Services\Execution\WorkerRunnerService;
use Illuminate\Console\Command;

class WorkerRunCommand extends Command
{
    protected $signature = 'worker:run
        {--once : Process only one polling cycle}
        {--child-task-file= : Internal option to process an already claimed task payload}';

    protected $description = 'Run the task worker polling loop.';

    public function __construct(
        private readonly WorkerRunnerService $workerRunnerService,
        private readonly WorkerProcessPoolService $workerProcessPoolService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $childTaskFile = $this->option('child-task-file');

        if (is_string($childTaskFile) && trim($childTaskFile) !== '') {
            return $this->handleClaimedTaskPayload($childTaskFile);
        }

        if ($this->maxConcurrentTasks() > 1) {
            $this->workerProcessPoolService->run(
                onProgress: function (string $message): void {
                    $this->line($message);
                },
                once: (bool) $this->option('once'),
            );

            return self::SUCCESS;
        }

        do {
            $result = $this->workerRunnerService->runCycle(function (string $message): void {
                $this->line($message);
            });

            if ($result->hadTask) {
                $this->line($result->message);
            } else {
                $this->comment($result->message);
            }

            if ($this->option('once')) {
                break;
            }

            sleep(max(1, (int) config('worker.polling_interval_seconds', 30)));
        } while (true);

        return self::SUCCESS;
    }

    private function handleClaimedTaskPayload(string $childTaskFile): int
    {
        $result = $this->workerProcessPoolService->runClaimedTaskPayload(
            payloadPath: $childTaskFile,
            onProgress: function (string $message): void {
                $this->line($message);
            },
        );

        if ($result->hadTask) {
            $this->line($result->message);
        } else {
            $this->comment($result->message);
        }

        return self::SUCCESS;
    }

    private function maxConcurrentTasks(): int
    {
        return max(1, (int) config('worker.max_concurrent_tasks', 1));
    }
}
