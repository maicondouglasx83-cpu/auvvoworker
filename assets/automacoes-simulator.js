/* Simulador de fluxos — chat interno dry-run + multi-turno */
(function () {
  const B = window.FLOW_BOOT || {};
  const API = window.API || 'backend/api.php';

  function getCsrf() {
    return document.getElementById('csrf-token')?.value
      || B.csrf
      || window.FLOW_BOOT?.csrf
      || window.CSRF
      || '';
  }

  let chatHistory = [];
  let pausedRunId = 0;

  window.getSimPausedRunId = () => pausedRunId;
  window.setSimPausedRunId = (id) => { pausedRunId = id || 0; };

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function connOptions() {
    const list = B.whatsappConnections || [];
    return ['<option value="*">Qualquer conexão (*)</option>']
      .concat(list.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`))
      .join('');
  }

  function flowOptions(flows) {
    const cur = typeof window.getCurrentFlowId === 'function' ? window.getCurrentFlowId() : 0;
    return (flows || [])
      .map((f) => `<option value="${f.id}" ${String(f.id) === String(cur) ? 'selected' : ''}>${esc(f.name)}${f.is_active == 1 ? '' : ' (rascunho)'}</option>`)
      .join('');
  }

  /** Sincroniza painel de teste com o gatilho do canvas atual. */
  function syncSimFromEditor() {
    if (!document.getElementById('sim-use-editor')?.checked) return;
    const exp = typeof window.getCurrentFlowExport === 'function' ? window.getCurrentFlowExport() : null;
    const tr = typeof window.extractFlowTriggerFromExport === 'function' ? window.extractFlowTriggerFromExport(exp) : null;
    if (!tr) return;

    const typeSel = document.getElementById('sim-trigger-type');
    if (typeSel) typeSel.value = tr.trigger_type;

    if (['whatsapp_first', 'whatsapp_message'].includes(tr.trigger_type)) {
      const conn = document.getElementById('sim-connection');
      if (conn) {
        const hasOpt = [...conn.options].some((o) => o.value === tr.trigger_value);
        conn.value = hasOpt ? tr.trigger_value : '*';
      }
    } else if (tr.trigger_type === 'tag_added') {
      const tag = document.getElementById('sim-trigger-tag');
      if (tag && tr.trigger_value) tag.value = tr.trigger_value;
    } else if (tr.trigger_type === 'stage_enter') {
      const st = document.getElementById('sim-trigger-stage');
      if (st && tr.trigger_value) st.value = tr.trigger_value;
    } else if (tr.trigger_type === 'webhook_received') {
      const wh = document.getElementById('sim-trigger-webhook');
      if (wh && tr.trigger_value) wh.value = tr.trigger_value;
    }

    syncTriggerFields();
    const hint = document.getElementById('sim-trigger-hint');
    if (hint) {
      hint.textContent = `Gatilho do canvas: ${tr.trigger_type} / ${tr.trigger_value}`;
    }
  }

  window.syncSimFromEditor = syncSimFromEditor;

  function renderChat() {
    const el = document.getElementById('sim-chat-messages');
    if (!el) return;
    if (!chatHistory.length) {
      el.innerHTML = '<p class="sim-chat-empty">Envie uma mensagem de teste para simular o fluxo.<br><span class="text-muted">Nada é enviado ao WhatsApp nem alterado no CRM.</span></p>';
      return;
    }
    el.innerHTML = chatHistory
      .map((m) => {
        if (m.type === 'system') {
          return `<div class="sim-msg sim-msg--system">${esc(m.text)}</div>`;
        }
        if (m.type === 'bot') {
          const label = m.ai ? 'Agente IA' : 'Fluxo';
          const cls = m.ai ? 'sim-msg sim-msg--ai' : 'sim-msg sim-msg--bot';
          return `<div class="${cls}"><strong>${label}</strong><div>${esc(m.text)}</div></div>`;
        }
        return `<div class="sim-msg sim-msg--user">${esc(m.text)}</div>`;
      })
      .join('');
    el.scrollTop = el.scrollHeight;
  }

  window.renderChat = renderChat;

  function renderSteps(steps, status) {
    const el = document.getElementById('sim-steps');
    if (!el) return;
    if (!steps || !steps.length) {
      el.innerHTML = `<p class="text-muted">Nenhum passo registrado (${esc(status || '—')}).</p>`;
      return;
    }
    el.innerHTML = steps
      .map((s) => {
        const st = s.status || 'ok';
        const cls = st === 'simulated' ? 'sim-step--sim' : st === 'branch_no' ? 'sim-step--no' : st === 'skip' || st === 'error' ? 'sim-step--skip' : 'sim-step--ok';
        let detail = s.detail || '';
        let responseHtml = '';
        if ((s.node_class === 'flow_agent' || s.node_class === 'flow_think' || s.node_class === 'flow_converse') && detail.includes('\n')) {
          const nl = detail.indexOf('\n');
          responseHtml = `<div class="sim-step-response">${esc(detail.slice(nl + 1))}</div>`;
          detail = detail.slice(0, nl);
        }
        return `<div class="sim-step ${cls}">
          <div class="sim-step-head"><span>${esc(s.node_label || s.node_class)}</span><em>${esc(st)}</em></div>
          ${detail ? `<div class="sim-step-detail">${esc(detail)}</div>` : ''}
          ${responseHtml}
        </div>`;
      })
      .join('');
  }

  window.renderSteps = renderSteps;

  async function loadFlowsSelect() {
    const sel = document.getElementById('sim-flow-id');
    if (!sel) return;
    try {
      const d = await (await fetch(API + '?action=crm_list_flows')).json();
      sel.innerHTML = '<option value="">— Fluxo do editor (salvo) —</option>' + flowOptions(d.flows || []);
      const cur = typeof window.getCurrentFlowId === 'function' ? window.getCurrentFlowId() : 0;
      if (cur) sel.value = String(cur);
    } catch (e) {}
  }

  async function checkWorkerWarn() {
    const el = document.getElementById('sim-worker-warn');
    if (!el) return;
    try {
      const ex = window.getCurrentFlowExport?.();
      const nodes = ex?.drawflow?.Home?.data || {};
      const hasDelay = Object.values(nodes).some((n) => {
        const t = n.class || n.name || '';
        return t === 'flow_delay' || t === 'flow_wait_reply';
      });
      if (!hasDelay) { el.hidden = true; return; }
      const q = await (await fetch(API + '?action=crm_automation_queue_stats')).json();
      const alive = q.stats?.worker_alive !== false && q.worker_alive !== false;
      el.hidden = alive;
      if (!el.hidden) el.textContent = 'Worker offline — nós de espera precisam do auvvo-worker (npm start).';
    } catch (e) { el.hidden = true; }
  }

  function stepBotText(s) {
    if (!s.detail) return '';
    if (s.node_class === 'flow_message') {
      return s.detail.replace(/^WhatsApp \(simulado\): /, '').replace(/^WhatsApp: /, '');
    }
    if (s.node_class === 'flow_agent' || s.node_class === 'flow_think' || s.node_class === 'flow_converse') {
      const idx = s.detail.indexOf('\n');
      return idx >= 0 ? s.detail.slice(idx + 1).trim() : '';
    }
    if (s.node_class === 'flow_action' && s.detail.startsWith('WhatsApp (simulado):')) {
      return s.detail.replace(/^WhatsApp \(simulado\): /, '');
    }
    return '';
  }

  function appendBotLinesFromSteps(steps) {
    const botLines = (steps || [])
      .flatMap((s) => {
        const text = stepBotText(s);
        if (!text) return [];
        if (s.node_class === 'flow_think' && text.includes('\n---\n')) {
          return text.split('\n---\n').map((part) => ({ text: part.trim(), ai: true }));
        }
        return [{ text, ai: s.node_class === 'flow_agent' || s.node_class === 'flow_think' || s.node_class === 'flow_converse' }];
      })
      .filter((x) => x.text);
    botLines.forEach((x) => chatHistory.push({ type: 'bot', text: x.text, ai: x.ai }));
  }

  async function runSimulation() {
    const msg = document.getElementById('sim-message')?.value?.trim() || '';
    if (!msg) return;

    const continueRun = pausedRunId > 0;
    syncSimFromEditor();

    const triggerType = continueRun ? 'whatsapp_message' : (document.getElementById('sim-trigger-type')?.value || 'whatsapp_first');
    let triggerValue = '*';
    if (!continueRun) {
      if (triggerType === 'contact_created') triggerValue = 'whatsapp';
      else if (triggerType === 'tag_added') triggerValue = document.getElementById('sim-trigger-tag')?.value?.trim() || 'teste';
      else if (triggerType === 'stage_enter') triggerValue = document.getElementById('sim-trigger-stage')?.value || 'new';
      else if (triggerType === 'webhook_received') triggerValue = document.getElementById('sim-trigger-webhook')?.value?.trim() || 'default';
      else if (triggerType === 'ltv_inactive') triggerValue = 'default';
      else if (['whatsapp_first', 'whatsapp_message'].includes(triggerType)) {
        triggerValue = document.getElementById('sim-connection')?.value || '*';
      }
    }

    const flowId = parseInt(document.getElementById('sim-flow-id')?.value || '0', 10) || 0;
    const useEditor = document.getElementById('sim-use-editor')?.checked;
    const connectionId = parseInt(document.getElementById('sim-connection')?.value || '0', 10) || 0;

    chatHistory.push({ type: 'user', text: msg });
    renderChat();

    const fd = new FormData();
    fd.append('csrf_token', getCsrf());
    fd.append('action', 'crm_simulate_flow');
    if (continueRun) {
      fd.append('continue_run_id', String(pausedRunId));
    } else {
      if (flowId > 0 && !useEditor) fd.append('flow_id', String(flowId));
      else if (useEditor && typeof window.getCurrentFlowExport === 'function') {
        const exported = window.getCurrentFlowExport();
        if (exported) fd.append('flow_data', JSON.stringify(exported));
      } else if (flowId > 0) {
        fd.append('flow_id', String(flowId));
      }
      fd.append('trigger_type', triggerType);
      fd.append('trigger_value', triggerValue);
    }
    fd.append('message_body', msg);
    if (connectionId > 0) fd.append('connection_id', String(connectionId));
    fd.append('name', document.getElementById('sim-lead-name')?.value?.trim() || 'Lead Teste');
    fd.append('phone', document.getElementById('sim-lead-phone')?.value?.trim() || '11999998888');
    if (document.getElementById('sim-use-llm')?.checked) fd.append('use_llm', '1');

    const btn = document.getElementById('sim-send');
    if (btn) btn.disabled = true;

    try {
      const resp = await fetch(API, { method: 'POST', body: fd });
      if (!resp.ok) {
        let serverMsg = '';
        try { const txt = await resp.text(); serverMsg = txt.substring(0, 200); } catch (_) {}
        chatHistory.push({ type: 'system', text: 'Erro HTTP ' + resp.status + (serverMsg ? ': ' + serverMsg : '') });
        renderChat();
        return;
      }
      let d;
      try { d = await resp.json(); } catch (parseErr) {
        chatHistory.push({ type: 'system', text: 'Resposta inválida do servidor (não é JSON). Possível timeout.' });
        renderChat();
        return;
      }
      if (d.error && !d.handled) {
        chatHistory.push({ type: 'system', text: d.message || 'Erro na simulação' });
        renderChat();
        renderSteps(d.steps || [], 'failed');
        return;
      }

      appendBotLinesFromSteps(d.steps);

      if (!chatHistory.some((m) => m.type === 'bot') && d.message) {
        chatHistory.push({ type: 'system', text: d.message });
      } else if (!chatHistory.some((m) => m.type === 'bot') && !continueRun) {
        if (d.matched) {
          chatHistory.push({
            type: 'system',
            text: `Fluxo executado (${d.status}) — ${(d.steps || []).length} passos`
              + (d.matched_trigger ? ` · gatilho ${d.matched_trigger.type}/${d.matched_trigger.value}` : ''),
          });
        } else {
          chatHistory.push({
            type: 'system',
            text: 'Gatilho não encontrou nó Início correspondente — clique «Sincronizar gatilho» ou ajuste o cenário à esquerda.',
          });
        }
      }

      pausedRunId = (d.waiting_reply || d.status === 'paused') && d.run_id ? d.run_id : 0;
      const banner = document.getElementById('sim-paused-banner');
      if (banner) {
        banner.hidden = pausedRunId <= 0;
        if (pausedRunId > 0) {
          const hasConverse = (d.steps || []).some((s) => s.node_class === 'flow_converse');
          banner.querySelector('strong').textContent = hasConverse
            ? 'Atendimento fluido ativo'
            : 'Fluxo aguardando resposta';
        }
      }

      renderChat();
      renderSteps(d.steps || [], d.status);
      if (typeof window.refreshRunsList === 'function') window.refreshRunsList();
    } catch (e) {
      const msg = e && e.message ? e.message : String(e || '');
      const isTimeout = msg.includes('timeout') || msg.includes('Timeout') || msg.includes('timed out');
      const isAbort = msg.includes('abort') || msg.includes('AbortError');
      chatHistory.push({
        type: 'system',
        text: isTimeout ? 'Timeout — o servidor demorou demais (IA pode estar lenta)' :
             isAbort ? 'Requisição cancelada' :
             'Falha de rede' + (msg ? ' (' + msg.substring(0, 80) + ')' : ''),
      });
      renderChat();
    } finally {
      if (btn) btn.disabled = false;
      document.getElementById('sim-message').value = '';
    }
  }

  function syncTriggerFields() {
    const tt = document.getElementById('sim-trigger-type')?.value || '';
    const connWrap = document.getElementById('sim-wrap-connection');
    const tagWrap = document.getElementById('sim-wrap-tag');
    const stageWrap = document.getElementById('sim-wrap-stage');
    const whWrap = document.getElementById('sim-wrap-webhook');
    if (connWrap) connWrap.style.display = ['whatsapp_first', 'whatsapp_message'].includes(tt) ? '' : 'none';
    if (tagWrap) tagWrap.style.display = tt === 'tag_added' ? '' : 'none';
    if (stageWrap) stageWrap.style.display = tt === 'stage_enter' ? '' : 'none';
    if (whWrap) whWrap.style.display = tt === 'webhook_received' ? '' : 'none';
  }

  function bindSimulator() {
    if (window._simulatorBound) return;
    window._simulatorBound = true;
    document.getElementById('sim-send')?.addEventListener('click', runSimulation);
    document.getElementById('sim-message')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        runSimulation();
      }
    });
    document.getElementById('sim-trigger-type')?.addEventListener('change', syncTriggerFields);
    document.getElementById('sim-use-editor')?.addEventListener('change', syncSimFromEditor);
    document.getElementById('sim-sync-trigger')?.addEventListener('click', syncSimFromEditor);
    document.getElementById('sim-reset-chat')?.addEventListener('click', () => {
      chatHistory = [];
      pausedRunId = 0;
      document.getElementById('sim-paused-banner').hidden = true;
      renderChat();
      document.getElementById('sim-steps').innerHTML = '';
    });
    document.getElementById('sim-reset-session')?.addEventListener('click', () => {
      document.getElementById('sim-reset-chat')?.click();
    });
    const conn = document.getElementById('sim-connection');
    if (conn) conn.innerHTML = connOptions();
    syncTriggerFields();
    loadFlowsSelect();
    checkWorkerWarn();
    setTimeout(syncSimFromEditor, 300);
  }

  window.initAutomacoesSimulator = function () {
    bindSimulator();
    renderChat();
    syncSimFromEditor();
  };

  window.refreshSimulatorFlows = loadFlowsSelect;
  window.runFlowSimulation = runSimulation;
})();
