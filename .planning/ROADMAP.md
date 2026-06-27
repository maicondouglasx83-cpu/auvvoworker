# Roadmap: Auvvo AI — Correcao de Bugs

## Overview

Corrigir bugs, falhas de seguranca, race conditions e erros do sistema — mantendo a stack atual (PHP vanilla, MySQL, Evolution API, Node.js worker). Nada de reescrever ou trocar tecnologia.

## Phases

- [x] **Phase 1: Seguranca** — Credenciais, XSS, Webhook, Sessao, .htaccess
- [x] **Phase 2: Bugs Backend** — PDO, Race conditions, Worker (timeout, graceful, campaign, LTV, HMAC)
- [x] **Phase 3: Codigo e Dados** — Esqueci senha, rate limit, foreign keys, error_log, worker utils
- [x] **Phase 4: Ajustes Finais** — CSS, cache migrations, config worker, README

## Phase Details

### Phase 1: Seguranca
**Goal**: Corrigir vulnerabilidades de seguranca (credenciais expostas, XSS, webhook sem auth, sessao, .htaccess)
**Depends on**: Nothing
**Requirements**: SEC-01, SEC-02, SEC-03, SEC-04, SEC-05, SEC-06
**Success Criteria**:
  1. `.env.example` tem so placeholders (sem credenciais reais)
  2. Chat nao aceita HTML injection
  3. Webhook rejeita eventos sem assinatura
  4. Sessao regenerada no login, expira, cookie SameSite
  5. `.htaccess` bloqueia `.env`, `*.log`, diretorios
  6. Nenhum innerHTML com dados brutos de API/usuario

Plans:
- [x] 01-01: Limpar `.env.example` (remover credenciais, deixar placeholders)
- [x] 01-02: Corrigir DOM XSS em `conversas.php` (innerHTML → textContent)
- [x] 01-03: Autenticar webhook Evolution (verificar WEBHOOK_SECRET)
- [x] 01-04: Hardening de sessao (regeneration, timeout, SameSite)
- [x] 01-05: Hardening `.htaccess` (bloqueios, headers)
- [x] 01-06: Sanitizar innerHTML em conversas.php e conexoes.php

### Phase 2: Bugs Backend
**Goal**: Corrigir race conditions, stalls do worker, fatal errors silenciosos
**Depends on**: Phase 1
**Requirements**: BUG-01, BUG-02, BUG-03, BUG-04, BUG-05, BUG-06, BUG-07, BUG-08
**Success Criteria**:
  1. PDO nao causa fatal error — exceptions em vez de silent
  2. Mensagens nao duplicam (FOR UPDATE com transacao)
  3. Worker nao congela (fetch com timeout)
  4. Worker nao morre em erro transitorio
  5. Worker finaliza jobs pendentes no desligamento
  6. Campanhas nao perdem status de envio
  7. LTV nao pula ciclos em falha
  8. HMAC secreto obrigatorio (sem fallback derivado de credenciais)

Plans:
- [ ] 02-01: PDO ERRMODE_EXCEPTION + auditar queries afetadas
- [ ] 02-02: Corrigir race condition no ai_queue (FOR UPDATE + transacao)
- [ ] 02-03: Timeout em todas as chamadas fetch do worker
- [ ] 02-04: verifyInternalHmac() robusto contra erros transitorios
- [ ] 02-05: Graceful shutdown (SIGTERM) no worker
- [ ] 02-06: Corrigir campaign worker (atomicidade + recovery de processing)
- [ ] 02-07: Corrigir LTV worker (lastLtvRun so em sucesso)
- [ ] 02-08: WORKER_HMAC_SECRET obrigatorio, remover fallback

### Phase 3: Codigo e Dados
**Goal**: Codigo organizado, integridade dos dados, funcionalidades quebradas
**Depends on**: Phase 2
**Requirements**: FUNC-01, FUNC-02, CODE-01, CODE-02, CODE-03, DATA-01, DATA-02, DATA-03
**Success Criteria**:
  1. Link "Esqueci a senha" funcional
  2. Rate limit por IP real
  3. `agentes.php` dividido (max 500 linhas cada)
  4. `automacoes-flow.js` dividido em modulos
  5. Worker sem codigo duplicado (hmac.js + httpClient.js)
  6. Foreign keys garantem integridade referencial
  7. Logs nao acessiveis via URL
  8. Nenhum catch {} vazio

Plans:
- [x] 03-01: Implementar "esqueci a senha" (token + email + reset)
- [x] 03-02: Rate limit de login por IP + sessao
- [x] 03-03: Split agentes.php (handlers extraidos para backend/)
- [x] 03-04: Split automacoes-flow.js (config extraida para assets/)
- [x] 03-05: Extrair hmac.js + httpClient.js no worker
- [x] 03-06: Foreign keys + error_log nos catches vazios
- [x] 03-07: Bloquear logs publicos + ON DELETE CASCADE

### Phase 4: Ajustes Finais
**Goal**: CSS, cache, config, documentacao
**Depends on**: Phase 3
**Requirements**: MISC-01, MISC-02, MISC-03, MISC-04, MISC-05
**Success Criteria**:
  1. design.json removido ou alinhado com CSS real
  2. Variaveis CSS unificadas (sem duplicacao style.css/app.css)
  3. migrations.php nao consulta information_schema em toda request
  4. campaignWorker le config via config.js
  5. README.md documenta setup

Plans:
- [x] 04-01: Alinhar design.json com CSS real (ou remover)
- [x] 04-02: Unificar variaveis CSS (extrair para shared.css)
- [x] 04-03: Cachear schema version no migrations.php
- [x] 04-04: Unificar config do worker (campaignWorker → config.js)
- [x] 04-05: Escrever README.md (setup, estrutura, troubleshooting)

## Progress

| Phase | Plans | Status |
|-------|-------|--------|
| 1. Seguranca | 6/6 | Complete |
| 2. Bugs Backend | 8/8 | Complete |
| 3. Codigo e Dados | 7/7 | Complete |
| 4. Ajustes Finais | 5/5 | Complete |

---
*Roadmap created: 2026-05-23*
