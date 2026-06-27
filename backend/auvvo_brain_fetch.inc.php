<?php
declare(strict_types=1);

/**
 * Tool-use loop "fetch" — o agente puxa dados ANTES de compor a resposta.
 *
 * Diferente das ações em [[AUVO_ACTIONS]] (que são "fire-and-forget" — backend
 * executa e segue), aqui o agente emite [[AUVO_FETCH]] sinalizando que precisa
 * de DADOS antes de poder responder. O backend:
 *   1) Executa o(s) fetch(es)
 *   2) Coloca o resultado como mensagem 'user' adicional ("Resultado: …")
 *   3) Chama o LLM uma SEGUNDA vez para gerar a resposta final
 *   4) A resposta final pode conter [[AUVO_ACTIONS]] normais
 *
 * Ferramentas fetch disponíveis (definidas em auvvo_brain_run_fetch_tools):
 *   - http.fetch_preset  → chama um preset HTTP cadastrado, retorna body
 *   - kb.search          → busca na base de conhecimento do agente
 *   - crm.find_contact   → busca um contato por nome/telefone
 *   - crm.last_messages  → últimas N mensagens da conversa
 *
 * Formato no prompt do agente (exemplo no system prompt do MasterPromptBuilder):
 *
 *   [[AUVO_FETCH]]
 *   [ {"tool":"http.fetch_preset","payload":{"preset_id":3,"query":{...}}} ]
 *
 * Limite: 1 ciclo de fetch por turno (anti-loop). Se o agente quiser mais
 * dados, pode emitir nova FETCH na próxima mensagem do lead.
 */

const AUVVO_BRAIN_FETCH_MAX_HOPS = 1;
const AUVVO_BRAIN_FETCH_MAX_RESULT_CHARS = 4000;
const AUVVO_BRAIN_FETCH_HTTP_TIMEOUT = 15;

/**
 * Extrai bloco JSON após [[AUVO_FETCH]] (mesmo parser do AUVO_ACTIONS).
 */
