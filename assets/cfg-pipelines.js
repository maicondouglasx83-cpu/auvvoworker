/**
 * Editor de pipelines CRM — página Configurações.
 */
(function () {
  const API = 'backend/api.php';
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="csrf_token"]')?.value
    || '';

  let pipelines = [];
  let activePipelineId = 0;

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function toast(msg, type) {
    const el = document.getElementById('cfg-toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'cfg-toast cfg-toast--' + (type || 'ok');
    el.hidden = false;
    clearTimeout(el._t);
    el._t = setTimeout(() => {
      el.hidden = true;
    }, 4000);
  }

  async function apiPost(action, fields) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    Object.entries(fields || {}).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(API, { method: 'POST', body: fd });
    return res.json();
  }

  async function loadPipelines() {
    const res = await fetch(API + '?action=crm_list_pipelines');
    const d = await res.json();
    pipelines = d.pipelines || [];
    if (!activePipelineId && pipelines[0]) {
      activePipelineId = parseInt(pipelines[0].id, 10);
    }
    renderPipelineList();
    renderStageEditor();
  }

  function renderPipelineList() {
    const list = document.getElementById('cfg-pipeline-list');
    if (!list) return;
    if (!pipelines.length) {
      list.innerHTML = '<p class="text-muted">Nenhum pipeline. Crie o primeiro abaixo.</p>';
      return;
    }
    list.innerHTML = pipelines
      .map((p) => {
        const active = parseInt(p.id, 10) === activePipelineId;
        return `<button type="button" class="cfg-pipe-item${active ? ' is-active' : ''}" data-id="${p.id}">
          <span class="cfg-pipe-item-name">${esc(p.name)}</span>
          ${parseInt(p.is_default, 10) === 1 ? '<span class="cfg-pipe-badge">Padrão</span>' : ''}
          <span class="cfg-pipe-meta">${p.contact_count || 0} contatos · ${(p.stages || []).length} estágios</span>
        </button>`;
      })
      .join('');
    list.querySelectorAll('.cfg-pipe-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        activePipelineId = parseInt(btn.getAttribute('data-id'), 10);
        renderPipelineList();
        renderStageEditor();
      });
    });
  }

  function stageRowHtml(st, idx) {
    const id = st.id ? parseInt(st.id, 10) : 0;
    return `<div class="cfg-stage-row" data-id="${id}" data-idx="${idx}">
      <span class="cfg-stage-order">
        <button type="button" class="cfg-stage-up" title="Subir">↑</button>
        <button type="button" class="cfg-stage-down" title="Descer">↓</button>
      </span>
      <input type="color" class="cfg-stage-color" value="${esc(st.color || '#6366F1')}" title="Cor">
      <input type="text" class="form-control cfg-stage-label" value="${esc(st.label || '')}" placeholder="Nome do estágio">
      <input type="text" class="form-control cfg-stage-slug" value="${esc(st.slug || '')}" placeholder="slug-interno" title="Identificador (automações)">
      <label class="cfg-stage-flag"><input type="checkbox" class="cfg-stage-won" ${st.is_won == 1 || st.is_won === true ? 'checked' : ''}> Ganho</label>
      <label class="cfg-stage-flag"><input type="checkbox" class="cfg-stage-lost" ${st.is_lost == 1 || st.is_lost === true ? 'checked' : ''}> Perdido</label>
      <button type="button" class="btn btn-outline cfg-stage-del" title="Remover"><i class="ph-bold ph-trash"></i></button>
    </div>`;
  }

  function renderStageEditor() {
    const wrap = document.getElementById('cfg-stage-editor');
    const meta = document.getElementById('cfg-pipeline-meta');
    const p = pipelines.find((x) => parseInt(x.id, 10) === activePipelineId);
    if (!wrap) return;
    if (!p) {
      wrap.innerHTML = '<p class="text-muted">Selecione ou crie um pipeline.</p>';
      if (meta) meta.innerHTML = '';
      return;
    }
    if (meta) {
      meta.innerHTML = `
        <div class="cfg-pipeline-meta-grid">
          <div class="form-group">
            <label class="form-label">Nome do pipeline</label>
            <input type="text" id="cfg-pipe-name" class="form-control" value="${esc(p.name)}">
          </div>
          <div class="cfg-pipeline-meta-actions">
            ${parseInt(p.is_default, 10) !== 1 ? `<button type="button" class="btn btn-outline" id="cfg-set-default">Definir como padrão</button>` : '<span class="cfg-pipe-badge">Pipeline padrão</span>'}
            <button type="button" class="btn btn-outline" id="cfg-duplicate-pipeline">Duplicar funil</button>
            <button type="button" class="btn btn-outline" id="cfg-delete-pipeline" style="color:#b91c1c">Excluir pipeline</button>
          </div>
        </div>`;
      document.getElementById('cfg-set-default')?.addEventListener('click', () => setDefaultPipeline(p.id));
      document.getElementById('cfg-duplicate-pipeline')?.addEventListener('click', () => duplicatePipeline(p.id));
      document.getElementById('cfg-delete-pipeline')?.addEventListener('click', () => deletePipeline(p.id));
    }
    const stages = p.stages || [];
    wrap.innerHTML =
      '<p class="text-muted cfg-stage-hint">Arraste mentalmente pela ordem: estágios da esquerda para a direita no Kanban. Marque <strong>Perdido</strong> para exigir motivo ao mover cards.</p>' +
      '<div id="cfg-stage-rows">' +
      stages.map((s, i) => stageRowHtml(s, i)).join('') +
      '</div>' +
      '<button type="button" class="btn btn-secondary" id="cfg-add-stage" style="margin-top:12px"><i class="ph-bold ph-plus"></i> Adicionar estágio</button>' +
      '<button type="button" class="btn btn-primary" id="cfg-save-stages" style="margin-top:16px"><i class="ph-bold ph-floppy-disk"></i> Salvar estágios deste pipeline</button>';

    document.getElementById('cfg-add-stage')?.addEventListener('click', () => {
      const rows = document.getElementById('cfg-stage-rows');
      const i = rows.children.length;
      const div = document.createElement('div');
      div.innerHTML = stageRowHtml({ label: 'Novo estágio', slug: 'novo-' + Date.now(), color: '#6366F1' }, i);
      rows.appendChild(div.firstElementChild);
      bindStageRowEvents();
    });

    document.getElementById('cfg-save-stages')?.addEventListener('click', () => saveStages(p.id));
    bindStageRowEvents();
  }

  function moveStageRow(row, dir) {
    const parent = document.getElementById('cfg-stage-rows');
    if (!parent || !row) return;
    if (dir < 0 && row.previousElementSibling) {
      parent.insertBefore(row, row.previousElementSibling);
    } else if (dir > 0 && row.nextElementSibling) {
      parent.insertBefore(row.nextElementSibling, row);
    }
  }

  function bindStageRowEvents() {
    document.querySelectorAll('.cfg-stage-up').forEach((btn) => {
      btn.onclick = () => moveStageRow(btn.closest('.cfg-stage-row'), -1);
    });
    document.querySelectorAll('.cfg-stage-down').forEach((btn) => {
      btn.onclick = () => moveStageRow(btn.closest('.cfg-stage-row'), 1);
    });
    document.querySelectorAll('.cfg-stage-del').forEach((btn) => {
      btn.onclick = () => {
        const row = btn.closest('.cfg-stage-row');
        if (document.querySelectorAll('.cfg-stage-row').length <= 1) {
          toast('Mantenha pelo menos um estágio.', 'err');
          return;
        }
        row.remove();
      };
    });
    document.querySelectorAll('.cfg-stage-lost').forEach((cb) => {
      cb.onchange = () => {
        if (cb.checked) {
          const row = cb.closest('.cfg-stage-row');
          row.querySelector('.cfg-stage-won').checked = false;
        }
      };
    });
    document.querySelectorAll('.cfg-stage-won').forEach((cb) => {
      cb.onchange = () => {
        if (cb.checked) {
          const row = cb.closest('.cfg-stage-row');
          row.querySelector('.cfg-stage-lost').checked = false;
        }
      };
    });
  }

  function collectStages() {
    return Array.from(document.querySelectorAll('.cfg-stage-row')).map((row) => ({
      id: parseInt(row.getAttribute('data-id'), 10) || 0,
      label: row.querySelector('.cfg-stage-label')?.value?.trim() || '',
      slug: row.querySelector('.cfg-stage-slug')?.value?.trim() || '',
      color: row.querySelector('.cfg-stage-color')?.value || '#6366F1',
      is_won: row.querySelector('.cfg-stage-won')?.checked ? 1 : 0,
      is_lost: row.querySelector('.cfg-stage-lost')?.checked ? 1 : 0,
    }));
  }

  async function saveStages(pipelineId) {
    const name = document.getElementById('cfg-pipe-name')?.value?.trim();
    if (name) {
      const r = await apiPost('crm_save_pipeline', { id: pipelineId, name });
      if (r.error) {
        toast(r.message || 'Erro ao salvar nome', 'err');
        return;
      }
    }
    const stages = collectStages();
    if (!stages.length) {
      toast('Adicione pelo menos um estágio.', 'err');
      return;
    }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'crm_save_pipeline_stages');
    fd.append('pipeline_id', pipelineId);
    fd.append('stages', JSON.stringify(stages));
    const res = await fetch(API, { method: 'POST', body: fd });
    const d = await res.json();
    if (d.error) {
      toast(d.message || 'Erro ao salvar', 'err');
      return;
    }
    toast('Pipeline e estágios salvos!', 'ok');
    await loadPipelines();
  }

  async function createPipeline() {
    const name = document.getElementById('cfg-new-pipeline-name')?.value?.trim();
    if (!name) {
      toast('Digite o nome do pipeline.', 'err');
      return;
    }
    const d = await apiPost('crm_save_pipeline', { name, is_default: 0 });
    if (d.error) {
      toast(d.message || 'Erro', 'err');
      return;
    }
    document.getElementById('cfg-new-pipeline-name').value = '';
    activePipelineId = d.id;
    toast('Pipeline criado!', 'ok');
    await loadPipelines();
  }

  async function setDefaultPipeline(id) {
    const d = await apiPost('crm_set_default_pipeline', { pipeline_id: id });
    if (d.error) toast(d.message || 'Erro', 'err');
    else {
      toast('Pipeline padrão atualizado.', 'ok');
      await loadPipelines();
    }
  }

  async function duplicatePipeline(id) {
    const d = await apiPost('crm_duplicate_pipeline', { pipeline_id: id });
    if (d.error) toast(d.message || 'Erro', 'err');
    else {
      toast('Pipeline duplicado!', 'ok');
      activePipelineId = d.id;
      await loadPipelines();
    }
  }

  async function deletePipeline(id) {
    if (!confirm('Excluir este pipeline? Contatos serão movidos para o pipeline padrão.')) return;
    const d = await apiPost('crm_delete_pipeline', { pipeline_id: id });
    if (d.error) toast(d.message || 'Erro', 'err');
    else {
      toast(d.message || 'Pipeline removido.', 'ok');
      activePipelineId = 0;
      await loadPipelines();
    }
  }

  function bindNav() {
    document.querySelectorAll('.cfg-nav a').forEach((a) => {
      a.addEventListener('click', (e) => {
        const href = a.getAttribute('href');
        if (!href || !href.startsWith('#')) return;
        e.preventDefault();
        document.querySelector(href)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.querySelectorAll('.cfg-nav a').forEach((x) => x.classList.remove('is-active'));
        a.classList.add('is-active');
      });
    });
    if (location.hash) {
      document.querySelector('.cfg-nav a[href="' + location.hash + '"]')?.classList.add('is-active');
    }
  }

  function init() {
    bindNav();
    document.getElementById('cfg-create-pipeline')?.addEventListener('click', createPipeline);
    loadPipelines();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
