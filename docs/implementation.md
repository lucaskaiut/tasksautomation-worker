# Implementar o Worker de Execução de Tasks

Use `docs/context.md` como **fonte de verdade absoluta** para esta implementação.

Este projeto é um **worker/orquestrador determinístico** responsável por consumir tasks da API, preparar o contexto local, executar o agente via Codex CLI, validar resultados, iterar automaticamente quando necessário e reportar o resultado final para a API.

A implementação deve seguir **SDD (Spec-Driven Development)**, em etapas incrementais, com **Definition of Done (DoD)** claro, objetivo e testável em cada etapa.

---

# Regras gerais de execução

- leia `docs/context.md` antes de implementar qualquer coisa
- trate esse documento como contrato funcional e técnico
- implemente em **Laravel 13**
- o projeto deve ser **console-first**
- não implemente painel web
- não use Blade
- não crie abstrações desnecessárias
- mantenha arquitetura sólida e pragmática
- controllers HTTP só existirão se forem realmente necessários
- priorize:
  - Commands
  - Services
  - DTOs
  - configuração centralizada
  - testes automatizados
- o worker deve ser **determinístico**
- o worker deve ser **configurável**
- o worker deve ser **orientado a execução contínua**
- cada etapa deve ser concluída completamente antes de avançar para a próxima
- ao final de cada etapa, valide se o DoD foi cumprido
- não pule etapas
- não implemente recursos além do necessário de cada etapa sem necessidade real

---

# Objetivo final

Ao final da implementação, o projeto deve ser capaz de:

- consultar a API
- fazer claim de uma task
- criar um workspace isolado
- materializar contexto local da task
- executar o Codex CLI
- rodar validações automáticas
- iterar automaticamente em caso de falha técnica
- enviar heartbeat durante a execução
- reportar sucesso ou falha para a API
- respeitar o fluxo de review humano descrito no contexto

---

# Stack e requisitos técnicos

- Laravel 13
- PHP compatível com Laravel 13
- arquitetura baseada em Console + Services
- integração HTTP com a API
- integração com Codex CLI via processo local
- suporte a execução contínua
- testes automatizados
- configuração via `.env` e arquivos `config/*`

---

# Estrutura arquitetural esperada

Organize o projeto com separação clara de responsabilidades.

Estrutura sugerida:

