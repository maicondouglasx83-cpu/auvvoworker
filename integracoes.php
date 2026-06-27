<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/migrations.php';
require_once 'backend/integrations_catalog.inc.php';
require_once 'backend/GoogleSheets.php';
require_once 'backend/GoogleCalendar.php';
require_once 'backend/ApiAuth.php';

auvvo_run_migrations($pdo);
$user_id = (int) $_SESSION['user_id'];
$panel = preg_replace('/[^a-z_]/', '', strtolower($_GET['panel'] ?? 'hub'));
$catalog = auvvo_integrations_catalog();
$gsToken = GoogleSheets::loadToken($pdo, $user_id);
$gcalToken = GoogleCalendar::loadToken($pdo, $user_id);
$apiBase = app_http_url('backend/api/v1.php');

$catLabels = [
    'google' => 'Google Workspace',
    'comms'  => 'Comunicação',
    'api'    => 'APIs & Webhooks',
    'ai'     => 'Inteligência artificial',
    'internal' => 'Plataforma',
];
$byCat = [];
foreach ($catalog as $item) {
    $byCat[$item['category'] ?? 'internal'][] = $item;
}
$panelTitles = [
    'hub' => 'Catálogo',
    'sheets' => 'Google Sheets',
    'api' => 'API REST',
    'http' => 'HTTP externo',
];
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Integrações – Auvvo</title>
<link rel="stylesheet" href="app.css">
<link rel="stylesheet" href="assets/integracoes.css?v=20260524">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="icon" type="image/png" href="icone.png">
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main int-shell">
  <div class="page-header">
    <div>
      <h1 class="page-title">Integrações</h1>
      <p class="page-hint">Conecte serviços externos. Cada integração abre em painel dedicado — sem rolagem infinita.</p>
    </div>
    <div class="page-header-actions">
      <a href="conexoes" class="btn btn-secondary"><i class="ph-bold ph-whatsapp-logo"></i> WhatsApp</a>
      <a href="configuracoes" class="btn btn-outline"><i class="ph-bold ph-gear"></i> Configurações</a>
    </div>
  </div>

  <?php if ($panel !== 'hub'): ?>
  <nav class="int-breadcrumb">
    <a href="integracoes"><i class="ph-bold ph-arrow-left"></i> Catálogo</a>
    <span>/</span>
    <span><?= htmlspecialchars($panelTitles[$panel] ?? ucfirst($panel)) ?></span>
  </nav>
  <div class="int-panel-wrap">
  <?php endif; ?>

  <?php if ($panel === 'hub'): ?>
  <div class="int-categories">
    <?php foreach ($byCat as $catKey => $items): ?>
    <section>
      <h2 class="int-cat-title"><?= htmlspecialchars($catLabels[$catKey] ?? $catKey) ?></h2>
      <div class="int-hub">
        <?php foreach ($items as $item):
          $st = auvvo_integration_status($pdo, $user_id, $item['id']);
          $iconCls = match ($item['category'] ?? '') {
            'api' => 'int-icon--api',
            'comms' => 'int-icon--wa',
            default => '',
          };
          $isPanel = str_contains($item['config_url'], 'integracoes?panel=');
          $href = $item['config_url'];
        ?>
        <article class="int-card<?= $st['connected'] ? ' connected' : '' ?>">
          <div class="int-card-head">
            <div class="int-icon <?= $iconCls ?>"><i class="ph-bold <?= htmlspecialchars($item['icon']) ?>"></i></div>
            <div class="int-card-body">
              <strong><?= htmlspecialchars($item['name']) ?></strong>
              <p><?= htmlspecialchars($item['description']) ?></p>
            </div>
          </div>
          <div class="int-card-foot">
            <span class="badge <?= $st['connected'] ? 'badge-success' : 'badge-gray' ?>" style="font-size:.7rem"><?= htmlspecialchars($st['detail']) ?></span>
            <a href="<?= htmlspecialchars($href) ?>" class="btn btn-secondary" style="font-size:.8rem;padding:6px 14px">
              <?= $isPanel ? 'Abrir painel' : 'Configurar' ?>
            </a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <?php elseif ($panel === 'sheets'): ?>
  <?php if (!GoogleSheets::isOAuthAppConfigured()): ?>
  <div class="app-card" style="border-color:#FCD34D;background:#FFFBEB">
    <strong>OAuth Google</strong>
    <p class="text-muted" style="font-size:.875rem;margin-top:8px">Configure <code>GOOGLE_OAUTH_CLIENT_ID</code> e <code>GOOGLE_OAUTH_CLIENT_SECRET</code> no <code>.env</code> e adicione o redirect <code><?= htmlspecialchars(app_http_url('backend/google_sheets_callback.php')) ?></code> no Google Cloud Console.</p>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">
    <div class="app-card">
      <h3 style="font-size:1rem;margin-bottom:16px">Conexão</h3>
      <?php if ($gsToken): ?>
        <p><span class="badge badge-success">Conectado</span></p>
        <form method="post" action="backend/google_sheets_disconnect.php" style="margin-top:16px">
          <?php csrf_field(); ?>
          <button type="submit" class="btn btn-secondary">Desconectar</button>
        </form>
      <?php else: ?>
        <p class="text-muted" style="font-size:.875rem">Autorize o Auvvo a escrever na sua planilha.</p>
        <a href="backend/google_sheets_connect.php" class="btn btn-primary" style="margin-top:12px">Conectar Google Sheets</a>
      <?php endif; ?>
    </div>
    <div class="app-card">
      <h3 style="font-size:1rem;margin-bottom:16px">Planilha destino</h3>
      <div class="form-group">
        <label class="form-label">ID da planilha</label>
        <input type="text" id="gs-spreadsheet-id" class="form-control" value="<?= htmlspecialchars($gsToken['spreadsheet_id'] ?? '') ?>" placeholder="copie da URL do Google Sheets">
      </div>
      <div class="form-group">
        <label class="form-label">Aba (nome)</label>
        <input type="text" id="gs-sheet-name" class="form-control" value="<?= htmlspecialchars($gsToken['sheet_name'] ?? 'Leads') ?>">
      </div>
      <button type="button" class="btn btn-primary" onclick="saveSheetsConfig()">Salvar</button>
      <button type="button" class="btn btn-secondary" style="margin-left:8px" onclick="testSheets()">Linha de teste</button>
      <button type="button" class="btn btn-outline" style="margin-top:12px;width:100%" onclick="loadSheetList()">Listar minhas planilhas</button>
      <div id="gs-files" style="margin-top:12px;font-size:.8rem"></div>
    </div>
  </div>
  <div class="app-card" style="margin-top:24px">
    <p class="text-muted" style="font-size:.875rem">Use em <a href="automacoes">Automações</a> → ação <strong>Registrar no Google Sheets</strong> quando um lead entrar em um estágio ou receber tag.</p>
  </div>
  <?php endif; ?>

  <?php elseif ($panel === 'api'): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">
    <div class="app-card">
      <h3 style="font-size:1rem;margin-bottom:12px">Chaves de API</h3>
      <p class="text-muted" style="font-size:.8125rem;margin-bottom:16px">Header: <code>X-Auvvo-Api-Key: auvvo_live_…</code></p>
      <div class="form-group">
        <label class="form-label">Nome da chave</label>
        <input type="text" id="api-key-name" class="form-control" placeholder="Produção, n8n…">
      </div>
      <div class="form-group">
        <label class="form-label">Permissões</label>
        <label style="display:block;font-size:.8125rem"><input type="checkbox" class="api-perm" value="crm.read" checked> CRM leitura</label>
        <label style="display:block;font-size:.8125rem"><input type="checkbox" class="api-perm" value="crm.write" checked> CRM escrita</label>
        <label style="display:block;font-size:.8125rem"><input type="checkbox" class="api-perm" value="agents.read"> Agentes leitura</label>
      </div>
      <button type="button" class="btn btn-primary" onclick="createApiKey()">Gerar chave</button>
      <div id="api-key-once"></div>
      <div id="api-keys-list" style="margin-top:20px"></div>
    </div>
    <div class="api-doc">
      <strong>Base URL</strong><br>
      <code><?= htmlspecialchars($apiBase) ?></code>
      <hr style="margin:16px 0;border:none;border-top:1px solid var(--border-subtle)">
      <strong>Recursos</strong>
      <ul style="margin:8px 0;padding-left:20px">
        <li><code>GET ?resource=me</code> — conta e permissões</li>
        <li><code>GET ?resource=contacts</code> — listar leads</li>
        <li><code>POST ?resource=contacts</code> — criar/atualizar (crm.write)</li>
        <li><code>GET ?resource=contact&id=1</code> — detalhe</li>
        <li><code>GET ?resource=agents</code> — listar agentes</li>
      </ul>
      <p style="margin-top:12px">Documentação completa: <a href="docs/APIS.md" target="_blank">docs/APIS.md</a></p>
      <p class="text-muted" style="margin-top:12px;font-size:.75rem">APIs internas do painel continuam em <code>backend/api.php</code> (sessão + CSRF).</p>
    </div>
  </div>

  <?php elseif ($panel === 'http'): ?>
  <div style="display:grid;grid-template-columns:1fr 400px;gap:24px">
    <div id="http-preset-list"></div>
    <div class="app-card">
      <h3 style="font-size:1rem;margin-bottom:16px">Preset HTTP</h3>
      <input type="hidden" id="http-id" value="0">
      <div class="form-group">
        <label class="form-label">Nome</label>
        <input type="text" id="http-name" class="form-control" placeholder="Ex.: notificar equipe">
      </div>
      <div class="form-group">
        <label class="form-label">Provedor</label>
        <select id="http-provider" class="form-control">
          <option value="custom">Custom</option>
          <option value="make">Make.com</option>
          <option value="zapier">Zapier</option>
          <option value="n8n">n8n</option>
          <option value="slack">Slack webhook</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">URL</label>
        <input type="url" id="http-url" class="form-control" placeholder="https://hook...">
      </div>
      <div class="form-group">
        <label class="form-label">Corpo JSON (templates {{contact.*}})</label>
        <textarea id="http-body" class="form-control" rows="5">{"nome":"{{contact.name}}","telefone":"{{contact.phone}}","estagio":"{{contact.stage}}"}</textarea>
      </div>
      <button type="button" class="btn btn-primary" style="width:100%" onclick="saveHttpPreset()">Salvar preset</button>
      <p class="text-muted" style="font-size:.75rem;margin-top:12px">Use em <a href="automacoes">Automações</a> → ação «HTTP preset».</p>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($panel !== 'hub'): ?>
  </div><!-- int-panel-wrap -->
  <?php endif; ?>
