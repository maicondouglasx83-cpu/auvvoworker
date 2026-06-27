<?php

require_once 'includes/auth.php';

require_once 'backend/db.php';
require_once __DIR__ . '/backend/migrations.php';

auvvo_run_migrations($pdo);

require_once __DIR__ . '/backend/whatsapp_connections.inc.php';
$whatsapp_connections = auvvo_whatsapp_connections_list($pdo, (int) $_SESSION['user_id']);

$stmt = $pdo->prepare('SELECT id, name, status FROM agents WHERE user_id = ? AND status != ? ORDER BY name');
$stmt->execute([$_SESSION['user_id'], 'draft']);
$agents = $stmt->fetchAll();



require_once __DIR__ . '/backend/CrmPipelines.php';
$pipeSvc = new CrmPipelines($pdo);
$user_id = (int) $_SESSION['user_id'];
$defaultPid = $pipeSvc->defaultPipelineId($user_id);
$stagesMap = $pipeSvc->stagesMap($user_id, $defaultPid);
$stages = [];
foreach ($stagesMap as $slug => $meta) {
    $stages[$slug] = $meta['label'];
}
if ($stages === []) {
$stages = [

    'new' => 'Novo Lead',

    'contacted' => 'Em Contato',

    'qualified' => 'Qualificado',

    'proposal' => 'Proposta',

    'closed' => 'Fechado',

    'lost' => 'Perdido',

];
}

$stmtPipes = $pdo->prepare(
    'SELECT id, name, is_default FROM crm_pipelines WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
);
$stmtPipes->execute([$user_id]);
$pipelinesFull = $stmtPipes->fetchAll(PDO::FETCH_ASSOC);
$stagesByPipeline = $pipeSvc->stagesByPipelineMap($user_id);
$stagesOrderedByPipeline = [];
foreach ($stagesByPipeline as $pid => $map) {
    $stagesOrderedByPipeline[$pid] = array_keys($map);
}

?>

<!DOCTYPE html>

<html lang="<?= lang_html() ?>">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Automações – Auvvo</title>

<link rel="stylesheet" href="app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.css">
<link rel="stylesheet" href="assets/automacoes-flow.css?v=20260528">
<link rel="stylesheet" href="assets/automacoes-lab.css?v=20260524d">

<script src="https://unpkg.com/@phosphor-icons/web"></script>
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js"></script>

<link rel="icon" type="image/png" href="icone.png">

<style>

.auto-grid{display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start}

@media(max-width:960px){.auto-grid{grid-template-columns:1fr}}

.auto-list{display:flex;flex-direction:column;gap:10px}

