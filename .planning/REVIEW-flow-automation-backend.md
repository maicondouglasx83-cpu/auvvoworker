---
phase: flow-automation-backend
reviewed: 2026-05-24T12:00:00Z
depth: deep
files_reviewed: 11
files_reviewed_list:
  - backend/crm_flow_engine.inc.php
  - backend/crm_flow_agent.inc.php
  - backend/crm_flow_converse.inc.php
  - backend/crm_flow_wait_reply.inc.php
  - backend/crm_automation.inc.php
  - backend/crm_automation_triggers.inc.php
  - backend/crm_automation_dedupe.inc.php
  - backend/whatsapp_connections.inc.php
  - backend/webhook_evolution.php
  - backend/ai_reply.inc.php
  - backend/conversation_history.inc.php
findings:
  critical: 4
  warning: 5
  info: 1
  total: 10
status: issues_found
---

# Flow / Automation Backend — Code Review

**Reviewed:** 2026-05-24  
**Depth:** deep (cross-file routing + session/dedupe traces)  
**Status:** issues_found

## Summary

Review focused on logic bugs and security in the WhatsApp flow engine, converse/defer paths, dedupe, wait-reply, and webhook routing. Several issues can cause **duplicate outbound messages**, **missed replies**, or **wrong-tenant routing** under realistic conditions. Prepared statements are used consistently in scoped files; no raw SQL injection found in reviewed files.

---

## Critical Issues

### CR-01: Same visual flow fires twice on first WhatsApp (dedupe key per trigger type)

**Severity:** HIGH  
**File:** `backend/crm_automation_triggers.inc.php`, `backend/crm_automation_dedupe.inc.php`, `backend/crm_flow_engine.inc.php`  
**Issue:** On the first inbound message, `auvvo_crm_fire_whatsapp_triggers()` emits both `whatsapp_first` and `whatsapp_message` for the same connection (and agent). Flow dedupe uses per-trigger keys (`flow:{id}:whatsapp_first:{conn}` vs `flow:{id}:whatsapp_message:{conn}`) and **does not** apply the global `whatsapp_first` key that rules use. The same published flow can walk twice in one webhook — duplicate welcome messages and duplicate converse arming.  
**Evidence:** `crm_automation_triggers.inc.php` L48–54 builds both events; `crm_automation_dedupe.inc.php` L128–142 skips global key for flows; `crm_flow_engine.inc.php` L803 marks dedupe after each walk.  
**Fix:** After a flow matches `whatsapp_first`, also mark/consult `whatsapp_message` (or a single `flow:{id}:whatsapp_inbound:{conn}` key), or collapse first-message events to one trigger before `auvvo_crm_run_visual_flows()`.

### CR-02: Welcome + converse defer failure → immediate AI reply on same inbound

**Severity:** HIGH  
**File:** `backend/crm_flow_engine.inc.php`, `backend/crm_flow_converse.inc.php`  
**Issue:** When `flow_message` sets `_flow_welcome_sent` and `flow_converse` runs in defer mode, a failed defer (`ok === false`) falls through to a **second** `auvvo_flow_run_converse_node()` call **without** `_converse_defer_reply`. That path calls `auvvo_flow_converse_reply()` on the current message body, so the lead can receive the fixed welcome **and** an AI reply in one webhook.  
**Evidence:** `crm_flow_engine.inc.php` L378–404 (defer branch returns only on success; L401–405 re-invokes converse); `crm_flow_converse.inc.php` L165–175 (defer vs immediate reply).  
**Fix:** On defer path failure, return `'paused'` or `'error'` without fallthrough; or pass a `_converse_defer_only` flag that prevents the second reply on the same walk.

### CR-03: Multiple concurrent `wait_states` rows per contact (no uniqueness, no close-on-new)

**Severity:** HIGH  
**File:** `backend/crm_flow_wait_reply.inc.php`, `backend/migrations.php`  
**Issue:** `crm_automation_wait_states` has only `(user_id, contact_id, status)` index — no UNIQUE on active wait. Each `flow_wait_reply` pause INSERTs a new `waiting` row without closing prior waits. Resume uses `ORDER BY id DESC LIMIT 1`, so stale waits can resume on wrong keyword/timeout ordering; timeout jobs and inbound resume can race on different rows.  
**Evidence:** `migrations.php` L200–218 (no unique waiting constraint); `crm_flow_wait_reply.inc.php` L58–79 INSERT, L180–184 SELECT latest only.  
**Fix:** Before INSERT, `UPDATE ... SET status='superseded' WHERE user_id=? AND contact_id=? AND status='waiting'`, or enforce `UNIQUE (user_id, contact_id, status)` for `waiting` via application-level single-row pattern + transaction.

### CR-04: Connection lookup by token/instance without `user_id` (cross-tenant routing risk)

