<?php
/**
 * LLM + Evolution + GCal — webhook inline, worker CLI e nós de fluxo (converse/agent).
 */
declare(strict_types=1);

require_once __DIR__ . '/conversation_history.inc.php';
require_once __DIR__ . '/rate_limit.inc.php';

function auvvo_ai_reply_lock_peer(string $canonical_jid, string $peer_digits): string
{
    if ($peer_digits !== '') {
        return $peer_digits;
    }

    return 'h' . substr(md5($canonical_jid !== '' ? $canonical_jid : 'unknown'), 0, 12);
}

function auvvo_ai_reply_is_contact_paused(
    PDO $pdo,
    int $agentId,
    string $canonical_jid,
    string $remote_jid,
    string $peer_digits
): bool {
    try {
        if ($peer_digits !== '') {
            $stmt = $pdo->prepare(
                "SELECT ia_paused_until FROM conversation_states
                 WHERE agent_id=? AND (contact_jid=? OR contact_jid=? OR contact_jid=? OR contact_jid=?)
                 ORDER BY ia_paused_until DESC LIMIT 1"
            );
            $stmt->execute([
                $agentId,
                $canonical_jid,
                $remote_jid,
                $peer_digits . '@s.whatsapp.net',
                $peer_digits . '@c.us',
            ]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT ia_paused_until FROM conversation_states
                 WHERE agent_id=? AND (contact_jid=? OR contact_jid=?)
                 ORDER BY ia_paused_until DESC LIMIT 1"
            );
            $stmt->execute([$agentId, $canonical_jid, $remote_jid]);
        }
        $st = $stmt->fetch(PDO::FETCH_ASSOC);

        return $st && !empty($st['ia_paused_until']) && strtotime((string) $st['ia_paused_until']) > time();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @return array{allowed:bool, reason:string}
 */
function auvvo_ai_reply_prechecks(
    PDO $pdo,
    int $agentId,
    string $canonical_jid,
    string $remote_jid,
    string $peer_digits
): array {
    if (auvvo_ai_reply_is_contact_paused($pdo, $agentId, $canonical_jid, $remote_jid, $peer_digits)) {
        return ['allowed' => false, 'reason' => 'ia_paused'];
    }

    $lockPeer = auvvo_ai_reply_lock_peer($canonical_jid, $peer_digits);
    $rate = auvvo_rate_limit_allow_ai_reply($pdo, $agentId, $lockPeer);
    if (!$rate['allowed']) {
        return ['allowed' => false, 'reason' => $rate['reason']];
    }
    if (auvvo_anti_bot_loop_exceeded($pdo, $agentId, $canonical_jid, $remote_jid, $peer_digits)) {
        return ['allowed' => false, 'reason' => 'anti_bot_loop'];
    }

    return ['allowed' => true, 'reason' => ''];
}

function auvvo_run_ai_reply(
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
    if (!function_exists('getConversationHistory')) {
        if (!defined('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER')) {
            define('AUVVO_WEBHOOK_SKIP_HTTP_ROUTER', true);
        }
        require_once __DIR__ . '/webhook_evolution.php';
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    ignore_user_abort(true);

    $precheck = auvvo_ai_reply_prechecks(
        $pdo,
        (int) $agent['id'],
        $canonical_jid,
        $remote_jid,
        $peer_digits
    );
    if (!$precheck['allowed']) {
        if ($pending_log_id !== null && $pending_log_id > 0) {
            if ($precheck['reason'] === 'ia_paused') {
                try {
                    logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, 'IA pausada — aguardando humano', 'fallback');
                    deleteConversationLogById($pdo, (int) $pending_log_id, (int) $agent['id']);
                } catch (Throwable $e) {
                }
            } else {
                deleteConversationLogById($pdo, (int) $pending_log_id, (int) $agent['id']);
            }
        }
        if ($precheck['reason'] !== 'ia_paused' && in_array($precheck['reason'], ['rate_peer_minute', 'rate_agent_minute', 'min_interval'], true)) {
            throw new RuntimeException('rate:' . $precheck['reason']);
        }

        return;
    }

    if ($pending_log_id !== null && $pending_log_id > 0) {
        if (!auvvo_conversation_logs_claim_ai_reply($pdo, (int) $pending_log_id, (int) $agent['id'])) {
            auvvo_webhook_tracelog('ai_reply_skip_lost_claim', [
                'pending_log_id' => (int) $pending_log_id,
                'agent_id'       => (int) $agent['id'],
            ]);
            try {
                $st = $pdo->prepare(
                    'SELECT response_msg, ai_reply_claimed_at, ai_reply_completed_at
                     FROM conversation_logs WHERE id = ? AND agent_id = ? LIMIT 1'
                );
                $st->execute([(int) $pending_log_id, (int) $agent['id']]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    if (trim((string) ($row['response_msg'] ?? '')) !== '') {
                        return;
                    }
                    $claimedAt = $row['ai_reply_claimed_at'] ?? null;
                    if ($claimedAt !== null && $claimedAt !== '') {
                        $staleMin = max(5, min(45, (int) (($_ENV['AI_REPLY_CLAIM_STALE_MINUTES'] ?? '12') ?: 12)));
                        $orphanSec = max(60, $staleMin * 60);
                        $age = time() - strtotime((string) $claimedAt);
                        if ($age >= 0 && $age < $orphanSec) {
                            return;
                        }
                    }
                }
            } catch (PDOException $e) {
                return;
            }
            // Claim perdido mas log ainda pendente — evita "Processando…" eterno.
            require_once __DIR__ . '/auvvo_scheduling.inc.php';
            require_once __DIR__ . '/context_memory.inc.php';
            $orphanReply = auvvo_scheduling_fallback_reply(
                auvvo_contact_memory_get_resolved($pdo, (int) $agent['user_id'], $canonical_jid),
                $body
            );
            try {
                finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $orphanReply, 'fallback');
                sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $orphanReply);
            } catch (Throwable $orphanEx) {
                error_log('[Auvvo] orphan pending log finalize: ' . $orphanEx->getMessage());
            }

            return;
        }
    }

    $history = getConversationHistory($pdo, (int) $agent['id'], $canonical_jid, 10, $remote_jid, $peer_digits);

    $settings['google_calendar_connected'] = false;
    if (!empty($settings['google_calendar_enabled']) && GoogleCalendar::isConfigured($pdo, (int) $agent['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM google_calendar_tokens WHERE user_id=? LIMIT 1");
            $stmt->execute([(int) $agent['user_id']]);
            $settings['google_calendar_connected'] = (bool) $stmt->fetch();
        } catch (PDOException $e) {
            $settings['google_calendar_connected'] = false;
        }
    }
    // Prompt / GCal: calendar_id efetivo é sempre por user_id (settings + token), não variável global .env.
    if (!empty($settings['google_calendar_enabled'])
        && GoogleCalendar::isConfigured($pdo, (int) $agent['user_id'])
        && !empty($settings['google_calendar_connected'])) {
        $settings['google_calendar_calendar_id'] = GoogleCalendar::getEffectiveCalendarId($pdo, (int) $agent['user_id']);
    }

    $agentForPrompt = $agent;
    $agentForPrompt['_contact_jid'] = $canonical_jid;

    require_once __DIR__ . '/auvvo_scheduling.inc.php';
    $schedState = auvvo_scheduling_process_inbound($pdo, (int) $agent['user_id'], $canonical_jid, $body);

    if (!empty($schedState['changed']) && ($schedState['status'] ?? '') === 'ready') {
        require_once __DIR__ . '/context_memory.inc.php';
        $memReady = auvvo_contact_memory_get_resolved($pdo, (int) $agent['user_id'], $canonical_jid);
        $fastReply = auvvo_scheduling_fallback_reply($memReady, $body);
        if (trim($fastReply) !== '') {
            auvvo_webhook_tracelog('scheduling_fast_path', ['status' => 'ready']);
            try {
                if (!empty($pending_log_id)) {
                    finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $fastReply, 'ai');
                } else {
                    logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $fastReply, 'ai');
                }
                sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fastReply);
            } catch (Throwable $fastEx) {
                error_log('[Auvvo] scheduling fast path: ' . $fastEx->getMessage());
            }

            return;
        }
    }

    $builder       = new MasterPromptBuilder($pdo);
    $system_prompt = $builder->build($agentForPrompt, $settings);

    $resolvedModel = trim((string)($agent['model'] ?? ''));
    if ($resolvedModel === '') {
        $resolvedModel = defined('OPENROUTER_DEFAULT_MODEL') ? OPENROUTER_DEFAULT_MODEL : 'openrouter/openai/gpt-4o-mini';
    }

    $orUser = 'auvvo-' . substr(hash('sha256', (string)($agent['id'] ?? 0) . "\x1e" . $canonical_jid), 0, 40);
    $nativeToolCalls = [];
    $ai_response = callOpenAI(
        $llmApiKey,
        $resolvedModel,
        $system_prompt,
        $body,
        $history,
        intval($agent['max_tokens'] ?? 1000),
        floatval($agent['temperature'] ?? 0.7),
        $orUser,
        $nativeToolCalls
    );
    auvvo_webhook_tracelog('llm_result', [
        'ok'           => ($ai_response !== null && trim((string) $ai_response) !== '') || $nativeToolCalls !== [],
        'response_len' => $ai_response ? mb_strlen($ai_response) : 0,
        'tool_calls'   => count($nativeToolCalls),
        'model'        => $resolvedModel,
    ]);

    $hasLlmOutput = ($ai_response !== null && trim((string) $ai_response) !== '') || $nativeToolCalls !== [];
    if ($hasLlmOutput) {
        if (defined('IS_DEV') && IS_DEV) {
            file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . ' AI_RESPONSE SUCCESS: ' . substr((string) $ai_response, 0, 100) . "...\n", FILE_APPEND);
        }

        require_once __DIR__ . '/auvvo_brain_tools.inc.php';
        $ai_response = auvvo_brain_process_llm_response(
            $pdo,
            (int) $agent['user_id'],
            $agent,
            $settings,
            (string) ($ai_response ?? ''),
            $canonical_jid,
            null,
            $nativeToolCalls !== [] ? $nativeToolCalls : null
        );
        if (trim((string) $ai_response) === '') {
            auvvo_webhook_tracelog('ai_reply_empty_after_brain', []);
            require_once __DIR__ . '/auvvo_scheduling.inc.php';
            require_once __DIR__ . '/context_memory.inc.php';
            $mem = auvvo_contact_memory_get_resolved($pdo, (int) $agent['user_id'], $canonical_jid);
            $ai_response = auvvo_scheduling_fallback_reply($mem, $body);
            try {
                if (!empty($pending_log_id)) {
                    finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $ai_response, 'ai');
                } else {
                    logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $ai_response, 'ai');
                }
                sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $ai_response);
            } catch (Throwable $emptyEx) {
                error_log('[Auvvo Webhook] empty_after_brain fallback: ' . $emptyEx->getMessage());
            }

            return;
        }

        if (function_exists('auvvo_pdo_ping') && !auvvo_pdo_ping($pdo)) {
            auvvo_webhook_tracelog('pdo_ping_failed_post_llm', []);
            error_log('[Auvvo Webhook] PDO ping falhou logo após a LLM — risco de conexão perdida antes de gravar/enviar.');
        }

        try {
            if (!empty($pending_log_id)) {
                finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $ai_response, 'ai');
            } else {
                logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $ai_response, 'ai');
            }

            $delay = intval($agent['response_delay'] ?? 2);
            $elapsed = time() - ($GLOBALS['auvvo_worker_start_time'] ?? time());
            if ($elapsed < 10) {
                $remaining_delay = $delay - $elapsed;
                if ($remaining_delay > 0) {
                    sleep(min($remaining_delay, 5));
                }
            }

            if (!empty($agent['audio_enabled']) && !empty($settings['elevenlabs_key'])) {
                auvvo_webhook_tracelog('elevenlabs_start', ['voice_id' => $agent['audio_voice'] ?? 'pNInz6obpgDQGcFmaJcg']);
                $voice_id = !empty($agent['audio_voice']) ? $agent['audio_voice'] : 'pNInz6obpgDQGcFmaJcg';

                $el_ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voice_id}");
                curl_setopt_array($el_ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'xi-api-key: ' . $settings['elevenlabs_key'],
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'text'           => $ai_response,
                        'model_id'       => 'eleven_multilingual_v2',
                        'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
                    ]),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);

                $audio_data = curl_exec($el_ch);
                $el_status  = curl_getinfo($el_ch, CURLINFO_HTTP_CODE);
                curl_close($el_ch);

                if ($el_status == 200 && $audio_data) {
                    $base64_audio = 'data:audio/mpeg;base64,' . base64_encode($audio_data);
                    $res          = sendEvolutionAudio($agent['evolution_token'], $evolution_instance_label, $remote_jid, $base64_audio);
                    if (defined('IS_DEV') && IS_DEV) {
                        file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . ' SEND AUDIO RES: ' . json_encode($res) . "\n", FILE_APPEND);
                    }
                } else {
                    error_log("[Auvvo] Falha ElevenLabs: HTTP $el_status");
                    $res = sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $ai_response);
                    if (defined('IS_DEV') && IS_DEV) {
                        file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . ' SEND FALLBACK TEXT RES: ' . json_encode($res) . "\n", FILE_APPEND);
                    }
                }
            } else {
                $res = sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $ai_response);
                if (defined('IS_DEV') && IS_DEV) {
                    file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . ' SEND TEXT RES: ' . json_encode($res) . "\n", FILE_APPEND);
                }
            }
            auvvo_webhook_tracelog('evolution_send_done', [
                'remote_jid'       => $remote_jid,
                'send_ok'          => isset($res) && empty($res['error']),
                'send_code'        => $res['code'] ?? null,
                'send_msg_preview' => mb_substr((string) (($res['message'] ?? '') !== '' ? $res['message'] : json_encode($res)), 0, 400),
            ]);
        } catch (Throwable $e) {
            error_log('[Auvvo Webhook] Erro após LLM antes do envio Evolution: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            auvvo_webhook_tracelog('pipeline_post_llm_exception', [
                'class' => get_class($e),
                'msg'   => mb_substr($e->getMessage(), 0, 400),
            ]);
            try {
                $res = sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $ai_response);
                auvvo_webhook_tracelog('evolution_send_done', [
                    'remote_jid'       => $remote_jid,
                    'send_ok'          => empty($res['error']),
                    'send_code'        => $res['code'] ?? null,
                    'send_msg_preview' => 'recovery_after_pipeline_error',
                    'recovery_send'    => true,
                ]);
            } catch (Throwable $sendEx) {
                error_log('[Auvvo Webhook] Falha recuperação Evolution após erro pós-LLM: ' . $sendEx->getMessage());
                auvvo_webhook_tracelog('pipeline_post_llm_recovery_send_failed', [
                    'class' => get_class($sendEx),
                    'msg'   => mb_substr($sendEx->getMessage(), 0, 200),
                ]);
            }
            try {
                if (!empty($pending_log_id)) {
                    finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $ai_response, 'ai');
                } else {
                    logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $ai_response, 'ai');
                }
            } catch (Throwable $_recLog) {
                // empty
            }
        }
    } else {
        if (defined('IS_DEV') && IS_DEV) {
            file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . " AI_RESPONSE FAILED (NULL)\n", FILE_APPEND);
        }
        error_log('[Auvvo Webhook] Resposta nula da IA para agente #' . $agent['id'] . ' — fallback acionado.'
            . (!empty($GLOBALS['auvvo_last_llm_error']) ? ' Motivo: ' . $GLOBALS['auvvo_last_llm_error'] : ''));
        auvvo_webhook_tracelog('llm_null_fallback', [
            'agent_id' => (int) $agent['id'],
            'reason'   => (string) ($GLOBALS['auvvo_last_llm_error'] ?? ''),
        ]);

        $fallback = 'Entendi! Só um instante que vou acionar um especialista para te ajudar. 🙏';
        $sent     = false;
        $throttleDuplicate = false;
        $bucket   = '10m_' . date('Ymd_Hi', auvvo_unix_ts(floor(time() / 600) * 600));
        try {
            $st = $pdo->prepare(
                'INSERT INTO webhook_fallback_throttle (agent_id, contact_jid, bucket) VALUES (?, ?, ?)'
            );
            if ($st instanceof PDOStatement) {
                try {
                    $okExe = $st->execute([(int) $agent['id'], $canonical_jid, $bucket]);
                    if ($okExe) {
                        $sent = true;
                    } else {
                        $ei                = $st->errorInfo();
                        $throttleDuplicate = isset($ei[1]) && (int) $ei[1] === 1062;
                    }
                } catch (PDOException $insEx) {
                    $drv               = isset($insEx->errorInfo[1]) ? (int) $insEx->errorInfo[1] : 0;
                    $throttleDuplicate = ($drv === 1062 || str_contains(mb_strtolower($insEx->getMessage()), 'duplicate'));
                }
            }
        } catch (Throwable $e) {
            if ($e instanceof PDOException) {
                $drv               = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
                $throttleDuplicate = ($drv === 1062 || str_contains(mb_strtolower($e->getMessage()), 'duplicate'));
            }
            $sent = false;
        }

        if ($sent) {
            sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
            auvvo_webhook_tracelog('fallback_message_sent', []);
            if (!empty($pending_log_id)) {
                finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $fallback, 'fallback');
            } else {
                logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $fallback, 'fallback');
            }
        } elseif ($throttleDuplicate && !empty($pending_log_id)) {
            finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $fallback, 'fallback');
            auvvo_webhook_tracelog('fallback_throttled_logged_only', [
                'pending_log_id' => $pending_log_id,
                'bucket'         => $bucket,
            ]);
        } elseif ($throttleDuplicate && empty($pending_log_id)) {
            logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $fallback, 'fallback');
            auvvo_webhook_tracelog('fallback_throttled_logged_only', ['bucket' => $bucket]);
        } else {
            sendEvolutionMessage($agent['evolution_token'], $evolution_instance_label, $remote_jid, $fallback);
            auvvo_webhook_tracelog('fallback_message_sent_without_throttle', []);
            if (!empty($pending_log_id)) {
                finalizeConversationLog($pdo, (int) $pending_log_id, (int) $agent['id'], $fallback, 'fallback');
            } else {
                logConversation($pdo, (int) $agent['id'], $canonical_jid, $body, $fallback, 'fallback');
            }
        }
    }
}
