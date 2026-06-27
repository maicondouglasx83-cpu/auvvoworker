import { getPool } from './db.js';
import { config } from './config.js';
import { processAiJob } from './phpClient.js';

export async function promoteDebounced(conn) {
  await conn.query(
    `UPDATE auvvo_ai_jobs SET status = 'pending', updated_at = NOW()
     WHERE status = 'debouncing' AND (flush_at IS NULL OR flush_at <= NOW())
     ORDER BY flush_at ASC, id ASC
     LIMIT 50`
  );
}

export async function claimAiJob(conn) {
  await conn.beginTransaction();
  try {
    const [rows] = await conn.query(
      `SELECT id FROM auvvo_ai_jobs
       WHERE status = 'pending'
         AND (next_retry_at IS NULL OR next_retry_at <= NOW())
       ORDER BY id ASC
       LIMIT 1
       FOR UPDATE SKIP LOCKED`
    );
    if (!rows.length) {
      await conn.commit();
      return null;
    }
    const id = rows[0].id;
    await conn.query(
      `UPDATE auvvo_ai_jobs SET status = 'processing', updated_at = NOW() WHERE id = ?`,
      [id]
    );
    await conn.commit();
    return id;
  } catch (e) {
    await conn.rollback();
    throw e;
  }
}

export async function processOneAiJob() {
  const pool = getPool();
  const conn = await pool.getConnection();
  try {
    await promoteDebounced(conn);
    const jobId = await claimAiJob(conn);
    if (!jobId) return false;

    const { status, data } = await processAiJob(jobId);

    if (status === 200 && data.ok) {
      return true;
    }

    if (status === 429 && data.retry) {
      await conn.query(
        `UPDATE auvvo_ai_jobs SET status = 'pending', next_retry_at = DATE_ADD(NOW(), INTERVAL 30 SECOND),
         last_error = ?, updated_at = NOW() WHERE id = ?`,
        [data.error || 'rate', jobId]
      );
      return true;
    }

    const [jobRows] = await conn.query('SELECT attempts FROM auvvo_ai_jobs WHERE id = ?', [jobId]);
    const attempts = (jobRows[0]?.attempts || 0) + 1;
    const failed = attempts >= config.maxAttempts;
    const backoff = [5, 30, 120][attempts - 1] || 300;

    const errMsg = (data.error || `http_${status}`).slice(0, 500);
    const lastError =
      data.error === 'invalid_signature'
        ? 'invalid_signature — defina WORKER_HMAC_SECRET igual no .env do PHP (Hostinger) e do worker Node'
        : errMsg;

    await conn.query(
      `UPDATE auvvo_ai_jobs SET status = ?, attempts = ?, last_error = ?,
       next_retry_at = IF(?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL), updated_at = NOW() WHERE id = ?`,
      [
        failed ? 'failed' : 'pending',
        attempts,
        lastError,
        failed ? 0 : 1,
        backoff,
        jobId,
      ]
    );
    return true;
  } finally {
    conn.release();
  }
}
