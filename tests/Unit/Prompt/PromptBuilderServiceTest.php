<?php

namespace Tests\Unit\Prompt;

use App\DTOs\IterationContext;
use App\DTOs\Mapping\ApiTaskMapper;
use App\Services\Prompt\PromptBuilderService;
use Tests\TestCase;

class PromptBuilderServiceTest extends TestCase
{
    public function test_build_initial_prompt_includes_all_relevant_task_context(): void
    {
        $task = $this->makeTask();

        $prompt = app(PromptBuilderService::class)->buildInitialPrompt($task);

        $this->assertStringContainsString('# Prompt de Execucao', $prompt);
        $this->assertStringContainsString('## Objetivo', $prompt);
        $this->assertStringContainsString('Implementar autenticacao', $prompt);
        $this->assertStringContainsString('## Descricao', $prompt);
        $this->assertStringContainsString('Adicionar fluxo de login com sessao persistida.', $prompt);
        $this->assertStringContainsString('## Entregaveis', $prompt);
        $this->assertStringContainsString('Tela de login', $prompt);
        $this->assertStringContainsString('## Restricoes', $prompt);
        $this->assertStringContainsString('Nao alterar contratos da API.', $prompt);
        $this->assertStringContainsString('## Projeto', $prompt);
        $this->assertStringContainsString('https://github.com/acme/app', $prompt);
        $this->assertStringContainsString('"requires_pr": true', $prompt);
        $this->assertStringContainsString('"service": "app"', $prompt);
        $this->assertStringContainsString('## Perfil de Ambiente', $prompt);
        $this->assertStringContainsString('services:', $prompt);
        $this->assertStringContainsString('docker compose exec', $prompt);
        $this->assertStringContainsString('Servico principal sugerido: app', $prompt);
        $this->assertStringContainsString('## Leitura Obrigatoria do Repositorio', $prompt);
        $this->assertStringContainsString('leia a documentacao existente no repositorio', $prompt);
        $this->assertStringContainsString('arquitetura, regras de negocio, fluxos funcionais', $prompt);
        $this->assertStringContainsString('## Instrucoes Operacionais', $prompt);
        $this->assertStringContainsString('Nao reimplemente do zero.', $prompt);
        $this->assertStringContainsString('Estagio atual: implementation:backend', $prompt);
        $this->assertStringContainsString('Dominio esperado desta implementacao: backend.', $prompt);
    }

    public function test_build_initial_prompt_for_analysis_stage_requires_json_only_output(): void
    {
        $task = $this->makeTask([
            'current_stage' => 'analysis',
        ]);

        $prompt = app(PromptBuilderService::class)->buildInitialPrompt($task);

        $this->assertStringContainsString('# Prompt de Analise', $prompt);
        $this->assertStringContainsString('Estagio atual: analysis', $prompt);
        $this->assertStringContainsString('Nao implemente alteracoes no codigo', $prompt);
        $this->assertStringContainsString('Retorne apenas o objeto JSON', $prompt);
        $this->assertStringContainsString('"next_stage": "implementation:backend"', $prompt);
    }

    public function test_build_iteration_prompt_includes_incremental_error_and_human_feedback(): void
    {
        $task = $this->makeTask();
        $context = new IterationContext(
            taskId: $task->id,
            attempt: 2,
            maxAttempts: 5,
            task: $task,
            lastTechnicalError: "php artisan test\nFAIL Tests\\Feature\\LoginTest",
            humanReviewFeedback: 'Corrigir redirecionamento apos login sem quebrar middleware existente.',
        );

        $prompt = app(PromptBuilderService::class)->buildIterationPrompt($context);

        $this->assertStringContainsString('# Prompt de Correcao Incremental', $prompt);
        $this->assertStringContainsString('## Contexto da Iteracao', $prompt);
        $this->assertStringContainsString('Tentativa atual: 2 de 5', $prompt);
        $this->assertStringContainsString('## Erro Tecnico da Iteracao Anterior', $prompt);
        $this->assertStringContainsString('FAIL Tests\\Feature\\LoginTest', $prompt);
        $this->assertStringContainsString('## Feedback Humano de Review', $prompt);
        $this->assertStringContainsString('Corrigir redirecionamento apos login', $prompt);
        $this->assertStringContainsString('Preserve o que ja funciona.', $prompt);
        $this->assertStringContainsString('Nao reimplemente do zero.', $prompt);
    }

    private function makeTask(array $overrides = []): \App\DTOs\TaskData
    {
        return ApiTaskMapper::map(array_replace_recursive([
            'id' => 11,
            'title' => 'Implementar autenticacao',
            'description' => 'Adicionar fluxo de login com sessao persistida.',
            'deliverables' => "Tela de login\nMiddleware autenticado",
            'constraints' => 'Nao alterar contratos da API.',
            'status' => 'claimed',
            'project_id' => 3,
            'environment_profile_id' => 7,
            'created_by' => 1,
            'claimed_by_worker' => 'worker-1',
            'claimed_at' => '2026-01-01T00:00:00.000000Z',
            'attempts' => 1,
            'max_attempts' => 5,
            'current_stage' => 'implementation:backend',
            'review_status' => 'needs_adjustment',
            'revision_count' => 1,
            'priority' => 'high',
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
            'project' => [
                'id' => 3,
                'name' => 'App',
                'slug' => 'app',
                'description' => 'Projeto principal',
                'repository_url' => 'https://github.com/acme/app',
                'default_branch' => 'main',
                'global_rules' => [
                    'docker' => [
                        'service' => 'app',
                        'workspace' => '/var/www/html',
                    ],
                    'requires_pr' => true,
                    'labels' => ['backend', 'urgent'],
                    'max_attempts' => 3,
                ],
                'is_active' => true,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ],
            'environment_profile' => [
                'id' => 7,
                'project_id' => 3,
                'name' => 'web',
                'slug' => 'web',
                'is_default' => true,
                'docker_compose_yml' => "services:\n  app:\n    image: php:8.3-cli",
            ],
        ], $overrides));
    }
}
