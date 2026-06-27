<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/GoogleCalendar.php';
$user_id = $_SESSION['user_id'];
auvvo_ensure_settings_calendar_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gcal_enable_only') {
    csrf_verify();
    auvvo_settings_enable_gcal_scheduling($pdo, (int) $user_id);
    if (GoogleCalendar::isOAuthAppConfigured()) {
        try {
            GoogleCalendar::syncEffectiveCalendarIdToToken($pdo, (int) $user_id);
        } catch (Throwable $_g) {
        }
    }
    header('Location: configuracoes?gcal_sched_enabled=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD']=='POST' && ($_POST['action']??'')==='save_keys') {
    csrf_verify();
    $fields = [
        'openai_key','gemini_key','elevenlabs_key',
        'mercadopago_token','pagseguro_token','cielo_merchant_id','cielo_merchant_key','efi_client_id','efi_client_secret',
        'webhook_url','evolution_url','evolution_key',
        'company_name','company_niche','company_site',
        'google_calendar_enabled','google_calendar_calendar_id',
    ];

    $vals = array_map(function($f) {
        if ($f === 'google_calendar_enabled') {
            return (int)(($_POST[$f] ?? '') === '1');
        }
        if ($f === 'google_calendar_calendar_id') {
            $v = trim((string)($_POST[$f] ?? 'primary'));
            return $v !== '' ? $v : 'primary';
        }
        return trim((string)($_POST[$f] ?? ''));
    }, $fields);
    $stmt = $pdo->prepare("SELECT id FROM settings WHERE user_id=?");
    $stmt->execute([$user_id]);
    $sets = implode(',', array_map(fn ($f) => "`$f`=?", $fields));
    $cols = implode(',', array_map(fn ($f) => "`$f`", $fields));
    $ph   = implode(',', array_fill(0, count($fields), '?'));
    if ($stmt->fetch()) {
        $st = $pdo->prepare("UPDATE settings SET $sets WHERE user_id=?");
        if ($st === false) {
            error_log('[configuracoes] prepare UPDATE failed: ' . json_encode($pdo->errorInfo()));
        } elseif ($st->execute([...$vals, $user_id]) === false) {
            error_log('[configuracoes] execute UPDATE failed: ' . json_encode($st->errorInfo()));
        }
    } else {
        $st = $pdo->prepare("INSERT INTO settings (user_id,$cols) VALUES (?,$ph)");
        if ($st === false) {
            error_log('[configuracoes] prepare INSERT failed: ' . json_encode($pdo->errorInfo()));
        } elseif ($st->execute([$user_id, ...$vals]) === false) {
            error_log('[configuracoes] execute INSERT failed: ' . json_encode($st->errorInfo()));
        }
    }
    if (GoogleCalendar::isOAuthAppConfigured()) {
        try {
            GoogleCalendar::syncEffectiveCalendarIdToToken($pdo, (int) $user_id);
        } catch (Throwable $_g) {
        }
    }
    header("Location: configuracoes?success=1"); exit;
}

$stmt = $pdo->prepare("SELECT * FROM settings WHERE user_id=?");
$stmt->execute([$user_id]);
$s = $stmt->fetch() ?: [];

$gcal = null;
try {
    $stmt = $pdo->prepare("SELECT calendar_id, expires_at, updated_at FROM google_calendar_tokens WHERE user_id=? LIMIT 1");
    $stmt->execute([$user_id]);
    $gcal = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    $gcal = null;
}
function sv($s,$k){ return htmlspecialchars($s[$k]??''); }
function hasVal($s,$k){ return !empty($s[$k]); }
$gcal_enabled = (int)($s['google_calendar_enabled'] ?? 0) === 1;
$gcal_calendar_id_setting = (string)($s['google_calendar_calendar_id'] ?? 'primary');
$gcal_oauth_ready = GoogleCalendar::isOAuthAppConfigured();
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= t('cfg_title') ?></title>
<link rel="stylesheet" href="app.css">
<link rel="stylesheet" href="assets/configuracoes.css?v=20260520q">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="icon" type="image/png" href="icone.png">
<style>
.gateway-card{display:flex;align-items:flex-start;gap:20px;padding:24px;border-bottom:1px solid var(--border-subtle)}
.gateway-card:last-child{border:none}
.gateway-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
.gateway-info{flex:1}
.gateway-info strong{display:block;font-size:1rem;margin-bottom:2px}
.gateway-info p{font-size:.8125rem;color:var(--text-muted);margin-bottom:12px}
.key-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
.key-input{position:relative}
.key-input input{padding-right:40px}
.key-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1rem}
.ia-hint{background:rgba(158,220,217,0.12);border:1px solid rgba(158,220,217,0.35);border-radius:var(--radius-md);padding:14px 16px;font-size:.8125rem;color:var(--text-secondary);line-height:1.55;margin-bottom:20px}
.ia-hint strong{color:var(--text-primary)}
</style>
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">

