<?php
declare(strict_types=1);

/**
 * Cérebro → backend: a IA sinaliza ações; o servidor executa integrações.
 * Formato: [[AUVO_ACTIONS]] + JSON na última linha (legado: [[GCAL_EVENT]]).
 */
require_once __DIR__ . '/crm_automation.inc.php';
require_once __DIR__ . '/crm_automation_motor.inc.php';

/**
 * Extrai array JSON balanceado após marcador [[AUVO_ACTIONS]].
 */
function auvvo_brain_extract_actions_json(string $text): ?string
{
    $pos = strrpos($text, '[[AUVO_ACTIONS]]');
    if ($pos === false) {
        $pos = strrpos($text, '[[AUVVO_ACTIONS]]');
    }
    if ($pos === false) {
        return null;
    }
    $after = substr($text, $pos);
    $start = strpos($after, '[');
    if ($start === false) {
        return null;
    }
    $chunk = substr($after, $start);
    $depth = 0;
    $len = strlen($chunk);
    for ($i = 0; $i < $len; $i++) {
        $ch = $chunk[$i];
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($chunk, 0, $i + 1);
            }
        }
    }

    return null;
}

/**
 * @return array{clean_text:string, actions:list<array{tool:string, payload:array}>}
 */
function auvvo_brain_parse_response(string $aiText): array
{
    $text = $aiText;
    $actions = [];

    $jsonBlob = auvvo_brain_extract_actions_json($text);
    if ($jsonBlob !== null && $jsonBlob !== '') {
        $decoded = json_decode($jsonBlob, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $tool = trim((string) ($item['tool'] ?? ''));
                $payload = $item['payload'] ?? [];
                if ($tool !== '' && is_array($payload)) {
                    $actions[] = ['tool' => $tool, 'payload' => $payload];
                }
            }
        }
    }

    $text = auvvo_brain_strip_actions_block($text);

    if (function_exists('extractGcalDirective')) {
        $gcal = extractGcalDirective($text);
        if ($gcal) {
            $text = $gcal['clean_text'];
            $hasCal = false;
            foreach ($actions as $a) {
                if (($a['tool'] ?? '') === 'calendar.create_event') {
                    $hasCal = true;
                    break;
                }
            }
            if (!$hasCal) {
                $actions[] = [
                    'tool'    => 'calendar.create_event',
                    'payload' => $gcal['payload'],
                ];
            }
        }
    }

    return ['clean_text' => $text, 'actions' => $actions];
}

/** Remove bloco [[AUVO_ACTIONS]] + JSON — nunca deve ir ao WhatsApp. */
function auvvo_brain_strip_actions_block(string $text): string
{
    $text = trim(preg_replace('/\[\[AUVV?O_ACTIONS\]\]\s*\[[\s\S]*\]\s*/u', '', $text));
    $text = trim(preg_replace('/\[\[AUVV?O_ACTIONS\]\]\s*[\s\S]*$/u', '', $text));

    return trim($text);
}

/**
 * Converte tool_calls nativos (OpenAI) para o formato do executor do cérebro.
 *
 * @param list<array<string, mixed>> $toolCalls
 * @return list<array{tool:string, payload:array}>
 */
