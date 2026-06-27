<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once __DIR__ . '/backend/migrations.php';
require_once 'backend/AgentTemplates.php';
require_once __DIR__ . '/backend/auvvo_brain_tools.inc.php';

auvvo_run_migrations($pdo);

$user_id = $_SESSION['user_id'];
$brain_settings = [];
try {
    $stBrain = $pdo->prepare('SELECT company_site, google_calendar_enabled, google_sheets_enabled FROM settings WHERE user_id = ? LIMIT 1');
    $stBrain->execute([$user_id]);
    $brain_settings = $stBrain->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $brain_settings = [];
}
$brain_capabilities = auvvo_brain_capabilities_ui($pdo, $user_id, $brain_settings);
$edit_agent = null;

function auvvo_agent_name_is_valid(string $name): bool
{
    $n = trim($name);
    if ($n === '' || mb_strlen($n) < 2) {
        return false;
    }
    if (mb_strtolower($n) === mb_strtolower('Rascunho Novo Agente')) {
        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ============================================================
    // BLUEPRINTS (modelos reutilizaveis)
    // ============================================================
    if ($action === 'save_blueprint') {
        $agent_id = intval($_POST['agent_id'] ?? 0);
        $bp_name  = trim($_POST['blueprint_name'] ?? '');
        if (!$agent_id || $bp_name === '') {
            header("Location: agentes?error=blueprint_invalid"); exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        $ag = $stmt->fetch();
        if (!$ag) { header("Location: agentes?error=blueprint_invalid"); exit; }
        try {
            $pdo->prepare(
                "INSERT INTO blueprints (user_id, name, agent_type, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$user_id,$bp_name,$ag['agent_type'],$ag['role'],$ag['prompt_base'],$ag['type_config'],$ag['model'],$ag['temperature'],$ag['max_tokens'],$ag['response_delay'],$ag['audio_enabled'],$ag['audio_voice'],$ag['handoff_rules'],$ag['handoff_enabled'],$ag['handoff_message']]);
        } catch (PDOException $e) { header("Location: agentes?error=blueprint_table_missing"); exit; }
        header("Location: agentes?success=blueprint_saved"); exit;
    }

    if ($action === 'delete_blueprint') {
        $bp_id = intval($_POST['blueprint_id'] ?? 0);
        if ($bp_id) { try { $pdo->prepare("DELETE FROM blueprints WHERE id=? AND user_id=?")->execute([$bp_id,$user_id]); } catch (PDOException $e) {} }
        header("Location: agentes?success=blueprint_deleted"); exit;
    }

    if ($action === 'create_from_blueprint') {
        $bp_id = intval($_POST['blueprint_id'] ?? 0);
        $new_name = trim($_POST['new_agent_name'] ?? 'Novo Agente (Blueprint)');
        if (!$bp_id || $new_name === '') { header("Location: agentes?error=blueprint_invalid"); exit; }
        try { $stmt = $pdo->prepare("SELECT * FROM blueprints WHERE id=? AND user_id=?"); $stmt->execute([$bp_id,$user_id]); $bp = $stmt->fetch(); } catch (PDOException $e) { $bp = null; }
        if (!$bp) { header("Location: agentes?error=blueprint_invalid"); exit; }
        $stmt = $pdo->prepare("INSERT INTO agents (user_id, agent_type, name, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')");
        $stmt->execute([$user_id,$bp['agent_type'],$new_name,$bp['role'],$bp['prompt_base'],$bp['type_config'],$bp['model'],$bp['temperature'],$bp['max_tokens'],$bp['response_delay'],$bp['audio_enabled'],$bp['audio_voice'],$bp['handoff_rules'],$bp['handoff_enabled'],$bp['handoff_message']]);
        $new_id = $pdo->lastInsertId();
        header("Location: agentes?edit=" . $new_id . "&success=created_from_blueprint"); exit;
    }

    $name = trim($_POST['name'] ?? ''); $role = trim($_POST['role'] ?? 'Vendedor');
    $agent_type = $_POST['agent_type'] ?? 'vendedor';
    $prompt_base = trim($_POST['prompt_base'] ?? '');
    $type_config = isset($_POST['type_config']) ? json_encode($_POST['type_config'], JSON_UNESCAPED_UNICODE) : null;

    $prevAgent = null;
    if ($action === 'update_agent' && !empty($_POST['agent_id'])) {
        $aid = intval($_POST['agent_id']);
        $st = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $st->execute([$aid, $user_id]);
        $prevAgent = $st->fetch() ?: null;
    }

    $model = trim((string)($_POST['model'] ?? '')); if ($model===''&&$prevAgent) $model=(string)($prevAgent['model']??''); if ($model==='') $model='gpt-4o';
    $temperature = isset($_POST['temperature'])?floatval($_POST['temperature']):($prevAgent?floatval($prevAgent['temperature']):0.7);
    $max_tokens = isset($_POST['max_tokens'])?intval($_POST['max_tokens']):($prevAgent?intval($prevAgent['max_tokens']):1000);
    $response_delay = isset($_POST['response_delay'])?intval($_POST['response_delay']):($prevAgent?intval($prevAgent['response_delay']):2);
    $audio_enabled = isset($_POST['audio_enabled'])?1:($prevAgent?(int)$prevAgent['audio_enabled']:0);
    $audio_voice = trim((string)($_POST['audio_voice']??'')); if ($audio_voice===''&&$prevAgent) $audio_voice=(string)($prevAgent['audio_voice']??'');
    $handoff_rules = trim((string)($_POST['handoff_rules']??'')); if ($handoff_rules===''&&$prevAgent) $handoff_rules=(string)($prevAgent['handoff_rules']??''); if ($handoff_rules==='') $handoff_rules='humano, atendente, suporte';
    $handoff_enabled = isset($_POST['handoff_enabled'])?1:($prevAgent?(int)$prevAgent['handoff_enabled']:1);
    $handoff_message = trim((string)($_POST['handoff_message']??'')); if ($handoff_message===''&&$prevAgent&&array_key_exists('handoff_message',$prevAgent)&&$prevAgent['handoff_message']!==null) $handoff_message=(string)$prevAgent['handoff_message'];
    $allowed_langs = ['pt-BR','en','es','pt','fr','de','it','ja','zh']; $bot_language = in_array($_POST['bot_language']??'',$allowed_langs)?$_POST['bot_language']:'pt-BR';
    $flow_mode = ($_POST['flow_mode']??'easy')==='advanced'?'advanced':'easy';
    $flow_steps = json_decode((string)($_POST['flow_steps_json']??'[]'),true); if (!is_array($flow_steps)) $flow_steps=[];
    $flow_config = json_encode(['partner_agent_id'=>(int)($_POST['flow_partner_agent_id']??0),'steps'=>$flow_steps],JSON_UNESCAPED_UNICODE);

    if ($action == 'create_agent') {
        if (!auvvo_agent_name_is_valid($name)) { header('Location: agentes?edit=new&error=name_required'); exit; }
        try {
            $stmt = $pdo->prepare("INSERT INTO agents (user_id, agent_type, name, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, bot_language, flow_mode, flow_config, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'offline')");
            $stmt->execute([$user_id,$agent_type,$name,$role,$prompt_base,$type_config,$model,$temperature,$max_tokens,$response_delay,$audio_enabled,$audio_voice,$handoff_rules,$handoff_enabled,$handoff_message,$bot_language,$flow_mode,$flow_config]);
            $new_id = (int) $pdo->lastInsertId();
            header('Location: agentes?edit='.$new_id.'&success=created'); exit;
        } catch (PDOException $e) {
            error_log('[Auvvo] create_agent: '.$e->getMessage());
            header('Location: agentes?error=save_failed&detail='.urlencode($e->getMessage())); exit;
        }
    }
    if ($action == 'update_agent') {
        $agent_id = intval($_POST['agent_id']??0); if ($agent_id<=0||!$prevAgent) { header('Location: agentes?error=save_failed'); exit; }
        if (!auvvo_agent_name_is_valid($name)) { header("Location: agentes?edit={$agent_id}&error=name_required"); exit; }
        $wasDraft = ($prevAgent['status']??'')==='draft'; $newStatus = $wasDraft?'offline':(string)($prevAgent['status']??'offline');
        try {
            $stmt = $pdo->prepare("UPDATE agents SET agent_type=?,name=?,role=?,prompt_base=?,type_config=?,model=?,temperature=?,max_tokens=?,response_delay=?,audio_enabled=?,audio_voice=?,handoff_rules=?,handoff_enabled=?,handoff_message=?,bot_language=?,flow_mode=?,flow_config=?,status=? WHERE id=? AND user_id=?");
            $stmt->execute([$agent_type,$name,$role,$prompt_base,$type_config,$model,$temperature,$max_tokens,$response_delay,$audio_enabled,$audio_voice,$handoff_rules,$handoff_enabled,$handoff_message,$bot_language,$flow_mode,$flow_config,$newStatus,$agent_id,$user_id]);
        } catch (PDOException $e) {
            error_log('[Auvvo] update_agent: '.$e->getMessage());
            header("Location: agentes?edit={$agent_id}&error=save_failed&detail=".urlencode($e->getMessage())); exit;
        }
        $redirect_tab = max(0,intval($_POST['redirect_tab']??0));
        if ($wasDraft) { header("Location: agentes?edit={$agent_id}&success=created&tab={$redirect_tab}"); exit; }
        header("Location: agentes?edit={$agent_id}&tab={$redirect_tab}&success=updated"); exit;
    }
    if ($action == 'delete_agent') {
        $agent_id = intval($_POST['agent_id']??0);
        $stmt_kb = $pdo->prepare("SELECT file_name, file_type FROM knowledge_base WHERE agent_id = ?");
        $stmt_kb->execute([$agent_id]);
        foreach ($stmt_kb->fetchAll() as $kb_file) {
            if ($kb_file['file_type']!=='text') { $path = __DIR__.'/uploads/knowledge/'.$kb_file['file_name']; if (file_exists($path)) unlink($path); }
        }
        $pdo->prepare("DELETE FROM agents WHERE id=? AND user_id=?")->execute([$agent_id, $user_id]);
        header("Location: agentes?success=deleted"); exit;
    }
}//

// Limpar rascunhos abandonados há mais de 24h
$current_edit_id = intval($_GET['edit'] ?? 0);
$pdo->prepare("DELETE FROM agents WHERE user_id=? AND status='draft' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND id != ?")->execute([$user_id, $current_edit_id]);

$edit_agent = null;
if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $stmt = $pdo->prepare("INSERT INTO agents (user_id, name, agent_type, role, model, temperature, max_tokens, response_delay, status) VALUES (?, 'Rascunho Novo Agente', 'vendedor', 'Atendente', 'auvvo-ai', 0.7, 1000, 2, 'draft')");
        $stmt->execute([$user_id]);
        $draft_id = $pdo->lastInsertId();
        header("Location: agentes?edit=" . $draft_id); exit;
    } else {
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([intval($_GET['edit']), $user_id]);
        $edit_agent = $stmt->fetch();
        if(!$edit_agent) { header("Location: agentes"); exit; }
    }
}

$stmt = $pdo->prepare(
    "SELECT id, name, agent_type, role, prompt_base, status, model, audio_enabled, handoff_enabled, created_at
     FROM agents WHERE user_id=? AND status != 'draft' ORDER BY id DESC"
);
$stmt->execute([$user_id]);
$agents = $stmt->fetchAll();

$flow_edit_id = (int) ($edit_agent['id'] ?? 0);
$flow_agent_opts = [];
foreach ($agents as $ag) {
    if ($flow_edit_id > 0 && (int) $ag['id'] === $flow_edit_id) {
        continue;
    }
    $flow_agent_opts[] = ['id' => (int) $ag['id'], 'name' => $ag['name']];
}

// Blueprints do usuário (para agências reutilizarem configurações)
$blueprints = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, name, agent_type, model, created_at FROM blueprints WHERE user_id=? ORDER BY id DESC"
    );
    $stmt->execute([$user_id]);
    $blueprints = $stmt->fetchAll();
} catch (PDOException $e) {
    // Se a migração V8 ainda não foi aplicada, apenas não exibe a seção.
    $blueprints = [];
}

$a = $edit_agent ?? [];
$is_draft = ($a['status'] ?? '') === 'draft';
$type_config = isset($a['type_config']) ? json_decode($a['type_config'], true) : [];
$types = AgentTemplates::types();
function v($a, $k, $def='') { return htmlspecialchars($a[$k] ?? $def); }
function vc($tc, $k, $def='') { return htmlspecialchars($tc[$k] ?? $def); }
/** Nome exibido ao usuário (o valor salvo em agents.model continua técnico). */
function agent_model_display_label(string $model): string {
    if ($model === 'auvvo-ai') return 'Auvvo AI';
    if ($model === 'deepseek/chat') return 'DeepSeek V3';
    if ($model === 'deepseek/reasoner') return 'DeepSeek R1';
    return $model;
}
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('agents_title') ?></title>
<link rel="stylesheet" href="app.css">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<!-- estilos do builder foram consolidados em app.css -->
 
    <link rel="icon" type="image/png" href="icone.png">
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">

<!-- ===== VIEW: LISTA ===== -->
<div id="view-list" <?=$edit_agent?'class="view-hidden"':''?>>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= t('agents_page_title') ?></h1>
    <p class="page-hint"><?= t('agents_page_sub') ?> O <strong>cérebro</strong> executa integrações quando as instruções abaixo pedirem. <a href="automacoes">Automações</a> = gatilhos e mensagens fixas.</p>
  </div>
