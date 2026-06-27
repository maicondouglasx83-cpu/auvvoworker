<?php
declare(strict_types=1);

/**
 * Evita disparos duplicados de automações (ex.: vários fluxos de boas-vindas no primeiro WhatsApp).
 */
require_once __DIR__ . '/migrations.php';

function auvvo_crm_dedupe_global_key(string $triggerType, string $triggerValue): ?string
{
    if ($triggerType === 'whatsapp_first') {
        return 'global:whatsapp_first';
    }
    if ($triggerType === 'contact_created' && in_array($triggerValue, ['whatsapp', '*'], true)) {
        return 'global:first_contact';
    }
    return null;
}

function auvvo_crm_dedupe_source_key(
    string $sourceType,
    int $sourceId,
    string $triggerType,
    string $triggerValue
): string {
    return $sourceType . ':' . $sourceId . ':' . $triggerType . ':' . $triggerValue;
}

function auvvo_crm_dedupe_is_blocked(
    PDO $pdo,
    int $userId,
    int $contactId,
    ?string $globalKey,
    ?string $sourceKey
): bool {
    if ($userId <= 0 || $contactId <= 0) {
        return true;
    }
    try {
        if ($globalKey !== null && $globalKey !== '') {
            $st = $pdo->prepare(
                'SELECT id FROM crm_automation_dedupe
                 WHERE user_id = ? AND contact_id = ? AND dedupe_key = ? LIMIT 1'
            );
            $st->execute([$userId, $contactId, $globalKey]);

            if ($st->fetchColumn()) {
                return true;
            }
        }
        if ($sourceKey !== null && $sourceKey !== '') {
            $st = $pdo->prepare(
                'SELECT id FROM crm_automation_dedupe
                 WHERE user_id = ? AND contact_id = ? AND dedupe_key = ? LIMIT 1'
            );
            $st->execute([$userId, $contactId, $sourceKey]);

            return (bool) $st->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] dedupe_is_blocked: ' . $e->getMessage());

        return true;
    }

    return false;
}

function auvvo_crm_dedupe_mark(
    PDO $pdo,
    int $userId,
    int $contactId,
    ?string $globalKey,
    ?string $sourceKey
): void {
    if ($userId <= 0 || $contactId <= 0) {
        return;
    }
    $keys = [];
    if ($globalKey !== null && $globalKey !== '') {
        $keys[] = $globalKey;
    }
    if ($sourceKey !== null && $sourceKey !== '' && !in_array($sourceKey, $keys, true)) {
        $keys[] = $sourceKey;
    }
    auvvo_crm_dedupe_mark_keys($pdo, $userId, $contactId, $keys);
}

/**
 * @param list<string> $keys
 */
function auvvo_crm_dedupe_mark_keys(PDO $pdo, int $userId, int $contactId, array $keys): void
{
    if ($userId <= 0 || $contactId <= 0 || $keys === []) {
        return;
    }
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
    if ($keys === []) {
        return;
    }
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO crm_automation_dedupe (user_id, contact_id, dedupe_key) VALUES (?,?,?)'
        );
        foreach ($keys as $k) {
            $ins->execute([$userId, $contactId, $k]);
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] dedupe_mark: ' . $e->getMessage());
    }
}

/**
 * Chaves de dedupe para um fluxo visual (inclui escopo unificado por linha WhatsApp).
 *
 * @param array<string,mixed> $context
 * @return list<string>
 */
function auvvo_crm_flow_dedupe_keys(
    int $flowId,
    string $triggerType,
    string $triggerValue,
    array $context = []
): array {
    $keys = [auvvo_crm_dedupe_source_key('flow', $flowId, $triggerType, $triggerValue)];
    $globalKey = auvvo_crm_dedupe_global_key($triggerType, $triggerValue);
    if ($globalKey !== null) {
        $keys[] = $globalKey;
    }
    $connId = (int) ($context['whatsapp_connection_id'] ?? 0);
    if ($connId > 0 && in_array($triggerType, ['whatsapp_first', 'whatsapp_message'], true)) {
        $keys[] = 'flow:' . $flowId . ':wa_inbound:conn:' . $connId;
        $keys[] = 'global:wa_inbound:conn:' . $connId;
    }

    return array_values(array_unique($keys));
}

/**
 * Reserva dedupe de forma atômica antes de executar o fluxo (evita TOCTOU).
 *
 * @param array<string,mixed> $context
 */
function auvvo_crm_dedupe_claim_flow(
    PDO $pdo,
    int $userId,
    array $contact,
    int $flowId,
    string $triggerType,
    string $triggerValue,
    array $context = []
): bool {
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0 || $flowId <= 0) {
        return false;
    }
    $keys = auvvo_crm_flow_dedupe_keys($flowId, $triggerType, $triggerValue, $context);
    if ($keys === []) {
        return true;
    }
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTx = true;
        } else {
            $ownTx = false;
        }
        foreach ($keys as $k) {
            $st = $pdo->prepare(
                'SELECT id FROM crm_automation_dedupe
                 WHERE user_id = ? AND contact_id = ? AND dedupe_key = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$userId, $contactId, $k]);
            if ($st->fetchColumn()) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return false;
            }
        }
        $ins = $pdo->prepare(
            'INSERT INTO crm_automation_dedupe (user_id, contact_id, dedupe_key) VALUES (?,?,?)'
        );
        foreach ($keys as $k) {
            $ins->execute([$userId, $contactId, $k]);
        }
        if ($ownTx) {
            $pdo->commit();
        }

        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Auvvo] dedupe_claim_flow: ' . $e->getMessage());

        return false;
    }
}

