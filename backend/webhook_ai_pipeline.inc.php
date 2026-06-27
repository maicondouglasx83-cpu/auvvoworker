<?php
/**
 * Pipeline LLM + envio WhatsApp — delega para ai_reply.inc.php (scheduling + fallbacks unificados).
 */
declare(strict_types=1);

require_once __DIR__ . '/ai_reply.inc.php';

function auvvo_webhook_run_ai_pipeline(
    PDO $pdo,
    array $agent,
    array $settings,
    string $llmApiKey,
    string $canonical_jid,
    string $remote_jid,
    string $peer_digits,
    string $body,
    ?int $pending_log_id,
    string $evolution_instance_label
): void {
    auvvo_run_ai_reply(
        $pdo,
        $agent,
        $settings,
        $llmApiKey,
        $canonical_jid,
        $remote_jid,
        $peer_digits,
        $body,
        $pending_log_id,
        $evolution_instance_label
    );
}
