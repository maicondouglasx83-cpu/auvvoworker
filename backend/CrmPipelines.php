<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

/**
 * Pipelines CRM configuráveis — múltiplos funis por usuário.
 */
class CrmPipelines
{
    /** Paleta padrão por ordem de estágio */
    public const PALETTE = [
        '#6366F1', '#8B5CF6', '#F59E0B', '#F97316', '#10B981', '#EF4444',
        '#0EA5E9', '#EC4899', '#84CC16', '#64748B',
    ];

    /** Fallback legado (sem DB) */
    public static array $LEGACY_STAGES = [
        'new'       => ['label' => 'Novo Lead',        'color' => '#6366F1'],
        'contacted' => ['label' => 'Em Contato',       'color' => '#8B5CF6'],
        'qualified' => ['label' => 'Qualificado',      'color' => '#F59E0B'],
        'proposal'  => ['label' => 'Proposta Enviada', 'color' => '#F97316'],
        'closed'    => ['label' => 'Fechado / Ganho',  'color' => '#10B981'],
        'lost'      => ['label' => 'Perdido',          'color' => '#EF4444'],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function defaultPipelineId(int $userId): int
    {
        return auvvo_ensure_default_pipeline($this->pdo, $userId);
    }

    /** @return list<array> */
    public function listPipelines(int $userId, bool $withContactCounts = true): array
    {
        $defaultId = $this->defaultPipelineId($userId);
        $this->backfillContactsWithoutPipeline($userId, $defaultId);

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, is_default, sort_order, created_at
             FROM crm_pipelines WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$userId]);
        $pipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($pipes === []) {
            return [];
        }

        $ids = array_map(static fn ($p) => (int) $p['id'], $pipes);
        $stagesByPipe = $this->getStagesGroupedByPipelineIds($ids);
        $countsByPipe = $withContactCounts
            ? $this->countContactsGroupedByPipeline($userId, $ids)
            : [];

        foreach ($pipes as &$p) {
            $pid = (int) $p['id'];
            $p['stages'] = $stagesByPipe[$pid] ?? [];
            $p['contact_count'] = $countsByPipe[$pid] ?? 0;
        }

