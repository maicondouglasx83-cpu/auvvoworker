<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/Contacts.php';
require_once 'backend/CrmPipelines.php';

$user_id = $_SESSION['user_id'];
$csrf    = $_SESSION['csrf_token'];

$stmt = $pdo->prepare("SELECT id, name FROM agents WHERE user_id=? ORDER BY name ASC");
$stmt->execute([$user_id]);
$agents = $stmt->fetchAll();

$pipeSvc = new CrmPipelines($pdo);
$stmtPipes = $pdo->prepare(
    'SELECT id, name, is_default FROM crm_pipelines WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
);
$stmtPipes->execute([$user_id]);
$pipelines_list = $stmtPipes->fetchAll(PDO::FETCH_ASSOC);
$current_pipeline_id = (int) ($_GET['pipeline'] ?? 0);
$crm_helper = new Contacts($pdo);
$current_pipeline_id = $crm_helper->resolvePipelineId($user_id, $current_pipeline_id ?: null);
$stages = $pipeSvc->stagesMap($user_id, $current_pipeline_id);
$total_contacts = null;
$current_pipeline_name = 'Pipeline';
foreach ($pipelines_list as $pl) {
    if ((int) $pl['id'] === $current_pipeline_id) {
        $current_pipeline_name = (string) $pl['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CRM – Auvvo</title>
<link rel="stylesheet" href="app.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>

    <link rel="icon" type="image/png" href="icone.png">
<style>
/* ===== CRM GLOBAL ===== */
.pipeline-select-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pipeline-select-wrap select{min-width:200px;font-weight:600}
.crm-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px}
.crm-toolbar input,.crm-toolbar select{height:40px;padding:0 14px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--surface-primary);color:var(--text-primary);font-size:.875rem;outline:none}
.crm-toolbar input:focus,.crm-toolbar select:focus{border-color:var(--accent-teal)}
.view-tabs{display:flex;gap:4px;background:var(--surface-secondary);border-radius:10px;padding:4px}
.view-tab{padding:6px 16px;border-radius:8px;border:none;background:transparent;color:var(--text-muted);cursor:pointer;font-size:.875rem;font-weight:500;transition:.2s}
.view-tab.active{background:#fff;color:var(--text-primary);box-shadow:0 1px 4px rgba(0,0,0,.08)}

/* ===== KANBAN ===== */
#kanban-view{display:flex;gap:16px;overflow-x:auto;padding-bottom:16px;min-height:calc(100vh - 280px)}
.kanban-col{min-width:240px;max-width:280px;flex-shrink:0;display:flex;flex-direction:column;gap:10px}
.kanban-header{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:var(--surface-secondary);margin-bottom:4px}
.kanban-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.kanban-header span{font-weight:600;font-size:.875rem;flex:1}
.kanban-header .kanban-count{background:rgba(0,0,0,.07);border-radius:20px;padding:2px 8px;font-size:.75rem;font-weight:700}
.kanban-body{display:flex;flex-direction:column;gap:8px;min-height:80px;padding:2px}
.kanban-card{background:#fff;border:1px solid var(--border-subtle);border-radius:12px;padding:14px;cursor:pointer;transition:box-shadow .2s,border-color .2s;position:relative}
.kanban-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);border-color:var(--accent-teal)}
.kanban-card.dragging{opacity:.5;box-shadow:0 8px 32px rgba(0,0,0,.2)}
.kanban-col.drag-over .kanban-body{background:rgba(20,184,166,.07);border-radius:10px;outline:2px dashed var(--accent-teal)}
.kc-name{font-weight:600;font-size:.875rem;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kc-phone{color:var(--text-muted);font-size:.75rem;margin-bottom:8px}
.kc-tags{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px}
.kc-tag{background:rgba(99,102,241,.1);color:#4F46E5;border-radius:20px;padding:2px 8px;font-size:.7rem;font-weight:500}
.kc-footer{display:flex;justify-content:space-between;align-items:center;font-size:.72rem;color:var(--text-muted)}
.kc-agent{background:var(--surface-secondary);border-radius:6px;padding:2px 6px}
.kanban-add-btn{width:100%;padding:10px;border:2px dashed var(--border-subtle);border-radius:12px;background:transparent;color:var(--text-muted);cursor:pointer;font-size:.85rem;transition:.2s;text-align:center}
.kanban-add-btn:hover{border-color:var(--accent-teal);color:var(--accent-teal)}

/* ===== LIST VIEW ===== */
#list-view{display:none}
.contacts-table{width:100%;border-collapse:separate;border-spacing:0}
.contacts-table th{padding:10px 14px;font-size:.78rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;background:var(--surface-secondary);border-bottom:1px solid var(--border-subtle)}
.contacts-table th:first-child{border-radius:10px 0 0 10px}
.contacts-table th:last-child{border-radius:0 10px 10px 0}
.contacts-table td{padding:12px 14px;border-bottom:1px solid var(--border-subtle);font-size:.875rem;vertical-align:middle}
.contacts-table tr:hover td{background:rgba(0,0,0,.012);cursor:pointer}
.stage-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;color:#fff}

/* ===== DETAIL PANEL ===== */
#detail-panel{position:fixed;top:0;right:-520px;width:500px;height:100vh;background:#fff;box-shadow:-8px 0 40px rgba(0,0,0,.12);z-index:1000;display:flex;flex-direction:column;transition:right .3s ease;overflow:hidden}
#detail-panel.open{right:0}
.dp-header{padding:20px 24px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:12px}
.dp-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.2rem;background:linear-gradient(135deg,#6366F1,#14B8A6);color:#fff;flex-shrink:0}
.dp-name{font-size:1.1rem;font-weight:700}
.dp-phone{font-size:.85rem;color:var(--text-muted)}
.dp-close{margin-left:auto;background:none;border:none;cursor:pointer;padding:8px;border-radius:8px;color:var(--text-muted);font-size:1.2rem}
.dp-close:hover{background:var(--surface-secondary)}
.dp-tabs{display:flex;border-bottom:1px solid var(--border-subtle)}
.dp-tab{flex:1;padding:12px;border:none;background:none;cursor:pointer;font-size:.875rem;color:var(--text-muted);font-weight:500;border-bottom:2px solid transparent;transition:.2s}
.dp-tab.active{color:var(--accent-teal);border-bottom-color:var(--accent-teal)}
.dp-body{flex:1;overflow-y:auto;padding:20px 24px}
.dp-section{margin-bottom:20px}
.dp-section-title{font-size:.78rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.06em;margin-bottom:10px}
.dp-field{margin-bottom:12px}
.dp-field label{font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px}
.dp-field input,.dp-field select,.dp-field textarea{width:100%;padding:8px 12px;border:1px solid var(--border-subtle);border-radius:8px;font-size:.875rem;background:var(--surface-primary);color:var(--text-primary);box-sizing:border-box}
.dp-field textarea{height:80px;resize:vertical}
.dp-field input:focus,.dp-field select:focus,.dp-field textarea:focus{outline:none;border-color:var(--accent-teal)}
.dp-footer{padding:16px 24px;border-top:1px solid var(--border-subtle);display:flex;gap:8px}
.dp-footer .btn{flex:1}

/* Tag input */
.tag-input-wrap{display:flex;flex-wrap:wrap;gap:6px;padding:8px 10px;border:1px solid var(--border-subtle);border-radius:8px;min-height:38px;cursor:text;background:var(--surface-primary)}
.tag-input-wrap input{border:none;outline:none;background:transparent;font-size:.875rem;flex:1;min-width:80px;color:var(--text-primary)}
.tag-pill{display:flex;align-items:center;gap:4px;background:rgba(99,102,241,.12);color:#4F46E5;border-radius:20px;padding:2px 8px;font-size:.78rem;font-weight:500}
.tag-pill button{background:none;border:none;cursor:pointer;color:#4F46E5;padding:0;font-size:.9rem;line-height:1}

/* Activities */
.activity-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-subtle)}
.activity-item:last-child{border-bottom:none}
.act-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.act-note{background:#EFF6FF;color:#3B82F6}
.act-call{background:#F0FDF4;color:#10B981}
.act-email{background:#FFF7ED;color:#F97316}
.act-stage_change{background:#F5F3FF;color:#7C3AED}
.act-system{background:#F9FAFB;color:#6B7280}
.act-whatsapp{background:#ECFDF5;color:#059669}
.act-text{font-size:.85rem;flex:1}
.act-time{font-size:.72rem;color:var(--text-muted);white-space:nowrap}

/* Chat mini */
.mini-msg{padding:8px 12px;border-radius:10px;font-size:.82rem;max-width:85%;margin-bottom:6px;line-height:1.4}
.mini-msg.received{background:var(--surface-secondary);align-self:flex-start}
.mini-msg.sent{background:linear-gradient(135deg,#6366F1,#14B8A6);color:#fff;align-self:flex-end;margin-left:auto}
.mini-msg.system{background:#FEF3C7;color:#92400E;align-self:center;font-size:.75rem;text-align:center;border-radius:20px;padding:4px 12px}
.mini-chat{display:flex;flex-direction:column}

/* Stage select colored */
select.stage-select option[value=new]{color:#6366F1}
select.stage-select option[value=contacted]{color:#8B5CF6}
select.stage-select option[value=qualified]{color:#F59E0B}
select.stage-select option[value=proposal]{color:#F97316}
select.stage-select option[value=closed]{color:#10B981}
select.stage-select option[value=lost]{color:#EF4444}

/* Overlay */
#panel-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.25);z-index:999}
#panel-overlay.show{display:block}

/* Modal nova nota */
.note-form{display:flex;flex-direction:column;gap:8px}
.note-type-row{display:flex;gap:8px}
.note-type-btn{flex:1;padding:8px;border:1px solid var(--border-subtle);border-radius:8px;background:none;cursor:pointer;font-size:.8rem;color:var(--text-muted);transition:.2s;text-align:center}
.note-type-btn.active{background:var(--accent-teal);color:#fff;border-color:var(--accent-teal)}

/* Empty states */
.empty-kanban{color:var(--text-muted);font-size:.82rem;text-align:center;padding:24px 12px;border:1px dashed var(--border-subtle);border-radius:10px}

/* Modal novo contato */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:1100;align-items:center;justify-content:center}
.modal-backdrop.open{display:flex}
.modal-box{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-box h3{margin-bottom:20px;font-size:1.1rem}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-grid .full{grid-column:1/-1}

@media(max-width:900px){
    #kanban-view{flex-direction:column}
    .kanban-col{min-width:100%;max-width:100%}
    #detail-panel{width:100%;right:-100%}
}
</style>
</head>
<body>
<div class="app-container">
    <?php include 'includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">CRM de Contatos</h1>
                <p class="text-muted"><span id="crm-total-label">Carregando contatos…</span> em <strong><?= htmlspecialchars($current_pipeline_name) ?></strong></p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a href="configuracoes#crm-pipelines" class="btn btn-secondary"><i class="ph-bold ph-sliders-horizontal"></i> Configurar pipelines</a>
                <a id="btn-export" href="backend/api.php?action=crm_export_csv&pipeline_id=<?= (int) $current_pipeline_id ?>" class="btn btn-secondary"><i class="ph-bold ph-export"></i> Exportar CSV</a>
                <button class="btn btn-primary" onclick="openNewContactModal()"><i class="ph-bold ph-plus"></i> Novo Contato</button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="crm-toolbar">
            <div class="pipeline-select-wrap">
                <i class="ph-bold ph-funnel" style="color:var(--accent-teal)"></i>
                <select id="filter-pipeline" onchange="onPipelineChange()">
                    <?php foreach ($pipelines_list as $pl): ?>
                    <option value="<?= (int) $pl['id'] ?>" <?= (int) $pl['id'] === $current_pipeline_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pl['name']) ?><?= (int) ($pl['is_default'] ?? 0) === 1 ? ' ★' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="text" id="search-input" placeholder="🔍  Buscar por nome, telefone, empresa…" style="min-width:240px" oninput="debounceLoad()">
            <select id="filter-agent" onchange="loadContacts()">
                <option value="">Todos os agentes</option>
                <?php foreach ($agents as $ag): ?>
                <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-stage" onchange="loadContacts()">
                <option value="">Todos os estágios</option>
                <?php foreach ($stages as $key => $s): ?>
                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="view-tabs" style="margin-left:auto">
                <button class="view-tab active" onclick="setView('kanban',this)"><i class="ph-bold ph-kanban"></i> Kanban</button>
                <button class="view-tab" onclick="setView('list',this)"><i class="ph-bold ph-list-bullets"></i> Lista</button>
            </div>
        </div>

        <!-- KANBAN VIEW (colunas dinâmicas por pipeline) -->
        <div id="kanban-view"></div>

        <!-- LIST VIEW -->
        <div id="list-view">
            <div class="app-card" style="padding:0">
                <div class="app-table-wrapper">
                    <table class="contacts-table" id="contacts-table">
                        <thead>
                            <tr>
                                <th>Nome / Telefone</th>
                                <th>Empresa</th>
                                <th>Estágio</th>
                                <th>Tags</th>
                                <th>Agente</th>
                                <th>Último Contato</th>
                            </tr>
                        </thead>
                        <tbody id="list-tbody">
                            <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Carregando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Panel Overlay -->
<div id="panel-overlay" onclick="closePanel()"></div>

<!-- DETAIL PANEL -->
<div id="detail-panel">
    <div class="dp-header">
        <div class="dp-avatar" id="dp-avatar">?</div>
        <div>
            <div class="dp-name" id="dp-name">–</div>
            <div class="dp-phone" id="dp-phone">–</div>
        </div>
        <button class="dp-close" onclick="closePanel()"><i class="ph-bold ph-x"></i></button>
    </div>
    <div class="dp-tabs">
        <button class="dp-tab active" onclick="dpTab('info',this)"><i class="ph-bold ph-info"></i> Dados</button>
        <button class="dp-tab" onclick="dpTab('activities',this)"><i class="ph-bold ph-clock-clockwise"></i> Histórico</button>
        <button class="dp-tab" onclick="dpTab('chat',this)"><i class="ph-bold ph-chat-dots"></i> Chat</button>
    </div>
    <div class="dp-body">

        <!-- INFO TAB -->
        <div id="dp-tab-info">
            <div class="dp-section">
                <div class="dp-section-title">Informações</div>
                <div class="dp-field"><label>Nome</label><input id="dp-edit-name" type="text" placeholder="Nome completo"></div>
                <div class="dp-field"><label>Telefone</label><input id="dp-edit-phone" type="text" placeholder="+55 11 99999-9999"></div>
                <div class="dp-field"><label>E-mail</label><input id="dp-edit-email" type="email" placeholder="email@exemplo.com"></div>
                <div class="dp-field"><label>Empresa</label><input id="dp-edit-company" type="text" placeholder="Empresa Ltda."></div>
                <div class="dp-field">
                    <label>Estágio</label>
                    <select id="dp-edit-stage" class="stage-select"></select>
                </div>
                <div class="dp-field">
                    <label>Tags</label>
                    <div class="tag-input-wrap" id="dp-tag-wrap" onclick="document.getElementById('dp-tag-input').focus()">
                        <input type="text" id="dp-tag-input" placeholder="Digite e pressione Enter" onkeydown="handleTagInput(event)">
                    </div>
                </div>
                <div class="dp-field"><label>Observações</label><textarea id="dp-edit-notes" placeholder="Notas internas sobre este contato…"></textarea></div>
                <div id="dp-loss-wrap" class="dp-field" style="display:none">
                    <label>Motivo da perda</label>
                    <input id="dp-loss-reason" type="text" readonly style="background:var(--surface-secondary)">
                </div>
            </div>
            <div class="dp-section" id="dp-ltv-section">
                <div class="dp-section-title">LTV · Ciclo de compra</div>
                <div id="dp-ltv-body" style="font-size:.85rem;line-height:1.6;color:var(--text-secondary)"></div>
                <button type="button" class="btn btn-secondary" style="margin-top:10px;font-size:.78rem;width:100%" onclick="recordPurchase()">
                    <i class="ph-bold ph-shopping-cart"></i> Registrar compra manual
                </button>
            </div>
            <div class="dp-section" id="dp-memory-section" style="display:none">
                <div class="dp-section-title">Memória da IA</div>
                <div id="dp-memory-body" style="font-size:.85rem;line-height:1.6;color:var(--text-secondary)"></div>
            </div>
        </div>

        <!-- ACTIVITIES TAB -->
        <div id="dp-tab-activities" style="display:none">
            <div class="dp-section">
                <div class="dp-section-title" style="display:flex;justify-content:space-between;align-items:center">
                    Histórico de Atividades
                    <button class="btn btn-secondary" style="padding:4px 12px;font-size:.78rem" onclick="openNoteForm()"><i class="ph-bold ph-plus"></i> Nota</button>
                </div>
                <div id="activity-list"></div>
            </div>
            <!-- add note inline -->
            <div id="note-form-wrap" style="display:none;margin-top:12px">
                <div class="note-type-row" style="margin-bottom:10px">
                    <?php
                    $act_types = ['note'=>['ph-note-pencil','Nota'],'call'=>['ph-phone-call','Ligação'],'email'=>['ph-envelope','E-mail'],'whatsapp'=>['ph-whatsapp-logo','WhatsApp']];
                    foreach ($act_types as $ak => $av):
                    ?>
                    <button class="note-type-btn <?= $ak==='note'?'active':'' ?>" onclick="selectNoteType('<?= $ak ?>',this)" data-type="<?= $ak ?>">
                        <i class="ph-bold <?= $av[0] ?>"></i><br><?= $av[1] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <textarea id="note-text" placeholder="Descreva a atividade…" style="width:100%;height:80px;padding:10px;border:1px solid var(--border-subtle);border-radius:8px;font-size:.875rem;resize:vertical;box-sizing:border-box;background:var(--surface-primary);color:var(--text-primary)"></textarea>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <button class="btn btn-primary" style="flex:1" onclick="saveActivity()">Salvar</button>
                    <button class="btn btn-secondary" onclick="closeNoteForm()">Cancelar</button>
                </div>
            </div>
        </div>

        <!-- CHAT TAB -->
        <div id="dp-tab-chat" style="display:none">
            <div class="dp-section">
                <div class="dp-section-title">Últimas mensagens</div>
                <div class="mini-chat" id="mini-chat-body"></div>
                <div style="margin-top:12px;text-align:center">
                    <a id="dp-open-conv" href="#" class="btn btn-secondary" style="font-size:.8rem"><i class="ph-bold ph-arrow-square-out"></i> Abrir em Conversas</a>
                </div>
            </div>
        </div>

    </div>
    <div class="dp-footer">
        <button class="btn btn-primary" onclick="saveContact()"><i class="ph-bold ph-floppy-disk"></i> Salvar</button>
        <button class="btn btn-secondary" onclick="deleteContact()" style="color:#EF4444"><i class="ph-bold ph-trash"></i></button>
    </div>
</div>

<!-- MODAL: Novo Contato -->
<div class="modal-backdrop" id="new-contact-modal">
    <div class="modal-box">
        <h3><i class="ph-bold ph-user-plus"></i> Novo Contato</h3>
        <div class="modal-grid">
            <div class="dp-field full"><label>Nome</label><input id="nc-name" type="text" placeholder="Nome completo"></div>
            <div class="dp-field"><label>Telefone *</label><input id="nc-phone" type="text" placeholder="5511999999999"></div>
            <div class="dp-field"><label>E-mail</label><input id="nc-email" type="email" placeholder="email@ex.com"></div>
            <div class="dp-field"><label>Empresa</label><input id="nc-company" type="text" placeholder="Empresa"></div>
            <div class="dp-field">
                <label>Estágio</label>
                <select id="nc-stage"></select>
            </div>
            <div class="dp-field">
                <label>Agente</label>
                <select id="nc-agent">
                    <option value="">Nenhum</option>
                    <?php foreach ($agents as $ag): ?>
                    <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:4px">
            <button class="btn btn-primary" style="flex:1" onclick="createContact()">Criar Contato</button>
            <button class="btn btn-secondary" onclick="closeNewContactModal()">Cancelar</button>
        </div>
    </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let STAGES = <?= json_encode($stages) ?>;
let PIPELINES = <?= json_encode($pipelines_list) ?>;
let CURRENT_PIPELINE_ID = <?= (int) $current_pipeline_id ?>;
const API = 'backend/api.php';

let currentView = 'kanban';
let allContacts = [];
let currentContactId = null;
let currentTags = [];
let selectedNoteType = 'note';
let dragContactId = null;
let debounceTimer = null;

function isLostStage(slug) {
  const s = STAGES[slug];
  return !!(s && (s.is_lost || slug === 'lost'));
}

function contactPhone(c) {
  if (!c) return '';
  if (c.phone_display) return c.phone_display;
  const digits = String(c.phone || '').replace(/\D/g, '');
  if (digits.length >= 10 && digits.length <= 15 && !String(c.phone || '').includes('@')) {
    return '+' + digits;
  }
  if (c.jid && c.jid.includes('@g.us')) return 'Grupo WhatsApp';
  const m = String(c.jid || '').match(/(\d{10,15})/);
  if (m) return '+' + m[1];
  return c.name || '';
}

function contactTitle(c) {
  const name = (c.name || '').trim();
  if (name) return name;
  const phone = contactPhone(c);
  return phone || '—';
}

function fillStageSelects() {
  const opts = Object.keys(STAGES).map(k => {
    const s = STAGES[k];
    return `<option value="${esc(k)}">${esc(s.label)}</option>`;
  }).join('');
  const dp = document.getElementById('dp-edit-stage');
  const nc = document.getElementById('nc-stage');
  const fs = document.getElementById('filter-stage');
  if (dp) dp.innerHTML = opts;
  if (nc) nc.innerHTML = opts;
  if (fs) {
    const cur = fs.value;
    fs.innerHTML = '<option value="">Todos os estágios</option>' + opts;
    if (cur) fs.value = cur;
  }
}

function buildKanbanColumns() {
  const view = document.getElementById('kanban-view');
  if (!view) return;
  view.innerHTML = '';
  Object.keys(STAGES).forEach(stage_key => {
    const info = STAGES[stage_key];
    const col = document.createElement('div');
    col.className = 'kanban-col';
    col.id = 'col-' + stage_key;
    col.dataset.stage = stage_key;
    col.ondragover = onDragOver;
    col.ondragleave = onDragLeave;
    col.ondrop = (e) => onDrop(e, stage_key);
    col.innerHTML = `
      <div class="kanban-header">
        <div class="kanban-dot" style="background:${info.color}"></div>
        <span>${esc(info.label)}</span>
        <span class="kanban-count" id="count-${stage_key}">0</span>
      </div>
      <div class="kanban-body" id="body-${stage_key}">
        <div class="empty-kanban" id="empty-${stage_key}">Sem contatos</div>
      </div>
      <button type="button" class="kanban-add-btn"><i class="ph-bold ph-plus"></i> Adicionar</button>`;
    col.querySelector('.kanban-add-btn').onclick = () => openNewContactModal(stage_key);
    view.appendChild(col);
  });
}

function onPipelineChange() {
  const sel = document.getElementById('filter-pipeline');
  if (!sel) return;
  CURRENT_PIPELINE_ID = parseInt(sel.value, 10) || 0;
  const url = new URL(window.location.href);
  url.searchParams.set('pipeline', String(CURRENT_PIPELINE_ID));
  window.history.replaceState({}, '', url);
  loadContacts();
}

function refreshStageSelectFromApi(stages) {
  STAGES = stages || {};
  fillStageSelects();
  buildKanbanColumns();
}

// ============================================================
// LOAD CONTACTS
// ============================================================
async function loadContacts() {
    const search   = document.getElementById('search-input').value.trim();
    const agent    = document.getElementById('filter-agent').value;
    const stage    = document.getElementById('filter-stage').value;

    let url = `${API}?action=crm_get_contacts&pipeline_id=${CURRENT_PIPELINE_ID}`;
    if (search)  url += `&search=${encodeURIComponent(search)}`;
    if (agent)   url += `&agent_id=${encodeURIComponent(agent)}`;
    if (stage)   url += `&stage=${encodeURIComponent(stage)}`;

    const res  = await fetch(url);
    const data = await res.json();
    if (data.error) return;

    if (data.pipeline_id) CURRENT_PIPELINE_ID = data.pipeline_id;
    if (data.stages) refreshStageSelectFromApi(data.stages);
    if (data.pipelines) PIPELINES = data.pipelines;

    allContacts = data.contacts;

    Object.keys(STAGES).forEach(s => {
        const cnt = data.stage_counts[s] ?? 0;
        const el  = document.getElementById(`count-${s}`);
        if (el) el.textContent = cnt;
    });

    const total = Object.values(data.stage_counts || {}).reduce((a, b) => a + (parseInt(b, 10) || 0), 0);
    const totalEl = document.getElementById('crm-total-label');
    if (totalEl) {
        totalEl.textContent = total.toLocaleString('pt-BR') + ' contato' + (total !== 1 ? 's' : '');
    }

    const exp = document.getElementById('btn-export');
    if (exp) exp.href = `${API}?action=crm_export_csv&pipeline_id=${CURRENT_PIPELINE_ID}`;

    if (currentView === 'kanban') renderKanban(allContacts);
    else renderList(allContacts);
}

function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadContacts, 300);
}

// ============================================================
// KANBAN RENDER
// ============================================================
function renderKanban(contacts) {
    const byStage = {};
    Object.keys(STAGES).forEach(s => byStage[s] = []);
    contacts.forEach(c => {
        if (byStage[c.stage] !== undefined) byStage[c.stage].push(c);
    });

    Object.keys(STAGES).forEach(stage => {
        const body  = document.getElementById(`body-${stage}`);
        const empty = document.getElementById(`empty-${stage}`);
        const cards = byStage[stage];

        // Limpa cartas antigas
        Array.from(body.children).forEach(el => {
            if (!el.classList.contains('empty-kanban')) el.remove();
        });

        if (cards.length === 0) {
            empty.style.display = 'block';
        } else {
            empty.style.display = 'none';
            cards.forEach(c => {
                const card = buildCard(c);
                body.appendChild(card);
            });
        }
    });
}

function buildCard(c) {
    const div = document.createElement('div');
    div.className = 'kanban-card';
    div.dataset.id = c.id;
    div.draggable = true;
    div.ondragstart = (e) => onDragStart(e, c.id);
    div.ondragend   = (e) => onDragEnd(e);
    div.onclick     = () => openPanel(c.id);

    const initials = contactTitle(c).trim().charAt(0).toUpperCase();
    const phone    = contactPhone(c);
    const title    = contactTitle(c);
    const tags     = (c.tags || []).slice(0,3).map(t => `<span class="kc-tag">${esc(t)}</span>`).join('');
    const lastAt   = c.last_contact_at ? relTime(c.last_contact_at) : '';
    const agent    = c.agent_name ? `<span class="kc-agent">${esc(c.agent_name)}</span>` : '';

    div.innerHTML = `
        <div class="kc-name">${esc(title)}</div>
        <div class="kc-phone">${c.name && phone && phone !== c.name ? esc(phone) : ''}</div>
        ${tags ? `<div class="kc-tags">${tags}</div>` : ''}
        <div class="kc-footer">
            ${agent}
            <span>${lastAt}</span>
        </div>`;
    return div;
}

// ============================================================
// LIST RENDER
// ============================================================
function renderList(contacts) {
    const tbody = document.getElementById('list-tbody');
    if (contacts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Nenhum contato encontrado.</td></tr>';
        return;
    }
    tbody.innerHTML = contacts.map(c => {
        const phone = contactPhone(c);
        const title = contactTitle(c);
        const si    = STAGES[c.stage] || {label:c.stage,color:'#6B7280'};
        const tags  = (c.tags||[]).map(t=>`<span class="kc-tag" style="font-size:.7rem">${esc(t)}</span>`).join('');
        const last  = c.last_contact_at ? new Date(c.last_contact_at).toLocaleDateString('pt-BR') : '–';
        return `<tr onclick="openPanel(${c.id})">
            <td><strong>${esc(title)}</strong><br><span style="color:var(--text-muted);font-size:.78rem">${esc(phone)}</span></td>
            <td>${c.company ? esc(c.company) : '<span style="color:var(--text-muted)">–</span>'}</td>
            <td><span class="stage-badge" style="background:${si.color}">${esc(si.label)}</span></td>
            <td>${tags || '<span style="color:var(--text-muted)">–</span>'}</td>
            <td>${c.agent_name ? esc(c.agent_name) : '<span style="color:var(--text-muted)">–</span>'}</td>
            <td>${last}</td>
        </tr>`;
    }).join('');
}

// ============================================================
// VIEW TOGGLE
// ============================================================
function setView(view, btn) {
    currentView = view;
    document.getElementById('kanban-view').style.display = view === 'kanban' ? 'flex' : 'none';
    document.getElementById('list-view').style.display   = view === 'list'   ? 'block' : 'none';
    document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (view === 'list') renderList(allContacts);
}

// ============================================================
// DRAG & DROP
// ============================================================
function onDragStart(e, id) {
    dragContactId = id;
    e.target.classList.add('dragging');
}
function onDragEnd(e) { e.target.classList.remove('dragging'); }
function onDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}
function onDragLeave(e) { e.currentTarget.classList.remove('drag-over'); }
async function onDrop(e, newStage) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    if (!dragContactId) return;

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('contact_id', dragContactId);
    fd.append('stage', newStage);
    if (isLostStage(newStage)) {
      const reason = prompt('Motivo da perda (obrigatório):', '');
      if (!reason || !reason.trim()) { toast('Informe o motivo da perda.', 'error'); return; }
      fd.append('loss_reason', reason.trim());
    }
    await fetch(`${API}?action=crm_update_stage`, {method:'POST',body:fd});
    dragContactId = null;
    loadContacts();
}

// ============================================================
// DETAIL PANEL
// ============================================================
async function openPanel(id) {
    currentContactId = id;
    const res  = await fetch(`${API}?action=crm_get_contact&id=${id}`);
    const data = await res.json();
    if (data.error) return alert(data.message);

    if (data.stages) {
      STAGES = data.stages;
      fillStageSelects();
    }

    const c = data.contact;
    currentTags = c.tags || [];

    // Header
    const initials = contactTitle(c).trim().charAt(0).toUpperCase();
    const phone = contactPhone(c);
    document.getElementById('dp-avatar').textContent = initials;
    document.getElementById('dp-name').textContent  = contactTitle(c);
    document.getElementById('dp-phone').textContent = c.email || phone || '—';

    // Fields
    document.getElementById('dp-edit-name').value    = c.name    || '';
    document.getElementById('dp-edit-phone').value   = (c.phone && !String(c.phone).includes('@')) ? c.phone : (phone.replace(/^\+/, '') || '');
    document.getElementById('dp-edit-email').value   = c.email   || '';
    document.getElementById('dp-edit-company').value = c.company || '';
    document.getElementById('dp-edit-stage').value   = c.stage   || 'new';
    document.getElementById('dp-edit-notes').value   = c.notes   || '';

    const lossWrap = document.getElementById('dp-loss-wrap');
    const lossInp  = document.getElementById('dp-loss-reason');
    if (isLostStage(c.stage) && c.loss_reason) {
        lossWrap.style.display = 'block';
        lossInp.value = c.loss_reason;
    } else {
        lossWrap.style.display = 'none';
        lossInp.value = '';
    }

    const ltvBody = document.getElementById('dp-ltv-body');
    const pc = parseInt(c.purchase_count, 10) || 0;
    const cycle = c.avg_purchase_cycle_days ? `${c.avg_purchase_cycle_days} dias` : '—';
    const last = c.last_purchase_at ? new Date(c.last_purchase_at).toLocaleString('pt-BR') : 'Nenhuma';
    ltvBody.innerHTML = `<div><strong>Compras:</strong> ${pc}</div>
      <div><strong>Última compra:</strong> ${esc(last)}</div>
      <div><strong>Ciclo médio:</strong> ${esc(cycle)}</div>`;

    const memSec = document.getElementById('dp-memory-section');
    const memBody = document.getElementById('dp-memory-body');
    const mem = c.memory_json || {};
    const keys = Object.keys(mem);
    if (keys.length) {
        memSec.style.display = 'block';
        memBody.innerHTML = keys.map(k => `<div><strong>${esc(k)}:</strong> ${esc(String(mem[k]))}</div>`).join('');
    } else {
        memSec.style.display = 'none';
        memBody.innerHTML = '';
    }

    // Tags
    renderTagsInPanel();

    // Activities
    renderActivities(c.activities || []);

    // Mini chat
    renderMiniChat(c.recent_messages || []);

    // Conversations link
    const jid   = c.jid?.replace('@s.whatsapp.net','') || '';
    document.getElementById('dp-open-conv').href = `conversas?jid=${encodeURIComponent(c.jid||'')}`;

    // Open panel
    dpTab('info', document.querySelector('.dp-tab'));
    document.getElementById('detail-panel').classList.add('open');
    document.getElementById('panel-overlay').classList.add('show');
}

function closePanel() {
    document.getElementById('detail-panel').classList.remove('open');
    document.getElementById('panel-overlay').classList.remove('show');
    currentContactId = null;
}

function dpTab(tab, btn) {
    ['info','activities','chat'].forEach(t => {
        document.getElementById(`dp-tab-${t}`).style.display = t===tab ? 'block' : 'none';
    });
    document.querySelectorAll('.dp-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    else document.querySelectorAll('.dp-tab')[['info','activities','chat'].indexOf(tab)]?.classList.add('active');
}

// Tags
function renderTagsInPanel() {
    const wrap = document.getElementById('dp-tag-wrap');
    // Remove old pills
    wrap.querySelectorAll('.tag-pill').forEach(p => p.remove());
    currentTags.forEach(tag => {
        const pill = document.createElement('span');
        pill.className = 'tag-pill';
        pill.innerHTML = `${esc(tag)} <button onclick="removeTag('${esc(tag)}')">×</button>`;
        wrap.insertBefore(pill, document.getElementById('dp-tag-input'));
    });
}

function handleTagInput(e) {
    if (e.key !== 'Enter' && e.key !== ',') return;
    e.preventDefault();
    const val = e.target.value.trim().toLowerCase();
    if (val && !currentTags.includes(val)) {
        currentTags.push(val);
        renderTagsInPanel();
    }
    e.target.value = '';
}

async function removeTag(tag) {
    if (!currentContactId) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('contact_id', currentContactId);
    fd.append('tag', tag);
    await fetch(`${API}?action=crm_remove_tag`, {method:'POST',body:fd});
    currentTags = currentTags.filter(t => t !== tag);
    renderTagsInPanel();
}

async function recordPurchase() {
    if (!currentContactId) return;
    const product = prompt('Produto / descrição da compra (opcional):', '');
    if (product === null) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('contact_id', currentContactId);
    fd.append('product_name', product);
    const res = await fetch(`${API}?action=crm_record_purchase`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) return alert(data.message || 'Erro');
    showToast('Compra registrada.');
    openPanel(currentContactId);
}

// Save contact
async function saveContact() {
    if (!currentContactId) return;

    // Add pending tag input
    const tagInput = document.getElementById('dp-tag-input');
    if (tagInput.value.trim()) {
        const val = tagInput.value.trim().toLowerCase();
        if (!currentTags.includes(val)) currentTags.push(val);
        tagInput.value = '';
    }

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('id',      currentContactId);
    fd.append('name',    document.getElementById('dp-edit-name').value);
    fd.append('phone',   document.getElementById('dp-edit-phone').value);
    fd.append('email',   document.getElementById('dp-edit-email').value);
    fd.append('company', document.getElementById('dp-edit-company').value);
    const newStage = document.getElementById('dp-edit-stage').value;
    fd.append('stage',   newStage);
    fd.append('notes',   document.getElementById('dp-edit-notes').value);
    fd.append('tags',    currentTags.join(','));
    if (isLostStage(newStage)) {
      const lr = prompt('Motivo da perda (obrigatório):', document.getElementById('dp-loss-reason')?.value || '');
      if (!lr || !lr.trim()) { toast('Informe o motivo da perda.', 'error'); return; }
      fd.append('loss_reason', lr.trim());
    }

    const res  = await fetch(`${API}?action=crm_save_contact`, {method:'POST',body:fd});
    const data = await res.json();
    if (data.error) return alert(data.message);

    showToast('Contato salvo com sucesso!');
    closePanel();
    loadContacts();
}

// Delete contact
async function deleteContact() {
    if (!currentContactId) return;
    if (!confirm('Tem certeza? Esta ação é irreversível.')) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('contact_id', currentContactId);
    await fetch(`${API}?action=crm_delete_contact`, {method:'POST',body:fd});
    showToast('Contato removido.', 'error');
    closePanel();
    loadContacts();
}

// ============================================================
// ACTIVITIES
// ============================================================
const ACT_ICONS = {
    note:'ph-note-pencil',call:'ph-phone-call',email:'ph-envelope',
    whatsapp:'ph-whatsapp-logo',stage_change:'ph-arrows-clockwise',system:'ph-info'
};

function renderActivities(activities) {
    const el = document.getElementById('activity-list');
    if (activities.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:24px">Nenhuma atividade registrada.</div>';
        return;
    }
    el.innerHTML = activities.map(a => {
        const icon  = ACT_ICONS[a.type] || 'ph-circle';
        const time  = new Date(a.created_at).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        return `<div class="activity-item">
            <div class="act-icon act-${a.type}"><i class="ph-bold ${icon}"></i></div>
            <div class="act-text">${esc(a.description)}</div>
            <div class="act-time">${time}</div>
        </div>`;
    }).join('');
}

function openNoteForm()  { document.getElementById('note-form-wrap').style.display = 'block'; document.getElementById('note-text').focus(); }
function closeNoteForm() { document.getElementById('note-form-wrap').style.display = 'none'; document.getElementById('note-text').value = ''; }

function selectNoteType(type, btn) {
    selectedNoteType = type;
    document.querySelectorAll('.note-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

async function saveActivity() {
    const text = document.getElementById('note-text').value.trim();
    if (!text || !currentContactId) return;

    const fd = new FormData();
    fd.append('csrf_token',  CSRF);
    fd.append('contact_id',  currentContactId);
    fd.append('type',        selectedNoteType);
    fd.append('description', text);
    const res  = await fetch(`${API}?action=crm_add_activity`, {method:'POST',body:fd});
    const data = await res.json();
    if (data.error) return alert(data.message);

    closeNoteForm();
    showToast('Atividade registrada!');
    // Reload panel
    openPanel(currentContactId);
    dpTab('activities', null);
}

// ============================================================
// MINI CHAT
// ============================================================
function renderMiniChat(msgs) {
    const el = document.getElementById('mini-chat-body');
    if (msgs.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:24px">Nenhuma mensagem encontrada.</div>';
        return;
    }
    el.innerHTML = msgs.map(m => {
        if (m.incoming_msg) return `<div class="mini-msg received">${esc(m.incoming_msg)}</div>`;
        if (m.response_msg && m.type === 'handoff') return `<div class="mini-msg system">⚠️ ${esc(m.response_msg)}</div>`;
        if (m.response_msg) return `<div class="mini-msg sent">${esc(m.response_msg)}</div>`;
        return '';
    }).join('');
    el.scrollTop = el.scrollHeight;
}

// ============================================================
// NEW CONTACT MODAL
// ============================================================
function openNewContactModal(stage = 'new') {
    document.getElementById('nc-stage').value = stage;
    document.getElementById('nc-name').value  = '';
    document.getElementById('nc-phone').value = '';
    document.getElementById('nc-email').value = '';
    document.getElementById('nc-company').value = '';
    document.getElementById('new-contact-modal').classList.add('open');
}
function closeNewContactModal() {
    document.getElementById('new-contact-modal').classList.remove('open');
}

async function createContact() {
    const phone = document.getElementById('nc-phone').value.trim();
    if (!phone) return alert('Telefone é obrigatório.');

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('phone',    phone);
    fd.append('name',     document.getElementById('nc-name').value.trim());
    fd.append('email',    document.getElementById('nc-email').value.trim());
    fd.append('company',  document.getElementById('nc-company').value.trim());
    fd.append('stage',    document.getElementById('nc-stage').value);
    fd.append('pipeline_id', String(CURRENT_PIPELINE_ID));
    fd.append('agent_id', document.getElementById('nc-agent').value);
    fd.append('tags',     '');

    const res  = await fetch(`${API}?action=crm_save_contact`, {method:'POST',body:fd});
    const data = await res.json();
    if (data.error) return alert(data.message);

    closeNewContactModal();
    showToast('Contato criado!');
    loadContacts();
}

// ============================================================
// EXPORT
// ============================================================
document.getElementById('btn-export').addEventListener('click', function(e) {
    e.preventDefault();
    const search = document.getElementById('search-input').value.trim();
    const agent  = document.getElementById('filter-agent').value;
    const stage  = document.getElementById('filter-stage').value;
    let url = `${API}?action=crm_export_csv&pipeline_id=${CURRENT_PIPELINE_ID}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (agent)  url += `&agent_id=${encodeURIComponent(agent)}`;
    if (stage)  url += `&stage=${encodeURIComponent(stage)}`;
    window.location.href = url;
});

// ============================================================
// UTILITIES
// ============================================================
function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function relTime(dt) {
    const now  = Date.now();
    const then = new Date(dt).getTime();
    const diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'agora';
    if (diff < 3600) return Math.floor(diff/60) + 'min';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    if (diff < 604800) return Math.floor(diff/86400) + 'd';
    return new Date(dt).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
}

function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;color:#fff;font-size:.875rem;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .4s;background:${type==='error'?'#EF4444':'#10B981'}`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(()=>t.remove(),400); }, 2500);
}

// Close modal on backdrop click
document.getElementById('new-contact-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNewContactModal();
});

// ============================================================
// INIT
// ============================================================
fillStageSelects();
buildKanbanColumns();
const _deepContactId = parseInt(new URLSearchParams(window.location.search).get('contact') || '0', 10);
loadContacts().then(() => {
  if (_deepContactId > 0) openPanel(_deepContactId);
});
</script>
<?php include __DIR__ . '/includes/toast.php'; ?>
</body>
</html>