<?php if(isset($_GET['success'])): ?>
<div style="background:var(--surface-success);color:var(--text-success);padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px">
  <i class="ph-bold ph-check-circle"></i> <?= t('cfg_saved_ok') ?>
</div>
<?php endif; ?>

<?php if(isset($_GET['gcal_sched_enabled'])): ?>
<div style="background:var(--surface-success);color:var(--text-success);padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px">
  <i class="ph-bold ph-check-circle"></i> <?= t('cfg_gcal_sched_enabled_ok') ?>
</div>
<?php endif; ?>

<?php if(isset($_GET['gcal_success'])): ?>
<div style="background:var(--surface-success);color:var(--text-success);padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px">
  <i class="ph-bold ph-check-circle"></i> <?= t('cfg_gcal_ok') ?>
</div>
<?php endif; ?>

<?php if(isset($_GET['gcal_disconnected'])): ?>
<div style="background:var(--surface-success);color:var(--text-success);padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px">
  <i class="ph-bold ph-check-circle"></i> <?= t('cfg_gcal_disc') ?>
</div>
<?php endif; ?>

<?php if(isset($_GET['gcal_error'])): ?>
<div style="background:rgba(239,68,68,0.10);color:#991B1B;padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px;border:1px solid rgba(239,68,68,0.25)">
  <i class="ph-bold ph-warning-circle"></i> <?= t('cfg_gcal_err', ['error' => htmlspecialchars($_GET['gcal_error'])]) ?>
</div>
<?php endif; ?>

<div class="page-header">
  <div><h1 class="page-title"><?= t('cfg_page_title') ?></h1><p class="text-muted">Conta, funis CRM e preferências. Integrações externas ficam em <a href="integracoes">Integrações</a>; WhatsApp em <a href="conexoes">Conexões</a>.</p></div>
  <a href="integracoes" class="btn btn-secondary"><i class="ph-bold ph-plugs-connected"></i> Integrações</a>
</div>

<div class="cfg-status-pills">
  <span class="cfg-status-pill <?= hasVal($s,'openai_key') || hasVal($s,'gemini_key') ? 'ok' : '' ?>"><i class="ph-bold ph-brain"></i> IA</span>
  <span class="cfg-status-pill <?= $gcal ? 'ok' : '' ?>"><i class="ph-bold ph-calendar"></i> Google Calendar</span>
  <span class="cfg-status-pill <?= hasVal($s,'mercadopago_token') ? 'ok' : '' ?>"><i class="ph-bold ph-credit-card"></i> Pagamentos</span>
  <span class="cfg-status-pill ok"><i class="ph-bold ph-funnel"></i> Pipelines CRM</span>
</div>

<div class="cfg-layout">
<nav class="cfg-nav" aria-label="Seções">
  <span class="cfg-nav-title">Configurações</span>
  <button type="button" class="cfg-nav-link is-active" data-section="crm-pipelines"><i class="ph-bold ph-kanban"></i> Pipelines CRM</button>
  <button type="button" class="cfg-nav-link" data-section="cfg-company"><i class="ph-bold ph-buildings"></i> Empresa</button>
  <button type="button" class="cfg-nav-link" data-section="cfg-ai"><i class="ph-bold ph-brain"></i> Motores IA</button>
  <button type="button" class="cfg-nav-link" data-section="cfg-gcal"><i class="ph-bold ph-calendar"></i> Agenda</button>
  <button type="button" class="cfg-nav-link" data-section="cfg-payments"><i class="ph-bold ph-credit-card"></i> Pagamentos</button>
  <button type="button" class="cfg-nav-link" data-section="cfg-dev"><i class="ph-bold ph-code"></i> Desenvolvedor</button>
  <div class="cfg-nav-footer">
    <a href="integracoes"><i class="ph-bold ph-plugs-connected"></i> Hub de integrações</a>
    <a href="conexoes" style="margin-top:6px;display:flex;align-items:center;gap:8px"><i class="ph-bold ph-whatsapp-logo"></i> Conexões WhatsApp</a>
  </div>
