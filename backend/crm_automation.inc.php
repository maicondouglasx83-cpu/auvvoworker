<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_automation_dedupe.inc.php';
require_once __DIR__ . '/evolution_resolve.inc.php';
require_once __DIR__ . '/webhook_engine.inc.php';
require_once __DIR__ . '/crm_automation_motor.inc.php';
require_once __DIR__ . '/crm_automation_runs.inc.php';

/**
 * Condições do fluxo/regra (tags, estágio, agente, palavra-chave, A/B).
 *
 * @param array<string, mixed> $context message_body, trigger_type, trigger_agent_id
 */
function auvvo_crm_contact_passes_conditions(array $config, array $contact, array $context = [], ?PDO $pdo = null): bool
{
    if ($pdo === null) {
        $msgBody = trim((string) ($context['message_body'] ?? $contact['_ctx_message'] ?? ''));
    } else {
        $msgBody = auvvo_crm_condition_message_corpus($pdo, $contact, $context);
    }
    $tags = $contact['tags'] ?? [];
    if (is_string($tags)) {
        $tags = json_decode($tags, true);
    }
    if (!is_array($tags)) {
        $tags = [];
    }

    $requireTag = trim((string) ($config['require_tag'] ?? ''));
    if ($requireTag !== '' && !in_array($requireTag, $tags, true)) {
        return false;
    }

    $excludeTag = trim((string) ($config['exclude_tag'] ?? ''));
    if ($excludeTag !== '' && in_array($excludeTag, $tags, true)) {
        return false;
    }

    $stageIs = trim((string) ($config['stage_is'] ?? ''));
    $stageNot = trim((string) ($config['stage_not'] ?? ''));
    $condPipelineId = (int) ($config['pipeline_id'] ?? 0);
    if ($condPipelineId > 0 && ($stageIs !== '' || $stageNot !== '')) {
        $contactPipelineId = (int) ($contact['pipeline_id'] ?? 0);
        if ($contactPipelineId !== $condPipelineId) {
            return false;
        }
    }

    if ($stageIs !== '' && (string) ($contact['stage'] ?? '') !== $stageIs) {
        return false;
    }

    if ($stageNot !== '' && (string) ($contact['stage'] ?? '') === $stageNot) {
        return false;
    }

    $condAgentId = (int) ($config['agent_id'] ?? 0);
    if ($condAgentId > 0 && (int) ($contact['agent_id'] ?? 0) !== $condAgentId) {
        return false;
    }

    if (!empty($config['agent_unassigned']) && (int) ($contact['agent_id'] ?? 0) > 0) {
        return false;
    }

    if (!empty($config['require_email']) && trim((string) ($contact['email'] ?? '')) === '') {
        return false;
    }

    if (!empty($config['require_phone']) && trim((string) ($contact['phone'] ?? '')) === '') {
        return false;
    }

    $kw = trim((string) ($config['keyword_contains'] ?? ''));
    if ($kw !== '') {
        if ($msgBody === '' || !auvvo_crm_text_matches_any_keyword($msgBody, $kw)) {
            return false;
        }
    }
    $kwNot = trim((string) ($config['keyword_not_contains'] ?? ''));
    if ($kwNot !== '' && $msgBody !== '' && auvvo_crm_text_matches_any_keyword($msgBody, $kwNot)) {
        return false;
    }

    if (!empty($config['business_hours_only']) && !auvvo_crm_is_business_hours($config)) {
        return false;
    }
    if (!empty($config['outside_business_hours']) && auvvo_crm_is_business_hours($config)) {
        return false;
    }

    $abChance = (int) ($config['ab_chance'] ?? 100);
    if ($abChance < 100 && $abChance > 0) {
        if (random_int(1, 100) > $abChance) {
            return false;
        }
    }

    $memKey = trim((string) ($config['memory_key'] ?? ''));
    if ($memKey !== '') {
        $mem = $contact['memory_json'] ?? [];
        if (is_string($mem)) {
            $mem = json_decode($mem, true);
        }
        if (!is_array($mem)) {
            $mem = [];
        }
        $expected = trim((string) ($config['memory_value'] ?? ''));
        if ($expected !== '') {
            if (!isset($mem[$memKey]) || trim((string) $mem[$memKey]) !== $expected) {
                return false;
            }
        } elseif (!isset($mem[$memKey]) || trim((string) $mem[$memKey]) === '') {
            return false;
        }
    }

    return true;
}

/**
 * Palavras separadas por vírgula — basta uma coincidir (OR).
 */
