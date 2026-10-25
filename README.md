<p align="center">
  <img src="docs/images/logo.png" width="200" alt="Vau">
</p>

<h1 align="center">Vau<br><sub><sup><sup><sub>Copyright (©) 2026 Erik Pimenta</sub></sup></sup></sub></h1>

<p align="center">
  Servidor de CI/CD que recebe webhooks do GitHub e do GitLab, clona o commit e roda testes e deploy em containers Docker isolados.<br>
  Cada etapa nasce num container novo, com limites de CPU e memória, e o log aparece ao vivo no painel. O deploy só sai quando tudo passa.
</p>

<p align="center">
  PHP 8.2+ · zero dependências em produção · Docker · SQLite, MySQL ou PostgreSQL · GitHub e GitLab · API REST · Prometheus · PHPUnit · PHPStan · GitHub Actions
</p>

---

> **Manual completo:** [`Manual-Vau.pdf`](Manual-Vau.pdf) explica instalação, configuração, conexão com GitHub e GitLab, o arquivo `.vau.yml`, cada tela, a arquitetura, a segurança, a API, a operação e a solução de problemas, passo a passo (cópia em [`docs/`](docs/Manual-Vau.pdf)).

> **Banco de dados:** [`Banco-de-Dados-Vau.pdf`](Banco-de-Dados-Vau.pdf) traz o diagrama de relacionamentos, todas as tabelas com campos, tipos, chaves e restrições, os índices, os ciclos de status, as decisões de arquitetura e como conectar MySQL ou PostgreSQL no lugar do SQLite (cópia em [`docs/`](docs/Banco-de-Dados-Vau.pdf)).

<p align="center">
  <img src="docs/images/tela-build.png" alt="Build com etapas e log ao vivo">
</p>

## O que o Vau faz

