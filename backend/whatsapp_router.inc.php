<?php
declare(strict_types=1);

/**
 * Roteador multi-agente para conexões WhatsApp.
 *
 * Modos suportados (whatsapp_connections.routing_mode):
 *  - single    : usa default_agent_id (comportamento clássico)
 *  - rules     : escolhe agente por regras (keyword/tag/regex) cadastradas em
 *                whatsapp_routing_rules — primeira regra que casar manda
 *  - ai_router : faz UMA chamada LLM curta com a lista de agentes e os
 *                respectivos "papéis" para decidir o melhor agente
 *
 * Pinning (sticky): uma vez decidido o agente para um contato, fixamos em
 *   contacts.agent_id. Mensagens seguintes vão direto pra esse agente sem nova
 *   chamada do roteador. Para trocar, basta resetar contacts.agent_id (ou usar
 *   crm.assign_agent dentro do agente).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_connections.inc.php';

const AUVVO_ROUTER_DEFAULT_MODEL = 'openrouter/openai/gpt-4o-mini';
const AUVVO_ROUTER_MAX_MSG_CHARS = 600;
const AUVVO_ROUTER_LOG_TABLE = 'whatsapp_routing_log';

/**
 * @return list<array{id:int,name:string,role:string,agent_type:string,router_hint:string,priority:int}>
 */
function auvvo_router_list_members(PDO $pdo, int $userId, int $connectionId): array
{
    if ($userId <= 0 || $connectionId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT a.id, a.name, a.role, a.agent_type,
                    COALESCE(NULLIF(wca.router_hint, ""), a.router_hint, "") AS router_hint,
                    wca.priority
               FROM whatsapp_connection_agents wca
               INNER JOIN agents a ON a.id = wca.agent_id AND a.user_id = wca.user_id
              WHERE wca.connection_id = ? AND wca.user_id = ? AND a.status != "draft"
              ORDER BY wca.priority DESC, a.name ASC'
        );
        $st->execute([$connectionId, $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'name'        => (string) ($r['name'] ?? ''),
                'role'        => (string) ($r['role'] ?? ''),
                'agent_type'  => (string) ($r['agent_type'] ?? ''),
                'router_hint' => (string) ($r['router_hint'] ?? ''),
                'priority'    => (int) ($r['priority'] ?? 0),
            ];
        }, $rows);
    } catch (PDOException $e) {
        error_log('[Auvvo Router] list_members: ' . $e->getMessage());

        return [];
    }
}

/**
 * Retorna o agente já fixado para este contato nesta conexão, ou null.
 */
function auvvo_router_pinned_agent_id(array $contact): int
{
    return (int) ($contact['agent_id'] ?? 0);
}

/**
 * Fixa agente para o contato (sticky routing).
 */
function auvvo_router_pin_agent(PDO $pdo, int $userId, int $contactId, int $agentId): void
{
    if ($userId <= 0 || $contactId <= 0 || $agentId <= 0) {
        return;
    }
    try {
        $pdo->prepare(
            'UPDATE contacts SET agent_id = ? WHERE id = ? AND user_id = ?'
        )->execute([$agentId, $contactId, $userId]);
    } catch (PDOException $e) {
        error_log('[Auvvo Router] pin_agent: ' . $e->getMessage());
    }
}

/**
 * Decide qual agente atende esta mensagem.
 *
 * @param array<string,mixed> $connection
 * @param array<string,mixed> $contact
 * @return array{agent_id:int, source:string, reason:string}
 */