**Severity:** HIGH  
**File:** `backend/whatsapp_connections.inc.php`, `backend/webhook_evolution.php`  
**Issue:** `auvvo_whatsapp_connection_by_token()` and `by_instance()` query `WHERE evolution_token = ?` / `evolution_instance = ? LIMIT 1` with no tenant filter. Schema uniqueness is `(user_id, evolution_token)` — **not** global — so duplicate tokens/instances across tenants resolve to an arbitrary row. Webhook routing then uses that row's `user_id` for CRM, flows, and AI.  
**Evidence:** `whatsapp_connections.inc.php` L16–18, L35–37; `migrations.php` L875–876; `webhook_evolution.php` L363–370.  
**Fix:** Require tenant hint from webhook auth, or enforce globally unique tokens/instances at DB level; lookup must include `user_id` when known.

---

## Warnings

### WR-01: Wait-reply INSERT failure still returns `'paused'`

**Severity:** MEDIUM  
**File:** `backend/crm_flow_wait_reply.inc.php`  
**Issue:** On PDOException during INSERT, function logs error but unconditionally returns `'paused'`. Engine treats flow as paused; no wait row exists — lead replies never resume and timeout job has nothing to close.  
**Evidence:** L57–104 (catch logs; L104 always `return 'paused'`).  
**Fix:** Return `'error'` or `'ok'` on insert failure; do not mark run paused.

### WR-02: Active wait does not block new flow triggers (keyword mismatch)

**Severity:** MEDIUM  
**File:** `backend/crm_flow_wait_reply.inc.php`, `backend/crm_flow_engine.inc.php`  
**Issue:** When keyword filter fails, `try_resume` returns `handled: true, resumed: false`. `auvvo_crm_run_visual_flows()` only early-returns when `resumed` is true (L724–726). Processing continues and **new** flows can trigger on the same inbound while an older wait is still `waiting`.  
**Evidence:** `crm_flow_wait_reply.inc.php` L191–192; `crm_flow_engine.inc.php` L723–726.  
**Fix:** If any `waiting` row exists for contact, skip new trigger walks or return early on `handled && !resumed`.

### WR-03: `flows_only` / `flows_first` scope block standalone with no fallback reply

**Severity:** MEDIUM  
**File:** `backend/crm_flow_agent.inc.php`, `backend/webhook_evolution.php`  
**Issue:** `auvvo_automation_should_block_standalone()` returns true for `flows_only` always, or when dedupe/session scope matches but recover/converse did not handle. Webhook exits with `"standalone agent skipped"` and **no** AI/message sent — silent drop for the lead.  
**Evidence:** `crm_flow_agent.inc.php` L176–204; `webhook_evolution.php` L512–538.  
**Fix:** Only block when flow actually handled or active session exists; otherwise allow standalone or send explicit fallback.

### WR-04: Dedupe check/mark TOCTOU race

**Severity:** MEDIUM  
**File:** `backend/crm_automation_dedupe.inc.php`  
**Issue:** `should_skip` SELECT then `mark` INSERT IGNORE are not atomic. Concurrent webhooks (or duplicate Evolution delivery before early dedupe) can both pass skip and both execute flows.  
**Evidence:** L29–67 vs L89–95; webhook lock is per agent+peer, not per flow dedupe.  
**Fix:** Use transactional `INSERT ... ON DUPLICATE KEY` as claim, or `SELECT FOR UPDATE` on contact dedupe row.

### WR-05: Multiple trigger nodes in one flow all execute per event

**Severity:** MEDIUM  
**File:** `backend/crm_flow_engine.inc.php`  
**Issue:** Inner loop over all `flow_trigger` nodes has no break after first match — each matching trigger node starts a full `auvvo_flow_walk()`. Misconfigured flows duplicate execution.  
**Evidence:** L741–812 (`continue` after each walk, no `break`).  
**Fix:** Break after first matched trigger per flow per event, or document/enforce single trigger node.

---

## Info

### IN-01: Empty PDO catch swallows flow stats / sim persistence errors

**Severity:** LOW  
**File:** `backend/crm_flow_engine.inc.php`, `backend/crm_flow_converse.inc.php`  
**Issue:** `auvvo_flow_bump_stats` and `auvvo_flow_converse_save_sim` catch PDOException with empty bodies — failures invisible except missing stats/sim state.  
**Evidence:** `crm_flow_engine.inc.php` L176–177; `crm_flow_converse.inc.php` L348–349.  
**Fix:** At minimum `error_log()` with context.

---

## Items checked — no issue found

| Check | Result |
|-------|--------|
| SQL without prepared statements in scoped files | Pass (whitelist-only dynamic SQL in bump_stats/LIMIT) |
| Missing includes for reviewed call chains | Pass (`require_once` / bootstrap helpers present; circular deps mitigated by `require_once`) |
| Double standalone + flow when flow marks handled | Pass when `mark_flow_handled` runs (webhook exits L483–491) |
| Converse defer + welcome happy path | Pass — defer sets session, marks handled, pauses without same-turn reply |
| `conversation_history` tenant isolation | Pass — scoped by `agent_id` (tenant-bound agents) |

---

_Reviewed: 2026-05-24_  
_Reviewer: Claude (gsd-code-reviewer)_  
_Depth: deep_