function auvvo_crm_text_matches_any_keyword(string $haystack, string $keywordsCsv): bool
{
    $parts = preg_split('/[,;]+/u', $keywordsCsv) ?: [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && mb_stripos($haystack, $p) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Horário comercial configurável no nó (padrão seg–sex 08:00–18:00 America/Sao_Paulo).
 *
 * @param array<string, mixed> $config bh_start, bh_end, bh_weekdays, bh_timezone
 */
function auvvo_crm_is_business_hours(array $config): bool
{
    $tzName = trim((string) ($config['bh_timezone'] ?? 'America/Sao_Paulo'));
    try {
        $tz = new DateTimeZone($tzName !== '' ? $tzName : 'America/Sao_Paulo');
    } catch (Exception $e) {
        $tz = new DateTimeZone('America/Sao_Paulo');
    }
    $now = new DateTime('now', $tz);
    $dow = (int) $now->format('N');

    $days = $config['bh_weekdays'] ?? '1,2,3,4,5';
    if (is_array($days)) {
        $allowed = array_map('intval', $days);
    } else {
        $allowed = array_map('intval', preg_split('/[,;]+/', (string) $days) ?: []);
    }
    $allowed = array_filter($allowed, static fn ($d) => $d >= 1 && $d <= 7);
    if ($allowed === []) {
        $allowed = [1, 2, 3, 4, 5];
    }
    if (!in_array($dow, $allowed, true)) {
        return false;
    }

    $start = trim((string) ($config['bh_start'] ?? '08:00'));
    $end = trim((string) ($config['bh_end'] ?? '18:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $start)) {
        $start = '08:00';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $end)) {
        $end = '18:00';
    }

    $t = $now->format('H:i');

    return $t >= $start && $t < $end;
}

/**
 * Variáveis para templates de mensagem e HTTP.
 *
 * @return array<string, string>
 */
function auvvo_crm_message_vars(PDO $pdo, array $contact, array $context = []): array
{
    $vars = [
        'nome'      => (string) ($contact['name'] ?? ''),
        'telefone'  => (string) ($contact['phone'] ?? ''),
        'email'     => (string) ($contact['email'] ?? ''),
        'empresa'   => (string) ($contact['company'] ?? ''),
        'estagio'   => (string) ($contact['stage'] ?? ''),
        'jid'       => (string) ($contact['jid'] ?? ''),
        'tag'       => '',
    ];

    $sessionVars = auvvo_crm_session_message_vars($pdo, $contact, $context);
    $vars['mensagem'] = $sessionVars['mensagem'];
    $vars['mensagens_hoje'] = $sessionVars['mensagens_hoje'];
    $vars['sessao'] = $sessionVars['sessao'];
    $vars['ultima_sessao'] = $sessionVars['ultima_sessao'];

    $tags = $contact['tags'] ?? [];
    if (is_string($tags)) {
        $tags = json_decode($tags, true);
    }
    if (is_array($tags) && $tags !== []) {
        $vars['tag'] = (string) $tags[0];
        $vars['tags'] = implode(', ', $tags);
    } else {
        $vars['tags'] = '';
    }

    $agentId = (int) ($contact['agent_id'] ?? $context['trigger_agent_id'] ?? 0);
    $vars['agente'] = '';
    $vars['agente_id'] = $agentId > 0 ? (string) $agentId : '';
    if ($agentId > 0) {
        try {
            $st = $pdo->prepare('SELECT name FROM agents WHERE id = ? LIMIT 1');
            $st->execute([$agentId]);
            $vars['agente'] = (string) ($st->fetchColumn() ?: '');
        } catch (PDOException $e) {
        }
    }

    $mem = $contact['memory_json'] ?? [];
    if (is_string($mem)) {
        $mem = json_decode($mem, true);
    }
    if (is_array($mem)) {
        foreach ($mem as $mk => $mv) {
            if (is_string($mk) && (is_scalar($mv) || $mv === null)) {
                $vars['memoria.' . $mk] = (string) ($mv ?? '');
                $vars['memory.' . $mk] = (string) ($mv ?? '');
            }
        }
    }

    $cf = $contact['custom_fields'] ?? [];
    if (is_string($cf)) {
        $cf = json_decode($cf, true);
    }
    if (is_array($cf)) {
        foreach ($cf as $ck => $cv) {
            if (is_string($ck) && is_scalar($cv)) {
                $vars['campo.' . $ck] = (string) $cv;
            }
        }
    }

    if (!empty($context['trigger_type'])) {
        $vars['gatilho'] = (string) $context['trigger_type'];
        $vars['gatilho_valor'] = (string) ($context['trigger_value'] ?? '');
    }

    return $vars;
}

function auvvo_crm_render_message(PDO $pdo, string $msg, array $contact, array $context = []): string
{
    if ($msg === '') {
        return '';
    }
    require_once __DIR__ . '/webhook_engine.inc.php';

    return auvvo_render_template($msg, auvvo_crm_message_vars($pdo, $contact, $context));
}

/**
 * Envia texto fixo no WhatsApp (Evolution). Retorna ok/erro para logs do fluxo.
 *
 * @return array{ok:bool,error:string,sent:string}
 */
function auvvo_crm_send_whatsapp(
    PDO $pdo,
    int $userId,
    array $config,
    array $contact,
    array $context = []
): array {
    $msg = trim((string) ($config['message'] ?? ''));
    if ($msg === '') {
        return ['ok' => false, 'error' => 'Mensagem vazia', 'sent' => ''];
    }
    if (empty($contact['jid'])) {
        return ['ok' => false, 'error' => 'Contato sem JID WhatsApp', 'sent' => ''];
    }

    require_once __DIR__ . '/EvolutionAPI.php';
    require_once __DIR__ . '/whatsapp_connections.inc.php';
    require_once __DIR__ . '/crm_automation_motor.inc.php';

    $agentId = (int) ($config['agent_id'] ?? $contact['agent_id'] ?? 0);
    $connectionId = auvvo_crm_resolve_whatsapp_connection_id(
        $pdo,
        $userId,
        (int) ($config['connection_id'] ?? 0),
        $agentId,
        $context,
        $contact
    );
    if ($connectionId <= 0) {
        return ['ok' => false, 'error' => 'Nenhuma conexão WhatsApp configurada', 'sent' => ''];
    }

    $token = auvvo_whatsapp_resolve_evolution_token(
        $pdo,
        $userId,
        $connectionId,
        $agentId > 0 ? $agentId : null
    );
    if (!$token) {
        return ['ok' => false, 'error' => 'Token Evolution ausente na conexão #' . $connectionId, 'sent' => ''];
    }

    $digits = preg_replace('/\D/', '', explode('@', (string) $contact['jid'])[0] ?? '');
    if ($digits === '') {
        return ['ok' => false, 'error' => 'Telefone inválido no JID do contato', 'sent' => ''];
    }

    $rendered = auvvo_crm_render_message($pdo, $msg, $contact, $context);
    if ($rendered === '') {
        return ['ok' => false, 'error' => 'Mensagem renderizada vazia', 'sent' => ''];
    }

    try {
        $uid = $agentId > 0 ? auvvo_evolution_user_id_for_agent($pdo, $agentId) : $userId;
        $cred = auvvo_evolution_credentials($pdo, $uid);
        $api = new EvolutionAPI($cred['url'], $cred['key']);
        $api->sendText($token, $digits, $rendered);

        return ['ok' => true, 'error' => '', 'sent' => $rendered];
    } catch (Throwable $e) {
        error_log('[Auvvo] automation send_whatsapp: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Falha ao enviar: ' . $e->getMessage(), 'sent' => ''];
    }
}

/**
 * Executa uma ação de automação imediatamente.
 */
function auvvo_crm_execute_action(
    PDO $pdo,
    int $userId,
    string $actionType,
    array $config,
    array $contact,
    string $triggerType = '',
    string $triggerValue = '',
    array $context = []
): void {
    if ($context !== []) {
        $context = array_merge([
            'trigger_type'  => $triggerType,
            'trigger_value' => $triggerValue,
        ], $context);
    } else {
        $context = [
            'trigger_type'  => $triggerType,
            'trigger_value' => $triggerValue,
        ];
    }

    if (auvvo_automation_is_simulate($context)) {
        $detail = auvvo_automation_simulate_action_detail($actionType, $config, $contact, $pdo, $userId, $context);
        $nodeClass = trim((string) ($config['_node_class'] ?? ''));
        if ($nodeClass === '') {
            $nodeClass = $actionType === 'send_whatsapp' ? 'flow_message' : 'flow_action';
        }
        auvvo_automation_run_log_step(
            $pdo,
            $context,
            (string) ($config['_node_id'] ?? 'action'),
            $nodeClass,
            (string) ($config['_node_label'] ?? $actionType),
            'simulated',
            $detail
        );

        return;
    }

    switch ($actionType) {
        case 'move_stage':
            $stage = trim((string) ($config['stage'] ?? ''));
            if ($stage === '' || empty($contact['id'])) {
                break;
            }
            $pipelineId = (int) ($config['pipeline_id'] ?? 0);
            require_once __DIR__ . '/CrmPipelines.php';
            require_once __DIR__ . '/Contacts.php';
            $pipes = new CrmPipelines($pdo);
            if ($pipelineId <= 0) {
                $pipelineId = (int) ($contact['pipeline_id'] ?? 0);
            }
            if ($pipelineId <= 0) {
                $pipelineId = $pipes->defaultPipelineId($userId);
            }
            $contactRef = $contact;
            auvvo_crm_sync_contact_to_pipeline($pdo, $userId, $contactRef, $pipelineId);
            $pipes->syncContactStage((int) $contact['id'], $pipelineId, $stage);
            $fresh = (new Contacts($pdo))->get($userId, (int) $contact['id']);
            if ($fresh) {
                $contact = $fresh;
            }
            break;

        case 'assign_agent':
            $agentId = (int) ($config['agent_id'] ?? 0);
            if ($agentId > 0 && !empty($contact['id'])) {
                $chk = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? LIMIT 1');
                $chk->execute([$agentId, $userId]);
                if (!$chk->fetchColumn()) {
                    break;
                }
                $pdo->prepare('UPDATE contacts SET agent_id = ? WHERE id = ? AND user_id = ?')
                    ->execute([$agentId, (int) $contact['id'], $userId]);
            }
            break;

        case 'add_tag':
            $tag = (string) ($config['tag'] ?? '');
            if ($tag !== '' && !empty($contact['id'])) {
                require_once __DIR__ . '/Contacts.php';
                (new Contacts($pdo))->addTag($userId, (int) $contact['id'], $tag, false);
            }
            break;

        case 'remove_tag':
            $tag = (string) ($config['tag'] ?? '');
            if ($tag !== '' && !empty($contact['id'])) {
                require_once __DIR__ . '/Contacts.php';
                (new Contacts($pdo))->removeTag($userId, (int) $contact['id'], $tag);
            }
            break;

        case 'pause_ai':
            $pauseAgentId = (int) ($config['agent_id'] ?? $contact['agent_id'] ?? 0);
            if ($pauseAgentId > 0 && !empty($contact['jid'])) {
                $mins = max(15, (int) ($config['minutes'] ?? 60));
                try {
                    $pdo->prepare(
                        'INSERT INTO conversation_states (agent_id, contact_jid, ia_paused_until)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
                         ON DUPLICATE KEY UPDATE ia_paused_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)'
                    )->execute([
                        $pauseAgentId,
                        (string) $contact['jid'],
                        $mins,
                        $mins,
                    ]);
                } catch (PDOException $e) {
                }
            }
            break;

        case 'resume_ai':
            $resumeAgentId = (int) ($config['agent_id'] ?? $contact['agent_id'] ?? 0);
            if ($resumeAgentId > 0 && !empty($contact['jid'])) {
                try {
                    $pdo->prepare(
                        'UPDATE conversation_states SET ia_paused_until = NULL WHERE agent_id = ? AND contact_jid = ?'
                    )->execute([$resumeAgentId, (string) $contact['jid']]);
                } catch (PDOException $e) {
                }
            }
            break;

        case 'send_whatsapp':
            auvvo_crm_send_whatsapp($pdo, $userId, $config, $contact, $context);
            break;

        case 'invoke_agent':
            $targetAgent = (int) ($config['agent_id'] ?? 0);
            if ($targetAgent <= 0 || empty($contact['id'])) {
                break;
            }
            $agOwn = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? LIMIT 1');
            $agOwn->execute([$targetAgent, $userId]);
            if (!$agOwn->fetchColumn()) {
                break;
            }
            if (!empty($config['switch_agent'])) {
                $pdo->prepare('UPDATE contacts SET agent_id = ? WHERE id = ? AND user_id = ?')
                    ->execute([$targetAgent, (int) $contact['id'], $userId]);
                $contact['agent_id'] = $targetAgent;
            }
            $intro = trim((string) ($config['message'] ?? ''));
            if ($intro !== '' && !empty($contact['jid'])) {
                try {
                    require_once __DIR__ . '/EvolutionAPI.php';
                    require_once __DIR__ . '/whatsapp_connections.inc.php';
                    $connId = (int) ($config['connection_id'] ?? $context['whatsapp_connection_id'] ?? 0);
                    $token = auvvo_whatsapp_resolve_evolution_token($pdo, $userId, $connId > 0 ? $connId : null, $targetAgent);
                    if ($token) {
                        $digits = preg_replace('/\D/', '', explode('@', (string) $contact['jid'])[0] ?? '');
                        if ($digits !== '') {
                            $uid = auvvo_evolution_user_id_for_agent($pdo, $targetAgent);
                            $cred = auvvo_evolution_credentials($pdo, $uid);
                            $api = new EvolutionAPI($cred['url'], $cred['key']);
                            $api->sendText($token, $digits, auvvo_crm_render_message($pdo, $intro, $contact, $context));
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[Auvvo] invoke_agent: ' . $e->getMessage());
                }
            }
            break;

        case 'call_webhook':
            $whId = (int) ($config['webhook_id'] ?? 0);
            if ($whId <= 0) {
                break;
            }
            $ctx = [
                'contact' => [
                    'id'    => $contact['id'] ?? 0,
                    'name'  => $contact['name'] ?? '',
                    'email' => $contact['email'] ?? '',
                    'phone' => $contact['phone'] ?? '',
                    'stage' => $contact['stage'] ?? '',
                    'jid'   => $contact['jid'] ?? '',
                ],
                'trigger' => ['type' => $triggerType, 'value' => $triggerValue],
            ];
            auvvo_webhook_call_outbound($pdo, $userId, $whId, $ctx);
            break;

        case 'set_memory':
            if (empty($contact['jid'])) {
                break;
            }
            require_once __DIR__ . '/context_memory.inc.php';
            $key = trim((string) ($config['key'] ?? ''));
            $val = trim((string) ($config['value'] ?? ''));
            if ($key !== '') {
                auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], [$key => $val]);
                $contact['memory_json'] = auvvo_contact_memory_get($pdo, $userId, (string) $contact['jid']);
            }
            break;

        case 'brain_mission':
            if (empty($contact['jid'])) {
                break;
            }
            require_once __DIR__ . '/context_memory.inc.php';
            $mission = trim((string) ($config['mission'] ?? $config['text'] ?? $config['message'] ?? ''));
            if ($mission !== '') {
                auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], ['_brain_mission' => $mission]);
                $contact['memory_json'] = auvvo_contact_memory_get($pdo, $userId, (string) $contact['jid']);
            }
            break;

        case 'clear_brain_mission':
            if (empty($contact['jid'])) {
                break;
            }
            require_once __DIR__ . '/context_memory.inc.php';
            auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], ['_brain_mission' => null]);
            $contact['memory_json'] = auvvo_contact_memory_get($pdo, $userId, (string) $contact['jid']);
            break;

        case 'google_sheets_append':
            require_once __DIR__ . '/GoogleSheets.php';
            try {
                $cols = $config['columns'] ?? null;
                if (is_array($cols) && $cols !== []) {
                    $row = $cols;
                } else {
                    $row = [
                        (string) ($contact['name'] ?? ''),
                        (string) ($contact['phone'] ?? ''),
                        (string) ($contact['email'] ?? ''),
                        (string) ($contact['stage'] ?? ''),
                        (string) ($contact['company'] ?? ''),
                        date('Y-m-d H:i:s'),
                        $triggerType . ':' . $triggerValue,
                    ];
                }
                GoogleSheets::appendRow(
                    $pdo,
                    $userId,
                    $row,
                    isset($config['spreadsheet_id']) ? (string) $config['spreadsheet_id'] : null,
                    isset($config['sheet_name']) ? (string) $config['sheet_name'] : null
                );
            } catch (Throwable $e) {
                error_log('[Auvvo] google_sheets_append: ' . $e->getMessage());
            }
            break;

        case 'http_preset':
            $presetId = (int) ($config['preset_id'] ?? 0);
            if ($presetId <= 0) {
                break;
            }
            try {
                auvvo_run_migrations($pdo);
                $st = $pdo->prepare('SELECT * FROM integration_http_presets WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
                $st->execute([$presetId, $userId]);
                $preset = $st->fetch(PDO::FETCH_ASSOC);
                if (!$preset) {
                    break;
                }
                $flat = auvvo_crm_message_vars($pdo, $contact, $context);
                $ctx = array_merge($flat, [
                    'contact' => $contact,
                    'trigger' => ['type' => $triggerType, 'value' => $triggerValue],
                ]);
                $bodyStr = auvvo_render_template((string) ($preset['body_template'] ?? '{}'), $ctx);
                $body = json_decode($bodyStr, true);
                if (!is_array($body)) {
                    $body = ['contact' => $contact];
                }
                $headers = ['Content-Type: application/json'];
                $hdrJson = json_decode((string) ($preset['headers_json'] ?? '{}'), true);
                if (is_array($hdrJson)) {
                    foreach ($hdrJson as $k => $v) {
                        if (is_string($k) && is_scalar($v)) {
                            $headers[] = $k . ': ' . $v;
                        }
                    }
                }
                require_once __DIR__ . '/http_ssrf.inc.php';
                $targetUrl = (string) ($preset['target_url'] ?? '');
                $urlCheck = auvvo_http_url_validate($targetUrl);
                if (!$urlCheck['ok']) {
                    error_log('[Auvvo] http_preset URL bloqueada: ' . ($urlCheck['error'] ?? ''));
                    break;
                }
                $ch = curl_init((string) $urlCheck['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 25,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_CUSTOMREQUEST  => strtoupper((string) ($preset['http_method'] ?? 'POST')),
                    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                auvvo_webhook_log_call($pdo, $userId, 'outbound', $presetId, $code, $body, $resp ?: '', $code >= 200 && $code < 300 ? 'ok' : 'error');
            } catch (Throwable $e) {
                error_log('[Auvvo] http_preset: ' . $e->getMessage());
            }
            break;
    }
}

/**
 * Agenda passos (delay acumulado em minutos).
 *
 * @param list<array{delay_minutes?:int, action_type:string, ...}> $steps
 */
function auvvo_crm_enqueue_steps(
    PDO $pdo,
    int $userId,
    ?int $automationId,
    array $contact,
    string $triggerType,
    string $triggerValue,
    array $steps,
    array $context = []
): void {
    if (empty($contact['id'])) {
        return;
    }
    auvvo_run_migrations($pdo);
    $cumulative = 0;
    $idx = 0;
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $actionType = trim((string) ($step['action_type'] ?? ''));
        if ($actionType === '') {
            continue;
        }
        $stepConfig = $step;
        unset($stepConfig['delay_minutes'], $stepConfig['action_type']);
        if ($context !== []) {
            $stepConfig['_trigger_context'] = $context;
        }
        $cumulative += max(0, (int) ($step['delay_minutes'] ?? 0));
        $runAt = date('Y-m-d H:i:s', auvvo_unix_ts(time() + $cumulative * 60));
        try {
            $pdo->prepare(
                'INSERT INTO crm_automation_queue
                    (user_id, automation_id, contact_id, trigger_type, trigger_value, step_index, action_type, action_config, run_at)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId,
                $automationId,
                (int) $contact['id'],
                $triggerType,
                $triggerValue,
                $idx,
                $actionType,
                json_encode($stepConfig, JSON_UNESCAPED_UNICODE),
                $runAt,
            ]);
        } catch (PDOException $e) {
            error_log('[Auvvo] enqueue step: ' . $e->getMessage());
        }
        $idx++;
    }
}

