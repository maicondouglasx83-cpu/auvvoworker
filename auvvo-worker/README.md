# auvvo-worker

Processo contínuo (**sem cron PHP**) que consome:

- `auvvo_ai_jobs` — respostas de IA → `backend/internal/process_ai_job.php`
- `campaign_send_queue` — campanhas
- `crm_automation_queue` — automações com atraso / sequências → `backend/internal/process_automation_queue.php`
- Gatilhos **LTV** (ciclo de compra) → `backend/internal/process_ltv_triggers.php` (~a cada 55 min no mesmo processo)

## Setup

```bash
cd auvvo-worker
cp .env.example .env
# Edite .env (mesmo DB e APP_BASE_URL do PHP)
npm install
npm start
```

Produção com PM2:

```bash
pm2 start ecosystem.config.cjs
```

## Requisitos

- Node 18+
- PHP acessível em `APP_BASE_URL` (para internal API)
- `WEBHOOK_AI_MODE=queue` no `.env` do PHP
- Publicar no PHP: `backend/internal/process_ai_job.php`, `process_automation_queue.php`, `process_ltv_triggers.php` (teste: `npm run ping-php` → HTTP 400, não 404)

## Diagnóstico

```bash
npm run status    # contagem por status na fila
npm run ping-php  # testa HMAC + URL do PHP
```

O worker carrega `.env` de `auvvo-worker/` e, se ausente, o `.env` na raiz do projeto PHP.

## Desenvolvimento local

Se o Apache local não expõe o projeto, crie `auvvo-worker/.env` só com:

```
APP_BASE_URL=http://localhost/auvvov2
```

(mesmos `DB_*` do `.env` raiz)
