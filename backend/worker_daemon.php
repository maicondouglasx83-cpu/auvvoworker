<?php
/**
 * backend/worker_daemon.php
 *
 * LEGADO OPCIONAL: fila MySQL (`auvvo_ai_queue`) — não faz parte do fluxo principal atual
 * (`webhook_evolution.php` + pipeline inline / `webhook_async_worker`).
 * Mantido apenas como referência se quiser reativar cron + fila no futuro.
 *
 * Worker contínuo para processamento de fila de IA.
 * Em vez de depender do LiteSpeed e timeouts web, este script roda em CLI.
 * Ele pega as mensagens do banco de dados (tabela auvvo_ai_queue) e processa.
 *
 * Como rodar na Hostinger:
 * Crie um Cron Job rodando a cada 1 minuto com o comando:
 * php /home/seu_usuario/public_html/backend/worker_daemon.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli' && empty($_GET['force_cli'])) {
    http_response_code(403);
    exit('Este script deve ser rodado via CLI (Cron Job).');
}

// Impede múltiplas instâncias simultâneas usando flock
$lockFile = fopen(__FILE__, 'r');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    // Já existe uma instância rodando, então apenas sai silenciosamente
    exit("Worker já está rodando.\n");
}

ignore_user_abort(true);
set_time_limit(0);

define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
require_once __DIR__ . '/webhook_evolution.php';

echo "Auvvo AI Worker Daemon iniciado...\n";

// Cria a tabela da fila caso não exista
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auvvo_ai_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payload JSON NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    error_log("[Worker Daemon] Falha ao criar tabela: " . $e->getMessage());
    exit("Falha no DB.\n");
}

$loopCount = 0;
// Roda em loop para sempre (ou até 55 segundos se for via cron para renovar memória)
$startTime = time();

while (true) {
    // Se foi chamado por CRON, renova o processo a cada 55 segundos para evitar vazamento de memória
    if (time() - $startTime > 55) {
        break;
    }

    try {
        $pdo->beginTransaction();
        
        // Pega o job mais antigo pendente
        $stmt = $pdo->prepare("SELECT id, payload FROM auvvo_ai_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $stmt->execute();
        $jobRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$jobRow) {
            $pdo->commit();
            sleep(1);
            $loopCount++;
            continue;
        }

        // Marca como processando
        $jobId = (int) $jobRow['id'];
        $pdo->prepare("UPDATE auvvo_ai_queue SET status = 'processing' WHERE id = ?")->execute([$jobId]);
        $pdo->commit();

        $job = json_decode($jobRow['payload'], true);

        echo "Processando job #{$jobId}...\n";

        // ============================================================
        // Executa o Pipeline de IA (copiado do webhook_async_worker.php)
        // ============================================================
        $GLOBALS['auvvo_webhook_trace_id']  = $job['rid'] ?? bin2hex(random_bytes(4));
        $GLOBALS['auvvo_worker_start_time'] = time();

        $agentId                = (int) ($job['agent_id'] ?? 0);
        $pendingLogId           = (int) ($job['pending_log_id'] ?? 0);
        $canonicalJid           = (string) ($job['canonical_jid'] ?? '');
        $remoteJid              = (string) ($job['remote_jid'] ?? '');
        $peerDigits             = (string) ($job['peer_digits'] ?? '');
        $body                   = (string) ($job['body'] ?? '');
        $evolutionInstanceLabel = (string) ($job['evolution_instance_label'] ?? '');
        $dedupeKey              = (string) ($job['dedupe_key'] ?? '');

        // Carrega agente
        $stmtAgent = $pdo->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
        $stmtAgent->execute([$agentId]);
        $agent = $stmtAgent->fetch(PDO::FETCH_ASSOC);

        if (!$agent) {
            $pdo->prepare("UPDATE auvvo_ai_queue SET status = 'failed' WHERE id = ?")->execute([$jobId]);
            continue;
        }

        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connectionId = (int) ($job['whatsapp_connection_id'] ?? $job['connection_id'] ?? 0);
        $agent = auvvo_whatsapp_attach_connection_for_agent($pdo, (int) $agent['user_id'], $agent, $connectionId > 0 ? $connectionId : null);

        // Carrega settings
        $stmtSet = $pdo->prepare("SELECT * FROM settings WHERE user_id = ? LIMIT 1");
        $stmtSet->execute([$agent['user_id']]);
        $settings = $stmtSet->fetch(PDO::FETCH_ASSOC) ?: [];

        $llmApiKey = '';
        if (trim((string)($agent['llm_api_key'] ?? '')) !== '') {
            $llmApiKey = trim($agent['llm_api_key']);
        } elseif (trim((string)($settings['openai_api_key'] ?? '')) !== '') {
            $llmApiKey = trim($settings['openai_api_key']);
        } else {
            $llmApiKey = trim((string)($_ENV['OPENROUTER_API_KEY'] ?? ''));
        }

        // Executa o pipeline de IA bloqueante
        auvvo_webhook_run_ai_pipeline(
            $pdo,
            $agent,
            $settings,
            $llmApiKey,
            $canonicalJid,
            $remoteJid,
            $peerDigits,
            $body,
            $pendingLogId,
            $evolutionInstanceLabel
        );

        if ($dedupeKey !== '') {
            auvvo_evolution_release_lock($pdo, 'evo_in_' . $dedupeKey);
        }

        // Marca como concluído
        $pdo->prepare("UPDATE auvvo_ai_queue SET status = 'completed' WHERE id = ?")->execute([$jobId]);
        echo "Job #{$jobId} concluído com sucesso.\n";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("[Worker Daemon] Exceção no job #{$jobId}: " . $e->getMessage());
        $pdo->prepare("UPDATE auvvo_ai_queue SET status = 'failed' WHERE id = ?")->execute([$jobId]);
    }
}

echo "Worker encerrado normalmente após 55 segundos.\n";