function auvvo_crm_enqueue_single(
    PDO $pdo,
    int $userId,
    ?int $automationId,
    array $contact,
    string $triggerType,
    string $triggerValue,
    string $actionType,
    array $actionConfig,
    int $delayMinutes,
    array $context = []
): void {
    if (empty($contact['id'])) {
        return;
    }
    auvvo_run_migrations($pdo);
    $runAt = date('Y-m-d H:i:s', auvvo_unix_ts(time() + max(0, (int) $delayMinutes) * 60));
    if ($context !== []) {
        $actionConfig['_trigger_context'] = $context;
    }
    try {
        $pdo->prepare(
            'INSERT INTO crm_automation_queue
                (user_id, automation_id, contact_id, trigger_type, trigger_value, step_index, action_type, action_config, run_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $automationId,
            (int) $contact['id'],
            $triggerType,
            $triggerValue,
            0,
            $actionType,
            json_encode($actionConfig, JSON_UNESCAPED_UNICODE),
            $runAt,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] enqueue automation: ' . $e->getMessage());
    }
}

/** @return list<string> */
function auvvo_crm_condition_keys(): array
{
    return [
        'require_tag', 'exclude_tag', 'ab_chance', 'stage_is', 'stage_not', 'agent_id', 'agent_unassigned',
        'require_email', 'require_phone', 'keyword_contains', 'keyword_not_contains',
        'business_hours_only', 'outside_business_hours', 'bh_start', 'bh_end', 'bh_weekdays', 'bh_timezone',
        'cycle_days', 'miss_factor', 'inactive_after_days', 'min_purchases',
        'memory_key', 'memory_value', 'value_mode', 'label',
    ];
}

function auvvo_crm_schedule_rule(
    PDO $pdo,
    int $userId,
    array $rule,
    array $contact,
    string $triggerType,
    string $triggerValue,
    array $context = []
): void {
    $config = json_decode((string) ($rule['action_config'] ?? '{}'), true);
    if (!is_array($config)) {
        $config = [];
    }
    if (!auvvo_crm_contact_passes_conditions($config, $contact, $context, $pdo)) {
        return;
    }

    $automationId = (int) ($rule['id'] ?? 0);
    if ($automationId > 0 && auvvo_crm_dedupe_should_skip_source(
        $pdo,
        $userId,
        $contact,
        'rule',
        $automationId,
        $triggerType,
        $triggerValue
    )) {
        return;
    }

    $actionType = (string) ($rule['action_type'] ?? '');
    $steps = $config['steps'] ?? null;

    if (is_array($steps) && $steps !== []) {
        auvvo_crm_enqueue_steps(
            $pdo,
            $userId,
            $automationId > 0 ? $automationId : null,
            $contact,
            $triggerType,
            $triggerValue,
            $steps,
            $context
        );
        if ($automationId > 0) {
            auvvo_crm_dedupe_mark_source($pdo, $userId, $contact, 'rule', $automationId, $triggerType, $triggerValue);
        }
        return;
    }

    $delay = max(0, (int) ($config['delay_minutes'] ?? 0));
    $execConfig = $config;
    foreach (auvvo_crm_condition_keys() as $ck) {
        unset($execConfig[$ck]);
    }
    unset($execConfig['delay_minutes'], $execConfig['steps']);

    if ($delay > 0) {
        auvvo_crm_enqueue_single($pdo, $userId, $automationId > 0 ? $automationId : null, $contact, $triggerType, $triggerValue, $actionType, $execConfig, $delay, $context);
        if ($automationId > 0) {
            auvvo_crm_dedupe_mark_source($pdo, $userId, $contact, 'rule', $automationId, $triggerType, $triggerValue);
        }
        return;
    }

    auvvo_crm_execute_action($pdo, $userId, $actionType, $execConfig, $contact, $triggerType, $triggerValue, $context);
    if ($automationId > 0) {
        auvvo_crm_dedupe_mark_source($pdo, $userId, $contact, 'rule', $automationId, $triggerType, $triggerValue);
    }
}

/**
 * Dispara automações para um gatilho.
 *
 * @param array<string, mixed> $context message_body, trigger_agent_id, …
 */
function auvvo_crm_run_automations(
    PDO $pdo,
    int $userId,
    string $triggerType,
    string $triggerValue,
    array $contact,
    array $context = []
): void {
    auvvo_run_migrations($pdo);
    $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    if ($context !== [] && !isset($context['trigger_type'])) {
        $context = auvvo_crm_build_trigger_context($triggerType, $triggerValue, $context);
    }
    $contactPipelineId = (int) ($contact['pipeline_id'] ?? 0);
    $skipPipelineFilter = auvvo_crm_trigger_skips_pipeline_filter($triggerType);

    try {
        if ($skipPipelineFilter) {
            $stmt = $pdo->prepare(
                'SELECT * FROM crm_automations WHERE user_id = ? AND is_active = 1 AND trigger_type = ?
                 AND (trigger_value = ? OR trigger_value = ?)'
            );
            $stmt->execute([$userId, $triggerType, $triggerValue, '*']);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM crm_automations WHERE user_id = ? AND is_active = 1 AND trigger_type = ?
                 AND (trigger_value = ? OR trigger_value = ?)
                 AND (pipeline_id IS NULL OR pipeline_id = 0 OR pipeline_id = ?)'
            );
            $stmt->execute([$userId, $triggerType, $triggerValue, '*', $contactPipelineId]);
        }
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return;
    }

    foreach ($rules as $rule) {
        $rulePipelineId = (int) ($rule['pipeline_id'] ?? 0);
        if ($rulePipelineId > 0 && $skipPipelineFilter) {
            auvvo_crm_sync_contact_to_pipeline($pdo, $userId, $contact, $rulePipelineId);
        }
        auvvo_crm_schedule_rule($pdo, $userId, $rule, $contact, $triggerType, $triggerValue, $context);
    }

    require_once __DIR__ . '/crm_flow_engine.inc.php';
    auvvo_crm_run_visual_flows($pdo, $userId, $triggerType, $triggerValue, $contact, $context);
}