function auvvo_brain_tool_calls_to_actions(array $toolCalls): array
{
    $actions = [];
    foreach ($toolCalls as $tc) {
        if (!is_array($tc)) {
            continue;
        }
        $fn = trim((string) ($tc['function']['name'] ?? ''));
        if ($fn === '') {
            continue;
        }
        $argsRaw = (string) ($tc['function']['arguments'] ?? '{}');
        $payload = json_decode($argsRaw, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $tool = auvvo_brain_normalize_tool_name($fn);
        if ($tool !== '') {
            $actions[] = ['tool' => $tool, 'payload' => $payload];
        }
    }

    return $actions;
}

function auvvo_brain_normalize_tool_name(string $fn): string
{
    $fn = strtolower(trim($fn));
    $map = [
        'calendar_create_event' => 'calendar.create_event',
        'calendar.create_event' => 'calendar.create_event',
        'gcal_create_event'     => 'calendar.create_event',
        'crm_add_tag'           => 'crm.add_tag',
        'crm.add_tag'           => 'crm.add_tag',
        'crm_remove_tag'        => 'crm.remove_tag',
        'crm.remove_tag'        => 'crm.remove_tag',
        'crm_move_stage'        => 'crm.move_stage',
        'crm.move_stage'        => 'crm.move_stage',
        'crm_set_memory'        => 'crm.set_memory',
        'crm.set_memory'        => 'crm.set_memory',
        'crm_assign_agent'      => 'crm.assign_agent',
        'crm.assign_agent'      => 'crm.assign_agent',
        'crm_clear_mission'     => 'crm.clear_mission',
        'crm.clear_mission'     => 'crm.clear_mission',
        'sheets_append_row'     => 'sheets.append_row',
        'sheets.append_row'     => 'sheets.append_row',
        'webhook_outbound'      => 'webhook.outbound',
        'webhook.outbound'      => 'webhook.outbound',
        'http_preset'           => 'http.preset',
        'http.preset'           => 'http.preset',
    ];
    if (isset($map[$fn])) {
        return $map[$fn];
    }
    if (str_contains($fn, '.')) {
        return $fn;
    }

    return str_replace('_', '.', $fn);
}

/**
 * @param list<string> $executed
 */
function auvvo_brain_confirmation_message(array $executed, array $warnings = []): string
{
    if (in_array('calendar.create_event', $executed, true)) {
        return 'Perfeito! Confirmo na agenda e te aviso se precisar de mais algum detalhe.';
    }
    foreach ($warnings as $w) {
        $w = (string) $w;
        if (str_contains($w, 'Horário do agendamento incompleto') || str_contains($w, 'Horario do agendamento incompleto')) {
            return 'Ótimo! Anotei o dia. Qual horário funciona melhor para você? (Ex.: 10h, 14h30)';
        }
        if (str_contains($w, 'Agenda Google não está conectada')) {
            return 'Anotei seu interesse! Qual horário amanhã fica melhor para você?';
        }
    }
    if ($warnings !== []) {
        return 'Anotei aqui. Houve um detalhe na integração — posso tentar de outro jeito se quiser.';
    }
    foreach ($executed as $e) {
        if (str_starts_with($e, 'crm.add_tag:')) {
            return 'Registrei no seu cadastro. Em que mais posso ajudar?';
        }
        if (str_starts_with($e, 'crm.move_stage:')) {
            return 'Atualizei seu estágio no funil. Seguimos!';
        }
    }
    if ($executed !== []) {
        return 'Pronto, já processei no sistema. Posso ajudar em mais alguma coisa?';
    }

    return 'Certo, estou verificando. Um momento.';
}

/**
 * @return array<string, mixed>|null
 */
function auvvo_brain_contact_for_jid(PDO $pdo, int $userId, string $jid): ?array
{
    $jid = trim($jid);
    if ($userId <= 0 || $jid === '') {
        return null;
    }

    require_once __DIR__ . '/Contacts.php';
    $variants = auvvo_crm_contact_jid_variants($jid);
    if ($variants === []) {
        $variants = [$jid];
    }

    foreach ($variants as $v) {
        $stmt = $pdo->prepare('SELECT id FROM contacts WHERE user_id = ? AND jid = ? LIMIT 1');
        $stmt->execute([$userId, $v]);
        $cid = (int) ($stmt->fetchColumn() ?: 0);
        if ($cid > 0) {
            $row = (new Contacts($pdo))->get($userId, $cid);

            return is_array($row) ? $row : null;
        }
    }

    return null;
}

/**
 * @param list<array{tool:string, payload:array}> $actions
 * @return array{warnings:list<string>, executed:list<string>}
 */
function auvvo_brain_run_actions(
    PDO $pdo,
    int $userId,
    array $agent,
    array $settings,
    ?array $contact,
    array $actions,
    string $canonicalJid,
    string $triggerType = 'whatsapp_message',
    string $triggerValue = '*'
): array {
    $warnings = [];
    $executed = [];
    if ($userId <= 0 || $actions === []) {
        return ['warnings' => $warnings, 'executed' => $executed];
    }

    $contact = is_array($contact) ? $contact : [];
    $context = auvvo_crm_build_trigger_context($triggerType, $triggerValue, [
        'trigger_agent_id' => (int) ($agent['id'] ?? 0),
        'message_body'     => '',
    ]);
    $agentId = (int) ($agent['id'] ?? 0);

    foreach ($actions as $action) {
        $tool = trim((string) ($action['tool'] ?? ''));
        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        if ($tool === '') {
            continue;
        }

        try {
            switch ($tool) {
                case 'crm.clear_mission':
                    if (!empty($contact['jid'])) {
                        require_once __DIR__ . '/context_memory.inc.php';
                        auvvo_contact_memory_merge($pdo, $userId, (string) $contact['jid'], ['_brain_mission' => null]);
                        $executed[] = $tool;
                    }
                    break;

                case 'calendar.create_event':
                    $warn = auvvo_brain_tool_calendar($pdo, $userId, $settings, $agentId, $canonicalJid, $payload);
                    if ($warn !== '') {
                        $warnings[] = $warn;
                    } else {
                        $executed[] = $tool;
                    }
                    break;

                case 'crm.add_tag':
                    if (empty($contact['id'])) {
                        $warnings[] = 'Não registrei a tag no CRM (contato ainda não vinculado).';
                        break;
                    }
                    $tag = trim((string) ($payload['tag'] ?? ''));
                    auvvo_crm_execute_action($pdo, $userId, 'add_tag', [
                        'tag' => $tag,
                    ], $contact, $triggerType, $triggerValue, $context);
                    if ($tag !== '') {
                        $executed[] = $tool . ':' . $tag;
                    }
                    break;

                case 'crm.remove_tag':
                    if (empty($contact['id'])) {
                        break;
                    }
                    $tag = trim((string) ($payload['tag'] ?? ''));
                    auvvo_crm_execute_action($pdo, $userId, 'remove_tag', [
                        'tag' => $tag,
                    ], $contact, $triggerType, $triggerValue, $context);
                    if ($tag !== '') {
                        $executed[] = $tool . ':' . $tag;
                    }
                    break;

                case 'crm.move_stage':
                    if (empty($contact['id'])) {
                        $warnings[] = 'Não movi o estágio no CRM (contato ainda não vinculado).';
                        break;
                    }
                    $stage = trim((string) ($payload['stage'] ?? ''));
                    auvvo_crm_execute_action($pdo, $userId, 'move_stage', [
                        'stage' => $stage,
                    ], $contact, $triggerType, $triggerValue, $context);
                    if ($stage !== '') {
                        $executed[] = $tool . ':' . $stage;
                    }
                    break;

                case 'crm.set_memory':
                    if (empty($contact['jid'])) {
                        break;
                    }
                    auvvo_crm_execute_action($pdo, $userId, 'set_memory', [
                        'key'   => trim((string) ($payload['key'] ?? '')),
                        'value' => trim((string) ($payload['value'] ?? '')),
                    ], $contact, $triggerType, $triggerValue, $context);
                    break;

                case 'crm.assign_agent':
                    if (empty($contact['id'])) {
                        break;
                    }
                    $target = (int) ($payload['agent_id'] ?? 0);
                    if ($target <= 0) {
                        $target = $agentId;
                    }
                    auvvo_crm_execute_action($pdo, $userId, 'assign_agent', [
                        'agent_id' => $target,
                    ], $contact, $triggerType, $triggerValue, $context);
                    $executed[] = $tool . ':' . $target;
                    break;

                case 'sheets.append_row':
                    $cols = $payload['columns'] ?? null;
                    $cfg = [];
                    if (is_array($cols) && $cols !== []) {
                        $cfg['columns'] = $cols;
                    }
                    if (!empty($payload['spreadsheet_id'])) {
                        $cfg['spreadsheet_id'] = (string) $payload['spreadsheet_id'];
                    }
                    if (!empty($payload['sheet_name'])) {
                        $cfg['sheet_name'] = (string) $payload['sheet_name'];
                    }
                    auvvo_crm_execute_action($pdo, $userId, 'google_sheets_append', $cfg, $contact, $triggerType, $triggerValue, $context);
                    $executed[] = $tool;
                    break;

                case 'webhook.outbound':
                    $whId = auvvo_brain_resolve_outbound_webhook_id($pdo, $userId, $payload);
                    if ($whId <= 0) {
                        $warnings[] = 'Webhook de saída não encontrado (verifique o ID/nome em Integrações).';
                        break;
                    }
                    auvvo_crm_execute_action($pdo, $userId, 'call_webhook', [
                        'webhook_id' => $whId,
                    ], $contact, $triggerType, $triggerValue, $context);
                    $executed[] = $tool;
                    break;

                case 'http.preset':
                    $presetId = auvvo_brain_resolve_http_preset_id($pdo, $userId, $payload);
                    if ($presetId <= 0) {
                        $warnings[] = 'Preset HTTP não encontrado (verifique o ID/nome em Integrações).';
                        break;
                    }
                    auvvo_crm_execute_action($pdo, $userId, 'http_preset', [
                        'preset_id' => $presetId,
                    ], $contact, $triggerType, $triggerValue, $context);
                    $executed[] = $tool;
                    break;

                default:
                    error_log('[Auvvo Brain] tool desconhecida: ' . $tool);
            }
        } catch (Throwable $e) {
            error_log('[Auvvo Brain] ' . $tool . ': ' . $e->getMessage());
            $warnings[] = 'Não concluí uma ação interna (' . $tool . ').';
        }
    }

    return ['warnings' => $warnings, 'executed' => $executed];
}

/** Tags que indicam missão de automação concluída (limpa _brain_mission). */
function auvvo_brain_mission_completion_tags(): array
{
    return [
        'consulta-agendada',
        'demo-confirmada',
        'proposta-enviada',
        'triagem-registrada',
        'comprou',
        'pedido-confirmado',
        'missao-concluida',
        'proposta-em-andamento',
    ];
}

function auvvo_brain_clear_mission(PDO $pdo, int $userId, string $jid): void
{
    $jid = trim($jid);
    if ($userId <= 0 || $jid === '') {
        return;
    }
    require_once __DIR__ . '/context_memory.inc.php';
    auvvo_contact_memory_merge($pdo, $userId, $jid, ['_brain_mission' => null]);
}

/**
 * @param list<string> $executed
 */
function auvvo_brain_should_auto_clear_mission(array $executed): bool
{
    if ($executed === []) {
        return false;
    }
    if (in_array('crm.clear_mission', $executed, true)) {
        return true;
    }
    if (in_array('calendar.create_event', $executed, true)) {
        return true;
    }
    $completionTags = auvvo_brain_mission_completion_tags();
    foreach ($executed as $e) {
        if (!str_starts_with($e, 'crm.add_tag:')) {
            continue;
        }
        $tag = substr($e, strlen('crm.add_tag:'));
        if (in_array($tag, $completionTags, true)) {
            return true;
        }
    }
    foreach ($executed as $e) {
        if (str_starts_with($e, 'crm.move_stage:') && in_array(substr($e, 15), ['closed', 'won', 'lost'], true)) {
            return true;
        }
    }

    return false;
}

function auvvo_brain_tool_calendar(
    PDO $pdo,
    int $userId,
    array $settings,
    int $agentId,
    string $canonicalJid,
    array $payload
): string {
    require_once __DIR__ . '/GoogleCalendar.php';
    require_once __DIR__ . '/auvvo_scheduling.inc.php';

    $enabled = (int) ($settings['google_calendar_enabled'] ?? 0) === 1;
    $connected = (bool) ($settings['google_calendar_connected'] ?? false);
    if (!$enabled || !$connected || !GoogleCalendar::isConfigured($pdo, $userId)) {
        return ' (Agenda Google não está conectada na conta — peça ao responsável conectar em Configurações.)';
    }

    $built = auvvo_scheduling_build_event_payload($pdo, $userId, $canonicalJid, $payload);
    if ($built !== null) {
        $payload = array_merge($payload, $built);
    }

    $start = trim((string) ($payload['start'] ?? ''));
    $end = trim((string) ($payload['end'] ?? ''));
    if ($start === '' || $end === '') {
        return ' (Horário do agendamento incompleto — confirme data e hora.)';
    }

    $tz = trim((string) ($payload['timezone'] ?? 'America/Sao_Paulo'));
    $sum = trim((string) ($payload['summary'] ?? 'Agendamento'));
    $desc = trim((string) ($payload['description'] ?? ''));
    $loc = trim((string) ($payload['location'] ?? ''));

    $event = [
        'id'          => GoogleCalendar::deterministicEventId($agentId, $canonicalJid, $start, $end, $sum),
        'summary'     => $sum,
        'description' => $desc !== '' ? $desc : null,
        'location'    => $loc !== '' ? $loc : null,
        'start'       => ['dateTime' => $start, 'timeZone' => $tz],
        'end'         => ['dateTime' => $end, 'timeZone' => $tz],
    ];
    $event = array_filter($event, static fn ($v) => $v !== null);

    try {
        $res = GoogleCalendar::createEvent($pdo, $userId, $event);
        if (!empty($res['error']) && (int) ($res['status'] ?? 0) !== 409) {
            $msg = isset($res['message']) ? (string) $res['message'] : '';

            return ' (Não confirmei na agenda agora' . ($msg !== '' && mb_strlen($msg) < 80 ? ': ' . mb_substr($msg, 0, 78) : '') . '.)';
        }
        auvvo_scheduling_mark_confirmed($pdo, $userId, $canonicalJid);
    } catch (Throwable $e) {
        error_log('[Auvvo GCal] ' . $e->getMessage());

        return ' (Falhou ao sincronizar com o Google Calendar; podemos tentar outro horário.)';
    }

    return '';
}

function auvvo_brain_resolve_outbound_webhook_id(PDO $pdo, int $userId, array $payload): int
{
    $id = (int) ($payload['webhook_id'] ?? $payload['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id FROM outbound_webhooks WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([$id, $userId]);

        return (int) ($st->fetchColumn() ?: 0);
    }
    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        return 0;
    }
    $st = $pdo->prepare('SELECT id FROM outbound_webhooks WHERE user_id = ? AND name = ? LIMIT 1');
    $st->execute([$userId, $name]);

    return (int) ($st->fetchColumn() ?: 0);
}

function auvvo_brain_resolve_http_preset_id(PDO $pdo, int $userId, array $payload): int
{
    $id = (int) ($payload['preset_id'] ?? $payload['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare(
            'SELECT id FROM integration_http_presets WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1'
        );
        $st->execute([$id, $userId]);

        return (int) ($st->fetchColumn() ?: 0);
    }
    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT id FROM integration_http_presets WHERE user_id = ? AND name = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$userId, $name]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * Processa resposta LLM: executa ações e devolve texto limpo para o WhatsApp.
 */
function auvvo_brain_process_llm_response(
    PDO $pdo,
    int $userId,
    array $agent,
    array $settings,
    string $aiText,
    string $canonicalJid,
    ?array $contact = null,
    ?array $nativeToolCalls = null
): string {
    $parsed = auvvo_brain_parse_response($aiText);
    if ($parsed['actions'] === [] && is_array($nativeToolCalls) && $nativeToolCalls !== []) {
        $parsed['actions'] = auvvo_brain_tool_calls_to_actions($nativeToolCalls);
    }
    $clean = $parsed['clean_text'];

    if ($contact === null) {
        $contact = auvvo_brain_contact_for_jid($pdo, $userId, $canonicalJid);
    }
    if (is_array($contact)) {
        $contact = auvvo_crm_hydrate_contact($pdo, $userId, $contact);
    } else {
        $contact = ['jid' => $canonicalJid, 'agent_id' => (int) ($agent['id'] ?? 0)];
    }

    $triggerValue = (string) ($agent['id'] ?? '*');
    require_once __DIR__ . '/context_memory.inc.php';
    $memBefore = auvvo_contact_memory_get($pdo, $userId, $canonicalJid);
    $hadMission = trim((string) ($memBefore['_brain_mission'] ?? '')) !== '';

    $run = auvvo_brain_run_actions(
        $pdo,
        $userId,
        $agent,
        $settings,
        $contact,
        $parsed['actions'],
        $canonicalJid,
        'whatsapp_message',
        $triggerValue
    );
    $warnings = $run['warnings'];
    $executed = $run['executed'];

    if ($hadMission && auvvo_brain_should_auto_clear_mission($executed) && !empty($contact['jid'])) {
        auvvo_brain_clear_mission($pdo, $userId, (string) $contact['jid']);
    }

    if (trim($clean) === '' && ($executed !== [] || $warnings !== [])) {
        $clean = auvvo_brain_confirmation_message($executed, $warnings);
    }

    if ($parsed['actions'] !== [] || $executed !== []) {
        require_once __DIR__ . '/auvvo_brain_log.inc.php';
        auvvo_brain_log_execution(
            $pdo,
            $userId,
            is_array($contact) ? $contact : null,
            (int) ($agent['id'] ?? 0),
            $parsed['actions'],
            $executed,
            $warnings
        );
    }

    if ($warnings !== []) {
        error_log('[Auvvo Brain] warnings: ' . implode(' | ', $warnings));
    }

    return $clean;
}

/**
 * Lista recursos do usuário para o prompt (webhooks, presets HTTP).
 *
 * @return array{outbound:list<array>, http_presets:list<array>}
 */
function auvvo_brain_list_integration_resources(PDO $pdo, int $userId): array
{
    $outbound = [];
    $presets = [];

    try {
        $st = $pdo->prepare('SELECT id, name FROM outbound_webhooks WHERE user_id = ? ORDER BY id ASC');
        $st->execute([$userId]);
        $outbound = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
    }

    try {
        $st = $pdo->prepare(
            'SELECT id, name, provider_slug FROM integration_http_presets
             WHERE user_id = ? AND is_active = 1 ORDER BY id ASC'
        );
        $st->execute([$userId]);
        $presets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
    }

    return ['outbound' => $outbound, 'http_presets' => $presets];
}

/**
 * Seção de prompt: ferramentas que o cérebro pode acionar (só o que está ligado).
 */
/**
 * Status das ferramentas para UI (Agentes / Integrações).
 *
 * @return list<array{id:string,label:string,connected:bool,detail:string,hint:string}>
 */
function auvvo_brain_capabilities_ui(PDO $pdo, int $userId, array $settings = []): array
{
    require_once __DIR__ . '/integrations_catalog.inc.php';
    require_once __DIR__ . '/GoogleCalendar.php';
    require_once __DIR__ . '/GoogleSheets.php';

    $items = [];
    $items[] = [
        'id'        => 'whatsapp',
        'label'     => 'WhatsApp (Evolution)',
        'connected' => true,
        'detail'    => 'Conexão por agente',
        'hint'      => 'Respostas e envio automático na conversa.',
    ];

    $gcalOn = (int) ($settings['google_calendar_enabled'] ?? 0) === 1;
    $gcalTok = $gcalOn && GoogleCalendar::loadToken($pdo, $userId) !== null;
    $items[] = [
        'id'        => 'calendar',
        'label'     => 'Google Calendar',
        'connected' => $gcalOn && $gcalTok && GoogleCalendar::isConfigured($pdo, $userId),
        'detail'    => $gcalOn ? ($gcalTok ? 'OAuth conectado' : 'Ative em Configurações → Conectar') : 'Desligado em Configurações',
        'hint'      => 'Agende com [[AUVO_ACTIONS]] calendar.create_event após confirmar horário.',
    ];

    $sheetsOn = (int) ($settings['google_sheets_enabled'] ?? 0) === 1;
    $sheetsTok = $sheetsOn && GoogleSheets::loadToken($pdo, $userId) !== null;
    $items[] = [
        'id'        => 'sheets',
        'label'     => 'Google Sheets',
        'connected' => $sheetsTok,
        'detail'    => $sheetsOn ? ($sheetsTok ? 'Planilha vinculada' : 'Conectar em Integrações') : 'Desligado',
        'hint'      => 'Registre leads com sheets.append_row.',
    ];

    $items[] = [
        'id'        => 'crm',
        'label'     => 'CRM (tags, estágio, memória)',
        'connected' => true,
        'detail'    => 'Sempre disponível',
        'hint'      => 'crm.add_tag, crm.move_stage, crm.set_memory no prompt do agente.',
    ];

    $res = auvvo_brain_list_integration_resources($pdo, $userId);
    $whCount = count($res['outbound']);
    $items[] = [
        'id'        => 'webhook',
        'label'     => 'Webhooks de saída',
        'connected' => $whCount > 0,
        'detail'    => $whCount > 0 ? "{$whCount} webhook(s) cadastrado(s)" : 'Crie em Webhooks',
        'hint'      => 'webhook.outbound com webhook_id listado no prompt.',
    ];

    $prCount = count($res['http_presets']);
    $items[] = [
        'id'        => 'http',
        'label'     => 'APIs / HTTP presets',
        'connected' => $prCount > 0,
        'detail'    => $prCount > 0 ? "{$prCount} preset(s)" : 'Crie em Integrações → HTTP',
        'hint'      => 'http.preset com preset_id — ERP, site, Hotmart, etc.',
    ];

    if (!empty($settings['company_site'])) {
        $items[] = [
            'id'        => 'site',
            'label'     => 'Site da empresa',
            'connected' => true,
            'detail'    => (string) $settings['company_site'],
            'hint'      => 'Referência no prompt; não substitui integrações.',
        ];
    }

    return $items;
}

function auvvo_brain_native_tools_enabled(): bool
{
    $v = strtolower(trim((string) ($_ENV['BRAIN_NATIVE_TOOLS'] ?? getenv('BRAIN_NATIVE_TOOLS') ?: '')));

    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Definições OpenAI function-calling (opcional; ver BRAIN_NATIVE_TOOLS).
 *
 * @return list<array<string, mixed>>
 */
function auvvo_brain_openai_tools_for_api(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'calendar_create_event',
                'description' => 'Cria evento no Google Calendar após o cliente confirmar data, hora e assunto.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'start'       => ['type' => 'string', 'description' => 'Início RFC3339'],
                        'end'         => ['type' => 'string', 'description' => 'Fim RFC3339'],
                        'timezone'    => ['type' => 'string', 'description' => 'Ex.: America/Sao_Paulo'],
                        'summary'     => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'location'    => ['type' => 'string'],
                    ],
                    'required'   => ['start', 'end', 'summary'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'crm_add_tag',
                'description' => 'Adiciona tag ao contato da conversa.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['tag' => ['type' => 'string']],
                    'required'   => ['tag'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'crm_move_stage',
                'description' => 'Move o contato para outro estágio do funil.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['stage' => ['type' => 'string', 'description' => 'Slug: new, contacted, qualified, proposal, closed, lost']],
                    'required'   => ['stage'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'crm_set_memory',
                'description' => 'Salva um dado importante sobre o lead na memória persistente do CRM.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'key'   => ['type' => 'string', 'description' => 'Nome do campo (ex: interesse, cargo)'],
                        'value' => ['type' => 'string'],
                    ],
                    'required'   => ['key', 'value'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'crm_remove_tag',
                'description' => 'Remove uma tag do contato.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['tag' => ['type' => 'string']],
                    'required'   => ['tag'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'sheets_append_row',
                'description' => 'Registra dados do lead na planilha Google Sheets conectada.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Valores da linha (opcional — padrão: nome, telefone, estágio)'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'webhook_outbound',
                'description' => 'Dispara um webhook de saída cadastrado (notifica ERP, CRM externo, Hotmart, etc.).',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'webhook_id' => ['type' => 'integer', 'description' => 'ID numérico do webhook (listado no prompt)'],
                        'name'       => ['type' => 'string', 'description' => 'Nome exato do webhook (alternativa ao ID)'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'http_preset',
                'description' => 'Chama uma API externa configurada como preset HTTP.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'preset_id' => ['type' => 'integer', 'description' => 'ID do preset (listado no prompt)'],
                        'name'      => ['type' => 'string', 'description' => 'Nome do preset (alternativa ao ID)'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'crm_clear_mission',
                'description' => 'Remove a missão ativa (_brain_mission) após concluir o objetivo.',
                'parameters'  => ['type' => 'object', 'properties' => []],
            ],
        ],
    ];
}

function auvvo_brain_build_prompt_section(PDO $pdo, array $agent, array $company): string
{
    $userId = (int) ($agent['user_id'] ?? 0);
    if ($userId <= 0) {
        return '';
    }

    require_once __DIR__ . '/integrations_catalog.inc.php';
    require_once __DIR__ . '/GoogleCalendar.php';
    require_once __DIR__ . '/GoogleSheets.php';

    $lines = [];
    $lines[] = 'Você tem FERRAMENTAS no backend Auvvo. O cliente NÃO vê os marcadores abaixo — só o texto amigável.';
    $lines[] = 'IMPORTANTE: Sempre separe o texto da conversa dos comandos com UMA LINHA EM BRANCO. Exemplo:';
    $lines[] = '';
    $lines[] = 'Seu texto amigavel aqui. Fica tudo certo!';
    $lines[] = '';
    $lines[] = '[[AUVO_ACTIONS]]';
    $lines[] = '[ {"tool":"nome.da.ferramenta","payload":{...}} ]';
    $lines[] = '';
    $lines[] = 'O bloco [[AUVO_ACTIONS]] DEVE ficar isolado na ultima linha, apos uma linha em branco. NUNCA cole ele grudado no texto.';
    $lines[] = 'Formato do JSON: array de objetos com "tool" e "payload". Exemplo real:';
    $lines[] = '[ {"tool":"crm.add_tag","payload":{"tag":"lead-qualificado"}} ]';
    $lines[] = 'Legado ainda aceito para agenda: [[GCAL_EVENT]]{...json...} na ultima linha, tambem isolado.';
    $lines[] = '';

    $gcalOn = (int) ($company['google_calendar_enabled'] ?? 0) === 1;
    $gcalConn = (bool) ($company['google_calendar_connected'] ?? false);
    if ($gcalOn && $gcalConn && GoogleCalendar::isConfigured($pdo, $userId)) {
        $calId = trim((string) ($company['google_calendar_calendar_id'] ?? 'primary')) ?: 'primary';
        $lines[] = '### calendar.create_event (Google Calendar — conectado)';
        $lines[] = '- Use SOMENTE após o cliente confirmar data, hora e assunto do compromisso.';
        $lines[] = '- payload: start, end (RFC3339), timezone (America/Sao_Paulo), summary, description?, location?';
        $lines[] = '- Calendário: ' . $calId;
        $lines[] = '- Exemplo payload: {"start":"2026-06-01T14:00:00-03:00","end":"2026-06-01T15:00:00-03:00","timezone":"America/Sao_Paulo","summary":"Consulta — Maria","description":"WhatsApp"}';
        $lines[] = '';
    } elseif ($gcalOn) {
        $lines[] = '### Google Calendar';
        $lines[] = '- Opção ativa na conta, mas OAuth pendente. NÃO use calendar.create_event nem [[GCAL_EVENT]]. Oriente conectar em Configurações.';
        $lines[] = '';
    }

    $sheetsOn = (int) ($company['google_sheets_enabled'] ?? 0) === 1;
    if ($sheetsOn && GoogleSheets::loadToken($pdo, $userId)) {
        $lines[] = '### sheets.append_row (Google Sheets — conectado)';
        $lines[] = '- Registra lead/evento na planilha quando instruído (ex.: lead qualificado, compra).';
        $lines[] = '- payload opcional: columns (array de strings). Se omitir, o sistema preenche nome/telefone/estágio automaticamente.';
        $lines[] = '';
    }

    $lines[] = '### CRM (contato desta conversa)';
    $lines[] = '- crm.add_tag — payload: {"tag":"nome-da-tag"}';
    $lines[] = '- crm.remove_tag — payload: {"tag":"..."}';
    $lines[] = '- crm.move_stage — payload: {"stage":"slug"} (ex.: new, contacted, qualified, proposal, closed, lost)';
    $lines[] = '- crm.set_memory — payload: {"key":"chave","value":"texto"} (memória persistente do lead)';
    $lines[] = '- crm.assign_agent — payload: {"agent_id":N} (opcional; padrão: agente atual)';
    $lines[] = '- crm.clear_mission — payload: {} — remove MISSÃO ATIVA após concluir objetivos da automação';
    $lines[] = '';

    $res = auvvo_brain_list_integration_resources($pdo, $userId);
    if ($res['outbound'] !== []) {
        $lines[] = '### webhook.outbound (disparar integração HTTP cadastrada)';
        $lines[] = '- payload: {"webhook_id":ID} ou {"name":"Nome exato"}';
        foreach ($res['outbound'] as $wh) {
            $lines[] = '  - ID ' . (int) $wh['id'] . ': «' . (string) $wh['name'] . '»';
        }
        $lines[] = '- Use quando as instruções do negócio pedirem notificar sistema externo (Hotmart, ERP, site, etc.).';
        $lines[] = '';
    }

    if ($res['http_presets'] !== []) {
        $lines[] = '### http.preset (API REST configurada pelo usuário)';
        $lines[] = '- payload: {"preset_id":ID} ou {"name":"Nome do preset"}';
        foreach ($res['http_presets'] as $pr) {
            $slug = (string) ($pr['provider_slug'] ?? 'custom');
            $lines[] = '  - ID ' . (int) $pr['id'] . ': «' . (string) $pr['name'] . '» (' . $slug . ')';
        }
        $lines[] = '- O backend envia JSON com dados do contato; não invente URL — use só IDs listados.';
        $lines[] = '';
    }

    if (!empty($company['company_site'])) {
        $lines[] = 'Site da empresa (referência para o cliente): ' . trim((string) $company['company_site']);
        $lines[] = '';
    }

    $lines[] = 'REGRAS:';
    $lines[] = '- Nunca invente integração desconectada ou ID que não está na lista.';
    $lines[] = '- Confirme com o cliente antes de agendar, mover estágio sensível ou disparar webhook.';
    $lines[] = '- Uma resposta pode ter várias ações no array (ex.: tag + planilha + calendário).';

    $title = 'FERRAMENTAS DO CÉREBRO (backend executa — você decide QUANDO)';
    $line = str_repeat('═', 60);

    return "╔ {$title}\n{$line}\n" . implode("\n", $lines) . "\n{$line}";
}
