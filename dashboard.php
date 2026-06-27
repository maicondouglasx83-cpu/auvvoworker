<?php
// dashboard.php
require_once 'includes/auth.php';
require_once 'backend/db.php';
require_once 'backend/ai_queue.inc.php';

$user_id = $_SESSION['user_id'];
$queue_stats = auvvo_ai_queue_stats($pdo, $user_id);
$queue_pending = (int) ($queue_stats['pending'] ?? 0) + (int) ($queue_stats['debouncing'] ?? 0) + (int) ($queue_stats['processing'] ?? 0);
$queue_failed = (int) ($queue_stats['failed'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'Usuário';
$first_name = explode(' ', $user_name)[0];

// --- Busca de dados reais do banco — queries consolidadas ---

// Query 1: agentes + métricas por agente (1 query com LEFT JOIN)
$agent_metrics = [];
$total_agents  = 0;
$online_agents = 0;
$total_ai_msgs = 0;

$stmt = $pdo->prepare(
    "SELECT
        a.id, a.name, a.status,
        COUNT(DISTINCT cl.contact_jid) AS leads,
        SUM(CASE WHEN cl.type='ai'     THEN 1 ELSE 0 END) AS ai_msgs,
        SUM(CASE WHEN cl.type='manual' THEN 1 ELSE 0 END) AS manual_msgs,
        COUNT(DISTINCT CASE WHEN cl.type='handoff' THEN cl.contact_jid END) AS handoffs
     FROM agents a
     LEFT JOIN conversation_logs cl ON cl.agent_id = a.id
     WHERE a.user_id = ?
     GROUP BY a.id
     ORDER BY leads DESC, a.id DESC"
);
$stmt->execute([$user_id]);
foreach ($stmt->fetchAll() ?: [] as $row) {
    $agent_metrics[] = $row;
    $total_agents++;
    if ($row['status'] === 'online') $online_agents++;
    $total_ai_msgs += (int)$row['ai_msgs'];
}
$total_handoffs = array_sum(array_column($agent_metrics, 'handoffs'));
$estimated_cost = number_format($total_ai_msgs * 0.02, 2, ',', '.');

// Query 2: total de leads do CRM (contacts é mais rápido que conversation_logs)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_leads = (int)$stmt->fetchColumn();
    if ($total_leads === 0) {
        $total_leads = array_sum(array_column($agent_metrics, 'leads'));
    }
} catch (PDOException $e) {
    $total_leads = array_sum(array_column($agent_metrics, 'leads'));
}

// Query 3: risk conversations (últimas 24h, sem handoff, ≥6 msgs inbound)
$risk_conversations = [];
if ($total_agents > 0) {
    // Usa subquery com agent_ids do usuário — evita JOIN cross-table sem índice user_id
    $agentIds = array_column($agent_metrics, 'id');
    $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT
            cl.agent_id,
            a.name AS agent_name,
            cl.contact_jid,
            SUM(CASE WHEN cl.incoming_msg IS NOT NULL AND cl.incoming_msg != '' THEN 1 ELSE 0 END) AS inbound_msgs,
            SUM(CASE WHEN cl.type='handoff' THEN 1 ELSE 0 END) AS handoff_events,
            MAX(cl.created_at) AS last_at
         FROM conversation_logs cl
         JOIN agents a ON a.id = cl.agent_id
         WHERE cl.agent_id IN ({$placeholders})
           AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         GROUP BY cl.agent_id, cl.contact_jid
         HAVING inbound_msgs >= 6 AND handoff_events = 0
         ORDER BY inbound_msgs DESC, last_at DESC
         LIMIT 12"
    );
    $stmt->execute($agentIds);
    $risk_conversations = $stmt->fetchAll() ?: [];
}

// Query 4: gráfico semanal
$chart_data = [];
$max_daily  = 1;
$dias_map = ta('dash_days_map');
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = $dias_map[date('D', strtotime($date))] ?? date('D', strtotime($date));
    $chart_data[$date] = ['label' => $label, 'count' => 0, 'percent' => 0];
}
if ($total_agents > 0) {
    $stmt = $pdo->prepare(
        "SELECT DATE(cl.created_at) AS dia, COUNT(*) AS total
         FROM conversation_logs cl
         WHERE cl.agent_id IN ({$placeholders})
           AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(cl.created_at)"
    );
    $stmt->execute($agentIds);
    foreach ($stmt->fetchAll() as $row) {
        $d = $row['dia'];
        if (isset($chart_data[$d])) {
            $chart_data[$d]['count'] = (int)$row['total'];
            if ($row['total'] > $max_daily) $max_daily = (int)$row['total'];
        }
    }
}

