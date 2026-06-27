import { sign } from './hmac.js';
import { fetchJson } from './httpClient.js';
import { getPool } from './db.js';
import { internalApiUrl } from './config.js';

export async function processAutomationQueue(limit = 25) {
  const pool = getPool();
  const [pendingRows] = await pool.query(
    `SELECT COUNT(*) AS c FROM crm_automation_queue WHERE status = 'pending' AND run_at <= NOW()`
  );
  const pending = Number(pendingRows[0]?.c || 0);
  if (pending === 0) {
    return { status: 200, data: { ok: true, processed: 0, pending: 0 } };
  }

  const body = JSON.stringify({ limit });
  const { ts, sig } = sign(body);
  const url = internalApiUrl('backend/internal/process_automation_queue.php');
  const result = await fetchJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Auvvo-Timestamp': ts, 'X-Auvvo-Signature': sig },
    body,
  });

  if (result.status !== 200 || !result.data.ok) {
    console.error('[auvvo-worker] automation queue', result.status, result.data.error || '');
  } else if ((result.data.processed || 0) > 0) {
    console.log('[auvvo-worker] automation queue processed', result.data.processed);
  }
  return result;
}