        return $pipes;
    }

    /**
     * @param list<int> $pipelineIds
     * @return array<int, list<array>>
     */
    private function getStagesGroupedByPipelineIds(array $pipelineIds): array
    {
        $pipelineIds = array_values(array_filter(array_map('intval', $pipelineIds)));
        if ($pipelineIds === []) {
            return [];
        }
        $in = implode(',', $pipelineIds);
        $stmt = $this->pdo->query(
            "SELECT * FROM crm_pipeline_stages WHERE pipeline_id IN ({$in}) ORDER BY pipeline_id ASC, sort_order ASC, id ASC"
        );
        $grouped = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['pipeline_id'];
            $grouped[$pid][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<int> $pipelineIds
     * @return array<int, int>
     */
    private function countContactsGroupedByPipeline(int $userId, array $pipelineIds): array
    {
        $pipelineIds = array_values(array_filter(array_map('intval', $pipelineIds)));
        if ($pipelineIds === []) {
            return [];
        }
        $in = implode(',', $pipelineIds);
        $stmt = $this->pdo->prepare(
            "SELECT pipeline_id, COUNT(*) AS c FROM contacts
             WHERE user_id = ? AND pipeline_id IN ({$in}) GROUP BY pipeline_id"
        );
        $stmt->execute([$userId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['pipeline_id']] = (int) $row['c'];
        }

        return $out;
    }

    /** @return list<array> */
    public function getStagesRaw(int $pipelineId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM crm_pipeline_stages WHERE pipeline_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$pipelineId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mapa slug => metadados (para Kanban / selects).
     *
     * @return array<string, array{label:string,color:string,id:int,is_won:bool,is_lost:bool}>
     */
    /**
     * slug => label por pipeline (para editor de automações).
     *
     * @return array<int, array<string, string>>
     */
    public function stagesByPipelineMap(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.pipeline_id, s.slug, s.label
             FROM crm_pipeline_stages s
             INNER JOIN crm_pipelines p ON p.id = s.pipeline_id AND p.user_id = ?
             ORDER BY s.pipeline_id ASC, s.sort_order ASC, s.id ASC'
        );
        $stmt->execute([$userId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['pipeline_id'];
            $out[$pid][(string) $row['slug']] = (string) $row['label'];
        }

        return $out;
    }

    public function stagesMap(int $userId, ?int $pipelineId = null): array
    {
        $pid = $pipelineId > 0 ? $pipelineId : $this->defaultPipelineId($userId);
        $rows = $this->getStagesRaw($pid);
        if ($rows === []) {
            return self::$LEGACY_STAGES;
        }

        $map = [];
        foreach ($rows as $r) {
            $slug = (string) $r['slug'];
            $map[$slug] = [
                'label'   => (string) $r['label'],
                'color'   => (string) ($r['color'] ?? self::PALETTE[0]),
                'id'      => (int) $r['id'],
                'is_won'  => (int) ($r['is_won'] ?? 0) === 1,
                'is_lost' => (int) ($r['is_lost'] ?? 0) === 1,
            ];
        }

        return $map;
    }

    public function isLostSlug(int $userId, int $pipelineId, string $slug): bool
    {
        $map = $this->stagesMap($userId, $pipelineId);

        return !empty($map[$slug]['is_lost']) || $slug === 'lost';
    }

    public function resolveStageId(int $pipelineId, string $slug): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM crm_pipeline_stages WHERE pipeline_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$pipelineId, $slug]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    public function firstStageSlug(int $pipelineId): string
    {
        $rows = $this->getStagesRaw($pipelineId);

        return $rows[0]['slug'] ?? 'new';
    }

    public function countByStage(int $userId, int $pipelineId): array
    {
        $map = $this->stagesMap($userId, $pipelineId);
        $counts = array_fill_keys(array_keys($map), 0);

        $stmt = $this->pdo->prepare(
            'SELECT stage, COUNT(*) AS total FROM contacts WHERE user_id = ? AND pipeline_id = ? GROUP BY stage'
        );
        $stmt->execute([$userId, $pipelineId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $slug = (string) $r['stage'];
            if (array_key_exists($slug, $counts)) {
                $counts[$slug] = (int) $r['total'];
            }
        }

        return $counts;
    }

    private function countContactsInPipeline(int $userId, int $pipelineId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contacts WHERE user_id = ? AND pipeline_id = ?'
        );
        $stmt->execute([$userId, $pipelineId]);

        return (int) $stmt->fetchColumn();
    }

    public function backfillContactsWithoutPipeline(int $userId, int $defaultPipelineId): void
    {
        static $ran = [];
        $key = $userId . ':' . $defaultPipelineId;
        if (isset($ran[$key])) {
            return;
        }
        $ran[$key] = true;

        $chk = $this->pdo->prepare(
            'SELECT id FROM contacts WHERE user_id = ? AND (pipeline_id IS NULL OR pipeline_id = 0) LIMIT 1'
        );
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, stage FROM contacts WHERE user_id = ? AND (pipeline_id IS NULL OR pipeline_id = 0)'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }

        $upd = $this->pdo->prepare(
            'UPDATE contacts SET pipeline_id = ?, stage_id = ? WHERE id = ?'
        );
        foreach ($rows as $r) {
            $slug = (string) ($r['stage'] ?: 'new');
            $stageId = $this->resolveStageId($defaultPipelineId, $slug)
                ?? $this->resolveStageId($defaultPipelineId, 'new');
            $upd->execute([$defaultPipelineId, $stageId, (int) $r['id']]);
        }
    }

    public function syncContactStage(int $contactId, int $pipelineId, string $slug): void
    {
        $stageId = $this->resolveStageId($pipelineId, $slug);
        $this->pdo->prepare(
            'UPDATE contacts SET pipeline_id = ?, stage = ?, stage_id = ? WHERE id = ?'
        )->execute([$pipelineId, $slug, $stageId, $contactId]);
    }

    /**
     * @param array{name?:string,is_default?:int,sort_order?:int} $data
     */
    public function savePipeline(int $userId, array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['error' => true, 'message' => 'Nome do pipeline obrigatório.'];
        }

        $isDefault = (int) ($data['is_default'] ?? 0) === 1 ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        if ($id > 0) {
            $chk = $this->pdo->prepare('SELECT id FROM crm_pipelines WHERE id = ? AND user_id = ?');
            $chk->execute([$id, $userId]);
            if (!$chk->fetch()) {
                return ['error' => true, 'message' => 'Pipeline não encontrado.'];
            }
            $this->pdo->prepare(
                'UPDATE crm_pipelines SET name = ?, sort_order = ? WHERE id = ? AND user_id = ?'
            )->execute([$name, $sortOrder, $id, $userId]);
            if ($isDefault) {
                $this->setDefaultPipeline($userId, $id);
            }

            return ['error' => false, 'id' => $id];
        }

        if ($isDefault) {
            $this->pdo->prepare('UPDATE crm_pipelines SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM crm_pipelines WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $isFirst = (int) $stmt->fetchColumn() === 0;

        $this->pdo->prepare(
            'INSERT INTO crm_pipelines (user_id, name, is_default, sort_order) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $name, ($isDefault || $isFirst) ? 1 : 0, $sortOrder]);

        $newId = (int) $this->pdo->lastInsertId();
        $this->seedDefaultStages($newId, $name);

        return ['error' => false, 'id' => $newId];
    }

    private function setDefaultPipeline(int $userId, int $pipelineId): void
    {
        $this->pdo->prepare('UPDATE crm_pipelines SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'UPDATE crm_pipelines SET is_default = 1 WHERE id = ? AND user_id = ?'
        )->execute([$pipelineId, $userId]);
    }

    private function seedDefaultStages(int $pipelineId, string $pipelineName): void
    {
        $templates = [
            ['novo', 'Novo', 0, 0, 0],
            ['em-contato', 'Em contato', 1, 0, 0],
            ['proposta', 'Proposta', 2, 0, 0],
            ['ganho', 'Ganho', 3, 1, 0],
            ['perdido', 'Perdido', 4, 0, 1],
        ];
        if (stripos($pipelineName, 'suporte') !== false) {
            $templates = [
                ['aberto', 'Aberto', 0, 0, 0],
                ['em-analise', 'Em análise', 1, 0, 0],
                ['aguardando', 'Aguardando cliente', 2, 0, 0],
                ['resolvido', 'Resolvido', 3, 1, 0],
            ];
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO crm_pipeline_stages (pipeline_id, slug, label, color, sort_order, is_won, is_lost)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($templates as $i => $t) {
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $ins->execute([$pipelineId, $t[0], $t[1], $color, $t[2], $t[3], $t[4]]);
        }
    }

    /**
     * @param list<array{id?:int,slug?:string,label?:string,color?:string,sort_order?:int,is_won?:int,is_lost?:int,_delete?:bool}> $stages
     */
    public function saveStages(int $userId, int $pipelineId, array $stages): array
    {
        $chk = $this->pdo->prepare('SELECT id FROM crm_pipelines WHERE id = ? AND user_id = ?');
        $chk->execute([$pipelineId, $userId]);
        if (!$chk->fetch()) {
            return ['error' => true, 'message' => 'Pipeline não encontrado.'];
        }

        $existing = $this->getStagesRaw($pipelineId);
        if (count($existing) <= 1 && $this->onlyDeletes($stages)) {
            return ['error' => true, 'message' => 'O pipeline precisa de pelo menos um estágio.'];
        }

        $order = 0;
        foreach ($stages as $s) {
            if (!empty($s['_delete']) && !empty($s['id'])) {
                $this->deleteStage($userId, $pipelineId, (int) $s['id']);

                continue;
            }

            $label = trim((string) ($s['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $slug = $this->normalizeSlug((string) ($s['slug'] ?? $label));
            $color = $this->normalizeColor((string) ($s['color'] ?? self::PALETTE[$order % count(self::PALETTE)]));
            $isWon = (int) ($s['is_won'] ?? 0) === 1 ? 1 : 0;
            $isLost = (int) ($s['is_lost'] ?? 0) === 1 ? 1 : 0;
            $stageId = (int) ($s['id'] ?? 0);

            if ($stageId > 0) {
                $this->pdo->prepare(
                    'UPDATE crm_pipeline_stages SET slug = ?, label = ?, color = ?, sort_order = ?, is_won = ?, is_lost = ?
                     WHERE id = ? AND pipeline_id = ?'
                )->execute([$slug, $label, $color, $order, $isWon, $isLost, $stageId, $pipelineId]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO crm_pipeline_stages (pipeline_id, slug, label, color, sort_order, is_won, is_lost)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$pipelineId, $slug, $label, $color, $order, $isWon, $isLost]);
            }
            $order++;
        }

        $this->migrateOrphanContacts($userId, $pipelineId);

        return ['error' => false, 'pipeline_id' => $pipelineId];
    }

    /** @param list<array> $stages */
    private function onlyDeletes(array $stages): bool
    {
        $kept = array_filter($stages, fn ($s) => empty($s['_delete']));

        return $kept === [];
    }

    private function deleteStage(int $userId, int $pipelineId, int $stageId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug FROM crm_pipeline_stages WHERE id = ? AND pipeline_id = ?'
        );
        $stmt->execute([$stageId, $pipelineId]);
        $oldSlug = $stmt->fetchColumn();
        if (!$oldSlug) {
            return;
        }

        $fallback = $this->firstStageSlug($pipelineId);
        $fallbackId = $this->resolveStageId($pipelineId, $fallback);

        $this->pdo->prepare(
            'UPDATE contacts SET stage = ?, stage_id = ? WHERE user_id = ? AND pipeline_id = ? AND stage = ?'
        )->execute([$fallback, $fallbackId, $userId, $pipelineId, $oldSlug]);

        $this->pdo->prepare('DELETE FROM crm_pipeline_stages WHERE id = ? AND pipeline_id = ?')
            ->execute([$stageId, $pipelineId]);
    }

    private function migrateOrphanContacts(int $userId, int $pipelineId): void
    {
        $valid = array_keys($this->stagesMap($userId, $pipelineId));
        if ($valid === []) {
            return;
        }
        $fallback = $valid[0];
        $fallbackId = $this->resolveStageId($pipelineId, $fallback);

        $stmt = $this->pdo->prepare(
            'SELECT id, stage FROM contacts WHERE user_id = ? AND pipeline_id = ?'
        );
        $stmt->execute([$userId, $pipelineId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array($r['stage'], $valid, true)) {
                $this->pdo->prepare('UPDATE contacts SET stage = ?, stage_id = ? WHERE id = ?')
                    ->execute([$fallback, $fallbackId, (int) $r['id']]);
            }
        }
    }

    public function deletePipeline(int $userId, int $pipelineId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, is_default FROM crm_pipelines WHERE id = ? AND user_id = ?');
        $stmt->execute([$pipelineId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['error' => true, 'message' => 'Pipeline não encontrado.'];
        }

        $cntStmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_pipelines WHERE user_id = ?');
        $cntStmt->execute([$userId]);
        if ((int) $cntStmt->fetchColumn() <= 1) {
            return ['error' => true, 'message' => 'Não é possível excluir o único pipeline.'];
        }

        $defaultId = $this->defaultPipelineId($userId);
        if ((int) $row['id'] === $defaultId) {
            $alt = $this->pdo->prepare(
                'SELECT id FROM crm_pipelines WHERE user_id = ? AND id != ? ORDER BY sort_order, id LIMIT 1'
            );
            $alt->execute([$userId, $pipelineId]);
            $newDefault = (int) ($alt->fetchColumn() ?: 0);
            if ($newDefault > 0) {
                $this->setDefaultPipeline($userId, $newDefault);
                $defaultId = $newDefault;
            }
        }

        $fallbackSlug = $this->firstStageSlug($defaultId);
        $fallbackStageId = $this->resolveStageId($defaultId, $fallbackSlug);

        $this->pdo->prepare(
            'UPDATE contacts SET pipeline_id = ?, stage = ?, stage_id = ? WHERE user_id = ? AND pipeline_id = ?'
        )->execute([$defaultId, $fallbackSlug, $fallbackStageId, $userId, $pipelineId]);

        $this->pdo->prepare('DELETE FROM crm_pipeline_stages WHERE pipeline_id = ?')->execute([$pipelineId]);
        $this->pdo->prepare('DELETE FROM crm_pipelines WHERE id = ? AND user_id = ?')->execute([$pipelineId, $userId]);

        return ['error' => false, 'message' => 'Pipeline removido. Contatos movidos para o pipeline padrão.'];
    }

    /**
     * Duplica pipeline e estágios (sem copiar contatos).
     */
    public function duplicatePipeline(int $userId, int $pipelineId): array
    {
        $chk = $this->pdo->prepare('SELECT name FROM crm_pipelines WHERE id = ? AND user_id = ?');
        $chk->execute([$pipelineId, $userId]);
        $name = $chk->fetchColumn();
        if (!$name) {
            return ['error' => true, 'message' => 'Pipeline não encontrado.'];
        }

        $res = $this->savePipeline($userId, ['name' => $name . ' (cópia)', 'is_default' => 0]);
        if (!empty($res['error'])) {
            return $res;
        }
        $newId = (int) ($res['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM crm_pipeline_stages WHERE pipeline_id = ?')->execute([$newId]);
        $stages = $this->getStagesRaw($pipelineId);
        $ins = $this->pdo->prepare(
            'INSERT INTO crm_pipeline_stages (pipeline_id, slug, label, color, sort_order, is_won, is_lost) VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($stages as $i => $s) {
            $ins->execute([
                $newId,
                $s['slug'],
                $s['label'],
                $s['color'] ?? self::PALETTE[$i % count(self::PALETTE)],
                $i,
                (int) ($s['is_won'] ?? 0),
                (int) ($s['is_lost'] ?? 0),
            ]);
        }

        return ['error' => false, 'id' => $newId];
    }

    public function setDefault(int $userId, int $pipelineId): array
    {
        $chk = $this->pdo->prepare('SELECT id FROM crm_pipelines WHERE id = ? AND user_id = ?');
        $chk->execute([$pipelineId, $userId]);
        if (!$chk->fetch()) {
            return ['error' => true, 'message' => 'Pipeline não encontrado.'];
        }
        $this->setDefaultPipeline($userId, $pipelineId);

        return ['error' => false];
    }

    private function normalizeSlug(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? 'estagio';
        $s = trim($s, '-');

        return $s !== '' ? mb_substr($s, 0, 64) : 'estagio';
    }

    private function normalizeColor(string $c): string
    {
        $c = trim($c);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
            return $c;
        }

        return self::PALETTE[0];
    }
}
