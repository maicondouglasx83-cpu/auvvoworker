<?php
/**
 * backend/agentes-handlers.php
 * Handlers POST do agentes.php (create, update, delete, blueprints).
 * Incluido por agentes.php apos verificacao CSRF.
 */

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ============================================================
    // BLUEPRINTS (modelos reutilizaveis)
    // ============================================================
    if ($action === 'save_blueprint') {
        $agent_id = intval($_POST['agent_id'] ?? 0);
        $bp_name  = trim($_POST['blueprint_name'] ?? '');
        if (!$agent_id || $bp_name === '') {
            header("Location: agentes?error=blueprint_invalid"); exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $stmt->execute([$agent_id, $user_id]);
        $ag = $stmt->fetch();
        if (!$ag) { header("Location: agentes?error=blueprint_invalid"); exit; }
        try {
            $pdo->prepare(
                "INSERT INTO blueprints (user_id, name, agent_type, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $user_id, $bp_name, $ag['agent_type'], $ag['role'], $ag['prompt_base'],
                $ag['type_config'], $ag['model'], $ag['temperature'], $ag['max_tokens'],
                $ag['response_delay'], $ag['audio_enabled'], $ag['audio_voice'],
                $ag['handoff_rules'], $ag['handoff_enabled'], $ag['handoff_message'],
            ]);
        } catch (PDOException $e) {
            header("Location: agentes?error=blueprint_table_missing"); exit;
        }
        header("Location: agentes?success=blueprint_saved"); exit;
    }

    if ($action === 'delete_blueprint') {
        $bp_id = intval($_POST['blueprint_id'] ?? 0);
        if ($bp_id) {
            try {
                $pdo->prepare("DELETE FROM blueprints WHERE id=? AND user_id=?")->execute([$bp_id, $user_id]);
            } catch (PDOException $e) {}
        }
        header("Location: agentes?success=blueprint_deleted"); exit;
    }

    if ($action === 'create_from_blueprint') {
        $bp_id = intval($_POST['blueprint_id'] ?? 0);
        $new_name = trim($_POST['new_agent_name'] ?? 'Novo Agente (Blueprint)');
        if (!$bp_id || $new_name === '') {
            header("Location: agentes?error=blueprint_invalid"); exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM blueprints WHERE id=? AND user_id=?");
            $stmt->execute([$bp_id, $user_id]);
            $bp = $stmt->fetch();
        } catch (PDOException $e) { $bp = null; }
        if (!$bp) { header("Location: agentes?error=blueprint_invalid"); exit; }

        $stmt = $pdo->prepare(
            "INSERT INTO agents (user_id, agent_type, name, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')"
        );
        $stmt->execute([
            $user_id, $bp['agent_type'], $new_name, $bp['role'], $bp['prompt_base'],
            $bp['type_config'], $bp['model'], $bp['temperature'], $bp['max_tokens'],
            $bp['response_delay'], $bp['audio_enabled'], $bp['audio_voice'],
            $bp['handoff_rules'], $bp['handoff_enabled'], $bp['handoff_message'],
        ]);
        $new_id = $pdo->lastInsertId();
        header("Location: agentes?edit=" . $new_id . "&success=created_from_blueprint"); exit;
    }

    // ============================================================
    // AGENT CRUD
    // ============================================================
    $name = trim($_POST['name'] ?? ''); $role = trim($_POST['role'] ?? 'Vendedor');
    $agent_type = $_POST['agent_type'] ?? 'vendedor';
    $prompt_base = trim($_POST['prompt_base'] ?? '');
    $type_config = isset($_POST['type_config']) ? json_encode($_POST['type_config'], JSON_UNESCAPED_UNICODE) : null;

    $prevAgent = null;
    if ($action === 'update_agent' && !empty($_POST['agent_id'])) {
        $aid = intval($_POST['agent_id']);
        $st = $pdo->prepare("SELECT * FROM agents WHERE id=? AND user_id=?");
        $st->execute([$aid, $user_id]);
        $prevAgent = $st->fetch() ?: null;
    }

    $model = trim((string)($_POST['model'] ?? ''));
    if ($model === '' && $prevAgent) { $model = (string)($prevAgent['model'] ?? ''); }
    if ($model === '') { $model = 'gpt-4o'; }

    $temperature = isset($_POST['temperature']) ? floatval($_POST['temperature']) : ($prevAgent ? floatval($prevAgent['temperature']) : 0.7);
    $max_tokens = isset($_POST['max_tokens']) ? intval($_POST['max_tokens']) : ($prevAgent ? intval($prevAgent['max_tokens']) : 1000);
    $response_delay = isset($_POST['response_delay']) ? intval($_POST['response_delay']) : ($prevAgent ? intval($prevAgent['response_delay']) : 2);

    $audio_enabled = isset($_POST['audio_enabled']) ? 1 : ($prevAgent ? (int)$prevAgent['audio_enabled'] : 0);
    $audio_voice = trim((string)($_POST['audio_voice'] ?? ''));
    if ($audio_voice === '' && $prevAgent) { $audio_voice = (string)($prevAgent['audio_voice'] ?? ''); }

    $handoff_rules = trim((string)($_POST['handoff_rules'] ?? ''));
    if ($handoff_rules === '' && $prevAgent) { $handoff_rules = (string)($prevAgent['handoff_rules'] ?? ''); }
    if ($handoff_rules === '') { $handoff_rules = 'humano, atendente, suporte'; }

    $handoff_enabled = isset($_POST['handoff_enabled']) ? 1 : ($prevAgent ? (int)$prevAgent['handoff_enabled'] : 1);
    $handoff_message = trim((string)($_POST['handoff_message'] ?? ''));
    if ($handoff_message === '' && $prevAgent && array_key_exists('handoff_message', $prevAgent) && $prevAgent['handoff_message'] !== null) {
        $handoff_message = (string)$prevAgent['handoff_message'];
    }
    $allowed_langs = ['pt-BR','en','es','pt','fr','de','it','ja','zh'];
    $bot_language = in_array($_POST['bot_language'] ?? '', $allowed_langs) ? $_POST['bot_language'] : 'pt-BR';
    $flow_mode = ($_POST['flow_mode'] ?? 'easy') === 'advanced' ? 'advanced' : 'easy';
    $flow_steps = json_decode((string) ($_POST['flow_steps_json'] ?? '[]'), true);
    if (!is_array($flow_steps)) { $flow_steps = []; }
    $flow_config = json_encode([
        'partner_agent_id' => (int) ($_POST['flow_partner_agent_id'] ?? 0),
        'steps'            => $flow_steps,
    ], JSON_UNESCAPED_UNICODE);

    if ($action == 'create_agent') {
        if (!auvvo_agent_name_is_valid($name)) {
            header('Location: agentes?edit=new&error=name_required'); exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO agents (user_id, agent_type, name, role, prompt_base, type_config, model, temperature, max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled, handoff_message, bot_language, flow_mode, flow_config, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'offline')");
            $stmt->execute([$user_id,$agent_type,$name,$role,$prompt_base,$type_config,$model,$temperature,$max_tokens,$response_delay,$audio_enabled,$audio_voice,$handoff_rules,$handoff_enabled,$handoff_message,$bot_language,$flow_mode,$flow_config]);
            $new_id = (int) $pdo->lastInsertId();
            header('Location: agentes?edit=' . $new_id . '&success=created'); exit;
        } catch (PDOException $e) {
            error_log('[Auvvo] create_agent: ' . $e->getMessage());
            header('Location: agentes?error=save_failed&detail=' . urlencode($e->getMessage())); exit;
        }
    }

    if ($action == 'update_agent') {
        $agent_id = intval($_POST['agent_id'] ?? 0);
        if ($agent_id <= 0) { header('Location: agentes?error=save_failed'); exit; }
        if (!$prevAgent) { header('Location: agentes?error=save_failed'); exit; }
        if (!auvvo_agent_name_is_valid($name)) {
            header("Location: agentes?edit={$agent_id}&error=name_required"); exit;
        }
        $wasDraft = ($prevAgent['status'] ?? '') === 'draft';
        $newStatus = $wasDraft ? 'offline' : (string) ($prevAgent['status'] ?? 'offline');
        try {
            $stmt = $pdo->prepare("UPDATE agents SET agent_type=?,name=?,role=?,prompt_base=?,type_config=?,model=?,temperature=?,max_tokens=?,response_delay=?,audio_enabled=?,audio_voice=?,handoff_rules=?,handoff_enabled=?,handoff_message=?,bot_language=?,flow_mode=?,flow_config=?,status=? WHERE id=? AND user_id=?");
            $stmt->execute([$agent_type,$name,$role,$prompt_base,$type_config,$model,$temperature,$max_tokens,$response_delay,$audio_enabled,$audio_voice,$handoff_rules,$handoff_enabled,$handoff_message,$bot_language,$flow_mode,$flow_config,$newStatus,$agent_id,$user_id]);
        } catch (PDOException $e) {
            error_log('[Auvvo] update_agent: ' . $e->getMessage());
            header("Location: agentes?edit={$agent_id}&error=save_failed&detail=" . urlencode($e->getMessage())); exit;
        }
        $redirect_tab = max(0, intval($_POST['redirect_tab'] ?? 0));
        if ($wasDraft) {
            header("Location: agentes?edit={$agent_id}&success=created&tab={$redirect_tab}"); exit;
        }
        header("Location: agentes?edit={$agent_id}&tab={$redirect_tab}&success=updated"); exit;
    }

    if ($action == 'delete_agent') {
        $agent_id = intval($_POST['agent_id'] ?? 0);
        // Buscar e deletar arquivos fisicos de conhecimento antes de remover do banco
        $stmt_kb = $pdo->prepare("SELECT file_name, file_type FROM knowledge_base WHERE agent_id = ?");
        $stmt_kb->execute([$agent_id]);
        foreach ($stmt_kb->fetchAll() as $kb_file) {
            if ($kb_file['file_type'] !== 'text') {
                $path = __DIR__ . '/../uploads/knowledge/' . $kb_file['file_name'];
                if (file_exists($path)) unlink($path);
            }
        }
        $pdo->prepare("DELETE FROM agents WHERE id=? AND user_id=?")->execute([$agent_id, $user_id]);
        header("Location: agentes?success=deleted"); exit;
    }
}
