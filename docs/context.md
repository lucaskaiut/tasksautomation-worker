# Worker de Execução de Tasks — Especificação Técnica

## Objetivo

Este documento define os requisitos funcionais e técnicos do **Worker de Execução de Tasks**.

Ele deve ser tratado como a **fonte de verdade** para implementação.

O worker é responsável por:

- consultar a API de gestão de tasks
- reservar tasks para execução (claim)
- preparar o ambiente local de execução
- construir o contexto da task
- executar o agente (Cursor)
- validar resultados
- iterar automaticamente quando necessário
- reportar status e resultados para a API

---

# Visão geral da arquitetura

O sistema é composto por:

- API de gestão de tasks (já existente)
- Worker (este projeto)
- Agente executor (Codex CLI)

O fluxo geral é:

1. Worker consulta a API
2. Worker faz claim de uma task
3. Worker prepara execução local
4. Worker executa o agente
5. Worker valida resultado
6. Worker itera (se necessário)
7. Worker envia resultado para API

---

# Responsabilidades do Worker

O worker é um **orquestrador determinístico**.

Ele NÃO deve:
- tomar decisões de negócio complexas
- “inventar” regras
- depender de contexto implícito

Ele DEVE:
- executar fluxos previsíveis
- aplicar regras definidas
- controlar o ciclo de execução
- garantir consistência e reprodutibilidade

---

# Requisitos funcionais

## 1. Polling de tasks

O worker deve consultar periodicamente a API para buscar tasks disponíveis.

### Endpoint utilizado
- `POST /api/tasks/claim`

### Comportamento
- deve enviar `worker_id`
- deve receber uma task ou nenhum resultado
- se não houver task, aguardar e tentar novamente

### Configuração
- intervalo de polling configurável
- worker deve rodar continuamente

---

## 2. Claim de task

O worker deve:

- solicitar uma task elegível
- receber uma task já reservada
- nunca executar tasks sem claim

---

## 3. Heartbeat

Durante a execução, o worker deve enviar heartbeat periodicamente.

### Endpoint
- `POST /api/tasks/{task}/heartbeat`

### Frequência
- a cada 5 a 15 segundos (configurável)

### Objetivo
- manter a task marcada como viva
- renovar o lock da execução

---

## 4. Workspace por task

Cada task deve ser executada em um ambiente isolado.

### Estrutura padrão

```text
/storage/workspaces/{task_id}/
  repo/
  context/
  logs/
  task.json
  prompt.md
````

### Regras

* nunca reutilizar workspace entre tasks
* workspace deve ser limpo após execução (configurável)

---

## 5. Resolução do repositório

O worker deve obter o repositório a partir da task:

```json
project.repository_url
project.default_branch
```

### Estratégias suportadas

#### Opção A (recomendada inicialmente)

* worker clona, configura e atualiza automaticamente, usando docker

---

## 6. Construção do contexto da task

O worker deve transformar a task da API em um contexto estruturado local.

### Arquivos obrigatórios

#### raw-task-response.json

Payload bruto da API

#### task.json

Versão normalizada da task

#### prompt.md

Prompt principal para o agente

---

## 7. Execução do agente (Cursor)

O worker deve executar o Cursor via CLI.

### Requisitos

* execução não interativa
* execução dentro do diretório do repositório
* captura de stdout/stderr
* captura de exit code

### Responsabilidade do worker

* montar o comando
* executar processo
* capturar logs
* detectar falha/sucesso

---

## 8. Loop iterativo (ESSENCIAL)

O worker NÃO deve executar apenas uma vez.

Ele deve implementar um loop de iteração:

```text
executar → validar → corrigir → repetir
```

---

## 9. Validação automática

Após cada execução, o worker deve validar o resultado.

### Exemplos de validação

* `php artisan test`
* build frontend
* scripts definidos no perfil de ambiente

### Resultado

* sucesso → encerra loop
* falha → gera nova iteração

---

## 10. Correção incremental

Se a execução falhar:

* o worker deve gerar um novo prompt
* incluir erros capturados
* pedir correção da implementação anterior

### Regra

* nunca pedir reimplementação do zero
* sempre pedir ajuste incremental

---

## 11. Limite de tentativas

O worker deve ter um limite de iterações por execução.

### Configuração

* `max_attempts_per_execution` (ex: 3 a 5)

### Comportamento

* se exceder → marcar como falha

---

## 12. Publicação do resultado

Ao finalizar, o worker deve enviar o resultado para a API.

### Cenários

#### Sucesso técnico

* status → `review`
* enviar resumo

#### Falha

* status → `failed`
* enviar erro

---

## 13. Integração com revisão humana

O worker deve respeitar o fluxo de revisão:

* nunca marcar task como `done`
* apenas como `review`

Se a task voltar como:

* `needs_adjustment`

o worker deve:

* pegar novamente a task
* incluir feedback humano no contexto
* executar nova iteração

---

# Requisitos não funcionais

## Determinismo

O worker deve ser previsível:

* mesma task → mesmo comportamento

---

## Isolamento

Cada execução deve ser isolada:

* workspace independente
* sem vazamento de estado

---

## Observabilidade

O worker deve registrar:

* logs de execução
* comandos executados
* resultados
* erros

---

## Tolerância a falhas

O worker deve suportar:

* falha de rede
* falha do agente
* timeout
* crash do processo

---

## Configurabilidade

Configurações devem ser centralizadas:

* API base URL
* token
* polling interval
* heartbeat interval
* max attempts
* caminhos locais
* comandos de validação
* binário do Cursor

---

# Estrutura interna do worker

## Camadas

### Console

* commands de execução

### Services

* comunicação com API
* gerenciamento de workspace
* construção de contexto
* execução do Cursor
* validação
* iteração

### Jobs (opcional)

* execução de task

---

# Fluxo completo

```text
loop:
  claim task
  if task:
    preparar workspace
    while tentativas < max:
      executar agente
      validar
      if sucesso:
        reportar sucesso
        break
      else:
        gerar prompt de correção
    if falhou:
      reportar falha
  sleep
```

---

# Regras importantes

* nunca executar sem claim
* nunca pular validação
* nunca sobrescrever contexto anterior
* nunca ignorar feedback humano
* nunca marcar task como done diretamente
* sempre registrar logs
* sempre respeitar limite de tentativas

---

# Integração com TaskReview

Quando existir revisão com:

### approved

* não reexecutar

### needs_adjustment

* incluir feedback no contexto
* executar nova rodada

---

# Critérios de conclusão

O worker está completo quando:

* consegue consumir tasks da API
* consegue executar tasks automaticamente
* implementa loop iterativo
* valida execução
* respeita fluxo de review
* envia resultados corretamente
* funciona de forma contínua

---

# Resumo

O worker não é apenas um executor.

Ele é um **orquestrador de execução iterativa com validação e feedback**, responsável por garantir que tasks evoluam até um estado tecnicamente válido e pronto para revisão funcional.