</main>
</div>
<style>
.api-doc{font-size:.8125rem;background:#FAFAFA;border:1px solid var(--border-subtle);border-radius:10px;padding:16px;line-height:1.6}
.api-doc code{background:#eee;padding:2px 6px;border-radius:4px;font-size:.75rem}
.key-once{background:#111;color:#6EE7B7;padding:14px;border-radius:10px;font-size:.8rem;word-break:break-all;margin-top:12px}
</style>
<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const API = 'backend/api.php';

function escHTML(str) { if (!str) return ''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

async function saveSheetsConfig() {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'google_sheets_save_config');
  fd.append('spreadsheet_id', document.getElementById('gs-spreadsheet-id').value);
  fd.append('sheet_name', document.getElementById('gs-sheet-name').value);
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  alert(d.error ? (d.message || 'Erro') : 'Salvo!');
}

async function testSheets() {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'google_sheets_test');
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  alert(d.error ? d.message : 'Linha de teste enviada!');
}

async function loadSheetList() {
  const d = await (await fetch(API + '?action=google_sheets_list')).json();
  const el = document.getElementById('gs-files');
  if (d.error) { el.innerHTML = '<span style="color:#B91C1C">' + escHTML(d.message) + '</span>'; return; }
  el.innerHTML = (d.files || []).map(f =>
    `<div style="padding:6px 0;border-bottom:1px solid #eee;cursor:pointer" onclick="document.getElementById('gs-spreadsheet-id').value='${escHTML(f.id)}'"><strong>${escHTML(f.name)}</strong><br><code style="font-size:.65rem">${escHTML(f.id)}</code></div>`
  ).join('') || 'Nenhuma planilha encontrada.';
}

async function createApiKey() {
  const perms = [...document.querySelectorAll('.api-perm:checked')].map(c => c.value);
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'api_key_create');
  fd.append('name', document.getElementById('api-key-name').value);
  fd.append('permissions', JSON.stringify(perms));
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d.error) return alert(d.message || 'Erro');
  document.getElementById('api-key-once').innerHTML =
    '<div class="key-once"><strong>Copie agora — não exibimos de novo:</strong><br>' + escHTML(d.key.api_key) + '</div>';
  loadApiKeys();
}

