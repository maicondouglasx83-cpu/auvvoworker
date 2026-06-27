<?php
/**
 * Webhook inbound — Hotmart, Shopify, formulários, etc.
 * POST backend/webhook_inbound.php?slug=xxx
 * Header opcional: X-Webhook-Token: {secret_token}
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/Contacts.php';
require_once __DIR__ . '/webhook_engine.inc.php';
require_once __DIR__ . '/crm_automation.inc.php';
require_once __DIR__ . '/crm_ltv.inc.php';

header('Content-Type: application/json; charset=utf-8');

auvvo_run_migrations($pdo);

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($_GET['slug'] ?? ''))));
if ($slug === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_slug']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM inbound_webhooks WHERE url_slug = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$slug]);
$hook = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$hook) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$tokenHeader = trim((string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
$secretToken = trim((string) ($hook['secret_token'] ?? ''));
if ($secretToken === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'webhook_secret_not_configured']);
    exit;
}
if ($tokenHeader === '' || !hash_equals($secretToken, $tokenHeader)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

$raw = file_get_contents('php://input') ?: '{}';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$userId = (int) $hook['user_id'];
$webhookId = (int) $hook['id'];
$hash = hash('sha256', $raw);

try {
    $pdo->prepare(
        'INSERT INTO inbound_webhook_log (webhook_id, payload_hash, status, payload_json) VALUES (?, ?, ?, ?)'
    )->execute([$webhookId, $hash, 'ok', $raw]);
} catch (PDOException $e) {
    http_response_code(200);
    $dupResp = auvvo_render_template(
        (string) ($hook['response_template'] ?? auvvo_inbound_default_response_template()),
        ['contact' => ['id' => 0], 'webhook' => ['id' => $webhookId]]
    );
    echo $dupResp;
    exit;
}

$stmt = $pdo->prepare('SELECT json_path, crm_field FROM inbound_webhook_field_maps WHERE webhook_id = ?');
$stmt->execute([$webhookId]);
$maps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fields = ['name' => '', 'email' => '', 'phone' => '', 'company' => ''];
$tags   = [];
foreach ($maps as $m) {
    $val = auvvo_json_path_get($payload, (string) $m['json_path']);
    $field = (string) $m['crm_field'];
    if ($val === '') {
        continue;
    }
    if ($field === 'tag') {
        $tags[] = $val;
    } elseif (array_key_exists($field, $fields)) {
        $fields[$field] = $val;
    }
}

$varMaps = json_decode((string) ($hook['variable_maps'] ?? '[]'), true);
if (is_array($varMaps) && $varMaps !== []) {
    auvvo_webhook_store_variables($pdo, $userId, 'inbound', $webhookId, $varMaps, $payload);
}

$phone  = preg_replace('/\D/', '', $fields['phone']);
$prefix = preg_replace('/\D/', '', (string) ($hook['phone_country_prefix'] ?? '55'));
if ($phone !== '' && $prefix !== '' && strlen($phone) <= 11 && !str_starts_with($phone, $prefix)) {
    $phone = $prefix . $phone;
    $fields['phone'] = $phone;
}
$jid    = $phone !== '' ? ($phone . '@s.whatsapp.net') : '';

if ($jid === '' && $fields['email'] !== '') {
    $jid = 'email:' . md5(strtolower($fields['email'])) . '@auvvo.local';
}

if ($jid === '') {
    http_response_code(422);
    $errBody = json_encode(['ok' => false, 'error' => 'no_contact_key']);
    try {
        $pdo->prepare('UPDATE inbound_webhook_log SET status = ?, response_json = ? WHERE webhook_id = ? AND payload_hash = ?')
            ->execute(['error', $errBody, $webhookId, $hash]);
    } catch (PDOException $e) {
    }
    auvvo_webhook_log_call($pdo, $userId, 'inbound', $webhookId, 422, $payload, $errBody, 'error');
    echo $errBody;
    exit;
}

$defaultAgentId = (int) ($hook['default_agent_id'] ?? 0);
$crm = new Contacts($pdo);
$upsert = $crm->upsertFromWebhook($userId, $defaultAgentId > 0 ? $defaultAgentId : 0, $jid, $fields['name']);
$contactId = is_array($upsert) ? (int) ($upsert['id'] ?? 0) : 0;
$isNewContact = is_array($upsert) && !empty($upsert['is_new']);

$stmt = $pdo->prepare('SELECT id, agent_id FROM contacts WHERE user_id = ? AND jid = ? LIMIT 1');
$stmt->execute([$userId, $jid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($contactId <= 0 && $row) {
    $contactId = (int) $row['id'];
}

if ($contactId > 0 && $defaultAgentId > 0 && empty($row['agent_id'])) {
    $pdo->prepare('UPDATE contacts SET agent_id = ? WHERE id = ? AND user_id = ?')
        ->execute([$defaultAgentId, $contactId, $userId]);
}

if ($contactId > 0) {
    require_once __DIR__ . '/CrmPipelines.php';
    $pipes = new CrmPipelines($pdo);
    $entryPipelineId = (int) ($hook['entry_pipeline_id'] ?? 0);
    if ($entryPipelineId <= 0) {
        $entryPipelineId = $pipes->defaultPipelineId($userId);
    }
    $entryStage = trim((string) ($hook['entry_stage'] ?? ''));
    $stageMap = $pipes->stagesMap($userId, $entryPipelineId);
    if ($entryStage === '' || !isset($stageMap[$entryStage])) {
        $entryStage = $pipes->firstStageSlug($entryPipelineId);
    }
    $entryTags  = json_decode((string) ($hook['entry_tags'] ?? '[]'), true);
    if (!is_array($entryTags)) {
        $entryTags = [];
    }
    $allTags = array_values(array_unique(array_filter(array_merge($tags, $entryTags))));

    $pdo->prepare('UPDATE contacts SET pipeline_id = ?, stage = ?, stage_id = ? WHERE id = ? AND user_id = ?')
        ->execute([
            $entryPipelineId,
            $entryStage,
            $pipes->resolveStageId($entryPipelineId, $entryStage),
            $contactId,
            $userId,
        ]);

    $save = [
        'id'      => $contactId,
        'name'    => $fields['name'] ?: null,
        'email'   => $fields['email'] ?: null,
        'phone'   => $phone ?: null,
        'company' => $fields['company'] ?: null,
        'stage'   => $entryStage,
    ];
    if ($allTags !== []) {
        $save['tags'] = $allTags;
    }
    $crm->save($userId, $save);

    $stmt = $pdo->prepare(
        'SELECT id, user_id, agent_id, jid, name, phone, email, company, stage, tags FROM contacts WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$contactId, $userId]);
    $contactRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contactRow) {
        $countPurchase = !empty($hook['counts_as_purchase'])
            || auvvo_crm_detect_purchase_from_payload($payload);
        if ($countPurchase) {
            $meta = auvvo_crm_purchase_meta_from_payload($payload, (string) $hook['url_slug']);
            auvvo_crm_record_purchase($pdo, $userId, $contactId, $meta);
            $contactRow = $crm->get($userId, $contactId) ?: $contactRow;
        }

        if ($isNewContact) {
            auvvo_crm_run_automations($pdo, $userId, 'contact_created', 'webhook', $contactRow);
            auvvo_crm_run_automations($pdo, $userId, 'contact_created', (string) $hook['url_slug'], $contactRow);
        }
        auvvo_crm_run_automations($pdo, $userId, 'webhook_received', (string) $hook['url_slug'], $contactRow);
    }
}

$respTpl = trim((string) ($hook['response_template'] ?? ''));
if ($respTpl === '') {
    $respTpl = auvvo_inbound_default_response_template();
}
$renderVars = [
    'contact' => [
        'id'    => $contactId,
        'name'  => $fields['name'],
        'email' => $fields['email'],
        'phone' => $fields['phone'],
        'jid'   => $jid,
    ],
    'webhook' => ['id' => $webhookId, 'slug' => $hook['url_slug']],
    'payload' => $payload,
];
$responseBody = auvvo_render_template($respTpl, $renderVars);

try {
    $pdo->prepare('UPDATE inbound_webhook_log SET response_json = ? WHERE webhook_id = ? AND payload_hash = ?')
        ->execute([$responseBody, $webhookId, $hash]);
} catch (PDOException $e) {
}

auvvo_webhook_log_call($pdo, $userId, 'inbound', $webhookId, 200, $payload, $responseBody, 'ok');

http_response_code(200);
echo $responseBody;
