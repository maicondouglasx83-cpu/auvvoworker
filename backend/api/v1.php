<?php
/**
 * API REST pública v1 — autenticação via X-Auvvo-Api-Key
 * GET/POST backend/api/v1.php?resource=contacts&...
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auvvo-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../migrations.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../Contacts.php';
require_once __DIR__ . '/../../includes/subscription.inc.php';

function v1_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = trim((string) ($_SERVER['HTTP_X_AUVVO_API_KEY'] ?? ''));
if ($apiKey === '') {
    v1_json(401, ['error' => true, 'message' => 'Envie a API key no header X-Auvvo-Api-Key']);
}
$auth   = ApiAuth::authenticate($pdo, $apiKey);
if (!$auth) {
    v1_json(401, ['error' => true, 'message' => 'API key inválida ou inativa']);
}

$userId   = $auth['user_id'];
$resource = trim((string) ($_GET['resource'] ?? ''));
$method   = $_SERVER['REQUEST_METHOD'];

auvvo_run_migrations($pdo);

if (!defined('IS_DEV') || !IS_DEV) {
    if (!auvvo_user_subscription_active($pdo, $userId)) {
        v1_json(403, ['error' => true, 'message' => 'Assinatura inativa. Renove em checkout.']);
    }
}

$crm = new Contacts($pdo);

switch ($resource) {
    case 'contacts':
        if (!ApiAuth::hasPermission($auth, ApiAuth::PERM_CRM_READ) && !ApiAuth::hasPermission($auth, ApiAuth::PERM_CRM_WRITE)) {
            v1_json(403, ['error' => true, 'message' => 'Permissão crm.read ou crm.write necessária']);
        }
        if ($method === 'GET') {
            $filters = [
                'stage'    => $_GET['stage'] ?? '',
                'agent_id' => $_GET['agent_id'] ?? '',
                'search'   => $_GET['search'] ?? '',
                'tag'      => $_GET['tag'] ?? '',
            ];
            v1_json(200, ['error' => false, 'contacts' => $crm->list($userId, array_filter($filters))]);
        }
        if ($method === 'POST' && ApiAuth::hasPermission($auth, ApiAuth::PERM_CRM_WRITE)) {
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($body)) {
                v1_json(400, ['error' => true, 'message' => 'JSON inválido']);
            }
            $res = $crm->save($userId, $body);
            v1_json($res['error'] ? 422 : 200, $res);
        }
        v1_json(405, ['error' => true, 'message' => 'Método não permitido']);

    case 'contact':
        if (!ApiAuth::hasPermission($auth, ApiAuth::PERM_CRM_READ)) {
            v1_json(403, ['error' => true, 'message' => 'Permissão crm.read necessária']);
        }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            v1_json(400, ['error' => true, 'message' => 'id obrigatório']);
        }
        $row = $crm->get($userId, $id);
        if (!$row) {
            v1_json(404, ['error' => true, 'message' => 'não encontrado']);
        }
        v1_json(200, ['error' => false, 'contact' => $row]);

    case 'agents':
        if (!ApiAuth::hasPermission($auth, ApiAuth::PERM_AGENTS_READ)) {
            v1_json(403, ['error' => true, 'message' => 'Permissão agents.read necessária']);
        }
        $stmt = $pdo->prepare('SELECT id, name, agent_type, status, flow_mode FROM agents WHERE user_id = ? AND status != ?');
        $stmt->execute([$userId, 'draft']);
        v1_json(200, ['error' => false, 'agents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    case 'me':
        v1_json(200, [
            'error'   => false,
            'user_id' => $userId,
            'permissions' => $auth['permissions'],
            'version' => 'v1',
        ]);

    default:
        v1_json(404, [
            'error'   => true,
            'message' => 'resource desconhecido',
            'available' => ['me', 'contacts', 'contact', 'agents'],
        ]);
}
