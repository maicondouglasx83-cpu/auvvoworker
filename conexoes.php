<?php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once __DIR__ . '/backend/migrations.php';

auvvo_run_migrations($pdo);
require_once __DIR__ . '/backend/whatsapp_connections.inc.php';

$user_id = (int) $_SESSION['user_id'];
$whatsapp_connections = auvvo_whatsapp_connections_list($pdo, $user_id);

$stmt = $pdo->prepare('SELECT id, name FROM agents WHERE user_id = ? AND status != ? ORDER BY name');
$stmt->execute([$user_id, 'draft']);
$agents = $stmt->fetchAll();

$online = count(array_filter($whatsapp_connections, static fn ($c) => ($c['status'] ?? '') === 'online'));
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conexões WhatsApp – Auvvo</title>
<link rel="stylesheet" href="app.css">
<link rel="stylesheet" href="assets/conexoes.css?v=20260524">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="icon" type="image/png" href="icone.png">
</head>
<body>
<div class="app-container">
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">

  <div class="page-header">
    <div>
      <h1 class="page-title">Conexões WhatsApp</h1>
      <p class="page-hint">Linhas nomeadas (Vendas, Suporte…) independentes dos agentes. Defina o <strong>modo de IA</strong> por linha e use em <a href="automacoes">Automações</a>.</p>
    </div>
    <div class="page-header-actions">
      <a href="agentes" class="btn btn-outline"><i class="ph-bold ph-brain"></i> Agentes (cérebro)</a>
      <a href="automacoes" class="btn btn-secondary"><i class="ph-bold ph-lightning"></i> Automações</a>
    </div>
  </div>

  <div class="wa-stats">
    <div class="wa-stat">
      <span class="wa-stat-label">Linhas</span>
      <strong><?= count($whatsapp_connections) ?></strong>
    </div>
    <div class="wa-stat wa-stat--ok">
      <span class="wa-stat-label">Conectadas</span>
      <strong><?= $online ?></strong>
    </div>
    <div class="wa-stat">
      <span class="wa-stat-label">Arquitetura</span>
      <strong style="font-size:.875rem;font-weight:600">Linha ≠ Agente</strong>
    </div>
  </div>

  <div class="wa-layout">
    <section class="app-card wa-panel-list">
      <h2 class="wa-panel-title"><i class="ph-bold ph-list"></i> Suas linhas</h2>
      <input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

      <label class="form-label">Nova conexão</label>
      <div class="wa-create-row">
        <input type="text" id="wa-new-name" class="form-control" placeholder="Ex: Vendas, Suporte…" maxlength="120">
        <button type="button" class="btn btn-primary" id="btn-wa-create"><i class="ph-bold ph-plus"></i> Criar</button>
      </div>

      <label class="form-label">Agente padrão (cérebro)</label>
      <select id="wa-default-agent" class="form-control wa-field-gap">
        <option value="">— Definir ao editar a linha —</option>
        <?php foreach ($agents as $ag): ?>
        <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="form-label">Modo IA (nova linha)</label>
      <select id="wa-default-ai-mode" class="form-control wa-field-gap">
        <option value="flows_first" selected>Fluxos primeiro — automação tem prioridade</option>
        <option value="standalone">Só agente — IA livre, sem bloqueio de fluxo</option>
        <option value="flows_only">Só automação — agente livre nunca responde</option>
      </select>

      <div id="wa-conn-list" class="wa-conn-list">
        <?php if (empty($whatsapp_connections)): ?>
        <p class="text-muted wa-empty">Nenhuma conexão ainda. Crie uma acima.</p>
        <?php else: foreach ($whatsapp_connections as $wc):
          $st = (string) ($wc['status'] ?? 'offline');
          $badgeClass = $st === 'online' ? 'online' : ($st === 'waiting_qr' ? 'waiting' : 'offline');
          $badgeLabel = $st === 'online' ? 'Conectado' : ($st === 'waiting_qr' ? 'Aguardando QR' : 'Desconectado');
          $aiMode = (string) ($wc['ai_mode'] ?? 'flows_first');
          $modeShort = match ($aiMode) {
              'standalone' => 'Agente',
              'flows_only' => 'Só fluxo',
              default => 'Fluxos',
          };
        ?>
        <div class="wa-conn-item" data-conn-id="<?= (int) $wc['id'] ?>">
          <button type="button" class="wa-conn-select" data-conn-id="<?= (int) $wc['id'] ?>">
            <span class="wa-conn-name"><?= htmlspecialchars($wc['name']) ?></span>
            <span class="wa-conn-mode" title="Modo IA"><?= htmlspecialchars($modeShort) ?></span>
            <span class="wa-conn-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
          </button>
          <div class="wa-conn-actions">
            <button type="button" class="wa-conn-btn wa-conn-rename" data-conn-id="<?= (int) $wc['id'] ?>" title="Renomear"><i class="ph-bold ph-pencil-simple"></i></button>
            <button type="button" class="wa-conn-btn wa-conn-delete" data-conn-id="<?= (int) $wc['id'] ?>" title="Excluir"><i class="ph-bold ph-trash"></i></button>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <input type="hidden" id="wa-connection-select" value="<?= !empty($whatsapp_connections) ? (int) $whatsapp_connections[0]['id'] : 0 ?>">
    </section>

    <section class="app-card wa-panel-qr">
      <h2 class="wa-panel-title"><i class="ph-bold ph-qr-code"></i> Conectar</h2>
      <div id="evo-qr-box" class="wa-qr-wrap">
        <i class="ph-bold ph-qr-code wa-qr-placeholder"></i>
      </div>
      <div id="evo-status" class="wa-status">Selecione ou crie uma conexão</div>
      <div id="evo-actions" class="wa-actions"></div>

      <div id="evo-conn-edit" class="wa-edit" style="display:none">
        <label class="form-label">Nome da conexão</label>
        <input type="text" id="evo-conn-name" class="form-control" maxlength="120">

        <label class="form-label">Agente padrão (cérebro)</label>
        <select id="evo-conn-default-agent" class="form-control">
          <option value="">— Nenhum —</option>
          <?php foreach ($agents as $ag): ?>
          <option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="wa-field-hint">Usado quando nenhum fluxo tratar a mensagem (modo «Fluxos primeiro» ou «Só agente»).</p>

        <label class="form-label">Modo de IA desta linha</label>
        <select id="evo-conn-ai-mode" class="form-control">
          <option value="flows_first">Fluxos primeiro — automação publicada tem prioridade; agente só se o fluxo não responder</option>
          <option value="standalone">Só agente — IA livre na conexão; fluxos podem enviar mensagens, mas o agente responde sempre que quiser</option>
          <option value="flows_only">Só automação — agente livre nunca responde; use fluxos publicados nesta linha</option>
        </select>
        <div id="evo-ai-mode-desc" class="wa-mode-desc"></div>

        <button type="button" class="btn btn-secondary wa-save-btn" id="btn-evo-save-conn">
          <i class="ph-bold ph-floppy-disk"></i> Salvar conexão
        </button>
      </div>

      <div class="wa-help">
        <strong>Modos de IA</strong>
        <ul class="wa-help-modes">
          <li><strong>Fluxos primeiro</strong> — ideal para «Clínica — agendamento»: mensagem fixa do fluxo, depois IA segue as instruções do nó Pensar.</li>
          <li><strong>Só agente</strong> — linha atendida apenas pelo cérebro (Guilherme), sem depender de fluxo publicado.</li>
          <li><strong>Só automação</strong> — respostas vêm exclusivamente de fluxos; útil para jornadas rígidas.</li>
        </ul>
        <strong style="display:block;margin-top:14px">Passo a passo</strong>
        <ol>
          <li>Crie uma linha (ex: <em>Vendas</em>) e escolha o modo</li>
          <li>Gere o QR e escaneie no WhatsApp</li>
          <li>Em Automações, publique o fluxo com gatilho nesta linha</li>
        </ol>
      </div>
    </section>
  </div>

</main>
</div>
<script src="assets/evolution-connect.js?v=20260524"></script>
<?php include __DIR__ . '/includes/toast.php'; ?>
</body>
</html>
