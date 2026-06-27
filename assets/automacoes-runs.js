/* Log de execuções de automações */
(function () {
  const API = window.API || 'backend/api.php';
  let lastRun = null;

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function fmtDate(s) {
    if (!s) return '—';
    try {
      return new Date(s.replace(' ', 'T')).toLocaleString('pt-BR');
    } catch (e) {
      return s;
    }
  }

  function statusBadge(st, mode) {
    const m = mode === 'simulate' ? 'sim' : 'live';
    return `<span class="run-badge run-badge--${esc(st)} run-badge--${m}">${esc(st)} · ${m === 'sim' ? 'teste' : 'real'}</span>`;
  }

  function renderRunDetail(run) {
    lastRun = run;
    const el = document.getElementById('runs-detail');
    if (!el || !run) return;
    const steps = run.steps || [];
    const errStep = steps.find((s) => s.status === 'error');
    el.innerHTML = `
      <header class="runs-detail-head">
        <h3>${esc(run.flow_name || 'Fluxo #' + run.flow_id)}</h3>
        ${statusBadge(run.status, run.mode)}
        <p class="text-muted">${fmtDate(run.started_at)} · ${esc(run.trigger_type)} / ${esc(run.trigger_value)}</p>
        ${run.message_preview ? `<p class="runs-preview">« ${esc(run.message_preview)} »</p>` : ''}
        ${run.error_message ? `<p class="runs-error">${esc(run.error_message)}</p>` : ''}
        <div class="runs-detail-actions">
          ${run.flow_id ? `<button type="button" class="btn btn-outline" id="runs-open-flow" style="font-size:.75rem">Abrir fluxo</button>` : ''}
          ${errStep?.node_id ? `<button type="button" class="btn btn-outline" id="runs-highlight-node" data-node="${esc(errStep.node_id)}" style="font-size:.75rem">Ver nó com erro</button>` : ''}
        </div>
      </header>
      <div class="runs-timeline">
        ${steps.length ? steps.map((s) => `
          <div class="runs-step runs-step--${esc(s.status || 'ok')}">
            <div class="runs-step-meta">
              <strong>${esc(s.node_label || s.node_class)}</strong>
              <span>${esc(s.status)}</span>
              ${s.node_id ? `<button type="button" class="runs-step-link" data-node="${esc(s.node_id)}">ver no editor</button>` : ''}
            </div>
            ${s.detail ? `<div class="runs-step-body">${esc(s.detail)}</div>` : ''}
          </div>`).join('') : '<p class="text-muted">Sem passos registrados.</p>'}
      </div>`;

    document.getElementById('runs-open-flow')?.addEventListener('click', async () => {
      if (typeof window.setAutomacoesTab === 'function') window.setAutomacoesTab('visual');
      if (typeof window.loadFlow === 'function' && run.flow_id) await window.loadFlow(run.flow_id);
    });
    document.getElementById('runs-highlight-node')?.addEventListener('click', (e) => {
      const nid = e.currentTarget.getAttribute('data-node');
      if (nid && typeof window.highlightFlowNode === 'function') window.highlightFlowNode(nid);
    });
    el.querySelectorAll('.runs-step-link').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const nid = btn.getAttribute('data-node');
        if (typeof window.setAutomacoesTab === 'function') window.setAutomacoesTab('visual');
        if (run.flow_id && typeof window.loadFlow === 'function') await window.loadFlow(run.flow_id);
        if (nid && typeof window.highlightFlowNode === 'function') window.highlightFlowNode(nid);
      });
    });
  }

  async function openRun(id) {
    try {
      const d = await (await fetch(API + '?action=crm_get_run&id=' + id)).json();
      if (d.error || !d.run) return;
      renderRunDetail(d.run);
      document.querySelectorAll('.runs-row').forEach((r) => r.classList.toggle('active', r.dataset.id === String(id)));
    } catch (e) {}
  }

  async function refreshRunsList() {
    const el = document.getElementById('runs-list');
    if (!el) return;
    const flowFilter = document.getElementById('runs-filter-flow')?.value || '';
    const modeFilter = document.getElementById('runs-filter-mode')?.value || 'live';
    let url = API + '?action=crm_list_runs&limit=50&mode=' + encodeURIComponent(modeFilter);
    if (flowFilter) url += '&flow_id=' + encodeURIComponent(flowFilter);
    try {
      const d = await (await fetch(url)).json();
      const runs = d.runs || [];
      if (!runs.length) {
        el.innerHTML = '<p class="text-muted" style="padding:16px">Nenhuma execução neste filtro.</p>';
        document.getElementById('runs-detail').innerHTML = '<p class="text-muted">Selecione uma execução.</p>';
        return;
      }
      el.innerHTML = runs
        .map(
          (r) => `<button type="button" class="runs-row" data-id="${r.id}">
            <div class="runs-row-top"><strong>${esc(r.flow_name || 'Fluxo')}</strong>${statusBadge(r.status, r.mode)}</div>
            <div class="runs-row-sub">${fmtDate(r.started_at)} · ${esc(r.trigger_type)}</div>
            ${r.message_preview ? `<div class="runs-row-msg">${esc(r.message_preview.slice(0, 80))}</div>` : ''}
          </button>`
        )
        .join('');
      el.querySelectorAll('.runs-row').forEach((btn) => {
        btn.addEventListener('click', () => openRun(btn.dataset.id));
      });
      openRun(runs[0].id);
    } catch (e) {
      el.innerHTML = '<p class="text-muted">Erro ao carregar execuções.</p>';
    }
  }

  async function loadFlowFilter() {
    const sel = document.getElementById('runs-filter-flow');
    if (!sel) return;
    try {
      const d = await (await fetch(API + '?action=crm_list_flows')).json();
      sel.innerHTML =
        '<option value="">Todos os fluxos</option>' +
        (d.flows || []).map((f) => `<option value="${f.id}">${esc(f.name)}</option>`).join('');
    } catch (e) {}
  }

  window.initAutomacoesRuns = function () {
    if (!window._runsBound) {
      window._runsBound = true;
      document.getElementById('runs-filter-flow')?.addEventListener('change', refreshRunsList);
      document.getElementById('runs-filter-mode')?.addEventListener('change', refreshRunsList);
      document.getElementById('runs-refresh')?.addEventListener('click', refreshRunsList);
    }
    loadFlowFilter();
    refreshRunsList();
  };

  window.refreshRunsList = refreshRunsList;
})();