</nav>

<div class="cfg-main">

<section id="crm-pipelines" class="cfg-section cfg-section--active">
  <div class="cfg-section-head">
    <h3><i class="ph-bold ph-funnel"></i> Pipelines do Kanban</h3>
    <p>Crie vários funis (Vendas, Suporte, Parcerias…), personalize estágios, cores e marque ganho/perdido. O CRM usa o pipeline selecionado na barra superior.</p>
  </div>
  <div class="app-card" style="padding:24px">
    <div class="cfg-pipelines-grid">
      <div>
        <strong style="font-size:.875rem">Seus pipelines</strong>
        <div id="cfg-pipeline-list" class="cfg-pipeline-list" style="margin-top:10px"><p class="text-muted">Carregando…</p></div>
        <div class="cfg-new-pipeline">
          <input type="text" id="cfg-new-pipeline-name" class="form-control" placeholder="Ex: Suporte, Parcerias B2B">
          <button type="button" class="btn btn-primary" id="cfg-create-pipeline"><i class="ph-bold ph-plus"></i> Novo</button>
        </div>
      </div>
      <div>
        <div id="cfg-pipeline-meta"></div>
        <div id="cfg-stage-editor"><p class="text-muted">Selecione um pipeline à esquerda.</p></div>
      </div>
    </div>
  </div>
</section>

<form action="configuracoes" method="POST" id="cfg-main-form">
<?php csrf_field(); ?>
<input type="hidden" name="action" value="save_keys">

<section id="cfg-company" class="cfg-section" hidden>
<div class="cfg-section-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <h3><?= t('cfg_company_title') ?></h3>
    <p><?= t('cfg_company_desc') ?></p>
  </div>
  <button type="submit" class="btn btn-primary"><i class="ph-bold ph-floppy-disk"></i> <?= t('cfg_save_btn') ?></button>
</div>
<div class="app-card" style="margin-bottom:40px">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px">
    <div class="form-group" style="margin:0">
      <label class="form-label"><?= t('cfg_company_name') ?></label>
      <input type="text" name="company_name" class="form-control" value="<?=sv($s,'company_name')?>" placeholder="Ex: Auvvo Solutions">
    </div>
    <div class="form-group" style="margin:0">
      <label class="form-label"><?= t('cfg_company_site') ?></label>
      <input type="text" name="company_site" class="form-control" value="<?=sv($s,'company_site')?>" placeholder="Ex: https://Auvvo.com">
    </div>
  </div>
  <div class="form-group" style="margin:0">
    <label class="form-label"><?= t('cfg_company_niche') ?></label>
    <input type="text" name="company_niche" class="form-control" value="<?=sv($s,'company_niche')?>" placeholder="Ex: Venda de softwares de automação para pequenas empresas">
  </div>
</div>
</section>

<section id="cfg-ai" class="cfg-section" hidden>
<div class="cfg-section-head">
  <h3><?= t('cfg_ai_title') ?></h3>
  <p><?= t('cfg_ai_desc') ?></p>
</div>
<div class="ia-hint">
  <strong><?= t('cfg_ai_hint_title') ?></strong> <?= t('cfg_ai_hint_body') ?>
