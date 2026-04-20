<?php

namespace App\Services\Prompt;

use App\DTOs\IterationContext;
use App\DTOs\TaskData;
use App\Services\Service;
use App\Support\Enums\TaskAnalysisDomain;

class PromptBuilderService extends Service
{
    public function buildInitialPrompt(TaskData $task): string
    {
        return $this->renderPrompt(
            title: $task->isAnalysisStage() ? 'Prompt de Analise' : 'Prompt de Execucao',
            task: $task,
            extraSections: [],
        );
    }

    public function buildIterationPrompt(IterationContext $context): string
    {
        $extraSections = [
            $this->section('Contexto da Iteracao', implode("\n", [
                '- Tentativa atual: '.$context->attempt.' de '.$context->maxAttempts,
                '- Task ID: '.$context->taskId,
                '- Ajuste incremental obrigatorio: sim',
            ])),
        ];

        if ($context->lastTechnicalError !== null && trim($context->lastTechnicalError) !== '') {
            $extraSections[] = $this->section(
                'Erro Tecnico da Iteracao Anterior',
                $this->literalBlock(trim($context->lastTechnicalError))
            );
        }

        if ($context->humanReviewFeedback !== null && trim($context->humanReviewFeedback) !== '') {
            $extraSections[] = $this->section(
                'Feedback Humano de Review',
                trim($context->humanReviewFeedback)
            );
        }

        return $this->renderPrompt(
            title: 'Prompt de Correcao Incremental',
            task: $context->task,
            extraSections: $extraSections,
        );
    }

    /**
     * @param  list<string>  $extraSections
     */
    private function renderPrompt(string $title, TaskData $task, array $extraSections): string
    {
        $sections = array_values(array_filter([
            '# '.$title,
            $this->renderStageSection($task),
            $this->section('Objetivo', $task->title),
            $this->optionalSection('Descricao', $task->description),
            $this->optionalSection('Entregaveis', $task->deliverables),
            $this->optionalSection('Restricoes', $task->constraints),
            $this->renderProjectSection($task),
            $this->renderEnvironmentSection($task),
            $this->section('Leitura Obrigatoria do Repositorio', implode("\n", [
                '- Antes de implementar qualquer alteracao, leia a documentacao existente no repositorio.',
                '- Procure arquivos de arquitetura, regras de negocio, fluxos funcionais e convencoes do projeto.',
                '- Verifique especialmente arquivos como README, docs/, ADRs, arquivos de arquitetura e documentos de dominio.',
                '- Se houver documentacao relevante na raiz ou em subpastas, use esse contexto antes de editar o codigo.',
                '- Nao assuma regras de negocio sem antes procurar evidencias na documentacao e no codigo existente.',
            ])),
            $this->section('Instrucoes Operacionais', implode("\n", [
                '- Trabalhe de forma incremental sobre o codigo existente.',
                '- Preserve o que ja funciona.',
                '- Nao reimplemente do zero.',
                '- Mantenha a alteracao focada no objetivo da task.',
                '- Use erros tecnicos e feedback humano apenas para ajustar o necessario.',
            ])),
            ...$extraSections,
        ]));

        return implode("\n\n", $sections)."\n";
    }

