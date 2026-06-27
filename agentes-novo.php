<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once __DIR__ . '/backend/migrations.php';
require_once 'backend/AgentTemplates.php';

auvvo_run_migrations($pdo);

$user_id = (int) $_SESSION['user_id'];
$types = AgentTemplates::types();
$error = null;

// Sugestões de nome por tipo
$nameSuggestions = [
    'Auvvo'         => ['Auvvo', 'Atendente Principal', 'Recepcionista'],
    'vendedor'      => ['Lucas Vendas', 'Mariana', 'Closer Pro'],
    'atendente'     => ['Ana Atendimento', 'Helena', 'Centro de Ajuda'],
    'suporte'       => ['Tiago Suporte', 'Sara Tech', 'Equipe Técnica'],
    'sdr'           => ['Pedro SDR', 'Filtro Inteligente', 'Pré-vendas'],
    'restaurante'   => ['Pizza Pronta', 'Cantina Bot', 'Atendente Delivery'],
    'agendamentos'  => ['Agenda Fácil', 'Recepção Clínica', 'Marcador'],
    'recuperacao'   => ['Recupera+', 'Resgate Carrinho', 'Pix Pendente'],
    'imobiliaria'   => ['Imóveis Bot', 'Corretor IA', 'Buscador de Imóveis'],
    'faq_inteligente' => ['FAQ Smart', 'Central de Dúvidas', 'Conhecimento Bot'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $agentType = trim((string) ($_POST['agent_type'] ?? ''));
    $name      = trim((string) ($_POST['name'] ?? ''));
    $role      = trim((string) ($_POST['role'] ?? 'Atendente'));
    $business  = trim((string) ($_POST['business_description'] ?? ''));
    $company   = trim((string) ($_POST['company_name'] ?? ''));
    $niche     = trim((string) ($_POST['niche'] ?? ''));
    $hint      = trim((string) ($_POST['router_hint'] ?? ''));

    if ($agentType === '' || !isset($types[$agentType])) {
        $error = 'Escolha um tipo de agente.';
    } elseif (mb_strlen($name) < 2) {
        $error = 'Dê um nome ao agente (mínimo 2 caracteres).';
    } else {
        try {
            // Auto-gera prompt_base a partir da descrição do negócio (camada extra)
            $promptBase = '';
            if ($business !== '') {
                $promptBase = "Sobre o negócio que você representa:\n" . $business;
            }
            if ($hint === '' && $business !== '') {
                // Sugere router_hint a partir do tipo + business
                $hint = ($types[$agentType]['label'] ?? 'Atendente') . ' — ' . mb_substr($business, 0, 180);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO agents
                 (user_id, agent_type, name, role, prompt_base, model, temperature, max_tokens,
                  response_delay, audio_enabled, handoff_rules, handoff_enabled, bot_language,
                  flow_mode, router_hint, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'offline')"
            );
            $stmt->execute([
                $user_id,
                $agentType,
                $name,
                $role !== '' ? $role : ($types[$agentType]['label'] ?? 'Atendente'),
                $promptBase,
                'auvvo-ai',
                0.7,
                1000,
                2,
                0,
                'humano, atendente, suporte',
                1,
                'pt-BR',
                'easy',
                $hint !== '' ? mb_substr($hint, 0, 255) : null,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Salva dados da empresa em settings (se informados) — sem sobrescrever os existentes
            if ($company !== '' || $niche !== '') {
                try {
                    $st = $pdo->prepare('SELECT company_name, niche FROM settings WHERE user_id = ? LIMIT 1');
                    $st->execute([$user_id]);
                    $cur = $st->fetch(PDO::FETCH_ASSOC) ?: ['company_name' => '', 'niche' => ''];
                    if (($cur['company_name'] ?? '') === '' && $company !== '') {
                        $pdo->prepare(
                            'INSERT INTO settings (user_id, company_name) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE company_name = COALESCE(NULLIF(settings.company_name, ""), VALUES(company_name))'
                        )->execute([$user_id, $company]);
                    }
                    if (($cur['niche'] ?? '') === '' && $niche !== '') {
                        $pdo->prepare(
                            'INSERT INTO settings (user_id, niche) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE niche = COALESCE(NULLIF(settings.niche, ""), VALUES(niche))'
                        )->execute([$user_id, $niche]);
                    }
                } catch (PDOException $e) {
                    error_log('[Auvvo] wizard save settings: ' . $e->getMessage());
                }
            }

            header('Location: agentes?edit=' . $newId . '&success=created'); exit;
        } catch (PDOException $e) {
            error_log('[Auvvo] wizard create: ' . $e->getMessage());
            $error = 'Falha ao criar agente. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Novo agente — Auvvo</title>
<link rel="stylesheet" href="app.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="icon" type="image/png" href="icone.png">
<style>
.wiz-wrap { max-width: 920px; margin: 0 auto; padding: 32px 20px 64px; }
.wiz-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px }
.wiz-title { font-size: 1.5rem; font-weight: 700; color:#111827; margin:0 }
.wiz-sub { color:#6B7280; margin: 4px 0 28px; font-size:.9rem }
.wiz-back { color:#6B7280; text-decoration:none; font-size:.85rem; display:inline-flex; align-items:center; gap:6px }
.wiz-back:hover { color:#374151 }

.wiz-steps { display:flex; gap:6px; margin-bottom: 24px }
.wiz-step { flex:1; height:4px; background:#E5E7EB; border-radius:99px; transition: background .2s }
.wiz-step.active { background:#14B8A6 }

.wiz-card { background:#FFF; border:1px solid #E5E7EB; border-radius:16px; padding:32px; box-shadow: 0 2px 12px rgba(0,0,0,.03) }

.wiz-section-title { font-size:1.1rem; font-weight:700; color:#111827; margin: 0 0 4px }
.wiz-section-hint  { color:#6B7280; font-size:.85rem; margin: 0 0 20px }

.type-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:12px; margin-bottom: 8px }
.type-card { position:relative; border:2px solid #E5E7EB; border-radius:12px; padding:16px; cursor:pointer; transition: all .15s; background:#fff }
.type-card:hover { border-color:#14B8A6; transform: translateY(-1px) }
.type-card.selected { border-color:#14B8A6; background: linear-gradient(135deg,#F0FDFA,#FAFFFE); box-shadow: 0 4px 12px rgba(20,184,166,.15) }
.type-card .ti { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; margin-bottom:10px }
.type-card .tl { font-weight:700; font-size:.95rem; color:#111827; margin:0 0 4px }
.type-card .tg { font-size:.78rem; color:#6B7280; line-height:1.4; margin:0 }
.type-card .tb { position:absolute; top:10px; right:10px; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:2px 7px; border-radius:99px }
.type-card .ts { position:absolute; top:10px; right:10px; width:20px; height:20px; border-radius:50%; background:#14B8A6; color:#fff; display:none; align-items:center; justify-content:center; font-size:.8rem }
.type-card.selected .ts { display:flex }
.type-card.selected .tb { display:none }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px }
.form-row.single { grid-template-columns: 1fr }
.form-group { display:flex; flex-direction:column }
.form-group label { font-weight:600; font-size:.82rem; color:#374151; margin-bottom:6px }
.form-group input, .form-group textarea, .form-group select { padding:10px 12px; border:1px solid #D1D5DB; border-radius:8px; font-family:inherit; font-size:.9rem; color:#111827; background:#FFF; transition: border .15s, box-shadow .15s }
.form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline:none; border-color:#14B8A6; box-shadow: 0 0 0 3px rgba(20,184,166,.12) }
.form-group .hint { color:#9CA3AF; font-size:.75rem; margin-top:4px }
.suggest-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px }
.suggest-chip { padding:4px 10px; background:#F3F4F6; border:1px solid #E5E7EB; border-radius:99px; font-size:.75rem; color:#6B7280; cursor:pointer; transition: all .12s }
.suggest-chip:hover { background:#E0F2FE; color:#0369A1; border-color:#7DD3FC }

.wiz-nav { display:flex; justify-content:space-between; align-items:center; margin-top:28px; padding-top:20px; border-top:1px dashed #E5E7EB }
.wiz-nav .btn-ghost { background:transparent; border:none; color:#6B7280; cursor:pointer; font-size:.9rem; font-weight:500; padding:8px 14px; border-radius:8px; transition: background .12s }
.wiz-nav .btn-ghost:hover { background:#F3F4F6 }
.wiz-nav .btn-ghost:disabled { opacity:.5; cursor:default }

.alert-error { background:#FEE2E2; border:1px solid #FECACA; color:#991B1B; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.85rem }
.wiz-step-pane { display:none }
.wiz-step-pane.active { display:block }

.summary-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #F3F4F6 }
.summary-row:last-child { border-bottom:none }
.summary-row .sl { font-size:.78rem; color:#6B7280; min-width:120px }
.summary-row .sv { font-size:.88rem; color:#111827; font-weight:600; flex:1 }
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
  <div class="wiz-wrap">
    <div class="wiz-head">
      <div>
        <a href="agentes" class="wiz-back"><i class="ph-bold ph-arrow-left"></i> Voltar aos agentes</a>
        <h1 class="wiz-title">Novo agente em 3 passos</h1>
      </div>
      <a href="agentes?edit=new" class="wiz-back" title="Modo avançado">Modo avançado <i class="ph-bold ph-gear"></i></a>
    </div>
    <p class="wiz-sub">Crie um agente útil em &lt;1 minuto. Depois você pode refinar tudo na edição completa.</p>

    <div class="wiz-steps">
      <div class="wiz-step active" id="step-bar-1"></div>
      <div class="wiz-step" id="step-bar-2"></div>
      <div class="wiz-step" id="step-bar-3"></div>
    </div>

    <?php if ($error): ?>
      <div class="alert-error"><i class="ph-bold ph-warning-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="wiz-card" id="wiz-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="agent_type" id="agent_type" value="">
      <input type="hidden" name="router_hint" id="router_hint" value="">

      <!-- ─── Passo 1: tipo ─── -->
      <div class="wiz-step-pane active" data-step="1">
        <h2 class="wiz-section-title">Que tipo de agente você precisa?</h2>
        <p class="wiz-section-hint">Cada tipo já vem com prompt mestre + tom de voz adequados. Você pode mudar depois.</p>
        <div class="type-grid">
          <?php foreach ($types as $key => $t): ?>
            <div class="type-card" data-type="<?= htmlspecialchars($key) ?>"
                 data-name-suggestions='<?= htmlspecialchars(json_encode($nameSuggestions[$key] ?? [], JSON_UNESCAPED_UNICODE)) ?>'>
              <div class="ti" style="background:<?= htmlspecialchars($t['bg']) ?>;color:<?= htmlspecialchars($t['color']) ?>">
                <i class="ph-bold <?= htmlspecialchars($t['icon']) ?>"></i>
              </div>
              <?php if (!empty($t['badge'])): ?>
                <span class="tb" style="background:<?= htmlspecialchars($t['bg']) ?>;color:<?= htmlspecialchars($t['badge_color'] ?? $t['color']) ?>"><?= htmlspecialchars($t['badge']) ?></span>
              <?php endif; ?>
              <div class="ts"><i class="ph-bold ph-check"></i></div>
              <h3 class="tl"><?= htmlspecialchars($t['label']) ?></h3>
              <p class="tg"><?= htmlspecialchars($t['tagline'] ?? '') ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ─── Passo 2: nome + role ─── -->
      <div class="wiz-step-pane" data-step="2">
        <h2 class="wiz-section-title">Como vai chamar?</h2>
        <p class="wiz-section-hint">Esse é o nome que aparece pro cliente no início da conversa.</p>

        <div class="form-row">
          <div class="form-group">
            <label for="agent-name">Nome do agente</label>
            <input type="text" id="agent-name" name="name" maxlength="120" required placeholder="Ex: Lucas Vendas">
            <div class="suggest-chips" id="name-suggestions"></div>
          </div>
          <div class="form-group">
            <label for="agent-role">Cargo / função</label>
            <input type="text" id="agent-role" name="role" maxlength="80" placeholder="Ex: Consultor Comercial">
            <div class="hint">Aparece no resumo do agente.</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="company-name">Nome da empresa <span style="color:#9CA3AF;font-weight:400">(opcional)</span></label>
            <input type="text" id="company-name" name="company_name" maxlength="120" placeholder="Sua empresa">
            <div class="hint">Usado no prompt mestre. Pode preencher depois em Configurações.</div>
          </div>
          <div class="form-group">
            <label for="niche">Segmento <span style="color:#9CA3AF;font-weight:400">(opcional)</span></label>
            <input type="text" id="niche" name="niche" maxlength="120" placeholder="Ex: clínica odontológica, e-commerce de moda">
          </div>
        </div>
      </div>

      <!-- ─── Passo 3: business + router hint ─── -->
      <div class="wiz-step-pane" data-step="3">
        <h2 class="wiz-section-title">O que esse agente atende?</h2>
        <p class="wiz-section-hint">Resuma o que ele faz e quem ele atende. Vira a base do prompt + ajuda o roteador IA a escolher esse agente quando o WhatsApp tiver outros vinculados.</p>

        <div class="form-row single">
          <div class="form-group">
            <label for="business-description">Descrição (1-3 frases curtas)</label>
            <textarea id="business-description" name="business_description" rows="5" maxlength="2000" placeholder="Ex: Atendimento de vendas para uma clínica odontológica. Recebe leads do Instagram, qualifica orçamento, agenda primeira consulta com o doutor. Quando o cliente pergunta sobre cobrança ou retorno, encaminha pro humano."></textarea>
            <div class="hint">Quanto mais específico, melhor a IA responde. Você pode editar depois nos campos avançados.</div>
          </div>
        </div>

        <details style="margin-top:14px">
          <summary style="cursor:pointer;color:#6B7280;font-size:.85rem">Resumo antes de criar</summary>
          <div id="wiz-summary" style="margin-top:12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px"></div>
        </details>
      </div>

      <!-- ─── Navegação ─── -->
      <div class="wiz-nav">
        <button type="button" class="btn-ghost" id="btn-back" disabled>
          <i class="ph-bold ph-arrow-left"></i> Voltar
        </button>
        <div style="font-size:.78rem;color:#9CA3AF" id="step-indicator">Passo 1 de 3</div>
        <button type="button" class="btn btn-primary" id="btn-next">
          Próximo <i class="ph-bold ph-arrow-right"></i>
        </button>
        <button type="submit" class="btn btn-primary" id="btn-submit" style="display:none">
          <i class="ph-bold ph-check-circle"></i> Criar agente
        </button>
      </div>
    </form>
  </div>
</main>
</div>

<script>
(function () {
  let step = 1;
  const TOTAL = 3;
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => document.querySelectorAll(s);

  function go(n) {
    step = Math.max(1, Math.min(TOTAL, n));
    $$('.wiz-step-pane').forEach((p) => p.classList.toggle('active', Number(p.dataset.step) === step));
    for (let i = 1; i <= TOTAL; i++) {
      $('#step-bar-' + i).classList.toggle('active', i <= step);
    }
    $('#btn-back').disabled = step === 1;
    $('#btn-next').style.display = step < TOTAL ? '' : 'none';
    $('#btn-submit').style.display = step === TOTAL ? '' : 'none';
    $('#step-indicator').textContent = 'Passo ' + step + ' de ' + TOTAL;
    if (step === TOTAL) updateSummary();
  }

  function validate() {
    if (step === 1) {
      const t = $('#agent_type').value;
      if (!t) { alert('Escolha um tipo de agente.'); return false; }
    }
    if (step === 2) {
      const n = $('#agent-name').value.trim();
      if (n.length < 2) { alert('Dê um nome ao agente (mínimo 2 caracteres).'); return false; }
    }
    return true;
  }

  function updateSummary() {
    const t = $('#agent_type').value;
    const card = document.querySelector('.type-card[data-type="' + t + '"]');
    const tlabel = card?.querySelector('.tl')?.textContent || t;
    const html = `
      <div class="summary-row"><span class="sl">Tipo</span><span class="sv">${escapeHtml(tlabel)}</span></div>
      <div class="summary-row"><span class="sl">Nome</span><span class="sv">${escapeHtml($('#agent-name').value || '—')}</span></div>
      <div class="summary-row"><span class="sl">Cargo</span><span class="sv">${escapeHtml($('#agent-role').value || '—')}</span></div>
      <div class="summary-row"><span class="sl">Empresa</span><span class="sv">${escapeHtml($('#company-name').value || '—')}</span></div>
      <div class="summary-row"><span class="sl">Segmento</span><span class="sv">${escapeHtml($('#niche').value || '—')}</span></div>
      <div class="summary-row"><span class="sl">Descrição</span><span class="sv">${escapeHtml($('#business-description').value || '—').slice(0, 200)}</span></div>
    `;
    $('#wiz-summary').innerHTML = html;
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Selecionar tipo
  document.querySelectorAll('.type-card').forEach((card) => {
    card.addEventListener('click', () => {
      document.querySelectorAll('.type-card.selected').forEach((c) => c.classList.remove('selected'));
      card.classList.add('selected');
      $('#agent_type').value = card.dataset.type;
      // Sugere nomes
      const suggestions = JSON.parse(card.dataset.nameSuggestions || '[]');
      const wrap = $('#name-suggestions');
      wrap.innerHTML = suggestions.map((s) => `<span class="suggest-chip" data-name="${escapeHtml(s)}">${escapeHtml(s)}</span>`).join('');
      wrap.querySelectorAll('.suggest-chip').forEach((chip) => {
        chip.addEventListener('click', () => { $('#agent-name').value = chip.dataset.name; });
      });
      // Auto-pula pra próximo passo
      setTimeout(() => { if (step === 1) go(2); }, 200);
    });
  });

  $('#btn-next').addEventListener('click', () => { if (validate()) go(step + 1); });
  $('#btn-back').addEventListener('click', () => go(step - 1));
})();
</script>

<?php include __DIR__ . '/includes/toast.php'; ?>
</body>
</html>
