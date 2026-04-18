# Worker de execução de tasks

Worker em Laravel 13, orientado a console, responsável por:

- fazer claim de tasks na API
- preparar workspace isolado por task
- resolver e sincronizar o repositório do projeto
- gerar prompt para o Codex CLI
- executar o agente
- validar o resultado
- iterar automaticamente quando necessário
- enviar heartbeat durante execução
- reportar o resultado final para a API

A especificação técnica está em `docs/context.md` e o plano incremental está em `docs/implementation.md`.

## Requisitos

- PHP 8.3+
- Composer 2
- extensões usuais do Laravel
- `git` disponível no ambiente
- Codex CLI instalado e acessível no host/container

Se usar SQLite para testes locais, tenha também `pdo_sqlite`.

## Instalação local

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Configuração via `.env`

As configurações do worker ficam em `config/worker.php` e são alimentadas por variáveis `WORKER_*`.

Configurações mínimas:

```dotenv
WORKER_ID=worker-local-01
WORKER_API_BASE_URL=http://localhost
WORKER_API_EMAIL=admin@example.com
WORKER_API_PASSWORD=password
WORKER_CODEX_BINARY=codex
WORKER_WORKSPACES_PATH=/abs/path/to/workspaces
WORKER_REPOSITORIES_AUTOMATIC_CLONE_BASE_PATH=/abs/path/to/repository-cache
```

Configurações relevantes já suportadas:

- `WORKER_ID`
- `WORKER_API_BASE_URL`
- `WORKER_API_TIMEOUT`
- `WORKER_API_CONNECT_TIMEOUT`
- `WORKER_API_TOKEN_PATH`
- `WORKER_API_EMAIL`
- `WORKER_API_PASSWORD`
- `WORKER_API_TOKEN_NAME`
- `WORKER_API_ABILITIES`
- `WORKER_API_CLAIM_PATH`
- `WORKER_API_HEARTBEAT_PATH_TEMPLATE`
- `WORKER_API_FINISH_PATH_TEMPLATE`
- `WORKER_POLLING_INTERVAL`
- `WORKER_HEARTBEAT_INTERVAL`
- `WORKER_HEARTBEAT_FAIL_ON_ERROR`
- `WORKER_HEARTBEAT_POLL_INTERVAL_MS`
- `WORKER_MAX_ATTEMPTS_PER_EXECUTION`
- `WORKER_PUBLICATION_ENABLED`
- `WORKER_PUBLICATION_GIT_USER_NAME`
- `WORKER_PUBLICATION_GIT_USER_EMAIL`
- `WORKER_PUBLICATION_REMOTE_NAME`
- `WORKER_WORKSPACES_PATH`
- `WORKER_REPOSITORIES_BY_PROJECT_SLUG`
- `WORKER_REPOSITORIES_BY_URL`
- `WORKER_REPOSITORIES_AUTOMATIC_CLONE_BASE_PATH`
- `WORKER_REPOSITORIES_GIT_BINARY`
- `WORKER_VALIDATION_GLOBAL_COMMANDS`
- `WORKER_VALIDATION_COMMANDS_BY_ENVIRONMENT_PROFILE_SLUG`
- `WORKER_VALIDATION_STOP_ON_FAILURE`
- `WORKER_DOCKER_BINARY`
- `WORKER_DOCKER_COMPOSE_FILENAME`
- `WORKER_DOCKER_DEFAULT_EXEC_SERVICE`
- `WORKER_DOCKER_EXEC_SERVICE_BY_ENVIRONMENT_PROFILE_SLUG`
- `WORKER_DOCKER_SHUTDOWN_AFTER_TASK`
- `WORKER_CODEX_BINARY`
- `WORKER_CODEX_SANDBOX`
- `WORKER_CODEX_EPHEMERAL`
- `WORKER_CODEX_SKIP_GIT_REPO_CHECK`
- `WORKER_PROCESS_TIMEOUT`
- `WORKER_CLEANUP_WORKSPACE`
- `WORKER_CLEANUP_WORKSPACE_ON_SUCCESS`
- `WORKER_CLEANUP_WORKSPACE_ON_FAILURE`

## Execução

Rodar continuamente:

```bash
php artisan worker:run
```

Rodar um único ciclo de polling:

```bash
php artisan worker:run --once
```

Listar commands disponíveis:

