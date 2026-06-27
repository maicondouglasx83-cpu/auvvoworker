<?php
// backend/process_knowledge.php
require_once '../includes/auth.php';
require_once 'db.php';
require_once 'MasterPromptBuilder.php';

// Proteção CSRF obrigatória
csrf_verify();

$user_id = $_SESSION['user_id'];
$agent_id = intval($_POST['agent_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Verifica se o agente pertence ao usuário logado
if ($agent_id) {
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE id = ? AND user_id = ?");
    $stmt->execute([$agent_id, $user_id]);
    if (!$stmt->fetch()) {
        die("Acesso negado ao agente.");
    }
}

// === AÇÃO: DELETAR ARQUIVO DE CONHECIMENTO ===
if ($action === 'delete_knowledge') {
    $knowledge_id = intval($_POST['knowledge_id'] ?? 0);
    if ($knowledge_id) {
        // Busca o arquivo para deletar do disco também
        $stmt = $pdo->prepare(
            "SELECT kb.file_name FROM knowledge_base kb 
             JOIN agents a ON kb.agent_id = a.id 
             WHERE kb.id = ? AND a.user_id = ?"
        );
        $stmt->execute([$knowledge_id, $user_id]);
        $kb_entry = $stmt->fetch();

        if ($kb_entry) {
            $file_path = __DIR__ . '/../uploads/knowledge/' . $kb_entry['file_name'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ?");
            $stmt->execute([$knowledge_id]);
        }
    }
    header("Location: ../conhecimento?agent_id={$agent_id}&success=deleted");
    exit;
}

// === AÇÃO: SALVAR TEXTO DIRETO ===
if ($action === 'save_text' && $agent_id) {
    $text_content = trim($_POST['text_content'] ?? '');
    $text_label   = trim($_POST['text_label'] ?? 'Texto Manual — ' . date('d/m/Y H:i'));
    if ($text_content) {
        $upload_dir = __DIR__ . '/../uploads/knowledge/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        // Nome seguro + conteúdo limitado (evita abuso e arquivos gigantes)
        $file_name = 'text_' . time() . '_' . $agent_id . '_' . bin2hex(random_bytes(4)) . '.txt';
        $file_path = $upload_dir . $file_name;
        $safe_content = mb_substr($text_content, 0, 200000); // 200k chars
        file_put_contents($file_path, $safe_content);

        // Label limpo para evitar lixo no UI
        $text_label = mb_substr(preg_replace('/[\\r\\n\\t]+/', ' ', $text_label), 0, 180);
        $stmt = $pdo->prepare("INSERT INTO knowledge_base (agent_id, file_name, original_name, file_type, content, status) VALUES (?, ?, ?, 'text', ?, 'trained')");
        $stmt->execute([$agent_id, $file_name, $text_label, $safe_content]);

        header("Location: ../conhecimento?agent_id={$agent_id}&success=text");
        exit;
    }
}

// === AÇÃO: UPLOAD DE ARQUIVO ===
if ($action === 'upload_file' && $agent_id && isset($_FILES['knowledge_file'])) {
    $file = $_FILES['knowledge_file'];

    // Tipos permitidos MIME reais
    $allowed_mimes = [
        'application/pdf', 
        'text/plain', 
        'text/csv', 
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    $allowed_exts  = ['pdf', 'txt', 'csv', 'docx'];
    $max_size      = 50 * 1024 * 1024; // 50MB

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        header("Location: ../conhecimento?agent_id={$agent_id}&error=upload_error");
        exit;
    }
    if ($file['size'] > $max_size) {
        header("Location: ../conhecimento?agent_id={$agent_id}&error=too_large");
        exit;
    }
    
    // Validação de extensão
    if (!in_array($ext, $allowed_exts)) {
        header("Location: ../conhecimento?agent_id={$agent_id}&error=invalid_type");
        exit;
    }

    // Validação real de MIME Type (Magic Bytes) para evitar bypass com renomeação
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes)) {
        // Fallback para CSV que as vezes é lido como text/plain pelo finfo
        if ($ext !== 'csv' || !in_array($mime, ['text/plain', 'text/csv'])) {
            header("Location: ../conhecimento?agent_id={$agent_id}&error=invalid_type");
            exit;
        }
    }

    // Nome seguro para o arquivo salvo
    $safe_name     = uniqid('kb_', true) . '.' . $ext;
    $original_name = $file['name'];
    $dest_path     = __DIR__ . '/../uploads/knowledge/' . $safe_name;

    if (move_uploaded_file($file['tmp_name'], $dest_path)) {
        // Extrai conteúdo do arquivo
        $content = MasterPromptBuilder::extractContent($dest_path, $ext);

        $stmt = $pdo->prepare("INSERT INTO knowledge_base (agent_id, file_name, original_name, file_type, content, status) VALUES (?, ?, ?, ?, ?, 'trained')");
        $stmt->execute([$agent_id, $safe_name, $original_name, $ext, $content ?: null]);

        header("Location: ../conhecimento?agent_id={$agent_id}&success=uploaded&original_name=" . urlencode($original_name));
        exit;
    } else {
        header("Location: ../conhecimento?agent_id={$agent_id}&error=move_failed");
        exit;
    }
}

// Fallback
header("Location: ../conhecimento");
exit;
?>