</div>
<div class="app-card" style="padding:0;margin-bottom:40px">
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#F0FFF4;color:#10B981"><i class="ph-bold ph-open-ai-logo"></i></div>
    <div class="gateway-info">
      <strong><?= t('cfg_openai_name') ?> <?php if(hasVal($s,'openai_key')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_key_saved') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_openai_desc') ?> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:var(--accent-teal)"><?= t('cfg_openai_link') ?></a></p>
      <div class="key-input">
        <input type="password" name="openai_key" class="form-control" value="<?=sv($s,'openai_key')?>" placeholder="sk-proj-… ou sk-…" autocomplete="off">
        <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
      </div>
    </div>
  </div>
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#E8F0FE;color:#1A73E8"><i class="ph-bold ph-sparkle"></i></div>
    <div class="gateway-info">
      <strong><?= t('cfg_gemini_name') ?> <?php if(hasVal($s,'gemini_key')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_key_saved') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_gemini_desc') ?> <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" style="color:var(--accent-teal)"><?= t('cfg_gemini_link') ?></a></p>
      <div class="key-input">
        <input type="password" name="gemini_key" class="form-control" value="<?=sv($s,'gemini_key')?>" placeholder="AIza…" autocomplete="off">
        <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
      </div>
    </div>
  </div>
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#FFF5F7;color:#E11D48"><i class="ph-bold ph-speaker-high"></i></div>
    <div class="gateway-info">
      <strong><?= t('cfg_eleven_name') ?> <?php if(hasVal($s,'elevenlabs_key')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_connected') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_eleven_desc') ?></p>
      <div class="key-input">
        <input type="password" name="elevenlabs_key" class="form-control" value="<?=sv($s,'elevenlabs_key')?>" placeholder="Chave de API ElevenLabs">
        <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
      </div>
    </div>
  </div>
</div>
</section>

<section id="cfg-gcal" class="cfg-section" hidden>
<div class="cfg-section-head">
  <h3><?= t('cfg_gcal_title') ?></h3>
  <p><?= t('cfg_gcal_desc') ?></p>
</div>
<div class="app-card" style="padding:0;margin-bottom:40px">
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#E8F0FE;color:#1A73E8"><i class="ph-bold ph-calendar"></i></div>
    <div class="gateway-info">
      <strong>
        Google Calendar
        <?php if($gcal): ?>
          <span class="badge badge-success" style="margin-left:8px"><?= t('cfg_gcal_connected') ?></span>
        <?php else: ?>
          <span class="badge" style="margin-left:8px;background:rgba(0,0,0,.06);color:var(--text-muted)"><?= t('cfg_gcal_not_conn') ?></span>
        <?php endif; ?>
        <?php if($gcal_enabled): ?>
          <span class="badge badge-success" style="margin-left:8px"><?= t('cfg_gcal_sched_on') ?></span>
        <?php else: ?>
          <span class="badge" style="margin-left:8px;background:rgba(0,0,0,.06);color:var(--text-muted)"><?= t('cfg_gcal_sched_off') ?></span>
        <?php endif; ?>
      </strong>
      <p>
        <?php if($gcal): ?>
          Calendar ID: <code style="font-size:.8em;background:rgba(0,0,0,.06);padding:2px 6px;border-radius:4px"><?=htmlspecialchars($gcal['calendar_id'] ?? 'primary')?></code>
          • <?=htmlspecialchars(date('d/m/Y H:i', strtotime($gcal['updated_at'] ?? 'now')))?>
        <?php else: ?>
          <?= t('cfg_gcal_desc') ?>
        <?php endif; ?>
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="form-group" style="margin:0">
          <label class="form-label"><?= t('cfg_gcal_enable_label') ?></label>
          <select name="google_calendar_enabled" class="form-control" id="google_calendar_enabled">
            <option value="0" <?=$gcal_enabled ? '' : 'selected'?>><?= t('cfg_gcal_enable_off') ?></option>
            <option value="1" <?=$gcal_enabled ? 'selected' : ''?>><?= t('cfg_gcal_enable_on') ?></option>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label"><?= t('cfg_gcal_cal_id') ?></label>
          <input type="text" name="google_calendar_calendar_id" class="form-control" value="<?=htmlspecialchars($gcal_calendar_id_setting)?>" placeholder="primary">
        </div>
      </div>
      <p class="text-muted" style="font-size:.75rem;margin:-4px 0 12px"><?= t('cfg_gcal_save_hint') ?></p>

      <?php if ($gcal && $gcal_oauth_ready && !$gcal_enabled): ?>
      <div style="background:rgba(59,130,246,0.10);border:1px solid rgba(59,130,246,0.35);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:12px;font-size:.8125rem;line-height:1.5">
        <strong><?= t('cfg_gcal_quick_enable_title') ?></strong> <?= t('cfg_gcal_quick_enable_body') ?>
        <button type="submit" form="gcal-enable-form" class="btn btn-primary" style="margin-top:12px"><i class="ph-bold ph-calendar-check"></i> <?= t('cfg_gcal_quick_enable_btn') ?></button>
      </div>
      <?php endif; ?>

      <?php if (!$gcal_oauth_ready): ?>
      <div class="ia-hint" style="margin-bottom:12px;border-color:rgba(251,191,36,0.45);background:rgba(251,191,36,0.10)">
        <strong><?= t('cfg_gcal_need_admin_title') ?></strong> <?= t('cfg_gcal_need_admin_body') ?>
      </div>
      <?php elseif ($gcal_enabled && !$gcal): ?>
      <div style="background:rgba(239,68,68,0.10);color:#991B1B;padding:12px 16px;border-radius:var(--radius-md);margin-bottom:12px;border:1px solid rgba(239,68,68,0.28);font-size:.8125rem;line-height:1.5">
        <strong><?= t('cfg_gcal_warn_no_token_title') ?></strong> <?= t('cfg_gcal_warn_no_token_body') ?>
      </div>
      <?php else: ?>
      <p class="text-muted" style="font-size:.8125rem;line-height:1.55;margin:0 0 12px"><?= t('cfg_gcal_client_flow') ?></p>
      <?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn" href="backend/gcal_status.php" target="_blank" rel="noopener" style="border:1px solid var(--border-subtle)"><i class="ph-bold ph-stethoscope"></i> <?= t('cfg_gcal_diag_link') ?></a>
        <?php if ($gcal_oauth_ready): ?>
        <a class="btn btn-primary" href="backend/google_calendar_connect.php"><i class="ph-bold ph-plug"></i> <?= t('cfg_gcal_connect_btn') ?></a>
        <?php else: ?>
        <span class="btn btn-primary" style="opacity:.55;pointer-events:none;cursor:not-allowed" title="<?= htmlspecialchars(t('cfg_gcal_oauth_need')) ?>"><i class="ph-bold ph-plug"></i> <?= t('cfg_gcal_connect_btn') ?></span>
        <?php endif; ?>
        <?php if($gcal): ?>
          <button type="submit" form="gcal-disconnect-form" class="btn" style="background:rgba(239,68,68,0.10);color:#991B1B;border:1px solid rgba(239,68,68,0.25)">
              <i class="ph-bold ph-x-circle"></i> <?= t('cfg_gcal_disc_btn') ?>
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</section>

