<?php
/**
 * backend/api.php
 * Endpoint AJAX interno do Auvvo.
 * Recebe: ?action=X via GET ou POST JSON/form.
 * Retorna: JSON
 */
require_once __DIR__ . '/../includes/session_bootstrap.inc.php';
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => true, 'message' => 'Não autenticado.']); exit;
}

$user_id = $_SESSION['user_id'];
require_once __DIR__ . '/../includes/subscription.inc.php';
auvvo_auth_require_subscription();

$action  = $_GET['action'] ?? $_POST['action'] ?? '';

function json_out(array $data): void { echo json_encode($data); exit; }

// GET libera sessão cedo — evita fila quando conversas faz vários polls em paralelo
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
}

// ============================================================
// PROTEÇÃO CSRF PARA TODAS AS CHAMADAS POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token_ok = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
    if (!$token_ok) {
        json_out(['error' => true, 'message' => 'Sessão expirada ou inválida. Por favor, recarregue a página.']);
    }
}



switch ($action) {

    case 'csrf_refresh': {
        json_out(['error' => false, 'csrf_token' => $_SESSION['csrf_token'] ?? '']);
    }

    // ============================================================
    // GOOGLE CALENDAR: Criar evento (agendamento)
    // ============================================================
    case 'google_calendar_create_event': {
        require_once 'GoogleCalendar.php';

        if (!GoogleCalendar::isOAuthAppConfigured()) {
            json_out(['error'=>true,'message'=>'Google Calendar: o administrador deve configurar GOOGLE_OAUTH_CLIENT_ID e GOOGLE_OAUTH_CLIENT_SECRET no servidor (.env).']);
        }

        // Respeita preferências do usuário (toggle)
        try {
            $stmt = $pdo->prepare("SELECT google_calendar_enabled FROM settings WHERE user_id=? LIMIT 1");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch();
            $enabled = (int)($row['google_calendar_enabled'] ?? 0) === 1;
            if (!$enabled) {
                json_out(['error'=>true,'message'=>'Agendamentos estão desativados nas Configurações.']);
            }
        } catch (PDOException $e) {
            // Se a migração ainda não foi aplicada, mantém compatibilidade.
        }

        $summary     = trim((string)($_POST['summary'] ?? 'Agendamento'));
        $description = trim((string)($_POST['description'] ?? ''));
        $location    = trim((string)($_POST['location'] ?? ''));
        $timezone    = trim((string)($_POST['timezone'] ?? 'America/Sao_Paulo'));
        $startIn     = trim((string)($_POST['start'] ?? ''));
        $endIn       = trim((string)($_POST['end'] ?? ''));

        if ($startIn === '' || $endIn === '') {
            json_out(['error'=>true,'message'=>'Informe start e end.']);
        }

        $mkRfc3339 = function(string $v): string {
            // aceita ISO (2026-05-05T10:00:00-03:00) ou "Y-m-d H:i"
            if (str_contains($v, 'T')) return $v;
            $ts = strtotime($v);
            if ($ts === false) return $v;
            return date('c', $ts);
        };

        $event = [
            'summary' => $summary,
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'start' => [
                'dateTime' => $mkRfc3339($startIn),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $mkRfc3339($endIn),
                'timeZone' => $timezone,
            ],
        ];
        // remove nulls
        $event = array_filter($event, fn($v)=>$v!==null);

        try {
            $res = GoogleCalendar::createEvent($pdo, $user_id, $event);
            if (!empty($res['error'])) {
                json_out(['error'=>true,'message'=>$res['message'] ?? 'Erro ao criar evento.', 'raw'=>$res]);
            }
            json_out(['error'=>false,'event'=>$res]);
        } catch (Throwable $e) {
            json_out(['error'=>true,'message'=>$e->getMessage()]);
        }
    }

    // ============================================================
    // EVOLUTION: Testar conexão com a API
    // ============================================================
    case 'evolution_ping': {
        require_once 'EvolutionAPI.php';
        $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
        $ok  = $api->ping();
        json_out(['error'=>!$ok, 'message'=> $ok ? 'Conexão bem-sucedida com o Evolution Go!' : 'Não foi possível conectar ao Evolution Go. Verifique a URL e a global API key.']);
    }

    // ============================================================
    // WHATSAPP: Conexões nomeadas (independente do agente/cérebro)
    // ============================================================
    case 'list_whatsapp_connections': {
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $list = auvvo_whatsapp_connections_list($pdo, $user_id);
        json_out(['error' => false, 'connections' => $list]);
    }

    case 'create_whatsapp_connection': {
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) {
            json_out(['error' => true, 'message' => 'Informe um nome para a conexão (mín. 2 caracteres).']);
        }
        $defaultAgent = (int) ($_POST['default_agent_id'] ?? 0);
        if ($defaultAgent > 0) {
            $chk = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? AND status != ? LIMIT 1');
            $chk->execute([$defaultAgent, $user_id, 'draft']);
            if (!$chk->fetchColumn()) {
                $defaultAgent = 0;
            }
        }
        $aiMode = strtolower(trim((string) ($_POST['ai_mode'] ?? 'flows_first')));
        if (!in_array($aiMode, ['standalone', 'flows_first', 'flows_only'], true)) {
            $aiMode = 'flows_first';
        }
        $pdo->prepare(
            'INSERT INTO whatsapp_connections (user_id, name, status, default_agent_id, ai_mode) VALUES (?, ?, ?, ?, ?)'
        )->execute([$user_id, $name, 'offline', $defaultAgent > 0 ? $defaultAgent : null, $aiMode]);
        $id = (int) $pdo->lastInsertId();
        $slug = 'Auvvo_conn_' . $id . '_' . $user_id;
        $pdo->prepare('UPDATE whatsapp_connections SET evolution_instance = ? WHERE id = ? AND user_id = ?')
            ->execute([$slug, $id, $user_id]);
        $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $id);
        json_out(['error' => false, 'connection' => $conn]);
    }

    case 'update_whatsapp_connection': {
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $cid = (int) ($_POST['connection_id'] ?? 0);
        $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $cid);
        if (!$conn) {
            json_out(['error' => true, 'message' => 'Conexão não encontrada.']);
        }
        $name = trim((string) ($_POST['name'] ?? $conn['name'] ?? ''));
        $defaultAgent = (int) ($_POST['default_agent_id'] ?? $conn['default_agent_id'] ?? 0);
        if ($defaultAgent > 0) {
            $chk = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? LIMIT 1');
            $chk->execute([$defaultAgent, $user_id]);
            if (!$chk->fetchColumn()) {
                $defaultAgent = 0;
            }
        }
        $aiMode = strtolower(trim((string) ($_POST['ai_mode'] ?? $conn['ai_mode'] ?? 'flows_first')));
        if (!in_array($aiMode, ['standalone', 'flows_first', 'flows_only'], true)) {
            $aiMode = 'flows_first';
        }
        $pdo->prepare(
            'UPDATE whatsapp_connections SET name = ?, default_agent_id = ?, ai_mode = ? WHERE id = ? AND user_id = ?'
        )->execute([$name, $defaultAgent > 0 ? $defaultAgent : null, $aiMode, $cid, $user_id]);
        json_out(['error' => false, 'connection' => auvvo_whatsapp_connection_get($pdo, $user_id, $cid)]);
    }

    case 'delete_whatsapp_connection': {
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        require_once 'EvolutionAPI.php';
        $cid = (int) ($_POST['connection_id'] ?? 0);
        $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $cid);
        if (!$conn) {
            json_out(['error' => true, 'message' => 'Conexão não encontrada.']);
        }
        if (!empty($conn['evolution_token'])) {
            $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
            $api->deleteInstance((string) $conn['evolution_token']);
        }
        $pdo->prepare('UPDATE agents SET whatsapp_connection_id = NULL WHERE whatsapp_connection_id = ? AND user_id = ?')
            ->execute([$cid, $user_id]);
        $pdo->prepare('DELETE FROM whatsapp_connections WHERE id = ? AND user_id = ?')->execute([$cid, $user_id]);
        json_out(['error' => false, 'message' => 'Conexão removida.']);
    }

    // ============================================================
    // EVOLUTION: Criar instância / pegar QR Code (por connection_id)
    // ============================================================
    case 'evolution_connect': {
        require_once 'EvolutionAPI.php';
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connection_id = (int) ($_POST['connection_id'] ?? 0);
        $agent_id = (int) ($_POST['agent_id'] ?? 0); // legado

        $conn = null;
        if ($connection_id > 0) {
            $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $connection_id);
        } elseif ($agent_id > 0) {
            $st = $pdo->prepare('SELECT whatsapp_connection_id FROM agents WHERE id = ? AND user_id = ?');
            $st->execute([$agent_id, $user_id]);
            $linked = (int) ($st->fetchColumn() ?: 0);
            if ($linked > 0) {
                $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $linked);
            }
        }
        if (!$conn) {
            json_out(['error' => true, 'message' => 'Conexão WhatsApp não encontrada. Crie uma conexão nomeada em Automações.']);
        }

        $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
        $instance_name = $conn['evolution_instance'] ?? ('Auvvo_conn_' . $conn['id'] . '_' . $user_id);
        $webhook_url = app_http_url('backend/webhook_evolution.php');
        $instance_token = $conn['evolution_token'] ?? null;

        if (!$instance_token) {
            $create = $api->createInstance($instance_name);
            if (isset($create['error']) && $create['error']) {
                json_out(['error' => true, 'message' => 'Erro ao criar instância: ' . ($create['message'] ?? 'desconhecido'), 'raw' => $create]);
            }
            $instance_token = EvolutionAPI::extractToken($create);
            if (!$instance_token) {
                json_out(['error' => true, 'message' => 'Evolution Go não retornou um token para a instância.', 'raw' => $create]);
            }
            $pdo->prepare(
                'UPDATE whatsapp_connections SET evolution_instance = ?, evolution_token = ?, status = ? WHERE id = ? AND user_id = ?'
            )->execute([$instance_name, $instance_token, 'waiting_qr', (int) $conn['id'], $user_id]);
        }

        $api->connectInstance($instance_token, $webhook_url);
        $qr = $api->getQRCode($instance_token);
        $qr_base64 = EvolutionAPI::extractQRCode($qr);
        if ($qr_base64 && strpos($qr_base64, 'data:image') === false) {
            $qr_base64 = 'data:image/png;base64,' . $qr_base64;
        }

        json_out([
            'error'         => false,
            'connection_id' => (int) $conn['id'],
            'instance'      => $instance_name,
            'qr_code'       => $qr_base64,
            'raw'           => $qr,
        ]);
    }

    // ============================================================
    // EVOLUTION: Status da instância
    // ============================================================
    case 'evolution_status': {
        require_once 'EvolutionAPI.php';
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connection_id = (int) ($_GET['connection_id'] ?? $_POST['connection_id'] ?? 0);
        $agent_id = (int) ($_GET['agent_id'] ?? 0);

        $conn = null;
        if ($connection_id > 0) {
            $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $connection_id);
        } elseif ($agent_id > 0) {
            $token = auvvo_whatsapp_resolve_evolution_token($pdo, $user_id, null, $agent_id);
            if ($token) {
                $conn = auvvo_whatsapp_connection_by_token($pdo, $token);
            }
        }

        if (!$conn || empty($conn['evolution_token']) || (int) ($conn['user_id'] ?? 0) !== $user_id) {
            json_out(['state' => 'not_configured', 'label' => 'Não configurado']);
        }

        $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
        $status = $api->getStatus((string) $conn['evolution_token']);
        $connected = EvolutionAPI::isConnected($status);
        $state = $connected ? 'open' : (($status['data']['Connected'] ?? false) ? 'connecting' : 'close');
        $labels = ['open' => 'Conectado', 'connecting' => 'Conectando...', 'close' => 'Desconectado'];
        $db_status = $connected ? 'online' : 'waiting_qr';
        $pdo->prepare('UPDATE whatsapp_connections SET status = ? WHERE id = ? AND user_id = ?')
            ->execute([$db_status, (int) $conn['id'], $user_id]);

        json_out(['state' => $state, 'label' => $labels[$state] ?? 'Verificando...', 'connection_id' => (int) $conn['id'], 'raw' => IS_DEV ? $status : null]);
    }

    // ============================================================
    // EVOLUTION: Desconectar instância
    // ============================================================
    case 'evolution_disconnect': {
        require_once 'EvolutionAPI.php';
        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connection_id = (int) ($_POST['connection_id'] ?? 0);
        $conn = auvvo_whatsapp_connection_get($pdo, $user_id, $connection_id);
        if (!$conn || empty($conn['evolution_token'])) {
            json_out(['error' => true, 'message' => 'Sem instância para desconectar.']);
        }

        $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
        $result = $api->deleteInstance((string) $conn['evolution_token']);
        $pdo->prepare(
            'UPDATE whatsapp_connections SET evolution_instance = NULL, evolution_token = NULL, status = ? WHERE id = ? AND user_id = ?'
        )->execute(['offline', (int) $conn['id'], $user_id]);
        json_out(['error' => false, 'message' => 'Instância desconectada com sucesso.', 'raw' => $result]);
    }

    // ============================================================
    // MASTER PROMPT: Gerar e retornar
    // ============================================================
    case 'get_master_prompt': {
        require_once 'MasterPromptBuilder.php';
        $agent_id = intval($_GET['agent_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        $agent = $stmt->fetch();
        if (!$agent) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        $stmt = $pdo->prepare("SELECT company_name, company_niche, company_site FROM settings WHERE user_id=?");
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch() ?: [];

        $builder = new MasterPromptBuilder($pdo);
        $prompt  = $builder->build($agent, $settings);
        $tokens  = $builder->estimateTokens($prompt);
        json_out(['error'=>false,'prompt'=>$prompt,'tokens'=>$tokens,'agent'=>$agent['name']]);
    }

    // ============================================================
    // KNOWLEDGE: Extrair conteúdo de um arquivo já salvo
    // ============================================================
    case 'extract_knowledge': {
        require_once 'MasterPromptBuilder.php';
        $knowledge_id = intval($_POST['knowledge_id'] ?? 0);

        $stmt = $pdo->prepare(
            "SELECT kb.*, a.user_id FROM knowledge_base kb JOIN agents a ON kb.agent_id=a.id WHERE kb.id=? AND a.user_id=?"
        );
        $stmt->execute([$knowledge_id, $user_id]);
        $kb = $stmt->fetch();
        if (!$kb) json_out(['error'=>true,'message'=>'Arquivo não encontrado.']);

        $file_path = __DIR__ . '/../uploads/knowledge/' . $kb['file_name'];
        $content   = MasterPromptBuilder::extractContent($file_path, $kb['file_type']);

        if ($content) {
            $pdo->prepare("UPDATE knowledge_base SET content=? WHERE id=?")->execute([$content, $knowledge_id]);
            json_out(['error'=>false,'message'=>'Conteúdo extraído com sucesso.','chars'=>strlen($content),'preview'=>mb_substr($content,0,300)]);
        } else {
            json_out(['error'=>true,'message'=>'Não foi possível extrair conteúdo deste arquivo.']);
        }
    }

    // ============================================================
    // INLINE KNOWLEDGE BASE: Listar
    // ============================================================
    case 'list_knowledge': {
        $agent_id = intval($_GET['agent_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT kb.id, kb.file_name, kb.original_name, kb.file_type, kb.status, kb.created_at FROM knowledge_base kb JOIN agents a ON kb.agent_id = a.id WHERE a.id=? AND a.user_id=? ORDER BY kb.id DESC");
        $stmt->execute([$agent_id, $user_id]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = array_map(function($f) {
            $f['date'] = date('d/m/Y H:i', strtotime($f['created_at']));
            return $f;
        }, $files);
        json_out(['error'=>false, 'files'=>$formatted]);
    }

    // ============================================================
    // INLINE KNOWLEDGE BASE: Upload de Arquivo
    // ============================================================
    case 'inline_upload_knowledge': {
        require_once 'MasterPromptBuilder.php';
        $agent_id = intval($_POST['agent_id'] ?? 0);
        $category = trim((string)($_POST['category'] ?? ''));
        // Verifica dono
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        if (!isset($_FILES['knowledge_file']) || $_FILES['knowledge_file']['error'] !== UPLOAD_ERR_OK) {
            json_out(['error'=>true,'message'=>'Erro no upload.']);
        }

        $file = $_FILES['knowledge_file'];
        if ($file['size'] > 50 * 1024 * 1024) json_out(['error'=>true,'message'=>'Arquivo maior que 50MB.']);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['txt', 'csv', 'pdf', 'docx'];
        if (!in_array($ext, $allowed)) json_out(['error'=>true,'message'=>'Tipo de arquivo não permitido.']);

        $upload_dir = __DIR__ . '/../uploads/knowledge/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $new_name = uniqid('kb_') . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
            $content = MasterPromptBuilder::extractContent($upload_dir . $new_name, $ext);
            $status = $content ? 'trained' : 'failed';
            $orig_name = $file['name'];
            if ($category !== '') {
                $tag = strtoupper(preg_replace('/[^a-zA-Z0-9_\\- ]+/', '', $category));
                $orig_name = '[' . $tag . '] ' . $orig_name;
            }
            $stmt = $pdo->prepare("INSERT INTO knowledge_base (agent_id, file_name, original_name, file_type, content, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$agent_id, $new_name, $orig_name, $ext, $content, $status]);
            json_out(['error'=>false, 'message'=>'Sucesso', 'extracted'=>$status==='trained']);
        }
        json_out(['error'=>true,'message'=>'Falha ao salvar no servidor.']);
    }

    // ============================================================
    // INLINE KNOWLEDGE BASE: Salvar Texto Livre
    // ============================================================
    case 'inline_save_text': {
        $agent_id = intval($_POST['agent_id'] ?? 0);
        $text = trim($_POST['text_content'] ?? '');
        if (!$text) json_out(['error'=>true,'message'=>'Texto vazio.']);

        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        $stmt = $pdo->prepare("INSERT INTO knowledge_base (agent_id, file_name, original_name, file_type, content, status) VALUES (?, 'text_manual', 'Texto Manual', 'text', ?, 'trained')");
        $stmt->execute([$agent_id, $text]);
        json_out(['error'=>false, 'message'=>'Texto treinado.']);
    }

    // ============================================================
    // INLINE KNOWLEDGE BASE: Deletar
    // ============================================================
    case 'inline_delete_knowledge': {
        $knowledge_id = intval($_POST['knowledge_id'] ?? 0);
        $agent_id = intval($_POST['agent_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT kb.* FROM knowledge_base kb JOIN agents a ON kb.agent_id = a.id WHERE kb.id=? AND a.user_id=? AND a.id=?");
        $stmt->execute([$knowledge_id, $user_id, $agent_id]);
        $kb = $stmt->fetch();

        if ($kb) {
            if ($kb['file_type'] !== 'text') {
                $path = __DIR__ . '/../uploads/knowledge/' . $kb['file_name'];
                if (file_exists($path)) unlink($path);
            }
            $pdo->prepare("DELETE FROM knowledge_base WHERE id=?")->execute([$knowledge_id]);
        }
        json_out(['error'=>false, 'message'=>'Deletado.']);
    }

    // ============================================================
    // CONVERSAS AO VIVO: Pausar/retomar IA (por contato)
    // ============================================================
    case 'set_conversation_pause': {
        $agent_id    = intval($_POST['agent_id'] ?? 0);
        $contact_jid = trim((string)($_POST['contact_jid'] ?? ''));
        $paused      = (int)($_POST['paused'] ?? 0) === 1;
        $minutes     = max(5, min(24 * 60, intval($_POST['minutes'] ?? 30))); // 5min..24h

        if (!$agent_id || $contact_jid === '') json_out(['error'=>true,'message'=>'Parâmetros inválidos.']);

        $resolved = auvvo_resolve_pn_jid_from_thread($pdo, $agent_id, $contact_jid);
        $contact_jid = $resolved !== '' ? $resolved : auvvo_canonical_whatsapp_jid($contact_jid);

        // Verifica dono do agente
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        // Tenta persistir estado. Se a tabela ainda não existir, falha silenciosa (UI segue local).
        try {
            if ($paused) {
                $pdo->prepare(
                    "INSERT INTO conversation_states (agent_id, contact_jid, ia_paused_until, manual_owner_user_id)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
                     ON DUPLICATE KEY UPDATE ia_paused_until=VALUES(ia_paused_until), manual_owner_user_id=VALUES(manual_owner_user_id)"
                )->execute([$agent_id, $contact_jid, $minutes, $user_id]);
            } else {
                $pdo->prepare(
                    "INSERT INTO conversation_states (agent_id, contact_jid, ia_paused_until, manual_owner_user_id)
                     VALUES (?, ?, NULL, NULL)
                     ON DUPLICATE KEY UPDATE ia_paused_until=NULL, manual_owner_user_id=NULL"
                )->execute([$agent_id, $contact_jid]);
            }
        } catch (PDOException $e) { error_log('[Auvvo] api ia_resume: ' . $e->getMessage()); }

        json_out(['error'=>false,'paused'=>$paused,'minutes'=>$minutes]);
    }

    // ============================================================
    // CONVERSAS AO VIVO: Enviar mensagem manual (humano)
    // ============================================================
    case 'send_manual_message': {
        require_once 'EvolutionAPI.php';
        $agent_id    = intval($_POST['agent_id'] ?? 0);
        $contact_jid = trim((string)($_POST['contact_jid'] ?? ''));
        $text        = trim((string)($_POST['text'] ?? ''));
        $client_msg_id = trim((string)($_POST['client_msg_id'] ?? ''));
        if (!$agent_id || $contact_jid === '' || $text === '') json_out(['error'=>true,'message'=>'Mensagem vazia ou inválida.']);

        require_once __DIR__ . '/whatsapp_connections.inc.php';
        $connection_id = (int) ($_POST['connection_id'] ?? 0);
        $token = auvvo_whatsapp_resolve_evolution_token($pdo, $user_id, $connection_id > 0 ? $connection_id : null, $agent_id);
        if (!$token) {
            json_out(['error' => true, 'message' => 'Nenhuma conexão WhatsApp ativa. Configure em Automações → Conexões.']);
        }

        $resolved = auvvo_resolve_pn_jid_from_thread($pdo, $agent_id, $contact_jid);
        $contact_jid = $resolved !== '' ? $resolved : auvvo_canonical_whatsapp_jid($contact_jid);
        $digits = auvvo_whatsapp_peer_digits($contact_jid);
        if ($digits === '') {
            json_out(['error'=>true,'message'=>'Não foi possível identificar o número WhatsApp (JID interno/LID). Recarregue o Monitoramento ao vivo ou aguarde nova mensagem do cliente.']);
        }

        $stmt = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetchColumn()) {
            json_out(['error' => true, 'message' => 'Agente não encontrado.']);
        }

        // Throttle leve: 1 msg a cada 3s por contato (evita spam/cliques duplos)
        $allowSend = true;
        try {
            $bucket = '3s_' . (string)floor(time() / 3);
            $pdo->prepare(
                "INSERT INTO manual_send_throttle (agent_id, contact_jid, bucket) VALUES (?, ?, ?)"
            )->execute([$agent_id, $contact_jid, $bucket]);
        } catch (PDOException $e) {
            $allowSend = false;
        }
        if (!$allowSend) json_out(['error'=>true,'message'=>'Aguarde um instante antes de enviar outra mensagem.']);

        // Dedup por idempotency key (client_msg_id)
        if ($client_msg_id !== '') {
            $isDup = false;
            try {
                $pdo->prepare(
                    "INSERT INTO manual_message_dedup (agent_id, contact_jid, client_msg_id) VALUES (?, ?, ?)"
                )->execute([$agent_id, $contact_jid, $client_msg_id]);
            } catch (PDOException $e) {
                $isDup = true;
            }
            if ($isDup) json_out(['error'=>false,'sent'=>true,'duplicate'=>true]);
        }

        $api = new EvolutionAPI(EVOLUTION_API_URL, EVOLUTION_API_KEY);
        $res = $api->sendText($token, $digits, $text);

        if (isset($res['error']) && $res['error']) {
            json_out(['error'=>true,'message'=>'Falha ao enviar pela Evolution. Tente novamente.', 'raw'=>$res]);
        }

        // Log manual
        try {
            $pdo->prepare(
                "INSERT INTO conversation_logs (agent_id, contact_jid, incoming_msg, response_msg, type)
                 VALUES (?, ?, NULL, ?, 'manual')"
            )->execute([$agent_id, $contact_jid, $text]);

            // Limpeza lazy (mantém leve)
            if (rand(1, 50) === 1) {
                $pdo->prepare("DELETE FROM manual_message_dedup WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->execute();
                $pdo->prepare("DELETE FROM manual_send_throttle WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)")->execute();
            }
        } catch (PDOException $e) { error_log('[Auvvo] api manual cleanup: ' . $e->getMessage()); }

        json_out(['error'=>false,'sent'=>true,'raw'=>$res,'duplicate'=>false]);
    }

    // ============================================================
    // CONVERSAS AO VIVO: Consultar estado (pausado/ativo)
    // ============================================================
    case 'get_conversation_state': {
        $agent_id    = intval($_GET['agent_id'] ?? 0);
        $contact_jid = trim((string)($_GET['contact_jid'] ?? ''));
        if (!$agent_id || $contact_jid === '') json_out(['error'=>true,'message'=>'Parâmetros inválidos.']);

        // Verifica dono do agente
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        $variants = auvvo_conversation_contact_jid_variants($pdo, $agent_id, $contact_jid);
        $placeholders = implode(',', array_fill(0, count($variants), '?'));

        $paused = false;
        $until  = null;
        try {
            $stmt = $pdo->prepare(
                "SELECT ia_paused_until FROM conversation_states
                 WHERE agent_id=? AND contact_jid IN ($placeholders) AND ia_paused_until IS NOT NULL
                 ORDER BY ia_paused_until DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$agent_id], $variants));
            $row = $stmt->fetch();
            if ($row && !empty($row['ia_paused_until'])) {
                $until = $row['ia_paused_until'];
                $paused = strtotime($until) > time();
            }
        } catch (PDOException $e) { error_log('[Auvvo] api pause status: ' . $e->getMessage()); }

        json_out(['error'=>false,'paused'=>$paused,'ia_paused_until'=>$until]);
    }

    // ============================================================
    // CONVERSAS AO VIVO: Buscar resumo do handoff (para humano)
    // ============================================================
    case 'get_handoff_summary': {
        $agent_id    = intval($_GET['agent_id'] ?? 0);
        $contact_jid = trim((string)($_GET['contact_jid'] ?? ''));
        if (!$agent_id || $contact_jid === '') json_out(['error'=>true,'message'=>'Parâmetros inválidos.']);

        // Verifica dono do agente
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        try {
            $stmt = $pdo->prepare(
                "SELECT id, intent, urgency, sentiment, summary_text, created_at
                 FROM handoff_summaries
                 WHERE agent_id=? AND contact_jid=?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute([$agent_id, $contact_jid]);
            $row = $stmt->fetch();
            if (!$row) json_out(['error'=>false,'has'=>false]);
            json_out(['error'=>false,'has'=>true,'summary'=>$row]);
        } catch (PDOException $e) {
            json_out(['error'=>false,'has'=>false]);
        }
    }

    // ============================================================
    // CONVERSAS AO VIVO: Apagar conversa
    // ============================================================
    case 'delete_conversation': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_out(['error' => true, 'message' => 'Método não permitido.']);
        }
        $agent_id    = intval($_POST['agent_id'] ?? 0);
        $contact_jid = trim((string)($_POST['contact_jid'] ?? ''));
        if (!$agent_id || $contact_jid === '') json_out(['error'=>true,'message'=>'Parâmetros inválidos.']);

        // Verifica dono do agente
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Agente não encontrado.']);

        $variants = auvvo_conversation_contact_jid_variants($pdo, $agent_id, $contact_jid);
        $placeholders = implode(',', array_fill(0, count($variants), '?'));

        try {
            $pdo->beginTransaction();
            
            // Deleta da tabela conversation_logs
            $stmt = $pdo->prepare("DELETE FROM conversation_logs WHERE agent_id=? AND contact_jid IN ($placeholders)");
            $stmt->execute(array_merge([$agent_id], $variants));
            
            // Deleta da tabela handoff_summaries
            $stmt = $pdo->prepare("DELETE FROM handoff_summaries WHERE agent_id=? AND contact_jid IN ($placeholders)");
            $stmt->execute(array_merge([$agent_id], $variants));
            
            // Deleta da tabela conversation_states
            $stmt = $pdo->prepare("DELETE FROM conversation_states WHERE agent_id=? AND contact_jid IN ($placeholders)");
            $stmt->execute(array_merge([$agent_id], $variants));
            
            $pdo->commit();
            json_out(['error'=>false,'message'=>'Conversa apagada com sucesso.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['error'=>true,'message'=>'Erro no banco de dados ao apagar conversa: ' . $e->getMessage()]);
        }
    }

    // ============================================================

    // GERAR PROMPT: Usando template pré-definido
    // ============================================================
    case 'gemini_generate_prompt': {
        // Coleta dados do formulário enviados pelo frontend
        $agent_name   = trim($_POST['agent_name']   ?? '');
        $agent_type   = trim($_POST['agent_type']   ?? 'vendedor');
        $organizacao  = trim($_POST['organizacao']  ?? '');
        $tone         = trim($_POST['tone']         ?? 'Amigável');
        $abertura     = trim($_POST['abertura']     ?? '');
        $extra_rules  = trim($_POST['extra_rules']  ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $company_niche= trim($_POST['company_niche']?? '');
        $products     = trim($_POST['products']     ?? '');
        $payments     = trim($_POST['payments']     ?? '');
        $diferenciais = trim($_POST['diferenciais'] ?? '');
        $horario      = trim($_POST['horario']      ?? '');
        $politica     = trim($_POST['politica']     ?? '');
        $sistemas     = trim($_POST['sistemas']     ?? '');
        $triagem      = trim($_POST['triagem']      ?? '');
        $escalada     = trim($_POST['escalada']     ?? '');
        $setores      = trim($_POST['setores']      ?? '');
        $objetivo_agente = trim($_POST['objetivo_agente'] ?? '');
        $publico_alvo    = trim($_POST['publico_alvo']    ?? '');
        $restricoes      = trim($_POST['restricoes']      ?? '');

        $type_labels = [
            'vendedor'  => 'Vendedor / Fechador de Negocios',
            'atendente' => 'Atendente de Suporte ao Cliente',
            'suporte'   => 'Suporte Tecnico',
            'Auvvo'     => 'Orquestrador Auvvo (Master AI)',
        ];
        $type_label = $type_labels[$agent_type] ?? $agent_type;

        // Monta contexto para o gerador
        $contexto = "AGENTE: {$agent_name}\n";
        if ($company_name) $contexto .= "Empresa: {$company_name}\n";
        if ($company_niche) $contexto .= "Nicho: {$company_niche}\n";
        if ($organizacao) $contexto .= "Tipo de Organizacao: {$organizacao}\n";
        if ($publico_alvo) $contexto .= "Publico-Alvo: {$publico_alvo}\n";
        if ($tone) $contexto .= "Tom de Voz: {$tone}\n";
        if ($objetivo_agente) $contexto .= "Objetivo: {$objetivo_agente}\n";
        if ($abertura) $contexto .= "Mensagem de abertura desejada: \"{$abertura}\"\n";

        if ($agent_type === 'vendedor') {
            if ($products) $contexto .= "Produtos/Servicos: {$products}\n";
            if ($payments) $contexto .= "Formas de Pagamento: {$payments}\n";
            if ($diferenciais) $contexto .= "Diferenciais: {$diferenciais}\n";
        } elseif ($agent_type === 'atendente') {
            if ($horario) $contexto .= "Horario de Atendimento: {$horario}\n";
            if ($politica) $contexto .= "Politicas: {$politica}\n";
        } elseif ($agent_type === 'suporte') {
            if ($sistemas) $contexto .= "Sistemas Suportados: {$sistemas}\n";
            if ($triagem) $contexto .= "Triagem: {$triagem}\n";
            if ($escalada) $contexto .= "Escalada: {$escalada}\n";
        } elseif ($agent_type === 'Auvvo') {
            if ($setores) $contexto .= "Setores: {$setores}\n";
        }

        if ($restricoes) $contexto .= "Restricoes: {$restricoes}\n";
        if ($extra_rules) $contexto .= "Regras extras: {$extra_rules}\n";

        // Tenta usar DeepSeek para gerar prompt de alta qualidade
        if (auvvo_deepseek_configured()) {
            $meta_prompt = "Voce e um especialista em criar prompts para agentes de IA que atendem clientes via WhatsApp.\n\n";
            $meta_prompt .= "Seu trabalho: gerar um prompt de sistema em Portugues Brasileiro para um agente {$type_label}.\n\n";
            $meta_prompt .= "REGRAS ABSOLUTAS PARA O PROMPT GERADO:\n";
            $meta_prompt .= "- O prompt deve ser em Portugues Brasileiro natural, sem acentos e sem caracteres especiais\n";
            $meta_prompt .= "- ZERO markdown: nada de **, *, ##, `, tabelas, [links](url)\n";
            $meta_prompt .= "- WhatsApp e texto puro — o cliente ve asteriscos se usar markdown\n";
            $meta_prompt .= "- Tom humano, natural, caloroso. Como uma pessoa real conversando\n";
            $meta_prompt .= "- Para enfase, use MAIUSCULAS, nunca asteriscos\n";
            $meta_prompt .= "- Para listas, use numeros (1. 2. 3.) ou frases curtas com quebra de linha\n";
            $meta_prompt .= "- Mensagens curtas (2-4 frases por vez) — WhatsApp e mobile\n";
            $meta_prompt .= "- Use emojis com moderacao (1-2 por mensagem)\n";
            $meta_prompt .= "- Inclua SMIs empre regras claras de comportamento e restricoes\n";
            $meta_prompt .= "- Nao repita o contexto cru — transforme em instrucoes acionaveis\n\n";
            $meta_prompt .= "CONTEXTO DO CLIENTE:\n{$contexto}\n\n";
            $meta_prompt .= "Gere apenas o prompt final (sem explicacoes, sem cabecalho). Formato: texto puro, paragrafos curtos, instrucoes diretas.";

            $generated = auvvo_deepseek_simple_call($meta_prompt, 0.8, 2000);
            if ($generated !== null && trim($generated) !== '') {
                json_out(['error' => false, 'prompt' => trim($generated), 'source' => 'deepseek']);
            }
        }

        // Fallback: geracao local (template-based) se DeepSeek nao estiver configurado ou falhar
        $prompt = "Voce e um especialista em atendimento e atua como {$type_label}.\n\n";
        $prompt .= "IDENTIDADE E CONTEXTO\n";
        if ($agent_name)  $prompt .= "- Seu nome: {$agent_name}\n";
        if ($company_name)  $prompt .= "- Empresa: {$company_name}\n";
        if ($company_niche) $prompt .= "- Nicho de Mercado: {$company_niche}\n";
        if ($organizacao)   $prompt .= "- Tipo de Organizacao: {$organizacao}\n";
        if ($publico_alvo)  $prompt .= "- Seu Publico-Alvo: {$publico_alvo}\n";
        if ($objetivo_agente) $prompt .= "- Seu Objetivo Principal: {$objetivo_agente}\n\n";

        $prompt .= "DIRETRIZES DE COMPORTAMENTO\n";
        $prompt .= "- Tom de Voz: Voce deve se comunicar de forma {$tone}.\n";
        if ($abertura)      $prompt .= "- Mensagem de Abertura sugerida (use como base): \"{$abertura}\"\n";
        $prompt .= "- Responda de forma clara, objetiva e humana. Seja empatico e prestativo.\n";
        $prompt .= "- FORMAtACAO: Nunca use markdown (**, *, ##, tabelas). WhatsApp nao renderiza isso. Use texto natural, MAIUSCULAS para enfase, numeros para listas. Links: envie apenas a URL pura, sem formatacao.\n\n";

        $prompt .= "INFORMACOES ESPECIFICAS DA FUNCAO\n";
        if ($agent_type === 'vendedor') {
            if ($products)     $prompt .= "- Produtos/Servicos e Precos:\n  {$products}\n";
            if ($payments)     $prompt .= "- Condicoes de Pagamento:\n  {$payments}\n";
            if ($diferenciais) $prompt .= "- Diferenciais (Use para quebrar objecoes):\n  {$diferenciais}\n";
        } elseif ($agent_type === 'atendente') {
            if ($horario)  $prompt .= "- Horario de Atendimento:\n  {$horario}\n";
            if ($politica) $prompt .= "- Politica de Trocas/Prazos/Termos:\n  {$politica}\n";
        } elseif ($agent_type === 'suporte') {
            if ($sistemas) $prompt .= "- Sistemas Suportados:\n  {$sistemas}\n";
            if ($triagem)  $prompt .= "- Passos de Triagem:\n  {$triagem}\n";
            if ($escalada) $prompt .= "- Escalonamento de Problemas:\n  {$escalada}\n";
        } elseif ($agent_type === 'Auvvo') {
            if ($setores) $prompt .= "- Setores para Transferencia:\n  {$setores}\n";
        }

        $prompt .= "\nREGRAS E RESTRICOES\n";
        if ($restricoes) $prompt .= "- {$restricoes}\n";
        if ($extra_rules) $prompt .= "- {$extra_rules}\n";
        $prompt .= "- Nunca invente informacoes que nao foram fornecidas. Se nao souber responder, seja honesto e direcione o usuario para um humano se possivel.\n";

        json_out(['error' => false, 'prompt' => trim($prompt), 'source' => 'local']);
    }

    // ============================================================
    // CRM: Listar contatos
    // ============================================================
    case 'crm_get_contacts': {
        require_once 'Contacts.php';
        require_once __DIR__ . '/CrmPipelines.php';
        $crm = new Contacts($pdo);
        $pipes = new CrmPipelines($pdo);
        $pipeline_id = (int) ($_GET['pipeline_id'] ?? 0);
        $pipeline_id = $crm->resolvePipelineId($user_id, $pipeline_id ?: null);
        $filters = [
            'pipeline_id' => $pipeline_id,
            'stage'       => $_GET['stage'] ?? '',
            'agent_id'    => $_GET['agent_id'] ?? '',
            'search'      => $_GET['search'] ?? '',
            'tag'         => $_GET['tag'] ?? '',
        ];
        $filters['skip_backfill'] = true;
        $contacts = $crm->list($user_id, array_filter($filters));
        $counts   = $crm->countByStage($user_id, $pipeline_id);
        $stages   = $pipes->stagesMap($user_id, $pipeline_id);
        json_out([
            'error'         => false,
            'contacts'      => $contacts,
            'stage_counts'  => $counts,
            'stages'        => $stages,
            'pipeline_id'   => $pipeline_id,
            'pipelines'     => $pipes->listPipelines($user_id, false),
        ]);
    }

    // ============================================================
    // CRM: Buscar contato individual (com atividades e mensagens)
    // ============================================================
    case 'crm_get_contact': {
        require_once 'Contacts.php';
        $contact_id = intval($_GET['id'] ?? 0);
        $crm = new Contacts($pdo);
        $contact = $crm->get($user_id, $contact_id);
        if (!$contact) json_out(['error'=>true,'message'=>'Contato não encontrado.']);
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $pid = (int) ($contact['pipeline_id'] ?? 0);
        $pid = $crm->resolvePipelineId($user_id, $pid ?: null);
        json_out([
            'error'   => false,
            'contact' => $contact,
            'stages'  => $pipes->stagesMap($user_id, $pid),
            'pipeline_id' => $pid,
        ]);
    }

    // ============================================================
    // CRM: Salvar contato (create / update)
    // ============================================================
    case 'crm_save_contact': {
        require_once 'Contacts.php';
        $crm  = new Contacts($pdo);
        $data = [
            'id'       => intval($_POST['id'] ?? 0),
            'jid'      => trim($_POST['jid']      ?? ''),
            'agent_id' => intval($_POST['agent_id'] ?? 0) ?: null,
            'name'     => trim($_POST['name']     ?? ''),
            'phone'    => trim($_POST['phone']    ?? ''),
            'email'    => trim($_POST['email']    ?? ''),
            'company'  => trim($_POST['company']  ?? ''),
            'stage'        => trim($_POST['stage'] ?? ''),
            'pipeline_id'  => (int) ($_POST['pipeline_id'] ?? 0),
            'notes'    => trim($_POST['notes']    ?? ''),
            'tags'     => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
        ];
        if (trim($_POST['loss_reason'] ?? '') !== '') {
            $data['loss_reason'] = trim($_POST['loss_reason']);
        }
        $result = $crm->save($user_id, $data);
        json_out($result);
    }

    // ============================================================
    // CRM: Deletar contato
    // ============================================================
    case 'crm_delete_contact': {
        require_once 'Contacts.php';
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $crm = new Contacts($pdo);
        $ok  = $crm->delete($user_id, $contact_id);
        json_out(['error'=>!$ok, 'message'=> $ok ? 'Contato removido.' : 'Contato não encontrado.']);
    }

    // ============================================================
    // CRM: Atualizar estágio do contato (drag-and-drop kanban)
    // ============================================================
    case 'crm_update_stage': {
        require_once 'Contacts.php';
        require_once __DIR__ . '/CrmPipelines.php';
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $new_stage  = trim($_POST['stage'] ?? '');
        $crm = new Contacts($pdo);
        $pipes = new CrmPipelines($pdo);

        $stmt = $pdo->prepare('SELECT pipeline_id FROM contacts WHERE id = ? AND user_id = ?');
        $stmt->execute([$contact_id, $user_id]);
        $pid = (int) ($stmt->fetchColumn() ?: 0);
        $pid = $crm->resolvePipelineId($user_id, $pid ?: null);
        $valid = array_keys($pipes->stagesMap($user_id, $pid));
        if (!in_array($new_stage, $valid, true)) {
            json_out(['error' => true, 'message' => 'Estágio inválido.']);
        }

        $payload = ['id' => $contact_id, 'stage' => $new_stage];
        if ($pipes->isLostSlug($user_id, $pid, $new_stage)) {
            $payload['loss_reason'] = trim($_POST['loss_reason'] ?? '');
        }
        $result = $crm->save($user_id, $payload);
        json_out($result);
    }

    case 'queue_stats': {
        require_once 'ai_queue.inc.php';
        json_out(['error' => false, 'stats' => auvvo_ai_queue_stats($pdo, $user_id)]);
    }

    case 'conversation_threads': {
        $limit = min(80, max(1, (int) ($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare(
            'SELECT cl.agent_id, cl.contact_jid, MAX(cl.id) AS last_log_id
             FROM conversation_logs cl
             INNER JOIN agents a ON a.id = cl.agent_id AND a.user_id = ?
             WHERE cl.contact_jid NOT LIKE ?
             GROUP BY cl.agent_id, cl.contact_jid
             ORDER BY MAX(cl.created_at) DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$user_id, '%@g.us']);
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($keys === []) {
            json_out(['error' => false, 'threads' => []]);
        }
        $ids = array_map(static fn ($r) => (int) $r['last_log_id'], $keys);
        $in = implode(',', $ids);
        $logsStmt = $pdo->query(
            "SELECT cl.id, cl.agent_id, cl.contact_jid, cl.incoming_msg, cl.response_msg, cl.type, cl.created_at, a.name AS agent_name
             FROM conversation_logs cl
             INNER JOIN agents a ON a.id = cl.agent_id
             WHERE cl.id IN ({$in})"
        );
        $byId = [];
        while ($row = $logsStmt->fetch(PDO::FETCH_ASSOC)) {
            $byId[(int) $row['id']] = $row;
        }
        $threads = [];
        foreach ($keys as $k) {
            $log = $byId[(int) $k['last_log_id']] ?? null;
            if ($log) {
                $threads[] = $log;
            }
        }
        json_out(['error' => false, 'threads' => $threads]);
    }

    case 'conversation_thread_messages': {
        $agentId = (int) ($_GET['agent_id'] ?? 0);
        $jid = trim((string) ($_GET['contact_jid'] ?? ''));
        $limit = min(80, max(10, (int) ($_GET['limit'] ?? 50)));
        if ($agentId <= 0 || $jid === '') {
            json_out(['error' => true, 'message' => 'Parâmetros inválidos.']);
        }
        $own = $pdo->prepare('SELECT id FROM agents WHERE id = ? AND user_id = ? LIMIT 1');
        $own->execute([$agentId, $user_id]);
        if (!$own->fetchColumn()) {
            json_out(['error' => true, 'message' => 'Agente não encontrado.']);
        }
        $variants = auvvo_conversation_contact_jid_variants($pdo, $agentId, $jid);
        if ($variants === []) {
            json_out(['error' => false, 'messages' => []]);
        }
        $ph = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $pdo->prepare(
            "SELECT incoming_msg, response_msg, type, created_at
             FROM conversation_logs
             WHERE agent_id = ? AND contact_jid IN ({$ph})
             ORDER BY id ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute(array_merge([$agentId], $variants));
        json_out(['error' => false, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'conversation_events_since': {
        $since = (int) ($_GET['since'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id, agent_id, contact_jid, event_type, payload, created_at
             FROM conversation_events WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 100'
        );
        $stmt->execute([$user_id, $since]);
        json_out(['error' => false, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'crm_list_pipelines': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        json_out(['error' => false, 'pipelines' => $pipes->listPipelines($user_id)]);
    }

    case 'crm_save_pipeline': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $data = [
            'id'          => (int) ($_POST['id'] ?? 0),
            'name'        => trim($_POST['name'] ?? ''),
            'is_default'  => (int) ($_POST['is_default'] ?? 0),
            'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
        ];
        json_out($pipes->savePipeline($user_id, $data));
    }

    case 'crm_delete_pipeline': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $id = (int) ($_POST['pipeline_id'] ?? $_POST['id'] ?? 0);
        json_out($pipes->deletePipeline($user_id, $id));
    }

    case 'crm_duplicate_pipeline': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $id = (int) ($_POST['pipeline_id'] ?? 0);
        json_out($pipes->duplicatePipeline($user_id, $id));
    }

    case 'crm_set_default_pipeline': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $id = (int) ($_POST['pipeline_id'] ?? 0);
        json_out($pipes->setDefault($user_id, $id));
    }

    case 'crm_save_pipeline_stages': {
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $pipelineId = (int) ($_POST['pipeline_id'] ?? 0);
        $raw = $_POST['stages'] ?? '[]';
        $stages = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($stages)) {
            json_out(['error' => true, 'message' => 'JSON de estágios inválido.']);
        }
        json_out($pipes->saveStages($user_id, $pipelineId, $stages));
    }

    case 'crm_save_automation': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $trigger_type = trim($_POST['trigger_type'] ?? '');
        $trigger_value = trim($_POST['trigger_value'] ?? '');
        $action_type = trim($_POST['action_type'] ?? '');
        $allowedActions = [
            'send_whatsapp', 'assign_agent', 'invoke_agent', 'move_stage', 'add_tag', 'remove_tag',
            'pause_ai', 'resume_ai', 'set_memory', 'brain_mission', 'clear_brain_mission',
            'call_webhook', 'google_sheets_append', 'http_preset',
        ];
        if ($action_type === '' || !in_array($action_type, $allowedActions, true)) {
            json_out(['error' => true, 'message' => 'Tipo de ação inválido.']);
        }
        $action_config = $_POST['action_config'] ?? '{}';
        if (is_array($action_config)) {
            $action_config = json_encode($action_config, JSON_UNESCAPED_UNICODE);
        }
        $pipeline_id = (int) ($_POST['pipeline_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE crm_automations SET pipeline_id=?, trigger_type=?, trigger_value=?, action_type=?, action_config=?, is_active=1 WHERE id=? AND user_id=?'
            )->execute([$pipeline_id > 0 ? $pipeline_id : null, $trigger_type, $trigger_value, $action_type, $action_config, $id, $user_id]);
        } else {
            $pdo->prepare(
                'INSERT INTO crm_automations (user_id, pipeline_id, trigger_type, trigger_value, action_type, action_config) VALUES (?,?,?,?,?,?)'
            )->execute([$user_id, $pipeline_id > 0 ? $pipeline_id : null, $trigger_type, $trigger_value, $action_type, $action_config]);
        }
        json_out(['error' => false]);
    }

    case 'crm_list_automations': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $stmt = $pdo->prepare(
            'SELECT a.*, p.name AS pipeline_name FROM crm_automations a
             LEFT JOIN crm_pipelines p ON p.id = a.pipeline_id AND p.user_id = a.user_id
             WHERE a.user_id = ? ORDER BY a.id DESC'
        );
        $stmt->execute([$user_id]);
        json_out(['error' => false, 'automations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'crm_automation_queue_stats': {
        require_once __DIR__ . '/crm_automation.inc.php';
        auvvo_run_migrations($pdo);
        $stats = auvvo_crm_automation_queue_stats($pdo, $user_id);
        json_out(['error' => false, 'stats' => auvvo_crm_queue_stats_enriched($pdo, $user_id, $stats)]);
    }

    case 'crm_automation_dedupe_warnings': {
        require_once __DIR__ . '/crm_automation_conflicts.inc.php';
        auvvo_run_migrations($pdo);
        json_out(['error' => false, 'warnings' => auvvo_crm_automation_dedupe_warnings($pdo, $user_id)]);
    }

    case 'brain_list_actions': {
        require_once __DIR__ . '/auvvo_brain_log.inc.php';
        auvvo_run_migrations($pdo);
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        $jid = trim((string) ($_GET['contact_jid'] ?? ''));
        if ($contactId <= 0 && $jid === '') {
            json_out(['error' => true, 'message' => 'contact_id ou contact_jid obrigatório']);
        }
        json_out([
            'error'   => false,
            'actions' => auvvo_brain_list_actions($pdo, $user_id, $contactId > 0 ? $contactId : null, $jid !== '' ? $jid : null),
        ]);
    }

    case 'crm_record_purchase': {
        require_once __DIR__ . '/crm_ltv.inc.php';
        require_once __DIR__ . '/Contacts.php';
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) {
            json_out(['error' => true, 'message' => 'contact_id obrigatório']);
        }
        $crm = new Contacts($pdo);
        if (!$crm->get($user_id, $contactId)) {
            json_out(['error' => true, 'message' => 'Contato não encontrado']);
        }
        auvvo_crm_record_purchase($pdo, $user_id, $contactId, [
            'product_name' => trim($_POST['product_name'] ?? ''),
            'amount'       => $_POST['amount'] ?? null,
            'source'       => 'manual',
        ]);
        json_out(['error' => false, 'contact' => $crm->get($user_id, $contactId)]);
    }

    case 'crm_delete_automation': {
        require_once __DIR__ . '/migrations.php';
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM crm_automations WHERE id = ? AND user_id = ?')->execute([$id, $user_id]);
        json_out(['error' => false]);
    }

    case 'crm_list_pack_templates': {
        require_once __DIR__ . '/AuvvoPackTemplates.php';
        json_out(['error' => false, 'packs' => AuvvoPackTemplates::listForUi()]);
    }

    case 'crm_apply_pack_template': {
        require_once __DIR__ . '/AuvvoPackTemplates.php';
        $packId = trim($_POST['pack_id'] ?? '');
        if ($packId === '') {
            json_out(['error' => true, 'message' => 'pack_id obrigatório']);
        }
        try {
            $result = AuvvoPackTemplates::provisionAgents($pdo, $user_id, $packId);
            json_out([
                'error'       => false,
                'pack_id'     => $packId,
                'agents'      => $result['agents'],
                'agent_names' => $result['agent_names'],
                'agent_rows'  => $result['agent_rows'],
                'message'     => 'Agentes criados. Conecte o WhatsApp em cada agente e publique os fluxos quando estiver pronto.',
            ]);
        } catch (Throwable $e) {
            json_out(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    case 'crm_list_flows': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $stmt = $pdo->prepare(
            'SELECT f.id, f.name, f.description, f.is_active, f.pipeline_id, f.stats_entered, f.stats_success,
                    f.stats_error, f.updated_at, f.created_at, p.name AS pipeline_name
             FROM crm_automation_flows f
             LEFT JOIN crm_pipelines p ON p.id = f.pipeline_id AND p.user_id = f.user_id
             WHERE f.user_id = ? ORDER BY f.updated_at DESC, f.id DESC'
        );
        $stmt->execute([$user_id]);
        json_out(['error' => false, 'flows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'crm_get_flow': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            json_out(['error' => true, 'message' => 'id obrigatório']);
        }
        $stmt = $pdo->prepare('SELECT * FROM crm_automation_flows WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_out(['error' => true, 'message' => 'Fluxo não encontrado']);
        }
        json_out(['error' => false, 'flow' => $row]);
    }

    case 'crm_save_flow': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_flow_validation.inc.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? 'Nova automação');
        if ($name === '') {
            $name = 'Nova automação';
        }
        $description = trim($_POST['description'] ?? '');
        $flowData = $_POST['flow_data'] ?? '{}';
        if (is_array($flowData)) {
            $flowData = json_encode($flowData, JSON_UNESCAPED_UNICODE);
        }
        $isActive = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $forcePublish = in_array(strtolower(trim((string) ($_POST['force_publish'] ?? '0'))), ['1', 'true', 'yes'], true);
        $pipelineId = (int) ($_POST['pipeline_id'] ?? 0);
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        if ($pipelineId <= 0) {
            $pipelineId = $pipes->defaultPipelineId($user_id);
        } else {
            $chk = $pdo->prepare('SELECT id FROM crm_pipelines WHERE id = ? AND user_id = ?');
            $chk->execute([$pipelineId, $user_id]);
            if (!$chk->fetchColumn()) {
                json_out(['error' => true, 'message' => 'Pipeline inválido.']);
            }
        }
        if ($isActive) {
            $validation = auvvo_flow_validate_graph($pdo, $user_id, (string) $flowData, true);
            if (!$validation['valid'] && !$forcePublish) {
                $isActive = 0;
                $validationWarnings = $validation;
            }
        }
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE crm_automation_flows SET pipeline_id=?, name=?, description=?, flow_data=?, is_active=? WHERE id=? AND user_id=?'
            )->execute([$pipelineId, $name, $description, $flowData, $isActive, $id, $user_id]);
            $resp = ['error' => false, 'id' => $id, 'pipeline_id' => $pipelineId, 'is_active' => $isActive];
            if (isset($validationWarnings)) $resp['validation'] = $validationWarnings;
            json_out($resp);
        } else {
            $pdo->prepare(
                'INSERT INTO crm_automation_flows (user_id, pipeline_id, name, description, flow_data, is_active) VALUES (?,?,?,?,?,?)'
            )->execute([$user_id, $pipelineId, $name, $description, $flowData, $isActive]);
            $resp = ['error' => false, 'id' => (int) $pdo->lastInsertId(), 'pipeline_id' => $pipelineId, 'is_active' => $isActive];
            if (isset($validationWarnings)) $resp['validation'] = $validationWarnings;
            json_out($resp);
        }
    }

    case 'crm_flow_publish_checklist': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_flow_validation.inc.php';
        auvvo_run_migrations($pdo);
        $flowId = (int) ($_GET['flow_id'] ?? $_POST['flow_id'] ?? 0);
        $flowData = $_POST['flow_data'] ?? null;
        if (is_array($flowData)) {
            $flowData = json_encode($flowData, JSON_UNESCAPED_UNICODE);
        }
        if (($flowData === null || $flowData === '') && $flowId > 0) {
            $st = $pdo->prepare('SELECT flow_data FROM crm_automation_flows WHERE id = ? AND user_id = ? LIMIT 1');
            $st->execute([$flowId, $user_id]);
            $flowData = (string) ($st->fetchColumn() ?: '{}');
        }
        json_out([
            'error' => false,
            'checklist' => auvvo_flow_publish_checklist($pdo, $user_id, (string) ($flowData ?? '{}'), $flowId),
        ]);
    }

    case 'crm_flow_validate': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_flow_validation.inc.php';
        auvvo_run_migrations($pdo);
        $flowData = $_POST['flow_data'] ?? '{}';
        if (is_array($flowData)) {
            $flowData = json_encode($flowData, JSON_UNESCAPED_UNICODE);
        }
        $forPublish = in_array(strtolower(trim((string) ($_POST['for_publish'] ?? '1'))), ['1', 'true', 'yes'], true);
        json_out([
            'error' => false,
            'validation' => auvvo_flow_validate_graph($pdo, $user_id, (string) $flowData, $forPublish),
        ]);
    }

    case 'crm_delete_flow': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM crm_automation_flows WHERE id = ? AND user_id = ?')->execute([$id, $user_id]);
        json_out(['error' => false]);
    }

    case 'crm_toggle_flow': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['is_active'] ?? 0) ? 1 : 0;
        $pdo->prepare('UPDATE crm_automation_flows SET is_active = ? WHERE id = ? AND user_id = ?')
            ->execute([$active, $id, $user_id]);
        json_out(['error' => false]);
    }

    case 'crm_simulate_flow': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_automation_runs.inc.php';
        auvvo_run_migrations($pdo);
        $flowId = (int) ($_POST['flow_id'] ?? 0);
        $flowData = $_POST['flow_data'] ?? null;
        if (is_array($flowData)) {
            $flowData = json_encode($flowData, JSON_UNESCAPED_UNICODE);
        }
        $triggerType = trim((string) ($_POST['trigger_type'] ?? 'whatsapp_first'));
        $triggerValue = trim((string) ($_POST['trigger_value'] ?? '*'));
        $messageBody = trim((string) ($_POST['message_body'] ?? ''));
        $connectionId = (int) ($_POST['connection_id'] ?? 0);
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $contactOverrides = [];
        foreach (['name', 'phone', 'email', 'company', 'stage'] as $k) {
            if (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '') {
                $contactOverrides[$k] = trim((string) $_POST[$k]);
            }
        }
        if ($flowId <= 0 && ($flowData === null || $flowData === '' || $flowData === '{}')) {
            json_out(['error' => true, 'message' => 'Informe flow_id ou flow_data']);
        }
        $useLlm = in_array(strtolower(trim((string) ($_POST['use_llm'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
        $continueRunId = (int) ($_POST['continue_run_id'] ?? 0);
        $result = auvvo_automation_simulate_flow(
            $pdo,
            $user_id,
            $flowId,
            is_string($flowData) ? $flowData : null,
            $triggerType,
            $triggerValue,
            $messageBody,
            $contactOverrides,
            $contactId > 0 ? $contactId : null,
            $connectionId,
            $useLlm,
            $continueRunId
        );
        json_out(array_merge(['error' => (bool) ($result['error'] ?? false)], $result));
    }

    case 'crm_list_runs': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_automation_runs.inc.php';
        auvvo_run_migrations($pdo);
        $flowId = (int) ($_GET['flow_id'] ?? 0);
        $limit = (int) ($_GET['limit'] ?? 40);
        $modeFilter = trim((string) ($_GET['mode'] ?? 'live'));
        if (!in_array($modeFilter, ['live', 'simulate', 'all'], true)) {
            $modeFilter = 'live';
        }
        if ($modeFilter === 'all') {
            $modeFilter = '';
        }
        $runs = auvvo_automation_list_runs($pdo, $user_id, $flowId, $limit, $modeFilter);
        json_out(['error' => false, 'runs' => $runs]);
    }

    case 'crm_get_run': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_automation_runs.inc.php';
        auvvo_run_migrations($pdo);
        $runId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($runId <= 0) {
            json_out(['error' => true, 'message' => 'id obrigatório']);
        }
        $run = auvvo_automation_get_run($pdo, $user_id, $runId);
        if (!$run) {
            json_out(['error' => true, 'message' => 'Execução não encontrada']);
        }
        json_out(['error' => false, 'run' => $run]);
    }

    case 'crm_flow_node_stats': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_flow_agent.inc.php';
        auvvo_run_migrations($pdo);
        $flowId = (int) ($_GET['flow_id'] ?? 0);
        if ($flowId <= 0) {
            json_out(['error' => true, 'message' => 'flow_id obrigatório']);
        }
        $chk = $pdo->prepare('SELECT id FROM crm_automation_flows WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$flowId, $user_id]);
        if (!$chk->fetchColumn()) {
            json_out(['error' => true, 'message' => 'Fluxo não encontrado']);
        }
        json_out(['error' => false, 'stats' => auvvo_automation_node_stats($pdo, $user_id, $flowId)]);
    }

    case 'crm_flow_node_errors': {
        require_once __DIR__ . '/migrations.php';
        require_once __DIR__ . '/crm_flow_validation.inc.php';
        auvvo_run_migrations($pdo);
        $flowId = (int) ($_GET['flow_id'] ?? 0);
        if ($flowId <= 0) {
            json_out(['error' => true, 'message' => 'flow_id obrigatório']);
        }
        json_out(['error' => false, 'errors' => auvvo_flow_node_last_errors($pdo, $user_id, $flowId)]);
    }

    case 'crm_get_contact_by_jid': {
        require_once 'Contacts.php';
        $jid = trim($_GET['jid'] ?? '');
        if ($jid === '') {
            json_out(['error' => true, 'message' => 'jid obrigatório']);
        }
        $stmt = $pdo->prepare('SELECT id FROM contacts WHERE user_id = ? AND jid = ? LIMIT 1');
        $stmt->execute([$user_id, $jid]);
        $cid = (int) ($stmt->fetchColumn() ?: 0);
        if ($cid <= 0) {
            json_out(['error' => false, 'has' => false]);
        }
        $crm = new Contacts($pdo);
        $contact = $crm->get($user_id, $cid);
        require_once __DIR__ . '/CrmPipelines.php';
        $pipes = new CrmPipelines($pdo);
        $pid = (int) ($contact['pipeline_id'] ?? 0);
        $pid = $crm->resolvePipelineId($user_id, $pid ?: null);
        json_out([
            'error'   => false,
            'has'     => true,
            'contact' => $contact,
            'stages'  => $pipes->stagesMap($user_id, $pid),
        ]);
    }

    case 'inbound_webhook_save': {
        require_once __DIR__ . '/inbound_webhook_service.inc.php';
        $res = auvvo_inbound_webhook_create($pdo, $user_id, [
            'name'                 => $_POST['name'] ?? '',
            'url_slug'             => $_POST['url_slug'] ?? '',
            'field_maps'           => $_POST['field_maps'] ?? '[]',
            'variable_maps'        => $_POST['variable_maps'] ?? '[]',
            'response_template'    => $_POST['response_template'] ?? '',
            'default_agent_id'     => $_POST['default_agent_id'] ?? 0,
            'phone_country_prefix' => $_POST['phone_country_prefix'] ?? '55',
        ]);
        if (!$res['ok']) {
            json_out(['error' => true, 'message' => $res['message'] ?? 'Erro ao criar webhook']);
        }
        json_out([
            'error'        => false,
            'id'           => $res['id'],
            'url'          => $res['url'],
            'secret_token' => $res['secret_token'],
            'url_slug'     => $res['url_slug'] ?? '',
        ]);
    }

    case 'inbound_webhook_detail': {
        require_once __DIR__ . '/inbound_webhook_service.inc.php';
        $id = (int) ($_GET['id'] ?? 0);
        $hook = auvvo_inbound_webhook_get_detail($pdo, $user_id, $id);
        if (!$hook) {
            json_out(['error' => true, 'message' => 'Webhook não encontrado']);
        }
        $payload = $hook['last_payload'] ?? [];
        $paths = is_array($payload) ? auvvo_flatten_json_paths($payload) : [];
        json_out(['error' => false, 'webhook' => $hook, 'payload_paths' => $paths]);
    }

    case 'inbound_webhook_save_maps': {
        require_once __DIR__ . '/inbound_webhook_service.inc.php';
        $id = (int) ($_POST['webhook_id'] ?? 0);
        $maps = json_decode($_POST['field_maps'] ?? '[]', true);
        if ($id <= 0 || !is_array($maps)) {
            json_out(['error' => true, 'message' => 'Dados inválidos']);
        }
        auvvo_inbound_webhook_save_maps($pdo, $user_id, $id, $maps);
        json_out(['error' => false]);
    }

    case 'inbound_webhook_save_sample': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['webhook_id'] ?? 0);
        $sample = $_POST['sample_payload'] ?? '{}';
        if ($id <= 0) {
            json_out(['error' => true, 'message' => 'webhook_id obrigatório']);
        }
        $decoded = json_decode($sample, true);
        if (!is_array($decoded)) {
            json_out(['error' => true, 'message' => 'JSON inválido']);
        }
        try {
            $pdo->prepare('UPDATE inbound_webhooks SET sample_payload = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE), $id, $user_id]);
        } catch (PDOException $e) {
            json_out(['error' => true, 'message' => 'Atualize o banco (migrações).']);
        }
        require_once __DIR__ . '/inbound_webhook_service.inc.php';
        json_out([
            'error'         => false,
            'payload_paths' => auvvo_flatten_json_paths($decoded),
        ]);
    }

    case 'inbound_webhook_list': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $stmt = $pdo->prepare(
            'SELECT id, name, url_slug, is_active, default_agent_id, response_template, variable_maps, created_at
             FROM inbound_webhooks WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['url'] = app_http_url('backend/webhook_inbound.php?slug=' . $r['url_slug']);
        }
        json_out(['error' => false, 'webhooks' => $rows]);
    }

    case 'inbound_webhook_update': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_out(['error' => true, 'message' => 'id obrigatório']);
        }
        $name = trim($_POST['name'] ?? '');
        $response = trim($_POST['response_template'] ?? '');
        $agentId = (int) ($_POST['default_agent_id'] ?? 0);
        $entryStage = trim($_POST['entry_stage'] ?? '');
        $entryPipelineId = (int) ($_POST['entry_pipeline_id'] ?? 0);
        $entryTagsRaw = trim($_POST['entry_tags'] ?? '');
        $maps = $_POST['variable_maps'] ?? '[]';
        if (is_array($maps)) {
            $maps = json_encode($maps, JSON_UNESCAPED_UNICODE);
        }
        $entryTagsJson = null;
        if ($entryTagsRaw !== '') {
            $tags = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $entryTagsRaw))));
            $entryTagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE);
        } elseif (array_key_exists('entry_tags', $_POST)) {
            $entryTagsJson = '[]';
        }
        $countsPurchase = array_key_exists('counts_as_purchase', $_POST)
            ? ((int) ($_POST['counts_as_purchase'] ?? 0) ? 1 : 0)
            : null;
        $hasUpdate = $name !== ''
            || $response !== ''
            || array_key_exists('default_agent_id', $_POST)
            || array_key_exists('entry_stage', $_POST)
            || array_key_exists('entry_pipeline_id', $_POST)
            || array_key_exists('entry_tags', $_POST)
            || $countsPurchase !== null
            || ($maps !== '[]' && $maps !== null);
        if (!$hasUpdate) {
            json_out(['error' => true, 'message' => 'Nada para atualizar']);
        }
        $sets = [];
        $params = [];
        if ($name !== '') {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($response !== '') {
            $sets[] = 'response_template = ?';
            $params[] = $response;
        }
        if (array_key_exists('default_agent_id', $_POST)) {
            $sets[] = 'default_agent_id = ?';
            $params[] = $agentId > 0 ? $agentId : null;
        }
        if (array_key_exists('entry_stage', $_POST)) {
            $sets[] = 'entry_stage = ?';
            $params[] = $entryStage !== '' ? $entryStage : null;
        }
        if (array_key_exists('entry_pipeline_id', $_POST)) {
            $sets[] = 'entry_pipeline_id = ?';
            $params[] = $entryPipelineId > 0 ? $entryPipelineId : null;
        }
        if ($entryTagsJson !== null) {
            $sets[] = 'entry_tags = ?';
            $params[] = $entryTagsJson;
        }
        if ($countsPurchase !== null) {
            $sets[] = 'counts_as_purchase = ?';
            $params[] = $countsPurchase;
        }
        if ($maps !== '[]' && $maps !== null) {
            $sets[] = 'variable_maps = ?';
            $params[] = $maps;
        }
        $params[] = $id;
        $params[] = $user_id;
        $pdo->prepare(
            'UPDATE inbound_webhooks SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        )->execute($params);
        json_out(['error' => false]);
    }

    case 'inbound_webhook_delete': {
        require_once __DIR__ . '/migrations.php';
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare(
            'DELETE m FROM inbound_webhook_field_maps m
             INNER JOIN inbound_webhooks w ON w.id = m.webhook_id
             WHERE m.webhook_id = ? AND w.user_id = ?'
        )->execute([$id, $user_id]);
        $pdo->prepare('DELETE FROM inbound_webhooks WHERE id = ? AND user_id = ?')->execute([$id, $user_id]);
        json_out(['error' => false]);
    }

    case 'outbound_webhook_save': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? 'Webhook outbound');
        $url = trim($_POST['target_url'] ?? '');
        if ($url === '') {
            json_out(['error' => true, 'message' => 'URL obrigatória']);
        }
        require_once __DIR__ . '/http_ssrf.inc.php';
        $urlCheck = auvvo_http_url_validate($url);
        if (!$urlCheck['ok']) {
            json_out(['error' => true, 'message' => 'URL não permitida (use HTTPS público).']);
        }
        $url = (string) $urlCheck['url'];
        $method = strtoupper(trim($_POST['http_method'] ?? 'POST'));
        $body = trim($_POST['body_template'] ?? '{"contact_id":"{{contact.id}}","stage":"{{contact.stage}}"}');
        $hdr = $_POST['headers_json'] ?? '{}';
        $maps = $_POST['response_var_maps'] ?? '[]';
        if (is_array($hdr)) {
            $hdr = json_encode($hdr, JSON_UNESCAPED_UNICODE);
        }
        if (is_array($maps)) {
            $maps = json_encode($maps, JSON_UNESCAPED_UNICODE);
        }
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE outbound_webhooks SET name=?, target_url=?, http_method=?, headers_json=?, body_template=?, response_var_maps=? WHERE id=? AND user_id=?'
            )->execute([$name, $url, $method, $hdr, $body, $maps, $id, $user_id]);
            json_out(['error' => false, 'id' => $id]);
        }
        $pdo->prepare(
            'INSERT INTO outbound_webhooks (user_id, name, target_url, http_method, headers_json, body_template, response_var_maps) VALUES (?,?,?,?,?,?,?)'
        )->execute([$user_id, $name, $url, $method, $hdr, $body, $maps]);
        json_out(['error' => false, 'id' => (int) $pdo->lastInsertId()]);
    }

    case 'outbound_webhook_list': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $stmt = $pdo->prepare('SELECT * FROM outbound_webhooks WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$user_id]);
        json_out(['error' => false, 'webhooks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'outbound_webhook_delete': {
        require_once __DIR__ . '/migrations.php';
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM outbound_webhooks WHERE id = ? AND user_id = ?')->execute([$id, $user_id]);
        json_out(['error' => false]);
    }

    case 'webhook_logs': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $kind = trim($_GET['kind'] ?? '');
        $wid = (int) ($_GET['webhook_id'] ?? 0);
        $limit = min(100, max(5, (int) ($_GET['limit'] ?? 30)));
        $sql = 'SELECT * FROM webhook_call_logs WHERE user_id = ?';
        $params = [$user_id];
        if ($kind !== '') {
            $sql .= ' AND webhook_kind = ?';
            $params[] = $kind;
        }
        if ($wid > 0) {
            $sql .= ' AND webhook_id = ?';
            $params[] = $wid;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_out(['error' => false, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'webhook_variables': {
        require_once __DIR__ . '/webhook_engine.inc.php';
        $kind = trim($_GET['kind'] ?? 'inbound');
        $wid = (int) ($_GET['webhook_id'] ?? 0);
        if ($wid <= 0) {
            json_out(['error' => true, 'message' => 'webhook_id obrigatório']);
        }
        json_out([
            'error' => false,
            'variables' => auvvo_webhook_get_variables($pdo, $user_id, $kind, $wid),
        ]);
    }

    case 'webhook_test_outbound': {
        require_once __DIR__ . '/webhook_engine.inc.php';
        $wid = (int) ($_POST['webhook_id'] ?? 0);
        if ($wid <= 0) {
            json_out(['error' => true, 'message' => 'webhook_id obrigatório']);
        }
        $ctx = json_decode($_POST['context'] ?? '{}', true);
        if (!is_array($ctx)) {
            $ctx = ['test' => true];
        }
        $res = auvvo_webhook_call_outbound($pdo, $user_id, $wid, $ctx);
        json_out(['error' => false, 'result' => $res]);
    }

    case 'agents_list_simple': {
        $stmt = $pdo->prepare('SELECT id, name, agent_type, status, flow_mode FROM agents WHERE user_id = ? AND status != ? ORDER BY name');
        $stmt->execute([$user_id, 'draft']);
        json_out(['error' => false, 'agents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'integrations_catalog': {
        require_once __DIR__ . '/integrations_catalog.inc.php';
        $catalog = auvvo_integrations_catalog();
        foreach ($catalog as &$item) {
            $st = auvvo_integration_status($pdo, $user_id, $item['id']);
            $item['status'] = $st;
        }
        json_out(['error' => false, 'integrations' => $catalog]);
    }

    case 'api_key_create': {
        require_once __DIR__ . '/ApiAuth.php';
        $name = trim($_POST['name'] ?? 'Chave API');
        $perms = json_decode($_POST['permissions'] ?? '["crm.read","crm.write"]', true);
        if (!is_array($perms)) {
            $perms = [ApiAuth::PERM_CRM_READ, ApiAuth::PERM_CRM_WRITE];
        }
        $created = ApiAuth::createKey($pdo, $user_id, $name, $perms);
        json_out(['error' => false, 'key' => $created]);
    }

    case 'api_key_list': {
        require_once __DIR__ . '/ApiAuth.php';
        json_out(['error' => false, 'keys' => ApiAuth::listKeys($pdo, $user_id)]);
    }

    case 'api_key_revoke': {
        require_once __DIR__ . '/ApiAuth.php';
        ApiAuth::revokeKey($pdo, $user_id, (int) ($_POST['id'] ?? 0));
        json_out(['error' => false]);
    }

    case 'google_sheets_status': {
        require_once __DIR__ . '/GoogleSheets.php';
        $t = GoogleSheets::loadToken($pdo, $user_id);
        json_out([
            'error'     => false,
            'connected' => $t !== null,
            'config'    => $t ? [
                'spreadsheet_id' => $t['spreadsheet_id'] ?? '',
                'sheet_name'     => $t['sheet_name'] ?? 'Leads',
            ] : null,
        ]);
    }

    case 'google_sheets_save_config': {
        require_once __DIR__ . '/GoogleSheets.php';
        try {
            GoogleSheets::saveSheetConfig(
                $pdo,
                $user_id,
                trim($_POST['spreadsheet_id'] ?? ''),
                trim($_POST['sheet_name'] ?? 'Leads')
            );
            json_out(['error' => false]);
        } catch (Throwable $e) {
            json_out(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    case 'google_sheets_list': {
        require_once __DIR__ . '/GoogleSheets.php';
        try {
            json_out(['error' => false, 'files' => GoogleSheets::listSpreadsheets($pdo, $user_id)]);
        } catch (Throwable $e) {
            json_out(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    case 'google_sheets_test': {
        require_once __DIR__ . '/GoogleSheets.php';
        try {
            $res = GoogleSheets::appendRow($pdo, $user_id, [
                'Teste Auvvo',
                'integracoes',
                date('Y-m-d H:i:s'),
            ]);
            json_out(['error' => false, 'result' => $res]);
        } catch (Throwable $e) {
            json_out(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    case 'http_preset_save': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? 'HTTP');
        $url = trim($_POST['target_url'] ?? '');
        if ($url === '') {
            json_out(['error' => true, 'message' => 'URL obrigatória']);
        }
        require_once __DIR__ . '/http_ssrf.inc.php';
        $urlCheck = auvvo_http_url_validate($url);
        if (!$urlCheck['ok']) {
            json_out(['error' => true, 'message' => 'URL não permitida (use HTTPS público).']);
        }
        $url = (string) $urlCheck['url'];
        $provider = trim($_POST['provider_slug'] ?? 'custom');
        $method = strtoupper(trim($_POST['http_method'] ?? 'POST'));
        $body = trim($_POST['body_template'] ?? '{}');
        $hdr = $_POST['headers_json'] ?? '{}';
        if (is_array($hdr)) {
            $hdr = json_encode($hdr, JSON_UNESCAPED_UNICODE);
        }
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE integration_http_presets SET name=?, target_url=?, http_method=?, headers_json=?, body_template=?, provider_slug=? WHERE id=? AND user_id=?'
            )->execute([$name, $url, $method, $hdr, $body, $provider, $id, $user_id]);
            json_out(['error' => false, 'id' => $id]);
        }
        $pdo->prepare(
            'INSERT INTO integration_http_presets (user_id, name, target_url, http_method, headers_json, body_template, provider_slug) VALUES (?,?,?,?,?,?,?)'
        )->execute([$user_id, $name, $url, $method, $hdr, $body, $provider]);
        json_out(['error' => false, 'id' => (int) $pdo->lastInsertId()]);
    }

    case 'http_preset_list': {
        require_once __DIR__ . '/migrations.php';
        auvvo_run_migrations($pdo);
        $stmt = $pdo->prepare('SELECT * FROM integration_http_presets WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$user_id]);
        json_out(['error' => false, 'presets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'http_preset_delete': {
        require_once __DIR__ . '/migrations.php';
        $pdo->prepare('DELETE FROM integration_http_presets WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $user_id]);
        json_out(['error' => false]);
    }

    // ============================================================
    // CRM: Adicionar atividade (nota, ligação, email…)
    // ============================================================
    case 'crm_add_activity': {
        require_once 'Contacts.php';
        $contact_id  = intval($_POST['contact_id'] ?? 0);
        $type        = trim($_POST['type'] ?? 'note');
        $description = trim($_POST['description'] ?? '');
        if (!$contact_id || !$description) json_out(['error'=>true,'message'=>'Parâmetros obrigatórios.']);

        // Verifica dono
        $stmt = $pdo->prepare("SELECT id FROM contacts WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$contact_id, $user_id]);
        if (!$stmt->fetch()) json_out(['error'=>true,'message'=>'Contato não encontrado.']);

        $crm = new Contacts($pdo);
        $act_id = $crm->addActivity($user_id, $contact_id, $type, $description);
        json_out(['error'=>false,'activity_id'=>$act_id]);
    }

    // ============================================================
    // CRM: Adicionar tag
    // ============================================================
    case 'crm_add_tag': {
        require_once 'Contacts.php';
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $tag        = trim($_POST['tag'] ?? '');
        if (!$contact_id || !$tag) json_out(['error'=>true,'message'=>'Parâmetros obrigatórios.']);
        $crm = new Contacts($pdo);
        $ok  = $crm->addTag($user_id, $contact_id, $tag);
        json_out(['error'=>!$ok]);
    }

    // ============================================================
    // CRM: Remover tag
    // ============================================================
    case 'crm_remove_tag': {
        require_once 'Contacts.php';
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $tag        = trim($_POST['tag'] ?? '');
        if (!$contact_id || !$tag) json_out(['error'=>true,'message'=>'Parâmetros obrigatórios.']);
        $crm = new Contacts($pdo);
        $ok  = $crm->removeTag($user_id, $contact_id, $tag);
        json_out(['error'=>!$ok]);
    }

    // ============================================================
    // CRM: Exportar CSV
    // ============================================================
    case 'crm_export_csv': {
        require_once 'Contacts.php';
        $crm = new Contacts($pdo);
        $pipeline_id = (int) ($_GET['pipeline_id'] ?? 0);
        $filters = [
            'pipeline_id' => $pipeline_id > 0 ? $pipeline_id : null,
            'stage'       => $_GET['stage'] ?? '',
            'agent_id'    => $_GET['agent_id'] ?? '',
            'search'      => $_GET['search'] ?? '',
        ];
        $crm->exportCsv($user_id, array_filter($filters, fn ($v) => $v !== null && $v !== ''));
        exit;
    }

    default:
        json_out(['error'=>true,'message'=>"Ação '{$action}' não reconhecida."]);
}
?>
