<?php
/**
 * Migrações idempotentes — ver docs/SCHEMA-EVOLUCAO.md
 */
declare(strict_types=1);

/** Incrementar ao adicionar migração que altera schema em produção. */
const AUVVO_SCHEMA_VERSION = 49;

function auvvo_run_migrations(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Reparo idempotente — executa mesmo se schema_version já estiver marcado como atual
    auvvo_migration_agents_columns_repair($pdo);
    auvvo_migration_whatsapp_connections_repair($pdo);
    auvvo_migration_connection_id_columns_repair($pdo);
    require_once __DIR__ . '/crm_flow_migrate.inc.php';
    auvvo_migration_flow_graphs_modernize_repair($pdo);
    auvvo_migration_webhook_event_log_repair($pdo);

    if (auvvo_migrations_schema_up_to_date($pdo)) {
        return;
    }

    auvvo_migration_ai_jobs($pdo);
    auvvo_migration_rate_buckets($pdo);
    auvvo_migration_conversation_summaries($pdo);
    auvvo_migration_contacts_extras($pdo);
    auvvo_migration_campaign_queue($pdo);
    auvvo_migration_conversation_events($pdo);
    auvvo_migration_inbound_webhooks($pdo);
    auvvo_migration_crm_pipelines($pdo);
    auvvo_migration_crm_automations($pdo);
    auvvo_migration_agents_bot_language($pdo);
    auvvo_migration_agents_flow($pdo);
    auvvo_migration_webhooks_v2($pdo);
    auvvo_migration_crm_automations_v2($pdo);
    auvvo_migration_integrations_hub($pdo);
    auvvo_migration_webhooks_v3($pdo);
    auvvo_migration_crm_automation_queue($pdo);
    auvvo_migration_ltv($pdo);
    auvvo_migration_automation_flows($pdo);
    auvvo_migration_automation_flows_pipeline($pdo);
    auvvo_migration_automation_dedupe($pdo);
    auvvo_migration_brain_action_log($pdo);
    auvvo_migration_conversation_logs_indexes($pdo);
    auvvo_migration_agents_updated_at($pdo);
    auvvo_migration_knowledge_original_name($pdo);
    auvvo_migration_contacts_phone_cleanup($pdo);
    auvvo_migration_whatsapp_connections($pdo);
    auvvo_migration_connection_id_columns($pdo);
    auvvo_migration_automation_runs($pdo);
    auvvo_migration_automation_wait_states($pdo);
    auvvo_migration_login_attempts($pdo);
    auvvo_migration_password_resets($pdo);
    auvvo_migration_webhook_event_log($pdo);
    auvvo_migration_checkout_pending($pdo);
    auvvo_migration_foreign_keys($pdo);

    auvvo_migrations_mark_schema_current($pdo);
}

function auvvo_migration_contacts_phone_cleanup(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE contacts SET phone = NULL WHERE phone LIKE '%@%' OR LENGTH(REPLACE(phone, '+', '')) > 15");
    } catch (PDOException $e) {
        error_log('[Auvvo] migration contacts phone cleanup: ' . $e->getMessage());
    }
}

function auvvo_migration_knowledge_original_name(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'knowledge_base', 'original_name')) {
        try {
            $pdo->exec('ALTER TABLE knowledge_base ADD COLUMN original_name VARCHAR(255) NULL DEFAULT NULL AFTER file_name');
        } catch (PDOException $e) {
            error_log('[Auvvo] migration knowledge_base.original_name: ' . $e->getMessage());
        }
    }
}

function auvvo_migration_agents_updated_at(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'agents', 'updated_at')) {
        try {
            $pdo->exec('ALTER TABLE agents ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        } catch (PDOException $e) {
            error_log('[Auvvo] migration agents.updated_at: ' . $e->getMessage());
        }
    }
}

