<?php
// campanhas.php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/whatsapp_connections.inc.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id, name, role FROM agents WHERE user_id = ? ORDER BY id DESC");
$stmt->execute([$user_id]);
$agents = $stmt->fetchAll();

$whatsapp_connections = auvvo_whatsapp_connections_list($pdo, (int) $user_id);

$stmt = $pdo->prepare(
    "SELECT c.*, a.name AS agent_name, wc.name AS connection_name
     FROM campaigns c
     LEFT JOIN agents a ON c.agent_id = a.id
     LEFT JOIN whatsapp_connections wc ON wc.id = c.whatsapp_connection_id
     WHERE c.user_id = ?
     ORDER BY c.id DESC"
);
$stmt->execute([$user_id]);
$campaigns = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('camp_title') ?></title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <link rel="icon" type="image/png" href="icone.png">
</head>
<body>

    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="page-header">
                <div>
                    <h1 class="page-title"><?= t('camp_page_title') ?></h1>
                    <p class="text-muted"><?= t('camp_page_sub') ?></p>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div style="background: var(--surface-success); color: var(--text-success); padding: 12px 24px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <i class="ph-bold ph-check-circle"></i>
                <?php
                    if ($_GET['success'] === 'created') {
                        $contacts = intval($_GET['contacts'] ?? 0);
                        echo t('camp_success_created') . ($contacts > 0 ? t('camp_success_contacts', ['count' => $contacts]) : '');
                    } elseif ($_GET['success'] === 'deleted') {
                        echo t('camp_success_deleted');
                    }
                ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #EF4444; padding: 12px 24px; border-radius: var(--radius-md); margin-bottom: 24px;">
                <i class="ph-bold ph-warning"></i>
                <?php
                    $errs = [
                        'missing_fields' => t('camp_err_missing'),
                        'missing_connection' => 'Selecione a conexão WhatsApp (linha) para enviar a campanha.',
                        'invalid_connection' => 'Conexão WhatsApp inválida.',
                        'invalid_csv'    => t('camp_err_invalid_csv'),
                    ];
                    echo $errs[$_GET['error']] ?? t('camp_err_unknown');
                ?>
            </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 32px;">
                
                <!-- Formulário de Campanha -->
                <div class="app-card" style="padding: 32px;">
                    <h3 style="font-size: 1.125rem; margin-bottom: 24px;"><?= t('camp_form_title') ?></h3>
                    
                    <form action="backend/process_campaign.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create_campaign">
                        
                        <div class="form-group">
                            <label class="form-label"><?= t('camp_name_label') ?></label>
                            <input type="text" name="campaign_name" class="form-control" placeholder="<?= htmlspecialchars(t('camp_name_ph')) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Conexão WhatsApp (linha)</label>
                            <select name="whatsapp_connection_id" class="form-control" required>
                                <option value="">— Selecione a linha —</option>
                                <?php foreach ($whatsapp_connections as $wc): ?>
                                <option value="<?= (int) $wc['id'] ?>"><?= htmlspecialchars($wc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($whatsapp_connections)): ?>
                            <p class="text-muted" style="font-size:.875rem">Crie uma conexão em <a href="conexoes">Conexões WhatsApp</a>.</p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Agente (cérebro) — opcional</label>
                            <select name="agent_id" class="form-control">
                                <option value=""><?= t('camp_agent_ph') ?></option>
                                <?php if (count($agents) > 0): ?>
                                    <?php foreach($agents as $agent): ?>
                                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?> (<?php echo htmlspecialchars($agent['role']); ?>)</option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option disabled><?= t('camp_agent_none') ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?= t('camp_csv_label') ?></label>
                            <div class="upload-area" style="padding: 20px; cursor: pointer;" onclick="document.getElementById('csv-input').click();">
                                <i class="ph-bold ph-file-csv" style="font-size: 2rem; color: var(--accent-teal);"></i>
                                <div>
                                    <strong style="display: block;"><?= t('camp_csv_drag') ?></strong>
                                    <span class="text-muted" style="font-size: 0.75rem;"><?= t('camp_csv_hint') ?></span>
                                    <p id="csv-name" style="margin-top: 6px; font-size: 0.875rem; color: var(--accent-teal); display: none;"></p>
                                </div>
                                <input type="file" id="csv-input" name="csv_file" accept=".csv" style="display: none;" onchange="document.getElementById('csv-name').textContent = '📎 ' + this.files[0].name; document.getElementById('csv-name').style.display='block';">
                                <button type="button" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.75rem;" onclick="event.stopPropagation(); document.getElementById('csv-input').click();"><?= t('camp_csv_btn') ?></button>
                            </div>
                        </div>

                        <div class="form-group">
                            <div style="display: flex; justify-content: space-between;">
                                <label class="form-label"><?= t('camp_msg_label') ?></label>
                                <span class="text-muted" style="font-size: 0.75rem;">Variáveis: <code>{{nome}}</code>, <code>{{telefone}}</code></span>
                            </div>
                            <textarea name="message" id="campaign-message" class="form-control" rows="5" placeholder="<?= htmlspecialchars(t('camp_msg_ph')) ?>" oninput="updatePreview(this.value)" required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?= t('camp_schedule_label') ?></label>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="radio" name="schedule_type" value="now" checked onchange="toggleSchedule(false)"> <?= t('camp_send_now') ?>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="radio" name="schedule_type" value="schedule" onchange="toggleSchedule(true)"> <?= t('camp_send_schedule') ?>
                                </label>
                                <input type="datetime-local" name="scheduled_at" id="schedule-input" class="form-control" style="flex: 1; display: none;">
                            </div>
                        </div>

                        <div style="display: flex; gap: 16px; margin-top: 32px;">
                            <button type="submit" class="btn btn-primary" style="flex: 2;">
                                <i class="ph-bold ph-paper-plane-right"></i> <?= t('camp_create_btn') ?>
                            </button>
                            <button type="reset" class="btn btn-outline" style="flex: 1;" onclick="updatePreview('')">
                                <i class="ph-bold ph-x"></i> <?= t('camp_clear_btn') ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Preview e Lista de Campanhas -->
                <div>
                    <!-- Preview em Tempo Real -->
                    <div class="app-card" style="margin-bottom: 24px; background: #FAFAFA;">
                        <h4 style="font-size: 0.9375rem; margin-bottom: 16px;"><?= t('camp_preview_title') ?></h4>
                        
                        <div style="background: #E5DDD5; padding: 16px; border-radius: var(--radius-lg);">
                            <div class="chat-bubble sent" id="preview-bubble">
                                <?= t('camp_preview_default') ?>
                                <span class="chat-time"><?= t('camp_preview_now') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Campanhas Criadas -->
                    <div class="app-card" style="padding: 0;">
                        <div style="padding: 20px; border-bottom: 1px solid var(--border-subtle);">
                            <h4 style="font-size: 0.9375rem;"><?= t('camp_list_title') ?></h4>
                        </div>
                        <?php if (count($campaigns) > 0): ?>
                            <?php foreach($campaigns as $camp): ?>
                            <?php
                                $status_map = [
                                    'draft'     => ['label' => t('camp_status_draft'),     'class' => ''],
                                    'scheduled' => ['label' => t('camp_status_scheduled'), 'class' => 'badge-warning'],
                                    'running'   => ['label' => t('camp_status_running'),   'class' => 'badge-success'],
                                    'completed' => ['label' => t('camp_status_completed'), 'class' => 'badge-success'],
                                    'paused'    => ['label' => t('camp_status_paused'),    'class' => 'badge-danger'],
                                ];
                                $st = $status_map[$camp['status']] ?? ['label' => $camp['status'], 'class' => ''];
                                $pct = $camp['total_contacts'] > 0 ? round(($camp['sent_count'] / $camp['total_contacts']) * 100) : 0;
                            ?>
                            <div style="padding: 16px; border-bottom: 1px solid var(--border-subtle);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <strong style="display: block; font-size: 0.875rem;"><?php echo htmlspecialchars($camp['name']); ?></strong>
                                        <span class="text-muted" style="font-size: 0.75rem;"><?php echo $camp['total_contacts']; ?> contatos • <?php echo htmlspecialchars($camp['connection_name'] ?? $camp['agent_name'] ?? 'Sem linha'); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span class="badge <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span>
                                        <form method="POST" action="backend/process_campaign.php" onsubmit="return confirm('<?= addslashes(t('camp_delete_confirm')) ?>');" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_campaign">
                                            <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                            <button type="submit" class="btn btn-icon" style="color: var(--text-danger); font-size: 0.75rem;"><i class="ph-bold ph-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($camp['total_contacts'] > 0): ?>
                                <div class="progress-container"><div class="progress-bar" style="width: <?php echo $pct; ?>%; background: #10B981;"></div></div>
                                <span class="text-muted" style="font-size: 0.75rem;"><?php echo $camp['sent_count']; ?> / <?php echo $camp['total_contacts']; ?> enviados (<?php echo $pct; ?>%)</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 32px; text-align: center; color: var(--text-muted);">
                                <i class="ph-bold ph-megaphone" style="font-size: 2rem; display: block; margin-bottom: 8px; opacity: 0.4;"></i>
                                <?= t('camp_list_empty') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <script>
        const PREVIEW_NOW    = <?= json_encode(t('camp_preview_now')) ?>;
        const PREVIEW_TYPE   = <?= json_encode(t('camp_preview_type')) ?>;

        function updatePreview(text) {
            const bubble = document.getElementById('preview-bubble');
            if (!text || text.trim() === '') {
                bubble.innerHTML = '<em style="color: var(--text-muted);">' + PREVIEW_TYPE + '</em><span class="chat-time">' + PREVIEW_NOW + '</span>';
                return;
            }
            let preview = text
                .replace(/\{\{nome\}\}/gi, '<strong>João</strong>')
                .replace(/\{\{telefone\}\}/gi, '+55 11 99999-9999');
            bubble.innerHTML = preview + '<span class="chat-time">' + PREVIEW_NOW + '</span>';
        }

        function toggleSchedule(show) {
            document.getElementById('schedule-input').style.display = show ? 'block' : 'none';
        }
    </script>

</body>
</html>