function auvvo_router_pick_agent_for_message(
    PDO $pdo,
    int $userId,
    array $connection,
    array $contact,
    string $messageBody
): array {
    $connectionId = (int) ($connection['id'] ?? 0);
    $contactId    = (int) ($contact['id'] ?? 0);

    // 1) Sticky: já há agente fixado pro contato.
    $pinned = auvvo_router_pinned_agent_id($contact);
    if ($pinned > 0) {
        return [
            'agent_id' => $pinned,
            'source'   => 'sticky',
            'reason'   => 'Contato já atribuído (contacts.agent_id)',
        ];
    }

    $mode = (string) ($connection['routing_mode'] ?? 'single');
    $defaultId  = (int) ($connection['default_agent_id'] ?? 0);
    $fallbackId = (int) ($connection['routing_fallback_agent_id'] ?? 0);
    if ($fallbackId <= 0) {
        $fallbackId = $defaultId;
    }

    // 2) Modo single — sempre o default.
    if ($mode === 'single' || $mode === '') {
        if ($defaultId > 0) {
            auvvo_router_pin_agent($pdo, $userId, $contactId, $defaultId);
        }

        return [
            'agent_id' => $defaultId,
            'source'   => 'single',
            'reason'   => 'routing_mode=single, usando default_agent_id',
        ];
    }

    // 3) Modo rules — primeira regra ativa que casar.
    if ($mode === 'rules') {
        $chosen = auvvo_router_match_rules($pdo, $userId, $connectionId, $contact, $messageBody);
        if ($chosen > 0) {
            auvvo_router_pin_agent($pdo, $userId, $contactId, $chosen);
            auvvo_router_log($pdo, $userId, $connectionId, $contactId, $chosen, 'rules', 'Regra de roteamento casou');

            return [
                'agent_id' => $chosen,
                'source'   => 'rules',
                'reason'   => 'Regra de keyword/tag/regex casou',
            ];
        }
        if ($fallbackId > 0) {
            auvvo_router_pin_agent($pdo, $userId, $contactId, $fallbackId);
            auvvo_router_log($pdo, $userId, $connectionId, $contactId, $fallbackId, 'rules_fallback', 'Sem match, usando fallback');

            return [
                'agent_id' => $fallbackId,
                'source'   => 'rules_fallback',
                'reason'   => 'Nenhuma regra casou — usando agente fallback',
            ];
        }

        return ['agent_id' => 0, 'source' => 'rules_no_match', 'reason' => 'Sem regra e sem fallback'];
    }

    // 4) Modo ai_router — pergunta ao LLM qual agente é melhor.
    if ($mode === 'ai_router') {
        $members = auvvo_router_list_members($pdo, $userId, $connectionId);
        if (count($members) <= 1) {
            $only = isset($members[0]) ? (int) $members[0]['id'] : $defaultId;
            if ($only > 0) {
                auvvo_router_pin_agent($pdo, $userId, $contactId, $only);
            }

            return ['agent_id' => $only, 'source' => 'ai_router_single', 'reason' => 'Só um agente vinculado'];
        }
        $chosen = auvvo_router_ai_pick(
            $userId,
            $members,
            $messageBody,
            (string) ($connection['routing_model'] ?? '')
        );
        if ($chosen > 0) {
            auvvo_router_pin_agent($pdo, $userId, $contactId, $chosen);
            auvvo_router_log($pdo, $userId, $connectionId, $contactId, $chosen, 'ai_router', 'LLM escolheu');

            return [
                'agent_id' => $chosen,
                'source'   => 'ai_router',
                'reason'   => 'LLM router escolheu o melhor agente para essa mensagem',
            ];
        }
        if ($fallbackId > 0) {
            auvvo_router_pin_agent($pdo, $userId, $contactId, $fallbackId);
            auvvo_router_log($pdo, $userId, $connectionId, $contactId, $fallbackId, 'ai_router_fallback', 'LLM falhou, fallback');

            return ['agent_id' => $fallbackId, 'source' => 'ai_router_fallback', 'reason' => 'LLM router não respondeu — usando fallback'];
        }
    }

    return ['agent_id' => $defaultId, 'source' => 'default', 'reason' => 'Modo desconhecido — usando default_agent_id'];
}

/**
 * Avalia regras (keyword, tag, regex) — primeira que casar manda.
 *
 * @param array<string,mixed> $contact
 */
