<?php
// backend/process_campaign.php
require_once '../includes/auth.php';
require_once 'db.php';
require_once __DIR__ . '/whatsapp_connections.inc.php';
require_once __DIR__ . '/migrations.php';

auvvo_run_migrations($pdo);

// Proteção CSRF obrigatória
csrf_verify();

$user_id  = $_SESSION['user_id'];
$action   = $_POST['action'] ?? '';

// === AÇÃO: CRIAR CAMPANHA ===
if ($action === 'create_campaign') {
    $campaign_name = trim($_POST['campaign_name'] ?? '');
    $connection_id = (int) ($_POST['whatsapp_connection_id'] ?? 0);
    $agent_id      = (int) ($_POST['agent_id'] ?? 0);
    $message       = trim($_POST['message'] ?? '');
    $schedule_type = $_POST['schedule_type'] ?? 'now'; // 'now' ou 'schedule'
    $scheduled_at  = null;

    if ($schedule_type === 'schedule' && !empty($_POST['scheduled_at'])) {
        $scheduled_at = date('Y-m-d H:i:s', strtotime($_POST['scheduled_at']));
    }

    if ($connection_id <= 0) {
        header('Location: ../campanhas?error=missing_connection');
        exit;
    }
    $connRow = auvvo_whatsapp_connection_get($pdo, (int) $user_id, $connection_id);
    if (!$connRow) {
        header('Location: ../campanhas?error=invalid_connection');
        exit;
    }

    if ($agent_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE id = ? AND user_id = ?");
        $stmt->execute([$agent_id, $user_id]);
        if (!$stmt->fetch()) {
            die("Acesso negado ao agente.");
        }
    }

    if (!$campaign_name || !$message) {
        header("Location: ../campanhas?error=missing_fields");
        exit;
    }

    // Upload do CSV (opcional)
    $csv_file_name = null;
    $total_contacts = 0;
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            header("Location: ../campanhas?error=invalid_csv");
            exit;
        }
        
        $safe_name = 'campaign_' . time() . '_' . $user_id . '.csv';
        $dir = __DIR__ . '/../uploads/campaigns/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest_path = $dir . $safe_name;
        
        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            $csv_file_name = $safe_name;
            // Conta as linhas do CSV (subtraindo o cabeçalho)
            $line_count = count(file($dest_path));
            $total_contacts = max(0, $line_count - 1);
        }
    }

    $status = ($schedule_type === 'schedule') ? 'scheduled' : 'running';

    $stmt = $pdo->prepare(
        "INSERT INTO campaigns (user_id, whatsapp_connection_id, agent_id, name, message, csv_file, total_contacts, status, scheduled_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $user_id,
        $connection_id,
        $agent_id > 0 ? $agent_id : null,
        $campaign_name,
        $message,
        $csv_file_name,
        $total_contacts,
        $status,
        $scheduled_at
    ]);

    $campaign_id = (int) $pdo->lastInsertId();
    if ($csv_file_name && $campaign_id > 0) {
        require_once __DIR__ . '/campaign_queue.inc.php';
        $queued = auvvo_campaign_queue_populate($pdo, $campaign_id);
        $total_contacts = $queued;
    }

    header("Location: ../campanhas?success=created&contacts={$total_contacts}");
    exit;
}

// === AÇÃO: DELETAR CAMPANHA ===
if ($action === 'delete_campaign') {
    $campaign_id = intval($_POST['campaign_id'] ?? 0);
    if ($campaign_id) {
        $stmt = $pdo->prepare("SELECT csv_file FROM campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([$campaign_id, $user_id]);
        $campaign = $stmt->fetch();
        
        if ($campaign) {
            if ($campaign['csv_file']) {
                $path = __DIR__ . '/../uploads/campaigns/' . $campaign['csv_file'];
                if (file_exists($path)) unlink($path);
            }
            $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$campaign_id, $user_id]);
        }
    }
    header("Location: ../campanhas?success=deleted");
    exit;
}

header("Location: ../campanhas");
exit;
?>
