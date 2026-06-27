<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/crm_automation.inc.php';

/**
 * Registra uma compra e atualiza métricas LTV do contato.
 */
function auvvo_crm_record_purchase(
    PDO $pdo,
    int $userId,
    int $contactId,
    array $opts = []
): void {
    auvvo_run_migrations($pdo);
    if ($contactId <= 0) {
        return;
    }

    $amount = isset($opts['amount']) ? (float) $opts['amount'] : null;
    $product = trim((string) ($opts['product_name'] ?? ''));
    $source = trim((string) ($opts['source'] ?? ''));
    $externalId = trim((string) ($opts['external_id'] ?? ''));
    $purchasedAt = $opts['purchased_at'] ?? date('Y-m-d H:i:s');

    try {
        $pdo->prepare(
            'INSERT INTO contact_purchases (user_id, contact_id, purchased_at, amount, product_name, source, external_id)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $contactId,
            $purchasedAt,
            $amount,
            $product !== '' ? $product : null,
            $source !== '' ? $source : null,
            $externalId !== '' ? $externalId : null,
        ]);
    } catch (PDOException $e) {
        error_log('[Auvvo] record_purchase: ' . $e->getMessage());
        return;
    }

    $avgCycle = auvvo_crm_compute_avg_cycle_days($pdo, $userId, $contactId);

    try {
        $pdo->prepare(
            'UPDATE contacts SET
                last_purchase_at = ?,
                purchase_count = COALESCE(purchase_count, 0) + 1,
                avg_purchase_cycle_days = COALESCE(?, avg_purchase_cycle_days)
             WHERE id = ? AND user_id = ?'
        )->execute([$purchasedAt, $avgCycle, $contactId, $userId]);
    } catch (PDOException $e) {
    }

    try {
        $pdo->prepare('DELETE FROM crm_ltv_pending WHERE user_id = ? AND contact_id = ?')
            ->execute([$userId, $contactId]);
    } catch (PDOException $e) {
    }
}

/**
 * Média em dias entre as últimas compras (mín. 2 registros).
 */
function auvvo_crm_compute_avg_cycle_days(PDO $pdo, int $userId, int $contactId): ?int
{
    try {
        $st = $pdo->prepare(
            'SELECT purchased_at FROM contact_purchases
             WHERE user_id = ? AND contact_id = ?
             ORDER BY purchased_at DESC LIMIT 12'
        );
        $st->execute([$userId, $contactId]);
        $dates = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return null;
    }

    if (count($dates) < 2) {
        return null;
    }

    $gaps = [];
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $t1 = strtotime((string) $dates[$i]);
        $t2 = strtotime((string) $dates[$i + 1]);
        if ($t1 && $t2) {
            $gaps[] = (int) round(abs($t1 - $t2) / 86400);
        }
    }
    if ($gaps === []) {
        return null;
    }

    return (int) max(1, round(array_sum($gaps) / count($gaps)));
}

/**
 * Dias desde a última compra.
 */
function auvvo_crm_days_since_purchase(array $contact): ?int
{
    $at = $contact['last_purchase_at'] ?? null;
    if (!$at) {
        return null;
    }
    $ts = strtotime((string) $at);
    if (!$ts) {
        return null;
    }

    return (int) floor((time() - $ts) / 86400);
}

/**
 * Verifica se o contato está "inativo" vs regra LTV.
 */
function auvvo_crm_ltv_contact_inactive(array $contact, array $ruleConfig): bool
{
    $purchaseCount = (int) ($contact['purchase_count'] ?? 0);
    $minPurchases = max(1, (int) ($ruleConfig['min_purchases'] ?? 2));
    if ($purchaseCount < $minPurchases) {
        return false;
    }

    $daysSince = auvvo_crm_days_since_purchase($contact);
    if ($daysSince === null) {
        return false;
    }

    $cycleDays = (int) ($contact['avg_purchase_cycle_days'] ?? 0);
    if ($cycleDays <= 0) {
        $cycleDays = max(1, (int) ($ruleConfig['cycle_days'] ?? 30));
    }

    $missFactor = max(1, (float) ($ruleConfig['miss_factor'] ?? 2));
    $inactiveAfter = (int) ($ruleConfig['inactive_after_days'] ?? 0);
    $threshold = $inactiveAfter > 0
        ? $inactiveAfter
        : (int) ceil($cycleDays * $missFactor);

    return $daysSince >= $threshold;
}

/**
 * Processa gatilhos LTV para todos os usuários (cron / worker).
 */
