<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_automation_dedupe.inc.php';

/**
 * @return list<array{trigger_type:string, trigger_value:string}>
 */
function auvvo_crm_flow_extract_triggers(string $flowDataJson): array
{
    $decoded = json_decode($flowDataJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $nodes = $decoded['drawflow']['Home']['data'] ?? $decoded['drawflow']['drawflow']['Home']['data'] ?? [];
    if (!is_array($nodes)) {
        return [];
    }
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node) || ($node['name'] ?? '') !== 'flow_trigger') {
            continue;
        }
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $type = trim((string) ($data['trigger_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $out[] = [
            'trigger_type'  => $type,
            'trigger_value' => trim((string) ($data['trigger_value'] ?? '*')) ?: '*',
        ];
    }

    return $out;
}

function auvvo_crm_dedupe_conflict_label(string $dedupeKey): string
{
    if ($dedupeKey === 'global:whatsapp_first') {
        return 'Primeiro WhatsApp (um por lead — só a primeira dispara)';
    }
    if ($dedupeKey === 'global:first_contact') {
        return 'Primeiro contato (um por lead — só a primeira dispara)';
    }

    return $dedupeKey;
}

/**
 * Automações ativas com o mesmo gatilho global — só a primeira executa por lead.
 *
 * @return list<array{key:string, label:string, count:int, items:list<array{kind:string, id:int, name:string}>}>
 */
function auvvo_crm_automation_dedupe_warnings(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    /** @var array<string, list<array{kind:string, id:int, name:string}>> $groups */
    $groups = [];

    try {
        $st = $pdo->prepare(
            'SELECT id, trigger_type, trigger_value FROM crm_automations
             WHERE user_id = ? AND is_active = 1'
        );
        $st->execute([$userId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $type = (string) ($row['trigger_type'] ?? '');
            $val  = trim((string) ($row['trigger_value'] ?? '*')) ?: '*';
            $key  = auvvo_crm_dedupe_global_key($type, $val);
            if ($key === null) {
                continue;
            }
            $groups[$key][] = [
                'kind' => 'rule',
                'id'   => (int) ($row['id'] ?? 0),
                'name' => 'Regra #' . (int) ($row['id'] ?? 0),
            ];
        }

        $stF = $pdo->prepare(
            'SELECT id, name, flow_data FROM crm_automation_flows
             WHERE user_id = ? AND is_active = 1'
        );
        $stF->execute([$userId]);
        while ($row = $stF->fetch(PDO::FETCH_ASSOC)) {
            $flowId   = (int) ($row['id'] ?? 0);
            $flowName = trim((string) ($row['name'] ?? '')) ?: ('Fluxo #' . $flowId);
            foreach (auvvo_crm_flow_extract_triggers((string) ($row['flow_data'] ?? '{}')) as $tr) {
                $key = auvvo_crm_dedupe_global_key($tr['trigger_type'], $tr['trigger_value']);
                if ($key === null) {
                    continue;
                }
                $groups[$key][] = [
                    'kind' => 'flow',
                    'id'   => $flowId,
                    'name' => $flowName,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] dedupe_warnings: ' . $e->getMessage());

        return [];
    }

    $warnings = [];
    foreach ($groups as $key => $items) {
        if (count($items) < 2) {
            continue;
        }
        $warnings[] = [
            'key'   => $key,
            'label' => auvvo_crm_dedupe_conflict_label($key),
            'count' => count($items),
            'items' => $items,
        ];
    }
    usort($warnings, static fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

    return $warnings;
}
