import { sign } from './hmac.js';
import { fetchJson } from './httpClient.js';
import { workerHmacSecret, internalApiUrl, config } from './config.js';

let lastLtvRun = 0;
const LTV_INTERVAL_MS = 55 * 60 * 1000;

export async function processLtvTriggersIfDue() {
  const now = Date.now();
  if (now - lastLtvRun < LTV_INTERVAL_MS) {
    return null;
  }

  const body = JSON.stringify({ limit: 200 });
  const { ts, sig } = sign(body);
  const url = internalApiUrl('backend/internal/process_ltv_triggers.php');
  const result = await fetchJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Auvvo-Timestamp': ts, 'X-Auvvo-Signature': sig },
    body,
  });

  if (result.status === 200 && result.data.ok) {
    lastLtvRun = now;
    if ((result.data.fired || 0) > 0) {
      console.log('[auvvo-worker] LTV fired', result.data.fired);
    }
  } else {
    console.error('[auvvo-worker] LTV scan', result.status, result.data.error || 'unknown');
  }
  return result;
}
