import { createHmac } from 'crypto';
import { config, workerHmacSecret } from '../src/config.js';

const body = JSON.stringify({ job_id: 0 });
const ts = String(Math.floor(Date.now() / 1000));
const sig = createHmac('sha256', workerHmacSecret()).update(`${ts}.${body}`).digest('hex');
const url = `${config.appBaseUrl}/backend/internal/process_ai_job.php`;

const res = await fetch(url, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Auvvo-Timestamp': ts,
    'X-Auvvo-Signature': sig,
  },
  body,
});
const text = await res.text();
console.log('URL:', url);
console.log('HTTP', res.status);
console.log('Body:', text.slice(0, 300));
