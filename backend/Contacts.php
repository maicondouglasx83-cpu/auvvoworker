<?php
/**
 * backend/Contacts.php
 * Helper CRM — operações sobre as tabelas contacts e contact_activities.
 */

require_once __DIR__ . '/CrmPipelines.php';

class Contacts
{
    private PDO $pdo;
    private ?CrmPipelines $pipelines = null;

    /** @deprecated Use stages via CrmPipelines — mantido para compatibilidade */
    public static array $STAGES = [
        'new'       => ['label' => 'Novo Lead',        'color' => '#6366F1'],
        'contacted' => ['label' => 'Em Contato',       'color' => '#8B5CF6'],
        'qualified' => ['label' => 'Qualificado',      'color' => '#F59E0B'],
        'proposal'  => ['label' => 'Proposta Enviada', 'color' => '#F97316'],
        'closed'    => ['label' => 'Fechado / Ganho',  'color' => '#10B981'],
        'lost'      => ['label' => 'Perdido',          'color' => '#EF4444'],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/CrmPipelines.php';
        $this->pipelines = new CrmPipelines($pdo);
    }

    private function pipelines(): CrmPipelines
    {
        return $this->pipelines;
    }

    public function resolvePipelineId(int $user_id, ?int $pipeline_id = null): int
    {
        if ($pipeline_id > 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM crm_pipelines WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$pipeline_id, $user_id]);
            if ($stmt->fetchColumn()) {
                return $pipeline_id;
            }
        }

        return $this->pipelines()->defaultPipelineId($user_id);
    }

    // ------------------------------------------------------------------
    // Upsert automático — chamado pelo webhook ao receber mensagem
    // ------------------------------------------------------------------
    /**
     * @return array{id:int,is_new:bool}|false
     */
    public function upsertFromWebhook(int $user_id, int $agent_id, string $jid, string $push_name = '', int $connection_id = 0): array|false
    {
        if (auvvo_is_whatsapp_group_jid($jid)) {
            return false;
        }

        $phone = auvvo_contact_phone_digits(null, $jid);
        $canonical = $phone !== '' ? ($phone . '@s.whatsapp.net') : trim($jid);
        $name  = $push_name !== '' ? mb_substr($push_name, 0, 255) : null;

        try {
            $chk = $this->pdo->prepare('SELECT id FROM contacts WHERE user_id = ? AND jid = ? LIMIT 1');
            $chk->execute([$user_id, $canonical]);
            $isNew = !$chk->fetchColumn();

            $this->pdo->prepare(
                "INSERT INTO contacts
                    (user_id, agent_id, jid, name, phone, first_contact_at, last_contact_at)
                 VALUES
                    (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    last_contact_at = NOW(),
                    agent_id        = IF(agent_id IS NULL, VALUES(agent_id), agent_id),
                    name            = IF(name IS NULL OR name = '', VALUES(name), name),
                    phone           = IF(VALUES(phone) != '' AND (phone IS NULL OR phone = '' OR phone LIKE '%@%'), VALUES(phone), phone)"
            )->execute([$user_id, $agent_id ?: null, $canonical, $name, $phone !== '' ? $phone : null]);

            $stmt = $this->pdo->prepare('SELECT id FROM contacts WHERE user_id = ? AND jid = ? LIMIT 1');
            $stmt->execute([$user_id, $canonical]);
            $id = (int) ($stmt->fetchColumn() ?: 0);

            if ($id > 0 && $isNew) {
                require_once __DIR__ . '/whatsapp_connections.inc.php';
                $pid = $connection_id > 0
                    ? auvvo_whatsapp_resolve_inbound_pipeline_id($this->pdo, $user_id, $connection_id)
                    : $this->pipelines()->defaultPipelineId($user_id);
                $slug = $this->pipelines()->firstStageSlug($pid);
                $this->pipelines()->syncContactStage($id, $pid, $slug);
            }

            return $id > 0 ? ['id' => $id, 'is_new' => $isNew] : false;
        } catch (PDOException $e) {
            error_log('[Contacts] upsertFromWebhook: ' . $e->getMessage());
            return false;
        }
    }

    public function fireContactCreatedAutomations(int $user_id, int $contact_id, string $source = 'whatsapp'): void
    {
        $row = $this->get($user_id, $contact_id);
        if (!$row) {
            return;
        }
        require_once __DIR__ . '/crm_automation.inc.php';
        auvvo_crm_run_automations($this->pdo, $user_id, 'contact_created', $source, $row);
    }

    // ------------------------------------------------------------------
    // Lista de contatos com filtros
    // ------------------------------------------------------------------
    public function list(int $user_id, array $filters = []): array
    {
        $where  = ["c.user_id = :uid"];
        $params = [':uid' => $user_id];

        $pipeline_id = $this->resolvePipelineId($user_id, isset($filters['pipeline_id']) ? (int) $filters['pipeline_id'] : null);
        if (empty($filters['skip_backfill'])) {
            $this->pipelines()->backfillContactsWithoutPipeline($user_id, $pipeline_id);
        }
        $where[] = 'c.pipeline_id = :pipeline_id';
        $params[':pipeline_id'] = $pipeline_id;

        if (!empty($filters['stage'])) {
            $where[]           = "c.stage = :stage";
            $params[':stage']  = $filters['stage'];
        }
        if (!empty($filters['agent_id'])) {
            $where[]              = "c.agent_id = :agent_id";
            $params[':agent_id']  = (int)$filters['agent_id'];
        }
        if (!empty($filters['search'])) {
            $where[]              = "(c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search OR c.company LIKE :search)";
            $params[':search']    = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['tag'])) {
            $where[]           = "JSON_CONTAINS(c.tags, :tag, '$')";
            $params[':tag']    = json_encode($filters['tag']);
        }

        // Oculta grupos WhatsApp (@g.us) — não são leads individuais
        $where[] = "(c.jid IS NULL OR c.jid NOT LIKE '%@g.us')";

        $sql = "SELECT c.id, c.user_id, c.pipeline_id, c.stage_id, c.agent_id, c.jid, c.name, c.phone,
                       c.email, c.company, c.stage, c.tags, c.notes, c.loss_reason,
                       c.first_contact_at, c.last_contact_at, c.created_at, c.updated_at,
                       a.name AS agent_name
                FROM contacts c
                LEFT JOIN agents a ON a.id = c.agent_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.last_contact_at DESC, c.id DESC
                LIMIT 500";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
            $row['custom_fields'] = [];
            $row['memory_json'] = [];
            $digits = auvvo_contact_phone_digits($row['phone'] ?? null, $row['jid'] ?? null);
            if ($digits !== '') {
                $row['phone'] = $digits;
            }
            $row['phone_display'] = auvvo_format_phone_display($row['phone'] ?? null, $row['jid'] ?? null, $row['name'] ?? null);
        }
        unset($row);
        return $rows;
    }

    // ------------------------------------------------------------------
    // Busca um contato (com atividades recentes)
    // ------------------------------------------------------------------
    public function get(int $user_id, int $contact_id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, a.name AS agent_name
             FROM contacts c
             LEFT JOIN agents a ON a.id = c.agent_id
             WHERE c.id = ? AND c.user_id = ? LIMIT 1"
        );
        $stmt->execute([$contact_id, $user_id]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contact) return null;

        $contact['tags']          = $contact['tags'] ? json_decode($contact['tags'], true) : [];
        $contact['custom_fields'] = $contact['custom_fields'] ? json_decode($contact['custom_fields'], true) : [];
        $contact['memory_json']   = !empty($contact['memory_json']) ? json_decode($contact['memory_json'], true) : [];
        if (!is_array($contact['memory_json'])) {
            $contact['memory_json'] = [];
        }

        // Últimas atividades
        $stmt = $this->pdo->prepare(
            "SELECT * FROM contact_activities WHERE contact_id = ? ORDER BY id DESC LIMIT 50"
        );
        $stmt->execute([$contact_id]);
        $contact['activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Últimas conversas (últimas 30 trocas)
        $stmt = $this->pdo->prepare(
            "SELECT id, incoming_msg, response_msg, type, created_at
             FROM conversation_logs
             WHERE contact_id = ? OR (agent_id = ? AND contact_jid = ?)
             ORDER BY id DESC LIMIT 30"
        );
        $stmt->execute([$contact_id, $contact['agent_id'] ?? 0, $contact['jid']]);
        $contact['recent_messages'] = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        return $contact;
    }

    // ------------------------------------------------------------------
    // Salvar (create ou update)
    // ------------------------------------------------------------------
    public function save(int $user_id, array $data): array
    {
        $id      = (int)($data['id'] ?? 0);
        $allowed = ['name','phone','email','company','stage','notes','tags','custom_fields','loss_reason','lost_at','memory_json'];

        if ($id) {
            // Verificar dono
            $stmt = $this->pdo->prepare("SELECT id, stage FROM contacts WHERE id=? AND user_id=? LIMIT 1");
            $stmt->execute([$id, $user_id]);
            $existing = $stmt->fetch();
            if (!$existing) return ['error' => true, 'message' => 'Contato não encontrado.'];

            $old_stage = $existing['stage'];
            $pidStmt = $this->pdo->prepare('SELECT pipeline_id FROM contacts WHERE id = ? LIMIT 1');
            $pidStmt->execute([$id]);
            $contactPipelineId = (int) ($pidStmt->fetchColumn() ?: 0);
            $contactPipelineId = $this->resolvePipelineId($user_id, $contactPipelineId ?: null);
            $stageMap = $this->pipelines()->stagesMap($user_id, $contactPipelineId);

            if (isset($data['stage']) && !array_key_exists($data['stage'], $stageMap)) {
                return ['error' => true, 'message' => 'Estágio inválido para este pipeline.'];
            }

            $sets = [];
            $params = [];
            foreach ($allowed as $f) {
                if (!array_key_exists($f, $data)) continue;
                $v = $data[$f];
                if (in_array($f, ['tags', 'custom_fields'])) {
                    $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_string($v) ? $v : null);
                }
                $sets[]     = "$f = ?";
                $params[]   = $v;
            }
            if (empty($sets)) return ['error' => false, 'id' => $id];

            $params[] = $id;
            $this->pdo->prepare("UPDATE contacts SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);

            if (isset($data['stage'])) {
                $this->pipelines()->syncContactStage($id, $contactPipelineId, (string) $data['stage']);
            }

            // Log mudança de estágio + automações
            if (isset($data['stage']) && $data['stage'] !== $old_stage) {
                if ($this->pipelines()->isLostSlug($user_id, $contactPipelineId, (string) $data['stage'])) {
                    $lr = trim((string) ($data['loss_reason'] ?? ''));
                    if ($lr === '') {
                        return ['error' => true, 'message' => 'Informe o motivo da perda (loss_reason).'];
                    }
                    $this->pdo->prepare('UPDATE contacts SET loss_reason = ?, lost_at = NOW() WHERE id = ?')
                        ->execute([$lr, $id]);
                }
                $from = $stageMap[$old_stage]['label'] ?? $old_stage;
                $to   = $stageMap[$data['stage']]['label'] ?? $data['stage'];
                $this->addActivity($user_id, $id, 'stage_change', "Estágio alterado de \"{$from}\" para \"{$to}\"");
                require_once __DIR__ . '/crm_automation.inc.php';
                $contactRow = $this->get($user_id, $id);
                if ($contactRow) {
                    auvvo_crm_run_automations($this->pdo, $user_id, 'stage_enter', (string) $data['stage'], $contactRow);
                }
            }

            return ['error' => false, 'id' => $id];
        }

        // Novo contato
        $jid  = trim($data['jid'] ?? '');
        $phone = trim($data['phone'] ?? '');
        if ($jid === '' && $phone !== '') {
            $jid = preg_replace('/\D/', '', $phone) . '@s.whatsapp.net';
        }
        if ($jid === '') return ['error' => true, 'message' => 'JID ou telefone obrigatório.'];

        $tags   = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null;
        $cf     = isset($data['custom_fields']) && is_array($data['custom_fields']) ? json_encode($data['custom_fields'], JSON_UNESCAPED_UNICODE) : null;

        $pipeline_id = $this->resolvePipelineId($user_id, isset($data['pipeline_id']) ? (int) $data['pipeline_id'] : null);
        $stageMap = $this->pipelines()->stagesMap($user_id, $pipeline_id);
        $stage = trim((string) ($data['stage'] ?? ''));
        if ($stage === '' || !array_key_exists($stage, $stageMap)) {
            $stage = $this->pipelines()->firstStageSlug($pipeline_id);
        }
        $stage_id = $this->pipelines()->resolveStageId($pipeline_id, $stage);

        try {
            $this->pdo->prepare(
                "INSERT INTO contacts
                    (user_id, pipeline_id, stage_id, agent_id, jid, name, phone, email, company, stage, tags, notes, custom_fields, first_contact_at, last_contact_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            )->execute([
                $user_id,
                $pipeline_id,
                $stage_id,
                $data['agent_id'] ?? null,
                $jid,
                $data['name']    ?? null,
                $phone ?: null,
                $data['email']   ?? null,
                $data['company'] ?? null,
                $stage,
                $tags,
                $data['notes']   ?? null,
                $cf,
            ]);
            $new_id = (int) $this->pdo->lastInsertId();

            $this->addActivity($user_id, $new_id, 'system', 'Contato criado manualmente.');
            $this->fireContactCreatedAutomations($user_id, $new_id, 'manual');

            return ['error' => false, 'id' => $new_id];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['error' => true, 'message' => 'Já existe um contato com este número/JID.'];
            }
            return ['error' => true, 'message' => 'Erro ao salvar: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Deletar
    // ------------------------------------------------------------------
    public function delete(int $user_id, int $contact_id): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM contacts WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$contact_id, $user_id]);
        if (!$stmt->fetch()) return false;

        $this->pdo->prepare("DELETE FROM contact_activities WHERE contact_id=?")->execute([$contact_id]);
        $this->pdo->prepare("UPDATE conversation_logs SET contact_id=NULL WHERE contact_id=?")->execute([$contact_id]);
        $this->pdo->prepare("DELETE FROM contacts WHERE id=?")->execute([$contact_id]);
        return true;
    }

    // ------------------------------------------------------------------
    // Adicionar atividade
    // ------------------------------------------------------------------
    public function addActivity(int $user_id, int $contact_id, string $type, string $description, ?array $meta = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO contact_activities (contact_id, user_id, type, description, meta)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $contact_id,
            $user_id,
            $type,
            $description,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Contagem por estágio (para Kanban header)
    // ------------------------------------------------------------------
    public function countByStage(int $user_id, ?int $pipeline_id = null): array
    {
        $pid = $this->resolvePipelineId($user_id, $pipeline_id);

        return $this->pipelines()->countByStage($user_id, $pid);
    }

    // ------------------------------------------------------------------
    // Exportar CSV
    // ------------------------------------------------------------------
    public function exportCsv(int $user_id, array $filters = []): void
    {
        $contacts = $this->list($user_id, $filters);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="contatos_crm_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

        fputcsv($out, ['ID','Nome','Telefone','Email','Empresa','Estágio','Tags','Agente','Último Contato','Criado Em']);
        foreach ($contacts as $c) {
            $tags = implode(', ', (array)($c['tags'] ?? []));
            $pid = (int) ($c['pipeline_id'] ?? 0);
            $map = $this->pipelines()->stagesMap($user_id, $pid ?: null);
            $stage_label = $map[$c['stage']]['label'] ?? $c['stage'];
            fputcsv($out, [
                $c['id'],
                $c['name'] ?? '',
                $c['phone'] ?? '',
                $c['email'] ?? '',
                $c['company'] ?? '',
                $stage_label,
                $tags,
                $c['agent_name'] ?? '',
                $c['last_contact_at'] ?? '',
                $c['created_at'] ?? '',
            ]);
        }
        fclose($out);
    }

    // ------------------------------------------------------------------
    // Adicionar tag ao contato
    // ------------------------------------------------------------------
    public function addTag(int $user_id, int $contact_id, string $tag, bool $runAutomations = true): bool
    {
        $stmt = $this->pdo->prepare("SELECT id, tags FROM contacts WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$contact_id, $user_id]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $tags = $row['tags'] ? json_decode($row['tags'], true) : [];
        $tag  = mb_strtolower(trim($tag));
        $added = false;
        if ($tag && !in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->pdo->prepare("UPDATE contacts SET tags=? WHERE id=?")
                ->execute([json_encode($tags, JSON_UNESCAPED_UNICODE), $contact_id]);
            $added = true;
        }
        if ($added && $runAutomations) {
            require_once __DIR__ . '/crm_automation.inc.php';
            $contactRow = $this->get($user_id, $contact_id);
            if ($contactRow) {
                auvvo_crm_run_automations($this->pdo, $user_id, 'tag_added', $tag, $contactRow);
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Remover tag do contato
    // ------------------------------------------------------------------
    public function removeTag(int $user_id, int $contact_id, string $tag): bool
    {
        $stmt = $this->pdo->prepare("SELECT id, tags FROM contacts WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$contact_id, $user_id]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $tags = $row['tags'] ? json_decode($row['tags'], true) : [];
        $tags = array_values(array_filter($tags, fn($t) => $t !== mb_strtolower(trim($tag))));
        $this->pdo->prepare("UPDATE contacts SET tags=? WHERE id=?")
            ->execute([json_encode($tags, JSON_UNESCAPED_UNICODE), $contact_id]);
        return true;
    }
}
