<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id,name,role FROM agents WHERE user_id=? ORDER BY id DESC");
$stmt->execute([$user_id]);
$agents = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= t('conv_title') ?></title>
<link rel="stylesheet" href="app.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>

    <link rel="icon" type="image/png" href="icone.png">
<style>
.conv-item{padding:16px;border-bottom:1px solid var(--border-subtle);cursor:pointer;display:flex;gap:12px;transition:background .2s}
.conv-item:hover{background:rgba(0,0,0,.02)}
.conv-item.active{background:#FFF;border-left:4px solid var(--accent-teal)}
.conv-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.125rem;flex-shrink:0}
.unread-dot{width:8px;height:8px;border-radius:50%;background:#EF4444;flex-shrink:0;margin-top:6px}
.crm-chat-sidebar{width:280px;border-left:1px solid var(--border-subtle);background:#FAFAFA;display:flex;flex-direction:column;flex-shrink:0}
@media(max-width:1100px){.crm-chat-sidebar{display:none}}
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main" style="padding:24px;display:flex;flex-direction:column">
  <div class="page-header" style="margin-bottom:24px">
    <div><h1 class="page-title" style="font-size:1.5rem"><?= t('conv_page_title') ?></h1>
    <p class="text-muted" style="font-size:.875rem"><?= t('conv_page_sub') ?></p></div>
  </div>

  <div class="chat-layout">
    <!-- Sidebar de conversas -->
    <div class="chat-sidebar">
      <div style="padding:16px;border-bottom:1px solid var(--border-subtle);background:#F9FAFB">
        <label style="font-size:.75rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;display:block"><?= t('conv_inbox_label') ?></label>
        <select id="agent-filter" class="form-control" style="padding:8px 12px;font-size:.875rem;background:#FFF" onchange="filterByAgent(this.value)">
          <option value="all"><?= t('conv_all_agents') ?></option>
          <?php foreach($agents as $ag): ?>
          <option value="<?=$ag['id']?>">👔 <?=htmlspecialchars($ag['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="padding:12px 16px;border-bottom:1px solid var(--border-subtle)">
        <input type="text" class="form-control" placeholder="<?= t('conv_search_ph') ?>" style="border-radius:99px;font-size:.875rem;padding:8px 16px" oninput="filterBySearch(this.value)">
      </div>
      <div id="conv-list" style="flex:1;overflow-y:auto">
      </div>
    </div>

    <!-- Painel de Mensagens -->
    <div class="chat-main">
      <div class="chat-main-header" id="chat-header">
        <div style="display:flex;align-items:center;gap:12px">
          <button class="btn btn-icon back-btn" onclick="backToConversations()" style="display:none;width:36px;height:36px;border-radius:50%" title="Voltar"><i class="ph-bold ph-arrow-left"></i></button>
          <div class="chat-avatar" id="hdr-avatar" style="background:#E5E7EB">M</div>
          <div>
            <strong id="hdr-name" style="display:block;font-size:1rem">Marcos Silva</strong>
            <span id="hdr-status" style="font-size:.8125rem;color:var(--text-success)"><i class="ph-fill ph-robot"></i> <?= t('conv_attended_by', ['agent' => 'Auvvo']) ?></span>
          </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
          <select id="pause-minutes" class="form-control" style="width:170px;padding:8px 10px;font-size:.8125rem">
            <option value="15"><?= t('conv_pause_15') ?></option>
            <option value="30" selected><?= t('conv_pause_30') ?></option>
            <option value="120"><?= t('conv_pause_2h') ?></option>
            <option value="1440"><?= t('conv_pause_24h') ?></option>
          </select>
          <button class="btn btn-primary" id="toggle-ia-btn" style="background:#1A1A1E" onclick="toggleIA()"><i class="ph-bold ph-hand"></i> <?= t('conv_pause_btn') ?></button>
          <button class="btn btn-icon text-danger" id="delete-conv-btn" onclick="confirmDeleteConversation()" style="background:#FEF2F2;border:1px solid #FCA5A5;width:38px;height:38px" title="Apagar Conversa"><i class="ph-bold ph-trash"></i></button>
        </div>
      </div>


      <div class="chat-history" id="chat-history"></div>

      <div class="chat-handoff-banner" id="ia-banner"><i class="ph-bold ph-robot"></i> <?= t('conv_ia_banner') ?></div>
      <div id="handoff-summary" style="display:none;margin:12px 16px;padding:14px 16px;border:1px solid var(--border-subtle);border-radius:14px;background:#FFFBEB">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap">
          <strong style="font-size:.9rem;color:#92400E"><i class="ph-bold ph-clipboard-text" style="margin-right:6px"></i> <?= t('conv_handoff_title') ?></strong>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
            <span class="badge" id="hs-meta" style="background:#FEF3C7;color:#92400E">—</span>
            <button type="button" class="btn btn-outline" style="padding:4px 10px;font-size:.75rem" onclick="copyHandoffSummary()"><i class="ph-bold ph-copy"></i> <?= t('conv_copy_btn') ?></button>
            <button type="button" class="btn btn-outline" style="padding:4px 10px;font-size:.75rem" onclick="resumeIA()"><i class="ph-bold ph-robot"></i> <?= t('conv_resume_ia_btn') ?></button>
          </div>
        </div>
        <pre id="hs-text" style="margin:0;white-space:pre-wrap;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;font-size:.78rem;line-height:1.5;color:#78350F"></pre>
      </div>

      <div class="chat-input-area" id="chat-input-area" style="opacity:.5;pointer-events:none;transition:opacity .3s">
        <button class="btn btn-outline" style="border:none;padding:8px"><i class="ph-bold ph-paperclip" style="font-size:1.5rem"></i></button>
        <input type="text" id="manual-input" class="form-control" placeholder="<?= t('conv_input_ph_locked') ?>">
        <button class="btn btn-primary" id="manual-send-btn" onclick="sendMsg()"><i class="ph-bold ph-paper-plane-right"></i></button>
      </div>
    </div>

    <aside class="crm-chat-sidebar" id="crm-sidebar">
      <div style="padding:16px;border-bottom:1px solid var(--border-subtle);font-weight:600;font-size:.875rem">CRM</div>
      <div id="crm-sidebar-body" style="padding:16px;font-size:.8125rem;color:var(--text-muted)">Selecione uma conversa</div>
      <div style="padding:12px 16px;border-top:1px solid var(--border-subtle)">
        <a id="crm-open-link" href="crm" class="btn btn-secondary" style="width:100%;font-size:.8rem;display:none">Abrir no CRM</a>
      </div>
    </aside>
  </div>
</main>
</div>

<script>
const agentNames = {
<?php foreach($agents as $i=>$ag): ?>
  <?=$ag['id']?>: "<?=htmlspecialchars($ag['name'],ENT_QUOTES)?>",
<?php endforeach; ?>
};

let conversations = [];
const CSRF_TOKEN = "<?=htmlspecialchars(csrf_token(), ENT_QUOTES)?>";
const CONV_IA_ACTIVE = <?= json_encode(t('conv_ia_active')) ?>;
const CONV_WAITING = <?= json_encode(t('conv_waiting_human')) ?>;
const CONV_IA_PROCESSING = <?= json_encode(t('conv_ia_processing')) ?>;

// i18n strings for JS
const I18N = {
  attendedBy:    <?= json_encode(t('conv_attended_by', ['agent' => '{agent}'])) ?>,
  pauseBtn:      <?= json_encode(t('conv_pause_btn')) ?>,
  resumeBtn:     <?= json_encode(t('conv_resume_btn')) ?>,
  iaBanner:      <?= json_encode(t('conv_ia_banner')) ?>,
  inputLocked:   <?= json_encode(t('conv_input_ph_locked')) ?>,
  inputOpen:     <?= json_encode(t('conv_input_ph')) ?>,
  sending:       <?= json_encode(t('conv_sending')) ?>,
  sent:          <?= json_encode(t('conv_sent')) ?>,
  failed:        <?= json_encode(t('conv_failed')) ?>,
  noSummary:     <?= json_encode(t('conv_toast_no_summary')) ?>,
  copied:        <?= json_encode(t('conv_toast_copied')) ?>,
  noCopy:        <?= json_encode(t('conv_toast_no_copy')) ?>,
  sendErr:       <?= json_encode(t('conv_toast_send_err')) ?>,
  dedup:         <?= json_encode(t('conv_toast_dedup')) ?>,
  toastSent:     <?= json_encode(t('conv_toast_sent')) ?>,
  netErr:        <?= json_encode(t('conv_toast_net_err')) ?>,
  handoffLabel:  <?= json_encode(t('conv_handoff_label')) ?>,
};

let activeId = null;
let loadConvSeq = 0;
let threadsLoading = false;
let iaActive = true;
let sendingManual = false;
let renderListTimer = null;
let pollTimer = null;
let liveUpdatesActive = false;
let crmSidebarFor = null;
let crmSidebarLoading = false;

function auvvoPeerFromJid(jid) {
  if (!jid || String(jid).includes('@g.us')) return '';
  const m = String(jid).match(/(\d{10,15})/);
  return m ? m[1] : '';
}

function escHTML(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function sanitizeHTML(str) {
  if (!str) return '';
  // Remove dangerous tags and attributes, keep text + basic formatting
  return String(str)
    .replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<iframe[^>]*>[\s\S]*?<\/iframe>/gi, '')
    .replace(/<svg[^>]*>[\s\S]*?<\/svg>/gi, '')
    .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
    .replace(/\s*on\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]*)/gi, '')
    .replace(/<img[^>]*>/gi, '')
    .replace(/javascript\s*:/gi, '')
    .replace(/<[^>]*>/g, function(match) {
      // Allow only safe formatting tags, strip all attributes
      const tagName = match.match(/<\/?(\w+)/);
      if (tagName && /^(b|i|strong|em|br|p|u|s|del|code|pre|h[1-6]|ul|ol|li|a|blockquote)$/i.test(tagName[1])) {
        if (match.startsWith('</')) return '</' + tagName[1] + '>';
        return '<' + tagName[1] + '>';
      }
      return '';
    });
}

function convDisplayName(log) {
  const peer = auvvoPeerFromJid(log.contact_jid || '');
  if (peer) return '+' + peer;
  const raw = String(log.contact_jid || '');
  if (raw.includes('@lid')) return 'WhatsApp';
  return 'Contato';
}

function scheduleRenderList(filter, search) {
  if (renderListTimer) clearTimeout(renderListTimer);
  renderListTimer = setTimeout(() => renderList(filter, search), 120);
}

function markActiveInList() {
  document.querySelectorAll('#conv-list .conv-item').forEach(el => {
    el.classList.toggle('active', el.dataset.id === activeId);
  });
}

function toast(msg, type='info') {
  let wrap = document.getElementById('toast-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'toast-wrap';
    wrap.style.cssText = 'position:fixed;top:18px;right:18px;display:flex;flex-direction:column;gap:10px;z-index:99999';
    document.body.appendChild(wrap);
  }
  const el = document.createElement('div');
  const bg = type==='error' ? '#FEF2F2' : (type==='success' ? '#F0FDF4' : '#EEF2FF');
  const bd = type==='error' ? '#FCA5A5' : (type==='success' ? '#86EFAC' : '#C7D2FE');
  const fg = type==='error' ? '#991B1B' : (type==='success' ? '#166534' : '#1E3A8A');
  el.style.cssText = `background:${bg};border:1px solid ${bd};color:${fg};padding:10px 12px;border-radius:12px;min-width:240px;max-width:360px;box-shadow:0 10px 24px rgba(0,0,0,.12);font-weight:600;font-size:.875rem`;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .25s'; }, 2400);
  setTimeout(()=>{ el.remove(); }, 2700);
}

function renderList(filter='all', search='') {
  const ul = document.getElementById('conv-list');
  ul.innerHTML = '';
  conversations.filter(c => {
    if (filter !== 'all' && String(c.agentId) !== filter) return false;
    if (search && !c.name.toLowerCase().includes(search.toLowerCase())) return false;
    return true;
  }).forEach(c => {
    const div = document.createElement('div');
    div.className = 'conv-item' + (c.id === activeId ? ' active' : '');
    div.dataset.id = c.id;
    div.onclick = () => loadConv(c.id);
    div.innerHTML = `
      <div class="conv-avatar" style="background:${escHTML(c.avatarBg)};color:${escHTML(c.avatarColor)}">${escHTML(c.avatar)}</div>
      <div style="flex:1;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong style="font-size:.9375rem">${escHTML(c.name)}</strong>
          <span style="font-size:.75rem;color:var(--text-muted)">${escHTML(c.time)}</span>
        </div>
        <p style="font-size:.8125rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:2px 0 4px">${escHTML(c.lastMsg)}</p>
        <span class="badge ${escHTML(c.badgeClass)}" style="font-size:.65rem">${escHTML(c.badge)}</span>
      </div>
      ${c.status==='waiting_human'?'<div class="unread-dot"></div>':''}
    `;
    ul.appendChild(div);
  });
}

function buildConvFromLastLog(log) {
  if (String(log.contact_jid || '').includes('@g.us')) return null;
  const peer = auvvoPeerFromJid(log.contact_jid || '');
  const rawJid = String(log.contact_jid || '');
  const cidKey = peer || ('lid_' + rawJid.slice(0, 24));
  const cid = log.agent_id + '_' + cidKey;
  const contactJid = peer ? (peer + '@s.whatsapp.net') : rawJid;
  const name = convDisplayName(log);
  let lastMsg = '';
  let time = '';
  let status = 'ia_active';
  let badge = CONV_IA_ACTIVE;
  let badgeClass = 'badge-success';
  const created = log.created_at ? new Date(log.created_at) : new Date();
  const timeStr = created.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  if (log.incoming_msg) {
    lastMsg = log.incoming_msg;
    time = timeStr;
  }
  if (log.response_msg) {
    lastMsg = log.response_msg;
    time = timeStr;
    if (log.type === 'handoff') {
      status = 'waiting_human';
      badge = CONV_WAITING;
      badgeClass = 'badge-danger';
    }
  }
  return {
    id: cid,
    name,
    avatar: name.replace(/\D/g,'').charAt(0).toUpperCase() || '?',
    avatarBg: '#F3F4F6',
    avatarColor: '#4B5563',
    agentId: parseInt(log.agent_id, 10),
    contactJid,
    status,
    badge,
    badgeClass,
    lastMsg,
    time,
    messages: [],
    messagesLoaded: false,
  };
}

function parseThreadMessages(rows) {
  const messages = [];
  rows.forEach(log => {
    const created = log.created_at ? new Date(log.created_at) : new Date();
    const timeStr = created.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    if (log.incoming_msg) {
      messages.push({ t: 'received', text: log.incoming_msg, time: timeStr });
      if (!String(log.response_msg || '').trim() && log.type === 'ai') {
        messages.push({ t: 'system', text: '⏳ ' + CONV_IA_PROCESSING, time: timeStr });
      }
    }
    if (log.response_msg) {
      if (log.type === 'handoff') {
        messages.push({ t: 'system', text: '⚠️ ' + log.response_msg });
      } else {
        messages.push({ t: 'sent', text: log.response_msg, time: timeStr });
      }
    }
  });
  return messages;
}

async function loadConversationThreads() {
  if (threadsLoading) return;
  threadsLoading = true;
  const ul = document.getElementById('conv-list');
  if (ul) ul.innerHTML = '<p style="padding:16px;color:var(--text-muted);font-size:.85rem">Carregando conversas…</p>';
  try {
    const res = await fetch('backend/api.php?action=conversation_threads&limit=50');
    const d = await res.json();
    if (d.error) throw new Error(d.message || 'Erro');
    conversations = (d.threads || []).map(buildConvFromLastLog).filter(Boolean);
    renderList(document.getElementById('agent-filter')?.value || 'all');
    if (!conversations.length && ul) {
      ul.innerHTML = '<p style="padding:16px;color:var(--text-muted)">Nenhuma conversa ainda.</p>';
    }
  } catch (e) {
    if (ul) ul.innerHTML = '<p style="padding:16px;color:#B91C1C">Não foi possível carregar conversas.</p>';
  } finally {
    threadsLoading = false;
  }
}

async function loadConv(id) {
  const seq = ++loadConvSeq;
  activeId = id;
  const c = conversations.find(x=>x.id===id);
  if (!c) return;
  markActiveInList();
  document.getElementById('hdr-avatar').textContent = c.avatar;
  document.getElementById('hdr-avatar').style.background = c.avatarBg;
  document.getElementById('hdr-avatar').style.color = c.avatarColor;
  document.getElementById('hdr-name').textContent = c.name;
  const agName = agentNames[c.agentId] || 'Auvvo';
  document.getElementById('hdr-status').innerHTML = `<i class="ph-fill ph-robot"></i> ` + I18N.attendedBy.replace('{agent}', escHTML(agName));
  const hist = document.getElementById('chat-history');
  if (!c.messagesLoaded) {
    hist.innerHTML = '<p style="padding:16px;color:var(--text-muted)">Carregando mensagens…</p>';
    try {
      const url = `backend/api.php?action=conversation_thread_messages&agent_id=${c.agentId}&contact_jid=${encodeURIComponent(c.contactJid)}&limit=50`;
      const res = await fetch(url);
      const d = await res.json();
      if (seq !== loadConvSeq || activeId !== id) return;
      if (!d.error && d.messages) {
        c.messages = parseThreadMessages(d.messages);
        c.messagesLoaded = true;
        if (d.messages.length) {
          const last = d.messages[d.messages.length - 1];
          if (last.response_msg) {
            c.lastMsg = last.response_msg;
            c.time = new Date(last.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            if (last.type === 'handoff') {
              c.status = 'waiting_human';
              c.badge = CONV_WAITING;
              c.badgeClass = 'badge-danger';
            }
          } else if (last.incoming_msg) {
            c.lastMsg = last.incoming_msg;
            c.time = new Date(last.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
          }
        }
      }
    } catch (e) {
      if (seq !== loadConvSeq || activeId !== id) return;
      hist.innerHTML = '<p style="padding:16px;color:#B91C1C">Erro ao carregar mensagens.</p>';
      return;
    }
  }
  if (seq !== loadConvSeq || activeId !== id) return;
  hist.innerHTML = '';
  (c.messages || []).forEach(m => {
    const div = document.createElement('div');
    div.className = 'chat-bubble ' + m.t;
    div.innerHTML = sanitizeHTML(m.text) + (m.time ? `<span class="chat-time">${escHTML(m.time)}</span>` : '');
    hist.appendChild(div);
  });
  if (!c.messages.length) {
    hist.innerHTML = '<p style="padding:16px;color:var(--text-muted)">Sem mensagens neste thread.</p>';
  } else {
    hist.scrollTop = hist.scrollHeight;
  }
  fetch(`backend/api.php?action=get_conversation_state&agent_id=${c.agentId}&contact_jid=${encodeURIComponent(c.contactJid)}`)
    .then(r=>r.json()).then(d => {
      if (d && d.error === false) {
        iaActive = !d.paused;
        applyIaUiState();
      }
    }).catch(()=>{});
  loadHandoffSummary(c);
  if (crmSidebarFor !== c.id) loadCrmSidebar(c);
  
  // Lógica de visualização móvel: oculta sidebar de conversas e exibe chat
  if (window.innerWidth <= 768) {
    document.querySelector('.chat-sidebar').classList.add('mobile-inactive');
    document.querySelector('.chat-main').classList.add('mobile-active');
  }
}

function backToConversations() {
  activeId = null;
  markActiveInList();
  document.querySelector('.chat-sidebar').classList.remove('mobile-inactive');
  document.querySelector('.chat-main').classList.remove('mobile-active');
  
  // Limpa o cabeçalho no desktop/mobile
  document.getElementById('hdr-name').textContent = 'Selecione uma conversa';
  document.getElementById('hdr-avatar').textContent = '?';
  document.getElementById('hdr-avatar').style.background = '#E5E7EB';
  document.getElementById('hdr-status').innerHTML = '';
}

async function confirmDeleteConversation() {
  const c = conversations.find(x => x.id === activeId);
  if (!c) return;
  if (!confirm('Deseja realmente apagar todo o histórico desta conversa no painel? Esta ação é irreversível.')) return;
  
  try {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'delete_conversation');
    fd.append('agent_id', String(c.agentId));
    fd.append('contact_jid', c.contactJid);
    
    const res = await fetch('backend/api.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d.error) {
      toast(d.message || 'Erro ao apagar conversa', 'error');
    } else {
      toast('Conversa apagada com sucesso!', 'success');
      
      // Remove localmente
      conversations = conversations.filter(x => x.id !== activeId);
      activeId = null;
      document.getElementById('chat-history').innerHTML = '';
      document.getElementById('hdr-name').textContent = 'Selecione uma conversa';
      document.getElementById('hdr-avatar').textContent = '?';
      document.getElementById('hdr-avatar').style.background = '#E5E7EB';
      document.getElementById('hdr-status').innerHTML = '';
      
      const box = document.getElementById('handoff-summary');
      if (box) box.style.display = 'none';
      
      const crmBody = document.getElementById('crm-sidebar-body');
      if (crmBody) crmBody.innerHTML = 'Selecione uma conversa';
      
      const crmLink = document.getElementById('crm-open-link');
      if (crmLink) crmLink.style.display = 'none';
      
      renderList(document.getElementById('agent-filter').value);
      if (window.innerWidth <= 768) {
        backToConversations();
      }
    }
  } catch (e) {
    toast('Erro de conexão ao tentar apagar conversa.', 'error');
  }
}

function loadCrmSidebar(c) {

  const body = document.getElementById('crm-sidebar-body');
  const link = document.getElementById('crm-open-link');
  if (!body || !c) return;
  if (crmSidebarLoading && crmSidebarFor === c.id) return;
  crmSidebarFor = c.id;
  crmSidebarLoading = true;
  body.innerHTML = 'Carregando…';
  fetch(`backend/api.php?action=crm_get_contact_by_jid&jid=${encodeURIComponent(c.contactJid)}`)
    .then(r => r.json())
    .then(d => {
      if (activeId !== c.id) return;
      if (!d.has || !d.contact) {
        body.innerHTML = '<p>Contato ainda não está no CRM. Será criado na próxima mensagem.</p>';
        if (link) link.style.display = 'none';
        return;
      }
      const ct = d.contact;
      const stages = d.stages || {};
      const mem = ct.memory_json || {};
      const memHtml = Object.keys(mem).length
        ? Object.keys(mem).map(k => `<div style="margin-top:6px"><strong>${escHTML(k)}:</strong> ${sanitizeHTML(String(mem[k]))}</div>`).join('')
        : '<span style="color:var(--text-muted)">—</span>';
      body.innerHTML = `
        <div><strong>${escHTML(ct.name || ct.phone || '—')}</strong></div>
        <div style="margin-top:8px">Estágio: <strong>${escHTML((stages[ct.stage] && stages[ct.stage].label) || stages[ct.stage] || ct.stage)}</strong></div>
        ${ct.loss_reason ? `<div style="margin-top:6px;color:#B91C1C">Perda: ${escHTML(ct.loss_reason)}</div>` : ''}
        <div style="margin-top:12px"><span style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted)">Memória IA</span>${memHtml}</div>
        <div style="margin-top:12px">${(ct.tags||[]).map(t=>'<span class="badge" style="margin:2px">'+escHTML(t)+'</span>').join('') || ''}</div>
        <div id="brain-actions-box" style="margin-top:14px"></div>`;
      if (link) {
        link.href = `crm?contact=${encodeURIComponent(ct.id)}`;
        link.style.display = 'block';
      }
      loadBrainActions(c, ct.id);
    })
    .catch(() => { if (activeId === c.id) body.innerHTML = 'Erro ao carregar CRM.'; })
    .finally(() => { crmSidebarLoading = false; });
}

function loadBrainActions(conv, contactId) {
  const box = document.getElementById('brain-actions-box');
  if (!box || !conv) return;
  const q = contactId > 0
    ? `contact_id=${contactId}`
    : `contact_jid=${encodeURIComponent(conv.contactJid)}`;
  fetch(`backend/api.php?action=brain_list_actions&${q}`)
    .then(r => r.json())
    .then(d => {
      const rows = (d && !d.error && d.actions) ? d.actions : [];
      if (!rows.length) {
        box.innerHTML = '<span style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted)">Cérebro</span><p style="margin:4px 0 0;color:var(--text-muted)">Nenhuma ação registrada ainda.</p>';
        return;
      }
      const fmt = (ts) => {
        if (!ts) return '';
        const dt = new Date(String(ts).replace(' ', 'T'));
        return isNaN(dt.getTime()) ? '' : dt.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
      };
      box.innerHTML = '<span style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted)">Cérebro (ações)</span>'
        + rows.slice(0, 8).map(row => {
          const ex = (row.executed || []).join(', ') || '—';
          const warn = (row.warnings || []).length ? ` <span style="color:#B45309">⚠</span>` : '';
          return `<div style="margin-top:8px;padding:8px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;font-size:.75rem">
            <div style="color:var(--text-muted)">${fmt(row.created_at)}${warn}</div>
            <div style="margin-top:4px">${ex}</div>
          </div>`;
        }).join('');
    })
    .catch(() => { box.innerHTML = ''; });
}

function loadHandoffSummary(c) {
  const box = document.getElementById('handoff-summary');
  const meta = document.getElementById('hs-meta');
  const text = document.getElementById('hs-text');
  if (!box || !c) return;
  const shouldTry = (c.status === 'waiting_human') || (iaActive === false);
  if (!shouldTry) { box.style.display = 'none'; return; }

  fetch(`backend/api.php?action=get_handoff_summary&agent_id=${c.agentId}&contact_jid=${encodeURIComponent(c.contactJid)}`)
    .then(r=>r.json()).then(d => {
      if (!d || d.error || !d.has) { box.style.display = 'none'; return; }
      const s = d.summary || {};
      if (meta) meta.textContent = `${s.intent || '—'} • ${s.urgency || '—'} • ${s.sentiment || '—'}`;
      if (text) text.textContent = (s.summary_text || '').trim();
      box.style.display = 'block';
    })
    .catch(() => { box.style.display = 'none'; });
}

async function copyHandoffSummary() {
  const el = document.getElementById('hs-text');
  const meta = document.getElementById('hs-meta')?.textContent || '';
  const content = (el?.textContent || '').trim();
  if (!content) return toast(I18N.noSummary, 'error');
  const toCopy = `${I18N.handoffLabel}\n${meta}\n\n${content}`;
  try {
    await navigator.clipboard.writeText(toCopy);
    toast(I18N.copied, 'success');
  } catch {
    toast(I18N.noCopy, 'error');
  }
}

function resumeIA() {
  const c = conversations.find(x=>x.id===activeId);
  if (!c) return;
  const prev = iaActive;
  iaActive = true;
  applyIaUiState();
  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action', 'set_conversation_pause');
  fd.append('agent_id', String(c.agentId));
  fd.append('contact_jid', c.contactJid);
  fd.append('paused', '0');
  fd.append('minutes', '30');
  fetch('backend/api.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.error) throw new Error(d.message || 'Erro');
      const box = document.getElementById('handoff-summary');
      if (box) box.style.display = 'none';
    })
    .catch(() => {
      iaActive = prev;
      applyIaUiState();
      toast(I18N.sessionExpired || 'Não foi possível retomar a IA.', 'error');
    });
}

function applyIaUiState() {
  const btn = document.getElementById('toggle-ia-btn');
  const banner = document.getElementById('ia-banner');
  const area = document.getElementById('chat-input-area');
  const input = document.getElementById('manual-input');
  if (iaActive) {
    btn.innerHTML = '<i class="ph-bold ph-hand"></i> ' + I18N.pauseBtn;
    btn.style.background = '#1A1A1E';
    banner.style.display = 'flex';
    area.style.opacity = '.5'; area.style.pointerEvents = 'none';
    input.placeholder = I18N.inputLocked;
  } else {
    btn.innerHTML = '<i class="ph-bold ph-robot"></i> ' + I18N.resumeBtn;
    btn.style.background = '#10B981';
    banner.style.display = 'none';
    area.style.opacity = '1'; area.style.pointerEvents = 'auto';
    input.placeholder = I18N.inputOpen;
    input.focus();
  }
}

function toggleIA() {
  const c = conversations.find(x=>x.id===activeId);
  if (!c) return;
  const prev = iaActive;
  iaActive = !iaActive;
  applyIaUiState();

  const minutesEl = document.getElementById('pause-minutes');
  const minutes = parseInt(minutesEl?.value || '30', 10) || 30;
  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action', 'set_conversation_pause');
  fd.append('agent_id', String(c.agentId));
  fd.append('contact_jid', c.contactJid);
  fd.append('paused', iaActive ? '0' : '1');
  fd.append('minutes', String(minutes));
  fetch('backend/api.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.error) throw new Error(d.message || 'Erro'); })
    .catch(() => {
      iaActive = prev;
      applyIaUiState();
      toast('Não foi possível alterar o estado da IA. Recarregue a página.', 'error');
    });
}

function sendMsg() {
  const input = document.getElementById('manual-input');
  const text = input.value.trim(); if (!text) return;
  const c = conversations.find(x=>x.id===activeId);
  if (!c) return;
  if (iaActive) return;
  if (sendingManual) return;

  const hist = document.getElementById('chat-history');
  const now = new Date();
  const t = now.getHours().toString().padStart(2,'0')+':'+now.getMinutes().toString().padStart(2,'0');
  const clientMsgId = (globalThis.crypto?.randomUUID?.() || ('m_' + Date.now() + '_' + Math.random().toString(16).slice(2)));
  const div = document.createElement('div');
  div.className = 'chat-bubble sent';
  div.dataset.clientMsgId = clientMsgId;
  div.textContent = '';
  const textNode = document.createTextNode(text);
  const span = document.createElement('span');
  span.className = 'chat-time';
  span.innerHTML = `${t} <i class="ph-bold ph-user" style="font-size:.7rem"></i> <span class="mm-status" style="margin-left:6px;color:var(--text-muted)">${I18N.sending}</span></span>`;
  div.appendChild(textNode);
  div.appendChild(span);
  hist.appendChild(div);
  hist.scrollTop = hist.scrollHeight;
  input.value = '';

  sendingManual = true;
  const btn = document.getElementById('manual-send-btn');
  if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; }

  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action', 'send_manual_message');
  fd.append('agent_id', String(c.agentId));
  fd.append('contact_jid', c.contactJid);
  fd.append('text', text);
  fd.append('client_msg_id', clientMsgId);
  fetch('backend/api.php', { method:'POST', body: fd })
    .then(r=>r.json()).then(d => {
      const bubble = document.querySelector(`.chat-bubble.sent[data-client-msg-id="${clientMsgId}"]`);
      const st = bubble?.querySelector?.('.mm-status');
      if (d?.error) {
        if (st) st.textContent = I18N.failed;
        if (bubble) bubble.style.borderColor = '#FCA5A5';
        toast(I18N.sendErr + (d.message || 'falha'), 'error');
      } else if (d?.duplicate) {
        if (st) st.textContent = I18N.sent;
        toast(I18N.dedup, 'info');
      } else {
        if (st) st.textContent = I18N.sent;
        toast(I18N.toastSent, 'success');
      }
    })
    .catch(()=> toast(I18N.netErr, 'error'))
    .finally(() => {
      sendingManual = false;
      if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
    });

  if (c) { c.lastMsg = text; c.time = t; c.messages.push({t:'sent',text,time:t}); }
  renderList(document.getElementById('agent-filter').value);
}

function filterByAgent(v) { renderList(v, document.querySelector('.chat-sidebar input')?.value||''); }
function filterBySearch(v) { renderList(document.getElementById('agent-filter').value, v); }

document.getElementById('manual-input').addEventListener('keydown', e => {
  if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
});

applyIaUiState();
loadConversationThreads();

let lastEventId = 0;
function handleConversationEvent(ev) {
  const jid = ev.contact_jid || '';
  const c = conversations.find(x => x.contactJid === jid || x.id.includes(auvvoPeerFromJid(jid)));
  if (!c) return;
  if (ev.event_type === 'message_in' && ev.payload) {
    let payload = ev.payload;
    if (typeof payload === 'string') try { payload = JSON.parse(payload); } catch(e) {}
    const preview = payload.preview || '';
    if (preview) {
      const t = new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
      c.messages.push({t:'received', text: preview, time: t});
      c.lastMsg = preview;
      c.time = t;
      if (activeId === c.id) {
        const hist = document.getElementById('chat-history');
        if (hist) {
          const div = document.createElement('div');
          div.className = 'chat-bubble received';
          div.innerHTML = sanitizeHTML(preview) + `<span class="chat-time">${escHTML(t)}</span>`;
          hist.appendChild(div);
          hist.scrollTop = hist.scrollHeight;
        }
      }
      scheduleRenderList(document.getElementById('agent-filter')?.value||'');
    }
  }
}
function startLiveUpdates() {
  if (liveUpdatesActive || document.hidden) return;
  liveUpdatesActive = true;
  pollConversationEvents();
  pollTimer = setInterval(pollConversationEvents, 6000);
}

function stopLiveUpdates() {
  liveUpdatesActive = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) stopLiveUpdates();
  else startLiveUpdates();
});

window.addEventListener('pagehide', stopLiveUpdates);

function pollConversationEvents() {
  if (document.hidden) return;
  fetch(`backend/api.php?action=conversation_events_since&since=${lastEventId}`)
    .then(r => r.json())
    .then(d => {
      if (d.error || !d.events) return;
      d.events.forEach(ev => {
        lastEventId = Math.max(lastEventId, parseInt(ev.id, 10) || 0);
        handleConversationEvent(ev);
      });
    })
    .catch(() => {});
}

startLiveUpdates();
</script>
</body></html>