async function loadApiKeys() {
  const d = await (await fetch(API + '?action=api_key_list')).json();
  const el = document.getElementById('api-keys-list');
  if (!el) return;
  el.innerHTML = (d.keys || []).map(k =>
    `<div style="padding:10px 0;border-bottom:1px solid #eee;font-size:.8rem">
      <strong>${escHTML(k.name)}</strong> <code>${escHTML(k.api_key_prefix)}…</code>
      ${k.is_active == 1 ? '' : ' (revogada)'}
      ${k.is_active == 1 ? `<button type="button" class="btn btn-secondary" style="padding:2px 8px;font-size:.7rem;margin-left:8px" onclick="revokeKey(${k.id})">Revogar</button>` : ''}
    </div>`
  ).join('') || '<span class="text-muted">Nenhuma chave.</span>';
}

async function revokeKey(id) {
  if (!confirm('Revogar chave?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'api_key_revoke');
  fd.append('id', id);
  await fetch(API, { method: 'POST', body: fd });
  loadApiKeys();
}

async function loadHttpPresets() {
  const d = await (await fetch(API + '?action=http_preset_list')).json();
  const el = document.getElementById('http-preset-list');
  if (!el) return;
  window._httpPresets = {};
  (d.presets || []).forEach(p => { window._httpPresets[p.id] = p; });
  el.innerHTML = (d.presets || []).map(p => `
    <div class="app-card" style="margin-bottom:10px;padding:14px">
      <strong>#${p.id} ${escHTML(p.name)}</strong> <span class="badge badge-gray">${escHTML(p.provider_slug)}</span>
      <div class="text-muted" style="font-size:.75rem;margin:6px 0">${escHTML(p.target_url)}</div>
      <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:4px 10px" onclick="editHttp(${p.id})">Editar</button>
      <button type="button" class="btn btn-secondary" style="font-size:.75rem;padding:4px 10px" onclick="deleteHttp(${p.id})">Remover</button>
    </div>`).join('') || '<p class="text-muted">Nenhum preset.</p>';
}

function editHttp(id) {
  const p = window._httpPresets[id];
  if (!p) return;
  document.getElementById('http-id').value = id;
  document.getElementById('http-name').value = p.name;
  document.getElementById('http-provider').value = p.provider_slug || 'custom';
  document.getElementById('http-url').value = p.target_url;
  document.getElementById('http-body').value = p.body_template || '';
}

async function saveHttpPreset() {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'http_preset_save');
  fd.append('id', document.getElementById('http-id').value);
  fd.append('name', document.getElementById('http-name').value);
  fd.append('provider_slug', document.getElementById('http-provider').value);
  fd.append('target_url', document.getElementById('http-url').value);
  fd.append('body_template', document.getElementById('http-body').value);
  fd.append('http_method', 'POST');
  fd.append('headers_json', '{}');
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d.error) return alert(d.message || 'Erro');
  document.getElementById('http-id').value = '0';
  loadHttpPresets();
}

async function deleteHttp(id) {
  if (!confirm('Remover?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'http_preset_delete');
  fd.append('id', id);
  await fetch(API, { method: 'POST', body: fd });
  loadHttpPresets();
}

<?php if ($panel === 'api'): ?>loadApiKeys();<?php endif; ?>
<?php if ($panel === 'http'): ?>loadHttpPresets();<?php endif; ?>
</script>
</body>
</html>