<section id="cfg-payments" class="cfg-section" hidden>
<div class="cfg-section-head">
  <h3><?= t('cfg_gw_title') ?></h3>
  <p><?= t('cfg_gw_desc') ?></p>
</div>
<div class="app-card" style="padding:0;margin-bottom:40px">

  <!-- Mercado Pago -->
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#FFF7E6;color:#FFB800"><i class="ph-bold ph-credit-card"></i></div>
    <div class="gateway-info">
      <strong>Mercado Pago <?php if(hasVal($s,'mercadopago_token')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_connected') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_mp_desc') ?> <a href="https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/additional-content/your-integrations/credentials" target="_blank" style="color:var(--accent-teal)"><?= t('cfg_mp_link') ?></a></p>
      <div class="key-input">
        <input type="password" name="mercadopago_token" class="form-control" value="<?=sv($s,'mercadopago_token')?>" placeholder="APP_USR-...">
        <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
      </div>
    </div>
  </div>

  <!-- PagSeguro -->
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#FFF0F0;color:#FF6B00"><i class="ph-bold ph-currency-circle-dollar"></i></div>
    <div class="gateway-info">
      <strong>PagSeguro <?php if(hasVal($s,'pagseguro_token')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_connected') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_ps_desc') ?> <a href="https://dev.pagseguro.uol.com.br/" target="_blank" style="color:var(--accent-teal)"><?= t('cfg_ps_link') ?></a></p>
      <div class="key-input">
        <input type="password" name="pagseguro_token" class="form-control" value="<?=sv($s,'pagseguro_token')?>" placeholder="Token PagSeguro">
        <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
      </div>
    </div>
  </div>

  <!-- Cielo -->
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#E8F4FD;color:#003087"><i class="ph-bold ph-buildings"></i></div>
    <div class="gateway-info">
      <strong>Cielo <?php if(hasVal($s,'cielo_merchant_id')&&hasVal($s,'cielo_merchant_key')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_connected') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_cielo_desc') ?> <a href="https://developercielo.github.io/manual/cielo-ecommerce" target="_blank" style="color:var(--accent-teal)"><?= t('cfg_cielo_link') ?></a></p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="key-input">
          <input type="password" name="cielo_merchant_id" class="form-control" value="<?=sv($s,'cielo_merchant_id')?>" placeholder="MerchantId">
          <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
        </div>
        <div class="key-input">
          <input type="password" name="cielo_merchant_key" class="form-control" value="<?=sv($s,'cielo_merchant_key')?>" placeholder="MerchantKey">
          <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- SejaeEfi -->
  <div class="gateway-card">
    <div class="gateway-icon" style="background:#EEF2FF;color:#4F46E5"><i class="ph-bold ph-lightning"></i></div>
    <div class="gateway-info">
      <strong>SejaeEfi (Efí Bank) <?php if(hasVal($s,'efi_client_id')&&hasVal($s,'efi_client_secret')): ?><span class="badge badge-success" style="margin-left:8px"><?= t('cfg_connected') ?></span><?php endif; ?></strong>
      <p><?= t('cfg_efi_desc') ?> <a href="https://dev.efipay.com.br/" target="_blank" style="color:var(--accent-teal)"><?= t('cfg_efi_link') ?></a></p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="key-input">
          <input type="password" name="efi_client_id" class="form-control" value="<?=sv($s,'efi_client_id')?>" placeholder="Client_Id">
          <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
        </div>
        <div class="key-input">
          <input type="password" name="efi_client_secret" class="form-control" value="<?=sv($s,'efi_client_secret')?>" placeholder="Client_Secret">
          <button type="button" class="key-toggle" onclick="toggleKey(this)"><i class="ph-bold ph-eye"></i></button>
        </div>
      </div>
    </div>
  </div>