</div>
<style>
.brain-caps-panel{border-radius:16px;border:1px solid #E5E7EB;background:#fff;margin-bottom:28px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.brain-caps-header{padding:18px 24px 14px;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.brain-caps-title{display:flex;align-items:center;gap:10px;font-size:1rem;font-weight:700;color:#111827}
.brain-caps-title i{width:32px;height:32px;background:linear-gradient(135deg,#F0FDFA,#CCFBF1);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#14B8A6}
.brain-caps-subtitle{font-size:.8rem;color:#6B7280;margin:2px 0 0}
.brain-caps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;padding:0}
.brain-cap-item{padding:16px 20px;border-right:1px solid #F3F4F6;border-bottom:1px solid #F3F4F6;display:flex;flex-direction:column;gap:5px;transition:background .15s}
.brain-cap-item:hover{background:#FAFAFA}
.brain-cap-item.on{background:linear-gradient(135deg,#F0FDFA,#FAFFFE)}
.brain-cap-head{display:flex;align-items:center;gap:7px;margin-bottom:3px}
.brain-cap-dot{width:7px;height:7px;border-radius:50%;background:#D1D5DB;flex-shrink:0}
.brain-cap-item.on .brain-cap-dot{background:#10B981;box-shadow:0 0 6px rgba(16,185,129,.5)}
.brain-cap-label{font-size:.875rem;font-weight:600;color:#111827;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brain-cap-badge{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 7px;border-radius:99px;flex-shrink:0}
.brain-cap-item.on  .brain-cap-badge{background:#DCFCE7;color:#166534}
.brain-cap-item.off .brain-cap-badge{background:#F0FDFA;color:#0F766E}
.brain-cap-detail{font-size:.78rem;color:#6B7280;margin:0}
.brain-cap-hint{font-size:.72rem;color:#9CA3AF;margin:0;line-height:1.45}
.brain-caps-footer{padding:11px 24px;background:#FAFAFA;border-top:1px solid #F3F4F6;font-size:.75rem;color:#6B7280}
</style>
<div class="brain-caps-panel">
  <div class="brain-caps-header">
    <div>
      <div class="brain-caps-title">
        <i class="ph-bold ph-brain"></i>
        O que o cérebro pode fazer nesta conta
      </div>
      <p class="brain-caps-subtitle">Escreva no prompt do agente <em>quando</em> usar cada item — o backend executa, não é só texto.</p>
    </div>
  </div>
  <div class="brain-caps-grid">
    <?php foreach ($brain_capabilities as $cap): ?>
    <div class="brain-cap-item <?= $cap['connected'] ? 'on' : 'off' ?>">
      <div class="brain-cap-head">
        <span class="brain-cap-dot"></span>
        <span class="brain-cap-label"><?= htmlspecialchars($cap['label']) ?></span>
        <span class="brain-cap-badge"><?= $cap['connected'] ? 'Ativo' : 'Disponível' ?></span>
      </div>
      <p class="brain-cap-detail"><?= htmlspecialchars($cap['detail']) ?></p>
      <p class="brain-cap-hint"><?= htmlspecialchars($cap['hint']) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="brain-caps-footer">Recursos disponíveis — configure quando quiser: <a href="configuracoes">Configurações</a> · <a href="integracoes">Integrações</a> · <a href="conexoes">Conexões</a></div>
</div>
<div class="agent-grid">
<?php foreach($agents as $ag):
  $typeLabel = $types[$ag['agent_type'] ?? 'vendedor']['label'] ?? ucfirst((string) ($ag['agent_type'] ?? 'agente'));
?>
<div class="agent-card">
  <!-- Header -->
  <div class="agent-card-header">
    <div class="agent-card-avatar"><i class="ph-fill ph-robot"></i></div>
    <div class="agent-card-info">
      <div class="agent-card-name"><?=htmlspecialchars($ag['name'])?></div>
      <div class="agent-card-role"><?=htmlspecialchars($ag['role'])?></div>
    </div>
    <span class="agent-card-status-pill offline">
      <span class="pill-dot"></span>
      <?= htmlspecialchars($typeLabel) ?>
    </span>
  </div>
  <!-- Body -->
  <div class="agent-card-body">
    <div class="agent-card-badges">
      <span class="badge badge-gray" style="font-size:.7rem"><i class="ph-bold ph-cpu" style="margin-right:3px"></i><?=htmlspecialchars(agent_model_display_label($ag['model']??'gpt-4o'))?></span>
      <?php if($ag['audio_enabled']??false): ?><span class="badge badge-success" style="font-size:.7rem"><i class="ph-bold ph-speaker-high"></i> Áudio</span><?php endif; ?>
      <?php if($ag['handoff_enabled']??true): ?><span class="badge" style="background:#EDE9FE;color:#6D28D9;font-size:.7rem"><i class="ph-bold ph-git-branch" style="margin-right:2px"></i>Transbordo</span><?php endif; ?>
    </div>
    <p class="agent-card-prompt"><?=htmlspecialchars($ag['prompt_base']?:t('agents_no_prompt'))?></p>
  </div>
  <!-- Footer -->
  <div class="agent-card-footer">
    <form method="POST" onsubmit="return confirm('Remover <?=htmlspecialchars(addslashes($ag['name']))?>?')" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_agent">
      <input type="hidden" name="agent_id" value="<?=$ag['id']?>">
      <button type="submit" class="btn btn-icon" style="color:#EF4444;width:34px;height:34px;border-radius:8px;background:#FEF2F2;border:none" title="Remover"><i class="ph-bold ph-trash"></i></button>
    </form>
    <a href="agentes?edit=<?=$ag['id']?>" class="btn btn-primary" style="padding:7px 18px;font-size:.8125rem;border-radius:9px;margin-left:auto"><?= t('agents_edit_btn') ?> <i class="ph-bold ph-pencil-simple" style="margin-left:4px"></i></a>
  </div>
</div>
<?php endforeach; ?>
<div class="agent-card dashed" onclick="window.location='agentes?edit=new'" role="button" tabindex="0">
  <div class="new-agent-icon"><i class="ph-bold ph-plus"></i></div>
  <strong style="font-size:.9375rem;color:#374151"><?= t('agents_new_btn') ?></strong>
  <span style="font-size:.8125rem;color:#9CA3AF"><?= t('agents_new_sub') ?></span>
</div>
</div>

<?php if(!empty($blueprints)): ?>
  <div style="margin-top:32px">
    <div class="page-header" style="margin-bottom:14px">
      <div>
        <h2 class="page-title" style="font-size:1.25rem"><?= t('agents_blueprints') ?></h2>
        <p class="text-muted"><?= t('agents_bp_sub') ?></p>
      </div>
    </div>
    <div class="agent-grid">
      <?php foreach($blueprints as $bp): ?>
      <div class="agent-card" style="border-style:dashed">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <div class="chat-avatar" style="background:rgba(139,92,246,.12);color:#6D28D9"><i class="ph-fill ph-bookmark-simple"></i></div>
            <div style="min-width:0">
              <h3 style="font-size:1.05rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($bp['name'])?></h3>
              <span class="text-muted" style="font-size:.8125rem">Tipo: <?=htmlspecialchars($bp['agent_type'])?> • Modelo: <?=htmlspecialchars(agent_model_display_label($bp['model'] ?? 'gpt-4o'))?></span>
            </div>
          </div>
          <form method="POST" onsubmit="return confirm('<?= addslashes(t('agents_bp_delete')) ?>')" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_blueprint">
            <input type="hidden" name="blueprint_id" value="<?=$bp['id']?>">
            <button type="submit" class="btn btn-icon" style="color:var(--text-danger)"><i class="ph-bold ph-trash"></i></button>
          </form>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <span class="badge badge-gray"><?=htmlspecialchars($bp['role'] ?? '—')?></span>
          <?php if(($bp['handoff_enabled'] ?? 1)): ?><span class="badge" style="background:#EDE9FE;color:#6D28D9">Transbordo</span><?php endif; ?>
        </div>

        <div style="display:flex;gap:8px;margin-top:auto;padding-top:14px;border-top:1px solid var(--border-subtle)">
          <button type="button" class="btn btn-primary" onclick="createFromBlueprint(<?=intval($bp['id'])?>, '<?=htmlspecialchars(addslashes($bp['name']))?>')"><i class="ph-bold ph-plus"></i> <?= t('agents_bp_create_btn') ?></button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<!-- ===== VIEW: CRIAR / EDITAR ===== -->
<div id="view-edit" <?=!$edit_agent&&!isset($_GET['edit'])?'class="view-hidden"':''?>>
<form action="agentes" method="POST" id="form-main-agent" onsubmit="return validateAgentSave()">
  <?php csrf_field(); ?>
  <input type="hidden" name="redirect_tab" id="redirect-tab-input" value="0">
  <?php if($edit_agent): ?>
    <input type="hidden" name="action" value="update_agent">
    <input type="hidden" name="agent_id" value="<?=$edit_agent['id']?>">
  <?php else: ?>
    <input type="hidden" name="action" value="create_agent">
  <?php endif; ?>
  <!-- estilos do builder foram consolidados em app.css -->
  <div class="builder-layout">
    <aside class="glass-sidebar">
      <div style="margin-bottom: 32px;">
        <a href="agentes" class="btn btn-outline btn-block">
          <i class="ph-bold ph-arrow-left"></i> Voltar
        </a>
      </div>

      <?php if ($is_draft): ?>
      <div class="app-card" style="padding:14px;margin-bottom:16px;background:#FFFBEB;border:1px solid #FDE68A">
        <strong style="font-size:.8125rem;color:#92400E;display:block;margin-bottom:6px"><i class="ph-bold ph-info"></i> Novo agente</strong>
        <p style="font-size:.75rem;color:#78350F;margin:0;line-height:1.45">Configure o <strong>cérebro</strong> (identidade, prompt, conhecimento). WhatsApp e gatilhos ficam em <a href="automacoes" style="color:#92400E">Automações</a>.</p>
      </div>
      <?php endif; ?>
      
      <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; padding-left: 12px;">Configurações do Agente</div>
      
      <div class="wizard-step active" id="step-nav-0" onclick="switchTab(0)"><i class="ph-bold ph-identification-card"></i> <span>Identidade</span></div>
      <div class="wizard-step" id="step-nav-1" onclick="switchTab(1)"><i class="ph-bold ph-terminal-window"></i> <span>Prompt</span></div>
      <div class="wizard-step" id="step-nav-2" onclick="switchTab(2)"><i class="ph-bold ph-brain"></i> <span>Conhecimento</span></div>
      <div class="wizard-step" id="step-nav-3" onclick="switchTab(3)"><i class="ph-bold ph-link"></i> <span>Links</span></div>
      <div class="wizard-step" id="step-nav-4" onclick="switchTab(4)"><i class="ph-bold ph-clock"></i> <span>Follow-up</span></div>
      <div class="wizard-step" id="step-nav-5" onclick="switchTab(5)"><i class="ph-bold ph-pause-circle"></i> <span>Pausa</span></div>
      
      <div style="margin-top: auto; padding-top: 24px;">
        <div id="readiness-box" class="app-card" style="padding:14px 14px;margin-bottom:12px;background:rgba(0,0,0,0.01);border:1px dashed var(--border-subtle);display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
            <strong style="font-size:.875rem">Prontidão</strong>
            <span class="badge badge-gray" id="readiness-score">—</span>
          </div>
          <div style="height:8px;background:#E5E7EB;border-radius:99px;overflow:hidden;margin-bottom:10px">
            <div id="readiness-bar" style="height:100%;width:0%;background:#10B981;transition:width .25s"></div>
          </div>
          <div id="readiness-items" style="display:flex;flex-direction:column;gap:6px;font-size:.8125rem;color:var(--text-muted)"></div>
          <div id="token-hint" style="margin-top:10px;display:none;font-size:.75rem;color:var(--text-muted)"></div>
        </div>
        <?php if($edit_agent): ?>
          <button type="button" class="btn btn-outline btn-block" onclick="saveBlueprintPrompt()" style="margin-bottom:10px">
            <i class="ph-bold ph-bookmark-simple"></i> Salvar como Blueprint
          </button>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-block" id="btn-save-agent">
          <i class="ph-bold ph-floppy-disk"></i> <?= $is_draft ? 'Criar agente' : 'Salvar alterações' ?>
        </button>
      </div>
    </aside>

    <main class="builder-content">
      
<?php if(isset($_GET['success'])): $msgs=['created'=>t('agents_msg_created'),'updated'=>t('agents_msg_updated'),'deleted'=>t('agents_msg_deleted'),'blueprint_saved'=>t('agents_msg_bp_saved'),'blueprint_deleted'=>t('agents_msg_bp_deleted'),'created_from_blueprint'=>t('agents_msg_bp_created')]; ?>
<div style="background:var(--surface-success);color:var(--text-success);padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
<i class="ph-bold ph-check-circle"></i>
<span><?=htmlspecialchars($msgs[$_GET['success']]??'OK')?></span>
<?php if (($_GET['success'] ?? '') === 'created'): ?>
<span style="font-size:.875rem">Seu agente está pronto! Para atender no WhatsApp, vincule uma <a href="conexoes">Conexão</a>. É opcional — o agente já funciona sem isso.</span>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if(isset($_GET['error'])): $errMsgs=['name_required'=>'Informe um nome para o agente (mínimo 2 caracteres, diferente de "Rascunho Novo Agente").','save_failed'=>'Não foi possível salvar o agente. Recarregue a página e tente de novo.','blueprint_invalid'=>'Blueprint inválido.']; ?>
<div style="background:#FEF2F2;color:#991B1B;padding:12px 24px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
<i class="ph-bold ph-warning-circle"></i>
<span><?=htmlspecialchars($errMsgs[$_GET['error']] ?? 'Ocorreu um erro.')?></span>
<?php if (!empty($_GET['detail']) && ($_GET['error'] ?? '') === 'save_failed'): ?>
<span style="font-size:.75rem;opacity:.85">Detalhe técnico: <?= htmlspecialchars((string) $_GET['detail']) ?></span>
<?php endif; ?>
</div>
<?php endif; ?>

      <!-- TAB 0: IDENTIDADE -->
      <div id="tab-0" class="tab-panel active">
        <div class="app-card">
          <div class="glass-card-header">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div>
                <h3 class="glass-card-title"><i class="ph-bold ph-identification-card"></i> Identidade do Agente</h3>
                <p class="text-muted" style="margin-top: 8px;">Defina quem é o agente e como ele deve se portar.</p>
              </div>
              <button type="button" class="btn btn-outline" onclick="openQuickSetup()"><i class="ph-bold ph-magic-wand"></i> Assistente rápido</button>
            </div>
          </div>
          <div class="form-two-col">
            <div>
              <details class="collapsible" open>
                <summary>
                  <span class="left"><i class="ph-bold ph-user-circle"></i> Básico</span>
                  <span class="text-muted" style="font-weight:700;font-size:.8125rem">Nome, organização, tipo e tom</span>
                </summary>
                <div class="collapsible-body">
                  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div class="form-group">
                      <label class="form-label">Nome do assistente</label>
                      <input type="text" name="name" class="form-control" placeholder="Ex: Bia, Carlos..." value="<?= ($is_draft && ($a['name'] ?? '') === 'Rascunho Novo Agente') ? '' : v($a,'name') ?>" required minlength="2">
                    </div>
                    <div class="form-group">
                      <label class="form-label">Nome da organização</label>
                      <input type="text" name="type_config[organizacao_nome]" class="form-control" placeholder="Ex: Acme Corp" value="<?=vc($type_config,'organizacao_nome')?>">
                    </div>
                  </div>

                  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div class="form-group">
                      <label class="form-label">Função (Comportamento na conversa)</label>
                       <div class="form-select-wrapper">
                           <select name="agent_type" class="form-control">
                             <option value="Auvvo" <?=(($a['agent_type']??'')==='Auvvo')?'selected':''?>>Auvvo (Triagem)</option>
                             <option value="vendedor" <?=(($a['agent_type']??'vendedor')==='vendedor')?'selected':''?>>Vendedor</option>
                             <option value="atendente" <?=(($a['agent_type']??'')==='atendente')?'selected':''?>>Atendente</option>
                             <option value="suporte" <?=(($a['agent_type']??'')==='suporte')?'selected':''?>>Suporte Tecnico</option>
                             <option value="sdr" <?=(($a['agent_type']??'')==='sdr')?'selected':''?>>Agendamentos (SDR)</option>
                             <option value="agendamentos" <?=(($a['agent_type']??'')==='agendamentos')?'selected':''?>>Agendamentos (Barbearia/Clinica)</option>
                             <option value="recuperacao" <?=(($a['agent_type']??'')==='recuperacao')?'selected':''?>>Recuperacao de Carrinho</option>
                             <option value="imobiliaria" <?=(($a['agent_type']??'')==='imobiliaria')?'selected':''?>>Imobiliaria / Corretor</option>
                             <option value="restaurante" <?=(($a['agent_type']??'')==='restaurante')?'selected':''?>>Delivery / Restaurante</option>
                             <option value="faq_inteligente" <?=(($a['agent_type']??'')==='faq_inteligente')?'selected':''?>>FAQ + Transbordo Humano</option>
                           </select>
                           <i class="ph-bold ph-caret-down"></i>
                       </div>
                    </div>
                    <div class="form-group">
                      <label class="form-label"><i class="ph-bold ph-globe" style="margin-right:4px"></i>Idioma de Resposta do Bot</label>
                      <?php
                        $bot_lang_val = $a['bot_language'] ?? 'pt-BR';
                        $bot_langs = [
                          'pt-BR' => '🇧🇷 Português (Brasil)',
                          'en'    => '🇺🇸 English',
                          'es'    => '🇪🇸 Español',
                          'pt'    => '🇵🇹 Português (Portugal)',
                          'fr'    => '🇫🇷 Français',
                          'de'    => '🇩🇪 Deutsch',
                          'it'    => '🇮🇹 Italiano',
                          'ja'    => '🇯🇵 日本語',
                          'zh'    => '🇨🇳 中文',
                        ];
                      ?>
                      <div class="form-select-wrapper">
                        <select name="bot_language" class="form-control">
                          <?php foreach($bot_langs as $code => $label): ?>
                          <option value="<?=$code?>" <?=$bot_lang_val===$code?'selected':''?>><?=$label?></option>
                          <?php endforeach; ?>
                        </select>
                        <i class="ph-bold ph-caret-down"></i>
                      </div>
                      <div class="text-muted" style="font-size:.75rem;margin-top:6px;line-height:1.5">
                        O bot responderá <strong>sempre</strong> neste idioma, independente da língua do cliente.
                      </div>
                    </div>
                  </div>

                  <?php
                    $flow_cfg = json_decode((string)($a['flow_config'] ?? '{}'), true);
                    if (!is_array($flow_cfg)) $flow_cfg = [];
                    $flow_mode_cur = ($a['flow_mode'] ?? 'easy') === 'advanced' ? 'advanced' : 'easy';
                    $partner_id = (int)($flow_cfg['partner_agent_id'] ?? 0);
                    $flow_steps = $flow_cfg['steps'] ?? [];
                  ?>
                  <div class="divider"></div>
                  <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px"><i class="ph-bold ph-flow-arrow"></i> Transbordo entre agentes (opcional)</div>
                  <p class="text-muted" style="font-size:.8125rem;margin:-8px 0 14px">Não confundir com <a href="automacoes">Automações do CRM</a>. Aqui é só troca de agente IA na conversa (parceiro ou passos internos).</p>
                  <div class="form-group">
                    <label class="form-label">Modo</label>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                      <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="radio" name="flow_mode" value="easy" <?=$flow_mode_cur==='easy'?'checked':''?> onchange="toggleFlowMode()"> Inteligente (fácil)</label>
                      <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="radio" name="flow_mode" value="advanced" <?=$flow_mode_cur==='advanced'?'checked':''?> onchange="toggleFlowMode()"> Fluxo avançado</label>
                    </div>
                  </div>
                  <div id="flow-easy" class="form-group" style="<?=$flow_mode_cur!=='easy'?'display:none':''?>">
                    <label class="form-label">Agente parceiro (transbordo / especialista)</label>
                    <select name="flow_partner_agent_id" class="form-control">
                      <option value="0">— Nenhum —</option>
                      <?php foreach ($agents as $ag): if (!empty($edit_agent) && (int)$ag['id'] === (int)$edit_agent['id']) continue; ?>
                      <option value="<?=(int)$ag['id']?>" <?=$partner_id===(int)$ag['id']?'selected':''?>><?=htmlspecialchars($ag['name'])?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div id="flow-advanced" style="<?=$flow_mode_cur!=='advanced'?'display:none':''?>">
                    <input type="hidden" name="flow_steps_json" id="flow-steps-json" value="<?=htmlspecialchars(json_encode($flow_steps, JSON_UNESCAPED_UNICODE))?>">
                    <div id="flow-steps-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px"></div>
                    <button type="button" class="btn btn-secondary" onclick="addFlowStep()"><i class="ph-bold ph-plus"></i> Adicionar passo</button>
                  </div>

                  <div class="divider"></div>

                  <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px"><i class="ph-bold ph-cpu"></i> Motor de IA</div>
                  <p class="text-muted" style="font-size:.8125rem;margin:-8px 0 14px;line-height:1.55">
                    O modelo define o provedor. Suas chaves em <strong>Configurações</strong>: OpenAI (<code>gpt-*</code>) e Gemini (<code>gemini*</code>). <strong>Auvvo AI</strong> usa a infraestrutura da plataforma — sem chave sua.
                  </p>
                  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <?php $sel_model = $a['model'] ?? 'openrouter/owl-alpha'; ?>
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Modelo</label>
                      <div class="form-select-wrapper">
                        <select name="model" class="form-control">
                          <?php
                          $known_models = ['gpt-4o','gpt-4o-mini','gpt-4-turbo','gpt-3.5-turbo','gemini-flash-latest','openrouter/owl-alpha','deepseek/chat','deepseek/reasoner'];
                          if (!in_array($sel_model, $known_models, true)): ?>
                          <option value="<?=htmlspecialchars($sel_model)?>" selected><?=htmlspecialchars(agent_model_display_label($sel_model))?></option>
                          <?php endif; ?>
                          
                          <option value="auvvo-ai" <?= $sel_model === 'auvvo-ai' ? 'selected' : '' ?>>Auvvo AI</option>
                          <option value="deepseek/chat" <?= $sel_model === 'deepseek/chat' ? 'selected' : '' ?>>DeepSeek — V3 (chat)</option>
                          <option value="deepseek/reasoner" <?= $sel_model === 'deepseek/reasoner' ? 'selected' : '' ?>>DeepSeek — R1 (reasoner)</option>
                          <option value="gpt-4o" <?= $sel_model === 'gpt-4o' ? 'selected' : '' ?>>OpenAI — gpt-4o</option>
                          <option value="gpt-4o-mini" <?= $sel_model === 'gpt-4o-mini' ? 'selected' : '' ?>>OpenAI — gpt-4o-mini</option>
                          <option value="gpt-4-turbo" <?= $sel_model === 'gpt-4-turbo' ? 'selected' : '' ?>>OpenAI — gpt-4-turbo</option>
                          <option value="gpt-3.5-turbo" <?= $sel_model === 'gpt-3.5-turbo' ? 'selected' : '' ?>>OpenAI — gpt-3.5-turbo</option>
                          <option value="gemini-flash-latest" <?= $sel_model === 'gemini-flash-latest' ? 'selected' : '' ?>>Google — gemini-flash-latest</option>
                        </select>
                        <i class="ph-bold ph-caret-down"></i>
                      </div>
                    </div>
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Delay de resposta (segundos)</label>
                      <input type="number" name="response_delay" class="form-control" min="0" max="60" step="1" value="<?= htmlspecialchars((string)($a['response_delay'] ?? 2)) ?>">
                    </div>
                  </div>
                  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px;">
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Temperatura (0–2)</label>
                      <input type="number" name="temperature" class="form-control" min="0" max="2" step="0.05" value="<?= htmlspecialchars((string)($a['temperature'] ?? '0.7')) ?>">
                    </div>
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Máx. tokens (resposta)</label>
                      <input type="number" name="max_tokens" class="form-control" min="256" max="8192" step="64" value="<?= htmlspecialchars((string)($a['max_tokens'] ?? 1024)) ?>">
                    </div>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Tom de voz</label>
                    <div class="option-pill-row" id="tone-pill-row">
                      <?php $tone = vc($type_config,'tone','Amigável / Descontraído'); $tones = [
                        'Engajador / Entusiástico' => 'Enérgico e motivador',
                        'Formal / Profissional' => 'Direto ao ponto, sem gírias',
                        'Amigável / Descontraído' => 'Leve e próximo, natural',
                        'Objetivo / Direto' => 'Curto, prático e claro',
                        'Acolhedor / Empático' => 'Calmo e compreensivo',
                      ]; foreach($tones as $tlabel => $tdesc): ?>
                        <label class="option-pill <?=$tone===$tlabel?'selected':''?>">
                          <input type="radio" name="type_config[tone]" value="<?=htmlspecialchars($tlabel)?>" <?=$tone===$tlabel?'checked':''?>>
                          <?=htmlspecialchars(explode(' / ', $tlabel)[0])?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="text-muted" style="font-size:.8125rem;margin-top:8px">Escolha um tom. O agente vai manter esse estilo durante a conversa.</div>
                  </div>

                  <div class="divider"></div>

                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                    <div class="form-group" style="margin:0">
                      <label class="form-label">O que esse negócio faz? (contexto do estabelecimento)</label>
                      <textarea name="type_config[descricao]" class="form-control" rows="3" placeholder="Ex: Clínica odontológica focada em implantes e estética. Atendimento presencial em Curitiba..."><?=vc($type_config,'descricao')?></textarea>
                    </div>
                    <div class="form-group" style="margin:0">
                      <label class="form-label">Mensagem de abertura (opcional)</label>
                      <input type="text" name="type_config[abertura]" class="form-control" placeholder="Ex: Olá! Sou a Bia da Acme. Posso te ajudar com..." value="<?=vc($type_config,'abertura')?>">
                    </div>
                  </div>
                </div>
              </details>
            </div>

            <aside class="preview-card" aria-label="Prévia da Identidade">
              <div class="preview-title"><i class="ph-bold ph-sparkle"></i> Prévia da Identidade</div>
              <div class="preview-body">Uma boa identidade deixa o atendimento mais humano e consistente. Use isso como uma “checagem rápida” antes de avançar.</div>
              <div class="preview-kv">
                <div class="kv"><div class="k">Agente</div><div class="v" id="pv-agent"><?=htmlspecialchars($a['name'] ?? '—')?></div></div>
                <div class="kv"><div class="k">Organização</div><div class="v" id="pv-org"><?=htmlspecialchars($type_config['organizacao_nome'] ?? '—')?></div></div>
                <div class="kv"><div class="k">Tipo</div><div class="v" id="pv-type"><?=htmlspecialchars($a['agent_type'] ?? 'vendedor')?></div></div>
                <div class="kv"><div class="k">Tom</div><div class="v" id="pv-tone"><?=htmlspecialchars($type_config['tone'] ?? '—')?></div></div>
              </div>
              <div class="preview-progress">
                <small>Completude</small>
                <div class="bar"><div class="fill" id="pv-fill"></div></div>
              </div>
            </aside>
          </div>
        </div>
      </div>

      <!-- TAB 1: PROMPT -->
      <div id="tab-1" class="tab-panel">
        <div class="app-card">
          <div class="glass-card-header">
            <h3 class="glass-card-title"><i class="ph-bold ph-terminal-window"></i> Prompt de Instrução</h3>
            <p class="text-muted" style="margin-top: 8px;">Configure as diretrizes principais do comportamento do agente.</p>
          </div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px">
            <button type="button" class="btn btn-outline" onclick="openPromptPreview()"><i class="ph-bold ph-eye"></i> Ver prompt final</button>
            <div class="text-muted" style="font-size:.875rem;line-height:1.5;display:flex;align-items:center">Mostra o prompt mestre completo (template + campos + conhecimento).</div>
          </div>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px;padding:14px 18px;background:linear-gradient(135deg,#F0F9FF,#ECFEFF);border:1px solid #BAE6FD;border-radius:12px">
            <button type="button" class="btn btn-primary" id="btn-ai-generate" onclick="generateWithAI()" style="background:linear-gradient(135deg,#6366F1,#8B5CF6);border:none;font-weight:600;white-space:nowrap">
              <i class="ph-bold ph-sparkle"></i> Gerar com IA
            </button>
            <span id="ai-generate-loading" style="display:none;color:#6366F1;font-weight:600;font-size:.875rem"><i class="ph-bold ph-circle-notch ph-spin" style="margin-right:6px"></i>Gerando prompt...</span>
            <span style="font-size:.8125rem;color:#475569">A IA analisa seus campos e cria um prompt otimizado para WhatsApp, sem markdown.</span>
          </div>
          <div class="form-group">
            <label class="form-label">Identidade e Personalidade</label>
            <textarea name="type_config[prompt_identidade]" class="form-control" rows="3" placeholder="Ex: Você é um assistente calmo, atencioso..."><?=vc($type_config,'prompt_identidade')?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Objetivo e Foco Principal</label>
            <textarea name="type_config[prompt_objetivo]" class="form-control" rows="3" placeholder="Ex: Seu objetivo é sanar dúvidas básicas..."><?=vc($type_config,'prompt_objetivo')?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Limites e Restrições</label>
            <textarea name="type_config[prompt_restricoes]" class="form-control" rows="3" placeholder="Ex: Não ofereça descontos."><?=vc($type_config,'prompt_restricoes')?></textarea>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Regras extras (opcional)</label>
            <textarea id="prompt-base-ta" name="prompt_base" class="form-control" rows="6" placeholder="Escreva regras adicionais em tópicos. Ex:\n- Sempre cumprimente pelo nome\n- Se pedirem desconto, ofereça valor e só depois condições\n- Se faltar informação, peça 1 dado por vez"><?=v($a,'prompt_base')?></textarea>
            <div class="text-muted" style="font-size:.8125rem;margin-top:8px">
              Isso entra como camada final no prompt mestre (além do template do tipo e dos campos acima).
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL: Prompt Preview -->
      <div class="modal-overlay" id="prompt-modal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-label="Prompt final">
          <div class="modal-header">
            <div class="modal-title"><i class="ph-bold ph-terminal-window"></i> Prompt final (mestre)</div>
            <button type="button" class="btn btn-icon" onclick="closePromptPreview()" title="Fechar"><i class="ph-bold ph-x"></i></button>
          </div>
          <div class="modal-body">
            <div class="terminal-meta" id="prompt-meta" style="display:none"></div>
            <div id="prompt-preview" class="terminal-block">Carregando...</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="copyPromptPreview()"><i class="ph-bold ph-copy"></i> Copiar</button>
            <button type="button" class="btn btn-primary" onclick="closePromptPreview()">Fechar</button>
          </div>
        </div>
      </div>

      <!-- TAB 2: CONHECIMENTO -->
      <div id="tab-2" class="tab-panel">
        <div class="app-card">
          <div class="glass-card-header">
            <h3 class="glass-card-title"><i class="ph-bold ph-brain"></i> Conhecimento Base</h3>
            <p class="text-muted" style="margin-top: 8px;">Ensine ao agente tudo que ele precisa saber.</p>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-vendedor" style="display:none">
            <div class="section-title"><i class="ph-bold ph-package"></i> Produtos/serviços (Vendedor)</div>
            <div class="section-sub">Deixe aqui os produtos, preços e descrições. Assim não fica duplicado na Identidade.</div>

            <div class="repeater-row">
              <div class="grow">
                <label class="form-label" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Nome</label>
                <input type="text" id="prod-name" class="form-control" placeholder="Ex: Plano Pro">
              </div>
              <div style="width:180px">
                <label class="form-label" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Preço (opcional)</label>
                <input type="text" id="prod-price" class="form-control" placeholder="Ex: R$ 199/mês">
              </div>
              <div class="grow">
                <label class="form-label" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Descrição (opcional)</label>
                <input type="text" id="prod-desc" class="form-control" placeholder="Ex: Inclui X, Y e Z">
              </div>
              <div style="align-self:end">
                <button type="button" class="btn btn-outline" onclick="addProductItem()"><i class="ph-bold ph-plus"></i> Adicionar</button>
              </div>
            </div>

            <div class="repeater-list" id="prod-list"></div>
            <textarea name="type_config[vendedor_produtos]" id="prod-textarea" class="form-control" rows="5" style="display:none"><?=vc($type_config,'vendedor_produtos')?></textarea>

            <div class="divider"></div>

            <?php $obj_json = $type_config['vendedor_objecoes_json'] ?? '[]'; ?>
            <?php $cta_json = $type_config['vendedor_ctas_json'] ?? '[]'; ?>
            <input type="hidden" name="type_config[vendedor_objecoes_json]" id="obj-json" value="<?=htmlspecialchars(is_string($obj_json)?$obj_json:json_encode($obj_json, JSON_UNESCAPED_UNICODE))?>">
            <input type="hidden" name="type_config[vendedor_ctas_json]" id="cta-json" value="<?=htmlspecialchars(is_string($cta_json)?$cta_json:json_encode($cta_json, JSON_UNESCAPED_UNICODE))?>">

            <div class="section-title"><i class="ph-bold ph-handshake"></i> Objeções e CTAs (Vendedor)</div>
            <div class="section-sub">Cadastre como lista. O agente usa isso para responder objeções e sempre puxar o próximo passo.</div>

            <div class="app-card" style="background: rgba(0,0,0,0.01); border-radius: 14px; margin-bottom: 14px;">
              <div class="form-label">Objeções comuns</div>
              <div class="repeater-row">
                <div class="grow">
                  <input type="text" id="obj-text" class="form-control" placeholder="Ex: 'Está caro' / 'Vou pensar' / 'Preciso falar com alguém'">
                </div>
                <div style="align-self:end">
                  <button type="button" class="btn btn-outline" onclick="addObjectionItem()"><i class="ph-bold ph-plus"></i> Adicionar</button>
                </div>
              </div>
              <div class="repeater-list" id="obj-list"></div>
              <textarea name="type_config[vendedor_objecoes_comuns]" id="obj-textarea" class="form-control" rows="4" style="display:none"><?=vc($type_config,'vendedor_objecoes_comuns')?></textarea>
            </div>

            <div class="app-card" style="background: rgba(0,0,0,0.01); border-radius: 14px;">
              <div class="form-label">CTAs (próximo passo)</div>
              <div class="repeater-row">
                <div class="grow">
                  <input type="text" id="cta-text" class="form-control" placeholder="Ex: 'Posso te enviar o link de pagamento?'">
                </div>
                <div style="align-self:end">
                  <button type="button" class="btn btn-outline" onclick="addCtaItem()"><i class="ph-bold ph-plus"></i> Adicionar</button>
                </div>
              </div>
              <div class="repeater-list" id="cta-list"></div>
              <textarea name="type_config[vendedor_cta_padrao]" id="cta-textarea" class="form-control" rows="4" style="display:none"><?=vc($type_config,'vendedor_cta_padrao')?></textarea>
            </div>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-agendamentos" style="display:none">
            <div class="section-title"><i class="ph-bold ph-calendar-check"></i> Servicos e Horarios (Agendamentos)</div>
            <div class="section-sub">Configure os servicos, profissionais e horarios disponiveis.</div>
            <div class="form-group">
              <label class="form-label">Servicos e Precos</label>
              <textarea name="type_config[agendamentos_servicos]" class="form-control" rows="4" placeholder="Ex: Corte de Cabelo - R$ 40 (30 min)&#10;Barba - R$ 30 (20 min)&#10;Hidratacao - R$ 80 (60 min)"><?=vc($type_config,'agendamentos_servicos')?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Profissionais Disponiveis</label>
              <input type="text" name="type_config[agendamentos_profissionais]" class="form-control" placeholder="Ex: Joao (corte/barba), Maria (hidratacao/coloracao)" value="<?=vc($type_config,'agendamentos_profissionais')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Horarios de Funcionamento</label>
              <input type="text" name="type_config[agendamentos_horarios]" class="form-control" placeholder="Ex: Seg a Sab, 9h as 19h. Fechado domingos e feriados." value="<?=vc($type_config,'agendamentos_horarios')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Endereco do Estabelecimento</label>
              <input type="text" name="type_config[agendamentos_endereco]" class="form-control" placeholder="Ex: Rua das Flores, 123, Centro - Curitiba/PR" value="<?=vc($type_config,'agendamentos_endereco')?>">
            </div>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-recuperacao" style="display:none">
            <div class="section-title"><i class="ph-bold ph-shopping-cart"></i> Configuracao de Recuperacao</div>
            <div class="section-sub">Defina cupons, links e prazos para recuperar carrinhos abandonados.</div>
            <div class="form-group">
              <label class="form-label">Exemplo de Produto (para referencia)</label>
              <input type="text" name="type_config[recuperacao_produto_exemplo]" class="form-control" placeholder="Ex: Kit Completo de Skincare - R$ 149,90" value="<?=vc($type_config,'recuperacao_produto_exemplo')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Cupom de Desconto (se disponivel)</label>
              <input type="text" name="type_config[recuperacao_cupom]" class="form-control" placeholder="Ex: VOLTEI10 (10% de desconto)" value="<?=vc($type_config,'recuperacao_cupom')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Link Base do Checkout</label>
              <input type="text" name="type_config[recuperacao_link_checkout]" class="form-control" placeholder="Ex: https://meusite.com/checkout/" value="<?=vc($type_config,'recuperacao_link_checkout')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Prazo Pagamento Pix (horas)</label>
              <input type="number" name="type_config[recuperacao_prazo_pix]" class="form-control" placeholder="Ex: 24" value="<?=vc($type_config,'recuperacao_prazo_pix')?>">
            </div>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-imobiliaria" style="display:none">
            <div class="section-title"><i class="ph-bold ph-house-line"></i> Portfolio Imobiliario</div>
            <div class="section-sub">Regioes, precos e tipos de imoveis que voce trabalha.</div>
            <div class="form-group">
              <label class="form-label">Regioes e Bairros de Atuacao</label>
              <input type="text" name="type_config[imobiliaria_regioes]" class="form-control" placeholder="Ex: Centro, Batel, Agua Verde, Bigorrilho - Curitiba/PR" value="<?=vc($type_config,'imobiliaria_regioes')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Faixa de Preco dos Imoveis</label>
              <input type="text" name="type_config[imobiliaria_faixa_preco]" class="form-control" placeholder="Ex: R$ 200.000 a R$ 2.000.000" value="<?=vc($type_config,'imobiliaria_faixa_preco')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Tipos de Imoveis</label>
              <input type="text" name="type_config[imobiliaria_tipos]" class="form-control" placeholder="Ex: Apartamento, Casa, Cobertura, Comercial, Terreno" value="<?=vc($type_config,'imobiliaria_tipos')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Link do Portfolio/Site</label>
              <input type="url" name="type_config[imobiliaria_link_portfolio]" class="form-control" placeholder="Ex: https://minhaimobiliaria.com/imoveis" value="<?=vc($type_config,'imobiliaria_link_portfolio')?>">
            </div>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-restaurante" style="display:none">
            <div class="section-title"><i class="ph-bold ph-pizza"></i> Cardapio e Entrega</div>
            <div class="section-sub">Configure cardapio, taxas e formas de pagamento.</div>
            <div class="form-group">
              <label class="form-label">Cardapio e Precos</label>
              <textarea name="type_config[restaurante_cardapio]" class="form-control" rows="5" placeholder="Ex: Pizza Grande (8 fatias) - R$ 49,90&#10;Hamburguer Artesanal - R$ 32,00&#10;..."><?=vc($type_config,'restaurante_cardapio')?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Taxa de Entrega e Bairros</label>
              <textarea name="type_config[restaurante_taxa]" class="form-control" rows="3" placeholder="Ex: Centro - R$ 5,00&#10;Batel - R$ 8,00&#10;Demais bairros - R$ 12,00"><?=vc($type_config,'restaurante_taxa')?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Formas de Pagamento</label>
              <input type="text" name="type_config[restaurante_pagamento]" class="form-control" placeholder="Ex: Pix, Cartao (credito/debito), Dinheiro (precisa de troco?)" value="<?=vc($type_config,'restaurante_pagamento')?>">
            </div>
          </div>

          <div class="dynamic-type-fields" id="kb-fields-faq_inteligente" style="display:none">
            <div class="section-title"><i class="ph-bold ph-question"></i> FAQ e Transbordo</div>
            <div class="section-sub">Palavras-chave de irritacao e topicos principais de duvida.</div>
            <div class="form-group">
              <label class="form-label">Palavras de Irritacao (Transbordo Imediato)</label>
              <input type="text" name="type_config[faq_palavras_irritacao]" class="form-control" placeholder="Ex: absurdo, palhacada, processo, Procon, reclame aqui, quero falar com gerente" value="<?=vc($type_config,'faq_palavras_irritacao')?>">
            </div>
            <div class="form-group">
              <label class="form-label">Principais Assuntos de Duvida</label>
              <textarea name="type_config[faq_topicos_principais]" class="form-control" rows="3" placeholder="Ex: Entrega e prazo, Trocas e devolucoes, Formas de pagamento, Politica de reembolso"><?=vc($type_config,'faq_topicos_principais')?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Horario de Atendimento Humano</label>
              <input type="text" name="type_config[faq_horario_humano]" class="form-control" placeholder="Ex: Seg a Sex, 9h as 18h" value="<?=vc($type_config,'faq_horario_humano')?>">
            </div>
          </div>
          <div class="form-group">
              <label class="form-label">Informações base</label>
              <textarea name="type_config[informacoes_base]" class="form-control" rows="6" placeholder="Escreva aqui detalhes sobre produtos, serviços, políticas..."><?=vc($type_config,'informacoes_base')?></textarea>
          </div>
          <div class="section-title"><i class="ph-bold ph-upload-simple"></i> Adicionar conhecimento</div>
          <div class="section-sub">Envie CSV/PDF/DOCX/TXT para qualquer coisa: produtos, políticas, FAQ, scripts, etc. Selecione uma categoria para organizar.</div>

          <?php $kb_cat = vc($type_config,'kb_upload_category','GERAL'); ?>
          <input type="hidden" name="type_config[kb_upload_category]" id="kb-category" value="<?=htmlspecialchars($kb_cat)?>">
          <div class="option-pill-row" id="kb-cat-row" style="margin-bottom: 12px;">
            <?php foreach(['AUTO','GERAL','FAQ','PRODUTOS','POLITICAS','SCRIPTS'] as $c): ?>
              <label class="option-pill <?=$kb_cat===$c?'selected':''?>">
                <input type="radio" name="kb_cat_radio" value="<?=$c?>" <?=$kb_cat===$c?'checked':''?>>
                <?=$c?>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="dropzone" id="kb-dropzone" style="margin-bottom:14px">
            <div>
              <div class="dz-title">Arraste e solte arquivos aqui</div>
              <div class="dz-sub">ou use os botões. Categoria <strong>AUTO</strong> tenta adivinhar pelo nome do arquivo.</div>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
              <button type="button" class="btn btn-outline" onclick="openTextModal()"><i class="ph-bold ph-text-aa"></i> Texto manual</button>
              <button type="button" class="btn btn-outline" onclick="document.getElementById('kb-any-file').click()"><i class="ph-bold ph-upload-simple"></i> Enviar arquivo</button>
              <input type="file" id="kb-any-file" accept=".csv,.pdf,.docx,.txt" style="display:none" onchange="uploadInlineKnowledgeFrom(this, resolveKbCategoryFromInput(this))">
            </div>
          </div>

          <div style="display:flex;gap:12px;flex-wrap:wrap;margin:-4px 0 12px">
            <button type="button" class="btn btn-outline" onclick="quickUploadCategory('PRODUTOS')"><i class="ph-bold ph-package"></i> CSV de Produtos</button>
            <button type="button" class="btn btn-outline" onclick="quickUploadCategory('FAQ')"><i class="ph-bold ph-question"></i> CSV de FAQ</button>
            <button type="button" class="btn btn-outline" onclick="quickUploadCategory('POLITICAS')"><i class="ph-bold ph-shield-check"></i> Políticas/Termos</button>
          </div>
          <div class="hint-box" id="kb-upload-hint" style="margin-bottom:18px;display:none"></div>

          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;align-items:center">
            <div class="text-muted" style="font-size:.875rem">Dica: suba “catálogo.csv” com categoria <strong>PRODUTOS</strong> para acelerar respostas de preço/variações.</div>
          </div>

          <?php $faq_items_json = $type_config['faq_items_json'] ?? '[]'; ?>
          <input type="hidden" name="type_config[faq_items_json]" id="faq-items-json" value="<?=htmlspecialchars(is_string($faq_items_json)?$faq_items_json:json_encode($faq_items_json, JSON_UNESCAPED_UNICODE))?>">
          <div class="app-card" style="background: rgba(0,0,0,0.01); border-radius: 14px; margin-bottom: 18px;">
            <div class="form-label">FAQ em Pergunta/Resposta (dinâmico)</div>
            <div class="text-muted" style="font-size:.875rem;line-height:1.55;margin-bottom:12px">Opcional. Se preferir, você pode subir um CSV/PDF com FAQ também.</div>
            <div class="repeater-row">
              <div class="grow">
                <label class="form-label" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Pergunta</label>
                <input type="text" id="faq-q" class="form-control" placeholder="Ex: Vocês aceitam PIX?">
              </div>
              <div class="grow">
                <label class="form-label" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Resposta</label>
                <input type="text" id="faq-a" class="form-control" placeholder="Ex: Sim, aceitamos PIX, cartão e débito.">
              </div>
              <div style="align-self:end">
                <button type="button" class="btn btn-outline" onclick="addFaqItem()"><i class="ph-bold ph-plus"></i> Adicionar</button>
              </div>
            </div>
            <div class="repeater-list" id="faq-list"></div>
          </div>

          <label class="form-label">Arquivos e textos treinados</label>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
            <div class="text-muted" style="font-size:.875rem">Filtro:</div>
            <select id="kb-filter" class="form-control" style="width:220px">
              <option value="">Todos</option>
              <option value="GERAL">GERAL</option>
              <option value="FAQ">FAQ</option>
              <option value="PRODUTOS">PRODUTOS</option>
              <option value="POLITICAS">POLITICAS</option>
              <option value="SCRIPTS">SCRIPTS</option>
            </select>
          </div>
          <div id="inline-kb-list" style="border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 16px; background: rgba(0,0,0,0.01); min-height: 100px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 0.875rem;">
              Nenhum arquivo adicionado.
          </div>
        </div>
      </div>

      <!-- MODAL: Texto Manual (FAQ/Políticas/Preços) -->
      <div class="modal-overlay" id="text-modal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-label="Inserir texto manual">
          <div class="modal-header">
            <div class="modal-title"><i class="ph-bold ph-text-aa"></i> Inserir texto manual</div>
            <button type="button" class="btn btn-icon" onclick="closeTextModal()" title="Fechar"><i class="ph-bold ph-x"></i></button>
          </div>
          <div class="modal-body">
            <div class="text-muted" style="font-size:.875rem;margin-bottom:12px">Dica: use títulos e seções. Ex: “FAQ”, “Preços”, “Políticas”.</div>
            <textarea id="manual-text" class="form-control" rows="12" placeholder="Cole aqui o conteúdo..."></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeTextModal()">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="saveInlineText()"><i class="ph-bold ph-floppy-disk"></i> Treinar texto</button>
          </div>
        </div>
      </div>

      <!-- MODAL: Assistente rápido (onboarding) -->
      <div class="modal-overlay" id="quick-setup-modal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-label="Assistente rápido">
          <div class="modal-header">
            <div class="modal-title"><i class="ph-bold ph-magic-wand"></i> Assistente rápido (2 min)</div>
            <button type="button" class="btn btn-icon" onclick="closeQuickSetup()" title="Fechar"><i class="ph-bold ph-x"></i></button>
          </div>
          <div class="modal-body">
            <div class="text-muted" style="font-size:.875rem;margin-bottom:12px">Responda o básico. Vou preencher campos-chave (oferta, público, diferenciais, política e CTA) no formato ideal para conversão.</div>

            <div class="form-group">
              <label class="form-label">O que você vende / resolve? (oferta)</label>
              <input type="text" id="qs-oferta" class="form-control" placeholder="Ex: Implantes dentários, sistema de gestão, consultoria financeira...">
            </div>
            <div class="form-group">
              <label class="form-label">Público-alvo (ICP)</label>
              <input type="text" id="qs-icp" class="form-control" placeholder="Ex: Donos de clínicas, pequenas empresas, mães com crianças...">
            </div>
            <div class="form-group">
              <label class="form-label">Diferenciais / prova</label>
              <input type="text" id="qs-difs" class="form-control" placeholder="Ex: +200 casos, garantia, entrega em 24h, parcelamento...">
            </div>
            <div class="form-group">
              <label class="form-label">Política / restrições (troca, horário, desconto, etc.)</label>
              <input type="text" id="qs-pol" class="form-control" placeholder="Ex: Atendimento Seg-Sex 9h-18h. Não dar desconto sem aprovação.">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Próximo passo (CTA padrão)</label>
              <input type="text" id="qs-cta" class="form-control" placeholder="Ex: Posso te enviar o link? Quer agendar para hoje ou amanhã?">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeQuickSetup()">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="applyQuickSetup()"><i class="ph-bold ph-check"></i> Aplicar</button>
          </div>
        </div>
      </div>

      <!-- TAB 3: LINKS -->
      <div id="tab-3" class="tab-panel">
        <div class="app-card">
          <div class="glass-card-header">
            <h3 class="glass-card-title"><i class="ph-bold ph-link"></i> Links Externos</h3>
            <p class="text-muted" style="margin-top: 8px;">Adicione URLs úteis.</p>
          </div>
          <?php $links_json = $type_config['links_json'] ?? '[]'; ?>
          <input type="hidden" name="type_config[links_json]" id="links-json" value="<?=htmlspecialchars(is_string($links_json)?$links_json:json_encode($links_json, JSON_UNESCAPED_UNICODE))?>">
          <div class="form-group" style="margin-bottom: 24px;">
              <label class="form-label">Adicionar novo link</label>
              <div style="display: flex; gap: 12px; align-items: flex-start;">
                  <div style="flex: 1; display:flex; flex-direction: column; gap: 8px;">
                      <input type="url" id="new-link-url" class="form-control" placeholder="https://exemplo.com">
                      <input type="text" id="new-link-desc" class="form-control" placeholder="Descrição do link">
                  </div>
                  <button type="button" class="btn btn-primary" style="height: 48px;" onclick="addLink()">Adicionar</button>
              </div>
          </div>
          <label class="form-label">Lista de links</label>
          <div id="links-list" style="border: 1px dashed var(--border-subtle); border-radius: var(--radius-md); padding: 16px; color: var(--text-muted); font-size: 0.875rem;">
              <div style="text-align:center;padding:16px">Nenhum link adicionado.</div>
          </div>
        </div>
      </div>

      <!-- TAB 4: FOLLOW-UP -->
      <div id="tab-4" class="tab-panel">
        <div class="app-card">
          <div class="glass-card-header">
            <h3 class="glass-card-title"><i class="ph-bold ph-clock"></i> Follow-Up</h3>
            <p class="text-muted" style="margin-top: 8px;">Automatize mensagens se o cliente não responder.</p>
          </div>
          <div class="Auvvo-toggle-box">
              <div>
                  <strong style="display: block; font-size: 1rem; color: var(--text-primary); margin-bottom: 4px;">Ativar follow-up automático</strong>
                  <span style="font-size: 0.875rem; color: var(--text-muted);">Envia mensagem para reengajar o cliente inativo.</span>
              </div>
              <label class="switch">
                  <input type="checkbox" name="type_config[followup_ativo]" onchange="document.getElementById('fu-config').style.display=this.checked?'block':'none'" <?=vc($type_config,'followup_ativo')?'checked':''?>>
                  <span class="slider"></span>
              </label>
          </div>
          <div id="fu-config" style="display:<?=vc($type_config,'followup_ativo')?'block':'none'?>;">
              <div class="form-group">
                <label class="form-label">Quantidade de follow-ups</label>
                <?php $f_qtd = vc($type_config,'followup_qtd','3'); ?>
                <div class="option-grid cols-3" id="fu-qtd-grid">
                  <?php foreach(['1'=>'1 mensagem','2'=>'2 mensagens','3'=>'3 mensagens'] as $val => $label): ?>
                    <label class="option-card <?=$f_qtd===$val?'selected':''?>">
                      <input type="radio" name="type_config[followup_qtd]" value="<?=$val?>" <?=$f_qtd===$val?'checked':''?>>
                      <div>
                        <div class="option-title"><?=$label?></div>
                        <div class="option-sub">Define quantas tentativas o sistema fará.</div>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="form-group">
                  <label class="form-label">Intervalos de envio</label>
                  <div class="text-muted" style="font-size:.875rem;margin-bottom:12px">Tempo de espera após a última interação do cliente antes de enviar cada follow-up.</div>

                  <div style="display:grid;grid-template-columns:1fr;gap:12px">
                    <div style="display:flex;align-items:center;gap:12px">
                      <div style="width:110px;font-weight:700;color:var(--text-primary)">Follow-up 1</div>
                      <input type="number" name="type_config[fu1_val]" class="form-control" value="<?=vc($type_config,'fu1_val','10')?>" style="width: 140px;">
                      <div class="form-select-wrapper" style="width: 160px;">
                        <select name="type_config[fu1_unidade]" class="form-control">
                            <option value="Minutos" <?=vc($type_config,'fu1_unidade')==='Minutos'?'selected':''?>>Minutos</option>
                            <option value="Horas" <?=vc($type_config,'fu1_unidade')==='Horas'?'selected':''?>>Horas</option>
                            <option value="Dias" <?=vc($type_config,'fu1_unidade')==='Dias'?'selected':''?>>Dias</option>
                        </select>
                        <i class="ph-bold ph-caret-down"></i>
                      </div>
                    </div>

                    <div id="fu-row-2" style="display:<?=intval($f_qtd) >= 2 ? 'flex':'none'?>;align-items:center;gap:12px">
                      <div style="width:110px;font-weight:700;color:var(--text-primary)">Follow-up 2</div>
                      <input type="number" name="type_config[fu2_val]" class="form-control" value="<?=vc($type_config,'fu2_val','4')?>" style="width: 140px;">
                      <div class="form-select-wrapper" style="width: 160px;">
                        <select name="type_config[fu2_unidade]" class="form-control">
                            <option value="Minutos" <?=vc($type_config,'fu2_unidade')==='Minutos'?'selected':''?>>Minutos</option>
                            <option value="Horas" <?=vc($type_config,'fu2_unidade')==='Horas'?'selected':''?>>Horas</option>
                            <option value="Dias" <?=vc($type_config,'fu2_unidade')==='Dias'?'selected':''?>>Dias</option>
                        </select>
                        <i class="ph-bold ph-caret-down"></i>
                      </div>
                    </div>

                    <div id="fu-row-3" style="display:<?=intval($f_qtd) >= 3 ? 'flex':'none'?>;align-items:center;gap:12px">
                      <div style="width:110px;font-weight:700;color:var(--text-primary)">Follow-up 3</div>
                      <input type="number" name="type_config[fu3_val]" class="form-control" value="<?=vc($type_config,'fu3_val','2')?>" style="width: 140px;">
                      <div class="form-select-wrapper" style="width: 160px;">
                        <select name="type_config[fu3_unidade]" class="form-control">
                            <option value="Minutos" <?=vc($type_config,'fu3_unidade')==='Minutos'?'selected':''?>>Minutos</option>
                            <option value="Horas" <?=vc($type_config,'fu3_unidade')==='Horas'?'selected':''?>>Horas</option>
                            <option value="Dias" <?=vc($type_config,'fu3_unidade')==='Dias'?'selected':''?>>Dias</option>
                        </select>
                        <i class="ph-bold ph-caret-down"></i>
                      </div>
                    </div>
                  </div>
              </div>

              <label class="form-label">Prévia do fluxo</label>
              <div id="fu-preview" style="border: 1px solid var(--border-subtle); border-radius: 14px; padding: 16px; background: rgba(0,0,0,0.01);">
                <div style="display:flex;flex-direction:column;gap:10px;font-size:.875rem">
                  <div><strong>Lead não responde</strong></div>
                  <div id="fu-prev-1">Após <strong><?=htmlspecialchars(vc($type_config,'fu1_val','10'))?> <?=htmlspecialchars(vc($type_config,'fu1_unidade','Minutos'))?></strong> → Follow-up 1</div>
                  <div id="fu-prev-2" style="display:<?=intval($f_qtd) >= 2 ? 'block':'none'?>;">Após <strong><?=htmlspecialchars(vc($type_config,'fu2_val','4'))?> <?=htmlspecialchars(vc($type_config,'fu2_unidade','Horas'))?></strong> → Follow-up 2</div>
                  <div id="fu-prev-3" style="display:<?=intval($f_qtd) >= 3 ? 'block':'none'?>;">Após <strong><?=htmlspecialchars(vc($type_config,'fu3_val','2'))?> <?=htmlspecialchars(vc($type_config,'fu3_unidade','Dias'))?></strong> → Follow-up 3</div>
                </div>
              </div>
          </div>
        </div>
      </div>

      <!-- TAB 5: PAUSA -->
      <div id="tab-5" class="tab-panel">
        <div class="app-card">
          <div class="glass-card-header">
            <h3 class="glass-card-title"><i class="ph-bold ph-pause-circle"></i> Pausa da I.A.</h3>
            <p class="text-muted" style="margin-top: 8px;">Gerencie quando o bot deve pausar.</p>
          </div>
          <div class="Auvvo-toggle-box">
              <div>
                  <strong style="display: block; font-size: 1rem; color: var(--text-primary); margin-bottom: 4px;">Ativar pausa automática</strong>
                  <span style="font-size: 0.875rem; color: var(--text-muted);">Pausa o robô se houver intervenção humana.</span>
              </div>
              <label class="switch">
                  <input type="checkbox" name="type_config[intervencao_ativa]" onchange="document.getElementById('pausa-config').style.display=this.checked?'block':'none'" <?=vc($type_config,'intervencao_ativa','1')?'checked':''?>>
                  <span class="slider"></span>
              </label>
          </div>
          <div id="pausa-config" style="display:<?=vc($type_config,'intervencao_ativa','1')?'block':'none'?>;">
              <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Tempo de pausa</label>
                  <div class="form-select-wrapper">
                      <select name="type_config[pausa_tempo]" class="form-control">
                          <?php $p = vc($type_config,'pausa_tempo','30 minutos'); ?>
                          <option value="15 minutos" <?=$p==='15 minutos'?'selected':''?>>15 Minutos</option>
                          <option value="30 minutos" <?=$p==='30 minutos'?'selected':''?>>30 Minutos</option>
                          <option value="1 hora" <?=$p==='1 hora'?'selected':''?>>1 Hora</option>
                          <option value="Permanente" <?=$p==='Permanente'?'selected':''?>>Permanente (Aguardar reativação)</option>
                      </select>
                      <i class="ph-bold ph-caret-down"></i>
                  </div>
              </div>
          </div>
        </div>
      </div>

    </main>
  </div>
</form>

<?php if($edit_agent): ?>
<form method="POST" id="form-delete" style="display:none" onsubmit="return confirm('Remover este agente? Esta ação é irreversível.')">
  <input type="hidden" name="action" value="delete_agent">
  <input type="hidden" name="agent_id" value="<?=$edit_agent['id']?>">
</form>
<?php endif; ?>
</div><!-- view-edit -->

</main>
</div>
<script>
const typePlaceholders = {
<?php foreach($types as $tid => $tinfo): ?>
  '<?=$tid?>': <?=json_encode(AgentTemplates::placeholder($tid))?>,
<?php endforeach; ?>
};

let currentTabIndex = 0;
const MAX_TAB = 5;

function agentNameValid() {
  const nameEl = document.querySelector('input[name="name"]');
  const n = (nameEl?.value || '').trim();
  if (n.length < 2) return false;
  if (n.toLowerCase() === 'rascunho novo agente') return false;
  return true;
}

function validateAgentSave() {
  const nameEl = document.querySelector('input[name="name"]');
  if (!agentNameValid()) {
    nameEl?.focus();
    if (nameEl) nameEl.style.borderColor = '#EF4444';
    setTimeout(() => { if (nameEl) nameEl.style.borderColor = ''; }, 2000);
    showTabError('Informe um nome para o agente (mínimo 2 caracteres).');
    switchTab(0, true);
    return false;
  }
  const tabInput = document.getElementById('redirect-tab-input');
  if (tabInput) tabInput.value = String(currentTabIndex);
  const btn = document.getElementById('btn-save-agent');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph-bold ph-circle-notch ph-spin"></i> Salvando…'; }
  return true;
}

function saveBlueprintPrompt() {
  const name = prompt('Nome do Blueprint (ex: Clínica Odonto - Vendedor):');
  if (!name || !name.trim()) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'agentes';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="${escapeHtml(document.querySelector('input[name=csrf_token]')?.value || '')}">
    <input type="hidden" name="action" value="save_blueprint">
    <input type="hidden" name="agent_id" value="${AGENT_ID}">
    <input type="hidden" name="blueprint_name" value="${escapeHtml(name.trim())}">
  `;
  document.body.appendChild(form);
  form.submit();
}

function createFromBlueprint(id, label) {
  const n = prompt(`Nome do novo agente (a partir de "${label}")`, 'Novo Agente');
  if (!n || !n.trim()) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'agentes';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="${escapeHtml(document.querySelector('input[name=csrf_token]')?.value || '')}">
    <input type="hidden" name="action" value="create_from_blueprint">
    <input type="hidden" name="blueprint_id" value="${id}">
    <input type="hidden" name="new_agent_name" value="${escapeHtml(n.trim())}">
  `;
  document.body.appendChild(form);
  form.submit();
}

function showTabError(msg) {
  let el = document.getElementById('tab-error-banner');
  if (!el) {
    el = document.createElement('div');
    el.id = 'tab-error-banner';
    el.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);background:#FEF2F2;color:#991B1B;border:1px solid #FCA5A5;padding:12px 24px;border-radius:12px;font-size:.9375rem;font-weight:600;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.15);display:flex;align-items:center;gap:10px';
    document.body.appendChild(el);
  }
  el.innerHTML = '<i class="ph-bold ph-warning-circle"></i> ' + msg;
  el.style.opacity = '1';
  setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
}

function switchTab(i, force=false){
  if (i > MAX_TAB) i = MAX_TAB;
  // Validacao ao avançar (nao ao voltar)
  if (!force && i > currentTabIndex) {
    if (currentTabIndex === 0) {
      const nameEl = document.querySelector('input[name="name"]');
      if (!agentNameValid()) {
        nameEl?.focus();
        nameEl?.style && (nameEl.style.borderColor = '#EF4444');
        setTimeout(() => nameEl && (nameEl.style.borderColor = ''), 2000);
        showTabError('Preencha o nome do agente antes de avançar.');
        return;
      }
    }
  }
  currentTabIndex = i;
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('tab-'+i).classList.add('active');
  
  // Wizard Progress Styles
  document.querySelectorAll('.wizard-step').forEach((el, index) => {
    el.classList.remove('active');
    el.classList.remove('completed');
    if (index < i) el.classList.add('completed');
    if (index === i) el.classList.add('active');
  });
  
  window.scrollTo({top:0, behavior:'smooth'});
}

function selectModel(id){
  document.querySelectorAll('.model-card').forEach(c=>c.classList.remove('selected'));
  document.querySelector('.model-card input[value="'+id+'"]').checked=true;
  event.currentTarget.classList.add('selected');
}

function selectType(id){
  // compat: versão atual usa <select>, mas mantemos suporte a cards (se existirem)
  document.querySelectorAll('.type-card').forEach(c=>{
    c.classList.remove('selected');
    c.style.borderColor = 'rgba(0,0,0,.1)';
    c.style.background = 'transparent';
  });
  const el = document.getElementById('type-card-'+id);
  if(!el) return;
  el.classList.add('selected');
  
  // Pick colors from the UI metadata we injected into DOM
  const color = el.querySelector('.type-icon').style.color;
  el.style.borderColor = color;
  el.style.background = color.replace('rgb', 'rgba').replace(')', ', 0.1)');
  
  const ta = document.getElementById('prompt-base-ta');
  if(ta) ta.placeholder = typePlaceholders[id] || '';

  // Show/hide dynamic fields
  showTypeFields(id);
}

function showTypeFields(id) {
  document.querySelectorAll('.dynamic-type-fields').forEach(f => f.style.display = 'none');
  const dField = document.getElementById('dynamic-fields-'+id);
  if(dField) dField.style.display = 'block';

  // Também controla campos dinâmicos na aba Conhecimento
  document.querySelectorAll('[id^="kb-fields-"]').forEach(f => f.style.display = 'none');
  const kb = document.getElementById('kb-fields-'+id);
  if (kb) kb.style.display = 'block';
}

function toggleAudioConfig(show){
  document.getElementById('audio-config').style.display=show?'block':'none';
}

const AGENT_ID = <?=$edit_agent ? $edit_agent['id'] : '0'?>;

// ====== INLINE KNOWLEDGE BASE ======
function loadInlineKnowledge() {
  if (!AGENT_ID) return;
  const list = document.getElementById('inline-kb-list');
  const filterEl = document.getElementById('kb-filter');
  const filter = (filterEl?.value || '').trim().toUpperCase();
  fetch('backend/api.php?action=list_knowledge&agent_id='+AGENT_ID)
    .then(r=>r.json()).then(d => {
      if(d.error) return list.innerHTML = '<span class="text-danger">'+d.message+'</span>';
      if(d.files.length === 0) return list.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;border:1px solid var(--border-subtle);border-radius:var(--radius-md);background:#FAFAFA;padding:32px"><span class="text-muted" style="font-size:.875rem">Nenhum conhecimento treinado ainda.</span></div>';
      
      let html = '<div style="display:flex;flex-direction:column;gap:12px">';
      d.files.forEach(f => {
        let rawName = f.file_type === 'text' ? 'Texto Manual' : (f.original_name || f.file_name);
        let tag = '';
        let name = rawName;
        const m = String(rawName).match(/^\[([^\]]+)\]\s*(.*)$/);
        if (m) { tag = m[1]; name = m[2] || rawName; }
        if (filter && (tag || '').toUpperCase() !== filter) return;
        let icon = f.file_type === 'text' ? 'ph-text-aa' : (f.file_type === 'pdf' ? 'ph-file-pdf' : (f.file_type === 'csv' ? 'ph-file-csv' : 'ph-file-text'));
        let color = f.file_type === 'pdf' ? '#EF4444' : (f.file_type === 'csv' ? '#10B981' : '#3B82F6');
        if (f.file_type === 'text') color = '#8B5CF6';
        
        html += `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:12px;transition:all 0.2s;box-shadow:0 1px 2px rgba(0,0,0,0.02)">
          <div style="display:flex;align-items:center;gap:16px">
            <div style="width:40px;height:40px;border-radius:10px;background:${color}15;color:${color};display:flex;align-items:center;justify-content:center;font-size:1.25rem">
              <i class="ph-fill ${icon}"></i>
            </div>
            <div>
              <div style="font-weight:600;font-size:.9375rem;color:var(--text-primary);margin-bottom:2px;display:flex;align-items:center;gap:8px">
                ${tag ? `<span class="badge badge-gray" style="font-size:.65rem">${escapeHtml(tag)}</span>` : ''}
                <span>${escapeHtml(name)}</span>
              </div>
              <div style="font-size:.75rem;color:var(--text-muted)">Treinado em ${f.date}</div>
            </div>
          </div>
          <button type="button" class="btn btn-icon text-danger" onclick="deleteInlineKnowledge(${f.id})" style="width:36px;height:36px;border-radius:50%;background:#FEF2F2" title="Excluir">
            <i class="ph-bold ph-trash"></i>
          </button>
        </div>`;
      });
      html += '</div>';
      list.innerHTML = html;
    });
}

function uploadInlineKnowledge() {
  const fileInput = document.getElementById('kb-file');
  const file = fileInput.files[0];
  if (!file) return;
  const list = document.getElementById('inline-kb-list');
  list.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;border:1px solid var(--border-subtle);border-radius:var(--radius-md);background:#FAFAFA;padding:32px"><span class="text-muted"><i class="ph-bold ph-spinner ph-spin" style="margin-right:8px;color:var(--accent-teal)"></i> Fazendo upload e extraindo texto...</span></div>';
  
  const fd = new FormData();
  fd.append('action', 'inline_upload_knowledge');
  fd.append('agent_id', AGENT_ID);
  fd.append('knowledge_file', file);
  fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
  
  fetch('backend/api.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d => {
      fileInput.value = '';
      if(d.error) alert('Erro: ' + d.message);
      loadInlineKnowledge();
    }).catch(e=>{ alert('Erro no servidor'); loadInlineKnowledge(); });
}

async function uploadInlineKnowledgeFrom(inputEl, category) {
  const file = inputEl?.files?.[0];
  if (!file) return;
  showKbHint('');

  // CSV: detectar colunas e sugerir categoria
  if ((file.name || '').toLowerCase().endsWith('.csv')) {
    const headers = await readCsvHeaders(file);
    const byHeaders = headers.length ? guessCategoryFromCsvHeaders(headers) : '';
    const byName = inferKbCategoryFromFilename(file.name);
    const suggested = byHeaders || (getKbCategory() === 'AUTO' ? byName : '');
    const cols = headers.length ? headers.map(h => `<span class="badge badge-gray" style="font-size:.65rem">${escapeHtml(h)}</span>`).join(' ') : '';
    showKbHint(`
      <div style="display:flex;flex-direction:column;gap:8px">
        <div><strong>CSV detectado.</strong> ${headers.length ? 'Colunas: ' + cols : 'Não consegui ler as colunas.'}</div>
        ${suggested ? `<div>Sugestão de categoria: <strong>${escapeHtml(suggested)}</strong> ${getKbCategory()==='AUTO' ? '(AUTO)' : ''}</div>` : ''}
      </div>
    `);
    if (getKbCategory() === 'AUTO' && suggested) {
      // Mantém AUTO selecionado, mas envia a categoria sugerida no upload (tag)
      category = suggested;
    }
  }
  const list = document.getElementById('inline-kb-list');
  list.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;border:1px solid var(--border-subtle);border-radius:var(--radius-md);background:#FAFAFA;padding:32px"><span class="text-muted"><i class="ph-bold ph-spinner ph-spin" style="margin-right:8px;color:var(--accent-teal)"></i> Fazendo upload e extraindo texto...</span></div>';

  const fd = new FormData();
  fd.append('action', 'inline_upload_knowledge');
  fd.append('agent_id', AGENT_ID);
  if (category) fd.append('category', category);
  fd.append('knowledge_file', file);
  fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

  fetch('backend/api.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d => {
      inputEl.value = '';
      if(d.error) alert('Erro: ' + d.message);
      loadInlineKnowledge();
    }).catch(() => { alert('Erro no servidor'); loadInlineKnowledge(); });
}

function getKbCategory() {
  const h = document.getElementById('kb-category');
  return (h?.value || 'GERAL').trim();
}

function inferKbCategoryFromFilename(filename) {
  const s = String(filename || '').toLowerCase();
  const has = (...words) => words.some(w => s.includes(w));
  if (has('faq', 'pergunta', 'perguntas', 'duvida', 'dúvida')) return 'FAQ';
  if (has('catalog', 'catálogo', 'catalogo', 'produto', 'produtos', 'preco', 'preços', 'estoque', 'sku')) return 'PRODUTOS';
  if (has('politica', 'política', 'termo', 'regras', 'privacidade', 'garantia', 'troca', 'devol')) return 'POLITICAS';
  if (has('script', 'roteiro', 'atendimento', 'mensagens', 'modelo')) return 'SCRIPTS';
  return 'GERAL';
}

function resolveKbCategoryFromInput(inputEl) {
  const chosen = getKbCategory();
  if (chosen && chosen !== 'AUTO') return chosen;
  const name = inputEl?.files?.[0]?.name || '';
  return inferKbCategoryFromFilename(name);
}

function guessCategoryFromCsvHeaders(headers) {
  const h = headers.map(x => String(x || '').trim().toLowerCase());
  const has = (...words) => words.some(w => h.includes(w) || h.some(col => col.includes(w)));
  // produtos
  if (has('produto', 'produtos', 'nome', 'preco', 'preço', 'valor', 'sku', 'estoque', 'quantidade', 'variante')) return 'PRODUTOS';
  // faq
  if (has('pergunta', 'questao', 'questão', 'duvida', 'dúvida') && has('resposta')) return 'FAQ';
  // politicas
  if (has('politica', 'política', 'termo', 'regra', 'regras')) return 'POLITICAS';
  return '';
}

async function readCsvHeaders(file) {
  try {
    const text = await file.text();
    const firstLine = text.split(/\r?\n/).find(l => l.trim().length) || '';
    // suporte simples a ; ou ,
    const sep = firstLine.includes(';') && !firstLine.includes(',') ? ';' : ',';
    return firstLine.split(sep).map(s => s.replace(/^"|"$/g, '').trim()).filter(Boolean).slice(0, 20);
  } catch {
    return [];
  }
}

function showKbHint(html) {
  const el = document.getElementById('kb-upload-hint');
  if (!el) return;
  if (!html) { el.style.display = 'none'; el.innerHTML = ''; return; }
  el.style.display = 'block';
  el.innerHTML = html;
}

function setKbCategory(cat) {
  const h = document.getElementById('kb-category');
  if (h) h.value = cat;
  document.querySelectorAll('#kb-cat-row .option-pill').forEach(p => p.classList.remove('selected'));
  const inp = document.querySelector(`#kb-cat-row input[type="radio"][value="${cat}"]`);
  inp?.closest('.option-pill')?.classList.add('selected');
  if (inp) inp.checked = true;
}

function quickUploadCategory(cat) {
  setKbCategory(cat);
  const picker = document.getElementById('kb-any-file');
  picker?.click();
}

function openTextModal(tpl) {
  const modal = document.getElementById('text-modal');
  if (!modal) return;
  modal.classList.add('visible');
  modal.setAttribute('aria-hidden', 'false');
  if (tpl) applyTextTemplate(tpl);
  setTimeout(() => document.getElementById('manual-text')?.focus(), 50);
}

function closeTextModal() {
  const modal = document.getElementById('text-modal');
  if (!modal) return;
  modal.classList.remove('visible');
  modal.setAttribute('aria-hidden', 'true');
}

// ====== LINKS (persist in type_config[links_json]) ======
let LINKS = [];

function parseLinksFromHidden() {
  const h = document.getElementById('links-json');
  if (!h) return [];
  try {
    const v = (h.value || '').trim();
    if (!v) return [];
    const parsed = JSON.parse(v);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function syncLinksHidden() {
  const h = document.getElementById('links-json');
  if (!h) return;
  h.value = JSON.stringify(LINKS);
}

function renderLinks() {
  const list = document.getElementById('links-list');
  if (!list) return;
  if (!LINKS.length) {
    list.innerHTML = '<div style="text-align:center;padding:16px">Nenhum link adicionado.</div>';
    return;
  }
  list.innerHTML = `
    <div class="repeater-list">
      ${LINKS.map((l, idx) => `
        <div class="repeater-item">
          <div>
            <strong>${escapeHtml(l.desc || 'Link')}</strong>
            <small>${escapeHtml(l.url || '')}</small>
          </div>
          <button type="button" class="btn btn-icon text-danger" onclick="removeLink(${idx})" title="Remover" style="background:#FEF2F2;border-color:#FCA5A5"><i class="ph-bold ph-trash"></i></button>
        </div>
      `).join('')}
    </div>
  `;
}

function addLink() {
  const urlEl = document.getElementById('new-link-url');
  const descEl = document.getElementById('new-link-desc');
  const url = (urlEl?.value || '').trim();
  const desc = (descEl?.value || '').trim();
  if (!url) return alert('Digite a URL do link.');
  LINKS.push({ url, desc });
  if (urlEl) urlEl.value = '';
  if (descEl) descEl.value = '';
  syncLinksHidden();
  renderLinks();
}

function removeLink(idx) {
  LINKS.splice(idx, 1);
  syncLinksHidden();
  renderLinks();
}

// ====== VENDEDOR: lista de produtos (sync to textarea) ======
let PRODUCTS = [];

function syncProductsTextarea() {
  const ta = document.getElementById('prod-textarea');
  if (!ta) return;
  ta.value = PRODUCTS.map((p) => {
    const price = p.price ? ` — ${p.price}` : '';
    const desc = p.desc ? ` (${p.desc})` : '';
    return `- ${p.name}${price}${desc}`;
  }).join('\n');
}

function renderProducts() {
  const list = document.getElementById('prod-list');
  if (!list) return;
  if (!PRODUCTS.length) {
    list.innerHTML = '<div class="text-muted" style="font-size:.875rem">Nenhum produto adicionado ainda.</div>';
    return;
  }
  list.innerHTML = PRODUCTS.map((p, idx) => `
    <div class="repeater-item">
      <div>
        <strong>${escapeHtml(p.name || 'Produto')}</strong>
        <small>${escapeHtml([p.price, p.desc].filter(Boolean).join(' • '))}</small>
      </div>
      <button type="button" class="btn btn-icon text-danger" onclick="removeProductItem(${idx})" title="Remover" style="background:#FEF2F2;border-color:#FCA5A5"><i class="ph-bold ph-trash"></i></button>
    </div>
  `).join('');
}

function addProductItem() {
  const nameEl = document.getElementById('prod-name');
  const priceEl = document.getElementById('prod-price');
  const descEl = document.getElementById('prod-desc');
  const name = (nameEl?.value || '').trim();
  const price = (priceEl?.value || '').trim();
  const desc = (descEl?.value || '').trim();
  if (!name) return alert('Digite o nome do produto.');
  PRODUCTS.push({ name, price, desc });
  if (nameEl) nameEl.value = '';
  if (priceEl) priceEl.value = '';
  if (descEl) descEl.value = '';
  syncProductsTextarea();
  renderProducts();
}

function removeProductItem(idx) {
  PRODUCTS.splice(idx, 1);
  syncProductsTextarea();
  renderProducts();
}

function parseProductsFromTextarea() {
  const ta = document.getElementById('prod-textarea');
  if (!ta) return [];
  const lines = (ta.value || '').split('\n').map(l => l.trim()).filter(Boolean);
  const items = [];
  for (const line of lines) {
    const clean = line.replace(/^-+\s*/, '').trim();
    if (!clean) continue;
    // Heurística simples: "Nome — Preço (Desc)"
    const m = clean.match(/^(.*?)\s*(?:—\s*(.*?))?\s*(?:\((.*)\))?$/);
    if (m) items.push({ name: (m[1]||'').trim(), price: (m[2]||'').trim(), desc: (m[3]||'').trim() });
    else items.push({ name: clean, price: '', desc: '' });
  }
  return items.filter(x => x.name);
}

// ====== VENDEDOR: objeções e CTAs (persist JSON + sync legacy textareas) ======
let OBJECTIONS = [];
let CTAS = [];

function parseJsonFromHidden(id) {
  const h = document.getElementById(id);
  if (!h) return [];
  try {
    const v = (h.value || '').trim();
    if (!v) return [];
    const parsed = JSON.parse(v);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function syncJsonHidden(id, value) {
  const h = document.getElementById(id);
  if (!h) return;
  h.value = JSON.stringify(value);
}

function syncObjectionsLegacy() {
  const ta = document.getElementById('obj-textarea');
  if (!ta) return;
  ta.value = OBJECTIONS.map(o => `- ${o}`).join('\n');
}

function syncCtasLegacy() {
  const ta = document.getElementById('cta-textarea');
  if (!ta) return;
  ta.value = CTAS.map(c => `- ${c}`).join('\n');
}

function renderObjections() {
  const list = document.getElementById('obj-list');
  if (!list) return;
  if (!OBJECTIONS.length) {
    list.innerHTML = '<div class="text-muted" style="font-size:.875rem">Nenhuma objeção cadastrada.</div>';
    return;
  }
  list.innerHTML = OBJECTIONS.map((o, idx) => `
    <div class="repeater-item">
      <div><strong>${escapeHtml(o)}</strong></div>
      <button type="button" class="btn btn-icon text-danger" onclick="removeObjectionItem(${idx})" title="Remover" style="background:#FEF2F2;border-color:#FCA5A5"><i class="ph-bold ph-trash"></i></button>
    </div>
  `).join('');
}

function renderCtas() {
  const list = document.getElementById('cta-list');
  if (!list) return;
  if (!CTAS.length) {
    list.innerHTML = '<div class="text-muted" style="font-size:.875rem">Nenhum CTA cadastrado.</div>';
    return;
  }
  list.innerHTML = CTAS.map((c, idx) => `
    <div class="repeater-item">
      <div><strong>${escapeHtml(c)}</strong></div>
      <button type="button" class="btn btn-icon text-danger" onclick="removeCtaItem(${idx})" title="Remover" style="background:#FEF2F2;border-color:#FCA5A5"><i class="ph-bold ph-trash"></i></button>
    </div>
  `).join('');
}

function addObjectionItem() {
  const el = document.getElementById('obj-text');
  const v = (el?.value || '').trim();
  if (!v) return alert('Digite uma objeção.');
  OBJECTIONS.push(v);
  if (el) el.value = '';
  syncJsonHidden('obj-json', OBJECTIONS);
  syncObjectionsLegacy();
  renderObjections();
}

function removeObjectionItem(idx) {
  OBJECTIONS.splice(idx, 1);
  syncJsonHidden('obj-json', OBJECTIONS);
  syncObjectionsLegacy();
  renderObjections();
}

function addCtaItem() {
  const el = document.getElementById('cta-text');
  const v = (el?.value || '').trim();
  if (!v) return alert('Digite um CTA.');
  CTAS.push(v);
  if (el) el.value = '';
  syncJsonHidden('cta-json', CTAS);
  syncCtasLegacy();
  renderCtas();
}

function removeCtaItem(idx) {
  CTAS.splice(idx, 1);
  syncJsonHidden('cta-json', CTAS);
  syncCtasLegacy();
  renderCtas();
}

// ====== FAQ items (Q/A) JSON ======
let FAQ_ITEMS = [];

function renderFaqItems() {
  const list = document.getElementById('faq-list');
  if (!list) return;
  if (!FAQ_ITEMS.length) {
    list.innerHTML = '<div class="text-muted" style="font-size:.875rem">Nenhum item de FAQ cadastrado.</div>';
    return;
  }
  list.innerHTML = FAQ_ITEMS.map((it, idx) => `
    <div class="repeater-item">
      <div>
        <strong>${escapeHtml(it.q || '')}</strong>
        <small>${escapeHtml(it.a || '')}</small>
      </div>
      <button type="button" class="btn btn-icon text-danger" onclick="removeFaqItem(${idx})" title="Remover" style="background:#FEF2F2;border-color:#FCA5A5"><i class="ph-bold ph-trash"></i></button>
    </div>
  `).join('');
}

function syncFaqHidden() {
  syncJsonHidden('faq-items-json', FAQ_ITEMS);
}

function addFaqItem() {
  const qEl = document.getElementById('faq-q');
  const aEl = document.getElementById('faq-a');
  const q = (qEl?.value || '').trim();
  const a = (aEl?.value || '').trim();
  if (!q || !a) return alert('Preencha pergunta e resposta.');
  FAQ_ITEMS.push({ q, a });
  if (qEl) qEl.value = '';
  if (aEl) aEl.value = '';
  syncFaqHidden();
  renderFaqItems();
}

function removeFaqItem(idx) {
  FAQ_ITEMS.splice(idx, 1);
  syncFaqHidden();
  renderFaqItems();
}

function saveInlineText() {
  const text = document.getElementById('manual-text').value;
  if (!text.trim()) return alert('Digite algum texto.');
  closeTextModal();
  const list = document.getElementById('inline-kb-list');
  list.innerHTML = '<span class="text-muted"><i class="ph-bold ph-spinner ph-spin"></i> Treinando texto...</span>';
  
  const fd = new FormData(); fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value); fd.append('action', 'inline_save_text'); fd.append('agent_id', AGENT_ID); fd.append('text_content', text);
  fetch('backend/api.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d => {
      document.getElementById('manual-text').value = '';
      if(d.error) alert('Erro: ' + d.message);
      loadInlineKnowledge();
    });
}

function deleteInlineKnowledge(id) {
  if(!confirm('Remover este arquivo/texto da inteligência do agente?')) return;
  const fd = new FormData(); fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value); fd.append('action', 'inline_delete_knowledge'); fd.append('knowledge_id', id); fd.append('agent_id', AGENT_ID);
  fetch('backend/api.php', {method:'POST', body:fd}).then(()=>loadInlineKnowledge());
}

// ====== UX PARA PROMPT E CONHECIMENTO ======
function insertPromptRule(text) {
  const ta = document.getElementById('prompt-base-ta');
  if (ta.value.trim() === '') {
    ta.value = '- ' + text;
  } else {
    ta.value += '\n- ' + text;
  }
  // Feedback visual
  const oldBorder = ta.style.borderColor;
  ta.style.borderColor = 'var(--accent-teal)';
  setTimeout(() => ta.style.borderColor = oldBorder, 500);
  ta.focus();
}

function applyTextTemplate(type) {
  const ta = document.getElementById('manual-text');
  let tpl = '';
  if (type === 'faq') {
    tpl = "=== FAQ DA EMPRESA ===\n\nQ: Qual o prazo de entrega?\nR: A entrega leva cerca de 5 dias úteis.\n\nQ: Como falo com o suporte?\nR: Você pode chamar pelo número 0800 000 000.\n\nQ: [Substitua pela sua Pergunta]\nR: [Substitua pela sua Resposta]\n";
  } else if (type === 'precos') {
    tpl = "=== TABELA DE PREÇOS E SERVIÇOS ===\n\n- Produto A: R$ 00,00 (Descrição breve do item e para quem serve)\n- Produto B: R$ 00,00 (Descrição breve)\n\nCondições de Pagamento:\n- Pix com 5% de desconto.\n- Cartão de crédito em até 12x sem juros.";
  } else if (type === 'politica') {
    tpl = "=== POLÍTICAS E REGRAS ===\n\nTrocas e Devoluções:\n- O cliente tem até 7 dias úteis para solicitar devolução.\n- É necessário que a embalagem esteja intacta.\n\nRegras de Atendimento:\n- Horário de funcionamento: Seg a Sex, das 9h às 18h.\n- Fora desse horário, anote a solicitação e diga que a equipe humana retornará no próximo dia útil.";
  }
  
  if (ta.value.trim() !== '' && !confirm('Isso vai substituir o texto atual da caixa. Tem certeza?')) return;
  ta.value = tpl;
  ta.focus();
}

// Inicializa a UI dependendo do tipo atual
document.addEventListener('DOMContentLoaded', () => {
  const curType = '<?=$a["agent_type"] ?? "vendedor"?>';
  // Se existir UI por cards, mantém; senão, usa o <select>
  selectType(curType);
  showTypeFields(curType);
  const typeSel = document.querySelector('select[name="agent_type"]');
  typeSel?.addEventListener('change', (e) => {
    const val = e.target?.value || 'vendedor';
    showTypeFields(val);
  });
  if (AGENT_ID) loadInlineKnowledge();

  // Option pills: tone (radio pills)
  document.querySelectorAll('#tone-pill-row .option-pill input[type=radio]').forEach((inp) => {
    inp.addEventListener('change', () => {
      document.querySelectorAll('#tone-pill-row .option-pill').forEach(p => p.classList.remove('selected'));
      inp.closest('.option-pill')?.classList.add('selected');
      updateIdentityPreview();
    });
  });

  // Follow-up qty cards
  document.querySelectorAll('#fu-qtd-grid .option-card input[type=radio]').forEach((inp) => {
    inp.addEventListener('change', () => {
      document.querySelectorAll('#fu-qtd-grid .option-card').forEach(c => c.classList.remove('selected'));
      inp.closest('.option-card')?.classList.add('selected');
      applyFollowupQtd(parseInt(inp.value || '3', 10));
    });
  });

  // Follow-up inputs live preview
  ['fu1_val','fu1_unidade','fu2_val','fu2_unidade','fu3_val','fu3_unidade'].forEach((name) => {
    const el = document.querySelector(`[name="type_config[${name}]"]`);
    el?.addEventListener('input', updateFollowupPreview);
    el?.addEventListener('change', updateFollowupPreview);
  });

  // Identity preview live updates
  document.querySelector('input[name="name"]')?.addEventListener('input', updateIdentityPreview);
  document.querySelector('input[name="type_config[organizacao_nome]"]')?.addEventListener('input', updateIdentityPreview);
  typeSel?.addEventListener('change', updateIdentityPreview);

  updateIdentityPreview();
  updateFollowupPreview();

  // FAQ mode cards
  // Knowledge category pills
  document.querySelectorAll('#kb-cat-row .option-pill input[type=radio]').forEach((inp) => {
    inp.addEventListener('change', () => {
      document.querySelectorAll('#kb-cat-row .option-pill').forEach(p => p.classList.remove('selected'));
      inp.closest('.option-pill')?.classList.add('selected');
      const h = document.getElementById('kb-category');
      if (h) h.value = inp.value || 'GERAL';
    });
  });

  // Knowledge filter
  document.getElementById('kb-filter')?.addEventListener('change', loadInlineKnowledge);

  // Dropzone behavior
  const dz = document.getElementById('kb-dropzone');
  dz?.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('dragover'); });
  dz?.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz?.addEventListener('drop', (e) => {
    e.preventDefault();
    dz.classList.remove('dragover');
    const file = e.dataTransfer?.files?.[0];
    if (!file) return;
    const fake = { files: [file], value: '' };
    // reuse upload method
    uploadInlineKnowledgeFrom(fake, resolveKbCategoryFromInput(fake));
  });

  // Init links/products lists from saved data
  LINKS = parseLinksFromHidden();
  renderLinks();
  PRODUCTS = parseProductsFromTextarea();
  renderProducts();
  syncProductsTextarea();

  // Init objections/ctas
  OBJECTIONS = parseJsonFromHidden('obj-json');
  CTAS = parseJsonFromHidden('cta-json');
  syncObjectionsLegacy();
  syncCtasLegacy();
  renderObjections();
  renderCtas();

  // Init FAQ items
  FAQ_ITEMS = parseJsonFromHidden('faq-items-json');
  syncFaqHidden();
  renderFaqItems();

  // Close modal on overlay click / ESC
  document.getElementById('text-modal')?.addEventListener('click', (e) => {
    if (e.target?.id === 'text-modal') closeTextModal();
  });
  document.getElementById('prompt-modal')?.addEventListener('click', (e) => {
    if (e.target?.id === 'prompt-modal') closePromptPreview();
  });
  document.getElementById('quick-setup-modal')?.addEventListener('click', (e) => {
    if (e.target?.id === 'quick-setup-modal') closeQuickSetup();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeTextModal();
    if (e.key === 'Escape') closePromptPreview();
    if (e.key === 'Escape') closeQuickSetup();
  });

  // Restaurar tab via URL param (ex: ?tab=5 após salvar e ir para WhatsApp)
  const urlParams = new URLSearchParams(window.location.search);
  let targetTab = parseInt(urlParams.get('tab') || '0', 10);
  if (targetTab > MAX_TAB) targetTab = MAX_TAB;
  if (targetTab > 0) switchTab(targetTab, true);

  const saveBtn = document.getElementById('btn-save-agent');
  if (saveBtn && saveBtn.disabled) {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> <?= $is_draft ? 'Criar agente' : 'Salvar alterações' ?>';
  }

  // Readiness checklist (sidebar)
  if (AGENT_ID) startReadinessLoop();

  renderFlowSteps();
});

function toggleFlowMode() {
  const mode = document.querySelector('input[name="flow_mode"]:checked')?.value || 'easy';
  document.getElementById('flow-easy').style.display = mode === 'easy' ? 'block' : 'none';
  document.getElementById('flow-advanced').style.display = mode === 'advanced' ? 'block' : 'none';
}

let flowSteps = [];
function loadFlowStepsFromHidden() {
  try {
    flowSteps = JSON.parse(document.getElementById('flow-steps-json')?.value || '[]');
    if (!Array.isArray(flowSteps)) flowSteps = [];
  } catch (e) { flowSteps = []; }
}
function syncFlowStepsHidden() {
  const el = document.getElementById('flow-steps-json');
  if (el) el.value = JSON.stringify(flowSteps);
}
function renderFlowSteps() {
  loadFlowStepsFromHidden();
  const box = document.getElementById('flow-steps-list');
  if (!box) return;
  const agentsOpts = <?= json_encode($flow_agent_opts, JSON_UNESCAPED_UNICODE) ?>;
  box.innerHTML = flowSteps.map((s, i) => {
    const type = s.type || 'instruction';
    const agOpts = agentsOpts.map(a => `<option value="${a.id}" ${String(s.agent_id)===String(a.id)?'selected':''}>${a.name}</option>`).join('');
    return `<div class="app-card" style="padding:12px">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px"><strong>Passo ${i+1}</strong>
        <button type="button" class="btn btn-icon" onclick="removeFlowStep(${i})"><i class="ph-bold ph-trash"></i></button></div>
      <select class="form-control" style="margin-bottom:8px" onchange="flowSteps[${i}].type=this.value;renderFlowSteps()">
        <option value="instruction" ${type==='instruction'?'selected':''}>Instrução</option>
        <option value="handoff_agent" ${type==='handoff_agent'?'selected':''}>Acionar outro agente</option>
        <option value="webhook" ${type==='webhook'?'selected':''}>Chamar webhook</option>
      </select>
      <input class="form-control" placeholder="Rótulo" value="${(s.label||'').replace(/"/g,'&quot;')}" oninput="flowSteps[${i}].label=this.value;syncFlowStepsHidden()">
      <textarea class="form-control" rows="2" style="margin-top:8px" placeholder="Instrução para a IA" oninput="flowSteps[${i}].instruction=this.value;syncFlowStepsHidden()">${s.instruction||''}</textarea>
      ${type==='handoff_agent'?`<select class="form-control" style="margin-top:8px" onchange="flowSteps[${i}].agent_id=parseInt(this.value,10);syncFlowStepsHidden()"><option value="0">Agente</option>${agOpts}</select>`:''}
    </div>`;
  }).join('') || '<p class="text-muted" style="font-size:.8125rem">Nenhum passo. Adicione etapas do fluxo.</p>';
}
function addFlowStep() {
  flowSteps.push({ type: 'instruction', label: 'Novo passo', instruction: '' });
  syncFlowStepsHidden();
  renderFlowSteps();
}
function removeFlowStep(i) {
  flowSteps.splice(i, 1);
  syncFlowStepsHidden();
  renderFlowSteps();
}

// applyFaqMode removed: knowledge hub now uses category + unified upload

function selectedTone() {
  const t = document.querySelector('input[name="type_config[tone]"]:checked');
  return t?.value || '';
}

function updateIdentityPreview() {
  const name = document.querySelector('input[name="name"]')?.value?.trim() || '—';
  const org  = document.querySelector('input[name="type_config[organizacao_nome]"]')?.value?.trim() || '—';
  const type = document.querySelector('select[name="agent_type"]')?.value || 'vendedor';
  const tone = selectedTone() || '—';

  const pvName = document.getElementById('pv-agent');
  const pvOrg  = document.getElementById('pv-org');
  const pvType = document.getElementById('pv-type');
  const pvTone = document.getElementById('pv-tone');
  if (pvName) pvName.textContent = name;
  if (pvOrg) pvOrg.textContent = org;
  if (pvType) pvType.textContent = type;
  if (pvTone) pvTone.textContent = tone;

  // completude simples: nome + org + objetivo + tone
  const objetivo = document.querySelector('textarea[name="type_config[prompt_objetivo]"]')?.value?.trim() || '';
  let score = 0;
  if (name !== '—' && name.length >= 2) score += 25;
  if (org !== '—' && org.length >= 2) score += 20;
  if (tone !== '—') score += 15;
  if (objetivo.length >= 10) score += 40;
  const fill = document.getElementById('pv-fill');
  if (fill) fill.style.width = Math.max(12, Math.min(100, score)) + '%';
}

function applyFollowupQtd(qtd) {
  const row2 = document.getElementById('fu-row-2');
  const row3 = document.getElementById('fu-row-3');
  const p2 = document.getElementById('fu-prev-2');
  const p3 = document.getElementById('fu-prev-3');
  if (row2) row2.style.display = qtd >= 2 ? 'flex' : 'none';
  if (row3) row3.style.display = qtd >= 3 ? 'flex' : 'none';
  if (p2) p2.style.display = qtd >= 2 ? 'block' : 'none';
  if (p3) p3.style.display = qtd >= 3 ? 'block' : 'none';
  updateFollowupPreview();
}

function updateFollowupPreview() {
  const qtd = parseInt((document.querySelector('input[name="type_config[followup_qtd]"]:checked')?.value || '3'), 10);
  const v1 = document.querySelector('[name="type_config[fu1_val]"]')?.value || '10';
  const u1 = document.querySelector('[name="type_config[fu1_unidade]"]')?.value || 'Minutos';
  const v2 = document.querySelector('[name="type_config[fu2_val]"]')?.value || '4';
  const u2 = document.querySelector('[name="type_config[fu2_unidade]"]')?.value || 'Horas';
  const v3 = document.querySelector('[name="type_config[fu3_val]"]')?.value || '2';
  const u3 = document.querySelector('[name="type_config[fu3_unidade]"]')?.value || 'Dias';

  const p1 = document.getElementById('fu-prev-1');
  const p2 = document.getElementById('fu-prev-2');
  const p3 = document.getElementById('fu-prev-3');
  if (p1) p1.innerHTML = `Após <strong>${escapeHtml(v1)} ${escapeHtml(u1)}</strong> → Follow-up 1`;
  if (p2 && qtd >= 2) p2.innerHTML = `Após <strong>${escapeHtml(v2)} ${escapeHtml(u2)}</strong> → Follow-up 2`;
  if (p3 && qtd >= 3) p3.innerHTML = `Após <strong>${escapeHtml(v3)} ${escapeHtml(u3)}</strong> → Follow-up 3`;
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

// ====== ONBOARDING: Assistente rápido ======
function openQuickSetup() {
  const modal = document.getElementById('quick-setup-modal');
  if (!modal) return;
  modal.classList.add('visible');
  modal.setAttribute('aria-hidden', 'false');
  setTimeout(() => document.getElementById('qs-oferta')?.focus(), 50);
}

function closeQuickSetup() {
  const modal = document.getElementById('quick-setup-modal');
  if (!modal) return;
  modal.classList.remove('visible');
  modal.setAttribute('aria-hidden', 'true');
}

function setField(selector, value) {
  const el = document.querySelector(selector);
  if (!el) return;
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
  el.dispatchEvent(new Event('change', { bubbles: true }));
}

function applyQuickSetup() {
  const oferta = (document.getElementById('qs-oferta')?.value || '').trim();
  const icp    = (document.getElementById('qs-icp')?.value || '').trim();
  const difs   = (document.getElementById('qs-difs')?.value || '').trim();
  const pol    = (document.getElementById('qs-pol')?.value || '').trim();
  const cta    = (document.getElementById('qs-cta')?.value || '').trim();

  const type = document.querySelector('select[name="agent_type"]')?.value || 'vendedor';
  if (type === 'vendedor') {
    // Se não houver campos dedicados, injeta no "informacoes_base" (que já existe no builder).
    const kbBaseSel = 'textarea[name="type_config[informacoes_base]"]';
    const kbBase = document.querySelector(kbBaseSel);
    if (kbBase) {
      const lines = [];
      if (oferta) lines.push(`Oferta: ${oferta}`);
      if (icp) lines.push(`Público-alvo (ICP): ${icp}`);
      if (difs) lines.push(`Diferenciais/Prova: ${difs}`);
      if (cta) lines.push(`CTA padrão: ${cta}`);
      if (pol) lines.push(`Políticas/Regras: ${pol}`);
      const block = lines.join('\n');
      if (block) {
        kbBase.value = (kbBase.value || '').trim() ? ((kbBase.value || '').trim() + '\n\n' + block) : block;
        kbBase.dispatchEvent(new Event('input', { bubbles: true }));
        kbBase.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

  const bullets = [];
  if (pol) bullets.push(`- Políticas/Regras: ${pol}`);
  if (cta) bullets.push(`- Sempre finalize com este CTA (se fizer sentido): ${cta}`);
  if (icp) bullets.push(`- Priorize este público-alvo: ${icp}`);
  if (difs) bullets.push(`- Use estes diferenciais como prova: ${difs}`);

  if (bullets.length) {
    const ta = document.getElementById('prompt-base-ta');
    if (ta) {
      const cur = (ta.value || '').trim();
      ta.value = (cur ? (cur + '\n') : '') + bullets.join('\n');
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  closeQuickSetup();
  switchTab(1, true);
}

// ====== Readiness checklist (heurístico, client-side) ======
let __readinessTimer = null;
let __tokenTimer = null;

function computeReadinessSnapshot() {
  const type = document.querySelector('select[name="agent_type"]')?.value || 'vendedor';
  const name = document.querySelector('input[name="name"]')?.value?.trim() || '';
  const org  = document.querySelector('input[name="type_config[organizacao_nome]"]')?.value?.trim() || '';
  const tone = selectedTone() || '';
  const promptBase = document.querySelector('textarea[name="prompt_base"]')?.value?.trim() || '';

  const items = [];
  let score = 0;

  const ok = (label, good) => {
    items.push({ label, good });
    score += good ? 1 : 0;
  };

  ok('Nome do agente', name.length >= 2);
  ok('Organização', org.length >= 2);
  ok('Tom de voz', !!tone);
  ok('Regras extras (prompt)', promptBase.length >= 20);

  if (type === 'vendedor') {
    const prod = document.querySelector('textarea[name="type_config[vendedor_produtos]"]')?.value?.trim() || '';
    const cta  = document.querySelector('textarea[name="type_config[vendedor_cta_padrao]"]')?.value?.trim() || '';
    ok('Produtos/serviços (mín.)', prod.length >= 10);
    ok('CTA definido', cta.length >= 6);
  }

  const maxScore = items.length || 1;
  const pct = Math.round((score / maxScore) * 100);
  return { pct, items };
}

function renderReadiness() {
  const box = document.getElementById('readiness-box');
  if (!box) return;

  const snap = computeReadinessSnapshot();
  const badge = document.getElementById('readiness-score');
  const bar = document.getElementById('readiness-bar');
  const list = document.getElementById('readiness-items');
  if (badge) badge.textContent = snap.pct + '%';
  if (bar) {
    bar.style.width = snap.pct + '%';
    bar.style.background = snap.pct >= 80 ? '#10B981' : (snap.pct >= 50 ? '#F59E0B' : '#EF4444');
  }
  if (list) {
    list.innerHTML = snap.items.map(it => {
      const icon = it.good ? 'ph-bold ph-check-circle' : 'ph-bold ph-warning-circle';
      const color = it.good ? 'var(--text-success)' : '#F59E0B';
      return `<div style="display:flex;gap:8px;align-items:center"><i class="${icon}" style="color:${color}"></i><span>${escapeHtml(it.label)}</span></div>`;
    }).join('');
  }
}

function refreshTokenHint() {
  const hint = document.getElementById('token-hint');
  if (!hint || !AGENT_ID) return;
  fetch('backend/api.php?action=get_master_prompt&agent_id=' + AGENT_ID)
    .then(r => r.json())
    .then(d => {
      if (d?.error) return;
      const t = parseInt(d.tokens || '0', 10) || 0;
      hint.style.display = 'block';
      const pct = Math.min(Math.round((t / 128000) * 100), 100);
      const level = t > 60000 ? 'alto' : (t > 20000 ? 'médio' : 'ok');
      hint.innerHTML = `Tokens estimados: <strong>${escapeHtml(String(t))}</strong> (${pct}% do contexto). Nível: <strong>${level}</strong>.`;
      if (level === 'alto') hint.style.color = '#EF4444';
      else if (level === 'médio') hint.style.color = '#F59E0B';
      else hint.style.color = 'var(--text-muted)';
    })
    .catch(()=>{});
}

function startReadinessLoop() {
  const box = document.getElementById('readiness-box');
  if (box) box.style.display = 'block';
  renderReadiness();
  refreshTokenHint();
  if (__readinessTimer) clearInterval(__readinessTimer);
  __readinessTimer = setInterval(renderReadiness, 1200);
  if (__tokenTimer) clearInterval(__tokenTimer);
  __tokenTimer = setInterval(refreshTokenHint, 15000);
}

// ====== PROMPT PREVIEW (master prompt) ======
let LAST_PROMPT_TEXT = '';

function openPromptPreview() {
  const modal = document.getElementById('prompt-modal');
  if (!modal) return;
  if (!AGENT_ID) return alert('Salve o agente primeiro para gerar o prompt final.');
  modal.classList.add('visible');
  modal.setAttribute('aria-hidden', 'false');
  const preview = document.getElementById('prompt-preview');
  const meta = document.getElementById('prompt-meta');
  if (preview) preview.textContent = 'Carregando...';
  if (meta) { meta.style.display = 'none'; meta.innerHTML = ''; }

  fetch('backend/api.php?action=get_master_prompt&agent_id=' + AGENT_ID)
    .then(r => r.json())
    .then(d => {
      if (d.error) throw new Error(d.message || 'Erro ao gerar prompt.');
      LAST_PROMPT_TEXT = d.prompt || '';
      if (preview) preview.textContent = LAST_PROMPT_TEXT || '(vazio)';
      if (meta) {
        meta.style.display = 'flex';
        meta.innerHTML = `
          <span class="terminal-badge">Agente: ${escapeHtml(d.agent || '')}</span>
          <span class="terminal-badge">Tokens (estimado): ${escapeHtml(d.tokens || '')}</span>
        `;
      }
      // Alerta de tokens (evita prompts gigantes por acidente)
      try {
        const t = parseInt(d.tokens || '0', 10) || 0;
        if (t > 60000) alert('Atenção: prompt muito grande (tokens altos). Considere reduzir/organizar o conhecimento.');
      } catch {}
    })
    .catch((e) => {
      if (preview) preview.textContent = 'Erro: ' + (e?.message || 'falha');
    });
}

function closePromptPreview() {
  const modal = document.getElementById('prompt-modal');
  if (!modal) return;
  modal.classList.remove('visible');
  modal.setAttribute('aria-hidden', 'true');
}

async function copyPromptPreview() {
  if (!LAST_PROMPT_TEXT) return alert('Nada para copiar.');
  try {
    await navigator.clipboard.writeText(LAST_PROMPT_TEXT);
    alert('Prompt copiado.');
  } catch {
    alert('Não foi possível copiar automaticamente. Selecione e copie manualmente.');
  }
}


// ====== GERAÇÃO DE PROMPT COM IA (DeepSeek com fallback local) ======
async function generateWithAI() {
  const btn = document.getElementById('btn-ai-generate');
  const loading = document.getElementById('ai-generate-loading');
  const ta = document.getElementById('prompt-base-ta');

  const form = document.querySelector('#view-edit form');
  const fd = new FormData();
  fd.append('action', 'gemini_generate_prompt');
  fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

  fd.append('agent_name',      document.querySelector('input[name="name"]')?.value || '');
  fd.append('agent_type',      document.querySelector('select[name="agent_type"]')?.value || 'vendedor');
  fd.append('organizacao',     document.querySelector('input[name="type_config[organizacao_nome]"]')?.value || '');
  fd.append('tone',            selectedTone() || '');
  fd.append('objetivo_agente', document.querySelector('textarea[name="type_config[prompt_objetivo]"]')?.value || '');
  fd.append('extra_rules',     ta?.value || '');

  fd.append('prompt_identidade', document.querySelector('textarea[name="type_config[prompt_identidade]"]')?.value || '');
  fd.append('prompt_objetivo',   document.querySelector('textarea[name="type_config[prompt_objetivo]"]')?.value || '');
  fd.append('prompt_restricoes', document.querySelector('textarea[name="type_config[prompt_restricoes]"]')?.value || '');

  fd.append('products',     document.querySelector('textarea[name="type_config[vendedor_produtos]"]')?.value || '');
  fd.append('payments',     document.querySelector('textarea[name="type_config[vendedor_pagamento]"]')?.value || '');
  fd.append('diferenciais', document.querySelector('textarea[name="type_config[vendedor_diferenciais]"]')?.value || '');
  fd.append('horario',      document.querySelector('input[name="type_config[atendente_horario]"]')?.value || '');
  fd.append('politica',     document.querySelector('textarea[name="type_config[atendente_politica]"]')?.value || '');
  fd.append('sistemas',     document.querySelector('input[name="type_config[suporte_sistemas]"]')?.value || '');
  fd.append('triagem',      document.querySelector('textarea[name="type_config[suporte_triagem]"]')?.value || '');
  fd.append('escalada',     document.querySelector('textarea[name="type_config[suporte_escalada]"]')?.value || '');
  fd.append('setores',      document.querySelector('textarea[name="type_config[Auvvo_setores]"]')?.value || '');

  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-circle-notch ph-spin"></i> Gerando...';
  loading.style.display = 'inline';

  try {
    const resp = await fetch('backend/api.php', { method: 'POST', body: fd });
    const data = await resp.json();

    if (data.error) {
      alert('Erro: ' + (data.message || 'Falha ao gerar prompt.'));
    } else {
      if (ta) {
        ta.value = data.prompt;
        ta.style.borderColor = data.source === 'deepseek' ? '#6366F1' : '#10B981';
        ta.style.boxShadow = data.source === 'deepseek' ? '0 0 0 3px rgba(99,102,241,.25)' : '0 0 0 3px rgba(16,185,129,.2)';
        setTimeout(() => { ta.style.borderColor = ''; ta.style.boxShadow = ''; }, 3000);
        ta.scrollIntoView({ behavior: 'smooth', block: 'center' });

        let banner = document.getElementById('ai-success-banner');
        if (!banner) {
          banner = document.createElement('div');
          banner.id = 'ai-success-banner';
          banner.style.cssText = 'margin-top:16px;padding:14px 20px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:12px;display:flex;align-items:center;gap:10px;color:#15803D;font-weight:600;font-size:.9375rem';
          ta.parentElement.insertAdjacentElement('afterend', banner);
        }
        const sourceLabel = data.source === 'deepseek' ? 'DeepSeek' : 'local';
        banner.innerHTML = '<i class="ph-bold ph-check-circle" style="font-size:1.25rem"></i> Prompt gerado via ' + sourceLabel + '! Revise o texto e clique em Salvar.';
        banner.style.display = 'flex';
        setTimeout(() => { if(banner) banner.style.display = 'none'; }, 8000);
      }
    }
  } catch (e) {
    alert('Erro de conexao ao gerar prompt. Tente novamente.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-sparkle"></i> Gerar com IA';
    loading.style.display = 'none';
  }
}

// Se vier de ?edit=new, mostrar view-edit
<?php if(isset($_GET['edit'])&&$_GET['edit']==='new'): ?>
document.getElementById('view-list').classList.add('view-hidden');
document.getElementById('view-edit').classList.remove('view-hidden');
<?php endif; ?>
</script>
<?php include __DIR__ . '/includes/toast.php'; ?>
</body></html>
