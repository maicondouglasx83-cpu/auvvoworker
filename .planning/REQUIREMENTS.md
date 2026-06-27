# Requirements: Auvvo AI — Correcao de Bugs e Erros

**Defined:** 2026-05-23
**Core Value:** Agente de IA atende e converte leads no WhatsApp 24/7 sem intervenção humana

## Requisitos

### Seguranca

- [ ] **SEC-01**: `.env.example` limpo — remover credenciais reais, deixar so placeholders
- [ ] **SEC-02**: DOM XSS no chat (`conversas.php:559`) — `innerHTML` com input do usuario corrigido
- [ ] **SEC-03**: Webhook Evolution autenticado — verificar assinatura, rejeitar eventos nao assinados
- [ ] **SEC-04**: Sessao segura — regenerar ID no login, adicionar timeout, cookie SameSite
- [ ] **SEC-05**: `.htaccess` — bloquear acesso a `.env` e `*.log`, impedir listagem de diretorios
- [ ] **SEC-06**: Todos `innerHTML` com dados de API substituidos por `textContent` (evitar XSS)

### Bugs Criticos

- [ ] **BUG-01**: PDO `ERRMODE_SILENT` — trocar para `ERRMODE_EXCEPTION` (evita fatal error quando `prepare()` falha)
- [ ] **BUG-02**: `SELECT ... FOR UPDATE` sem transacao em `ai_queue.inc.php` — mensagens podem duplicar
- [ ] **BUG-03**: Worker congela — `fetch()` sem timeout em todas as chamadas HTTP (phpClient, automation, ltv, campaign)
- [ ] **BUG-04**: Worker morre em erro transitorio — `verifyInternalHmac()` trata 502/503/timeout como falha de HMAC
- [ ] **BUG-05**: Worker sem graceful shutdown — jobs `processing` viram orfaos no restart
- [ ] **BUG-06**: Campaign — mensagem enviada mas DB update pode falhar → item fica `processing` pra sempre
- [ ] **BUG-07**: LTV — `lastLtvRun` atualizado mesmo em falha → pula 55 minutos de triggers
- [ ] **BUG-08**: Worker — `WORKER_HMAC_SECRET` ausente gera fallback fragil derivado de credenciais DB

### Funcionalidades Quebradas

- [ ] **FUNC-01**: "Esqueci a senha" no `login.php` — link `href="#"` nao funcional
- [ ] **FUNC-02**: Rate limit de login burlavel — baseado so em sessao, nao em IP

### Codigo (dividir arquivos enormes)

- [ ] **CODE-01**: `agentes.php` (2415 linhas) — dividir em arquivos menores
- [ ] **CODE-02**: `automacoes-flow.js` (2000+ linhas) — dividir em modulos
- [ ] **CODE-03**: Worker — extrair `sign()` e logica de fetch duplicada em 6+ arquivos

### Dados e Logs

- [ ] **DATA-01**: Foreign keys nas tabelas principais (evitar orfaos ao deletar usuario/agente)
- [ ] **DATA-02**: Logs (`webhook_debug.log`, `webhook_trace.log`) bloqueados — estao acessiveis via URL
- [ ] **DATA-03**: `catch {}` vazios em varios locais — adicionar `error_log()` para nao engolir erros

### Pequenos Ajustes

- [ ] **MISC-01**: `design.json` (tema escuro) vs CSS real (tema claro) — alinhar ou remover design.json
- [ ] **MISC-02**: Variaveis CSS duplicadas entre `style.css` e `app.css` — unificar
- [ ] **MISC-03**: `migrations.php` faz queries `information_schema` em toda requisicao — cachear schema version
- [ ] **MISC-04**: Worker — `campaignWorker.js` le config direto do `process.env` em vez do `config.js`
- [ ] **MISC-05**: README.md vazio — documentar setup basico

## Out of Scope (NAO MEXER)

| Item | Motivo |
|------|--------|
| Trocar Evolution API | Funciona bem, nao precisa |
| Migrar para Laravel/Symphony | Reescrita total, risco alto |
| Trocar MySQL queue por Redis | Funciona para escala atual |
| Adicionar build system (Vite/Webpack) | Complexidade desnecessaria agora |
| CI/CD, testes automatizados | Foco em corrigir bugs primeiro |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| SEC-01 a SEC-06 | Phase 1 | Pending |
| BUG-01 a BUG-08 | Phase 2 | Pending |
| FUNC-01, FUNC-02 | Phase 3 | Pending |
| CODE-01 a CODE-03 | Phase 3 | Pending |
| DATA-01 a DATA-03 | Phase 3 | Pending |
| MISC-01 a MISC-05 | Phase 4 | Pending |

**Coverage:** 26 requisitos, todos mapeados ✓

---
*Last updated: 2026-05-23*
