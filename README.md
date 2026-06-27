# Auvvo AI — Automação de Vendas via WhatsApp com IA

Plataforma SaaS que permite criar agentes de IA que se conectam ao WhatsApp e atendem conversas, qualificam leads e fecham vendas 24/7 automaticamente.

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Backend | PHP 8.x vanilla (sem framework) |
| Banco | MySQL via PDO |
| Servidor | Apache + mod_rewrite (.htaccess) |
| WhatsApp | Evolution API (Go/whatsmeow) |
| IA/LLM | OpenRouter (GPT-4o, Gemini, Claude, etc.) |
| Worker | Node.js 18+ (polling de jobs MySQL) |
| Frontend | Vanilla JS + CSS (glassmorphism theme) |
| Pagamentos | AbacatePay |
| Email | SMTP (Hostinger) |
| Google | Calendar OAuth, Sheets OAuth |

## Estrutura do Projeto

```
.
├── .env.example          # Template de configuracao (placeholders)
├── .htaccess             # URL rewriting + headers seguranca
├── index.php             # Landing page publica
├── login.php             # Login + rate limiting
├── esqueci-senha.php     # Recuperacao de senha
├── resetar-senha.php     # Redefinicao de senha (token)
├── checkout.php          # Assinatura/planos
├── dashboard.php         # App dashboard (KPIs, agentes, leads)
├── agentes.php           # Gestao de agentes IA
├── conversas.php         # Chat ao vivo + CRM sidebar
├── conexoes.php          # Conexoes WhatsApp (QR code)
├── campanhas.php         # Campanhas de mensagens em massa
├── crm.php               # CRM pipelines e contatos
├── automacoes.php        # Automacoes BPM/flow
├── webhooks.php          # Webhooks de saida
├── conhecimento.php      # Base de conhecimento dos agentes
├── configuracoes.php     # Configuracoes da conta
├── integracoes.php       # Integracoes (Google, APIs)
├── backend/
│   ├── db.php            # Conexao PDO + config .env
│   ├── api.php           # API AJAX do dashboard
│   ├── api/v1.php        # API REST publica (chave API)
│   ├── migrations.php    # Migracoes de schema idempotentes
│   ├── EvolutionAPI.php  # Cliente Evolution WhatsApp
│   ├── webhook_evolution.php  # Webhook receptor Evolution
│   ├── ai_queue.inc.php  # Fila de jobs IA
│   ├── ai_reply.inc.php  # Geracao de resposta IA
│   ├── PaymentGateway.php # Processamento de pagamento
│   ├── Contacts.php      # CRM contatos
│   ├── CrmPipelines.php  # CRM pipelines
│   ├── mail/             # Sistema de email transacional
│   ├── internal/         # APIs chamadas pelo worker Node
│   └── ...
├── includes/
│   ├── auth.php          # Guard de autenticacao + CSRF
│   ├── i18n.php          # Internacionalizacao (pt_BR/en/es)
│   └── sidebar.php       # Navegacao lateral do app
├── assets/               # JS e CSS das paginas
├── auvvo-worker/         # Worker Node.js (processa filas)
│   └── src/
│       ├── index.js      # Entrypoint + loop de polling
│       ├── aiWorker.js   # Jobs de resposta IA
│       ├── campaignWorker.js  # Envio de campanhas
│       ├── automationWorker.js # Fila de automacoes CRM
│       ├── ltvWorker.js  # Triggers de ciclo LTV
│       ├── hmac.js       # Utilitario HMAC compartilhado
│       ├── httpClient.js # Utilitario fetch com timeout
│       ├── config.js     # Config centralizada
│       └── db.js         # Pool de conexao MySQL
├── lang/                 # Traducoes
├── storage/              # Cache runtime (schema version)
└── uploads/              # Uploads de usuarios
```

## Setup Local (XAMPP)

1. **PHP + MySQL**: XAMPP com PHP 8.x e MySQL rodando
2. **Criar `.env`** a partir do `.env.example`:
   ```
   DB_HOST=127.0.0.1
   DB_NAME=auvvov2
   DB_USER=root
   DB_PASS=
   APP_BASE_URL=http://localhost
   ```
3. **Banco**: As tabelas sao criadas automaticamente na primeira request
4. **Worker Node.js**:
   ```bash
   cd auvvo-worker
   npm install
   # Criar .env com WORKER_HMAC_SECRET e DB_*
   node src/index.js
   ```
5. **Acessar**: `http://localhost/login`

## Variaveis de Ambiente Essenciais

| Variavel | Descricao |
|----------|-----------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexao MySQL |
| `APP_BASE_URL` | URL base (ex: https://auvvo.com) |
| `EVOLUTION_API_URL`, `EVOLUTION_API_KEY` | Evolution WhatsApp API |
| `WEBHOOK_SECRET` | Segredo do webhook (DIFERENTE da API key) |
| `OPENROUTER_API_KEY` | Chave OpenRouter para IA |
| `ABACATEPAY_API_KEY` | Gateway de pagamento |
| `SMTP_*` | Configuracao de email transacional |
| `WORKER_HMAC_SECRET` | HMAC interno (PHP e worker DEVEM ser iguais) |

## Seguranca

- CSRF em todos os formularios POST
- Sessao com `SameSite=Strict`, `HttpOnly`, timeout 8h, regeneracao no login
- Webhook autenticado via `WEBHOOK_SECRET`
- PDO `ERRMODE_EXCEPTION` + prepared statements
- Rate limit de login por IP real (tabela `login_attempts`)
- Foreign keys com `ON DELETE CASCADE` para integridade
- `.htaccess` bloqueia acesso a `.env`, logs, e diretorios
- Headers de seguranca: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`

## Troubleshooting

**Worker nao processa jobs**: Verifique `WORKER_HMAC_SECRET` — deve ser identico no `.env` do PHP e do worker Node.

**Erro 403 no webhook**: O webhook requer `X-Webhook-Secret` header ou `?secret=` query param. Configure o mesmo valor no painel Evolution em "webhookSecret".

**PDO errors**: Agora sao excecoes (nao silent). Verifique `error_log` do PHP.

**Migrations**: Schema version cacheado em `storage/schema_version.txt`. Delete o arquivo para forcar re-execucao das migrations.
"# auvvoworker" 
