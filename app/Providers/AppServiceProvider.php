<?php

namespace App\Providers;

use App\Console\Commands\WorkerRunCommand;
use App\Services\Api\TaskApiClient;
use App\Services\Execution\CursorExecutorService;
use App\Services\Execution\DockerComposeEnvironmentService;
use App\Services\Execution\ExecutionHeartbeatService;
use App\Services\Execution\ExecutionLoopService;
use App\Services\Execution\TaskReviewFlowService;
use App\Services\Execution\WorkerRunnerService;
use App\Services\Notifications\Channels\WhatsAppNotificationChannel;
use App\Services\Notifications\Evolution\EvolutionApiClient;
use App\Services\Notifications\TaskStatusNotificationFormatter;
use App\Services\Notifications\TaskStatusNotificationOrchestrator;
use App\Services\Publication\GitPublicationService;
use App\Services\Prompt\PromptBuilderService;
use App\Services\Reporting\TaskResultReporterService;
use App\Services\Repository\ProjectRepositoryResolver;
use App\Services\Repository\RepositorySyncService;
use App\Services\Validation\AutomaticValidationService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskApiClient::class);
        $this->app->singleton(CursorExecutorService::class);
        $this->app->singleton(DockerComposeEnvironmentService::class);
        $this->app->singleton(ExecutionHeartbeatService::class);
        $this->app->singleton(ExecutionLoopService::class);
        $this->app->singleton(TaskReviewFlowService::class);
        $this->app->singleton(WorkerRunnerService::class);
        $this->app->singleton(EvolutionApiClient::class);
        $this->app->singleton(TaskStatusNotificationFormatter::class);
        $this->app->singleton(TaskStatusNotificationOrchestrator::class);
        $this->app->singleton(WhatsAppNotificationChannel::class);
        $this->app->singleton(GitPublicationService::class);
        $this->app->singleton(PromptBuilderService::class);
        $this->app->singleton(TaskResultReporterService::class);
        $this->app->singleton(ProjectRepositoryResolver::class);
        $this->app->singleton(RepositorySyncService::class);
        $this->app->singleton(AutomaticValidationService::class);
        $this->app->singleton(WorkspaceService::class);

        $this->app->tag([
            WhatsAppNotificationChannel::class,
        ], 'notification.channels');

        $this->app
            ->when(TaskStatusNotificationOrchestrator::class)
            ->needs('$channels')
            ->giveTagged('notification.channels');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([
            WorkerRunCommand::class,
        ]);
    }
}