/**
 * Processa itens vencidos da fila (worker ou cron).
 */
function auvvo_crm_process_automation_queue(PDO $pdo, int $limit = 25): int
{
    auvvo_run_migrations($pdo);
    $limit = max(1, min(100, $limit));
    $processed = 0;
    $rows = [];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT id FROM crm_automation_queue
             WHERE status = ? AND run_at <= NOW()
             ORDER BY run_at ASC
             LIMIT ' . $limit . '
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute(['pending']);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            $pdo->commit();
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare(
            "UPDATE crm_automation_queue SET status = 'processing', attempts = attempts + 1
             WHERE id IN ($ph) AND status = 'pending'"
        );
        $upd->execute($ids);
        $fetch = $pdo->prepare(
            "SELECT * FROM crm_automation_queue WHERE id IN ($ph) AND status = 'processing'"
        );
        $fetch->execute($ids);
        $rows = $fetch->fetchAll(PDO::FETCH_ASSOC);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Auvvo] automation_queue claim: ' . $e->getMessage());

        return 0;
    }

    require_once __DIR__ . '/Contacts.php';
    $crm = new Contacts($pdo);

    foreach ($rows as $row) {
        $qid = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $contactId = (int) $row['contact_id'];
        $contact = $crm->get($userId, $contactId);
        if (!$contact) {
            $pdo->prepare('UPDATE crm_automation_queue SET status = ?, last_error = ? WHERE id = ?')
                ->execute(['failed', 'contact_not_found', $qid]);
            continue;
        }
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);

        $actionConfig = json_decode((string) ($row['action_config'] ?? '{}'), true);
        if (!is_array($actionConfig)) {
            $actionConfig = [];
        }
        $ctx = is_array($actionConfig['_trigger_context'] ?? null) ? $actionConfig['_trigger_context'] : [];
        unset($actionConfig['_trigger_context']);

        try {
            $actionType = (string) $row['action_type'];
            if ($actionType === 'flow_resume') {
                require_once __DIR__ . '/crm_flow_engine.inc.php';
                if ($ctx !== []) {
                    $actionConfig['_trigger_context'] = $ctx;
                }
                auvvo_flow_resume_from_queue(
                    $pdo,
                    $userId,
                    $actionConfig,
                    $contact,
                    (string) $row['trigger_type'],
                    (string) $row['trigger_value']
                );
            } elseif ($actionType === 'flow_wait_timeout') {
                require_once __DIR__ . '/crm_flow_wait_reply.inc.php';
                auvvo_flow_wait_timeout_from_queue($pdo, $userId, $actionConfig, $contact);
            } else {
                auvvo_crm_execute_action(
                    $pdo,
                    $userId,
                    $actionType,
                    $actionConfig,
                    $contact,
                    (string) $row['trigger_type'],
                    (string) $row['trigger_value'],
                    $ctx
                );
            }
            $pdo->prepare('UPDATE crm_automation_queue SET status = ? WHERE id = ?')->execute(['done', $qid]);
            $processed++;
        } catch (Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 500);
            $attempts = (int) ($row['attempts'] ?? 0); // ja foi incrementado pelo UPDATE da claim
            $maxAttempts = 5;
            if ($attempts >= $maxAttempts) {
                $pdo->prepare('UPDATE crm_automation_queue SET status = ?, last_error = ? WHERE id = ?')
                    ->execute(['failed', $err, $qid]);
            } else {
                // Backoff exponencial: 2^attempt minutos (1→2, 2→4, 3→8, 4→16 min)
                $backoffMins = min(60, (int) pow(2, $attempts));
                $nextRun = date('Y-m-d H:i:s', auvvo_unix_ts(time() + (int) $backoffMins * 60));
                $pdo->prepare(
                    'UPDATE crm_automation_queue SET status = ?, last_error = ?, run_at = ? WHERE id = ?'
                )->execute(['pending', $err, $nextRun, $qid]);
            }
        }
    }

    if ($processed > 0) {
        auvvo_worker_touch_heartbeat($pdo);
    }

    // Limpeza periódica: 1 em 200 ticks (≈ a cada 1 min com poll 300ms)
    if (random_int(1, 200) === 1) {
        auvvo_crm_cleanup_stale_queue($pdo);
    }

    return $processed;
}

