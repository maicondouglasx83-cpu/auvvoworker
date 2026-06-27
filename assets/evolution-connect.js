/**
 * Conexões WhatsApp nomeadas (Evolution) — independentes dos agentes/cérebro.
 */
(function () {
  let statusInterval = null;
  let currentConnectionId = 0;
  let connectionsCache = [];

  function csrfToken() {
    return document.getElementById('csrf-token')?.value
      || document.querySelector('input[name="csrf_token"]')?.value
      || '';
  }

  function qrBox() { return document.getElementById('evo-qr-box'); }
  function statusEl() { return document.getElementById('evo-status'); }
  function actionsEl() { return document.getElementById('evo-actions'); }
  function hiddenSelect() { return document.getElementById('wa-connection-select'); }
  function editPanel() { return document.getElementById('evo-conn-edit'); }

  function clearPoll() {
    if (statusInterval) {
      clearInterval(statusInterval);
      statusInterval = null;
    }
  }

  function badgeForStatus(st) {
    if (st === 'online') return ['online', 'Conectado'];
    if (st === 'waiting_qr') return ['waiting', 'Aguardando QR'];
    return ['offline', 'Desconectado'];
  }

  const AI_MODE_LABELS = {
    flows_first: 'Fluxos',
    standalone: 'Agente',
    flows_only: 'Só fluxo',
  };

  const AI_MODE_DESC = {
    flows_first: 'Fluxos publicados nesta linha têm prioridade. O agente padrão só responde se nenhum fluxo tratar a mensagem.',
    standalone: 'O agente padrão responde livremente. Fluxos ainda podem enviar mensagens, mas não bloqueiam o agente.',
    flows_only: 'Somente automações respondem. O agente livre nunca envia mensagens nesta linha.',
  };

  function updateAiModeDesc(mode) {
    const el = document.getElementById('evo-ai-mode-desc');
    if (!el) return;
    el.textContent = AI_MODE_DESC[mode] || AI_MODE_DESC.flows_first;
  }

  function currentConnection() {
    return connectionsCache.find(c => parseInt(c.id, 10) === parseInt(currentConnectionId, 10)) || null;
  }

  function renderConnList(connections, activeId) {
    const box = document.getElementById('wa-conn-list');
    if (!box) return;
    if (!connections.length) {
      box.innerHTML = '<p class="text-muted" style="font-size:.8125rem;padding:8px 0">Nenhuma conexão ainda. Crie uma acima.</p>';
      return;
    }
    box.innerHTML = connections.map(c => {
      const [cls, lbl] = badgeForStatus(c.status);
      const active = parseInt(c.id, 10) === parseInt(activeId, 10) ? ' active' : '';
      const mode = c.ai_mode || 'flows_first';
      const modeLbl = AI_MODE_LABELS[mode] || 'Fluxos';
      return `<div class="wa-conn-item${active}" data-conn-id="${c.id}">
        <button type="button" class="wa-conn-select" data-conn-id="${c.id}">
          <span class="wa-conn-name">${escapeHtml(c.name)}</span>
          <span class="wa-conn-mode" title="Modo IA">${escapeHtml(modeLbl)}</span>
          <span class="wa-conn-badge ${cls}">${lbl}</span>
        </button>
        <div class="wa-conn-actions">
          <button type="button" class="wa-conn-btn wa-conn-rename" data-conn-id="${c.id}" title="Renomear"><i class="ph-bold ph-pencil-simple"></i></button>
          <button type="button" class="wa-conn-btn wa-conn-delete" data-conn-id="${c.id}" title="Excluir"><i class="ph-bold ph-trash"></i></button>
        </div>
      </div>`;
    }).join('');

    box.querySelectorAll('.wa-conn-select').forEach(btn => {
      btn.addEventListener('click', () => initEvolution(parseInt(btn.dataset.connId, 10)));
    });
    box.querySelectorAll('.wa-conn-rename').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        initEvolution(parseInt(btn.dataset.connId, 10));
        document.getElementById('evo-conn-name')?.focus();
      });
    });
    box.querySelectorAll('.wa-conn-delete').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        deleteConnection(parseInt(btn.dataset.connId, 10));
      });
    });
  }

  function connectionOptionHtml(c) {
    const [, lbl] = badgeForStatus(c.status);
    return `<option value="${c.id}">${escapeHtml(c.name)} (${lbl})</option>`;
  }

  function syncConnectionDropdowns(connections) {
    const base = ['<option value="">— Selecione —</option>'].concat(
      connections.map(c => connectionOptionHtml(c))
    );
    const anyOpt = '<option value="*">Qualquer conexão</option>';
    const map = {
      'b-connection': base.join(''),
      'a-connection': base.join(''),
      'a-trigger-connection': [anyOpt].concat(connections.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)).join(''),
    };
    Object.keys(map).forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      const prev = el.value;
      el.innerHTML = map[id];
      if (prev) el.value = prev;
    });
  }

  function syncEditPanel(conn) {
    const panel = editPanel();
    if (!panel) return;
    if (!conn) {
      panel.style.display = 'none';
      return;
    }
    panel.style.display = 'block';
    const nameEl = document.getElementById('evo-conn-name');
    const agentEl = document.getElementById('evo-conn-default-agent');
    const modeEl = document.getElementById('evo-conn-ai-mode');
    if (nameEl) nameEl.value = conn.name || '';
    if (agentEl) agentEl.value = String(conn.default_agent_id || '');
    const mode = conn.ai_mode || 'flows_first';
    if (modeEl) {
      modeEl.value = mode;
      updateAiModeDesc(mode);
    }
  }

  function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function showConnected(label) {
    const box = qrBox();
    const st = statusEl();
    const act = actionsEl();
    if (!box || !st) return;
    box.innerHTML = '<div style="padding:32px;background:#F0FFF4;border-radius:12px;color:#10B981;text-align:center"><i class="ph-fill ph-whatsapp-logo" style="font-size:3rem;margin-bottom:8px"></i><strong style="display:block">Conectado</strong></div>';
    st.innerHTML = '<span style="color:#10B981"><i class="ph-bold ph-check-circle"></i> ' + (label || 'Online') + '</span>';
    if (act) {
      act.innerHTML = '<button type="button" class="btn btn-outline" style="font-size:.8125rem" id="btn-evo-disconnect"><i class="ph-bold ph-plugs"></i> Desconectar</button>';
      document.getElementById('btn-evo-disconnect')?.addEventListener('click', disconnect);
    }
  }

  function pollStatus() {
    if (!currentConnectionId) return;
    fetch('backend/api.php?action=evolution_status&connection_id=' + currentConnectionId)
      .then(r => r.json())
      .then(d => {
        if (d.state === 'open') {
          clearPoll();
          showConnected(d.label);
          loadConnectionsList();
        }
      })
      .catch(() => {});
  }

  function requestQR() {
    if (!currentConnectionId) return;
    const box = qrBox();
    const st = statusEl();
    const act = actionsEl();
    if (!box || !st) return;
    clearPoll();
    if (act) act.innerHTML = '';
    box.innerHTML = '<div style="padding:32px;text-align:center"><i class="ph-bold ph-circle-notch ph-spin" style="font-size:2rem;color:var(--accent-teal)"></i><br><span style="font-size:.875rem;color:var(--text-muted);display:inline-block;margin-top:12px">Gerando QR Code…</span></div>';
    st.textContent = 'Aguardando QR Code…';

    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('action', 'evolution_connect');
    fd.append('connection_id', String(currentConnectionId));

    fetch('backend/api.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.error) {
          box.innerHTML = '<div style="padding:16px;color:#EF4444;font-size:.875rem">' + (d.message || 'Erro na conexão') + '</div>';
          st.textContent = 'Erro';
          return;
        }
        if (d.qr_code) {
          box.innerHTML = '<img src="' + d.qr_code + '" alt="QR WhatsApp" style="width:220px;height:auto;border-radius:12px">';
          st.textContent = 'Escaneie o QR no WhatsApp (Aparelhos conectados)';
          let pollCount = 0;
          statusInterval = setInterval(() => {
            if (++pollCount > 24) {
              clearPoll();
              st.textContent = 'QR expirado — clique em Gerar QR Code novamente.';
              return;
            }
            pollStatus();
          }, 5000);
        } else if (d.instance) {
          st.textContent = 'Conectando…';
          statusInterval = setInterval(pollStatus, 5000);
        }
      })
      .catch(() => {
        box.innerHTML = '<div style="padding:16px;color:#EF4444;font-size:.875rem">Falha ao comunicar com Evolution API</div>';
      });
  }

  function disconnect() {
    if (!currentConnectionId || !confirm('Desconectar esta linha WhatsApp?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('action', 'evolution_disconnect');
    fd.append('connection_id', String(currentConnectionId));
    fetch('backend/api.php', { method: 'POST', body: fd }).then(() => {
      loadConnectionsList().then(() => initEvolution(currentConnectionId));
    });
  }

  async function saveConnectionMeta() {
    if (!currentConnectionId) return;
    const name = (document.getElementById('evo-conn-name')?.value || '').trim();
    if (name.length < 2) return alert('Nome da conexão: mínimo 2 caracteres.');
    const defaultAgent = parseInt(document.getElementById('evo-conn-default-agent')?.value || '0', 10) || 0;
    const aiMode = document.getElementById('evo-conn-ai-mode')?.value || 'flows_first';
    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('action', 'update_whatsapp_connection');
    fd.append('connection_id', String(currentConnectionId));
    fd.append('name', name);
    fd.append('ai_mode', aiMode);
    if (defaultAgent > 0) fd.append('default_agent_id', String(defaultAgent));
    const d = await (await fetch('backend/api.php', { method: 'POST', body: fd })).json();
    if (d.error) return alert(d.message || 'Erro ao salvar');
    await loadConnectionsList();
    if (typeof window.toast === 'function') window.toast('Conexão atualizada', 'success');
  }

  async function deleteConnection(id) {
    const cid = parseInt(id, 10) || 0;
    if (!cid || !confirm('Excluir esta conexão WhatsApp? Automações que usam esta linha precisarão ser ajustadas.')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('action', 'delete_whatsapp_connection');
    fd.append('connection_id', String(cid));
    const d = await (await fetch('backend/api.php', { method: 'POST', body: fd })).json();
    if (d.error) return alert(d.message || 'Erro ao excluir');
    if (currentConnectionId === cid) currentConnectionId = 0;
    const list = await loadConnectionsList();
    if (list.length) initEvolution(list[0].id);
    else initEvolution(0);
    if (typeof window.toast === 'function') window.toast('Conexão removida', 'success');
  }

  async function loadConnectionsList() {
    try {
      const d = await (await fetch('backend/api.php?action=list_whatsapp_connections')).json();
      const list = d.connections || [];
      connectionsCache = list;
      renderConnList(list, currentConnectionId);
      syncConnectionDropdowns(list);
      if (window.FLOW_BOOT) window.FLOW_BOOT.whatsappConnections = list.map(c => ({
        id: parseInt(c.id, 10),
        name: c.name,
        status: c.status || 'offline',
        ai_mode: c.ai_mode || 'flows_first',
      }));
      syncEditPanel(currentConnection());
      return list;
    } catch (e) {
      return [];
    }
  }

  function initEvolution(connectionId) {
    currentConnectionId = parseInt(connectionId, 10) || 0;
    const hid = hiddenSelect();
    if (hid) hid.value = String(currentConnectionId || 0);
    document.querySelectorAll('.wa-conn-item').forEach(el => {
      el.classList.toggle('active', parseInt(el.dataset.connId, 10) === currentConnectionId);
    });
    syncEditPanel(currentConnection());

    const box = qrBox();
    const st = statusEl();
    const act = actionsEl();
    if (!currentConnectionId || !box || !st) {
      if (st) st.textContent = 'Selecione ou crie uma conexão';
      if (act) act.innerHTML = '';
      return;
    }

    clearPoll();
    if (act) act.innerHTML = '<button type="button" class="btn btn-primary" id="btn-evo-qr" style="font-size:.8125rem"><i class="ph-bold ph-qr-code"></i> Gerar QR Code</button>';
    document.getElementById('btn-evo-qr')?.addEventListener('click', requestQR);

    box.innerHTML = '<i class="ph-bold ph-qr-code" style="font-size:3rem;color:var(--border-subtle)"></i>';
    st.innerHTML = '<i class="ph-bold ph-circle-notch ph-spin"></i> Verificando…';

    fetch('backend/api.php?action=evolution_status&connection_id=' + currentConnectionId)
      .then(r => r.json())
      .then(d => {
        if (d.state === 'open') {
          showConnected(d.label);
        } else if (d.state === 'not_configured' || d.state === 'no_credentials') {
          box.innerHTML = '<div style="padding:16px;color:var(--text-muted);font-size:.875rem;text-align:center"><i class="ph-fill ph-warning-circle" style="color:#F59E0B"></i><br>Evolution API não configurada.</div>';
          st.textContent = d.label || 'Não configurado';
          if (act) act.innerHTML = '<button type="button" class="btn btn-primary" id="btn-evo-qr2" style="font-size:.8125rem"><i class="ph-bold ph-qr-code"></i> Gerar QR Code</button>';
          document.getElementById('btn-evo-qr2')?.addEventListener('click', requestQR);
        } else {
          if (act) act.innerHTML = '<button type="button" class="btn btn-primary" id="btn-evo-qr3" style="font-size:.8125rem"><i class="ph-bold ph-qr-code"></i> Gerar QR Code</button>';
          document.getElementById('btn-evo-qr3')?.addEventListener('click', requestQR);
          st.textContent = 'Pronto para conectar';
        }
      })
      .catch(() => { st.textContent = 'Erro ao verificar status'; });
  }

  window.selectWaConnection = function (id) {
    initEvolution(id);
  };

  async function createConnection() {
    const nameEl = document.getElementById('wa-new-name');
    const name = (nameEl?.value || '').trim();
    if (name.length < 2) {
      alert('Informe um nome para a conexão (mín. 2 caracteres).');
      nameEl?.focus();
      return;
    }
    const defaultAgent = parseInt(document.getElementById('wa-default-agent')?.value || '0', 10) || 0;
    const aiMode = document.getElementById('wa-default-ai-mode')?.value || 'flows_first';
    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('action', 'create_whatsapp_connection');
    fd.append('name', name);
    fd.append('ai_mode', aiMode);
    if (defaultAgent > 0) fd.append('default_agent_id', String(defaultAgent));
    const d = await (await fetch('backend/api.php', { method: 'POST', body: fd })).json();
    if (d.error) return alert(d.message || 'Erro ao criar conexão');
    if (nameEl) nameEl.value = '';
    await loadConnectionsList();
    if (d.connection?.id) initEvolution(d.connection.id);
    if (typeof window.toast === 'function') window.toast('Conexão «' + name + '» criada!', 'success');
  }

  function refreshSelection() {
    const hid = hiddenSelect();
    const id = parseInt(hid?.value || '0', 10);
    if (id > 0) initEvolution(id);
  }

  function bindUi() {
    document.getElementById('btn-wa-create')?.addEventListener('click', createConnection);
    document.getElementById('btn-evo-save-conn')?.addEventListener('click', saveConnectionMeta);
    document.getElementById('evo-conn-ai-mode')?.addEventListener('change', (e) => {
      updateAiModeDesc(e.target.value || 'flows_first');
    });
    loadConnectionsList().then(() => {
      const hid = hiddenSelect();
      const initial = parseInt(hid?.value || '0', 10);
      if (initial > 0) initEvolution(initial);
    });
  }

  window.auvvoEvolutionConnect = { init: initEvolution, refreshSelection, loadConnectionsList };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }
})();
