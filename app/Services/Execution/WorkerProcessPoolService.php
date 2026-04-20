<?php

namespace App\Services\Execution;

use App\DTOs\Mapping\ApiTaskMapper;
use App\DTOs\TaskData;
use App\Services\Service;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class WorkerProcessPoolService extends Service
{
    private const MONITOR_INTERVAL_MICROSECONDS = 200000;

    public function __construct(
        private readonly WorkerRunnerService $workerRunnerService,
        private readonly Filesystem $filesystem,
    ) {}

    public function run(?callable $onProgress = null, bool $once = false): void
    {
        /** @var array<int, array{task: TaskData, process: Process, payload_path: string}> $activeProcesses */
        $activeProcesses = [];
        $drainOnly = false;

        do {
            $this->drainFinishedProcesses($activeProcesses, $onProgress);

            if (! $drainOnly) {
                $claimedTask = false;

                while (count($activeProcesses) < $this->maxConcurrentTasks()) {
                    $task = $this->workerRunnerService->claimTask();

                    if ($task === null) {
                        break;
                    }

                    $claimedTask = true;
                    $activeProcesses[] = $this->startClaimedTaskProcess($task, $onProgress);
                }

                if ($once) {
                    $drainOnly = true;
                }

                if (! $claimedTask && $activeProcesses === []) {
                    if ($once) {
                        return;
                    }

                    sleep(max(1, (int) config('worker.polling_interval_seconds', 30)));

                    continue;
                }
            }

            if ($drainOnly && $activeProcesses === []) {
                return;
            }

            usleep(self::MONITOR_INTERVAL_MICROSECONDS);
        } while (true);
    }

    public function runClaimedTaskPayload(string $payloadPath, ?callable $onProgress = null): WorkerCycleResult
    {
        $task = $this->loadTaskFromPayload($payloadPath);

        $onProgress?->__invoke(sprintf(
            'Task %d claimed. Starting execution for "%s".',
            $task->id,
            $task->title,
        ));

        return $this->workerRunnerService->runClaimedTask($task, $onProgress);
    }

    /**
     * @param  array<int, array{task: TaskData, process: Process, payload_path: string}>  $activeProcesses
     * @return array{task: TaskData, process: Process, payload_path: string}
     */
    protected function startClaimedTaskProcess(TaskData $task, ?callable $onProgress = null): array
    {
        $payloadPath = $this->writeClaimedTaskPayload($task);

        try {
            $process = $this->startChildProcess($task, $payloadPath);
        } catch (\Throwable $exception) {
            $this->deletePayloadFile($payloadPath);

            throw $exception;
        }

        $onProgress?->__invoke(sprintf(
            'Task %d claimed. Dispatched to worker child process.',
            $task->id,
        ));

        return [
            'task' => $task,
            'process' => $process,
            'payload_path' => $payloadPath,
        ];
    }

    protected function startChildProcess(TaskData $task, string $payloadPath): Process
    {
        $process = new Process(
            command: [
                PHP_BINARY,
                base_path('artisan'),
                'worker:run',
                '--child-task-file='.$payloadPath,
            ],
            cwd: base_path(),
            timeout: (float) config('worker.process_timeout_seconds', 3600),
        );

        $process->start();

        return $process;
    }

    protected function writeClaimedTaskPayload(TaskData $task): string
    {
        $directory = storage_path('app/worker-dispatch');

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.sprintf('task-%d-%s.json', $task->id, bin2hex(random_bytes(6)));

        $this->filesystem->put(
            $path,
            json_encode($task->sourcePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $path;
    }

    /**
     * @param  array<int, array{task: TaskData, process: Process, payload_path: string}>  $activeProcesses
     */
    private function drainFinishedProcesses(array &$activeProcesses, ?callable $onProgress = null): void
    {
        foreach ($activeProcesses as $index => $activeProcess) {
            $this->flushProcessOutput($activeProcess['task'], $activeProcess['process'], $onProgress);

            if ($activeProcess['process']->isRunning()) {
                continue;
            }

            $this->flushProcessOutput($activeProcess['task'], $activeProcess['process'], $onProgress);

            if ($activeProcess['process']->getExitCode() !== 0) {
                $onProgress?->__invoke(sprintf(
                    'Task %d child process exited with code %d.',
                    $activeProcess['task']->id,
                    $activeProcess['process']->getExitCode(),
                ));
            }

            $this->deletePayloadFile($activeProcess['payload_path']);

            unset($activeProcesses[$index]);
        }

        $activeProcesses = array_values($activeProcesses);
    }

    private function flushProcessOutput(TaskData $task, Process $process, ?callable $onProgress = null): void
    {
        $this->emitOutputLines($task, $process->getIncrementalOutput(), $onProgress);
        $this->emitOutputLines($task, $process->getIncrementalErrorOutput(), $onProgress);
    }

    private function emitOutputLines(TaskData $task, string $buffer, ?callable $onProgress = null): void
    {
        $trimmedBuffer = trim($buffer);

        if ($trimmedBuffer === '') {
            return;
        }

        foreach (preg_split('/\r\n|\r|\n/', $trimmedBuffer) ?: [] as $line) {
            $normalizedLine = trim((string) $line);

            if ($normalizedLine === '') {
                continue;
            }

            $onProgress?->__invoke(sprintf(
                '[task:%d] %s',
                $task->id,
                $normalizedLine,
            ));
        }
    }

    private function loadTaskFromPayload(string $payloadPath): TaskData
    {
        if (! $this->filesystem->exists($payloadPath)) {
            throw new \RuntimeException(sprintf('Claimed task payload file "%s" was not found.', $payloadPath));
        }

        $payload = json_decode((string) $this->filesystem->get($payloadPath), true);

        if (! is_array($payload)) {
            throw new \RuntimeException(sprintf('Claimed task payload file "%s" is invalid.', $payloadPath));
        }

        return ApiTaskMapper::map($payload);
    }

    private function deletePayloadFile(string $payloadPath): void
    {
        if ($this->filesystem->exists($payloadPath)) {
            $this->filesystem->delete($payloadPath);
        }
    }

    private function maxConcurrentTasks(): int
    {
        return max(1, (int) config('worker.max_concurrent_tasks', 1));
    }
}