.auto-card{padding:16px;border:1px solid var(--border-subtle);border-radius:12px;background:#fff}

.auto-card strong{display:block;margin-bottom:6px}

.auto-meta{font-size:.8rem;color:var(--text-muted);line-height:1.5}

.pipe-stages{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}

.pipe-pill{padding:6px 12px;border-radius:20px;font-size:.75rem;background:var(--surface-secondary)}
.bpm-flow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.bpm-block{padding:8px 12px;border-radius:10px;font-size:.75rem;font-weight:600;border:1px solid var(--border-subtle)}
.bpm-trigger{background:#EEF2FF;color:#4338CA;border-color:#C7D2FE}
.bpm-cond{background:#FFFBEB;color:#B45309;border-color:#FDE68A}
.bpm-action{background:#ECFDF5;color:#047857;border-color:#A7F3D0}
.bpm-arrow{color:var(--text-muted);font-size:.875rem}
.auto-step{padding:12px;border:1px dashed var(--border-subtle);border-radius:10px;margin-bottom:10px;background:#FAFAFA}
.mode-tabs{display:flex;gap:0;margin-bottom:20px;border:1px solid var(--border-subtle);border-radius:10px;overflow:hidden}
.mode-tab{flex:1;padding:12px 16px;border:none;background:#fff;cursor:pointer;font-size:.875rem;font-weight:600;color:var(--text-muted)}
.mode-tab.active{background:var(--accent-teal);color:#fff}
.basic-box{padding:16px;background:#F0FDFA;border:1px solid #99F6E4;border-radius:12px;margin-bottom:16px;font-size:.8125rem}
.queue-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.queue-stat{padding:12px 16px;border-radius:10px;background:#fff;border:1px solid var(--border-subtle);font-size:.8125rem}
.queue-stat strong{display:block;font-size:1.25rem;color:var(--text-primary)}
.auto-wa-banner{display:flex;align-items:center;gap:16px;padding:16px 20px;margin-bottom:20px;background:linear-gradient(135deg,#ecfdf5,#fff);border:1px solid #99f6e4;border-radius:14px;flex-wrap:wrap}
.auto-wa-banner i{font-size:2rem;color:#25D366}
.auto-wa-banner p{margin:4px 0 0;font-size:.8125rem;color:var(--text-muted)}
.auto-wa-banner .btn{margin-left:auto}

</style>

</head>

<body>

<div class="app-container">

<?php include 'includes/sidebar.php'; ?>

<main class="app-main">

  <div class="page-header">

    <div>

      <h1 class="page-title">Automações</h1>
      <p class="page-hint">Monte jornadas: <strong>quando começa</strong> (gatilho) → <strong>Atendimento IA</strong> (recomendado). Boas-vindas fixas são opcionais. Cérebro em <a href="agentes">Agentes</a> · linhas em <a href="conexoes">Conexões</a>.</p>
    </div>
    <div class="page-header-actions">
      <a href="conexoes" class="btn btn-secondary"><i class="ph-bold ph-whatsapp-logo"></i> Conexões</a>
      <a href="integracoes" class="btn btn-outline"><i class="ph-bold ph-plugs-connected"></i> Integrações</a>
    </div>
  </div>

  <?php if (empty($whatsapp_connections)): ?>
  <div class="auto-wa-banner">
    <i class="ph-bold ph-whatsapp-logo"></i>
    <div>
      <strong>Nenhuma linha WhatsApp conectada</strong>
      <p>Crie uma conexão nomeada antes de usar gatilhos de mensagem.</p>
    </div>
    <a href="conexoes" class="btn btn-primary">Conectar WhatsApp</a>
  </div>
  <?php endif; ?>

  <div id="queue-stats" class="queue-stats"></div>
  <div id="dedupe-warn-global"></div>

  <nav class="auto-nav" aria-label="Modos de automação">
    <div class="auto-nav-tabs">
      <button type="button" class="auto-nav-tab active" id="tab-visual" onclick="setAutomacoesTab('visual')"><i class="ph-bold ph-tree-structure"></i> Editor</button>
      <button type="button" class="auto-nav-tab" id="tab-test" onclick="setAutomacoesTab('test')"><i class="ph-bold ph-chat-circle-dots"></i> Testar</button>
      <button type="button" class="auto-nav-tab" id="tab-runs" onclick="setAutomacoesTab('runs')"><i class="ph-bold ph-list-checks"></i> Execuções</button>
    </div>
    <button type="button" class="auto-nav-legacy" id="tab-quick" onclick="setAutomacoesTab('quick')" title="Modo legado — prefira o editor visual">Regras legado</button>
  </nav>

  <div id="panel-visual">
    <div class="flow-app">
      <aside class="flow-sidebar">
        <div class="flow-sidebar-head">
          <h3>Seus fluxos</h3>
          <button type="button" class="btn btn-primary" style="width:100%;font-size:.8125rem;margin-bottom:6px" id="btn-new-flow"><i class="ph-bold ph-plus"></i> Nova jornada</button>
          <div class="flow-sidebar-subbtns">
            <button type="button" class="btn btn-outline" style="font-size:.75rem" id="btn-new-flow-blank">Só gatilho</button>
            <button type="button" class="btn btn-outline" style="font-size:.75rem" id="btn-flow-journey">Escolher jornada</button>
          </div>
          <button type="button" class="btn btn-secondary" style="width:100%;font-size:.8125rem" id="btn-pack-templates"><i class="ph-bold ph-package"></i> Pacote completo (agentes + fluxos)</button>
          <span class="btn-template-hint">Pacotes criam vários agentes para testar handoff, memória e sessão.</span>
        </div>
        <div id="flow-dedupe-warn" style="padding:0 12px 8px"></div>
        <div id="flow-list" class="flow-list"></div>
        <div class="flow-blocks-panel" id="flow-blocks-panel">
          <div class="flow-blocks-panel__head">
            <h4><i class="ph-bold ph-squares-four"></i> Blocos</h4>
            <span class="text-muted">Clique para adicionar ao canvas</span>
          </div>
          <div class="flow-block-group">
            <span class="flow-block-group__label">Essencial</span>
            <button type="button" class="flow-block-btn flow-block-btn--trigger" data-add-node="flow_trigger" title="Quando o fluxo começa"><i class="ph-bold ph-play-circle"></i><span>Gatilho</span></button>
            <button type="button" class="flow-block-btn flow-block-btn--converse" data-add-node="flow_converse" data-add-preset='{"label":"Atendimento IA"}'><i class="ph-bold ph-chats-circle"></i><span>Atendimento IA</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_message" data-add-preset='{"label":"Boas-vindas","message":"Olá {{nome}}! Como posso ajudar?"}'><i class="ph-bold ph-whatsapp-logo"></i><span>Boas-vindas</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_condition"><i class="ph-bold ph-funnel"></i><span>Filtro</span></button>
          </div>
          <div class="flow-block-group">
            <span class="flow-block-group__label">IA (modos)</span>
            <button type="button" class="flow-block-btn flow-block-btn--think" data-add-node="flow_think"><i class="ph-bold ph-lightbulb"></i><span>IA por turno</span></button>
            <button type="button" class="flow-block-btn flow-block-btn--agent" data-add-node="flow_agent"><i class="ph-bold ph-robot"></i><span>Agente IA (1×)</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_memory"><i class="ph-bold ph-brain"></i><span>Memória IA</span></button>
          </div>
          <div class="flow-block-group">
            <span class="flow-block-group__label">CRM &amp; funil</span>
            <button type="button" class="flow-block-btn" data-add-node="flow_action" data-add-preset='{"action_type":"move_stage","stage":"new","pipeline_id":0,"label":"Mover estágio"}'><i class="ph-bold ph-columns"></i><span>Mover estágio</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_action" data-add-preset='{"action_type":"add_tag","tag":"novo-lead","label":"Tag CRM"}'><i class="ph-bold ph-tag"></i><span>Tag CRM</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_action" data-add-preset='{"action_type":"assign_agent","label":"Atribuir agente"}'><i class="ph-bold ph-user-switch"></i><span>Atribuir agente</span></button>
          </div>
          <div class="flow-block-group">
            <span class="flow-block-group__label">Fluxo &amp; integrações</span>
            <button type="button" class="flow-block-btn" data-add-node="flow_wait_reply"><i class="ph-bold ph-chat-teardrop-dots"></i><span>Aguardar resposta</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_delay"><i class="ph-bold ph-clock"></i><span>Esperar tempo</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_action" data-add-preset='{"action_type":"http_preset","label":"Integração HTTP"}'><i class="ph-bold ph-plugs-connected"></i><span>Integração</span></button>
            <button type="button" class="flow-block-btn" data-add-node="flow_randomizer"><i class="ph-bold ph-shuffle"></i><span>Random A/B</span></button>
            <button type="button" class="flow-block-btn flow-block-btn--ghost" data-add-node="flow_action"><i class="ph-bold ph-lightning"></i><span>Ação avançada</span></button>
          </div>
        </div>
      </aside>
      <section class="flow-center">
        <header class="flow-toolbar">
          <input type="text" id="flow-name" class="form-control flow-name-input" value="Nova automação" placeholder="Nome do fluxo">
          <select id="flow-pipeline" class="form-control flow-pipeline-select" title="Funil deste fluxo — gatilhos de estágio usam colunas deste pipeline"></select>
          <label class="flow-toggle" title="Use o botão Publicar para ativar"><input type="checkbox" id="flow-active" disabled> Publicado</label>
          <p id="flow-publish-hint" class="flow-publish-hint">Rascunho — salve e publique quando estiver pronto</p>
          <div class="flow-toolbar-actions">
            <button type="button" class="btn btn-outline" id="btn-test-before-publish" style="font-size:.8125rem" title="Abrir simulador"><i class="ph-bold ph-chat-circle-dots"></i></button>
            <button type="button" class="btn btn-outline" id="btn-playground-toggle" style="font-size:.8125rem" title="Playground"><i class="ph-bold ph-sidebar-simple"></i></button>
            <div class="flow-zoom">
              <button type="button" id="zoom-out" title="Diminuir">−</button>
              <button type="button" id="zoom-reset" title="Reset">◎</button>
              <button type="button" id="zoom-in" title="Aumentar">+</button>
            </div>
            <button type="button" class="btn btn-outline" id="btn-flow-delete" style="font-size:.8125rem">Excluir</button>
            <button type="button" class="btn btn-outline" id="btn-flow-save" style="font-size:.8125rem"><span id="btn-flow-saved">Salvar rascunho</span></button>
            <button type="button" class="btn btn-primary" id="btn-flow-publish" style="font-size:.8125rem"><i class="ph-bold ph-rocket-launch"></i> Publicar</button>
          </div>
        </header>
        <div id="flow-meta-bar" class="flow-meta-bar">
          <div class="flow-meta-bar__jornada">
            <span class="flow-steps-guide__label"><i class="ph-bold ph-signpost"></i> Jornada</span>
            <div id="flow-steps-guide-chips" class="flow-steps-guide__chips"></div>
          </div>
          <div class="flow-meta-bar__routing">
            <span class="flow-meta-bar__routing-label"><i class="ph-bold ph-path"></i> Roteamento</span>
            <div id="flow-routing-summary" class="flow-routing-summary"></div>
          </div>
        </div>
        <div class="drawflow-wrap">
          <div id="drawflow"></div>
          <div class="flow-palette-wrap">
            <div class="flow-palette-popover" id="flow-palette-extra" hidden>
              <p class="flow-palette-popover__title">Avançado</p>
              <button type="button" class="flow-palette-btn" data-add-node="flow_trigger"><i class="ph-bold ph-play-circle"></i> Gatilho extra</button>
              <button type="button" class="flow-palette-btn flow-palette-btn--agent" data-add-node="flow_agent"><i class="ph-bold ph-robot"></i> Agente IA (1 resposta)</button>
              <button type="button" class="flow-palette-btn flow-palette-btn--think" data-add-node="flow_think"><i class="ph-bold ph-lightbulb"></i> IA por turno (script)</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_wait_reply"><i class="ph-bold ph-chat-teardrop-dots"></i> Aguardar resposta</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"move_stage","stage":"new","pipeline_id":0,"label":"Mover estágio CRM"}'><i class="ph-bold ph-columns"></i> Mover estágio</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"add_tag","tag":"novo-lead","label":"Adicionar tag"}'><i class="ph-bold ph-tag"></i> Tag CRM</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_delay"><i class="ph-bold ph-clock"></i> Esperar tempo</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"http_preset","label":"HTTP integração"}'><i class="ph-bold ph-plugs-connected"></i> Integração</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_randomizer"><i class="ph-bold ph-shuffle"></i> Random A/B</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_memory"><i class="ph-bold ph-brain"></i> Memória IA</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action"><i class="ph-bold ph-lightning"></i> Ação avançada</button>
            </div>
            <div class="flow-palette-popover" id="flow-palette-crm" hidden>
              <p class="flow-palette-popover__title">Ações CRM</p>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"add_tag","tag":"novo-lead","label":"Adicionar tag"}'><i class="ph-bold ph-tag"></i> Adicionar tag</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"move_stage","stage":"contacted","pipeline_id":0,"label":"Mover estágio"}'><i class="ph-bold ph-columns"></i> Mover estágio</button>
              <button type="button" class="flow-palette-btn" data-add-node="flow_action" data-add-preset='{"action_type":"assign_agent","label":"Atribuir agente"}'><i class="ph-bold ph-user-switch"></i> Atribuir agente</button>
            </div>
            <div class="flow-palette-popover" id="flow-palette-ia" hidden>
              <p class="flow-palette-popover__title">Atendimento IA</p>
              <button type="button" class="flow-palette-btn flow-palette-btn--converse" data-add-node="flow_converse" data-add-preset='{"label":"Atendimento IA contínuo"}'><i class="ph-bold ph-chats-circle"></i> Contínuo <span class="flow-palette-badge">recomendado</span></button>
              <p class="flow-palette-popover__hint">Responde cada nova mensagem do lead com histórico.</p>
              <button type="button" class="flow-palette-btn flow-palette-btn--think" data-add-node="flow_think"><i class="ph-bold ph-lightbulb"></i> Por turno (script)</button>
              <p class="flow-palette-popover__hint">IA envia N mensagens e segue o fluxo — use antes de «Aguardar resposta».</p>
            </div>
            <div class="flow-palette flow-palette--compact">
              <span class="flow-palette-group-label">Atalho</span>
              <button type="button" class="flow-palette-btn flow-palette-btn--converse" id="btn-palette-ia" aria-expanded="false"><i class="ph-bold ph-robot"></i> IA</button>
              <button type="button" class="flow-palette-btn" id="btn-palette-crm" aria-expanded="false"><i class="ph-bold ph-tag"></i> CRM</button>
              <button type="button" class="flow-palette-btn flow-palette-btn--more" id="btn-palette-more" title="Mais blocos — use também a barra lateral" aria-expanded="false"><i class="ph-bold ph-dots-three"></i></button>
            </div>
          </div>
        </div>
        <aside class="flow-playground" id="flow-playground">
          <header><h4>Playground</h4><p class="text-muted">Teste rápido sem trocar de aba</p></header>
          <div id="pg-chat-messages" class="sim-chat-messages" style="min-height:180px;max-height:240px"></div>
          <div class="sim-chat-input">
            <textarea id="pg-message" class="form-control" rows="2" placeholder="Mensagem de teste…"></textarea>
            <button type="button" class="btn btn-primary" id="pg-send">Enviar</button>
          </div>
          <label style="font-size:.7rem;margin-top:6px;display:block"><input type="checkbox" id="pg-use-llm"> IA real</label>
        </aside>
      </section>
      <aside class="flow-props" id="flow-props">
        <div class="flow-props-head">
          <h3>Propriedades</h3>
          <button type="button" class="btn btn-outline" id="btn-props-mobile" style="display:none;font-size:.75rem;margin-top:8px">Fechar</button>
        </div>
        <div id="flow-props-body" class="flow-props-body">
          <p class="flow-props-empty">Clique em um bloco do canvas ou adicione pela barra lateral. Jornada ideal: <strong>Gatilho</strong> → <strong>Atendimento IA</strong>.</p>
        </div>
      </aside>
    </div>
    <p class="text-muted" style="font-size:.7rem;margin-top:10px">Atrasos e fila: processe com <strong>auvvo-worker</strong> (<code>npm start</code>).</p>
  </div>

  <div id="pack-success-banner" class="pack-success-banner" hidden></div>

  <div id="pack-template-modal" class="flow-modal" aria-hidden="true">
    <div class="flow-modal-backdrop"></div>
    <div class="flow-modal-panel flow-modal-panel--wide" role="dialog" aria-labelledby="pack-tpl-title">
      <header class="flow-modal-header">
        <div>
          <h2 id="pack-tpl-title">Pacotes completos</h2>
          <p>Escolha um segmento. Você confirma antes de criar — ideal para testar o motor com vários agentes WhatsApp.</p>
        </div>
        <button type="button" class="btn btn-outline" id="pack-template-close" aria-label="Fechar">Fechar</button>
      </header>
      <div class="flow-modal-body" id="pack-template-grid"></div>
    </div>
  </div>

  <div id="pack-confirm-modal" class="flow-modal" aria-hidden="true">
    <div class="flow-modal-backdrop"></div>
    <div class="flow-modal-panel pack-confirm-panel" role="dialog" aria-labelledby="pack-confirm-title">
      <header class="flow-modal-header">
        <div>
          <h2 id="pack-confirm-title">Confirmar pacote</h2>
          <p id="pack-confirm-sub">Revise o que será criado na sua conta.</p>
        </div>
      </header>
      <div class="flow-modal-body pack-confirm-body-wrap">
        <div id="pack-confirm-body"></div>
        <div id="pack-confirm-agents" class="pack-confirm-list"></div>
        <p id="pack-confirm-error" class="pack-confirm-error" role="alert"></p>
      </div>
      <footer class="pack-confirm-footer">
        <button type="button" class="btn btn-outline" id="pack-confirm-back">Voltar</button>
        <button type="button" class="btn btn-outline" id="pack-confirm-cancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="pack-confirm-apply">Sim, criar agentes e fluxos</button>
      </footer>
    </div>
  </div>

  <div id="flow-template-modal" class="flow-modal" aria-hidden="true">
    <div class="flow-modal-backdrop"></div>
    <div class="flow-modal-panel" role="dialog" aria-labelledby="flow-tpl-title">
      <header class="flow-modal-header">
        <div>
          <h2 id="flow-tpl-title">Fluxos avulsos</h2>
          <p>Modelos só de automação (sem criar agentes). Para equipe completa, use <strong>Pacote completo</strong> na barra lateral.</p>
        </div>
        <button type="button" class="btn btn-outline" id="flow-template-close" aria-label="Fechar">Fechar</button>
      </header>
      <div class="flow-modal-body" id="flow-template-grid"></div>
    </div>
  </div>

  <div id="panel-quick-rules" style="display:none">
  <div class="basic-box" style="margin-bottom:16px">
    <strong>Modo legado</strong>
    <p style="margin:6px 0 0;color:var(--text-muted)">Use o <strong>Editor visual</strong> para fluxos com Agente IA, simulador e log de execuções. Estas regras simples continuam funcionando, mas não recebem novos recursos.</p>
  </div>
  <div class="auto-grid">

    <div>

      <h3 style="font-size:1rem;margin-bottom:12px">Regras ativas</h3>

      <div id="auto-list" class="auto-list"><p class="text-muted">Carregando…</p></div>

    </div>



    <div class="app-card">

      <h3 style="font-size:1rem;margin-bottom:12px">Nova automação</h3>

      <div class="mode-tabs">
        <button type="button" class="mode-tab active" id="tab-mode-basic" onclick="setAutomationMode('basic')">Fluxo básico</button>
        <button type="button" class="mode-tab" id="tab-mode-advanced" onclick="setAutomationMode('advanced')">Fluxo avançado</button>
      </div>

      <div class="form-group" style="margin-bottom:16px;padding:12px;background:var(--surface-secondary);border-radius:10px">
        <label class="form-label"><i class="ph-bold ph-funnel"></i> Pipeline (funil)</label>
        <select id="auto-pipeline" class="form-control" onchange="onAutomationPipelineChange()"></select>
        <p class="text-muted" style="font-size:.7rem;margin:6px 0 0">Regras de estágio só disparam para leads neste funil. <a href="configuracoes#crm-pipelines">Editar pipelines</a></p>
      </div>

      <div id="panel-basic">
        <div class="basic-box">
          <strong>Conexão + cérebro</strong>
          <p style="margin:8px 0 0;color:var(--text-muted)"><strong>Conexão</strong> = qual linha WhatsApp recebe. <strong>Agente</strong> = qual cérebro responde. Vários agentes podem usar a mesma conexão.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Quando</label>
          <select id="b-preset" class="form-control" onchange="toggleBasicPreset()">
            <option value="new_whatsapp">Primeiro contato no WhatsApp</option>
            <option value="stage">Lead entra em um estágio do funil</option>
            <option value="tag">Tag adicionada ao lead</option>
            <option value="tag_brain">Tag → missão para o cérebro IA</option>
            <option value="tag_brain_journey">Tag → missão → limpar após X horas</option>
            <option value="webhook">Lead chega por integração (webhook)</option>
          </select>
        </div>
        <div id="b-extra-stage" class="form-group" style="display:none">
          <label class="form-label">Estágio</label>
          <select id="b-stage" class="form-control"></select>
        </div>
        <div id="b-extra-tag" class="form-group" style="display:none">
          <label class="form-label">Nome da tag</label>
          <input type="text" id="b-tag" class="form-control" placeholder="ex: agendar-consulta">
        </div>
        <div id="b-extra-brain" class="form-group" style="display:none;padding:12px;background:#f5f3ff;border-radius:10px;border:1px solid #ddd6fe">
          <label class="form-label"><i class="ph-bold ph-brain"></i> Missão para o cérebro</label>
          <textarea id="b-brain-mission" class="form-control" rows="3" placeholder="Ex.: Confirmar horário, agendar no Google Calendar, tag consulta-agendada."></textarea>
          <p class="text-muted" style="font-size:.7rem;margin:8px 0 0">A IA executa na próxima resposta do lead (Calendar, CRM, webhooks). Use tags de conclusão ou limpeza automática.</p>
          <div id="b-extra-clear-delay" class="form-group" style="margin-top:12px;margin-bottom:0">
            <label class="form-label">Limpar missão após (horas)</label>
            <input type="number" id="b-clear-hours" class="form-control" value="24" min="1" max="720">
          </div>
        </div>
        <div id="b-extra-webhook" class="form-group" style="display:none">
          <label class="form-label">Integração (webhook)</label>
          <select id="b-webhook" class="form-control"><option value="">Carregando…</option></select>
        </div>
        <div class="form-group" id="b-row-connection">
          <label class="form-label">Conexão WhatsApp (linha)</label>
          <select id="b-connection" class="form-control">
            <option value="">— Selecione —</option>
            <?php foreach ($whatsapp_connections as $wc):
              $st = (string) ($wc['status'] ?? '');
              $conn = $st === 'online' ? ' ✓ conectado' : ($st === 'waiting_qr' ? ' ○ aguardando QR' : ' ○ desconectado');
            ?>
            <option value="<?= (int) $wc['id'] ?>"><?= htmlspecialchars($wc['name']) ?><?= $conn ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-muted" style="font-size:.7rem;margin-top:6px">Crie conexões na aba <strong>Conexões WhatsApp</strong>.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Agente (cérebro IA)</label>
          <select id="b-agent" class="form-control">
            <option value="">— Selecione —</option>
            <?php foreach ($agents as $ag): ?>
            <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-muted" style="font-size:.7rem;margin-top:6px">Configure personalidade e prompt em <a href="agentes">Agentes</a>.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Mensagem WhatsApp (opcional)</label>
          <textarea id="b-message" class="form-control" rows="3" placeholder="Olá {{nome}}, obrigado pelo contato!"></textarea>
          <span class="text-muted" style="font-size:.7rem">Deixe vazio para só atribuir o agente ao lead (a IA responde sozinha nas conversas).</span>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;margin-bottom:12px">
          <input type="checkbox" id="b-assign-only"> Só atribuir agente (sem enviar mensagem automática)
        </label>
        <button type="button" class="btn btn-primary" style="width:100%" onclick="saveBasicAutomation()">Salvar automação básica</button>
      </div>

      <div id="panel-advanced" style="display:none">
      <p class="text-muted" style="font-size:.8125rem;margin-bottom:16px">Gatilho → condição (opcional) → ação. Para especialistas.</p>

      <div class="bpm-flow" aria-hidden="true">
        <span class="bpm-block bpm-trigger">Gatilho</span>
        <span class="bpm-arrow">→</span>
        <span class="bpm-block bpm-cond">Condição</span>
        <span class="bpm-arrow">→</span>
        <span class="bpm-block bpm-action">Ação</span>
      </div>

      <div class="form-group">
        <label class="form-label">Gatilho</label>
        <select id="a-trigger-type" class="form-control">
          <option value="whatsapp_first">Primeira mensagem WhatsApp</option>
          <option value="whatsapp_message">Qualquer mensagem WhatsApp</option>
          <option value="stage_enter">Lead entra no estágio</option>
          <option value="tag_added">Tag adicionada</option>
          <option value="contact_created">Lead criado (novo)</option>
          <option value="webhook_received">Lead recebido por webhook</option>
          <option value="ltv_inactive">LTV — sumiu do ciclo de compra</option>
        </select>
      </div>

      <div id="a-trigger-ltv" style="display:none;padding:12px;background:#FFFBEB;border-radius:10px;margin-bottom:12px;font-size:.8125rem">
        <strong>Ciclo de compra</strong>
        <p class="text-muted" style="margin:8px 0">Compras vêm de webhooks (Hotmart etc.) com «Registrar como compra» ou status approved/paid. O sistema calcula o intervalo médio entre compras.</p>
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label">Ciclo esperado (dias) — se ainda não houver histórico</label>
          <input type="number" id="a-ltv-cycle" class="form-control" value="30" min="1">
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label">Disparar após (dias sem comprar)</label>
          <input type="number" id="a-ltv-inactive" class="form-control" value="0" min="0" placeholder="0 = usar 2× o ciclo médio">
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label">Mínimo de compras no histórico</label>
          <input type="number" id="a-ltv-min-purchases" class="form-control" value="2" min="1">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Multiplicador do ciclo (ex: 2 = o dobro do intervalo)</label>
          <input type="number" id="a-ltv-miss-factor" class="form-control" value="2" min="1" step="0.5">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Valor do gatilho</label>
        <select id="a-trigger-connection" class="form-control">
          <option value="*">Qualquer conexão</option>
          <?php foreach ($whatsapp_connections as $wc): ?>
          <option value="<?= (int) $wc['id'] ?>"><?= htmlspecialchars($wc['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="a-trigger-stage" class="form-control" style="display:none;margin-top:8px"></select>
        <input type="text" id="a-trigger-tag" class="form-control" style="display:none;margin-top:8px" placeholder="nome-da-tag">
        <select id="a-trigger-webhook" class="form-control" style="display:none;margin-top:8px">
          <option value="">Carregando webhooks…</option>
        </select>
        <select id="a-trigger-source" class="form-control" style="display:none;margin-top:8px">
          <option value="*">Qualquer origem</option>
          <option value="whatsapp">WhatsApp (primeira mensagem)</option>
          <option value="webhook">Webhook / integração</option>
          <option value="manual">Criado manualmente no CRM</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Condições (opcional)</label>
        <input type="text" id="a-require-tag" class="form-control" placeholder="Exigir tag (vazio = sempre)" style="margin-bottom:8px">
        <input type="text" id="a-exclude-tag" class="form-control" placeholder="Ignorar se tiver tag…" style="margin-bottom:8px">
        <div style="display:flex;align-items:center;gap:8px">
          <label class="form-label" style="margin:0;white-space:nowrap">Teste A/B (%)</label>
          <input type="number" id="a-ab-chance" class="form-control" min="1" max="100" value="100" style="max-width:80px">
          <span class="text-muted" style="font-size:.75rem">100 = sempre executa</span>
        </div>
      </div>

      <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;margin-bottom:16px">
        <input type="checkbox" id="a-use-sequence" onchange="toggleSequenceMode()"> Sequência com várias ações e atrasos
      </label>

      <div id="a-sequence-box" style="display:none;margin-bottom:16px">
        <p class="text-muted" style="font-size:.75rem;margin-bottom:8px">Cada passo executa após o atraso acumulado (minutos).</p>
        <div id="a-steps-list"></div>
        <button type="button" class="btn btn-outline" style="width:100%;font-size:.8rem" onclick="addAutomationStep()">+ Adicionar passo</button>
      </div>

      <div id="a-single-action-box">
      <div class="form-group">
        <label class="form-label">Ação</label>
        <select id="a-action-type" class="form-control" onchange="toggleActionFields()">
          <option value="send_whatsapp">Enviar WhatsApp</option>
          <option value="invoke_agent">Acionar outro agente</option>
          <option value="assign_agent">Atribuir agente ao lead</option>
          <option value="move_stage">Mover para outro estágio</option>
          <option value="call_webhook">Chamar webhook outbound</option>
          <option value="add_tag">Adicionar tag</option>
          <option value="pause_ai">Pausar IA</option>
          <option value="set_memory">Gravar memória IA</option>
          <option value="brain_mission">Missão para o cérebro IA</option>
          <option value="clear_brain_mission">Limpar missão IA</option>
          <option value="google_sheets_append">Registrar no Google Sheets</option>
          <option value="http_preset">HTTP preset (URL salva)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Aguardar antes da ação (minutos)</label>
        <input type="number" id="a-delay-min" class="form-control" value="0" min="0" max="43200">
      </div>

      <div id="a-action-assign-agent" style="display:none">
        <div class="form-group">
          <label class="form-label">Agente responsável</label>
          <select id="a-assign-agent" class="form-control">
            <?php foreach ($agents as $ag): ?>
            <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="a-action-move-stage" style="display:none">
        <div class="form-group">
          <label class="form-label">Estágio destino</label>
          <select id="a-move-stage" class="form-control"></select>
        </div>
      </div>

      <div id="a-action-whatsapp">

        <div class="form-group">
          <label class="form-label">Conexão (linha WhatsApp)</label>
          <select id="a-connection" class="form-control">
            <option value="">— Selecione —</option>
            <?php foreach ($whatsapp_connections as $wc): ?>
            <option value="<?= (int) $wc['id'] ?>"><?= htmlspecialchars($wc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Agente (cérebro)</label>
          <select id="a-agent" class="form-control">
            <?php foreach ($agents as $ag): ?>
            <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Mensagem</label>
          <textarea id="a-message" class="form-control" rows="4" placeholder="Olá {{nome}}, …"></textarea>
        </div>

      </div>

      <div id="a-action-invoke" style="display:none">

        <div class="form-group">

          <label class="form-label">Agente destino</label>

          <select id="a-invoke-agent" class="form-control">

            <?php foreach ($agents as $ag): ?>

            <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>

            <?php endforeach; ?>

          </select>

        </div>

        <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;margin-bottom:12px">

          <input type="checkbox" id="a-switch-agent" checked> Trocar agente do contato no CRM

        </label>

        <div class="form-group">

          <label class="form-label">Mensagem de abertura (opcional)</label>

          <textarea id="a-invoke-msg" class="form-control" rows="2"></textarea>

        </div>

      </div>

      <div id="a-action-webhook" style="display:none">

        <div class="form-group">

          <label class="form-label">Webhook outbound (ID)</label>

          <select id="a-webhook-id" class="form-control"><option value="">Carregando…</option></select>

        </div>

      </div>

      <div id="a-action-tag" style="display:none">

        <div class="form-group">

          <label class="form-label">Tag</label>

          <input type="text" id="a-tag" class="form-control" placeholder="ex: hotmart">

        </div>

      </div>

      <div id="a-action-pause" style="display:none">

        <div class="form-group">

          <label class="form-label">Pausar IA (minutos)</label>

          <input type="number" id="a-pause-min" class="form-control" value="60" min="15">

        </div>

      </div>

      <div id="a-action-sheets" style="display:none">
        <p class="text-muted" style="font-size:.8125rem">Usa a planilha configurada em <a href="integracoes?panel=sheets">Integrações → Google Sheets</a>.</p>
      </div>
      <div id="a-action-http-preset" style="display:none">
        <div class="form-group">
          <label class="form-label">Preset HTTP</label>
          <select id="a-http-preset" class="form-control"><option value="">Carregando…</option></select>
        </div>
      </div>
      <div id="a-action-memory" style="display:none">

        <div class="form-group">

          <label class="form-label">Chave</label>

          <input type="text" id="a-mem-key" class="form-control" placeholder="origem">

        </div>

        <div class="form-group">

          <label class="form-label">Valor</label>

          <input type="text" id="a-mem-val" class="form-control" placeholder="hotmart">

        </div>

      </div>

      <div id="a-action-brain-mission" style="display:none">
        <div class="form-group">
          <label class="form-label">Missão para o cérebro</label>
          <textarea id="a-brain-mission" class="form-control" rows="4" placeholder="Ex.: Confirmar horário, agendar no Calendar, tag consulta-agendada."></textarea>
          <p class="text-muted" style="font-size:.75rem;margin-top:6px">A próxima resposta IA do agente segue esta missão. Limpe com «Limpar missão» ou tags de conclusão.</p>
        </div>
      </div>

      <div id="a-action-clear-mission" style="display:none">
        <p class="text-muted" style="font-size:.8125rem">Remove a missão ativa (_brain_mission) do contato após concluir o fluxo.</p>
      </div>
      </div>

      <button type="button" class="btn btn-primary" style="width:100%" onclick="saveAutomation()">Salvar regra avançada</button>
      <p class="text-muted" style="font-size:.7rem;margin-top:10px;text-align:center">Atrasos e LTV: <strong>auvvo-worker</strong> (<code>npm start</code>).</p>
      </div>

    </div>

  </div>



  <div class="app-card" style="margin-top:32px">

    <h3 style="font-size:1rem;margin-bottom:8px">Seus pipelines</h3>
    <p class="text-muted" style="font-size:.875rem;margin-bottom:16px">Cada funil tem estágios próprios no Kanban. O editor visual usa o pipeline selecionado acima nas regras.</p>
    <div id="pipeline-list"></div>
    <a href="configuracoes#crm-pipelines" class="btn btn-outline" style="margin-top:12px;font-size:.8125rem"><i class="ph-bold ph-sliders-horizontal"></i> Gerenciar pipelines</a>

  </div>
  </div><!-- panel-quick-rules -->

  <div id="flow-publish-modal" class="flow-modal" hidden aria-hidden="true">
    <div class="flow-modal-backdrop"></div>
    <div class="flow-modal-panel" role="dialog">
      <header class="flow-modal-header">
        <div><h2>Publicar fluxo</h2><p>Revise antes de ativar no WhatsApp real.</p></div>
      </header>
      <div class="flow-modal-body" id="flow-publish-checklist"></div>
      <footer class="pack-confirm-footer">
        <button type="button" class="btn btn-outline" id="flow-publish-cancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="flow-publish-confirm">Publicar agora</button>
      </footer>
    </div>
  </div>

  <div id="flow-wizard-modal" class="flow-modal" hidden aria-hidden="true">
    <div class="flow-modal-backdrop"></div>
    <div class="flow-modal-panel flow-modal-panel--wide" role="dialog">
      <header class="flow-modal-header">
        <div><h2>Como começar?</h2><p>Escolha uma jornada pronta — você edita textos e agente depois. Teste na aba <strong>Testar</strong> antes de publicar.</p></div>
        <button type="button" class="btn btn-outline" id="wizard-skip" aria-label="Fechar">Fechar</button>
      </header>
      <div class="flow-modal-body">
        <div id="flow-journey-grid" class="flow-journey-grid"></div>
        <p class="flow-journey-foot text-muted">Mais modelos (lembrete, recuperação de vendas…) em <button type="button" class="flow-tpl-hint-link" id="wizard-use-template">Fluxos avulsos</button>.</p>
      </div>
    </div>
  </div>

  <div id="panel-test" style="display:none">
    <p class="page-hint" style="margin-bottom:16px">Simule mensagens sem enviar WhatsApp nem alterar o CRM. Funciona com fluxos <strong>salvos</strong> ou com o canvas atual (marque «Usar editor»).</p>
    <div class="sim-layout">
      <aside class="sim-panel">
        <h3>Cenário</h3>
        <div class="sim-field">
          <label>Fluxo</label>
          <select id="sim-flow-id" class="form-control"></select>
        </div>
        <div class="sim-field">
          <label><input type="checkbox" id="sim-use-editor" checked> Usar fluxo do editor (mesmo não salvo)</label>
        </div>
        <div class="sim-field">
          <label><input type="checkbox" id="sim-use-llm"> Usar IA real (preview LLM — consome créditos)</label>
          <p class="text-muted" style="font-size:.7rem;margin:4px 0 0">Sem marcar, o nó Agente IA só simula. Com marcar, gera resposta real sem enviar WhatsApp.</p>
        </div>
        <div class="sim-field">
          <label>Gatilho</label>
          <select id="sim-trigger-type" class="form-control">
            <option value="whatsapp_first">Primeira mensagem WhatsApp</option>
            <option value="whatsapp_message">Mensagem WhatsApp</option>
            <option value="stage_enter">Entrou no estágio</option>
            <option value="tag_added">Tag adicionada</option>
            <option value="contact_created">Lead criado</option>
            <option value="webhook_received">Webhook / integração</option>
            <option value="ltv_inactive">LTV — inativo</option>
          </select>
          <p id="sim-trigger-hint" class="text-muted" style="font-size:.7rem;margin:6px 0 0"></p>
          <button type="button" class="btn btn-outline" id="sim-sync-trigger" style="font-size:.75rem;margin-top:6px;width:100%">Sincronizar gatilho do editor</button>
        </div>
        <div class="sim-field" id="sim-wrap-connection">
          <label>Conexão</label>
          <select id="sim-connection" class="form-control"></select>
        </div>
        <div class="sim-field" id="sim-wrap-tag" style="display:none">
          <label>Tag</label>
          <input type="text" id="sim-trigger-tag" class="form-control" placeholder="nome-da-tag">
        </div>
        <div class="sim-field" id="sim-wrap-stage" style="display:none">
          <label>Estágio</label>
          <select id="sim-trigger-stage" class="form-control">
            <?php foreach ($stages as $slug => $label): ?>
            <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sim-field" id="sim-wrap-webhook" style="display:none">
          <label>Webhook slug</label>
          <input type="text" id="sim-trigger-webhook" class="form-control" value="default" placeholder="slug do webhook">
        </div>
        <div class="sim-field">
          <label>Lead teste — nome</label>
          <input type="text" id="sim-lead-name" class="form-control" value="Maria Silva">
        </div>
        <div class="sim-field">
          <label>Telefone</label>
          <input type="text" id="sim-lead-phone" class="form-control" value="11999998888">
        </div>
      </aside>
      <section class="sim-panel sim-chat">
        <h3>Chat de teste</h3>
        <div id="sim-paused-banner" class="sim-paused-banner" hidden>
          <strong>Conversa em andamento</strong> — envie mais mensagens como o lead para continuar o atendimento.
          <button type="button" class="btn btn-outline" id="sim-reset-session" style="font-size:.7rem;margin-left:8px">Nova sessão</button>
        </div>
        <div id="sim-worker-warn" class="sim-worker-warn" hidden></div>
        <div id="sim-chat-messages" class="sim-chat-messages"></div>
        <div class="sim-chat-input">
          <textarea id="sim-message" class="form-control" rows="2" placeholder="Digite como se fosse o lead no WhatsApp…"></textarea>
          <button type="button" class="btn btn-primary" id="sim-send">Enviar</button>
        </div>
        <button type="button" class="btn btn-outline" id="sim-reset-chat" style="margin-top:8px;font-size:.8125rem">Limpar chat</button>
      </section>
      <aside class="sim-panel">
        <h3>Passos do fluxo</h3>
        <div id="sim-steps" class="sim-steps"></div>
      </aside>
    </div>
  </div>

  <div id="panel-runs" style="display:none">
    <div class="runs-toolbar">
      <select id="runs-filter-flow" class="form-control"></select>
      <select id="runs-filter-mode" class="form-control">
        <option value="live" selected>Só produção</option>
        <option value="simulate">Só testes</option>
        <option value="all">Todos</option>
      </select>
      <button type="button" class="btn btn-outline" id="runs-refresh"><i class="ph-bold ph-arrows-clockwise"></i> Atualizar</button>
    </div>
    <div class="runs-layout">
      <div id="runs-list" class="runs-list"></div>
      <div id="runs-detail" class="runs-detail"><p class="text-muted">Selecione uma execução.</p></div>
    </div>
  </div>

</main>

</div>

<script>

const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

const API = 'backend/api.php';

const PIPELINES_META = <?= json_encode($pipelinesFull, JSON_UNESCAPED_UNICODE) ?>;
const STAGES_BY_PIPELINE = <?= json_encode($stagesByPipeline, JSON_UNESCAPED_UNICODE) ?>;
const WA_CONNECTION_NAMES = <?= json_encode(array_column(array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name']], $whatsapp_connections), 'name', 'id'), JSON_UNESCAPED_UNICODE) ?>;
const DEFAULT_PIPELINE_ID = <?= (int) $defaultPid ?>;
let AUTOMATION_PIPELINE_ID = DEFAULT_PIPELINE_ID;

let automationSteps = [];

function currentStagesMap() {
  return STAGES_BY_PIPELINE[AUTOMATION_PIPELINE_ID] || STAGES_BY_PIPELINE[DEFAULT_PIPELINE_ID] || {};
}

function stageLabel(slug) {
  const m = currentStagesMap();
  return m[slug] || slug;
}

function fillAutomationStageSelects() {
  const map = currentStagesMap();
  const opts = Object.keys(map).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(map[k])}</option>`).join('');
  ['b-stage', 'a-trigger-stage', 'a-move-stage'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = opts;
  });
}

function initAutomationPipelineSelect() {
  const sel = document.getElementById('auto-pipeline');
  if (!sel) return;
  sel.innerHTML = (PIPELINES_META || []).map(p =>
    `<option value="${p.id}">${escapeHtml(p.name)}${parseInt(p.is_default, 10) === 1 ? ' ★' : ''}</option>`
  ).join('');
  sel.value = String(AUTOMATION_PIPELINE_ID || DEFAULT_PIPELINE_ID);
  fillAutomationStageSelects();
  if (window.FLOW_BOOT) {
    window.FLOW_BOOT.automationPipelineId = AUTOMATION_PIPELINE_ID;
    window.FLOW_BOOT.stages = currentStagesMap();
  }
}

function onAutomationPipelineChange() {
  const sel = document.getElementById('auto-pipeline');
  AUTOMATION_PIPELINE_ID = parseInt(sel?.value || String(DEFAULT_PIPELINE_ID), 10) || DEFAULT_PIPELINE_ID;
  fillAutomationStageSelects();
  if (window.FLOW_BOOT) {
    window.FLOW_BOOT.automationPipelineId = AUTOMATION_PIPELINE_ID;
    window.FLOW_BOOT.stages = currentStagesMap();
  }
  const flowSel = document.getElementById('flow-pipeline');
  if (flowSel) flowSel.value = String(AUTOMATION_PIPELINE_ID);
  if (typeof window.onFlowPipelineChange === 'function') {
    window.onFlowPipelineChange();
  } else if (typeof loadFlowList === 'function') {
    loadFlowList();
  }
}

window.fillAutomationStageSelects = fillAutomationStageSelects;

function setAutomationMode(mode) {
  document.getElementById('panel-basic').style.display = mode === 'basic' ? 'block' : 'none';
  document.getElementById('panel-advanced').style.display = mode === 'advanced' ? 'block' : 'none';
  document.getElementById('tab-mode-basic').classList.toggle('active', mode === 'basic');
  document.getElementById('tab-mode-advanced').classList.toggle('active', mode === 'advanced');
}

function toggleBasicPreset() {
  const p = document.getElementById('b-preset')?.value || '';
  const isBrain = p === 'tag_brain' || p === 'tag_brain_journey';
  document.getElementById('b-extra-stage').style.display = p === 'stage' ? 'block' : 'none';
  document.getElementById('b-extra-tag').style.display = (p === 'tag' || isBrain) ? 'block' : 'none';
  document.getElementById('b-extra-webhook').style.display = p === 'webhook' ? 'block' : 'none';
  document.getElementById('b-extra-brain').style.display = isBrain ? 'block' : 'none';
  document.getElementById('b-extra-clear-delay').style.display = p === 'tag_brain_journey' ? 'block' : 'none';
  const connRow = document.getElementById('b-row-connection');
  if (connRow) connRow.style.display = p === 'new_whatsapp' ? 'block' : 'none';
  const assignRow = document.getElementById('b-assign-only')?.closest('label');
  if (assignRow) assignRow.style.display = isBrain ? 'none' : 'flex';
  if (isBrain && !(document.getElementById('b-tag')?.value || '').trim()) {
    const tagEl = document.getElementById('b-tag');
    if (tagEl) tagEl.value = 'agendar-consulta';
  }
}

function appendAutomationPipeline(fd) {
  fd.append('pipeline_id', String(AUTOMATION_PIPELINE_ID || DEFAULT_PIPELINE_ID));
}

async function saveBasicAutomation() {
  const preset = document.getElementById('b-preset')?.value || 'new_whatsapp';
  const connectionId = parseInt(document.getElementById('b-connection')?.value || '0', 10);
  const agentId = parseInt(document.getElementById('b-agent')?.value || '0', 10);
  if (preset === 'new_whatsapp' && !connectionId) return alert('Selecione a conexão WhatsApp (linha).');
  if (!agentId) return alert('Selecione o agente (cérebro).');
  let triggerType = 'whatsapp_first';
  let triggerValue = preset === 'new_whatsapp' ? String(connectionId) : String(agentId);
  if (preset === 'stage') {
    triggerType = 'stage_enter';
    triggerValue = document.getElementById('b-stage')?.value || 'new';
  } else if (preset === 'tag' || preset === 'tag_brain' || preset === 'tag_brain_journey') {
    triggerType = 'tag_added';
    triggerValue = (document.getElementById('b-tag')?.value || '').trim();
    if (!triggerValue) return alert('Informe o nome da tag.');
  } else if (preset === 'webhook') {
    triggerType = 'webhook_received';
    triggerValue = document.getElementById('b-webhook')?.value || '';
    if (!triggerValue) return alert('Selecione a integração webhook.');
  }
  const assignOnly = document.getElementById('b-assign-only')?.checked;
  const msg = (document.getElementById('b-message')?.value || '').trim();
  let actionType = assignOnly ? 'assign_agent' : 'send_whatsapp';
  let cfg = { agent_id: agentId };
  if (connectionId > 0) cfg.connection_id = connectionId;
  if (preset === 'tag_brain' || preset === 'tag_brain_journey') {
    const mission = (document.getElementById('b-brain-mission')?.value || '').trim();
    if (!mission) return alert('Descreva a missão para o cérebro IA.');
    if (preset === 'tag_brain') {
      actionType = 'brain_mission';
      cfg = { mission };
      if (msg) {
        cfg.steps = [
          { delay_minutes: 0, action_type: 'brain_mission', mission },
          { delay_minutes: 0, action_type: 'send_whatsapp', agent_id: agentId, connection_id: connectionId, message: msg },
        ];
      }
    } else {
      const clearMins = (parseInt(document.getElementById('b-clear-hours')?.value || '24', 10) || 24) * 60;
      const steps = [{ delay_minutes: 0, action_type: 'brain_mission', mission }];
      if (msg) steps.push({ delay_minutes: 0, action_type: 'send_whatsapp', agent_id: agentId, connection_id: connectionId, message: msg });
      steps.push({ delay_minutes: clearMins, action_type: 'clear_brain_mission' });
      actionType = 'brain_mission';
      cfg = { steps };
    }
  } else {
    if (!assignOnly) cfg.message = msg;
  }
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'crm_save_automation');
  appendAutomationPipeline(fd);
  fd.append('trigger_type', triggerType);
  fd.append('trigger_value', triggerValue);
  fd.append('action_type', actionType);
  fd.append('action_config', JSON.stringify(cfg));
  const d = await (await fetch(API, { method: 'POST', body: fd })).json();
  if (d.error) return alert(d.message || 'Erro');
  showAutoToast(preset === 'tag_brain_journey' ? 'Jornada IA salva (missão + limpeza agendada)!' : 'Automação básica salva!');
  loadAutomations();
}

function showAutoToast(msg) {
  let el = document.getElementById('auto-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'auto-toast';
    el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:12px 18px;background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;border-radius:10px;font-size:.875rem';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.hidden = false;
  setTimeout(() => { el.hidden = true; }, 3500);
}

document.getElementById('a-trigger-type').addEventListener('change', function() {
  const t = this.value;
  const isWa = t === 'whatsapp_first' || t === 'whatsapp_message';
  document.getElementById('a-trigger-connection').style.display = isWa ? 'block' : 'none';
  document.getElementById('a-trigger-stage').style.display = t === 'stage_enter' ? 'block' : 'none';
  document.getElementById('a-trigger-tag').style.display = t === 'tag_added' ? 'block' : 'none';
  document.getElementById('a-trigger-webhook').style.display = t === 'webhook_received' ? 'block' : 'none';
  document.getElementById('a-trigger-source').style.display = t === 'contact_created' ? 'block' : 'none';
  document.getElementById('a-trigger-ltv').style.display = t === 'ltv_inactive' ? 'block' : 'none';
  if (isWa) {
    document.getElementById('a-trigger-stage').style.display = 'none';
    document.getElementById('a-trigger-tag').style.display = 'none';
    document.getElementById('a-trigger-webhook').style.display = 'none';
    document.getElementById('a-trigger-source').style.display = 'none';
  }
});
document.getElementById('a-trigger-type').dispatchEvent(new Event('change'));

function toggleSequenceMode() {
  const on = document.getElementById('a-use-sequence').checked;
  document.getElementById('a-sequence-box').style.display = on ? 'block' : 'none';
  document.getElementById('a-single-action-box').style.display = on ? 'none' : 'block';
  if (on && automationSteps.length === 0) addAutomationStep();
}

function addAutomationStep() {
  automationSteps.push({ delay_minutes: 0, action_type: 'send_whatsapp', message: '', agent_id: document.getElementById('a-agent')?.value || '' });
  renderAutomationSteps();
}

function removeAutomationStep(i) {
  automationSteps.splice(i, 1);
  renderAutomationSteps();
}

function renderAutomationSteps() {
  const el = document.getElementById('a-steps-list');
  if (!el) return;
  const types = ['send_whatsapp','add_tag','move_stage','pause_ai','assign_agent','brain_mission','clear_brain_mission','call_webhook','http_preset'];
  el.innerHTML = automationSteps.map((s, i) => `
    <div class="auto-step" data-idx="${i}">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <strong style="font-size:.8rem">Passo ${i + 1}</strong>
        <button type="button" class="btn btn-icon" onclick="removeAutomationStep(${i})" style="padding:2px 8px">×</button>
      </div>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label">Atraso (min) após passo anterior</label>
        <input type="number" class="form-control step-delay" data-i="${i}" value="${s.delay_minutes||0}" min="0">
      </div>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label">Ação</label>
        <select class="form-control step-type" data-i="${i}">
          ${types.map(t => `<option value="${t}" ${s.action_type===t?'selected':''}>${actionLabels[t]||t}</option>`).join('')}
        </select>
      </div>
      <textarea class="form-control step-payload" data-i="${i}" rows="2" placeholder="Mensagem, tag, estágio (new) ou ID webhook/preset">${stepPayloadPreview(s)}</textarea>
    </div>`).join('');
  el.querySelectorAll('.step-delay').forEach(inp => inp.addEventListener('change', syncStepsFromDom));
  el.querySelectorAll('.step-type').forEach(inp => inp.addEventListener('change', syncStepsFromDom));
  el.querySelectorAll('.step-payload').forEach(inp => inp.addEventListener('input', syncStepsFromDom));
}

function stepPayloadPreview(s) {
  if (s.action_type === 'send_whatsapp') return s.message || '';
  if (s.action_type === 'add_tag') return s.tag || '';
  if (s.action_type === 'move_stage') return s.stage || 'contacted';
  if (s.action_type === 'pause_ai') return String(s.minutes || 60);
  if (s.action_type === 'assign_agent') return String(s.agent_id || '');
  if (s.action_type === 'call_webhook') return String(s.webhook_id || '');
  if (s.action_type === 'http_preset') return String(s.preset_id || '');
  if (s.action_type === 'brain_mission') return s.mission || s.message || '';
  if (s.action_type === 'clear_brain_mission') return '(limpar missão)';
  return '';
}

function syncStepsFromDom() {
  document.querySelectorAll('.auto-step').forEach(row => {
    const i = parseInt(row.dataset.idx, 10);
    const delay = row.querySelector('.step-delay');
    const type = row.querySelector('.step-type');
    const payload = row.querySelector('.step-payload');
    if (!automationSteps[i]) return;
    automationSteps[i].delay_minutes = parseInt(delay?.value || '0', 10) || 0;
    automationSteps[i].action_type = type?.value || 'send_whatsapp';
    const p = (payload?.value || '').trim();
    const t = automationSteps[i].action_type;
    delete automationSteps[i].message; delete automationSteps[i].tag; delete automationSteps[i].stage;
    delete automationSteps[i].minutes; delete automationSteps[i].agent_id; delete automationSteps[i].webhook_id; delete automationSteps[i].preset_id;
    delete automationSteps[i].mission;
    if (t === 'send_whatsapp') {
      automationSteps[i].message = p;
      automationSteps[i].agent_id = document.getElementById('a-agent')?.value;
      automationSteps[i].connection_id = document.getElementById('a-connection')?.value;
    }
    else if (t === 'add_tag') automationSteps[i].tag = p;
    else if (t === 'move_stage') automationSteps[i].stage = p || 'contacted';
    else if (t === 'pause_ai') automationSteps[i].minutes = parseInt(p, 10) || 60;
    else if (t === 'assign_agent') automationSteps[i].agent_id = parseInt(p, 10) || 0;
    else if (t === 'brain_mission') automationSteps[i].mission = p;
    else if (t === 'call_webhook') automationSteps[i].webhook_id = parseInt(p, 10) || 0;
    else if (t === 'http_preset') automationSteps[i].preset_id = parseInt(p, 10) || 0;
  });
}

function buildConditionsConfig(cfg) {
  const requireTag = document.getElementById('a-require-tag').value.trim();
  const excludeTag = document.getElementById('a-exclude-tag').value.trim();
  const ab = parseInt(document.getElementById('a-ab-chance').value, 10);
  if (requireTag) cfg.require_tag = requireTag;
  if (excludeTag) cfg.exclude_tag = excludeTag;
  if (ab > 0 && ab < 100) cfg.ab_chance = ab;
  return cfg;
}

function buildLtvConfig(cfg) {
  cfg.cycle_days = parseInt(document.getElementById('a-ltv-cycle').value, 10) || 30;
  cfg.inactive_after_days = parseInt(document.getElementById('a-ltv-inactive').value, 10) || 0;
  cfg.min_purchases = parseInt(document.getElementById('a-ltv-min-purchases').value, 10) || 2;
  cfg.miss_factor = parseFloat(document.getElementById('a-ltv-miss-factor').value) || 2;
  return cfg;
}



function toggleActionFields() {

  const t = document.getElementById('a-action-type').value;

  const map = {

    send_whatsapp: 'a-action-whatsapp',

    invoke_agent: 'a-action-invoke',

    call_webhook: 'a-action-webhook',

    add_tag: 'a-action-tag',

    pause_ai: 'a-action-pause',

    set_memory: 'a-action-memory',

    google_sheets_append: 'a-action-sheets',

    http_preset: 'a-action-http-preset',
    move_stage: 'a-action-move-stage',
    assign_agent: 'a-action-assign-agent',
    brain_mission: 'a-action-brain-mission',
    clear_brain_mission: 'a-action-clear-mission',

  };

  Object.keys(map).forEach(k => {

    const el = document.getElementById(map[k]);

    if (el) el.style.display = t === k ? 'block' : 'none';

  });

}



const actionLabels = {

  send_whatsapp: 'WhatsApp',

  invoke_agent: 'Agente',

  call_webhook: 'Webhook',

  add_tag: 'Tag',

  pause_ai: 'Pausar IA',

  set_memory: 'Memória IA',

  google_sheets_append: 'Google Sheets',

  http_preset: 'HTTP externo',
  move_stage: 'Mover estágio',
  assign_agent: 'Atribuir agente',
  brain_mission: 'Missão cérebro',
  clear_brain_mission: 'Limpar missão',

};



function connectionLabel(id) {
  if (id === '*' || id === '' || id == null) return 'qualquer conexão';
  return WA_CONNECTION_NAMES[id] || ('conexão #' + id);
}

function triggerLabel(type, val) {
  if (type === 'stage_enter') return 'Entrar em «' + stageLabel(val) + '»';
  if (type === 'webhook_received') return 'Webhook «' + val + '»';
  if (type === 'contact_created') return val === '*' ? 'Lead criado' : 'Novo: ' + val;
  if (type === 'ltv_inactive') return 'LTV — inativo no ciclo';
  if (type === 'whatsapp_first') return val === '*' ? 'Primeira msg WhatsApp' : 'Primeira msg · ' + connectionLabel(val);
  if (type === 'whatsapp_message') return val === '*' ? 'Msg WhatsApp' : 'Msg · ' + connectionLabel(val);
  if (type === 'tag_added') return 'Tag «' + val + '»';
  return type + ' «' + val + '»';
}



function actionLabel(type, cfg) {
  if (cfg.steps && cfg.steps.length) {
    const names = cfg.steps.map(s => actionLabels[s.action_type] || s.action_type).join(' → ');
    const miss = (cfg.steps.find(s => s.action_type === 'brain_mission') || {}).mission;
    return names + (miss ? ': ' + String(miss).slice(0, 40) : '');
  }
  if (type === 'send_whatsapp') return 'WhatsApp: ' + (cfg.message || '').slice(0, 50);

  if (type === 'invoke_agent') return 'Agente #' + (cfg.agent_id || '') + (cfg.switch_agent ? ' (troca)' : '');

  if (type === 'call_webhook') return 'Webhook #' + (cfg.webhook_id || '');

  if (type === 'add_tag') return 'Tag: ' + (cfg.tag || '');

  if (type === 'pause_ai') return 'Pausar ' + (cfg.minutes || 60) + ' min';

  if (type === 'set_memory') return (cfg.key || '') + '=' + (cfg.value || '');

  if (type === 'google_sheets_append') return 'Linha na planilha';

  if (type === 'http_preset') return 'Preset #' + (cfg.preset_id || '');
  if (type === 'move_stage') return '→ ' + stageLabel(cfg.stage || '');
  if (type === 'brain_mission') return (cfg.mission || cfg.message || '').slice(0, 60);
  if (type === 'clear_brain_mission') return 'Remove missão IA';

  return type;

}



async function loadHttpPresetSelect() {
  const d = await (await fetch(API + '?action=http_preset_list')).json();
  const sel = document.getElementById('a-http-preset');
  if (!sel) return;
  sel.innerHTML = (d.presets || []).map(p =>
    `<option value="${p.id}">#${p.id} ${p.name} (${p.provider_slug})</option>`
  ).join('') || '<option value="">Configure em Integrações → HTTP</option>';
}

function escapeHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
}

async function loadInboundWebhookSelect() {
  const d = await (await fetch(API + '?action=inbound_webhook_list')).json();
  const opts = (d.webhooks || []).map(w =>
    `<option value="${escapeHtml(w.url_slug)}">#${w.id} ${escapeHtml(w.name)} (${escapeHtml(w.url_slug)})</option>`
  ).join('') || '<option value="">Crie em Webhooks</option>';
  ['a-trigger-webhook', 'b-webhook'].forEach(id => {
    const sel = document.getElementById(id);
    if (sel) sel.innerHTML = opts;
  });
}

async function loadOutboundSelect() {

  const d = await (await fetch(API + '?action=outbound_webhook_list')).json();

  const sel = document.getElementById('a-webhook-id');

  if (!sel) return;

  sel.innerHTML = (d.webhooks || []).map(w =>

    `<option value="${w.id}">#${w.id} ${w.name}</option>`

  ).join('') || '<option value="">Crie em Webhooks</option>';

}



async function loadAutomations() {

  const res = await fetch(API + '?action=crm_list_automations');

  const d = await res.json();

  const el = document.getElementById('auto-list');

  if (!d.automations || !d.automations.length) {

    el.innerHTML = '<p class="text-muted">Nenhuma regra. Crie a primeira ao lado.</p>';

    return;

  }

  el.innerHTML = (window.AUVVO_DEDUPE_WARN_HTML || '') + d.automations.map(a => {

    let cfg = {};

    try { cfg = JSON.parse(a.action_config || '{}'); } catch(e) {}

    let condParts = [];
    if (cfg.require_tag) condParts.push('com tag ' + escapeHtml(cfg.require_tag));
    if (cfg.exclude_tag) condParts.push('sem ' + escapeHtml(cfg.exclude_tag));
    if (cfg.ab_chance && cfg.ab_chance < 100) condParts.push('A/B ' + cfg.ab_chance + '%');
    const cond = condParts.length ? `<span class="bpm-block bpm-cond">${condParts.join(' · ')}</span>` : '';
    const seq = (cfg.steps && cfg.steps.length) ? `<div class="auto-meta">${cfg.steps.length} passo(s) na sequência</div>` : '';
    const delayNote = cfg.delay_minutes > 0 ? ` · após ${cfg.delay_minutes} min` : '';
    const ltvNote = a.trigger_type === 'ltv_inactive'
      ? `<div class="auto-meta">Ciclo ${cfg.cycle_days||'?'}d · após ${cfg.inactive_after_days||'2×'}d sem compra</div>` : '';
    const pipeNote = a.pipeline_name
      ? `<span class="badge" style="margin-left:6px;font-size:.65rem">${escapeHtml(a.pipeline_name)}</span>`
      : (parseInt(a.pipeline_id, 10) > 0 ? '' : '<span class="badge" style="margin-left:6px;font-size:.65rem">Todos funis</span>');
    return `<div class="auto-card">
      <div class="bpm-flow">
        <span class="bpm-block bpm-trigger">${triggerLabel(a.trigger_type, a.trigger_value)}${pipeNote}</span>
        ${cond ? '<span class="bpm-arrow">→</span>' + cond : ''}
        <span class="bpm-arrow">→</span>
        <span class="bpm-block bpm-action">${actionLabels[a.action_type] || a.action_type}</span>
      </div>
      <div class="auto-meta">${actionLabel(a.action_type, cfg)}${delayNote}</div>${ltvNote}${seq}
      <button type="button" class="btn btn-secondary" style="margin-top:10px;padding:4px 12px;font-size:.75rem" onclick="deleteAutomation(${a.id})">Remover</button>
    </div>`;

  }).join('');

}



async function saveAutomation() {
  const triggerType = document.getElementById('a-trigger-type').value;
  let triggerValue = document.getElementById('a-trigger-stage').value;
  if (triggerType === 'whatsapp_first' || triggerType === 'whatsapp_message') {
    triggerValue = document.getElementById('a-trigger-connection')?.value || '*';
  }
  if (triggerType === 'tag_added') triggerValue = document.getElementById('a-trigger-tag').value.trim();
  if (triggerType === 'webhook_received') triggerValue = document.getElementById('a-trigger-webhook').value;
  if (triggerType === 'contact_created') triggerValue = document.getElementById('a-trigger-source').value;
  if (triggerType === 'ltv_inactive') triggerValue = 'default';

  let actionType = document.getElementById('a-action-type').value;
  let cfg = buildConditionsConfig({});
  if (triggerType === 'ltv_inactive') buildLtvConfig(cfg);

  if (document.getElementById('a-use-sequence').checked) {
    syncStepsFromDom();
    if (!automationSteps.length) return alert('Adicione ao menos um passo na sequência.');
    for (const s of automationSteps) {
      if (s.action_type === 'send_whatsapp' && !(s.message || '').trim()) {
        return alert('Preencha a mensagem WhatsApp em todos os passos da sequência.');
      }
    }
    cfg.steps = automationSteps.map(s => {
      const step = { delay_minutes: s.delay_minutes || 0, action_type: s.action_type };
      if (s.action_type === 'send_whatsapp') Object.assign(step, {
        agent_id: parseInt(s.agent_id, 10),
        connection_id: parseInt(s.connection_id, 10),
        message: s.message || '',
      });
      else if (s.action_type === 'add_tag') step.tag = s.tag || '';
      else if (s.action_type === 'move_stage') step.stage = s.stage || 'contacted';
      else if (s.action_type === 'pause_ai') step.minutes = s.minutes || 60;
      else if (s.action_type === 'assign_agent') step.agent_id = s.agent_id || 0;
      else if (s.action_type === 'call_webhook') step.webhook_id = s.webhook_id || 0;
      else if (s.action_type === 'http_preset') step.preset_id = s.preset_id || 0;
      else if (s.action_type === 'brain_mission') step.mission = s.mission || s.message || '';
      return step;
    });
    actionType = automationSteps[0].action_type;
  } else {
    const delay = parseInt(document.getElementById('a-delay-min').value, 10) || 0;
    if (delay > 0) cfg.delay_minutes = delay;
    if (actionType === 'send_whatsapp') {
      const connId = parseInt(document.getElementById('a-connection').value, 10);
      if (!connId) return alert('Selecione a conexão WhatsApp (linha).');
      const msg = (document.getElementById('a-message').value || '').trim();
      if (!msg) return alert('Digite a mensagem WhatsApp.');
      Object.assign(cfg, {
        connection_id: connId,
        agent_id: parseInt(document.getElementById('a-agent').value, 10),
        message: msg,
      });
    } else if (actionType === 'invoke_agent') {
      Object.assign(cfg, {
        agent_id: parseInt(document.getElementById('a-invoke-agent').value, 10),
        switch_agent: document.getElementById('a-switch-agent').checked,
        message: document.getElementById('a-invoke-msg').value,
      });
    } else if (actionType === 'assign_agent') {
      Object.assign(cfg, { agent_id: parseInt(document.getElementById('a-assign-agent').value, 10) });
    } else if (actionType === 'call_webhook') {
      Object.assign(cfg, { webhook_id: parseInt(document.getElementById('a-webhook-id').value, 10) });
    } else if (actionType === 'add_tag') {
      Object.assign(cfg, { tag: document.getElementById('a-tag').value.trim() });
    } else if (actionType === 'set_memory') {
      Object.assign(cfg, { key: document.getElementById('a-mem-key').value.trim(), value: document.getElementById('a-mem-val').value.trim() });
    } else if (actionType === 'http_preset') {
      Object.assign(cfg, { preset_id: parseInt(document.getElementById('a-http-preset').value, 10) });
    } else if (actionType === 'move_stage') {
      Object.assign(cfg, { stage: document.getElementById('a-move-stage').value });
    } else if (actionType === 'brain_mission') {
      Object.assign(cfg, { mission: (document.getElementById('a-brain-mission')?.value || '').trim() });
      if (!cfg.mission) return alert('Descreva a missão para o cérebro.');
    } else if (actionType === 'clear_brain_mission') {
      /* cfg mantém condições/LTV; ação não precisa de campos extras */
    } else {
      Object.assign(cfg, { minutes: parseInt(document.getElementById('a-pause-min').value, 10) || 60 });
    }
  }

  const fd = new FormData();

  fd.append('csrf_token', CSRF);

  fd.append('action', 'crm_save_automation');

  appendAutomationPipeline(fd);

  fd.append('trigger_type', triggerType);

  fd.append('trigger_value', triggerValue);

  fd.append('action_type', actionType);

  fd.append('action_config', JSON.stringify(cfg));

  const res = await fetch(API, { method: 'POST', body: fd });

  const d = await res.json();

  if (d.error) return alert(d.message || 'Erro');

  loadAutomations();
  loadDedupeWarnings();
  loadQueueStats();
}

async function loadQueueStats() {
  const d = await (await fetch(API + '?action=crm_automation_queue_stats')).json();
  const el = document.getElementById('queue-stats');
  if (!el || d.error) return;
  const s = d.stats || {};
  const pending = parseInt(s.pending, 10) || 0;
  const failed = parseInt(s.failed, 10) || 0;
  const overdue = parseInt(s.overdue_pending, 10) || 0;
  const workerAlive = !!s.worker_alive;
  const needAttn = !!s.worker_attention;
  let warn = '';
  if (needAttn) {
    const workerLine = workerAlive
      ? 'Worker ativo nos últimos 3 min.'
      : 'Worker não detectado — rode <code>cd auvvo-worker && npm start</code>.';
    warn = `<div style="width:100%;padding:10px 14px;margin-bottom:12px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;font-size:.8125rem">
      <strong>Fila de automações</strong> — ${pending} pendente(s)${overdue > 0 ? `, ${overdue} atrasada(s)` : ''}. ${workerLine}
    </div>`;
  } else if (failed > 0) {
    warn = `<div style="width:100%;padding:10px 14px;margin-bottom:12px;background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;font-size:.8125rem">
      <strong>${failed} falha(s)</strong> — verifique worker, Calendar ou webhooks.
    </div>`;
  }
  el.innerHTML = warn + `
    <div class="queue-stat"><span class="text-muted">Na fila</span><strong>${s.pending||0}</strong></div>
    <div class="queue-stat"><span class="text-muted">Processando</span><strong>${s.processing||0}</strong></div>
    <div class="queue-stat"><span class="text-muted">Concluídos hoje</span><strong>${s.done_today||0}</strong></div>
    <div class="queue-stat"><span class="text-muted">Falhas</span><strong>${s.failed||0}</strong></div>`;
}

async function deleteAutomation(id) {

  if (!confirm('Remover esta regra?')) return;

  const fd = new FormData();

  fd.append('csrf_token', CSRF);

  fd.append('action', 'crm_delete_automation');

  fd.append('id', id);

  await fetch(API, { method: 'POST', body: fd });

  loadAutomations();
  loadDedupeWarnings();

}



async function loadPipelines() {
  const res = await fetch(API + '?action=crm_list_pipelines');
  const d = await res.json();
  const el = document.getElementById('pipeline-list');
  if (!el || !d.pipelines) { if (el) el.innerHTML = ''; return; }
  el.innerHTML = d.pipelines.map(p => `
    <div style="margin-bottom:20px">
      <strong>${escapeHtml(p.name)}</strong>${p.is_default == 1 ? ' <span class="badge badge-success">Padrão</span>' : ''}
      <div class="pipe-stages">${(p.stages||[]).map(s => `<span class="pipe-pill">${escapeHtml(s.label)}</span>`).join('')}</div>
    </div>
  `).join('');
}

function toast(msg, type = 'info') {
  let wrap = document.getElementById('auvvo-toast-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'auvvo-toast-wrap';
    wrap.style.cssText = 'position:fixed;top:18px;right:18px;display:flex;flex-direction:column;gap:10px;z-index:99999;pointer-events:none';
    document.body.appendChild(wrap);
  }
  const colors = {
    error:   { bg: '#FEF2F2', bd: '#FCA5A5', fg: '#991B1B' },
    success: { bg: '#F0FDF4', bd: '#86EFAC', fg: '#166534' },
    info:    { bg: '#EEF2FF', bd: '#C7D2FE', fg: '#1E3A8A' },
  };
  const c = colors[type] || colors.info;
  const el = document.createElement('div');
  el.style.cssText = `background:${c.bg};border:1px solid ${c.bd};color:${c.fg};padding:10px 14px;border-radius:12px;min-width:220px;max-width:360px;box-shadow:0 8px 20px rgba(0,0,0,.1);font-size:.875rem;font-weight:600;pointer-events:auto`;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => { el.style.cssText += 'opacity:0;transition:opacity .25s'; }, 2400);
  setTimeout(() => el.remove(), 2700);
}
window.toast = toast;

toggleActionFields();
initAutomationPipelineSelect();
toggleBasicPreset();

let flowEditorInitialized = false;
function ensureFlowEditorInit() {
  if (flowEditorInitialized) return;
  flowEditorInitialized = true;
  if (typeof initAutomacoesFlow === 'function') initAutomacoesFlow().then(() => {
    if (typeof initAutomacoesImprovements === 'function') initAutomacoesImprovements();
  });
}
window.ensureFlowEditorInit = ensureFlowEditorInit;

async function loadDedupeWarnings() {
  try {
    const d = await (await fetch(API + '?action=crm_automation_dedupe_warnings')).json();
    if (d.error || !(d.warnings || []).length) {
      window.AUVVO_DEDUPE_WARN_HTML = '';
      const el = document.getElementById('dedupe-warn-global');
      if (el) el.innerHTML = '';
      const flowEl0 = document.getElementById('flow-dedupe-warn');
      if (flowEl0) flowEl0.innerHTML = '';
      return;
    }
    const html = `<div style="padding:10px 14px;margin-bottom:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;font-size:.8125rem">
      <strong>Gatilhos duplicados (dedupe)</strong> — por lead, só a <em>primeira</em> automação ativa dispara:
      <ul style="margin:8px 0 0 18px;padding:0">${d.warnings.map(w =>
        `<li><strong>${escapeHtml(w.label)}</strong> — ${w.count} ativas (${(w.items||[]).map(i => escapeHtml(i.name)).join(', ')})</li>`
      ).join('')}</ul>
    </div>`;
    window.AUVVO_DEDUPE_WARN_HTML = html;
    const el = document.getElementById('dedupe-warn-global');
    if (el) el.innerHTML = html;
    const flowEl = document.getElementById('flow-dedupe-warn');
    if (flowEl) flowEl.innerHTML = html;
  } catch (e) {}
}
window.loadDedupeWarnings = loadDedupeWarnings;

document.addEventListener('DOMContentLoaded', async () => {
  await loadDedupeWarnings();
  loadOutboundSelect();
  loadHttpPresetSelect();
  loadInboundWebhookSelect();
  loadAutomations();
  loadQueueStats();
  loadPipelines();
  const tabVisual = document.getElementById('tab-visual');
  if (tabVisual && tabVisual.classList.contains('active')) {
    ensureFlowEditorInit();
  }
});

window.FLOW_BOOT = <?= json_encode([
    'csrf' => $_SESSION['csrf_token'],
    'api' => 'backend/api.php',
    'defaultPipelineId' => $defaultPid,
    'automationPipelineId' => $defaultPid,
    'stagesByPipeline' => $stagesByPipeline,
    'stagesOrderedByPipeline' => $stagesOrderedByPipeline,
    'pipelines' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => (string) $p['name']], $pipelinesFull),
    'stages' => $stages,
    'agents' => array_map(static fn ($a) => [
        'id' => (int) $a['id'],
        'name' => (string) $a['name'],
        'status' => (string) ($a['status'] ?? ''),
    ], $agents),
    'whatsappConnections' => array_map(static fn ($c) => [
        'id' => (int) $c['id'],
        'name' => (string) $c['name'],
        'status' => (string) ($c['status'] ?? 'offline'),
    ], $whatsapp_connections),
    'sampleContact' => [
        'name' => 'Maria Silva',
        'phone' => '11999998888',
        'email' => 'maria@email.com',
        'company' => 'Acme Ltda',
        'stage' => 'new',
    ],
], JSON_UNESCAPED_UNICODE) ?>;

</script>
<script src="assets/automacoes-flow-config.js?v=20260528"></script>
<script src="assets/automacoes-flow-recipes.js?v=20260528"></script>
<script src="assets/automacoes-flow-templates.js?v=20260528"></script>
<script src="assets/auvvo-pack-flows.js?v=20260528"></script>
<script src="assets/automacoes-flow.js?v=20260528"></script>
<script src="assets/automacoes-improvements.js?v=20260528"></script>
<script src="assets/automacoes-simulator.js?v=20260521c"></script>
<script src="assets/automacoes-runs.js?v=20260524"></script>
<script src="assets/automacoes-packs.js?v=20260522"></script>
<script>
window.setAutomacoesTab = function (tab) {
  if (tab === 'build') tab = 'visual';
  var panels = { visual: 'panel-visual', test: 'panel-test', runs: 'panel-runs', quick: 'panel-quick-rules' };
  Object.keys(panels).forEach(function (k) {
    var el = document.getElementById(panels[k]);
    if (el) el.style.display = tab === k ? 'block' : 'none';
    var btn = document.getElementById('tab-' + k);
    if (btn) btn.classList.toggle('active', tab === k);
  });
  if (tab === 'test') {
    if (typeof window.initAutomacoesSimulator === 'function') window.initAutomacoesSimulator();
    if (typeof window.refreshSimulatorFlows === 'function') window.refreshSimulatorFlows();
    if (typeof window.syncSimFromEditor === 'function') window.syncSimFromEditor();
  }
  if (tab === 'runs' && typeof window.initAutomacoesRuns === 'function') window.initAutomacoesRuns();
  if (tab === 'visual' && typeof window.ensureFlowEditorInit === 'function') window.ensureFlowEditorInit();
};
window.setAutomacoesMainTab = function (tab) {
  var map = { build: 'visual', test: 'test', runs: 'runs' };
  window.setAutomacoesTab(map[tab] || tab);
};
window.setAutomacoesPageTab = function (tab) {
  window.setAutomacoesTab(tab === 'quick' ? 'quick' : 'visual');
};
</script>
<script>
async function syncWaConnectionDropdowns() {
  try {
    const d = await (await fetch(API + '?action=list_whatsapp_connections')).json();
    const list = d.connections || [];
    const base = ['<option value="">— Selecione —</option>'].concat(
      list.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
    ).join('');
    const trig = ['<option value="*">Qualquer conexão</option>'].concat(
      list.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
    ).join('');
    const b = document.getElementById('b-connection');
    const a = document.getElementById('a-connection');
    const t = document.getElementById('a-trigger-connection');
    if (b) { const p = b.value; b.innerHTML = base; if (p) b.value = p; }
    if (a) { const p = a.value; a.innerHTML = base; if (p) a.value = p; }
    if (t) { const p = t.value; t.innerHTML = trig; if (p) t.value = p; }
    if (window.FLOW_BOOT) {
      window.FLOW_BOOT.whatsappConnections = list.map(c => ({
        id: parseInt(c.id, 10), name: c.name, status: c.status || 'offline',
      }));
    }
  } catch (e) {}
}
document.addEventListener('DOMContentLoaded', syncWaConnectionDropdowns);

if (typeof initAutomacoesPacks === 'function') {
  initAutomacoesPacks({
    onApplied: function (data) {
      if (window.FLOW_BOOT && data.agent_rows) {
        var byId = {};
        (window.FLOW_BOOT.agents || []).forEach(function (a) {
          byId[a.id] = a;
        });
        data.agent_rows.forEach(function (r) {
          byId[r.id] = { id: r.id, name: r.name, status: 'waiting_qr' };
        });
        window.FLOW_BOOT.agents = Object.keys(byId).map(function (k) {
          return byId[k];
        });
      }
      if (typeof loadFlowList === 'function') loadFlowList();
    },
  });
}
</script>

</body>

</html>