/**
 * Remove conversation_states expiradas e itens da fila concluídos com mais de 7 dias.
 */
function auvvo_crm_cleanup_stale_queue(PDO $pdo): void
{
    try {
        $pdo->exec(
            "UPDATE crm_automation_queue SET status = 'pending'
             WHERE status = 'processing' AND run_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND attempts < 5"
        );
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec(
            "UPDATE crm_automation_queue SET status = 'failed', last_error = 'stale_processing'
             WHERE status = 'processing' AND run_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("DELETE FROM crm_automation_queue WHERE status IN ('done','failed') AND run_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("DELETE FROM conversation_states WHERE ia_paused_until IS NOT NULL AND ia_paused_until < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("DELETE FROM brain_action_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (PDOException $e) {
    }
}

/**
 * Estatísticas da fila para o painel.
 *
 * @return array{pending:int,processing:int,done_today:int,failed:int}
 */
function auvvo_crm_automation_queue_stats(PDO $pdo, int $userId): array
{
    auvvo_run_migrations($pdo);
    $out = ['pending' => 0, 'processing' => 0, 'done_today' => 0, 'failed' => 0];
    try {
        $st = $pdo->prepare(
            "SELECT status, COUNT(*) AS c FROM crm_automation_queue
             WHERE user_id = ? AND (status IN ('pending','processing','failed')
                OR (status = 'done' AND created_at >= CURDATE()))
             GROUP BY status"
        );
        $st->execute([$userId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $s = (string) $r['status'];
            $c = (int) $r['c'];
            if ($s === 'done') {
                $out['done_today'] = $c;
            } elseif (isset($out[$s])) {
                $out[$s] = $c;
            }
        }
    } catch (PDOException $e) {
    }

    return $out;
}

function auvvo_worker_heartbeat_path(): string
{
    $custom = trim((string) ($_ENV['AUVVO_WORKER_HEARTBEAT'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }

    return dirname(__DIR__) . '/storage/worker_heartbeat.txt';
}

function auvvo_worker_touch_heartbeat(?PDO $pdo = null): void
{
    // Arquivo local (funciona quando PHP e worker estão no mesmo servidor)
    $path = auvvo_worker_heartbeat_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, (string) time());

    // Banco de dados (funciona em servidores separados — Docker/VPS remoto)
    if ($pdo !== null) {
        try {
            $pdo->prepare(
                "INSERT INTO auvvo_app_meta (meta_key, meta_value) VALUES ('worker_heartbeat', ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
            )->execute([(string) time()]);
        } catch (PDOException $e) {
        }
    }
}

/**
 * @param array{pending:int,processing:int,done_today:int,failed:int} $stats
 * @return array{pending:int,processing:int,done_today:int,failed:int,overdue_pending:int,worker_alive:bool,worker_attention:bool}
 */
function auvvo_crm_queue_stats_enriched(PDO $pdo, int $userId, array $stats): array
{
    $out = $stats;
    $out['overdue_pending'] = 0;
    $out['worker_alive'] = false;
    $out['worker_attention'] = false;

    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM crm_automation_queue
             WHERE user_id = ? AND status = 'pending' AND run_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $st->execute([$userId]);
        $out['overdue_pending'] = (int) ($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
    }

    // Tenta o arquivo de heartbeat (funciona quando PHP e worker estão no mesmo servidor)
    $hb = auvvo_worker_heartbeat_path();
    if (is_file($hb)) {
        $out['worker_alive'] = (time() - (int) @filemtime($hb)) < 180;
    } else {
        // Fallback: worker em servidor separado (Docker/VPS) — infere atividade pelo banco
        // Se algum item foi processado nos últimos 5 min, o worker está vivo
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM crm_automation_queue
                 WHERE user_id = ? AND status = 'done' AND run_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
            );
            $st->execute([$userId]);
            $recentDone = (int) ($st->fetchColumn() ?: 0);
            if ($recentDone > 0) {
                $out['worker_alive'] = true;
            } else {
                // Alternativa: verifica tabela de meta para heartbeat remoto
                try {
                    $stMeta = $pdo->prepare(
                        "SELECT meta_value FROM auvvo_app_meta WHERE meta_key = 'worker_heartbeat' LIMIT 1"
                    );
                    $stMeta->execute();
                    $ts = (int) ($stMeta->fetchColumn() ?: 0);
                    $out['worker_alive'] = $ts > 0 && (time() - $ts) < 180;
                } catch (PDOException $e) {
                    $out['worker_alive'] = false;
                }
            }
        } catch (PDOException $e) {
            $out['worker_alive'] = false;
        }
    }
    $pending = (int) ($out['pending'] ?? 0);
    $out['worker_attention'] = $out['overdue_pending'] > 0 || ($pending > 0 && !$out['worker_alive']);

    return $out;
}
