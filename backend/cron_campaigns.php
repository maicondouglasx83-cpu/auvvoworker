<?php
/**
 * cron_campaigns.php
 * Motor de disparo de campanhas em massa do Auvvo SaaS.
 *
 * USO: Configure um cronjob no servidor para rodar a cada 1 minuto:
 * * * * * * /usr/bin/php /caminho/absoluto/para/backend/cron_campaigns.php
 *
 * Preferir auvvo-worker (campaignWorker.js) em produção; este cron é fallback legado.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/EvolutionAPI.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/ai_queue.inc.php';

auvvo_run_migrations($pdo);

if (auvvo_worker_ai_alive($pdo)) {
    exit(0);
}

set_time_limit(300);

$batch_size = 20;

try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COALESCE(wc.evolution_token, a.evolution_token) AS evolution_token,
               COALESCE(wc.evolution_instance, a.evolution_instance) AS evolution_instance,
               s.evolution_url,
               s.evolution_key
        FROM campaigns c
        LEFT JOIN whatsapp_connections wc ON wc.id = c.whatsapp_connection_id AND wc.user_id = c.user_id
        LEFT JOIN agents a ON c.agent_id = a.id
        JOIN settings s ON c.user_id = s.user_id
        WHERE c.status IN ('scheduled', 'running')
        AND (c.scheduled_at IS NULL OR c.scheduled_at <= NOW())
    ");
    $stmt->execute();
    $campaigns = $stmt->fetchAll();

    foreach ($campaigns as $camp) {
        if (empty($camp['evolution_token'])) {
            error_log("[Cron] Campanha #{$camp['id']} sem conexão WhatsApp configurada.");
            $pdo->prepare("UPDATE campaigns SET status = 'paused' WHERE id = ?")->execute([$camp['id']]);
            continue;
        }

        if ($camp['status'] === 'scheduled') {
            $pdo->prepare("UPDATE campaigns SET status = 'running' WHERE id = ?")->execute([$camp['id']]);
        }

        $csv_path = __DIR__ . '/../uploads/campaigns/' . $camp['csv_file'];

        if (!file_exists($csv_path)) {
            error_log("[Cron] Arquivo CSV não encontrado para campanha #{$camp['id']}");
            $pdo->prepare("UPDATE campaigns SET status = 'paused' WHERE id = ?")->execute([$camp['id']]);
            continue;
        }

        $api = new EvolutionAPI($camp['evolution_url'], $camp['evolution_key'] ?? '');

        $lines = file($csv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (empty($lines)) {
            $pdo->prepare("UPDATE campaigns SET status = 'completed' WHERE id = ?")->execute([$camp['id']]);
            continue;
        }

        array_shift($lines);

        $sent = intval($camp['sent_count']);
        $total = count($lines);
        $processed_in_this_run = 0;

        for ($i = $sent; $i < $total && $processed_in_this_run < $batch_size; $i++) {
            $row = str_getcsv($lines[$i]);
            $raw_phone = $row[0] ?? '';
            $phone = preg_replace('/\D/', '', $raw_phone);

            if (strlen($phone) >= 10) {
                $msg = $camp['message'];
                if (isset($row[1]) && trim($row[1]) !== '') {
                    $msg = str_ireplace(['{{nome}}', '{{name}}'], trim($row[1]), $msg);
                }

                $api->sendText($camp['evolution_token'], $phone, $msg);
                sleep(rand(2, 5));
            }

            $sent++;
            $processed_in_this_run++;

            $pdo->prepare("UPDATE campaigns SET sent_count = ? WHERE id = ?")->execute([$sent, $camp['id']]);
        }

        if ($sent >= $total) {
            $pdo->prepare("UPDATE campaigns SET status = 'completed' WHERE id = ?")->execute([$camp['id']]);
        }
    }

    echo "Cron executado com sucesso: processou os lotes de campanhas ativas.\n";
} catch (Exception $e) {
    error_log("[Cron Campanhas] Erro Crítico: " . $e->getMessage());
    echo "Erro Crítico: " . $e->getMessage() . "\n";
}