    private function renderStageSection(TaskData $task): string
    {
        $stage = $task->stage();
        $lines = [
            '- Estagio atual: '.$stage->value,
        ];

        if ($stage->isAnalysis()) {
            $lines[] = '- Este estagio e apenas de analise. Nao implemente alteracoes no codigo, nao execute refactors e nao publique mudancas.';
            $lines[] = '- Investigue o problema, identifique falhas provaveis, riscos tecnicos, impacto e artefatos necessarios para a proxima etapa.';
            $lines[] = '- Ao finalizar, responda com JSON valido contendo: `domain`, `confidence`, `next_stage`, `summary`, `evidence`, `risks`, `artifacts`, `notes`.';
            $lines[] = '- `domain` deve ser `backend`, `frontend` ou `infra`. `next_stage` deve ser um de: `implementation:backend`, `implementation:frontend`, `implementation:infra`.';
            $lines[] = "- Nao use markdown no resultado final deste estagio. Retorne apenas o objeto JSON.";

            return $this->section('Estagio da Task', implode("\n", array_merge($lines, [
                '- Exemplo de estrutura esperada:',
                $this->literalBlock(json_encode([
                    'domain' => TaskAnalysisDomain::Backend->value,
                    'confidence' => 0.9,
                    'next_stage' => 'implementation:backend',
                    'summary' => 'Resumo objetivo da analise.',
                    'evidence' => [
                        'entrypoint' => 'Arquivo ou fluxo principal analisado',
                    ],
                    'risks' => [
                        'Risco tecnico identificado',
                    ],
                    'artifacts' => [
                        'Teste ou arquivo relevante',
                    ],
                    'notes' => 'Observacoes adicionais.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ])));
        }

        $domain = $stage->implementationDomain();

        $lines[] = '- Execute apenas o escopo deste estagio.';
        $lines[] = '- Se houver necessidade fora deste dominio, documente no resultado, mas nao desvie a implementacao principal.';
        $lines[] = '- Dominio esperado desta implementacao: '.$domain.'.';

        return $this->section('Estagio da Task', implode("\n", $lines));
    }

    private function renderProjectSection(TaskData $task): ?string
    {
        if ($task->project === null) {
            return null;
        }

        $lines = array_values(array_filter([
            '- Nome: '.$task->project->name,
            '- Slug: '.$task->project->slug,
            '- Repositorio: '.$task->project->repositoryUrl,
            '- Branch padrao: '.$task->project->defaultBranch,
            $this->optionalStructuredBlock('Regras globais', $task->project->globalRules),
        ]));

        return $this->section('Projeto', implode("\n", $lines));
    }

    private function renderEnvironmentSection(TaskData $task): ?string
    {
        if ($task->environmentProfile === null) {
            return null;
        }

        $lines = array_values(array_filter([
            '- Nome: '.$task->environmentProfile->name,
            '- Slug: '.$task->environmentProfile->slug,
            '- Default: '.($task->environmentProfile->isDefault ? 'sim' : 'nao'),
            $this->dockerExecutionInstructions($task),
            $this->optionalLabeledBlock('Docker compose', $task->environmentProfile->dockerComposeYml),
        ]));

        return $this->section('Perfil de Ambiente', implode("\n", $lines));
    }

    private function dockerExecutionInstructions(TaskData $task): ?string
    {
        $dockerCompose = $task->environmentProfile?->dockerComposeYml;

        if ($dockerCompose === null || trim($dockerCompose) === '') {
            return null;
        }

        $service = $this->dockerExecService($task);

        return implode("\n", [
            '- O projeto deve ser operado dentro dos containers do docker compose.',
            '- Antes de executar comandos do projeto, assuma que o worker subiu o compose do workspace.',
            '- Execute comandos do projeto usando docker compose exec.',
            '- Servico principal sugerido: '.$service,
            '- Exemplo: `docker compose exec -T '.$service.' sh -lc "php artisan test"`',
        ]);
    }

    private function optionalSection(string $title, ?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return $this->section($title, trim($content));
    }

    private function optionalLabeledBlock(string $label, ?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return '- '.$label.":\n".$this->literalBlock(trim($content));
    }

    private function optionalStructuredBlock(string $label, mixed $content): ?string
    {
        if ($content === null) {
            return null;
        }

        if (is_string($content)) {
            return $this->optionalLabeledBlock($label, $content);
        }

        if (is_bool($content)) {
            $normalized = $content ? 'true' : 'false';

            return '- '.$label.":\n".$this->literalBlock($normalized);
        }

        if (is_int($content) || is_float($content)) {
            return '- '.$label.":\n".$this->literalBlock((string) $content);
        }

        if (is_array($content)) {
            return '- '.$label.":\n".$this->literalBlock(
                json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return null;
    }

    private function section(string $title, string $content): string
    {
        return '## '.$title."\n\n".$content;
    }

    private function literalBlock(string $content): string
    {
        return "```text\n".$content."\n```";
    }

    private function dockerExecService(TaskData $task): string
    {
        $profileSlug = $task->environmentProfile?->slug;
        $configured = config('worker.docker.exec_service_by_environment_profile_slug', []);

        if (is_string($profileSlug) && isset($configured[$profileSlug]) && is_string($configured[$profileSlug]) && trim($configured[$profileSlug]) !== '') {
            return trim($configured[$profileSlug]);
        }

        return (string) config('worker.docker.default_exec_service', 'app');
    }
}
