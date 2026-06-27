/* Melhorias Automações — publicar, wizard, playground, erros nos nós */
(function () {
  const API = window.API || 'backend/api.php';

  function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content
      || document.getElementById('csrf-token')?.value
      || window.FLOW_BOOT?.csrf
      || window.CSRF
      || '';
  }

  async function apiRequest(url, options) {
    if (window.AuvvoApi && typeof window.AuvvoApi.apiFetch === 'function') {
      return window.AuvvoApi.apiFetch(url, options);
    }
    const res = await fetch(url, options);
    if (!res.ok) {
      throw new Error('Erro HTTP ' + res.status);
    }
    return res.json();
  }

  function openFlowModal(modal) {
    if (!modal) return;
    modal.removeAttribute('hidden');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeFlowModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.hidden = true;
  }

  let nodeErrorsMap = {};
  let simPausedRunId = 0;

  window.getSimPausedRunId = () => simPausedRunId;
  window.setSimPausedRunId = (id) => { simPausedRunId = id || 0; };

  async function loadFlowNodeErrors(flowId) {
    if (!flowId) {
      nodeErrorsMap = {};
      return;
    }
    try {
      const d = await apiRequest(API + '?action=crm_flow_node_errors&flow_id=' + flowId);
      nodeErrorsMap = d.errors || {};
    } catch (e) {
      nodeErrorsMap = {};
    }
  }

  window.loadFlowNodeErrors = loadFlowNodeErrors;
  window.nodeErrorsFor = (nodeId) => nodeErrorsMap[String(nodeId)] || null;

  function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function showPublishModal(checklist) {
    const modal = document.getElementById('flow-publish-modal');
    const body = document.getElementById('flow-publish-checklist');
    if (!modal || !body) return Promise.resolve(false);
    const items = checklist?.items || [];
    const ready = checklist?.ready;
    body.innerHTML = items.length
      ? items
        .map((it) => {
          const st = it.status || 'info';
          const icon = st === 'ok' ? '✓' : st === 'fail' ? '✕' : st === 'warn' ? '!' : 'i';
          return `<div class="pub-item pub-item--${esc(st)}">
          <span class="pub-icon">${icon}</span>
          <div><strong>${esc(it.label)}</strong>${it.detail ? `<p>${esc(it.detail)}</p>` : ''}</div>
        </div>`;
        })
        .join('')
      : '<p class="text-muted">Nenhum item no checklist. Verifique se o fluxo está carregado.</p>';
    openFlowModal(modal);
    return new Promise((resolve) => {
      const onClose = (ok) => {
        closeFlowModal(modal);
        document.getElementById('flow-publish-confirm')?.removeEventListener('click', onOk);
        document.getElementById('flow-publish-cancel')?.removeEventListener('click', onCancel);
        modal.querySelector('.flow-modal-backdrop')?.removeEventListener('click', onCancel);
        resolve(ok);
      };
      const onOk = () => onClose(ready);
      const onCancel = () => onClose(false);
      document.getElementById('flow-publish-confirm')?.addEventListener('click', onOk);
      document.getElementById('flow-publish-cancel')?.addEventListener('click', onCancel);
      modal.querySelector('.flow-modal-backdrop')?.addEventListener('click', onCancel);
      const btn = document.getElementById('flow-publish-confirm');
      if (btn) btn.disabled = !ready;
    });
  }

  async function fetchChecklist() {
    const fd = new FormData();
    fd.append('csrf_token', getCsrf());
    fd.append('action', 'crm_flow_publish_checklist');
    if (typeof window.getCurrentFlowId === 'function') {
      const fid = window.getCurrentFlowId();
      if (fid) fd.append('flow_id', String(fid));
    }
    if (typeof window.getCurrentFlowExport === 'function') {
      const ex = window.getCurrentFlowExport();
      if (ex) fd.append('flow_data', JSON.stringify(ex));
    }
    try {
      const d = await apiRequest(API, { method: 'POST', body: fd });
      if (d.error) {
        window.toast?.(d.message || 'Não foi possível validar o fluxo', 'error');
        return {
          ready: false,
          items: [{ label: d.message || 'Erro ao validar', status: 'fail' }],
        };
      }
      return d.checklist || { ready: false, items: [] };
    } catch (e) {
      window.toast?.('Falha de rede ao validar publicação', 'error');
      return { ready: false, items: [{ label: 'Falha de rede', status: 'fail' }] };
    }
  }

  window.publishCurrentFlow = async function () {
    if (!window._flowEditor) {
      window.toast?.('Editor ainda não carregou — aguarde um instante', 'error');
      return false;
    }
    if (typeof window.syncOpenPropsToEditor === 'function') window.syncOpenPropsToEditor();
    const checklist = await fetchChecklist();
    const go = await showPublishModal(checklist);
    if (!go) return false;
    const active = document.getElementById('flow-active');
    if (active) active.checked = true;
    return window.saveCurrentFlowDraft(true);
  };

  window.saveCurrentFlowDraft = async function (publishing) {
    const editor = window._flowEditor;
    if (!editor) return false;
    if (typeof window.syncOpenPropsToEditor === 'function') window.syncOpenPropsToEditor();
    const name = document.getElementById('flow-name')?.value?.trim() || 'Nova automação';
    const isActive = publishing ? 1 : 0;
    const exported = editor.export();
    const fd = new FormData();
    fd.append('csrf_token', getCsrf());
    fd.append('action', 'crm_save_flow');
    const curId = typeof window.getCurrentFlowId === 'function' ? window.getCurrentFlowId() : 0;
    if (curId) fd.append('id', curId);
    fd.append('name', name);
    fd.append('flow_data', JSON.stringify(exported));
    fd.append('is_active', String(isActive));
    fd.append('pipeline_id', String(typeof window.getFlowPipelineId === 'function' ? window.getFlowPipelineId() : 0));
    if (publishing) fd.append('force_publish', '0');

    try {
      const d = await apiRequest(API, { method: 'POST', body: fd });
      if (d.error) {
        if (d.publish_blocked && d.validation) {
          window.toast?.(d.message || 'Corrija o fluxo antes de publicar', 'error');
          await showPublishModal({ ready: false, items: (d.validation.errors || []).map((e) => ({
            label: e.message,
            status: 'fail',
            detail: e.node_id ? 'Nó #' + e.node_id : '',
          })) });
        } else {
          window.toast?.(d.message || 'Erro ao salvar', 'error');
        }
        return false;
      }
      if (d.id && !curId && typeof window.loadFlow === 'function') {
        await window.loadFlow(d.id);
      }
      if (typeof window.loadFlowList === 'function') window.loadFlowList();
      document.getElementById('flow-active').checked = isActive === 1;
      if (typeof window.updateFlowPublishHint === 'function') window.updateFlowPublishHint();
      window.toast?.(publishing ? 'Fluxo publicado e ativo' : 'Rascunho salvo', 'success');
      const btn = document.getElementById('btn-flow-saved');
      if (btn) {
        btn.textContent = publishing ? 'Publicado ✓' : 'Salvo ✓';
        setTimeout(() => { btn.textContent = 'Salvar rascunho'; }, 2500);
      }
      return true;
    } catch (e) {
      window.toast?.('Falha de rede ao salvar', 'error');
      return false;
    }
  };

  function bindToolbar() {
    document.getElementById('btn-flow-publish')?.addEventListener('click', () => window.publishCurrentFlow());
    document.getElementById('btn-flow-save')?.addEventListener('click', (e) => {
      e.preventDefault();
      window.saveCurrentFlowDraft(false);
    });
    document.getElementById('btn-test-before-publish')?.addEventListener('click', () => {
      if (typeof window.setAutomacoesTab === 'function') window.setAutomacoesTab('test');
    });
    document.getElementById('btn-flow-journey')?.addEventListener('click', () => {
      if (typeof window.addWhatsAppJourney === 'function') window.addWhatsAppJourney();
    });
    document.getElementById('btn-playground-toggle')?.addEventListener('click', () => {
      document.getElementById('flow-playground')?.classList.toggle('flow-playground--open');
    });
    document.getElementById('btn-new-flow-blank')?.addEventListener('click', () => {
      if (typeof window.newFlowBlank === 'function') window.newFlowBlank();
    });
  }

  async function maybeShowWizard() {
    if (localStorage.getItem('auvvo_flow_wizard_done')) return;
    try {
      const d = await apiRequest(API + '?action=crm_list_flows');
      const flows = d.flows || [];
      const hasActive = flows.some((f) => f.is_active == 1);
      if (hasActive || flows.length > 2) {
        localStorage.setItem('auvvo_flow_wizard_done', '1');
        return;
      }
      if (typeof window.openJourneyModal === 'function') window.openJourneyModal();
    } catch (e) {}
  }

  function bindWizard() {
    document.getElementById('wizard-use-template')?.addEventListener('click', () => {
      localStorage.setItem('auvvo_flow_wizard_done', '1');
      closeFlowModal(document.getElementById('flow-wizard-modal'));
      if (typeof window.openTemplateModal === 'function') window.openTemplateModal();
    });
    document.getElementById('wizard-skip')?.addEventListener('click', () => {
      localStorage.setItem('auvvo_flow_wizard_done', '1');
      closeFlowModal(document.getElementById('flow-wizard-modal'));
    });
    document.querySelector('#flow-wizard-modal .flow-modal-backdrop')?.addEventListener('click', () => {
      closeFlowModal(document.getElementById('flow-wizard-modal'));
    });
  }

  window.highlightFlowNode = function (nodeId) {
    if (typeof window.setAutomacoesTab === 'function') window.setAutomacoesTab('visual');
    const el = document.querySelector('#node-' + nodeId);
    if (el) {
      el.classList.add('fn-node--highlight');
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => el.classList.remove('fn-node--highlight'), 3000);
    }
    if (window._flowEditor?.selectNode) {
      try { window._flowEditor.selectNode(parseInt(nodeId, 10)); } catch (e) {}
    }
  };

  window.initAutomacoesImprovements = function () {
    bindToolbar();
    bindWizard();
    bindPlayground();
    setTimeout(maybeShowWizard, 800);
  };

  function bindPlayground() {
    document.getElementById('pg-send')?.addEventListener('click', async () => {
      const msg = document.getElementById('pg-message')?.value?.trim();
      if (!msg || typeof window.getCurrentFlowExport !== 'function') return;
      const pgChat = document.getElementById('pg-chat-messages');
      if (pgChat.querySelector('.sim-chat-empty')) pgChat.innerHTML = '';
      pgChat.innerHTML += `<div class="sim-msg sim-msg--user">${esc(msg)}</div>`;
      document.getElementById('pg-message').value = '';

      const exp = window.getCurrentFlowExport();
      const tr = typeof window.extractFlowTriggerFromExport === 'function'
        ? window.extractFlowTriggerFromExport(exp)
        : { trigger_type: 'whatsapp_first', trigger_value: '*' };

      const fd = new FormData();
      fd.append('csrf_token', getCsrf());
      fd.append('action', 'crm_simulate_flow');
      fd.append('flow_data', JSON.stringify(exp));
      fd.append('trigger_type', tr.trigger_type || 'whatsapp_first');
      fd.append('trigger_value', tr.trigger_value || '*');
      fd.append('message_body', msg);
      fd.append('name', 'Teste');
      fd.append('phone', '11999998888');
      const boot = window.FLOW_BOOT || {};
      const conn = (boot.whatsappConnections && boot.whatsappConnections[0]) ? boot.whatsappConnections[0].id : 0;
      if (conn > 0) fd.append('connection_id', String(conn));
      if (document.getElementById('pg-use-llm')?.checked) fd.append('use_llm', '1');
      try {
        const d = await apiRequest(API, { method: 'POST', body: fd });
        if (!d.matched && d.steps?.length) {
          pgChat.innerHTML += `<div class="sim-msg sim-msg--system">Gatilho não bateu — ajuste o nó Início ou use a aba Testar.</div>`;
        }
        (d.steps || []).forEach((s) => {
          let text = s.detail || '';
          if (s.node_class === 'flow_message') text = text.replace(/^WhatsApp \(simulado\): /, '');
          if (s.node_class === 'flow_agent' || s.node_class === 'flow_think') {
            const nl = text.indexOf('\n');
            if (nl >= 0) text = text.slice(nl + 1);
          }
          if (text) {
            const ai = s.node_class === 'flow_agent' || s.node_class === 'flow_think';
            pgChat.innerHTML += `<div class="sim-msg ${ai ? 'sim-msg--ai' : 'sim-msg--bot'}"><small>${esc(s.node_label || '')}</small><div>${esc(String(text).slice(0, 400))}</div></div>`;
          }
        });
        pgChat.scrollTop = pgChat.scrollHeight;
      } catch (e) {
        pgChat.innerHTML += `<div class="sim-msg sim-msg--system">Erro no teste rápido</div>`;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (window._flowEditor) bindToolbar();
  });
})();
