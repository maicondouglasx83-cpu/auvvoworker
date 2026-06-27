/**
 * Pacotes completos: criar agentes + fluxos com modal de confirmação.
 */
(function () {
  const API = window.FLOW_BOOT?.api || 'backend/api.php';
  const CSRF = window.FLOW_BOOT?.csrf
    || document.querySelector('meta[name="csrf-token"]')?.content
    || '';

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

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  let packsCache = [];
  let pendingPack = null;

  function agentsByKeyFromResponse(d) {
    const byKey = {};
    const agents = d.agents || {};
    const names = d.agent_names || {};
    Object.keys(agents).forEach((key) => {
      byKey[key] = { id: parseInt(agents[key], 10), name: names[key] || key, key };
    });
    return byKey;
  }

  async function fetchPacks() {
    if (packsCache.length) return packsCache;
    const d = await apiRequest(API + '?action=crm_list_pack_templates');
    packsCache = d.packs || [];
    return packsCache;
  }

  function renderPackGrid(packs) {
    const grid = document.getElementById('pack-template-grid');
    if (!grid) return;
    const sectors = [...new Set(packs.map((p) => p.sector))];
    grid.innerHTML = sectors
      .map((sec) => {
        const items = packs.filter((p) => p.sector === sec);
        return `<div class="flow-tpl-sector"><h4>${esc(sec)}</h4><div class="flow-tpl-cards">${items
          .map((p) => {
            const hl = (p.highlights || []).slice(0, 3).map((h) => `<li>${esc(h)}</li>`).join('');
            return `<button type="button" class="flow-tpl-card flow-tpl-card--pack" data-pack="${esc(p.id)}">
              <span class="flow-tpl-icon" style="background:${p.color}18;color:${p.color}"><i class="ph-bold ${p.icon}"></i></span>
              <strong>${esc(p.name)}</strong>
              <span>${esc(p.description)}</span>
              <span class="pack-badge">${p.agent_count} agente(s) · ${p.flow_count} fluxo(s)</span>
              ${hl ? `<ul class="pack-highlights">${hl}</ul>` : ''}
            </button>`;
          })
          .join('')}</div></div>`;
      })
      .join('');

    grid.querySelectorAll('[data-pack]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-pack');
        const pack = packs.find((x) => x.id === id);
        if (pack) openPackConfirm(pack);
      });
    });
  }

  function openPackConfirm(pack) {
    pendingPack = pack;
    const modal = document.getElementById('pack-confirm-modal');
    const title = document.getElementById('pack-confirm-title');
    const body = document.getElementById('pack-confirm-body');
    const list = document.getElementById('pack-confirm-agents');
    if (!modal || !body) return;

    if (title) title.textContent = pack.name;

    const flowDefs = (window.AUVVO_PACK_FLOWS && window.AUVVO_PACK_FLOWS[pack.id]) || [];
    const labels = pack.agent_labels || [];

    const iconStyle = pack.color ? `style="background:${pack.color}18;color:${pack.color}"` : '';
    body.innerHTML = `
      <div class="pack-confirm-hero">
        <span class="flow-tpl-icon pack-confirm-icon" ${iconStyle}><i class="ph-bold ${pack.icon || 'ph-package'}"></i></span>
        <div>
          <p class="pack-confirm-lead">Para usar o pacote <strong>${esc(pack.name)}</strong>, serão criados <strong>${pack.agent_count} agente(s)</strong> e <strong>${flowDefs.length || pack.flow_count} fluxo(s)</strong> na sua conta. Nada é publicado automaticamente.</p>
        </div>
      </div>
      <div class="pack-confirm-stats">
        <div class="pack-stat"><i class="ph-bold ph-robot"></i><span><strong>${pack.agent_count}</strong> agentes novos</span></div>
        <div class="pack-stat"><i class="ph-bold ph-git-branch"></i><span><strong>${flowDefs.length || pack.flow_count}</strong> fluxos (inativos)</span></div>
      </div>
      <ol class="pack-confirm-steps">
        <li>Confirme abaixo — agentes entram como <em>aguardando QR</em></li>
        <li>Conecte cada WhatsApp em <a href="agentes.php">Agentes</a></li>
        <li>Abra os fluxos aqui, revise handoff / memória / sessão</li>
        <li>Ative o fluxo e teste com mensagem real (worker ligado para delays)</li>
      </ol>
      <p class="pack-confirm-note">Sempre cria <strong>novos</strong> registros (não substitui agentes existentes). Parceiros entre agentes (handoff) já vêm pré-configurados no <code>flow_config</code>.</p>`;

    if (list) {
      list.innerHTML =
        '<h4>O que será criado</h4><ul>' +
        labels
          .map((l) => `<li><i class="ph-bold ph-check-circle"></i> ${esc(l)}</li>`)
          .join('') +
        flowDefs.map((f) => `<li><i class="ph-bold ph-flow-arrow"></i> Fluxo: ${esc(f.name)}</li>`).join('') +
        '</ul>';
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closePackConfirm() {
    const modal = document.getElementById('pack-confirm-modal');
    if (modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
    pendingPack = null;
  }

  async function applyPack(pack) {
    const btn = document.getElementById('pack-confirm-apply');
    const errEl = document.getElementById('pack-confirm-error');
    if (errEl) errEl.textContent = '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Criando agentes e fluxos…';
    }

    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('action', 'crm_apply_pack_template');
      fd.append('pack_id', pack.id);

      const d = await apiRequest(API, { method: 'POST', body: fd });
      if (d.error) {
        throw new Error(d.message || 'Erro ao aplicar pacote');
      }

      const byKey = agentsByKeyFromResponse(d);
      const flowDefs = (window.AUVVO_PACK_FLOWS && window.AUVVO_PACK_FLOWS[pack.id]) || [];

      const pid =
        (window.FLOW_BOOT && (window.FLOW_BOOT.automationPipelineId || window.FLOW_BOOT.defaultPipelineId)) || 0;

      for (const flowDef of flowDefs) {
        if (typeof flowDef.build !== 'function') continue;
        let exported = flowDef.build(byKey);
        // Remapear estágios para o pipeline selecionado (mesma lógica dos templates avulsos)
        if (typeof window.remapFlowExportStages === 'function') {
          const remapped = window.remapFlowExportStages(exported, pid);
          if (remapped && remapped.export) exported = remapped.export;
        }
        const ffd = new FormData();
        ffd.append('csrf_token', CSRF);
        ffd.append('action', 'crm_save_flow');
        ffd.append('name', flowDef.name);
        ffd.append('flow_data', JSON.stringify(exported));
        ffd.append('is_active', '1');
        if (pid) ffd.append('pipeline_id', String(pid));
        const fdRes = await apiRequest(API, { method: 'POST', body: ffd });
        if (fdRes.error) {
          console.warn('Fluxo nao salvo:', flowDef.name, fdRes.message);
        } else if (fdRes.is_active != 1 && fdRes.validation) {
          console.warn('Fluxo salvo como rascunho:', flowDef.name, (fdRes.validation.errors || []).map(function(e) { return e.message; }).join('; '));
        }
      }

      closePackConfirm();
      closePackPickerModal();

      const summary = document.getElementById('pack-success-banner');
      if (summary) {
        const names = (d.agent_rows || []).map(function(r) { return r.name; }).join(', ');
        summary.innerHTML =
          '<div class="pack-success-inner">' +
          '<strong>Pacote instalado:</strong> ' + esc(pack.name) + '<br>' +
          'Agentes: ' + esc(names || '—') + ' · ' + flowDefs.length + ' fluxo(s) criado(s).<br>' +
          '<span style="font-size:.8125rem;color:#F59E0B">Importante: ative os fluxos na aba Automacoes e conecte o WhatsApp dos agentes.</span><br>' +
          '<a href="agentes.php">Conectar WhatsApp</a> · ' +
          '<button type="button" class="pack-success-dismiss">Ok</button>' +
          '</div>';
        summary.hidden = false;
        summary.querySelector('.pack-success-dismiss')?.addEventListener('click', function() {
          summary.hidden = true;
        });
      }

      if (typeof window.onPackApplied === 'function') {
        window.onPackApplied(d, byKey);
      } else {
        window.location.reload();
      }
    } catch (e) {
      if (errEl) errEl.textContent = e.message || String(e);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Sim, criar agentes e fluxos';
      }
    }
  }

  function closePackPickerModal() {
    const modal = document.getElementById('pack-template-modal');
    if (modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }
  }

  async function openPackPickerModal() {
    const modal = document.getElementById('pack-template-modal');
    if (!modal) return;
    const packs = await fetchPacks();
    renderPackGrid(packs);
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function bindPackUi() {
    document.getElementById('btn-pack-templates')?.addEventListener('click', openPackPickerModal);
    document.getElementById('pack-template-close')?.addEventListener('click', closePackPickerModal);
    document.querySelector('#pack-template-modal .flow-modal-backdrop')?.addEventListener('click', closePackPickerModal);

    document.getElementById('pack-confirm-cancel')?.addEventListener('click', closePackConfirm);
    document.getElementById('pack-confirm-back')?.addEventListener('click', () => {
      closePackConfirm();
      openPackPickerModal();
    });
    document.querySelector('#pack-confirm-modal .flow-modal-backdrop')?.addEventListener('click', closePackConfirm);
    document.getElementById('pack-confirm-apply')?.addEventListener('click', () => {
      if (pendingPack) applyPack(pendingPack);
    });
  }

  window.initAutomacoesPacks = function (opts) {
    if (opts && typeof opts.onApplied === 'function') {
      window.onPackApplied = opts.onApplied;
    }
    bindPackUi();
  };
})();