function auvvo_brain_extract_fetch_json(string $text): ?string
{
    $marker = '[[AUVO_FETCH]]';
    $pos = strrpos($text, $marker);
    if ($pos === false) {
        $marker = '[[AUVVO_FETCH]]';
        $pos = strrpos($text, $marker);
    }
    if ($pos === false) {
        return null;
    }
    // Procura o PRIMEIRO `[` APÓS o marcador (o marker tem brackets, não confunde).
    $after = substr($text, $pos + strlen($marker));
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
 * @return list<array{tool:string,payload:array<string,mixed>}>
 */
function auvvo_brain_parse_fetch_actions(string $aiText): array
{
    $json = auvvo_brain_extract_fetch_json($aiText);
    if ($json === null) {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $tool = trim((string) ($item['tool'] ?? ''));
        $payload = $item['payload'] ?? [];
        if ($tool !== '' && is_array($payload)) {
            $out[] = ['tool' => $tool, 'payload' => $payload];
        }
    }

    return $out;
}

/** Remove o bloco AUVO_FETCH do texto final (nunca vai pro WhatsApp). */
function auvvo_brain_strip_fetch_block(string $text): string
{
    $text = preg_replace('/\[\[AUVV?O_FETCH\]\]\s*\[[\s\S]*?\]\s*/u', '', $text);
    $text = preg_replace('/\[\[AUVV?O_FETCH\]\]\s*[\s\S]*$/u', '', $text);

    return trim((string) $text);
}

/**
 * Executa um conjunto de fetch tools e devolve resultados estruturados.
 *
 * @param list<array{tool:string,payload:array<string,mixed>}> $fetches
 * @param array<string,mixed> $agent
 * @param array<string,mixed>|null $contact
 * @return list<array{tool:string, ok:bool, summary:string, data:mixed}>
 */
function auvvo_brain_run_fetch_tools(
    PDO $pdo,
    int $userId,
    array $agent,
    ?array $contact,
    array $fetches
): array {
    $results = [];
    foreach ($fetches as $f) {
        $tool = (string) ($f['tool'] ?? '');
        $payload = is_array($f['payload'] ?? null) ? $f['payload'] : [];
        try {
            switch ($tool) {
                case 'http.fetch_preset':
                case 'http.preset_fetch':
                    $results[] = auvvo_brain_fetch_http_preset($pdo, $userId, $payload, $contact);
                    break;

                case 'kb.search':
                    $results[] = auvvo_brain_fetch_kb_search($pdo, $userId, $agent, $payload);
                    break;

                case 'crm.find_contact':
                    $results[] = auvvo_brain_fetch_find_contact($pdo, $userId, $payload);
                    break;

                case 'crm.last_messages':
                    $results[] = auvvo_brain_fetch_last_messages($pdo, $agent, $contact, $payload);
                    break;

                case 'crm.get_pipeline':
                    $results[] = auvvo_brain_fetch_pipeline_status($pdo, $userId, $contact);
                    break;

                case 'calendar.list_slots':
                    $results[] = auvvo_brain_fetch_calendar_slots($pdo, $userId, $payload);
                    break;

                default:
                    $results[] = [
                        'tool' => $tool,
                        'ok' => false,
                        'summary' => 'Ferramenta de fetch desconhecida.',
                        'data' => null,
                    ];
            }
        } catch (Throwable $e) {
            error_log('[Auvvo Brain Fetch] ' . $tool . ': ' . $e->getMessage());
            $results[] = [
                'tool' => $tool,
                'ok' => false,
                'summary' => 'Falha ao executar: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    return $results;
}

/**
 * Formata os resultados de fetch como texto que será injetado como mensagem
 * 'user' adicional no segundo turno da LLM.
 *
 * @param list<array{tool:string, ok:bool, summary:string, data:mixed}> $results
 */
function auvvo_brain_format_fetch_results(array $results): string
{
    if ($results === []) {
        return '';
    }
    $lines = ['[[AUVO_FETCH_RESULT]]', 'Resultados das ferramentas que você pediu (use para compor a resposta — NÃO envie esse texto ao cliente):'];
    foreach ($results as $i => $r) {
        $tool = (string) ($r['tool'] ?? '');
        $ok = !empty($r['ok']);
        $summary = (string) ($r['summary'] ?? '');
        $data = $r['data'] ?? null;
        $lines[] = sprintf('— %d) %s — %s', $i + 1, $tool, $ok ? 'OK' : 'ERRO');
        if ($summary !== '') {
            $lines[] = '  ' . $summary;
        }
        if ($data !== null) {
            $encoded = is_string($data)
                ? $data
                : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '') {
                $trimmed = mb_substr($encoded, 0, AUVVO_BRAIN_FETCH_MAX_RESULT_CHARS);
                if (mb_strlen($encoded) > AUVVO_BRAIN_FETCH_MAX_RESULT_CHARS) {
                    $trimmed .= '… [truncado]';
                }
                $lines[] = '  DADOS: ' . $trimmed;
            }
        }
    }
    $lines[] = '';
    $lines[] = 'Agora responda ao cliente usando esses dados. NÃO emita [[AUVO_FETCH]] de novo (já foi feito). Você pode emitir [[AUVO_ACTIONS]] normalmente.';

    return implode("\n", $lines);
}

// ─────────────────────────────────────────────────────────────────────────────
// Implementação das ferramentas de fetch
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed>|null $contact
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_http_preset(PDO $pdo, int $userId, array $payload, ?array $contact): array
{
    require_once __DIR__ . '/auvvo_brain_tools.inc.php';

    $presetId = auvvo_brain_resolve_http_preset_id($pdo, $userId, $payload);
    if ($presetId <= 0) {
        return [
            'tool' => 'http.fetch_preset',
            'ok' => false,
            'summary' => 'Preset HTTP não encontrado.',
            'data' => null,
        ];
    }

    try {
        $st = $pdo->prepare(
            'SELECT id, name, provider_slug, http_method, target_url, headers_json, body_template
               FROM integration_http_presets WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1'
        );
        $st->execute([$presetId, $userId]);
        $preset = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['tool' => 'http.fetch_preset', 'ok' => false, 'summary' => 'DB: ' . $e->getMessage(), 'data' => null];
    }
    if (!$preset) {
        return ['tool' => 'http.fetch_preset', 'ok' => false, 'summary' => 'Preset HTTP não encontrado.', 'data' => null];
    }

    $url = (string) ($preset['target_url'] ?? '');
    if ($url === '') {
        return ['tool' => 'http.fetch_preset', 'ok' => false, 'summary' => 'Preset sem URL.', 'data' => null];
    }

    // Query params dinâmicos vindos do agente
    $query = $payload['query'] ?? [];
    if (is_array($query) && $query !== []) {
        $sep = strpos($url, '?') !== false ? '&' : '?';
        $url .= $sep . http_build_query($query);
    }

    $method = strtoupper((string) ($preset['http_method'] ?? 'GET'));
    $headers = ['Accept: application/json'];
    $headerJson = (string) ($preset['headers_json'] ?? '');
    if ($headerJson !== '') {
        $decoded = json_decode($headerJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }

    // SSRF guard: bloqueia hosts internos
    if (function_exists('auvvo_http_is_url_safe') && !auvvo_http_is_url_safe($url)) {
        return ['tool' => 'http.fetch_preset', 'ok' => false, 'summary' => 'URL bloqueada (SSRF guard).', 'data' => null];
    }
    if (!function_exists('auvvo_http_is_url_safe')) {
        // Fallback simples: bloqueia loopback / private ranges óbvios
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if ($host === 'localhost' || str_starts_with($host, '127.') || str_starts_with($host, '10.') || str_starts_with($host, '192.168.') || $host === '::1') {
            return ['tool' => 'http.fetch_preset', 'ok' => false, 'summary' => 'URL bloqueada (host interno).', 'data' => null];
        }
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AUVVO_BRAIN_FETCH_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Auvvo-AgentBrain/1.0',
    ];
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $body = $payload['body'] ?? null;
        if (is_array($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code >= 400 || !is_string($raw)) {
        return [
            'tool' => 'http.fetch_preset',
            'ok' => false,
            'summary' => 'HTTP ' . $code . ($err !== '' ? ' — ' . $err : ''),
            'data' => null,
        ];
    }

    // Tenta JSON; senão texto puro truncado
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        // Opcional: filtrar caminho via payload {"response_path":"data.items"} (fornecido pelo agente)
        $path = trim((string) ($payload['response_path'] ?? ''));
        if ($path !== '') {
            $val = $decoded;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($val) || !array_key_exists($segment, $val)) {
                    $val = null;
                    break;
                }
                $val = $val[$segment];
            }
            return [
                'tool' => 'http.fetch_preset',
                'ok' => true,
                'summary' => 'OK ' . $code . ' — ' . (string) ($preset['name'] ?? ''),
                'data' => $val,
            ];
        }

        return [
            'tool' => 'http.fetch_preset',
            'ok' => true,
            'summary' => 'OK ' . $code . ' — ' . (string) ($preset['name'] ?? ''),
            'data' => $decoded,
        ];
    }

    return [
        'tool' => 'http.fetch_preset',
        'ok' => true,
        'summary' => 'OK ' . $code . ' — ' . (string) ($preset['name'] ?? ''),
        'data' => mb_substr($raw, 0, AUVVO_BRAIN_FETCH_MAX_RESULT_CHARS),
    ];
}

/**
 * Busca trechos da base de conhecimento do agente.
 *
 * @param array<string,mixed> $agent
 * @param array<string,mixed> $payload
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_kb_search(PDO $pdo, int $userId, array $agent, array $payload): array
{
    $agentId = (int) ($agent['id'] ?? 0);
    $q = trim((string) ($payload['query'] ?? ''));
    if ($agentId <= 0 || $q === '') {
        return ['tool' => 'kb.search', 'ok' => false, 'summary' => 'Faltou query ou agent_id', 'data' => null];
    }
    try {
        $st = $pdo->prepare(
            "SELECT id, file_name, original_name, content
               FROM knowledge_base
              WHERE agent_id = ? AND status = 'trained' AND content IS NOT NULL AND content != ''
              ORDER BY id DESC LIMIT 30"
        );
        $st->execute([$agentId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return ['tool' => 'kb.search', 'ok' => false, 'summary' => 'DB: ' . $e->getMessage(), 'data' => null];
    }

    $words = array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: [], static fn ($w) => mb_strlen((string) $w) >= 3);
    $hits = [];
    foreach ($rows as $row) {
        $content = (string) ($row['content'] ?? '');
        $lower = mb_strtolower($content);
        $score = 0;
        foreach ($words as $w) {
            $score += substr_count($lower, (string) $w);
        }
        if ($score > 0) {
            $hits[] = ['score' => $score, 'name' => (string) ($row['original_name'] ?? $row['file_name'] ?? ''), 'snippet' => mb_substr($content, 0, 600)];
        }
    }
    usort($hits, static fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $hits = array_slice($hits, 0, 3);

    if ($hits === []) {
        return ['tool' => 'kb.search', 'ok' => true, 'summary' => 'Nenhum resultado para "' . $q . '"', 'data' => []];
    }

    return [
        'tool' => 'kb.search',
        'ok' => true,
        'summary' => count($hits) . ' resultado(s) para "' . $q . '"',
        'data' => $hits,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_find_contact(PDO $pdo, int $userId, array $payload): array
{
    $name  = trim((string) ($payload['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '')) ?: '';

    if ($userId <= 0 || ($name === '' && $phone === '')) {
        return ['tool' => 'crm.find_contact', 'ok' => false, 'summary' => 'Faltou name ou phone', 'data' => null];
    }
    try {
        if ($phone !== '') {
            $st = $pdo->prepare(
                'SELECT id, name, phone, jid, email, notes
                   FROM contacts WHERE user_id = ? AND (phone LIKE ? OR jid LIKE ?) LIMIT 5'
            );
            $st->execute([$userId, '%' . $phone . '%', '%' . $phone . '%']);
        } else {
            $st = $pdo->prepare(
                'SELECT id, name, phone, jid, email, notes
                   FROM contacts WHERE user_id = ? AND name LIKE ? LIMIT 5'
            );
            $st->execute([$userId, '%' . $name . '%']);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return ['tool' => 'crm.find_contact', 'ok' => false, 'summary' => 'DB: ' . $e->getMessage(), 'data' => null];
    }
    if ($rows === []) {
        return ['tool' => 'crm.find_contact', 'ok' => true, 'summary' => 'Nenhum contato encontrado', 'data' => []];
    }

    return ['tool' => 'crm.find_contact', 'ok' => true, 'summary' => count($rows) . ' contato(s)', 'data' => $rows];
}

/**
 * @param array<string,mixed> $agent
 * @param array<string,mixed>|null $contact
 * @param array<string,mixed> $payload
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_last_messages(PDO $pdo, array $agent, ?array $contact, array $payload): array
{
    $agentId = (int) ($agent['id'] ?? 0);
    $jid     = trim((string) ($contact['jid'] ?? ''));
    $limit   = max(1, min(20, (int) ($payload['limit'] ?? 5)));
    if ($agentId <= 0 || $jid === '') {
        return ['tool' => 'crm.last_messages', 'ok' => false, 'summary' => 'Faltou agent ou contact_jid', 'data' => null];
    }

    require_once __DIR__ . '/conversation_history.inc.php';
    try {
        $history = getConversationHistory(
            $pdo,
            $agentId,
            $jid,
            $limit,
            $jid,
            preg_replace('/\D+/', '', $jid) ?: ''
        );
    } catch (Throwable $e) {
        return ['tool' => 'crm.last_messages', 'ok' => false, 'summary' => 'Erro histórico: ' . $e->getMessage(), 'data' => null];
    }

    return ['tool' => 'crm.last_messages', 'ok' => true, 'summary' => count($history) . ' mensagem(ns)', 'data' => $history];
}

/**
 * Status atual do contato no pipeline (qual funil, qual estágio).
 *
 * @param array<string,mixed>|null $contact
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_pipeline_status(PDO $pdo, int $userId, ?array $contact): array
{
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return ['tool' => 'crm.get_pipeline', 'ok' => false, 'summary' => 'Contato sem ID', 'data' => null];
    }
    try {
        $st = $pdo->prepare(
            'SELECT cp.name AS pipeline_name, cps.label AS stage_label, cps.slug AS stage_slug,
                    c.created_at AS lead_created, c.updated_at AS last_update, c.notes
               FROM contacts c
               LEFT JOIN crm_pipelines cp ON cp.id = c.pipeline_id AND cp.user_id = c.user_id
               LEFT JOIN crm_pipeline_stages cps ON cps.id = c.pipeline_stage_id AND cps.pipeline_id = c.pipeline_id
              WHERE c.id = ? AND c.user_id = ? LIMIT 1'
        );
        $st->execute([$contactId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['tool' => 'crm.get_pipeline', 'ok' => false, 'summary' => 'DB: ' . $e->getMessage(), 'data' => null];
    }
    if (!$row) {
        return ['tool' => 'crm.get_pipeline', 'ok' => true, 'summary' => 'Contato sem pipeline', 'data' => []];
    }
    $summary = (string) ($row['pipeline_name'] ?? '-') . ' / ' . (string) ($row['stage_label'] ?? '?');

    return ['tool' => 'crm.get_pipeline', 'ok' => true, 'summary' => $summary, 'data' => $row];
}

/**
 * Slots livres no Google Calendar nas próximas N horas.
 * payload: {hours_ahead?:int (default 48), slot_minutes?:int (default 60),
 *          business_hour_start?:int (default 9), business_hour_end?:int (default 18)}
 *
 * @param array<string,mixed> $payload
 * @return array{tool:string, ok:bool, summary:string, data:mixed}
 */
function auvvo_brain_fetch_calendar_slots(PDO $pdo, int $userId, array $payload): array
{
    require_once __DIR__ . '/GoogleCalendar.php';
    if (!GoogleCalendar::isConfigured($pdo, $userId) || GoogleCalendar::loadToken($pdo, $userId) === null) {
        return ['tool' => 'calendar.list_slots', 'ok' => false, 'summary' => 'Google Calendar não conectado', 'data' => null];
    }
    $hoursAhead   = max(2, min(168, (int) ($payload['hours_ahead'] ?? 48)));
    $slotMinutes  = max(15, min(240, (int) ($payload['slot_minutes'] ?? 60)));
    $bhStart      = max(0, min(23, (int) ($payload['business_hour_start'] ?? 9)));
    $bhEnd        = max($bhStart + 1, min(24, (int) ($payload['business_hour_end'] ?? 18)));
    $tz           = trim((string) ($payload['timezone'] ?? 'America/Sao_Paulo'));

    $now = new DateTimeImmutable('now', new DateTimeZone($tz));
    $end = $now->modify('+' . $hoursAhead . ' hours');
    $busyRes = GoogleCalendar::freeBusyQuery(
        $pdo,
        $userId,
        $now->format(DateTimeInterface::RFC3339),
        $end->format(DateTimeInterface::RFC3339),
        $tz
    );
    if (!empty($busyRes['error'])) {
        return ['tool' => 'calendar.list_slots', 'ok' => false, 'summary' => 'Calendar: ' . ($busyRes['message'] ?? 'erro'), 'data' => null];
    }
    $busy = [];
    foreach ((array) ($busyRes['busy'] ?? []) as $b) {
        if (!is_array($b)) continue;
        $busy[] = [
            'start' => strtotime((string) ($b['start'] ?? '')) ?: 0,
            'end'   => strtotime((string) ($b['end'] ?? '')) ?: 0,
        ];
    }

    $isFreeSlot = static function (int $s, int $e) use ($busy): bool {
        foreach ($busy as $b) {
            if ($s < $b['end'] && $e > $b['start']) {
                return false;
            }
        }
        return true;
    };

    $slots = [];
    $cursor = $now->setTime(max($now->format('G'), $bhStart), 0);
    if ((int) $cursor->format('H') < $now->format('H')) {
        $cursor = $now;
    }
    $maxIter = 200;
    while ($cursor < $end && count($slots) < 12 && $maxIter-- > 0) {
        $h = (int) $cursor->format('H');
        $dow = (int) $cursor->format('N'); // 1=segunda, 7=domingo
        if ($dow >= 6) {
            // pula fim de semana
            $cursor = $cursor->modify('+1 day')->setTime($bhStart, 0);
            continue;
        }
        if ($h < $bhStart) {
            $cursor = $cursor->setTime($bhStart, 0);
            continue;
        }
        if ($h >= $bhEnd) {
            $cursor = $cursor->modify('+1 day')->setTime($bhStart, 0);
            continue;
        }
        $slotEnd = $cursor->modify('+' . $slotMinutes . ' minutes');
        if ((int) $slotEnd->format('H') > $bhEnd || ((int) $slotEnd->format('H') === $bhEnd && (int) $slotEnd->format('i') > 0)) {
            $cursor = $cursor->modify('+1 day')->setTime($bhStart, 0);
            continue;
        }
        if ($isFreeSlot($cursor->getTimestamp(), $slotEnd->getTimestamp())) {
            $slots[] = [
                'start' => $cursor->format(DateTimeInterface::RFC3339),
                'end'   => $slotEnd->format(DateTimeInterface::RFC3339),
                'label' => $cursor->format('d/m H:i'),
            ];
        }
        $cursor = $cursor->modify('+' . $slotMinutes . ' minutes');
    }

    return [
        'tool' => 'calendar.list_slots',
        'ok' => true,
        'summary' => count($slots) . ' horário(s) livre(s) nas próximas ' . $hoursAhead . 'h',
        'data' => $slots,
    ];
}

/**
 * Trecho de prompt explicando as fetch tools (incluir no system prompt).
 * Use só se houver presets/integrações que justifiquem.
 */
function auvvo_brain_fetch_prompt_section(PDO $pdo, int $userId, array $agent): string
{
    $lines = [];
    $lines[] = '╔ FERRAMENTAS DE BUSCA (puxar dados ANTES de responder)';
    $lines[] = str_repeat('═', 60);
    $lines[] = 'Se precisar de DADOS pra responder corretamente (preço atual, estoque,';
    $lines[] = 'status de pedido, ficha do cliente, trecho de manual…), emita um bloco';
    $lines[] = 'FETCH em vez de responder direto. O backend executa, devolve o resultado';
    $lines[] = 'na sua próxima fala, e aí você compõe a mensagem final.';
    $lines[] = '';
    $lines[] = 'Formato (uma linha em branco antes do bloco):';
    $lines[] = '';
    $lines[] = '[[AUVO_FETCH]]';
    $lines[] = '[ {"tool":"http.fetch_preset","payload":{"preset_id":3,"query":{"sku":"ABC"}}} ]';
    $lines[] = '';
    $lines[] = 'Ferramentas disponíveis:';
    $lines[] = '- http.fetch_preset   — chama API REST cadastrada (Integrações). payload: {preset_id|name, query:{}, body:{}, response_path?:"a.b.c"}';
    $lines[] = '- kb.search           — busca trechos da Base de Conhecimento do agente. payload: {query:"texto"}';
    $lines[] = '- crm.find_contact    — busca contato por nome/telefone. payload: {name|phone}';
    $lines[] = '- crm.last_messages   — últimas N mensagens da conversa. payload: {limit:5}';
    $lines[] = '- crm.get_pipeline    — funil/estágio atual do contato. payload: {}';
    $lines[] = '- calendar.list_slots — slots livres na agenda Google (se conectada). payload: {hours_ahead:48, slot_minutes:60, business_hour_start:9, business_hour_end:18, timezone:"America/Sao_Paulo"}';
    $lines[] = '';
    $lines[] = 'REGRAS:';
    $lines[] = '- Emita FETCH APENAS quando precisar de dados. Para a maioria das msgs, responda direto.';
    $lines[] = '- 1 ciclo de fetch por turno (anti-loop). Se faltar mais info, peça ao cliente.';
    $lines[] = '- Quando você for chamado de novo, virá um bloco [[AUVO_FETCH_RESULT]] com os dados.';
    $lines[] = '  Aí você compõe a resposta final pro cliente, sem novo FETCH.';
    $lines[] = str_repeat('═', 60);

    return implode("\n", $lines);
}
