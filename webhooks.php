<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/migrations.php';

auvvo_run_migrations($pdo);
$user_id = (int) $_SESSION['user_id'];
auvvo_ensure_default_pipeline($pdo, $user_id);

require_once __DIR__ . '/backend/CrmPipelines.php';
$pipeSvc = new CrmPipelines($pdo);
$pipelinesFull = $pipeSvc->listPipelines($user_id);
$defaultPid = $pipeSvc->defaultPipelineId($user_id);
$crm_stages = $pipeSvc->stagesMap($user_id, $defaultPid);
$stagesByPipelineWh = [];
foreach ($pipelinesFull as $p) {
    $stagesByPipelineWh[(int) $p['id']] = $pipeSvc->stagesMap($user_id, (int) $p['id']);
}

$stmt = $pdo->prepare('SELECT id, name FROM agents WHERE user_id = ? AND status != ? ORDER BY name');
$stmt->execute([$user_id, 'draft']);
$agents = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Webhooks – Auvvo</title>
<link rel="stylesheet" href="app.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="icon" type="image/png" href="icone.png">
<style>
.wh-tabs{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.wh-tab{padding:10px 18px;border-radius:8px;border:1px solid var(--border-subtle);cursor:pointer;font-size:.875rem;background:#fff}
.wh-tab.active{background:var(--accent-teal);color:#fff;border-color:var(--accent-teal)}
.wh-grid{display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start}
@media(max-width:1000px){.wh-grid{grid-template-columns:1fr}}
.wh-card{padding:14px;border:1px solid var(--border-subtle);border-radius:12px;background:#fff;margin-bottom:10px}
.wh-card code{font-size:.7rem;word-break:break-all}
.log-pre{font-size:.7rem;background:#111;color:#9AE6B4;padding:10px;border-radius:8px;max-height:120px;overflow:auto;white-space:pre-wrap}
.var-pill{display:inline-block;padding:4px 10px;background:#ECFDF5;border-radius:16px;font-size:.75rem;margin:4px 4px 0 0}
.wh-inbound-layout{display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start}
@media(max-width:1100px){.wh-inbound-layout{grid-template-columns:1fr}}
.wh-card.clickable{cursor:pointer;border:2px solid transparent}
.wh-card.clickable.active{border-color:var(--accent-teal);background:#F0FDFA}
.map-row{display:grid;grid-template-columns:1fr 32px 1fr;gap:8px;align-items:center;margin-bottom:10px;font-size:.8125rem}
.map-path{padding:8px 10px;background:#F3F4F6;border-radius:8px;cursor:pointer;border:1px dashed #D1D5DB}
.map-path:hover{border-color:var(--accent-teal);background:#ECFDF5}
.path-chip{display:inline-block;padding:4px 8px;margin:4px 4px 0 0;background:#E0E7FF;border-radius:6px;font-size:.7rem;cursor:pointer}
.path-chip:hover{background:#C7D2FE}
.success-box{background:#ECFDF5;border:1px solid #6EE7B7;padding:14px;border-radius:10px;margin-top:12px;font-size:.8125rem}
.wh-step-badge{display:inline-block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#0D9488;background:#CCFBF1;padding:4px 8px;border-radius:6px;margin-bottom:8px}
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
  <div class="page-header">
    <div>
      <h1 class="page-title">Webhooks</h1>
      <p class="page-hint">Receba leads de Hotmart, Shopify, formulários e outras ferramentas. Mapeie os campos do JSON para o CRM.</p>
    </div>
    <div class="page-header-actions">
      <a href="integracoes" class="btn btn-outline">Integrações</a>
      <a href="automacoes" class="btn btn-outline">Automações</a>
    </div>
  </div>

  <div class="wh-tabs">
    <button type="button" class="wh-tab active" data-panel="inbound" onclick="switchWhTab('inbound')">Receber (inbound)</button>
    <button type="button" class="wh-tab" data-panel="outbound" onclick="switchWhTab('outbound')">Enviar (outbound)</button>
    <button type="button" class="wh-tab" data-panel="logs" onclick="switchWhTab('logs')">Histórico & variáveis</button>
  </div>

  <div id="panel-inbound" class="wh-inbound-layout">
    <div>
      <div class="app-card" style="margin-bottom:16px">
        <h3 style="font-size:1rem;margin-bottom:12px">1. Criar integração</h3>
        <div class="form-group">
          <label class="form-label">Nome (ex: Hotmart Vendas)</label>
          <input type="text" id="in-name" class="form-control" placeholder="Hotmart">
        </div>
        <div class="form-group">
          <label class="form-label">Identificador na URL (slug)</label>
          <input type="text" id="in-slug" class="form-control" placeholder="hotmart-vendas">
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;margin-bottom:12px">
          <input type="checkbox" id="in-phone-br" checked> Adicionar código do país <strong>55</strong> se o telefone vier sem DDI
        </label>
        <button type="button" class="btn btn-primary" style="width:100%" onclick="createInbound()">Gerar link do webhook</button>
        <div id="in-created" class="success-box" style="display:none"></div>
      </div>
      <h3 style="font-size:.875rem;margin-bottom:8px;color:var(--text-muted)">Suas integrações</h3>
      <div id="inbound-list"><p class="text-muted">Carregando…</p></div>
    </div>

    <div id="inbound-editor" class="app-card" style="display:none">
      <h3 style="font-size:1rem;margin-bottom:8px">2. Mapeamento visual</h3>
      <p class="text-muted" style="font-size:.8125rem;margin-bottom:16px">Cole o link na Hotmart/Shopify. Quando chegar o primeiro evento, os campos aparecem abaixo. Ou cole um JSON de exemplo.</p>

      <div style="background:#FAFAFA;padding:12px;border-radius:8px;margin-bottom:16px;font-size:.75rem">
        <strong>URL do webhook</strong><br>
        <code id="ed-url" style="word-break:break-all"></code>
        <div style="margin-top:8px">Token: <code id="ed-token"></code> · Header: <code>X-Webhook-Token</code></div>
      </div>

      <div class="form-group">
        <label class="form-label">JSON de exemplo (opcional — para mapear antes do 1º evento)</label>
        <select id="ed-template" class="form-control" style="margin-bottom:8px" onchange="applyWebhookTemplate(this.value)">
          <option value="">Modelo de JSON…</option>
          <option value="hotmart">Hotmart (compra)</option>
          <option value="form">Formulário genérico</option>
          <option value="shopify">Shopify (pedido)</option>
        </select>
        <textarea id="ed-sample" class="form-control" rows="4" placeholder='{"buyer":{"name":"João","email":"a@b.com","phone":"11999999999"}}'></textarea>
        <button type="button" class="btn btn-secondary" style="margin-top:8px" onclick="loadSamplePayload()">Carregar campos do JSON</button>
        <button type="button" class="btn btn-outline" style="margin-top:8px;margin-left:6px" onclick="refreshEditor()">Atualizar do último evento</button>
      </div>

      <div id="ed-path-chips" style="margin-bottom:16px"></div>

      <div id="ed-mapping"></div>

      <hr style="margin:24px 0;border:none;border-top:1px solid var(--border-subtle)">
      <span class="wh-step-badge">Passo 3</span>
      <h3 style="font-size:1rem;margin-bottom:12px">Ao receber o lead</h3>
      <p class="text-muted" style="font-size:.8125rem;margin-bottom:12px">Defina onde o contato entra no CRM assim que o webhook for processado.</p>
      <div class="form-group">
        <label class="form-label">Pipeline (funil CRM)</label>
        <select id="ed-entry-pipeline" class="form-control" onchange="onWebhookPipelineChange()">
          <?php foreach ($pipelinesFull as $pl): ?>
          <option value="<?= (int) $pl['id'] ?>" <?= (int) $pl['id'] === $defaultPid ? 'selected' : '' ?>><?= htmlspecialchars($pl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Estágio no funil</label>
        <select id="ed-entry-stage" class="form-control">
          <option value="">— primeiro estágio do pipeline —</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Tags fixas (além das mapeadas)</label>
        <input type="text" id="ed-entry-tags" class="form-control" placeholder="hotmart, boleto-gerado">
        <span class="text-muted" style="font-size:.7rem">Separe por vírgula</span>
      </div>
      <div class="form-group">
        <label class="form-label">Agente padrão (WhatsApp)</label>
        <select id="ed-default-agent" class="form-control">
          <option value="0">— nenhum —</option>
          <?php foreach ($agents as $ag): ?>
          <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;margin-bottom:12px">
        <input type="checkbox" id="ed-counts-purchase"> Registrar evento como <strong>compra</strong> (LTV) — ou detecta status «approved/paid» no JSON
      </label>

      <div class="form-group" style="margin-top:16px">
        <label class="form-label">Resposta para quem enviou o webhook (JSON)</label>
        <textarea id="ed-response" class="form-control" rows="3"></textarea>
      </div>

      <button type="button" class="btn btn-primary" onclick="saveMapping()">Salvar integração</button>
    </div>

    <div id="inbound-placeholder" class="app-card">
      <p class="text-muted" style="font-size:.875rem;margin:0">Selecione uma integração na lista ou crie um novo link à esquerda.</p>
    </div>
  </div>

  <div id="panel-outbound" class="wh-grid" style="display:none">
    <div>
      <h3 style="font-size:1rem;margin-bottom:12px">Webhooks outbound</h3>
      <div id="outbound-list"></div>
    </div>
    <div class="app-card">
      <h3 style="font-size:1rem;margin-bottom:16px">Novo / editar outbound</h3>
      <input type="hidden" id="out-id" value="0">
      <div class="form-group">
        <label class="form-label">Nome</label>
        <input type="text" id="out-name" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">URL destino</label>
        <input type="url" id="out-url" class="form-control" placeholder="https://api.seusistema.com/hook">
      </div>
      <div class="form-group">
        <label class="form-label">Corpo (JSON + {{contact.id}}, {{contact.stage}}…)</label>
        <textarea id="out-body" class="form-control" rows="4">{"contact_id":"{{contact.id}}","name":"{{contact.name}}","stage":"{{contact.stage}}"}</textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Extrair da resposta (JSON)</label>
        <textarea id="out-varmaps" class="form-control" rows="2" placeholder='[{"var_key":"external_id","json_path":"data.id"}]'></textarea>
      </div>
      <button type="button" class="btn btn-primary" style="width:100%;margin-bottom:8px" onclick="saveOutbound()">Salvar</button>
      <button type="button" class="btn btn-secondary" style="width:100%" onclick="testOutbound()">Testar disparo</button>
    </div>
  </div>

  <div id="panel-logs" style="display:none">
    <div class="app-card" style="margin-bottom:20px">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
        <div class="form-group" style="margin:0;flex:1;min-width:140px">
          <label class="form-label">Tipo</label>
          <select id="log-kind" class="form-control" onchange="loadLogs()">
            <option value="">Todos</option>
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:120px">
          <label class="form-label">ID webhook</label>
          <input type="number" id="log-wid" class="form-control" placeholder="opcional" onchange="loadLogs()">
        </div>
        <button type="button" class="btn btn-secondary" onclick="loadLogs()">Atualizar</button>
      </div>
    </div>
    <div id="logs-list"></div>
    <div class="app-card" style="margin-top:24px">
      <h3 style="font-size:1rem;margin-bottom:12px">Variáveis armazenadas</h3>
      <div style="display:flex;gap:12px;margin-bottom:12px">
        <select id="var-kind" class="form-control" style="max-width:140px"><option value="inbound">inbound</option><option value="outbound">outbound</option></select>
        <input type="number" id="var-wid" class="form-control" placeholder="ID webhook" style="max-width:120px">
        <button type="button" class="btn btn-secondary" onclick="loadVariables()">Ver variáveis</button>
      </div>
      <div id="vars-box"></div>
    </div>
  </div>
</main>
</div>
<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const API = 'backend/api.php';
const STAGES_BY_PIPELINE_WH = <?= json_encode($stagesByPipelineWh, JSON_UNESCAPED_UNICODE) ?>;
const DEFAULT_PIPELINE_WH = <?= (int) $defaultPid ?>;

function fillWebhookStageSelect(pipelineId, selectedSlug) {
  const sel = document.getElementById('ed-entry-stage');
  if (!sel) return;
  const map = STAGES_BY_PIPELINE_WH[pipelineId] || STAGES_BY_PIPELINE_WH[DEFAULT_PIPELINE_WH] || {};
  const cur = selectedSlug || sel.value || '';
  sel.innerHTML = '<option value="">— primeiro estágio do pipeline —</option>' +
    Object.keys(map).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(map[k])}</option>`).join('');
  if (cur) sel.value = cur;
}

function onWebhookPipelineChange() {
  const pid = parseInt(document.getElementById('ed-entry-pipeline')?.value || String(DEFAULT_PIPELINE_WH), 10);
  fillWebhookStageSelect(pid, '');
}

function switchWhTab(id) {
  document.querySelectorAll('.wh-tab').forEach(t => t.classList.toggle('active', t.dataset.panel === id));
  ['inbound','outbound','logs'].forEach(p => {
    const el = document.getElementById('panel-' + p);
    if (el) el.style.display = (p === id || (id === 'inbound' && p === 'inbound')) ? (p === 'logs' || p === id ? 'block' : 'none') : 'none';
  });
  document.getElementById('panel-inbound').style.display = id === 'inbound' ? 'grid' : 'none';
  if (id !== 'inbound') {
    document.getElementById('inbound-editor').style.display = 'none';
    document.getElementById('inbound-placeholder').style.display = 'block';
  }
  document.getElementById('panel-outbound').style.display = id === 'outbound' ? 'grid' : 'none';
  document.getElementById('panel-logs').style.display = id === 'logs' ? 'block' : 'none';
  if (id === 'logs') loadLogs();
}

let activeInboundId = 0;
const CRM_FIELDS = [
  { id: 'name', label: 'Nome' },
  { id: 'email', label: 'E-mail' },
  { id: 'phone', label: 'Telefone' },
  { id: 'company', label: 'Empresa' },
  { id: 'tag', label: 'Tag' },
];

async function loadInbound() {
  const d = await (await fetch(API + '?action=inbound_webhook_list')).json();
  const el = document.getElementById('inbound-list');
  if (d.error) {
    el.innerHTML = '<p class="text-muted">Erro ao carregar.</p>';
    return;
  }
  if (!d.webhooks?.length) {
    el.innerHTML = '<p class="text-muted">Nenhuma integração ainda.</p>';
    return;
  }
  el.innerHTML = d.webhooks.map(w => `
    <div class="wh-card clickable ${activeInboundId === w.id ? 'active' : ''}" onclick="openInboundEditor(${w.id})">
      <strong>#${w.id} — ${escapeHtml(w.name)}</strong>
      <div class="text-muted" style="font-size:.7rem;margin-top:4px">${escapeHtml(w.url_slug)}</div>
      <button type="button" class="btn btn-secondary" style="margin-top:10px;padding:4px 10px;font-size:.7rem" onclick="event.stopPropagation();deleteInbound(${w.id})">Remover</button>
    </div>`).join('');
}

function escapeHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
}

async function createInbound() {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'inbound_webhook_save');
  fd.append('name', document.getElementById('in-name').value);
  fd.append('url_slug', document.getElementById('in-slug').value);
  fd.append('phone_country_prefix', document.getElementById('in-phone-br').checked ? '55' : '');
  fd.append('field_maps', '[]');
  const res = await fetch(API, { method: 'POST', body: fd });
  let d;
  try { d = await res.json(); } catch (e) {
    return alert('Resposta inválida do servidor. Verifique migrações do banco.');
  }
  if (d.error) return alert(d.message || 'Erro');
  if (!d.id || d.id < 1) {
    return alert('Webhook não foi salvo (ID inválido). Atualize o servidor e rode: php backend/install_migrations.php');
  }
  const pre = document.getElementById('in-created');
  pre.style.display = 'block';
  pre.innerHTML = `<strong>Integração #${d.id} criada</strong><br>Copie a URL e cole na plataforma externa:<br><code style="word-break:break-all">${escapeHtml(d.url)}</code><br>Token: <code>${escapeHtml(d.secret_token)}</code>`;
  await loadInbound();
  openInboundEditor(d.id);
}

async function openInboundEditor(id) {
  activeInboundId = id;
  document.getElementById('inbound-placeholder').style.display = 'none';
  document.getElementById('inbound-editor').style.display = 'block';
  await loadInbound();
  const d = await (await fetch(API + '?action=inbound_webhook_detail&id=' + id)).json();
  if (d.error) return alert(d.message || 'Erro');
  const w = d.webhook;
  document.getElementById('ed-url').textContent = w.url || '';
  document.getElementById('ed-token').textContent = w.secret_token || '(use o token exibido na criação)';
  document.getElementById('ed-response').value = w.response_template || '{"ok":true,"contact_id":"{{contact.id}}"}';
  const pid = parseInt(w.entry_pipeline_id || DEFAULT_PIPELINE_WH, 10) || DEFAULT_PIPELINE_WH;
  const pipeSel = document.getElementById('ed-entry-pipeline');
  if (pipeSel) pipeSel.value = String(pid);
  fillWebhookStageSelect(pid, w.entry_stage || '');
  let entryTags = [];
  try { entryTags = JSON.parse(w.entry_tags || '[]'); } catch (e) {}
  document.getElementById('ed-entry-tags').value = Array.isArray(entryTags) ? entryTags.join(', ') : '';
  document.getElementById('ed-default-agent').value = w.default_agent_id || '0';
  document.getElementById('ed-counts-purchase').checked = w.counts_as_purchase == 1;
  renderPathChips(d.payload_paths || []);
  renderMappingForm(w.field_maps || [], d.payload_paths || []);
}

function renderPathChips(paths) {
  const el = document.getElementById('ed-path-chips');
  if (!paths.length) {
    el.innerHTML = '<span class="text-muted" style="font-size:.75rem">Aguardando payload — dispare um evento ou cole JSON de exemplo.</span>';
    return;
  }
  el.innerHTML = '<span style="font-size:.7rem;color:var(--text-muted)">Clique no campo do JSON, depois escolha o destino no select em foco:</span><br>' +
    paths.map((p, i) => `<span class="path-chip" data-path="${escapeHtml(p.path)}" onclick="pickPath(this.dataset.path)">${escapeHtml(p.path)} <em style="opacity:.7">${escapeHtml(p.sample||'')}</em></span>`).join('');
}

let pickTargetSelect = null;
function pickPath(path) {
  if (pickTargetSelect) {
    pickTargetSelect.value = path;
    pickTargetSelect = null;
    document.querySelectorAll('.map-row').forEach(r => r.style.outline = '');
  }
}

function renderMappingForm(existingMaps, paths) {
  const byField = {};
  existingMaps.forEach(m => { byField[m.crm_field] = m.json_path; });
  const pathOpts = paths.map(p => `<option value="${escapeHtml(p.path)}">${escapeHtml(p.path)}</option>`).join('');
  document.getElementById('ed-mapping').innerHTML = CRM_FIELDS.map(f => `
    <div class="map-row">
      <span><strong>${f.label}</strong></span>
      <span>→</span>
      <select class="form-control map-path-select" data-field="${f.id}">
        <option value="">— não mapear —</option>
        ${pathOpts}
      </select>
    </div>`).join('');
  document.querySelectorAll('.map-path-select').forEach(sel => {
    const field = sel.dataset.field;
    if (byField[field]) sel.value = byField[field];
    sel.addEventListener('focus', () => { pickTargetSelect = sel; });
  });
}

const WEBHOOK_TEMPLATES = {
  hotmart: { buyer: { name: 'Maria Silva', email: 'maria@email.com', phone: '11987654321' }, product: { name: 'Curso X' }, purchase: { status: 'approved' } },
  form: { name: 'João', email: 'joao@email.com', phone: '21999998888', message: 'Quero saber mais' },
  shopify: { customer: { first_name: 'Ana', email: 'ana@loja.com', phone: '+5511988776655' }, order: { id: '1001', total_price: '197.00' } },
};

function applyWebhookTemplate(key) {
  if (!key || !WEBHOOK_TEMPLATES[key]) return;
  document.getElementById('ed-sample').value = JSON.stringify(WEBHOOK_TEMPLATES[key], null, 2);
  document.getElementById('ed-template').value = '';
}

async function loadSamplePayload() {
  if (!activeInboundId) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'inbound_webhook_save_sample');
  fd.append('webhook_id', activeInboundId);
  fd.append('sample_payload', document.getElementById('ed-sample').value);
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d.error) return alert(d.message || 'JSON inválido');
  renderPathChips(d.payload_paths || []);
  const maps = [];
  document.querySelectorAll('.map-path-select').forEach(sel => {
    if (sel.value) maps.push({ crm_field: sel.dataset.field, json_path: sel.value });
  });
  renderMappingForm(maps, d.payload_paths || []);
}

function refreshEditor() {
  if (activeInboundId) openInboundEditor(activeInboundId);
}

async function saveMapping() {
  if (!activeInboundId) return;
  const maps = [];
  document.querySelectorAll('.map-path-select').forEach(sel => {
    if (sel.value) maps.push({ crm_field: sel.dataset.field, json_path: sel.value });
  });
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'inbound_webhook_save_maps');
  fd.append('webhook_id', activeInboundId);
  fd.append('field_maps', JSON.stringify(maps));
  const d1 = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d1.error) return alert(d1.message || 'Erro ao salvar mapas');

  const fd2 = new FormData();
  fd2.append('csrf_token', CSRF);
  fd2.append('action', 'inbound_webhook_update');
  fd2.append('id', activeInboundId);
  fd2.append('response_template', document.getElementById('ed-response').value);
  fd2.append('entry_pipeline_id', document.getElementById('ed-entry-pipeline')?.value || String(DEFAULT_PIPELINE_WH));
  fd2.append('entry_stage', document.getElementById('ed-entry-stage').value);
  fd2.append('entry_tags', document.getElementById('ed-entry-tags').value);
  fd2.append('default_agent_id', document.getElementById('ed-default-agent').value);
  fd2.append('counts_as_purchase', document.getElementById('ed-counts-purchase').checked ? '1' : '0');
  const d2 = await (await fetch(API, { method: 'POST', body: fd2 })).json();
  if (d2.error) return alert(d2.message || 'Erro ao salvar entrada');
  alert('Integração salva. Próximos eventos criarão ou atualizarão leads no CRM com estágio e tags definidos.');
}

async function updateInbound(id) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'inbound_webhook_update');
  fd.append('id', id);
  fd.append('response_template', document.getElementById('resp-' + id)?.value || '');
  await fetch(API, { method: 'POST', body: fd });
  loadInbound();
}

async function deleteInbound(id) {
  if (!confirm('Remover webhook #' + id + '?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'inbound_webhook_delete');
  fd.append('id', id);
  await fetch(API, { method: 'POST', body: fd });
  loadInbound();
}

async function loadOutbound() {
  const d = await (await fetch(API + '?action=outbound_webhook_list')).json();
  const el = document.getElementById('outbound-list');
  if (!d.webhooks?.length) {
    el.innerHTML = '<p class="text-muted">Nenhum outbound.</p>';
    return;
  }
  window._outHooks = {};
  d.webhooks.forEach(w => { window._outHooks[w.id] = w; });
  el.innerHTML = d.webhooks.map(w => `
    <div class="wh-card">
      <strong>#${w.id} — ${w.name}</strong>
      <div class="text-muted" style="font-size:.75rem;margin:6px 0">${w.target_url}</div>
      <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:4px 12px" onclick="editOutbound(${w.id})">Editar</button>
      <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:4px 12px" onclick="viewVars('outbound',${w.id})">Variáveis</button>
      <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:4px 12px" onclick="deleteOutbound(${w.id})">Remover</button>
    </div>`).join('');
}

function editOutbound(id) {
  const w = window._outHooks?.[id] || {};
  document.getElementById('out-id').value = id;
  document.getElementById('out-name').value = w.name || '';
  document.getElementById('out-url').value = w.target_url || '';
  document.getElementById('out-body').value = w.body_template || '';
  document.getElementById('out-varmaps').value = w.response_var_maps || '[]';
}

async function saveOutbound() {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'outbound_webhook_save');
  fd.append('id', document.getElementById('out-id').value);
  fd.append('name', document.getElementById('out-name').value);
  fd.append('target_url', document.getElementById('out-url').value);
  fd.append('body_template', document.getElementById('out-body').value);
  fd.append('response_var_maps', document.getElementById('out-varmaps').value);
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d.error) return alert(d.message || 'Erro');
  document.getElementById('out-id').value = '0';
  loadOutbound();
}

async function testOutbound() {
  const id = parseInt(document.getElementById('out-id').value, 10);
  if (!id) return alert('Salve o webhook antes ou edite um existente.');
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'webhook_test_outbound');
  fd.append('webhook_id', id);
  fd.append('context', JSON.stringify({ contact: { id: 1, name: 'Teste', stage: 'new' } }));
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  alert('HTTP ' + (d.result?.http_status || '?') + '\n' + JSON.stringify(d.result?.response || d.result, null, 2));
  loadLogs();
}

async function deleteOutbound(id) {
  if (!confirm('Remover?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'outbound_webhook_delete');
  fd.append('id', id);
  await fetch(API, { method: 'POST', body: fd });
  loadOutbound();
}

async function loadLogs() {
  const kind = document.getElementById('log-kind').value;
  const wid = document.getElementById('log-wid').value;
  let url = API + '?action=webhook_logs&limit=40';
  if (kind) url += '&kind=' + kind;
  if (wid) url += '&webhook_id=' + wid;
  const d = await (await fetch(url)).json();
  const el = document.getElementById('logs-list');
  if (!d.logs?.length) {
    el.innerHTML = '<p class="text-muted">Sem registros.</p>';
    return;
  }
  el.innerHTML = d.logs.map(l => `
    <div class="wh-card">
      <strong>#${l.id}</strong> ${l.webhook_kind} webhook ${l.webhook_id} · HTTP ${l.http_status || '—'} · ${l.status}
      <div class="text-muted" style="font-size:.7rem">${l.created_at}</div>
      <details style="margin-top:8px"><summary>Request / Response</summary>
        <pre class="log-pre">${(l.request_json||'').slice(0,2000)}</pre>
        <pre class="log-pre">${(l.response_json||'').slice(0,2000)}</pre>
      </details>
    </div>`).join('');
}

async function loadVariables() {
  const kind = document.getElementById('var-kind').value;
  const wid = document.getElementById('var-wid').value;
  if (!wid) return alert('Informe o ID do webhook');
  const d = await (await fetch(API + '?action=webhook_variables&kind=' + kind + '&webhook_id=' + wid)).json();
  const el = document.getElementById('vars-box');
  if (!d.variables?.length) {
    el.innerHTML = '<p class="text-muted">Nenhuma variável ainda (dispare o webhook primeiro).</p>';
    return;
  }
  el.innerHTML = d.variables.map(v =>
    `<span class="var-pill"><strong>${v.var_key}</strong>: ${(v.var_value||'').slice(0,80)}</span>`
  ).join('');
}

function viewVars(kind, id) {
  switchWhTab('logs');
  document.getElementById('var-kind').value = kind;
  document.getElementById('var-wid').value = id;
  loadVariables();
}

fillWebhookStageSelect(DEFAULT_PIPELINE_WH, '');
loadInbound();
loadOutbound();
</script>
</body>
</html>