```text
app/
  Console/
    Commands/
  DTOs/
  Services/
    Api/
    Workspace/
    Prompt/
    Execution/
    Validation/
    Reporting/
  Support/
    Enums/
    ValueObjects/
config/
tests/
````

Se fizer sentido, adapte nomes, mas mantenha a separação de responsabilidades.

---

# Etapa 1 — Bootstrap do projeto e configuração base

## Objetivo

Criar a base do projeto Laravel 13 com a estrutura inicial necessária para o worker.
O projeto já está inicializado com Laravel 13 blank

## Escopo

* configurar ambiente base usando docker
* criar estrutura de pastas coerente
* criar arquivo de configuração do worker
* preparar leitura de variáveis de ambiente
* criar README inicial com setup básico

## Requisitos

Implemente:

* projeto Laravel funcional
* `config/worker.php`
* variáveis de ambiente para:

  * API base URL
  * API token
  * worker id
  * polling interval
  * heartbeat interval
  * max attempts por execução
  * path base dos workspaces
  * binário do Cursor
  * timeout do processo
  * limpeza de workspace habilitada/desabilitada
* classes base e namespaces organizados
* `README.md` com instruções mínimas de setup e execução

## DoD

Considere esta etapa concluída somente quando:

* o projeto Laravel 13 estiver inicializado corretamente
* a aplicação conseguir carregar `config/worker.php`
* as variáveis de ambiente estiverem centralizadas e acessíveis
* existir uma estrutura inicial de diretórios coerente com a arquitetura do worker
* o `README.md` explicar como instalar dependências e executar localmente
* o projeto passar em um teste básico de bootstrap

---

# Etapa 2 — Cliente da API e integração HTTP

## Objetivo

Implementar a comunicação do worker com a API de gestão de tasks.

## Escopo

* criar cliente HTTP da API
* suportar claim de task
* suportar heartbeat
* suportar reporte de sucesso
* suportar reporte de falha

## Requisitos

Implemente um serviço central para API, por exemplo `TaskApiClient`, com métodos equivalentes a:

* `claimTask(): ?TaskData`
* `heartbeat(int $taskId): void`
* `completeTask(int $taskId, array $payload): void`
* `failTask(int $taskId, array $payload): void`

### Regras

* usar autenticação por token
* encapsular toda a comunicação HTTP em uma camada própria
* tratar erros de rede de forma previsível
* não espalhar chamadas HTTP pelo projeto
* mapear o payload da task para DTOs internos

## DoD

Considere esta etapa concluída somente quando:

* existir um serviço único responsável pela comunicação com a API
* o worker conseguir fazer claim de task
* o worker conseguir enviar heartbeat
* o worker conseguir reportar sucesso e falha
* os payloads da API forem convertidos em DTOs internos claros
* houver testes cobrindo:

  * claim com task retornada
  * claim sem task
  * heartbeat com sucesso
  * fail/complete com sucesso
  * falha de autenticação
  * falha de rede ou resposta inesperada

---

# Etapa 3 — Modelagem interna e DTOs

## Objetivo

Criar a representação interna tipada dos dados usados pelo worker.

## Escopo

* criar DTOs para task, projeto, perfil de ambiente e resultado de execução
* normalizar acesso aos dados vindos da API

## Requisitos

Implemente DTOs claros e tipados, ou estrutura equivalente, para no mínimo:

* TaskData
* ProjectData
* EnvironmentProfileData
* ExecutionResult
* ValidationResult
* IterationContext

### Regras

* não trabalhar com arrays soltos nas regras de negócio
* manter tipagem explícita
* permitir acesso claro aos campos da task e seus relacionamentos
* encapsular parsing de payload da API

## DoD

Considere esta etapa concluída somente quando:

* os dados da API não forem mais usados como arrays crus dentro da lógica do worker
* existirem DTOs ou objetos equivalentes cobrindo task, projeto e perfil de ambiente
* o código de execução conseguir trabalhar com objetos internos previsíveis
* houver testes cobrindo mapeamento de payload completo da API para DTOs

---

# Etapa 4 — Workspace isolado por task

## Objetivo

Implementar a criação e gestão do workspace local da task.

## Escopo

* criar pasta isolada por task
* criar subpastas padrão
* salvar contexto bruto e normalizado
* preparar arquivos de execução

## Requisitos

Implemente um serviço responsável por:

* criar `storage/workspaces/{task_id}/`
* criar subpastas:

  * `repo/`
  * `context/`
  * `logs/`
* salvar:

  * `raw-task-response.json`
  * `task.json`
  * `prompt.md`
* retornar um objeto com os caminhos do workspace
* limpar workspace ao final, se configurado

### Regras

* nunca reutilizar workspace entre tasks
* nunca misturar arquivos de tasks diferentes
* criar estrutura previsível e fácil de inspecionar
* persistir artefatos suficientes para debug

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir criar um workspace completo por task
* os arquivos obrigatórios forem gerados corretamente
* os diretórios forem previsíveis e consistentes
* a limpeza configurável funcionar corretamente
* houver testes cobrindo:

  * criação do workspace
  * escrita dos arquivos esperados
  * limpeza habilitada
  * limpeza desabilitada

---

# Etapa 5 — Resolução do repositório local

## Objetivo

Resolver corretamente a estratégia e o diretório base do repositório da task.

## Escopo

* identificar como o repositório será obtido localmente
* suportar mapeamento para repositório já existente
* preparar dados necessários para clone/sync automático na etapa seguinte

## Requisitos

Implemente a resolução da origem local do repositório a partir dos dados da task.

### Estratégias que devem ficar previstas

* repositório já existente localmente
* repositório que será clonado/sincronizado automaticamente pelo worker

### Necessário

* configuração por projeto slug e/ou URL do repositório, quando aplicável
* uso de `project.repository_url` e `project.default_branch` como fonte de verdade da task
* serviço dedicado para resolver a estratégia de obtenção do repositório
* retorno estruturado indicando:

  * estratégia selecionada
  * caminho base esperado
  * URL do repositório
  * branch padrão
* falha clara quando não houver informação suficiente para resolver a estratégia

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir resolver a estratégia de obtenção do repositório a partir da task
* o worker conseguir resolver os dados necessários para usar repositório local ou clone automático
* houver falha clara e tratada quando a estratégia não puder ser determinada
* a resolução não depender de lógica espalhada pelo código
* houver testes cobrindo:

  * resolução para repositório local existente
  * resolução para repositório com clone automático
  * projeto não configurado ou sem dados suficientes

---

# Etapa 6 — Clone e sincronização automática do repositório

## Objetivo

Garantir que o código do projeto esteja disponível localmente de forma automática e previsível antes da execução.

## Escopo

* clonar o repositório quando ele ainda não existir localmente
* sincronizar o repositório quando ele já existir em cache local
* preparar cópia utilizável no workspace da task
* garantir branch correta para execução

## Requisitos

Implemente um serviço dedicado, por exemplo `RepositorySyncService`, responsável por:

* receber o resultado da etapa de resolução do repositório
* clonar o repositório a partir de `project.repository_url` quando necessário
* garantir checkout da `project.default_branch`
* atualizar o repositório local antes da execução, quando a estratégia for automática
* disponibilizar o repositório final no `workspace/repo`
* encapsular execução de comandos de git/docker necessários

### Estratégia esperada nesta etapa

* usar clone/sync automático como fluxo principal recomendado
* manter suporte ao repositório já existente localmente como fallback configurável

### Regras

* não espalhar comandos git pelo código
* não depender de preparação manual quando a estratégia automática estiver habilitada
* falhas de clone, checkout ou atualização devem ser explícitas e tratadas
* garantir que o workspace use uma cópia previsível e isolada para a task
* evitar mutar diretamente um checkout compartilhado sem política clara

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir clonar automaticamente um repositório ainda não disponível localmente
* o worker conseguir atualizar um repositório já conhecido antes da execução
* o worker conseguir disponibilizar o repositório correto dentro do workspace da task
* falhas de clone, autenticação, checkout e diretório inválido forem tratadas de forma previsível
* houver testes cobrindo:

  * clone inicial com sucesso
  * atualização de repositório existente
  * checkout da branch padrão
  * falha de clone
  * falha de checkout

---

# Etapa 7 — Builder de prompt e contexto de execução

## Objetivo

Construir o prompt base e o contexto que será enviado ao Cursor.

## Escopo

* gerar prompt inicial com base na task
* incluir contexto do projeto
* incluir entregáveis e restrições
* incluir instruções operacionais para o executor

## Requisitos

Implemente um serviço de construção de prompt que gere um `prompt.md` consistente a partir de:

* título
* descrição
* entregáveis
* restrições
* dados do projeto
* regras globais do projeto, se existirem
* perfil de ambiente, se existir
* feedback humano de review, quando houver
* instruções para manter alterações incrementais

### Regras

* o prompt deve ser estruturado em seções claras
* o prompt deve ser reutilizável
* o prompt inicial e prompts de iteração devem ser distintos
* nunca pedir “reimplementar do zero” nas iterações
* preservar o que já funciona

## DoD

Considere esta etapa concluída somente quando:

* existir um builder dedicado para prompt inicial
* existir suporte para prompt de correção incremental
* o arquivo `prompt.md` for gerado corretamente no workspace
* o prompt incluir todos os dados relevantes da task
* houver testes cobrindo geração do prompt inicial e de prompt de iteração

---

# Etapa 8 — Executor do Codex CLI

## Objetivo

Executar o Codex CLI localmente de forma controlada e observável.

## Escopo

* montar comando do Cursor
* executar processo
* capturar stdout/stderr
* capturar exit code
* persistir logs

## Requisitos

Implemente um serviço, por exemplo `CursorExecutorService`, responsável por:

* validar existência do binário do Cursor
* montar o comando de execução
* executar o processo no diretório do repositório
* respeitar timeout configurado
* salvar stdout e stderr em arquivos de log
* retornar `ExecutionResult`

### Regras

* não executar o Cursor sem contexto preparado
* não espalhar `Process` pelo código
* encapsular completamente a execução do agente
* garantir logs úteis para debug

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir executar o Codex CLI
* stdout, stderr e exit code forem capturados
* logs forem escritos em disco
* timeout configurável funcionar
* falhas de processo forem tratadas de forma previsível
* houver testes cobrindo:

  * montagem do comando
  * execução bem-sucedida
  * falha de processo
  * binário inexistente
  * timeout

---

# Etapa 9 — Camada de validação automática

## Objetivo

Validar tecnicamente o resultado após cada execução do agente.

## Escopo

* executar comandos de validação
* centralizar validações
* retornar resultado estruturado

## Requisitos

Implemente um serviço de validação que:

* aceite lista de comandos de validação
* rode os comandos no diretório do repositório
* capture sucesso/falha
* capture saída
* retorne `ValidationResult`

### Fontes das validações

* configuração global do worker
* perfil de ambiente da task, se houver

### Regras

* não embutir validações diretamente no loop principal
* suportar múltiplos comandos
* parar ou continuar conforme política definida
* registrar logs da validação

## DoD

Considere esta etapa concluída somente quando:

* existir um serviço dedicado à validação
* o worker conseguir rodar validações automáticas após a execução
* o retorno da validação for estruturado e utilizável pelo loop
* houver testes cobrindo:

  * todos os comandos passam
  * um comando falha
  * captura de saída
  * comportamento com lista vazia de validações

---

# Etapa 10 — Loop iterativo de execução

## Objetivo

Implementar o ciclo automático de execução, validação e correção incremental.

## Escopo

* executar o agente
* validar
* gerar novo prompt em caso de falha
* repetir até sucesso ou limite de tentativas

## Requisitos

Implemente o loop descrito em `docs/context.md`:

```text
executar → validar → corrigir → repetir
```

### Regras

* usar `max_attempts_per_execution`
* cada iteração deve registrar seus artefatos
* em caso de falha técnica, gerar prompt incremental com base no erro
* nunca reiniciar a task “do zero”
* se a validação passar, encerrar loop com sucesso técnico
* se esgotar tentativas, encerrar com falha

### Necessário

* coordenador de iterações, por exemplo `ExecutionLoopService`
* integração entre:

  * prompt builder
  * executor do Cursor
  * validador
  * workspace
* persistência de contexto por iteração

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir executar múltiplas iterações automaticamente
* falhas de validação gerarem novas tentativas
* o prompt incremental incluir erro técnico da iteração anterior
* o loop respeitar o limite de tentativas
* o loop encerrar corretamente em sucesso ou falha
* houver testes cobrindo:

  * sucesso na primeira tentativa
  * sucesso após uma ou mais correções
  * falha após atingir o limite máximo
  * geração de prompt incremental com erro anterior

---

# Etapa 11 — Heartbeat durante execução

## Objetivo

Manter a task viva na API enquanto a execução estiver ocorrendo.

## Escopo

* envio periódico de heartbeat
* integração com execução longa
* tolerância a falhas transitórias

## Requisitos

Implemente heartbeat periódico durante a execução da task.

### Regras

* enviar heartbeat com intervalo configurável
* atualizar heartbeat durante iterações longas
* falha de heartbeat deve ser tratada de forma previsível
* o worker não deve parar imediatamente por uma falha transitória isolada de heartbeat, salvo se a política assim definir

### Estratégia

Pode ser implementado com:

* timer/controlador interno
* checkpoints entre etapas
* ou abordagem equivalente confiável

## DoD

Considere esta etapa concluída somente quando:

* o worker enviar heartbeat durante a execução
* a frequência do heartbeat for configurável
* o heartbeat não estiver acoplado diretamente ao código de negócio
* falhas de heartbeat forem tratadas
* houver testes cobrindo:

  * heartbeat enviado com sucesso
  * múltiplos heartbeats em execução longa
  * falha de heartbeat com tratamento previsível

---

# Etapa 12 — Reporte de sucesso e falha para a API

## Objetivo

Publicar o resultado da execução na API de gestão.

## Escopo

* reportar sucesso técnico
* reportar falha
* enviar resumo da execução

## Requisitos

Ao finalizar a execução:

### Em sucesso técnico

* reportar task como pronta para review
* enviar resumo da execução
* incluir metadados úteis

### Em falha

* reportar falha
* incluir motivo resumido
* incluir contexto da falha

### Metadados úteis

* número de tentativas
* duração
* logs relevantes
* branch/PR/commit se existirem

### Regras

* o worker nunca deve marcar task como `done`
* sucesso técnico deve resultar em `review`
* falha deve ser explicitamente reportada

## DoD

Considere esta etapa concluída somente quando:

* o worker conseguir reportar sucesso técnico
* o worker conseguir reportar falha
* o payload de reporte for consistente
* sucesso técnico nunca marcar task como `done`
* houver testes cobrindo:

  * fluxo de sucesso
  * fluxo de falha
  * envio de resumo estruturado

---

# Etapa 13 — Suporte ao fluxo de review humano

## Objetivo

Respeitar o ciclo de revisão funcional definido pela aplicação principal.

## Escopo

* não concluir tasks automaticamente
* reprocessar tasks com ajuste solicitado
* incluir feedback humano no contexto da nova execução

## Requisitos

O worker deve respeitar os estados e revisões descritos no contexto.

### Regras

* task em `review` não deve ser reexecutada
* task aprovada não deve ser reexecutada
* task que voltar com necessidade de ajuste deve ser executada novamente
* quando existir feedback humano:

  * incluir esse feedback no prompt
  * instruir correção incremental
  * preservar o que já está funcionando

## DoD

Considere esta etapa concluída somente quando:

* o worker não marcar task como `done`
* o worker respeitar tasks que aguardam review
* o worker conseguir incluir feedback humano em uma nova execução
* o prompt refletir corretamente a revisão anterior
* houver testes cobrindo:

  * task nova sem feedback
  * task reaberta com `needs_adjustment`
  * inclusão do feedback humano no contexto da nova iteração

---

# Etapa 14 — Command principal e execução contínua

## Objetivo

Criar a entrada principal do worker para rodar continuamente.

## Escopo

* command para polling contínuo
* integração das etapas anteriores
* loop principal do worker

## Requisitos

Implemente um command principal, por exemplo:

* `worker:run`

Esse command deve:

* iniciar ciclo contínuo
* fazer polling da API
* tentar claim
* executar task quando existir
* aguardar intervalo configurado quando não houver task
* registrar logs úteis no console

### Regras

* não executar tasks em paralelo nesta fase, a menos que o contexto exija explicitamente
* manter implementação simples e previsível
* encapsular a lógica pesada em services

## DoD

Considere esta etapa concluída somente quando:

* existir um command principal funcional
* o worker conseguir rodar continuamente
* o fluxo completo integrar claim, workspace, resolução/sync de repositório, executor, validação, loop, heartbeat e reporte
* o command não concentrar lógica de negócio
* houver testes cobrindo o fluxo principal com doubles/mocks dos serviços

---

# Etapa 15 — Testes, robustez e acabamento final

## Objetivo

Consolidar a implementação, garantir cobertura adequada e revisar a robustez operacional.

## Escopo

* revisar arquitetura
* eliminar acoplamentos indevidos
* reforçar testes
* revisar README final

## Requisitos

Antes de concluir:

* revise nomes e responsabilidades
* garanta que os serviços estejam coesos
* elimine duplicações
* garanta que falhas comuns tenham tratamento previsível
* finalize `README.md` com instruções completas

### README final deve conter

* objetivo do worker
* requisitos
* configuração via `.env`
* como executar
* como rodar testes
* como mapear repositórios locais
* como configurar clone/sync automático de repositórios
* como configurar o Codex CLI

## DoD

Considere esta etapa concluída somente quando:

* os testes relevantes estiverem implementados
* a arquitetura estiver coesa
* o fluxo completo estiver executável
* o `README.md` estiver suficiente para uso local
* o projeto estiver pronto para ser usado como worker real

---

# Restrições

* não implementar interface web
* não usar Blade
* não acoplar o worker à UI do Cursor
* não tratar arrays crus da API como domínio interno
* não colocar lógica pesada em Commands
* não pular o loop iterativo
* não marcar tasks como `done`
* não ignorar feedback humano quando existir
* não reutilizar workspace entre tasks

---

# Critério final de conclusão

Considere o projeto concluído apenas quando:

* todas as etapas acima estiverem implementadas
* cada etapa cumprir seu DoD
* o worker estiver operacional localmente
* o fluxo completo descrito em `docs/context.md` estiver coberto pela implementação
* a base estiver limpa, organizada e preparada para evolução futura

Implemente etapa por etapa, validando o DoD ao final de cada uma antes de avançar.
