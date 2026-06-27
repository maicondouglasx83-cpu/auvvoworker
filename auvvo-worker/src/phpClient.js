import { sign } from './hmac.js';
import { fetchJson } from './httpClient.js';
import { workerHmacSecret, internalApiUrl } from './config.js';

export async function processAiJob(jobId) {
  const body = JSON.stringify({ job_id: jobId });
  const { ts, sig } = sign(body);
  const url = internalApiUrl('backend/internal/process_ai_job.php');
  const result = await fetchJson(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Auvvo-Timestamp': ts,
      'X-Auvvo-Signature': sig,
    },
    body,
  });
  return result;
}

/** Ping leve — valida HMAC antes de processar fila. */
export async function verifyInternalHmac() {
  const { status, data } = await processAiJob(0);
  if (status === 403 && data.error === 'invalid_signature') return false;
  if (status === 400 && data.error === 'missing_job_id') return true;
  console.error('[auvvo-worker] HMAC verify failed:', status, data.error || 'unknown');
  if (status === 504 || status === 503) {
    console.error('[auvvo-worker] Retrying HMAC verify in 5s...');
    await new Promise(r => setTimeout(r, 5000));
    return verifyInternalHmac();
  }
  return false;
}
