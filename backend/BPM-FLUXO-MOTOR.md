# Motor de automações (BPM)

## Camadas

1. **Gatilho** — `auvvo_crm_fire_whatsapp_triggers`, `Contacts` (stage/tag/create), `webhook_inbound`, worker LTV.
2. **Orquestração** — `auvvo_crm_run_automation_events` → regras (`crm_automations`) + fluxos (`crm_automation_flows`).
3. **Grafo** — `auvvo_flow_walk` (trigger → condição → delay/mensagem/memória/ação).
4. **Ações** — `auvvo_crm_execute_action` (WhatsApp, agente, tags, memória, webhooks, **`brain_mission`**).
5. **Cérebro IA** — instruções do agente + `[[AUVO_ACTIONS]]` após LLM (`auvvo_brain_process_llm_response`). Ver `ARQUITETURA-CEREBRO.md`.
6. **Fila** — `crm_automation_queue` + worker Node (`flow_resume` e ações atrasadas).

### `brain_mission` / `clear_brain_mission`

- `brain_mission` grava `_brain_mission` em `contacts.memory_json`.
- `clear_brain_mission` remove a chave (ou `crm.clear_mission` / tags de conclusão via cérebro).
- `MasterPromptBuilder` injeta **MISSÃO ATIVA** na próxima resposta IA.
- Após `[[AUVO_ACTIONS]]` com agenda confirmada, tag de conclusão ou `crm.clear_mission`, a missão é limpa automaticamente.
- Preferir gatilhos `whatsapp_first`, `stage_enter`, `tag_added` em templates (evitar só `keyword_contains`).

### Dedupe (primeiro contato)

- Tabela `crm_automation_dedupe`: no primeiro `whatsapp_first`, **apenas uma** regra ou fluxo executa por lead (`global:whatsapp_first`).
- Primeira mensagem não dispara mais `whatsapp_message` (só `whatsapp_first`).
- Cada regra/fluxo também só dispara uma vez por par gatilho+origem (`rule:ID:tipo:valor`).

## Contexto em todo o fluxo

| Campo | Uso |
|--------|-----|
| `message_body` | Mensagem do gatilho atual; condições e vars também leem **sessão** (`conversation_logs`) |
| `trigger_agent_id` | Agente da linha WhatsApp que recebeu o gatilho |
| `_trigger_context` | Persistido na fila; restaurado em `flow_resume` |

## Agente efetivo

Ordem em nós de mensagem/ação: **id do nó** → **agent_id do contato** → **trigger_agent_id**.

Após `assign_agent` / `invoke_agent` (com troca), o webhook recarrega o contato e usa `auvvo_crm_resolve_whatsapp_agent_row` para a **resposta IA** na linha correta.

## Memória

- Nó **Memória** → `set_memory` → `contacts.memory_json`.
- Origens: `session_today` (padrão), `session_recent` (N msgs), `session_last`, `last_message` (só gatilho).
- Templates: `{{memoria.chave}}`, `{{mensagens_hoje}}`, `{{sessao}}`, `{{ultima_sessao}}`.
- IA: `MasterPromptBuilder` lê memória do banco no mesmo request (após automações).

## Condição opcional por memória

No JSON do nó condição (API futura / manual): `memory_key` + opcional `memory_value`.

## LTV

- Regras: worker `process_ltv_triggers.php`.
- Fluxos visuais com gatilho `ltv_inactive`: `auvvo_crm_run_ltv_visual_flows` (dedupe 7 dias, `source_type=flow`).

## Arquivo central

`crm_automation_motor.inc.php` — hidratação, contexto, eventos, resolução de agente.