```bash
php artisan list
```

## Retenção de artefatos

Para debug confiavel, o worker agora separa a politica de cleanup entre sucesso e falha.

Configuracoes:

```dotenv
WORKER_CLEANUP_WORKSPACE=true
WORKER_CLEANUP_WORKSPACE_ON_SUCCESS=true
WORKER_CLEANUP_WORKSPACE_ON_FAILURE=false
```

Com a configuracao padrao:

- execucao com sucesso: o workspace pode ser removido
- execucao com falha: o workspace e preservado

Em caso de falha, o worker tambem salva snapshots de diagnostico em `workspace/logs`, incluindo:

- `failure-context.json`
- `git-diagnostics-failure.json`
- `git-diagnostics-loop-failure.json`
- `git-diagnostics-pre-publication.json`

## Autenticação na API

O worker nao usa token fixo em `.env`.

Antes de fazer `claim`, `heartbeat` ou `finish`, ele cria um token em tempo real via:

```text
POST /api/tokens/create
```

Payload enviado:

```json
{
  "email": "admin@example.com",
  "password": "password",
  "token_name": "worker",
  "abilities": ["*"]
}
```

Configuracoes relacionadas:

- `WORKER_API_TOKEN_PATH`
- `WORKER_API_EMAIL`
- `WORKER_API_PASSWORD`
- `WORKER_API_TOKEN_NAME`
- `WORKER_API_ABILITIES`

O token obtido e reutilizado em memoria pelo cliente HTTP do worker durante a execucao do processo.

## Como o fluxo funciona

O command principal:

1. faz claim de uma task
2. cria um workspace em `storage/workspaces/{task_id}` ou no caminho configurado
3. resolve a estratégia de repositório
4. sincroniza o código para `workspace/repo`
5. gera `prompt.md`
6. executa o Codex CLI
7. roda as validações configuradas
8. itera quando houver falha técnica
9. envia heartbeat durante a execução
10. publica as alterações em uma branch git dedicada
11. reporta o resultado final em `POST /api/tasks/{task}/finish`

## Repositórios locais

O worker suporta dois modos de obtenção do repositório.

### 1. Repositório já existente localmente

Você pode mapear caminhos locais por slug do projeto ou por URL do repositório.

Exemplo:

```dotenv
WORKER_REPOSITORIES_BY_PROJECT_SLUG={"infra":"/var/repos/infra","app":"/var/repos/app"}
WORKER_REPOSITORIES_BY_URL={"https://github.com/acme/private-repo":"/var/repos/private-repo"}
```

Prioridade atual:

1. `by_project_slug`
2. `by_repository_url`
3. fallback para clone automático

## Clone e sync automático

Quando não houver mapeamento local, o worker usa a estratégia automática com base em:

- `project.repository_url`
- `project.default_branch`

O cache local do repositório é mantido em:

```dotenv
WORKER_REPOSITORIES_AUTOMATIC_CLONE_BASE_PATH=/abs/path/to/repository-cache
```

Comportamento atual:

- clona o repositório se o cache ainda não existir
- faz `fetch --prune` quando o cache já existe
- faz checkout da branch padrão
- alinha o cache com `origin/<branch>`
- copia uma versão isolada para `workspace/repo`

Isso permite reaproveitar cache entre tasks sem compartilhar diretamente o diretório de trabalho da task.

## Repositórios privados

O worker não gerencia credenciais por conta própria.

Para repositórios privados, o ambiente precisa já estar preparado com autenticação válida para `git`, por exemplo:

- chave SSH
- credential helper
- token embutido na URL
- autenticação já disponível no container/host

Sem isso, o clone falhará.

## Publicação git

Quando a execução técnica termina com sucesso, o worker publica as alterações diretamente pelo `git`.

Fluxo atual:

- cria uma branch `feat/{task_id}` para tasks de feature
- cria uma branch `fix/{task_id}` para tasks de correção
- faz `git add -A`
- faz commit com mensagem em ingles e em minusculas
- faz `git push -u origin <branch>`
- envia `branch_name` e `commit_sha` no `finish`

Configurações relacionadas:

```dotenv
WORKER_PUBLICATION_ENABLED=true
WORKER_PUBLICATION_GIT_USER_NAME="Tasks Automation Worker"
WORKER_PUBLICATION_GIT_USER_EMAIL=worker@example.com
WORKER_PUBLICATION_REMOTE_NAME=origin
```