/**
 * @param 'rule'|'flow' $sourceType
 */
function auvvo_crm_dedupe_should_skip_source(
    PDO $pdo,
    int $userId,
    array $contact,
    string $sourceType,
    int $sourceId,
    string $triggerType,
    string $triggerValue
): bool {
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return true;
    }
    $globalKey = auvvo_crm_dedupe_global_key($triggerType, $triggerValue);
    $sourceKey = $sourceId > 0
        ? auvvo_crm_dedupe_source_key($sourceType, $sourceId, $triggerType, $triggerValue)
        : null;

    return auvvo_crm_dedupe_is_blocked($pdo, $userId, $contactId, $globalKey, $sourceKey);
}

/**
 * Fluxos visuais: dedupe por fluxo+gatilho (não bloqueia outros fluxos no primeiro WhatsApp).
 */
function auvvo_crm_dedupe_should_skip_flow(
    PDO $pdo,
    int $userId,
    array $contact,
    int $flowId,
    string $triggerType,
    string $triggerValue,
    array $context = []
): bool {
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0 || $flowId <= 0) {
        return true;
    }
    foreach (auvvo_crm_flow_dedupe_keys($flowId, $triggerType, $triggerValue, $context) as $key) {
        if (auvvo_crm_dedupe_is_blocked($pdo, $userId, $contactId, null, $key)) {
            return true;
        }
    }

    return false;
}

function auvvo_crm_dedupe_mark_flow(
    PDO $pdo,
    int $userId,
    array $contact,
    int $flowId,
    string $triggerType,
    string $triggerValue,
    array $context = []
): void {
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0 || $flowId <= 0) {
        return;
    }
    auvvo_crm_dedupe_mark_keys(
        $pdo,
        $userId,
        $contactId,
        auvvo_crm_flow_dedupe_keys($flowId, $triggerType, $triggerValue, $context)
    );
}

/**
 * Cooldown opcional no nó Início para whatsapp_message (once_per_day).
 *
 * @param array<string, array> $nodes
 */
function auvvo_flow_trigger_cooldown_skip(
    PDO $pdo,
    int $userId,
    int $flowId,
    array $nodes,
    string $triggerNodeId,
    array $contact,
    string $triggerType
): bool {
    if ($triggerType !== 'whatsapp_message') {
        return false;
    }
    $node = $nodes[$triggerNodeId] ?? null;
    if (!is_array($node)) {
        return false;
    }
    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
    $mode = (string) ($data['cooldown_mode'] ?? 'none');
    if ($mode === 'none' || $mode === '') {
        return false;
    }
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return false;
    }
    $key = 'flow:' . $flowId . ':cooldown:' . $mode;
    if ($mode === 'once_per_day') {
        $key .= ':' . date('Y-m-d');
    }

    return auvvo_crm_dedupe_is_blocked($pdo, $userId, $contactId, null, $key);
}

/**
 * Marca cooldown após fluxo disparar.
 *
 * @param array<string, array> $nodes
 */
function auvvo_flow_trigger_cooldown_mark(
    PDO $pdo,
    int $userId,
    int $flowId,
    array $nodes,
    string $triggerNodeId,
    array $contact,
    string $triggerType
): void {
    if ($triggerType !== 'whatsapp_message') {
        return;
    }
    $node = $nodes[$triggerNodeId] ?? null;
    if (!is_array($node)) {
        return;
    }
    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
    $mode = (string) ($data['cooldown_mode'] ?? 'none');
    if ($mode === 'none' || $mode === '') {
        return;
    }
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return;
    }
    $key = 'flow:' . $flowId . ':cooldown:' . $mode;
    if ($mode === 'once_per_day') {
        $key .= ':' . date('Y-m-d');
    }
    auvvo_crm_dedupe_mark($pdo, $userId, $contactId, null, $key);
}

/**
 * @param 'rule'|'flow' $sourceType
 */
function auvvo_crm_dedupe_mark_source(
    PDO $pdo,
    int $userId,
    array $contact,
    string $sourceType,
    int $sourceId,
    string $triggerType,
    string $triggerValue
): void {
    $contactId = (int) ($contact['id'] ?? 0);
    if ($contactId <= 0) {
        return;
    }
    $globalKey = auvvo_crm_dedupe_global_key($triggerType, $triggerValue);
    $sourceKey = $sourceId > 0
        ? auvvo_crm_dedupe_source_key($sourceType, $sourceId, $triggerType, $triggerValue)
        : null;
    auvvo_crm_dedupe_mark($pdo, $userId, $contactId, $globalKey, $sourceKey);
}