function auvvo_migration_conversation_logs_indexes(PDO $pdo): void
{
    // Índices para queries do dashboard e webhook — evita full scan em conversation_logs
    $indexes = [
        'idx_cl_agent_type'        => 'ALTER TABLE conversation_logs ADD INDEX idx_cl_agent_type (agent_id, type)',
        'idx_cl_agent_jid'         => 'ALTER TABLE conversation_logs ADD INDEX idx_cl_agent_jid (agent_id, contact_jid)',
        'idx_cl_agent_created'     => 'ALTER TABLE conversation_logs ADD INDEX idx_cl_agent_created (agent_id, created_at)',
        'idx_cl_agent_jid_type'    => 'ALTER TABLE conversation_logs ADD INDEX idx_cl_agent_jid_type (agent_id, contact_jid, type)',
    ];
    foreach ($indexes as $name => $ddl) {
        try {
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_logs' AND INDEX_NAME = ?"
            );
            $chk->execute([$name]);
            if ((int) $chk->fetchColumn() === 0) {
                $pdo->exec($ddl);
            }
        } catch (PDOException $e) {
            error_log('[Auvvo] conversation_logs index: ' . $e->getMessage());
        }
    }
}

function auvvo_migration_brain_action_log(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS brain_action_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NULL,
            contact_jid VARCHAR(128) NULL,
            agent_id INT UNSIGNED NULL,
            tools_json JSON NOT NULL,
            executed_json JSON NOT NULL,
            warnings_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_contact (user_id, contact_id),
            KEY idx_user_jid (user_id, contact_jid),
            KEY idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_automation_dedupe(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_dedupe (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NOT NULL,
            dedupe_key VARCHAR(96) NOT NULL,
            fired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_contact_key (user_id, contact_id, dedupe_key),
            KEY idx_contact (contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_automation_runs(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            flow_id INT UNSIGNED NULL,
            contact_id INT UNSIGNED NULL,
            mode ENUM('simulate','live') NOT NULL DEFAULT 'live',
            trigger_type VARCHAR(64) NOT NULL DEFAULT '',
            trigger_value VARCHAR(128) NOT NULL DEFAULT '',
            message_preview VARCHAR(500) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'running',
            error_message VARCHAR(500) NULL,
            meta_json JSON NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_flow (user_id, flow_id),
            KEY idx_user_mode (user_id, mode),
            KEY idx_user_started (user_id, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_run_steps (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_id BIGINT UNSIGNED NOT NULL,
            step_order INT UNSIGNED NOT NULL DEFAULT 0,
            node_id VARCHAR(32) NOT NULL DEFAULT '',
            node_class VARCHAR(64) NOT NULL DEFAULT '',
            node_label VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'ok',
            detail TEXT NULL,
            branch VARCHAR(32) NULL,
            payload_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_run_order (run_id, step_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_automation_wait_states(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_wait_states (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            flow_id INT UNSIGNED NOT NULL,
            run_id BIGINT UNSIGNED NULL,
            contact_id INT UNSIGNED NOT NULL,
            node_id VARCHAR(32) NOT NULL DEFAULT '',
            mode ENUM('simulate','live') NOT NULL DEFAULT 'live',
            keyword_filter VARCHAR(255) NULL,
            reply_node_ids JSON NULL,
            timeout_node_ids JSON NULL,
            timeout_at TIMESTAMP NULL DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'waiting',
            meta_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_contact_status (user_id, contact_id, status),
            KEY idx_timeout (status, timeout_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migrations_schema_up_to_date(PDO $pdo): bool
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auvvo_app_meta (
                meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
                meta_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $stmt = $pdo->prepare("SELECT meta_value FROM auvvo_app_meta WHERE meta_key = 'schema_version' LIMIT 1");
        $stmt->execute();

        return (int) $stmt->fetchColumn() === AUVVO_SCHEMA_VERSION;
    } catch (PDOException $e) {
        return false;
    }
}

function auvvo_migrations_mark_schema_current(PDO $pdo): void
{
    try {
        $pdo->prepare(
            "INSERT INTO auvvo_app_meta (meta_key, meta_value) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        )->execute([(string) AUVVO_SCHEMA_VERSION]);
    } catch (PDOException $e) {
    }
}

function auvvo_migration_automation_flows(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_flows (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL DEFAULT 'Nova automação',
            description VARCHAR(500) NULL,
            flow_data LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            stats_entered INT UNSIGNED NOT NULL DEFAULT 0,
            stats_success INT UNSIGNED NOT NULL DEFAULT 0,
            stats_error INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_active (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_automation_flows_pipeline(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'crm_automation_flows', 'pipeline_id')) {
        auvvo_migration_add_column($pdo, 'crm_automation_flows', 'pipeline_id INT UNSIGNED NULL AFTER user_id');
        try {
            $pdo->exec(
                'ALTER TABLE crm_automation_flows ADD KEY idx_user_pipeline (user_id, pipeline_id, is_active)'
            );
        } catch (PDOException $e) {
        }
    }

    try {
        $pdo->exec(
            'UPDATE crm_automation_flows f
             INNER JOIN crm_pipelines p ON p.user_id = f.user_id AND p.is_default = 1
             SET f.pipeline_id = p.id
             WHERE f.pipeline_id IS NULL OR f.pipeline_id = 0'
        );
    } catch (PDOException $e) {
    }
}

function auvvo_migration_ltv(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'last_purchase_at')) {
        auvvo_migration_add_column($pdo, 'contacts', 'last_purchase_at DATETIME NULL');
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'purchase_count')) {
        auvvo_migration_add_column($pdo, 'contacts', 'purchase_count INT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'avg_purchase_cycle_days')) {
        auvvo_migration_add_column($pdo, 'contacts', 'avg_purchase_cycle_days INT UNSIGNED NULL');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contact_purchases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NOT NULL,
            purchased_at DATETIME NOT NULL,
            amount DECIMAL(12,2) NULL,
            product_name VARCHAR(255) NULL,
            source VARCHAR(64) NULL,
            external_id VARCHAR(128) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contact (contact_id, purchased_at),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_ltv_fired (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NOT NULL,
            automation_id INT UNSIGNED NOT NULL,
            fired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dedupe (automation_id, contact_id, fired_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!auvvo_migration_column_exists($pdo, 'crm_ltv_fired', 'source_type')) {
        auvvo_migration_add_column($pdo, 'crm_ltv_fired', "source_type VARCHAR(10) NOT NULL DEFAULT 'rule' COMMENT 'rule|flow'");
    }

    auvvo_migration_webhooks_v3($pdo);
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'counts_as_purchase')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'counts_as_purchase TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function auvvo_migration_crm_automation_queue(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automation_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            automation_id INT UNSIGNED NULL,
            contact_id INT UNSIGNED NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            trigger_value VARCHAR(64) NOT NULL,
            step_index INT NOT NULL DEFAULT 0,
            action_type VARCHAR(32) NOT NULL,
            action_config JSON NOT NULL,
            run_at DATETIME NOT NULL,
            status ENUM('pending','processing','done','failed','cancelled') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(512) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pending_run (status, run_at),
            KEY idx_user (user_id),
            KEY idx_contact (contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_webhooks_v3(PDO $pdo): void
{
    auvvo_migration_webhooks_v2($pdo);
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'entry_pipeline_id')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'entry_pipeline_id INT UNSIGNED NULL AFTER default_agent_id');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'entry_stage')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'entry_stage VARCHAR(64) NULL AFTER entry_pipeline_id');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'entry_tags')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'entry_tags JSON NULL AFTER entry_stage');
    }
}

function auvvo_migration_add_column(PDO $pdo, string $table, string $ddl): void
{
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    } catch (PDOException $e) {
        error_log("[Auvvo] migration add column {$table}.{$ddl}: " . $e->getMessage());
    }
}

/** Garante colunas de agente usadas no save — corrige instalações com schema_version adiantado. */
function auvvo_migration_agents_columns_repair(PDO $pdo): void
{
    $cols = [
        'bot_language' => "bot_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR'",
        'flow_mode'    => "flow_mode VARCHAR(16) NOT NULL DEFAULT 'easy'",
        'flow_config'  => 'flow_config JSON NULL',
    ];
    foreach ($cols as $name => $ddl) {
        if (!auvvo_migration_column_exists($pdo, 'agents', $name)) {
            auvvo_migration_add_column($pdo, 'agents', $ddl);
        }
    }
}

function auvvo_migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function auvvo_migration_ai_jobs(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auvvo_ai_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT UNSIGNED NOT NULL,
            pending_log_id INT UNSIGNED NULL,
            canonical_jid VARCHAR(255) NOT NULL,
            remote_jid VARCHAR(255) NOT NULL,
            peer_digits VARCHAR(32) NOT NULL DEFAULT '',
            body MEDIUMTEXT NOT NULL,
            evolution_instance_label VARCHAR(255) NOT NULL DEFAULT '',
            lock_peer VARCHAR(64) NOT NULL DEFAULT '',
            dedupe_key VARCHAR(128) NOT NULL,
            trace_id VARCHAR(32) NOT NULL DEFAULT '',
            status ENUM('debouncing','pending','processing','done','failed') NOT NULL DEFAULT 'debouncing',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            flush_at DATETIME NULL,
            next_retry_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_agent_dedupe (agent_id, dedupe_key),
            KEY idx_worker_pick (status, flush_at, id),
            KEY idx_agent_peer (agent_id, lock_peer, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!auvvo_migration_column_exists($pdo, 'auvvo_ai_jobs', 'flush_at')) {
        try {
            $pdo->exec("ALTER TABLE auvvo_ai_jobs ADD COLUMN flush_at DATETIME NULL AFTER last_error");
        } catch (PDOException $e) {
        }
    }
    if (!auvvo_migration_column_exists($pdo, 'auvvo_ai_jobs', 'next_retry_at')) {
        try {
            $pdo->exec("ALTER TABLE auvvo_ai_jobs ADD COLUMN next_retry_at DATETIME NULL AFTER flush_at");
        } catch (PDOException $e) {
        }
    }
    try {
        $pdo->exec("ALTER TABLE auvvo_ai_jobs MODIFY COLUMN status ENUM('debouncing','pending','processing','done','failed') NOT NULL DEFAULT 'debouncing'");
    } catch (PDOException $e) {
    }
}

function auvvo_migration_rate_buckets(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auvvo_rate_buckets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket_key VARCHAR(128) NOT NULL,
            window_start DATETIME NOT NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bucket_window (bucket_key, window_start),
            KEY idx_cleanup (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_conversation_summaries(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversation_summaries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT UNSIGNED NOT NULL,
            contact_jid VARCHAR(255) NOT NULL,
            summary_text MEDIUMTEXT NOT NULL,
            turn_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_summarized_log_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_agent_contact (agent_id, contact_jid),
            KEY idx_agent (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_contacts_extras(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'memory_json')) {
        try {
            $pdo->exec("ALTER TABLE contacts ADD COLUMN memory_json JSON NULL COMMENT 'Fatos IA' AFTER custom_fields");
        } catch (PDOException $e) {
        }
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'loss_reason')) {
        try {
            $pdo->exec("ALTER TABLE contacts ADD COLUMN loss_reason VARCHAR(255) NULL AFTER stage");
        } catch (PDOException $e) {
        }
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'lost_at')) {
        try {
            $pdo->exec("ALTER TABLE contacts ADD COLUMN lost_at DATETIME NULL AFTER loss_reason");
        } catch (PDOException $e) {
        }
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'pipeline_id')) {
        try {
            $pdo->exec('ALTER TABLE contacts ADD COLUMN pipeline_id INT UNSIGNED NULL AFTER user_id');
        } catch (PDOException $e) {
        }
    }
    if (!auvvo_migration_column_exists($pdo, 'contacts', 'stage_id')) {
        try {
            $pdo->exec('ALTER TABLE contacts ADD COLUMN stage_id INT UNSIGNED NULL AFTER pipeline_id');
        } catch (PDOException $e) {
        }
    }
}

function auvvo_migration_campaign_queue(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_send_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            phone VARCHAR(32) NOT NULL,
            name VARCHAR(255) NULL,
            message_rendered TEXT NOT NULL,
            status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_campaign_status (campaign_id, status),
            KEY idx_scheduled (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_conversation_events(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversation_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            agent_id INT UNSIGNED NOT NULL,
            contact_jid VARCHAR(255) NOT NULL,
            event_type ENUM('message_in','message_out','handoff','ia_paused','ia_resumed') NOT NULL,
            payload JSON NULL,
            created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            KEY idx_user_created (user_id, id),
            KEY idx_contact (agent_id, contact_jid, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_inbound_webhooks(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inbound_webhooks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            secret_token VARCHAR(64) NOT NULL,
            url_slug VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_slug (url_slug),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inbound_webhook_field_maps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            webhook_id INT UNSIGNED NOT NULL,
            json_path VARCHAR(255) NOT NULL,
            crm_field VARCHAR(64) NOT NULL,
            KEY idx_webhook (webhook_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inbound_webhook_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            webhook_id INT UNSIGNED NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status ENUM('ok','ignored','error') NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dedupe (webhook_id, payload_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_crm_pipelines(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_pipelines (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_pipeline_stages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pipeline_id INT UNSIGNED NOT NULL,
            slug VARCHAR(64) NOT NULL,
            label VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_won TINYINT(1) NOT NULL DEFAULT 0,
            is_lost TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_pipeline_slug (pipeline_id, slug),
            KEY idx_pipeline (pipeline_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function auvvo_migration_crm_automations(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_automations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            pipeline_id INT UNSIGNED NULL,
            trigger_type ENUM('stage_enter','stage_leave','tag_added') NOT NULL,
            trigger_value VARCHAR(64) NOT NULL,
            action_type ENUM('send_whatsapp','add_tag','pause_ai','assign_owner') NOT NULL,
            action_config JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Garante pipeline padrão "Vendas" para o usuário.
 */
function auvvo_ensure_default_pipeline(PDO $pdo, int $userId): int
{
    auvvo_run_migrations($pdo);
    $stmt = $pdo->prepare('SELECT id FROM crm_pipelines WHERE user_id = ? AND is_default = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $pdo->prepare('INSERT INTO crm_pipelines (user_id, name, is_default, sort_order) VALUES (?, ?, 1, 0)')
        ->execute([$userId, 'Vendas']);
    $pipelineId = (int) $pdo->lastInsertId();

    $stages = [
        ['new', 'Novo Lead', '#6366F1', 0, 0, 0],
        ['contacted', 'Em Contato', '#8B5CF6', 1, 0, 0],
        ['qualified', 'Qualificado', '#F59E0B', 2, 0, 0],
        ['proposal', 'Proposta', '#F97316', 3, 0, 0],
        ['closed', 'Fechado', '#10B981', 4, 1, 0],
        ['lost', 'Perdido', '#EF4444', 5, 0, 1],
    ];
    $hasColor = auvvo_migration_column_exists($pdo, 'crm_pipeline_stages', 'color');
    if ($hasColor) {
        $ins = $pdo->prepare(
            'INSERT INTO crm_pipeline_stages (pipeline_id, slug, label, color, sort_order, is_won, is_lost) VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($stages as $s) {
            $ins->execute([$pipelineId, $s[0], $s[1], $s[2], $s[3], $s[4], $s[5]]);
        }
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO crm_pipeline_stages (pipeline_id, slug, label, sort_order, is_won, is_lost) VALUES (?,?,?,?,?,?)'
        );
        foreach ($stages as $s) {
            $ins->execute([$pipelineId, $s[0], $s[1], $s[3], $s[4], $s[5]]);
        }
    }

    return $pipelineId;
}

function auvvo_migration_agents_bot_language(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'agents', 'bot_language')) {
        auvvo_migration_add_column($pdo, 'agents', "bot_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER handoff_message");
    }
}

function auvvo_migration_agents_flow(PDO $pdo): void
{
    if (!auvvo_migration_column_exists($pdo, 'agents', 'flow_mode')) {
        auvvo_migration_add_column($pdo, 'agents', "flow_mode VARCHAR(16) NOT NULL DEFAULT 'easy' AFTER bot_language");
    }
    if (!auvvo_migration_column_exists($pdo, 'agents', 'flow_config')) {
        auvvo_migration_add_column($pdo, 'agents', 'flow_config JSON NULL AFTER flow_mode');
    }
}

function auvvo_migration_webhooks_v2(PDO $pdo): void
{
    auvvo_migration_inbound_webhooks($pdo);

    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'response_template')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'response_template TEXT NULL AFTER url_slug');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'default_agent_id')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'default_agent_id INT UNSIGNED NULL AFTER response_template');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'variable_maps')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'variable_maps JSON NULL AFTER default_agent_id');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'phone_country_prefix')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', "phone_country_prefix VARCHAR(4) NOT NULL DEFAULT '55' AFTER variable_maps");
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhooks', 'sample_payload')) {
        auvvo_migration_add_column($pdo, 'inbound_webhooks', 'sample_payload MEDIUMTEXT NULL AFTER phone_country_prefix');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS outbound_webhooks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            target_url VARCHAR(512) NOT NULL,
            http_method VARCHAR(8) NOT NULL DEFAULT 'POST',
            headers_json JSON NULL,
            body_template TEXT NULL,
            response_var_maps JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_call_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            webhook_kind ENUM('inbound','outbound') NOT NULL,
            webhook_id INT UNSIGNED NOT NULL,
            http_status SMALLINT NULL,
            request_json MEDIUMTEXT NULL,
            response_json MEDIUMTEXT NULL,
            status ENUM('ok','error','ignored') NOT NULL DEFAULT 'ok',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_created (user_id, id),
            KEY idx_webhook (webhook_kind, webhook_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_stored_variables (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            webhook_kind ENUM('inbound','outbound') NOT NULL,
            webhook_id INT UNSIGNED NOT NULL,
            var_key VARCHAR(64) NOT NULL,
            var_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wh_var (webhook_kind, webhook_id, var_key),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!auvvo_migration_column_exists($pdo, 'inbound_webhook_log', 'payload_json')) {
        auvvo_migration_add_column($pdo, 'inbound_webhook_log', 'payload_json MEDIUMTEXT NULL AFTER payload_hash');
    }
    if (!auvvo_migration_column_exists($pdo, 'inbound_webhook_log', 'response_json')) {
        auvvo_migration_add_column($pdo, 'inbound_webhook_log', 'response_json MEDIUMTEXT NULL AFTER payload_json');
    }
}

function auvvo_migration_crm_automations_v2(PDO $pdo): void
{
    auvvo_migration_crm_automations($pdo);
    try {
        $pdo->exec("ALTER TABLE crm_automations MODIFY trigger_type VARCHAR(32) NOT NULL");
        $pdo->exec("ALTER TABLE crm_automations MODIFY action_type VARCHAR(32) NOT NULL");
    } catch (PDOException $e) {
    }
}

function auvvo_migration_integrations_hub(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS google_sheets_tokens (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            access_token TEXT NOT NULL,
            refresh_token TEXT NULL,
            token_type VARCHAR(32) NOT NULL DEFAULT 'Bearer',
            scope TEXT NULL,
            expires_at DATETIME NULL,
            spreadsheet_id VARCHAR(128) NULL,
            sheet_name VARCHAR(128) NOT NULL DEFAULT 'Leads',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_api_keys (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            api_key_prefix VARCHAR(16) NOT NULL,
            api_key_hash CHAR(64) NOT NULL,
            permissions JSON NOT NULL,
            last_used_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hash (api_key_hash),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS integration_http_presets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            target_url VARCHAR(512) NOT NULL,
            http_method VARCHAR(8) NOT NULL DEFAULT 'POST',
            headers_json JSON NULL,
            body_template TEXT NULL,
            provider_slug VARCHAR(64) NOT NULL DEFAULT 'custom',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!auvvo_migration_column_exists($pdo, 'settings', 'google_sheets_enabled')) {
        auvvo_migration_add_column($pdo, 'settings', 'google_sheets_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
}

function auvvo_migration_whatsapp_connections(PDO $pdo): void
{
    auvvo_migration_whatsapp_connections_repair($pdo);
}

function auvvo_migration_whatsapp_connections_repair(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                evolution_instance VARCHAR(255) NULL,
                evolution_token VARCHAR(255) NULL,
                status ENUM('waiting_qr','online','offline') NOT NULL DEFAULT 'offline',
                default_agent_id INT UNSIGNED NULL,
                phone_e164 VARCHAR(20) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user (user_id),
                KEY idx_user_status (user_id, status),
                UNIQUE KEY uq_user_token (user_id, evolution_token),
                UNIQUE KEY uq_user_instance (user_id, evolution_instance)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('[Auvvo] migration whatsapp_connections table: ' . $e->getMessage());
    }

    if (!auvvo_migration_column_exists($pdo, 'agents', 'whatsapp_connection_id')) {
        auvvo_migration_add_column($pdo, 'agents', 'whatsapp_connection_id INT UNSIGNED NULL');
    }

    if (!auvvo_migration_column_exists($pdo, 'whatsapp_connections', 'ai_mode')) {
        auvvo_migration_add_column(
            $pdo,
            'whatsapp_connections',
            "ai_mode ENUM('standalone','flows_first','flows_only') NOT NULL DEFAULT 'flows_first' AFTER default_agent_id"
        );
    }

    // Migrar linhas legadas (token ainda em agents) → conexões nomeadas
    try {
        $rows = $pdo->query(
            "SELECT id, user_id, name, evolution_instance, evolution_token, status
             FROM agents
             WHERE evolution_token IS NOT NULL AND evolution_token != ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $ag) {
            $token = (string) $ag['evolution_token'];
            $chk = $pdo->prepare('SELECT id FROM whatsapp_connections WHERE evolution_token = ? LIMIT 1');
            $chk->execute([$token]);
            if ($chk->fetchColumn()) {
                continue;
            }
            $connStatus = match ((string) ($ag['status'] ?? '')) {
                'online'     => 'online',
                'waiting_qr' => 'waiting_qr',
                default      => 'offline',
            };
            $connName = trim((string) ($ag['name'] ?? ''));
            if ($connName === '' || mb_strtolower($connName) === 'rascunho novo agente') {
                $connName = 'Linha ' . (int) $ag['id'];
            }
            $ins = $pdo->prepare(
                'INSERT INTO whatsapp_connections (user_id, name, evolution_instance, evolution_token, status, default_agent_id)
                 VALUES (?,?,?,?,?,?)'
            );
            $ins->execute([
                (int) $ag['user_id'],
                $connName,
                $ag['evolution_instance'],
                $token,
                $connStatus,
                (int) $ag['id'],
            ]);
            $connId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE agents SET whatsapp_connection_id = ? WHERE id = ?')
                ->execute([$connId, (int) $ag['id']]);
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] migration whatsapp_connections data: ' . $e->getMessage());
    }

    auvvo_migration_remap_whatsapp_triggers($pdo);
    auvvo_migration_whatsapp_global_unique_indexes($pdo);
}

function auvvo_migration_whatsapp_global_unique_indexes(PDO $pdo): void
{
    foreach (
        [
            ['uq_evolution_token_global', 'evolution_token'],
            ['uq_evolution_instance_global', 'evolution_instance'],
        ] as [$idx, $col]
    ) {
        try {
            $chk = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $chk->execute(['whatsapp_connections', $idx]);
            if ((int) $chk->fetchColumn() > 0) {
                continue;
            }
            $dup = $pdo->query(
                "SELECT {$col}, COUNT(*) AS c FROM whatsapp_connections
                 WHERE {$col} IS NOT NULL AND {$col} != ''
                 GROUP BY {$col} HAVING c > 1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                error_log('[Auvvo] migration skip UNIQUE ' . $idx . ': valor duplicado em ' . $col);

                continue;
            }
            $pdo->exec("ALTER TABLE whatsapp_connections ADD UNIQUE KEY {$idx} ({$col})");
        } catch (PDOException $e) {
            error_log('[Auvvo] migration whatsapp unique ' . $idx . ': ' . $e->getMessage());
        }
    }
}

function auvvo_migration_remap_whatsapp_triggers(PDO $pdo): void
{
    require_once __DIR__ . '/whatsapp_connections.inc.php';

    try {
        $rows = $pdo->query(
            "SELECT id, user_id, trigger_type, trigger_value FROM crm_automations
             WHERE trigger_type IN ('whatsapp_first','whatsapp_message')
             AND trigger_value REGEXP '^[0-9]+$'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $newVal = auvvo_whatsapp_remap_trigger_value(
                $pdo,
                (int) $row['user_id'],
                (string) $row['trigger_type'],
                (string) $row['trigger_value']
            );
            if ($newVal !== (string) $row['trigger_value']) {
                $pdo->prepare('UPDATE crm_automations SET trigger_value = ? WHERE id = ?')
                    ->execute([$newVal, (int) $row['id']]);
            }
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] migration remap crm_automations: ' . $e->getMessage());
    }

    try {
        $flows = $pdo->query('SELECT id, user_id, flow_data FROM crm_automation_flows WHERE flow_data IS NOT NULL AND flow_data != \'\'')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($flows as $flow) {
            $data = json_decode((string) $flow['flow_data'], true);
            if (!is_array($data)) {
                continue;
            }
            $home = &$data['drawflow']['Home']['data'];
            if (!is_array($home ?? null)) {
                $home = &$data['Home']['data'];
            }
            if (!is_array($home ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($home as &$node) {
                if (($node['class'] ?? '') !== 'flow_trigger') {
                    continue;
                }
                $nd = &$node['data'];
                $tt = (string) ($nd['trigger_type'] ?? '');
                if (!in_array($tt, ['whatsapp_first', 'whatsapp_message'], true)) {
                    continue;
                }
                $nv = auvvo_whatsapp_remap_trigger_value($pdo, (int) $flow['user_id'], $tt, (string) ($nd['trigger_value'] ?? ''));
                if ($nv !== (string) ($nd['trigger_value'] ?? '')) {
                    $nd['trigger_value'] = $nv;
                    $changed = true;
                }
            }
            unset($node, $nd);
            if ($changed) {
                $pdo->prepare('UPDATE crm_automation_flows SET flow_data = ? WHERE id = ?')
                    ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int) $flow['id']]);
            }
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] migration remap flows: ' . $e->getMessage());
    }
}

function auvvo_migration_connection_id_columns(PDO $pdo): void
{
    auvvo_migration_connection_id_columns_repair($pdo);
}

function auvvo_migration_connection_id_columns_repair(PDO $pdo): void
{
    if (auvvo_migration_column_exists($pdo, 'campaigns', 'agent_id')
        && !auvvo_migration_column_exists($pdo, 'campaigns', 'whatsapp_connection_id')) {
        auvvo_migration_add_column($pdo, 'campaigns', 'whatsapp_connection_id INT UNSIGNED NULL');
    }

    if (auvvo_migration_column_exists($pdo, 'auvvo_ai_jobs', 'agent_id')
        && !auvvo_migration_column_exists($pdo, 'auvvo_ai_jobs', 'whatsapp_connection_id')) {
        auvvo_migration_add_column($pdo, 'auvvo_ai_jobs', 'whatsapp_connection_id INT UNSIGNED NULL AFTER agent_id');
    }

    try {
        if (auvvo_migration_column_exists($pdo, 'campaigns', 'whatsapp_connection_id')) {
            $pdo->exec(
                'UPDATE campaigns c
                 INNER JOIN agents a ON a.id = c.agent_id
                 SET c.whatsapp_connection_id = a.whatsapp_connection_id
                 WHERE c.whatsapp_connection_id IS NULL AND a.whatsapp_connection_id IS NOT NULL'
            );
            $pdo->exec(
                'UPDATE campaigns c
                 INNER JOIN whatsapp_connections wc ON wc.user_id = c.user_id AND wc.default_agent_id = c.agent_id
                 SET c.whatsapp_connection_id = wc.id
                 WHERE c.whatsapp_connection_id IS NULL AND c.agent_id IS NOT NULL'
            );
        }
    } catch (PDOException $e) {
        error_log('[Auvvo] migration campaigns connection backfill: ' . $e->getMessage());
    }
}

/** Rate limiting de login por IP real — v46. */
function auvvo_migration_login_attempts(PDO $pdo): void {
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip VARCHAR(45) NOT NULL PRIMARY KEY, attempts INT UNSIGNED NOT NULL DEFAULT 1, first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_la_first (first_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $e) { error_log("[Auvvo] migration login_attempts: " . $e->getMessage()); }
}

/** Tokens de recuperacao de senha — v46. */
function auvvo_migration_password_resets(PDO $pdo): void {
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_pr_token (token), INDEX idx_pr_user (user_id), INDEX idx_pr_expires (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $e) { error_log("[Auvvo] migration password_resets: " . $e->getMessage()); }
}

/** Dedupe de webhooks de pagamento — v48. */
function auvvo_migration_webhook_event_log(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS webhook_event_log (
                event_id VARCHAR(128) NOT NULL,
                source VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id, source),
                INDEX idx_wel_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('[Auvvo] migration webhook_event_log: ' . $e->getMessage());
    }
}

function auvvo_migration_webhook_event_log_repair(PDO $pdo): void
{
    auvvo_migration_webhook_event_log($pdo);
    auvvo_migration_checkout_pending($pdo);
}

/** Cadastro pendente de checkout — v49. */
function auvvo_migration_checkout_pending(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS checkout_pending (
                token VARCHAR(64) NOT NULL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                plan_id VARCHAR(32) NOT NULL DEFAULT "anual",
                user_id INT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cp_email (email),
                INDEX idx_cp_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('[Auvvo] migration checkout_pending: ' . $e->getMessage());
    }
}

/** Foreign keys — v46. */
function auvvo_migration_foreign_keys(PDO $pdo): void {
    $fks = [["agents","user_id","users","id","CASCADE"],["contacts","user_id","users","id","CASCADE"],["whatsapp_connections","user_id","users","id","CASCADE"],["crm_pipelines","user_id","users","id","CASCADE"],["campaigns","user_id","users","id","CASCADE"]];
    foreach ($fks as [$t,$c,$rt,$rc,$od]) { $n="fk_{$t}_{$c}"; try { $pdo->exec("ALTER TABLE `$t` ADD CONSTRAINT `$n` FOREIGN KEY (`$c`) REFERENCES `$rt`(`$rc`) ON DELETE $od"); } catch (PDOException $e) { if (!(isset($e->errorInfo[1]) && (int)$e->errorInfo[1]===1005)) error_log("[Auvvo] FK $n: ".$e->getMessage()); } }
}