foreach ($chart_data as &$cd) {
    $cd['percent'] = round(($cd['count'] / $max_daily) * 100);
}
unset($cd);
$chart_data = array_values($chart_data);

$sub = null;
try {
    $stmt = $pdo->prepare("SELECT plan_id, status FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $sub = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[Auvvo] dashboard subscriptions: ' . $e->getMessage());
}
$_SESSION['user_plan'] = ($sub && $sub['status'] === 'active') ? t('plan_annual_active') : t('plan_annual');
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('dash_title') ?></title>
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
                    <h1 class="page-title"><?= t('dash_welcome', ['name' => htmlspecialchars($first_name)]) ?></h1>
                    <p class="text-muted"><?= t('dash_subtitle') ?></p>
                </div>
                <a href="agentes" class="btn btn-primary"><i class="ph-bold ph-plus"></i> <?= t('dash_new_agent') ?></a>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <a href="crm" style="text-decoration:none;color:inherit">
                <div class="app-card-glass stat-card" style="cursor:pointer;transition:box-shadow .2s" onmouseover="this.style.boxShadow='0 4px 24px rgba(20,184,166,.18)'" onmouseout="this.style.boxShadow=''">
                    <div class="stat-header">
                        <span><?= t('dash_leads') ?></span>
                        <div class="stat-icon icon-teal"><i class="ph-fill ph-address-book"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($total_leads, 0, ',', '.') ?></div>
                    <div class="stat-change text-muted">Ver CRM <i class="ph-bold ph-arrow-right" style="font-size:.75rem"></i></div>
                </div>
                </a>
                
                <div class="app-card-glass stat-card">
                    <div class="stat-header">
                        <span><?= t('dash_agents_cfg') ?></span>
                        <div class="stat-icon icon-purple"><i class="ph-fill ph-robot"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $total_agents; ?></div>
                    <div class="stat-change <?php echo $online_agents > 0 ? 'text-success' : 'text-muted'; ?>">
                        <?php if ($online_agents > 0): ?>
                            <i class="ph-bold ph-circle" style="color: #10B981;"></i> <?php echo $online_agents; ?> <?= t('online') ?>
                        <?php else: ?>
                            <i class="ph-bold ph-warning"></i> <?= t('dash_agents_waiting') ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="app-card-glass stat-card">
                    <div class="stat-header">
                        <span><?= t('dash_api_cost') ?></span>
                        <div class="stat-icon icon-green"><i class="ph-fill ph-coins"></i></div>
                    </div>
                    <div class="stat-value">R$ <?= $estimated_cost ?></div>
                    <div class="stat-change text-muted"><?= t('dash_ai_responses', ['count' => number_format($total_ai_msgs, 0, ',', '.')]) ?></div>
                </div>

                <?php if (strtolower(trim((string)($_ENV['WEBHOOK_AI_MODE'] ?? 'inline'))) === 'queue'): ?>
                <div class="app-card-glass stat-card">
                    <div class="stat-header">
                        <span>Fila IA</span>
                        <div class="stat-icon icon-teal"><i class="ph-fill ph-queue"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($queue_pending, 0, ',', '.') ?></div>
                    <div class="stat-change <?= $queue_failed > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?php if ($queue_failed > 0): ?>
                            <?= $queue_failed ?> falha(s) na fila
                        <?php else: ?>
                            Modo fila (worker)
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 32px;">
                
                <!-- Gráfico Principal -->
                <div class="app-card">
                    <h3 style="font-size: 1.125rem; margin-bottom: 16px;"><?= t('dash_chart_title') ?></h3>
                    <div class="chart-placeholder">
                        <?php foreach($chart_data as $cd): ?>
                        <div class="chart-bar" style="height: <?= max(5, $cd['percent']) ?>%;" title="<?= $cd['count'] ?> mensagens"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 8px; color: var(--text-muted); font-size: 0.75rem;">
                        <?php foreach($chart_data as $cd): ?>
                        <span><?= $cd['label'] ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Funil de Conversão -->
                <div class="app-card">
                    <h3 style="font-size: 1.125rem; margin-bottom: 24px;"><?= t('dash_funnel_title') ?></h3>
                    
                    <?php 
                        $pct_qualificado = $total_leads > 0 ? 100 : 0;
                        $pct_handoff = $total_leads > 0 ? round(($total_handoffs / $total_leads) * 100) : 0;
                    ?>
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.875rem; margin-bottom: 4px;">
                            <strong><?= t('dash_funnel_leads') ?></strong>
                            <span><?= number_format($total_leads, 0, ',', '.') ?></span>
                        </div>
                        <div class="progress-container"><div class="progress-bar" style="width: <?= $pct_qualificado ?>%; background: #E5E7EB;"></div></div>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.875rem; margin-bottom: 4px;">
                            <strong><?= t('dash_funnel_handoff') ?></strong>
                            <span><?= number_format($total_handoffs, 0, ',', '.') ?> (<?= $pct_handoff ?>%)</span>
                        </div>
                        <div class="progress-container"><div class="progress-bar" style="width: <?= $pct_handoff ?>%; background: #10B981;"></div></div>
                    </div>
                </div>

            </div>

            <?php if ($total_agents == 0): ?>
            <div class="app-card" style="text-align: center; padding: 48px; border: 2px dashed var(--border-subtle);">
                <i class="ph-bold ph-robot" style="font-size: 3rem; color: var(--text-muted); display: block; margin-bottom: 16px;"></i>
                <h3 style="font-size: 1.25rem; margin-bottom: 8px;"><?= t('dash_no_agent_title') ?></h3>
                <p class="text-muted" style="margin-bottom: 24px;"><?= t('dash_no_agent_desc') ?></p>
                <a href="agentes" class="btn btn-primary"><i class="ph-bold ph-plus"></i> <?= t('dash_no_agent_btn') ?></a>
            </div>
            <?php endif; ?>

            <?php if ($total_agents > 0): ?>
            <div style="display:grid;grid-template-columns: 2fr 1fr; gap:24px; margin-bottom: 32px;">
                <div class="app-card">
                    <h3 style="font-size: 1.125rem; margin-bottom: 16px;"><?= t('dash_perf_title') ?></h3>
                    <div class="text-muted" style="font-size:.875rem;margin-bottom:12px"><?= t('dash_perf_desc') ?></div>
                    <div class="app-table-wrapper">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th><?= t('dash_col_agent') ?></th>
                                    <th><?= t('dash_col_status') ?></th>
                                    <th><?= t('dash_col_leads') ?></th>
                                    <th><?= t('dash_col_ai_msgs') ?></th>
                                    <th><?= t('dash_col_human_msgs') ?></th>
                                    <th><?= t('dash_col_handoffs') ?></th>
                                    <th><?= t('dash_col_rate') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($agent_metrics as $m): 
                                $leads = (int)($m['leads'] ?? 0);
                                $handoffs = (int)($m['handoffs'] ?? 0);
                                $rate = $leads > 0 ? round(($handoffs / $leads) * 100) : 0;
                                $isOnline = ($m['status'] ?? '') === 'online';
                                $statusLabel = $isOnline ? t('dash_status_online') : t('dash_status_offline');
                            ?>
                                <tr>
                                    <td><strong><?=htmlspecialchars($m['name'])?></strong></td>
                                    <td>
                                        <span class="badge <?=$isOnline?'badge-success':'badge-gray'?>"><?=$statusLabel?></span>
                                    </td>
                                    <td><?=number_format($leads, 0, ',', '.')?></td>
                                    <td><?=number_format((int)($m['ai_msgs'] ?? 0), 0, ',', '.')?></td>
                                    <td><?=number_format((int)($m['manual_msgs'] ?? 0), 0, ',', '.')?></td>
                                    <td><?=number_format($handoffs, 0, ',', '.')?></td>
                                    <td><strong><?=$rate?>%</strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($agent_metrics)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:18px;color:var(--text-muted)"><?= t('dash_no_data') ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="app-card">
                    <h3 style="font-size: 1.125rem; margin-bottom: 16px;"><?= t('dash_risk_title') ?></h3>
                    <div class="text-muted" style="font-size:.875rem;margin-bottom:12px"><?= t('dash_risk_desc') ?></div>
                    <?php if (!empty($risk_conversations)): ?>
                        <div style="display:flex;flex-direction:column;gap:10px">
                        <?php foreach($risk_conversations as $r): 
                            $phone = explode('@', $r['contact_jid'])[0];
                        ?>
                            <div style="display:flex;justify-content:space-between;gap:10px;padding:12px;border:1px solid var(--border-subtle);border-radius:12px;background:rgba(0,0,0,0.01)">
                                <div>
                                    <strong style="display:block;font-size:.9rem">+<?=htmlspecialchars($phone)?></strong>
                                    <span class="text-muted" style="font-size:.75rem"><?= t('dash_col_agent') ?>: <?=htmlspecialchars($r['agent_name'])?></span>
                                </div>
                                <div style="text-align:right">
                                    <span class="badge" style="background:#FEF3C7;color:#92400E"><?=intval($r['inbound_msgs'])?> msgs</span>
                                    <div class="text-muted" style="font-size:.75rem;margin-top:6px"><?=date('d/m H:i', strtotime($r['last_at']))?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding:16px;border:1px dashed var(--border-subtle);border-radius:12px;color:var(--text-muted);font-size:.875rem;text-align:center">
                            <?= t('dash_risk_none') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

</body>
</html>