O worker espera que a task possa informar o tipo da implementação em `implementation_type`.

Contrato recomendado para a API:

- `implementation_type = feature`
- `implementation_type = fix`

Se esse campo não vier da API, o worker aplica um fallback heurístico baseado em texto da task para escolher entre `feat/{id}` e `fix/{id}`.

## Ambiente Docker por task

Se a API retornar conteúdo em `environment_profile.docker_compose_yml`, o worker materializa esse arquivo no workspace da task e passa a operar o projeto com Docker Compose.

Comportamento atual:

- grava o compose em `<workspace>/docker-compose.yml`
- executa `docker compose up -d` antes do loop principal
- orienta o agente, no `prompt.md`, a rodar comandos do projeto via `docker compose exec`
- executa as validações automáticas com `docker compose exec -T <service> sh -lc '<command>'`
- executa `docker compose down --remove-orphans` ao final da task, se `WORKER_DOCKER_SHUTDOWN_AFTER_TASK=true`

O serviço usado no `exec` segue esta ordem:

1. `WORKER_DOCKER_EXEC_SERVICE_BY_ENVIRONMENT_PROFILE_SLUG`
2. primeiro serviço detectado no `docker-compose.yml`
3. `WORKER_DOCKER_DEFAULT_EXEC_SERVICE`

Exemplo de configuração:

```dotenv
WORKER_DOCKER_BINARY=docker
WORKER_DOCKER_COMPOSE_FILENAME=docker-compose.yml
WORKER_DOCKER_DEFAULT_EXEC_SERVICE=app
WORKER_DOCKER_EXEC_SERVICE_BY_ENVIRONMENT_PROFILE_SLUG={"laravel":"app","node":"web"}
WORKER_DOCKER_SHUTDOWN_AFTER_TASK=true
```

## Codex CLI

O worker executa o Codex via processo local usando:

```bash
codex exec --full-auto --sandbox danger-full-access --ephemeral "<conteudo do prompt.md>"
```

O binário é configurado por:

```dotenv
WORKER_CODEX_BINARY=codex
WORKER_CODEX_SANDBOX=danger-full-access
WORKER_CODEX_EPHEMERAL=true
WORKER_CODEX_SKIP_GIT_REPO_CHECK=false
```

No ambiente Docker deste worker, `danger-full-access` e a configuracao recomendada. O modo `workspace-write` tende a falhar dentro do container por depender de `bwrap` e namespaces que nem sempre estao liberados.

Pode ser um nome no `PATH` ou um caminho absoluto para o executável.

O executor:

- roda dentro de `workspace/repo`
- captura `stdout`, `stderr` e `exit code`
- respeita timeout configurável
- grava logs em `workspace/logs`

Arquivos de log do Codex:

- `codex-command.txt`
- `codex-stdout.log`
- `codex-stderr.log`

## Validações automáticas

O worker suporta validações globais e por perfil de ambiente.

Exemplos:

```dotenv
WORKER_VALIDATION_GLOBAL_COMMANDS=["php artisan test --compact"]
WORKER_VALIDATION_COMMANDS_BY_ENVIRONMENT_PROFILE_SLUG={"web":["php artisan test","npm run build"],"api":["php artisan test --testsuite=Unit"]}
WORKER_VALIDATION_STOP_ON_FAILURE=true
```

Os comandos são executados em `workspace/repo`.

Quando a task tiver `environment_profile.docker_compose_yml`, o worker não roda esses comandos diretamente no host. Nesse caso, ele encapsula cada validação com `docker compose exec` dentro do serviço configurado.

Logs gerados:

- `validation-01.log`
- `validation-02.log`
- `validation-summary.log`

## Heartbeat

Durante a execução, o worker envia heartbeat para a API usando o intervalo configurado.

Configurações principais:

```dotenv
WORKER_HEARTBEAT_INTERVAL=10
WORKER_HEARTBEAT_FAIL_ON_ERROR=false
WORKER_HEARTBEAT_POLL_INTERVAL_MS=250
```

Se `WORKER_HEARTBEAT_FAIL_ON_ERROR=false`, falhas transitórias de heartbeat não derrubam a execução.

## Fluxo de review humano

O worker respeita o ciclo funcional de review.

Regras atuais:

- task em `review` não é reexecutada
- task com `review_status=approved` não é reexecutada
- task com `review_status=needs_adjustment` é reexecutada
- feedback humano encontrado no payload bruto da task é incluído no prompt incremental

No reporte final:

- sucesso técnico é enviado com `status=review`
- falha é enviada com `status=failed`
- o worker nunca marca task como `done`

## Reporte final

Ao concluir uma task, o worker envia:

```text
POST /api/tasks/{task}/finish
```

Payloads suportados hoje:

### Sucesso técnico

- `worker_id`
- `status=review`
- `execution_summary`
- opcionalmente `branch_name`
- `commit_sha`
- `pull_request_url`
- `logs_path`
- `metadata`

### Falha

- `worker_id`
- `status=failed`
- `execution_summary`
- `failure_reason`
- opcionalmente `logs_path`
- `metadata`

## Estrutura principal

- `app/Console/Commands/WorkerRunCommand.php` — entrypoint principal
- `app/Services/Api` — integração HTTP com a API
- `app/Services/Workspace` — gestão do workspace por task
- `app/Services/Repository` — resolução e sincronização de repositório
- `app/Services/Prompt` — geração de prompt inicial e incremental
- `app/Services/Execution` — executor do Cursor, loop iterativo, heartbeat e orquestração
- `app/Services/Validation` — validações automáticas
- `app/Services/Reporting` — reporte final para a API
- `app/DTOs` — DTOs e resultados estruturados

## Testes

Rodar toda a suíte:

```bash
php artisan test
```

Ou:

```bash
composer test
```

Rodar somente unitários:

```bash
php artisan test tests/Unit
```

Rodar o teste do command principal:

```bash
php artisan test tests/Feature/WorkerRunCommandTest.php
```

## Docker

Build e subida:

```bash
docker compose build
docker compose up -d
```

Execução de comandos no container:

```bash
docker compose exec worker composer install
docker compose exec worker php artisan list
docker compose exec worker php artisan worker:run --once
docker compose exec worker php artisan test
```

Execução pontual:

```bash
docker compose run --rm worker php artisan worker:run --once
```

O ambiente Docker do worker monta automaticamente os arquivos de SSH e git do host:

- `${HOME}/.ssh` em `/root/.ssh` como somente leitura
- `${HOME}/.gitconfig` em `/root/.gitconfig` como somente leitura
- `${HOME}/.codex` em `/root/.codex`

Isso permite que `git clone`, `git fetch` e `git push` dentro do container usem a mesma configuracao SSH do host.
Tambem permite que o Codex CLI dentro do container reutilize a autenticacao e configuracao do host.

Pre-requisitos no host:

- a chave SSH ja precisa estar configurada e funcional fora do container
- `known_hosts` precisa conter os hosts remotos usados pelos repositorios
- se o `~/.ssh/config` usar aliases ou regras especificas, elas passam a valer no container tambem
- o login do Codex no host precisa ja existir em `~/.codex`

O `Dockerfile` instala o Codex CLI diretamente na imagem com `npm install -g @openai/codex`, entao o binario `codex` passa a existir dentro do container.
O `Dockerfile` tambem instala `docker` e `docker-compose`, para que o worker possa subir o ambiente definido em `environment_profile.docker_compose_yml`.

Para evitar erros de sandbox como `bwrap: No permissions to create a new namespace`, o servico Docker do worker tambem sobe com:

- `cap_add: SYS_ADMIN`
- `security_opt: seccomp:unconfined`
- `security_opt: apparmor:unconfined`

Isso reduz bloqueios de namespace dentro do container quando o Codex tenta operar o workspace.

O servico `worker` tambem monta:

- `/var/run/docker.sock` em `/var/run/docker.sock`

Isso permite que o container do worker controle o Docker da maquina host e execute `docker compose up`, `exec` e `down` para a task.

No ambiente Docker deste projeto, o worker usa `docker-compose` como binario interno para compatibilidade com a imagem base. O service `worker` ja exporta `WORKER_DOCKER_BINARY=docker-compose`.

## Observações operacionais

- o workspace é isolado por task
- o cleanup do workspace é configurável
- o worker não executa tasks em paralelo nesta fase
- o command principal não concentra a lógica pesada; a orquestração fica em services
- arrays crus da API são convertidos para DTOs antes de serem usados no domínio

## Licença

MIT.