function auvvo_router_match_rules(
    PDO $pdo,
    int $userId,
    int $connectionId,
    array $contact,
    string $messageBody
): int {
    if ($userId <= 0 || $connectionId <= 0) {
        return 0;
    }
    $msgLower = mb_strtolower(trim($messageBody));
    $contactTags = [];
    if (!empty($contact['tags'])) {
        $rawTags = is_array($contact['tags']) ? $contact['tags'] : explode(',', (string) $contact['tags']);
        foreach ($rawTags as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $contactTags[] = mb_strtolower($t);
            }
        }
    }

    try {
        $st = $pdo->prepare(
            'SELECT id, agent_id, rule_type, rule_value
               FROM whatsapp_routing_rules
              WHERE user_id = ? AND connection_id = ? AND is_active = 1
              ORDER BY priority DESC, id ASC'
        );
        $st->execute([$userId, $connectionId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $type = (string) ($row['rule_type'] ?? 'keyword');
            $val  = trim((string) ($row['rule_value'] ?? ''));
            if ($val === '') {
                continue;
            }

            $matched = false;
            switch ($type) {
                case 'keyword':
                    $valLower = mb_strtolower($val);
                    // Suporta múltiplas keywords separadas por vírgula
                    $needles = array_filter(array_map('trim', explode(',', $valLower)));
                    foreach ($needles as $n) {
                        if ($n !== '' && mb_strpos($msgLower, $n) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'tag':
                    $valLower = mb_strtolower($val);
                    $needles = array_filter(array_map('trim', explode(',', $valLower)));
                    foreach ($needles as $n) {
                        if ($n !== '' && in_array($n, $contactTags, true)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'regex':
                    $pat = '/' . str_replace('/', '\/', $val) . '/iu';
                    $matched = @preg_match($pat, $messageBody) === 1;
                    break;
            }

            if ($matched) {
                return (int) $row['agent_id'];
            }
        }
    } catch (PDOException $e) {
        error_log('[Auvvo Router] match_rules: ' . $e->getMessage());
    }

    return 0;
}

/**
 * AI router — LLM curtinho decide o melhor agente.
 *
 * @param list<array{id:int,name:string,role:string,agent_type:string,router_hint:string}> $members
 */
function auvvo_router_ai_pick(int $userId, array $members, string $messageBody, string $modelOverride = ''): int
{
    if ($members === []) {
        return 0;
    }
    $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    if ($apiKey === '') {
        error_log('[Auvvo Router] OPENROUTER_API_KEY não configurada — ai_router indisponível');

        return 0;
    }

    $model = trim($modelOverride);
    if ($model === '') {
        $model = AUVVO_ROUTER_DEFAULT_MODEL;
    }
    if (str_starts_with($model, 'openrouter/')) {
        $model = substr($model, strlen('openrouter/'));
    }

    $msg = trim($messageBody);
    if ($msg === '') {
        return (int) $members[0]['id'];
    }
    if (mb_strlen($msg) > AUVVO_ROUTER_MAX_MSG_CHARS) {
        $msg = mb_substr($msg, 0, AUVVO_ROUTER_MAX_MSG_CHARS) . '…';
    }

    $list = [];
    foreach ($members as $i => $m) {
        $hint = $m['router_hint'] !== '' ? $m['router_hint'] : $m['role'];
        $list[] = sprintf(
            '%d) %s (tipo: %s) — %s',
            $i + 1,
            $m['name'],
            $m['agent_type'] !== '' ? $m['agent_type'] : 'geral',
            $hint !== '' ? $hint : 'agente sem descrição'
        );
    }
    $listStr = implode("\n", $list);

    $systemPrompt = <<<SYS
Você é um roteador de mensagens de WhatsApp. Sua única tarefa é decidir QUAL agente desta lista deve atender a mensagem recebida.

Responda APENAS com o NÚMERO da opção (1, 2, 3…). Sem texto, sem explicação, sem pontuação. Apenas o número.

Se NENHUM agente parecer ideal, escolha o primeiro (1) — ele é o padrão.
SYS;

    $userPrompt = "AGENTES DISPONÍVEIS:\n{$listStr}\n\nMENSAGEM RECEBIDA:\n\"\"\"\n{$msg}\n\"\"\"\n\nQual número?";

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'max_tokens'  => 4,
        'temperature' => 0,
        'stream'      => false,
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://auvvo.com',
            'X-Title: Auvvo WhatsApp Router',
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '' || $code >= 400 || !is_string($raw)) {
        error_log('[Auvvo Router] ai_pick HTTP ' . $code . ' err=' . $err);

        return 0;
    }
    $resp = json_decode($raw, true);
    if (!is_array($resp)) {
        return 0;
    }
    $content = trim((string) ($resp['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return 0;
    }
    if (!preg_match('/(\d+)/', $content, $m)) {
        return 0;
    }
    $idx = max(1, min((int) $m[1], count($members))) - 1;

    return (int) $members[$idx]['id'];
}

/**
 * Loga decisão pra UI/auditoria. Falha silenciosa.
 */
function auvvo_router_log(
    PDO $pdo,
    int $userId,
    int $connectionId,
    int $contactId,
    int $agentId,
    string $source,
    string $reason
): void {
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . AUVVO_ROUTER_LOG_TABLE . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                connection_id INT UNSIGNED NOT NULL,
                contact_id INT UNSIGNED NOT NULL,
                agent_id INT UNSIGNED NOT NULL,
                source VARCHAR(40) NOT NULL,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_created (user_id, created_at),
                KEY idx_contact (contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->prepare(
            'INSERT INTO ' . AUVVO_ROUTER_LOG_TABLE . '
             (user_id, connection_id, contact_id, agent_id, source, reason)
             VALUES (?,?,?,?,?,?)'
        )->execute([$userId, $connectionId, $contactId, $agentId, $source, mb_substr($reason, 0, 250)]);
    } catch (PDOException $e) {
        // log opcional
    }
}

/**
 * Conveniência: carrega o "brain" do agente selecionado pelo roteador.
 *
 * Versão multi-agente do auvvo_whatsapp_pick_brain_agent.
 *
 * @param array<string,mixed> $connection
 * @param array<string,mixed>|null $contact
 * @return array<string,mixed>|null
 */
function auvvo_router_select_brain(
    PDO $pdo,
    int $userId,
    array $connection,
    ?array $contact,
    string $messageBody = ''
): ?array {
    $contactArr = is_array($contact) ? $contact : [];

    $decision = auvvo_router_pick_agent_for_message(
        $pdo,
        $userId,
        $connection,
        $contactArr,
        $messageBody
    );
    $agentId = (int) ($decision['agent_id'] ?? 0);
    if ($agentId > 0) {
        $brain = auvvo_whatsapp_load_agent_brain($pdo, $userId, $agentId);
        if ($brain) {
            return $brain;
        }
    }

    // Fallback final: comportamento clássico (default + contact override).
    return auvvo_whatsapp_pick_brain_agent($pdo, $userId, $connection, $contactArr);
}
