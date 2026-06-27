/**
 * Diagnóstico HMAC + fila + últimas respostas fallback.
 * Uso: npm run diag-hmac
 */
import { createHash, createHmac } from 'crypto';
import { config, workerHmacSecret, internalApiUrl } from '../src/config.js';
import { getPool } from '../src/db.js';

const secret = workerHmacSecret();
const secretMode = process.env.WORKER_HMAC_SECRET ? 'WORKER_HMAC_SECRET (explícito)' : 'derivado (DB+APP_BASE_URL)';

console.log('=== Worker env ===');
console.log('APP_BASE_URL:', config.appBaseUrl);
console.log('APP_HTTP_PREFIX:', config.httpPrefix || '(vazio / raiz)');
console.log('DB:', config.db.user + '@' + config.db.host + '/' + config.db.database);
console.log('HMAC mode:', secretMode);
console.log('HMAC preview:', secret.slice(0, 8) + '…' + secret.slice(-4), `(${secret.length} chars)`);

if (secretMode.startsWith('derivado')) {
  const material = [
    'auvvo-internal-worker-v1',
    config.db.password,
    config.db.user,
    config.db.database,
    config.db.host,
    config.appBaseUrl,
  ].join('\x1e');
  console.log('Derived material hash:', createHash('sha256').update(material).digest('hex').slice(0, 16) + '…');
}

const body = JSON.stringify({ job_id: 0 });
const ts = String(Math.floor(Date.now() / 1000));
const sig = createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
const url = internalApiUrl('backend/internal/process_ai_job.php');

console.log('\n=== Ping PHP ===');
console.log('URL:', url);
console.log('Timestamp:', ts, '(local)', new Date().toISOString());

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
console.log('HTTP', res.status, text.slice(0, 200));

// Teste OpenRouter (mesma chave que o PHP usa em process_ai_job.php)
const orKey = (process.env.OPENROUTER_API_KEY || '').trim();
if (orKey) {
  let model = (process.env.OPENROUTER_DEFAULT_MODEL || 'openai/gpt-4o-mini').trim();
  if (model.startsWith('openrouter/')) model = model.slice('openrouter/'.length);
  const orBody = JSON.stringify({ model, messages: [{ role: 'user', content: 'ok' }], max_tokens: 8 });
  const orRes = await fetch('https://openrouter.ai/api/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${orKey}`,
      'HTTP-Referer': config.appBaseUrl,
    },
    body: orBody,
  });
  const orText = await orRes.text();
  console.log('\n=== OpenRouter (local .env) ===');
  console.log('model:', model, 'HTTP', orRes.status);
  console.log(orText.slice(0, 220));
  if (orRes.status === 401) {
    console.log('>>> CHAVE OPENROUTER INVÁLIDA — gera nova em openrouter.ai/keys e atualize Hostinger .env');
  }
} else {
  console.log('\n=== OpenRouter: OPENROUTER_API_KEY ausente no .env local ===');
}

const pool = getPool();
console.log('\n=== Fila IA ===');
const [jobs] = await pool.query(
  `SELECT status, COUNT(*) AS c FROM auvvo_ai_jobs GROUP BY status`
);
console.log(jobs);

const [failed] = await pool.query(
  `SELECT id, last_error, updated_at FROM auvvo_ai_jobs WHERE status='failed' ORDER BY id DESC LIMIT 5`
);
if (failed.length) console.log('Últimos failed:', failed);

const [hb] = await pool.query(
  `SELECT meta_value, updated_at FROM auvvo_app_meta WHERE meta_key='worker_heartbeat' LIMIT 1`
);
if (hb.length) {
  const tsHb = parseInt(hb[0].meta_value, 10);
  const age = tsHb ? Math.round(Date.now() / 1000 - tsHb) : null;
  console.log('\n=== Worker heartbeat ===');
  console.log('updated_at:', hb[0].updated_at, age !== null ? `( há ${age}s )` : '');
  console.log(age !== null && age < 120 ? 'Worker ATIVO (<2min)' : 'Worker OFFLINE ou parado');
} else {
  console.log('\n=== Worker heartbeat: nunca registrado ===');
}

try {
  const [logs] = await pool.query(
    `SELECT id, response_type, LEFT(response_msg, 100) AS msg, created_at
     FROM conversation_logs
     WHERE response_type IN ('fallback', 'ai')
     ORDER BY id DESC LIMIT 6`
  );
  console.log('\n=== Últimas respostas WhatsApp ===');
  for (const l of logs) {
    console.log(`#${l.id} [${l.response_type}] ${l.created_at}: ${l.msg}`);
  }
} catch (e) {
  console.log('conversation_logs:', e.message);
}

await pool.end();