function auvvo_crm_process_ltv_triggers(PDO $pdo, int $limitPerUser = 200): int
{
    auvvo_run_migrations($pdo);
    $fired = 0;

    try {
        $users = $pdo->query(
            "SELECT DISTINCT user_id FROM crm_automations WHERE is_active = 1 AND trigger_type = 'ltv_inactive'"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return 0;
    }

    require_once __DIR__ . '/Contacts.php';
    $crm = new Contacts($pdo);

    foreach ($users as $userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            continue;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM crm_automations WHERE user_id = ? AND is_active = 1 AND trigger_type = ?'
        );
        $stmt->execute([$userId, 'ltv_inactive']);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rules === []) {
            continue;
        }

        $cSt = $pdo->prepare(
            'SELECT id, user_id, agent_id, jid, name, phone, email, company, stage, tags,
                    last_purchase_at, purchase_count, avg_purchase_cycle_days
             FROM contacts
             WHERE user_id = ? AND purchase_count >= 1 AND last_purchase_at IS NOT NULL
             ORDER BY last_purchase_at ASC
             LIMIT ' . (int) $limitPerUser
        );
        $cSt->execute([$userId]);

        while ($row = $cSt->fetch(PDO::FETCH_ASSOC)) {
            $contactId = (int) $row['id'];
            $row['tags'] = $row['tags'] ? json_decode((string) $row['tags'], true) : [];

            foreach ($rules as $rule) {
                $ruleId = (int) $rule['id'];
                $config = json_decode((string) ($rule['action_config'] ?? '{}'), true);
                if (!is_array($config)) {
                    $config = [];
                }

                if (!auvvo_crm_ltv_contact_inactive($row, $config)) {
                    continue;
                }

                if (!auvvo_crm_contact_passes_conditions($config, $row, [], $pdo)) {
                    continue;
                }

                if (auvvo_crm_ltv_already_fired($pdo, $userId, $contactId, $ruleId, 'rule')) {
                    continue;
                }

                auvvo_crm_ltv_mark_fired($pdo, $userId, $contactId, $ruleId, 'rule');
                auvvo_crm_schedule_rule($pdo, $userId, $rule, $row, 'ltv_inactive', (string) ($rule['trigger_value'] ?? 'default'));
                $fired++;
            }

            if (auvvo_crm_ltv_contact_inactive($row, [])) {
                require_once __DIR__ . '/crm_flow_engine.inc.php';
                $fired += auvvo_crm_run_ltv_visual_flows($pdo, $userId, $row);
            }
        }
    }

    return $fired;
}

function auvvo_crm_ltv_already_fired(
    PDO $pdo,
    int $userId,
    int $contactId,
    int $sourceId,
    string $sourceType = 'rule'
): bool {
    $sourceType = $sourceType === 'flow' ? 'flow' : 'rule';
    try {
        $st = $pdo->prepare(
            'SELECT id FROM crm_ltv_fired
             WHERE user_id = ? AND contact_id = ? AND automation_id = ? AND source_type = ?
               AND fired_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1'
        );
        $st->execute([$userId, $contactId, $sourceId, $sourceType]);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        try {
            $st = $pdo->prepare(
                'SELECT id FROM crm_ltv_fired
                 WHERE user_id = ? AND contact_id = ? AND automation_id = ?
                   AND fired_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1'
            );
            $st->execute([$userId, $contactId, $sourceId]);

            return (bool) $st->fetchColumn();
        } catch (PDOException $e2) {
            return false;
        }
    }
}

function auvvo_crm_ltv_mark_fired(
    PDO $pdo,
    int $userId,
    int $contactId,
    int $sourceId,
    string $sourceType = 'rule'
): void {
    $sourceType = $sourceType === 'flow' ? 'flow' : 'rule';
    try {
        $pdo->prepare(
            'INSERT INTO crm_ltv_fired (user_id, contact_id, automation_id, source_type) VALUES (?,?,?,?)'
        )->execute([$userId, $contactId, $sourceId, $sourceType]);
    } catch (PDOException $e) {
        try {
            $pdo->prepare(
                'INSERT INTO crm_ltv_fired (user_id, contact_id, automation_id) VALUES (?,?,?)'
            )->execute([$userId, $contactId, $sourceId]);
        } catch (PDOException $e2) {
        }
    }
}

/**
 * Detecta compra aprovada em payload Hotmart/Eduzz-like.
 */
function auvvo_crm_detect_purchase_from_payload(array $payload): bool
{
    $statusPaths = [
        ['purchase', 'status'],
        ['data', 'purchase', 'status'],
        ['event', 'data', 'purchase', 'status'],
        ['status'],
        ['order', 'status'],
    ];
    $approved = ['approved', 'complete', 'completed', 'paid', 'aprovada', 'aprovado'];

    foreach ($statusPaths as $path) {
        $val = auvvo_ltv_json_path_get($payload, $path);
        if ($val !== '' && in_array(strtolower($val), $approved, true)) {
            return true;
        }
    }

    return false;
}

function auvvo_ltv_json_path_get(array $data, array $segments): string
{
    $cur = $data;
    foreach ($segments as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) {
            return '';
        }
        $cur = $cur[$seg];
    }

    return is_scalar($cur) ? trim((string) $cur) : '';
}

/**
 * Extrai metadados de compra do payload.
 */
function auvvo_crm_purchase_meta_from_payload(array $payload, string $source = ''): array
{
    $amount = auvvo_ltv_json_path_get($payload, ['purchase', 'price', 'value'])
        ?: auvvo_ltv_json_path_get($payload, ['data', 'purchase', 'price', 'value']);
    $product = auvvo_ltv_json_path_get($payload, ['product', 'name'])
        ?: auvvo_ltv_json_path_get($payload, ['data', 'product', 'name']);
    $extId = auvvo_ltv_json_path_get($payload, ['purchase', 'transaction'])
        ?: auvvo_ltv_json_path_get($payload, ['data', 'purchase', 'transaction']);

    return [
        'amount'       => $amount !== '' ? (float) preg_replace('/[^\d.]/', '', $amount) : null,
        'product_name' => $product,
        'external_id'  => $extId,
        'source'       => $source,
    ];
}