</div>
</section>

<section id="cfg-dev" class="cfg-section" hidden>
<div class="cfg-section-head">
  <h3><?= t('cfg_dev_title') ?></h3>
  <p><?= t('cfg_webhook_desc') ?></p>
</div>
<div class="app-card" style="background:#FAFAFA">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div>
      <strong style="display:block;font-size:.9375rem"><?= t('cfg_webhook_name') ?></strong>
      <span class="text-muted" style="font-size:.8125rem"><?= t('cfg_webhook_desc') ?></span>
    </div>
  </div>
  <div class="form-group" style="margin:0">
    <label class="form-label"><?= t('cfg_webhook_label') ?></label>
    <input type="text" name="webhook_url" class="form-control" value="<?=sv($s,'webhook_url')?>" placeholder="https://hook.make.com/...">
  </div>
</div>
</section>

<div style="margin-bottom:32px">
  <button type="submit" form="cfg-main-form" class="btn btn-primary"><i class="ph-bold ph-floppy-disk"></i> <?= t('cfg_save_btn') ?></button>
</div>
</form>

</div><!-- .cfg-main -->
</div><!-- .cfg-layout -->

<div id="cfg-toast" class="cfg-toast cfg-toast--ok" hidden></div>

<form id="gcal-disconnect-form" action="backend/google_calendar_disconnect.php" method="post" style="display:none" aria-hidden="true">
<?php csrf_field(); ?>
</form>
<form id="gcal-enable-form" action="configuracoes" method="post" style="display:none" aria-hidden="true">
<?php csrf_field(); ?>
<input type="hidden" name="action" value="gcal_enable_only">
</form>
</main>
</div>
<script>
function toggleKey(btn){
  const inp = btn.closest('.key-input').querySelector('input');
  const isPass = inp.type==='password';
  inp.type = isPass?'text':'password';
  btn.innerHTML = isPass?'<i class="ph-bold ph-eye-slash"></i>':'<i class="ph-bold ph-eye"></i>';
}
</script>
<script src="assets/cfg-pipelines.js?v=20260522"></script>
<script src="assets/configuracoes.js?v=20260522"></script>
<?php include __DIR__ . '/includes/toast.php'; ?>
</body></html>