- **Webhooks do GitHub e do GitLab** com assinatura conferida (HMAC SHA-256 no GitHub, `X-Gitlab-Token` no GitLab), entregas repetidas descartadas e limite de requisições por IP. Push, tag e pull/merge request geram build; ping, PR fechado e branch apagada são registrados e ignorados.
- **Isolamento por container**: cada build ganha um volume Docker próprio; o clone e cada etapa rodam em containers descartáveis (`--rm`), com CPU, memória, processos e rede limitados, sem novos privilégios e só com as capacidades mínimas do kernel.
- **Pipeline no repositório** (`.vau.yml`, `.vau.yaml` ou `.vau.json`) ou um pipeline padrão cadastrado no projeto: imagem por etapa, variáveis, etapas por branch, `allow_failure` e deploy só para as branches ou tags liberadas.
- **Segredos** cifrados no banco (libsodium), entregues como variáveis de ambiente sem passar pela linha de comando, mascarados no log e retidos em pull requests de forks. Segredos "somente deploy" não chegam às etapas de teste.
- **Fila no próprio banco** consumida por quantos workers você quiser, em uma ou várias máquinas, com reserva atômica, cancelamento, repetição, cancelamento automático de commits superados e recuperação de builds órfãos.
- **Painel** com builds por dia, taxa de sucesso, duração média e p95, workers, log ao vivo por etapa, histórico de entregas de webhook com reenvio, segredos, usuários e auditoria.
- **Status no commit** (o ícone ao lado do commit e o check do pull request) e **avisos no chat** (Slack, Discord, Google Chat) em falhas e recuperações.
- **API REST** com tokens, **selo SVG** para o README, **/health** e **/metrics** no formato Prometheus.
- **Identidade "caixa única"**, a mesma do [Nexo](https://github.com/ravelroot/Retrieval-Augmented-QA-System): fundo, menu e cabeçalhos impressos na mesa em `#F5F4F4`, conteúdo dividindo paredes finas dentro de uma só caixa branca, números na tampa preta, títulos e números em Bricolage Grotesque fina e condensada, textos em Instrument Sans, código em IBM Plex Mono e status como um ponto de cor. Paleta `#FFFFFF`, `#4D4B4B`, `#000000`, `#333232` e `#BAB7B6`.
- **Tema claro e escuro**, layout responsivo para celular. A logo é sempre em preto e branco.

## Como um commit vira deploy

```mermaid
flowchart LR
    G[Push ou PR<br/>GitHub / GitLab] --> W["POST /webhook/{slug}"]
    W --> S{Assinatura<br/>válida?}
    S -- não --> R[401 e registro<br/>da entrega]
    S -- sim --> F{Evento e branch<br/>passam nos filtros?}
    F -- não --> I[200 ignorado]
    F -- sim --> Q[(Fila no banco)]
    Q --> K[Worker reserva<br/>o build]
    K --> C[Container de clone<br/>volume vau-bID]
    C --> P[Lê .vau.yml]
    P --> E[Etapas em containers<br/>novos e limitados]
    E --> D{Tudo passou e a<br/>branch libera deploy?}
    D -- sim --> Y[Deploy com<br/>segredos de deploy]
    D -- não --> Z[Resultado]
    Y --> Z
    Z --> N[Status no commit<br/>e aviso no chat]
```

1. **Nada roda no servidor**: o código do repositório só executa dentro de containers descartáveis; o volume do build é apagado no fim, com sucesso ou falha.
2. **Limites por projeto**: CPU (`--cpus`), memória sem swap extra (`--memory` e `--memory-swap`), processos (`--pids-limit`), `/tmp` em memória e rede desligável (`--network none`).
3. **Menos privilégios**: `--security-opt no-new-privileges` e `--cap-drop ALL` devolvendo só `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETGID` e `SETUID` (o mínimo para gerenciadores de pacote).
4. **Tempo limite** do build inteiro, dividido entre as etapas; o container que estoura é encerrado com `docker kill`.
5. **Segredos protegidos**: valores entram pelo ambiente do processo (nunca no `docker run` visível no `ps`), saem mascarados do log (inclusive codificados em URL e base64) e não chegam a forks.

## Como rodar

Pré-requisitos: **PHP 8.2 ou mais recente** (extensões `pdo_sqlite`, `sodium`, `mbstring` e `curl`; `pcntl` recomendada) e **Docker** na máquina do worker. Não há dependências do Composer em produção.

**Windows:** dê dois cliques em `iniciar.cmd`.
**Linux / macOS:** `./iniciar.sh`

Os scripts copiam `.env.example` para `.env`, criam a chave de criptografia, o banco SQLite e o usuário admin, iniciam um worker (se o Docker responder) e sobem o painel em **http://127.0.0.1:8080**.

Manualmente:

```bash
php bin/vau setup          # .env, chave, banco e usuário admin
php bin/vau doctor         # confere PHP, extensões, banco e Docker
php bin/vau worker         # terminal 1: consome a fila de builds
php bin/vau serve          # terminal 2: painel em http://127.0.0.1:8080
```

### Com Docker Compose (PostgreSQL)

```bash
docker compose up -d --build
docker compose up -d --scale worker=3   # mais builds em paralelo
```

O painel sobe em **http://localhost:8080**, o worker usa o Docker do host pelo `/var/run/docker.sock` e os dados ficam nos volumes `vau-storage` e `vau-db`.

### Escolha um modo

| Modo | Para quê | `.env` |
|---|---|---|
| **Docker** (padrão) | Produção: cada etapa num container isolado | `VAU_RUNTIME=docker` |
| **Local** | Desenvolver o próprio Vau ou testar sem Docker. **Não isola nada** | `VAU_RUNTIME=local` |
| **MySQL / MariaDB** | Banco compartilhado entre servidores | `VAU_DATABASE_URL=mysql://vau:senha@host:3306/vau` |
| **PostgreSQL** | Banco compartilhado entre servidores | `VAU_DATABASE_URL=pgsql://vau:senha@host:5432/vau` |

## Login

| Usuário | Senha | Perfil |
|---|---|---|
| `admin` | `Vau@2026` | Administrador |

Esse é o login pré-definido, criado pelo `setup` quando o banco não tem usuários (defina outro com `VAU_ADMIN_PASSWORD` antes do primeiro `setup`). Troque a senha em **Sistema e conta** no primeiro acesso. Perfis: **administrador** (tudo, inclusive usuários), **operador** (projetos, segredos e builds) e **leitor** (só consulta). Esqueceu a senha? Rode `php bin/vau user:password <usuario>` no servidor.

| Tela | |
|---|---|
| <img src="docs/images/tela-login.png" alt="Entrada"> | <img src="docs/images/tela-painel.png" alt="Painel"> |
| <img src="docs/images/tela-projeto-webhook.png" alt="Webhook do projeto"> | <img src="docs/images/tela-escuro.png" alt="Tema escuro"> |

## Conectar um repositório

1. Em **Projetos → Novo projeto**, informe nome, provedor, a URL HTTPS de clonagem e, para repositório privado ou status no commit, um token de acesso (GitHub: *fine-grained token* com `Contents: read` e `Commit statuses: write`; GitLab: token com `read_repository` e `api`).
2. Na aba **Webhook**, copie a URL e o segredo.
3. **GitHub:** *Settings → Webhooks → Add webhook*, `application/json`, eventos *Pushes* e *Pull requests*.
   **GitLab:** *Settings → Webhooks*, segredo em *Secret token*, eventos *Push*, *Tag push* e *Merge request*.
4. Crie o `.vau.yml` na raiz do repositório e faça um push.

```yaml
image: php:8.3-cli            # imagem padrão das etapas
timeout: 20                   # minutos
env:
  APP_ENV: testing
steps:
  - name: Dependências
    run: composer install --no-interaction --prefer-dist
  - name: Testes
    run: vendor/bin/phpunit
  - name: Front-end
    image: node:22-alpine
    run: |
      npm ci
      npm run build
deploy:                       # só se tudo passar, e só nestas branches/tags
  branches: [main, "tag:v*"]
  image: alpine:3.20
  run: ./scripts/deploy.sh    # recebe os segredos "somente deploy"
```

Mais exemplos em [`exemplos/`](exemplos/). Toda etapa recebe `CI`, `VAU_BUILD_ID`, `VAU_BUILD_NUMBER`, `VAU_BUILD_URL`, `VAU_PROJECT`, `VAU_TRIGGER`, `VAU_REF`, `VAU_BRANCH`, `VAU_TAG`, `VAU_COMMIT` e `VAU_PR`.

<p align="center">
  <img src="docs/images/tela-projetos.png" alt="Projetos com taxa de sucesso e duração dos últimos builds">
</p>

## Configuração

Todas as opções ficam no `.env` (modelo em [`.env.example`](.env.example)); variáveis do ambiente têm prioridade. As principais:

| Variável | Padrão | Função |
|---|---|---|
| `VAU_APP_URL` | `http://127.0.0.1:8080` | Endereço público, usado nos links de webhook, status e avisos |
| `VAU_DATABASE_URL` | `sqlite:storage/vau.sqlite` | SQLite, `mysql://...` ou `pgsql://...` |
| `VAU_APP_KEY` | gerada em `storage/.chave` | Chave que cifra os segredos (`php bin/vau key:generate`) |
| `VAU_RUNTIME` | `docker` | `docker` ou `local` |
| `VAU_CLONE_IMAGE` / `VAU_DEFAULT_IMAGE` | `alpine/git:latest` / `alpine:3.20` | Imagem do clone e imagem padrão das etapas |
| `VAU_CPUS` / `VAU_MEMORY` / `VAU_PIDS_LIMIT` | `1.0` / `1g` / `512` | Limites padrão dos containers |
| `VAU_DROP_CAPABILITIES` | `true` | Remove as capacidades do kernel além do mínimo |
| `VAU_CLONE_DEPTH` | `50` | Profundidade do clone raso |
| `VAU_MAX_LOG_MB` | `10` | Tamanho máximo do log de um build |
| `VAU_LOG_RETENTION_DAYS` | `30` | Idade a partir da qual o `prune` apaga builds |
| `VAU_WEBHOOK_RATE_LIMIT` | `120` | Webhooks por minuto por IP |
| `VAU_LOGIN_MAX_ATTEMPTS` / `VAU_LOGIN_LOCK_MINUTES` | `5` / `15` | Bloqueio de conta por tentativas |
| `VAU_METRICS_TOKEN` | vazio | Protege `/metrics` com `Bearer` |
| `VAU_WORKER_STALE_SECONDS` | `120` | Sem sinal do worker por esse tempo, o build vira erro |

A tela **Sistema e conta** mostra a configuração ativa, o diagnóstico do Docker, os usuários, os tokens e a auditoria.

<p align="center">
  <img src="docs/images/tela-sistema.png" alt="Sistema e conta">
</p>

## Banco de dados

O Vau cria e migra tudo sozinho. Quem for administrar os dados encontra em [`Banco-de-Dados-Vau.pdf`](Banco-de-Dados-Vau.pdf) (e no resumo em [`docs/banco-de-dados.md`](docs/banco-de-dados.md)) o diagrama, a função de cada tabela, os índices e como apontar para MySQL ou PostgreSQL. O DDL de cada banco está em [`docs/schema-postgresql.sql`](docs/schema-postgresql.sql), [`docs/schema-mysql.sql`](docs/schema-mysql.sql) e [`docs/schema-sqlite.sql`](docs/schema-sqlite.sql), e sai também de `php bin/vau schema:dump <sqlite|mysql|pgsql>`.

```mermaid
erDiagram
    va_project ||--o{ va_build : executa
    va_project ||--o{ va_secret : guarda
    va_project |o--o{ va_webhook_delivery : recebe
    va_webhook_delivery |o--o{ va_build : dispara
    va_build ||--o{ va_build_step : tem
    va_build ||--o{ va_build_log : registra
    va_build_step |o--o{ va_build_log : agrupa
    va_build |o--o{ va_build : repete
    va_user ||--o{ va_api_token : possui
    va_user |o--o{ va_build : pede
    va_user |o--o{ va_session : abre
    va_user |o--o{ va_audit : faz
    va_build |o--o| va_worker : ocupa
```

## Estrutura

```
src/
  App.php            contêiner de serviços
  Config.php         configuração lida do .env e do ambiente
  Database/          conexão PDO, esquema portátil e migrações
  Repository/        projetos, builds, entregas, usuários, tokens, workers e auditoria
  Webhook/           GitHub, GitLab, verificação de assinatura e entrada na fila
  Pipeline/          leitura e validação do .vau.yml
  Build/             fila de builds no banco
  Runner/            worker, execução no Docker, processos, log e runtime local
  Integrations/      status no commit e avisos no chat
  Http/              kernel, rotas, sessão, views, gráficos SVG e controladores
  Auth/              entrada, bloqueio por tentativas e perfis
  Metrics/           números do painel e /metrics
  Console/           comandos do bin/vau
templates/           telas do painel (PHP puro, com escape em tudo)
public/              index.php, CSS, JS e fontes
bin/vau              linha de comando
docker/              Apache, PHP e entrypoint da imagem
exemplos/            pipelines prontos para PHP, Node e Python
docs/                manuais em PDF, banco de dados, DDL e imagens
tests/               PHPUnit (unidade e integração)
```

## API

Crie um token em **Sistema e conta** ou com `php bin/vau token:create <usuario>`. Todas as rotas exigem `Authorization: Bearer <token>`.

| Método | Rota | Função |
|---|---|---|
| GET | `/api/v1/projects` | Projetos com último build e taxa de sucesso |
| GET | `/api/v1/projects/{slug}` | Um projeto |
| GET / POST | `/api/v1/projects/{slug}/builds` | Listar builds (`status`, `branch`, `limit`) e disparar um (`{"branch": "main", "commit": "..."}`) |
| GET | `/api/v1/builds/{id}` | Build com as etapas |
| GET | `/api/v1/builds/{id}/log` | Log completo em texto |
| POST | `/api/v1/builds/{id}/cancel` · `/retry` | Cancelar e repetir |
| GET | `/api/v1/stats?days=7` | Totais, taxa de sucesso, duração média e p95 |

Rotas públicas: `POST /webhook/{slug}`, `GET /health`, `GET /metrics` e `GET /badge/{slug}.svg` (selo de status da branch padrão, ou de `?branch=`).

```bash
curl -X POST http://127.0.0.1:8080/api/v1/projects/api-de-pagamentos/builds \
  -H "Authorization: Bearer vau_..." -H "Content-Type: application/json" \
  -d '{"branch": "main"}'
```

## Linha de comando

| Comando | Função |
|---|---|
| `php bin/vau setup` | Prepara pastas, chave, banco e usuário admin |
| `php bin/vau serve` | Painel no servidor embutido do PHP |
| `php bin/vau worker [--once] [--max=N]` | Consome a fila (SIGTERM termina o build atual antes de sair) |
| `php bin/vau doctor` | Diagnóstico de PHP, extensões, banco e Docker |
| `php bin/vau build:run <projeto> [--branch=] [--commit=]` | Enfileira um build |
| `php bin/vau prune [--dias=30]` | Apaga builds e entregas antigos |
| `php bin/vau user:create` · `user:password` · `token:create` | Contas e tokens |
| `php bin/vau migrate` · `schema:dump` · `key:generate` | Banco e chave |

## Testes e qualidade

```bash
composer install
composer test        # PHPUnit: 49 testes de unidade e integração
composer analyse     # PHPStan nível 6
composer lint        # PHP-CS-Fixer, padrão PER-CS 2.0
```

Os testes usam SQLite em memória e um runtime falso, então rodam sem Docker. Cobrem o leitor de YAML, a validação do pipeline, os webhooks do GitHub e do GitLab, assinaturas, filtros, entregas repetidas, fila e reserva exclusiva, cancelamento automático, etapas, deploy, mascaramento de segredos, forks, cancelamento, repetição, login com bloqueio, CSRF, painel, API com token e rotas públicas.

O [CI](.github/workflows/ci.yml) roda tudo em PHP 8.2, 8.3 e 8.4, migra um PostgreSQL 16 de verdade e constrói a imagem Docker. O Dependabot acompanha Composer, Actions e a imagem base.

## Licença

[MIT](LICENSE). Use, copie, altere e distribua à vontade. As fontes [Instrument Sans](https://fonts.google.com/specimen/Instrument+Sans), [Bricolage Grotesque](https://fonts.google.com/specimen/Bricolage+Grotesque) e [IBM Plex Mono](https://www.ibm.com/plex/) da interface vêm do [Fontsource](https://fontsource.org/) e são distribuídas sob a SIL Open Font License 1.1.
