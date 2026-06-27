<?php
/**
 * Executa migrações uma vez — CLI ou browser (remova em produção após uso).
 * php backend/install_migrations.php
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/migrations.php';

auvvo_run_migrations($pdo);

echo "Migrations OK — schema Auvvo atualizado.\n";
