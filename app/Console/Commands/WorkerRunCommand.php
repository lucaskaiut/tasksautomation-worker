<?php

namespace App\Console\Commands;

use App\Services\Execution\WorkerRunnerService;
use Illuminate\Console\Command;

class WorkerRunCommand extends Command
{
    protected $signature = 'worker:run {--once : Process only one polling cycle}';

    protected $description = 'Run the task worker polling loop.';

    public function __construct(
        private readonly WorkerRunnerService $workerRunnerService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        do {
            $result = $this->workerRunnerService->runCycle();

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
}
