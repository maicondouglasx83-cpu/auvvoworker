/**
 * Requeue jobs that failed with invalid_signature (HMAC was misconfigured).
 * Usage: node scripts/requeue-failed.mjs [--dry-run]
 */
import { getPool } from '../src/db.js';

const dryRun = process.argv.includes('--dry-run');
const pool = getPool();

const [rows] = await pool.query(
  `SELECT id, status, attempts, last_error, created_at
   FROM auvvo_ai_jobs
   WHERE status = 'failed' AND last_error LIKE '%invalid_signature%'
   ORDER BY id ASC`
);

if (!rows.length) {
  console.log('Nenhum job failed com invalid_signature.');
  await pool.end();
  process.exit(0);
}

console.log(`Encontrados ${rows.length} job(s):`);
for (const r of rows) {
  console.log(`  #${r.id} attempts=${r.attempts} created=${r.created_at}`);
}

if (dryRun) {
  console.log('(dry-run — nada alterado)');
  await pool.end();
  process.exit(0);
}

const ids = rows.map((r) => r.id);
const [result] = await pool.query(
  `UPDATE auvvo_ai_jobs
   SET status = 'pending', attempts = 0, last_error = NULL, next_retry_at = NULL, updated_at = NOW()
   WHERE id IN (?)`,
  [ids]
);

console.log(`Requeued: ${result.affectedRows} job(s). Rode npm start no worker para processar.`);
await pool.end();
