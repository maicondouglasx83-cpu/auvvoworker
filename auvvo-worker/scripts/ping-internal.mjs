/**
 * Testa HMAC + URLs internas usadas pelo worker (IA, automações, LTV).
 * Uso: npm run ping-internal
 */
import { createHmac } from 'crypto';
import { config, workerHmacSecret, internalApiUrl } from '../src/config.js';

const endpoints = [
  { name: 'AI job', path: '/backend/internal/process_ai_job.php', body: { job_id: 0 } },
  { name: 'Automation queue', path: '/backend/internal/process_automation_queue.php', body: { limit: 5 } },
  { name: 'LTV triggers', path: '/backend/internal/process_ltv_triggers.php', body: { limit: 50 } },
];

function sign(bodyStr) {
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = createHmac('sha256', workerHmacSecret()).update(`${ts}.${bodyStr}`).digest('hex');
  return { ts, sig };
}

let failed = 0;

console.log('APP_BASE_URL:', config.appBaseUrl);
console.log('HMAC:', process.env.WORKER_HMAC_SECRET ? 'WORKER_HMAC_SECRET (explícito)' : 'derivado de DB + APP_BASE_URL');

for (const ep of endpoints) {
  const bodyStr = JSON.stringify(ep.body);
  const { ts, sig } = sign(bodyStr);
  const url = internalApiUrl(ep.path.replace(/^\//, ''));
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Auvvo-Timestamp': ts,
        'X-Auvvo-Signature': sig,
      },
      body: bodyStr,
    });
    const text = await res.text();
    let ok = false;
    let hint = '';
    if (ep.name === 'AI job' && res.status === 400) {
      ok = text.includes('missing_job_id');
      hint = ok ? '(esperado sem job_id)' : '';
    } else if (res.status === 200 && text.includes('"ok":true')) {
      ok = true;
    } else if (res.status === 403) {
      hint = '(HMAC inválido — defina WORKER_HMAC_SECRET igual no .env do PHP e do worker, ou alinhe DB_* e APP_BASE_URL)';
    } else if (res.status === 404) {
      hint = '(arquivo não publicado no servidor)';
    }
    const status = ok ? 'OK' : 'FALHA';
    if (!ok) failed++;
    console.log(`[${status}] ${ep.name}`);
    console.log(`  HTTP ${res.status} ${hint}`);
    console.log(`  ${url}`);
    console.log(`  ${text.slice(0, 120)}\n`);
  } catch (e) {
    failed++;
    console.log(`[FALHA] ${ep.name}`);
    console.log(`  ${url}`);
    console.log(`  ${e.message}\n`);
  }
}

process.exit(failed > 0 ? 1 : 0);
