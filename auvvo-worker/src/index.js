import { config } from './config.js';
import { getPool } from './db.js';
import { processOneAiJob } from './aiWorker.js';
import { processCampaignBatch } from './campaignWorker.js';
import { processAutomationQueue } from './automationWorker.js';
import { processLtvTriggersIfDue } from './ltvWorker.js';
import { touchWorkerHeartbeat } from './heartbeat.js';
import { verifyInternalHmac } from './phpClient.js';

let shuttingDown = false;

console.log('[auvvo-worker] starting', {
  db: config.db.database,
  base: config.appBaseUrl,
  prefix: config.httpPrefix || '(raiz)',
  pollMs: config.pollMs,
  modules: ['ai_jobs', 'campaigns', 'crm_automation_queue', 'ltv_triggers'],
});

async function startup() {
  await getPool().query('SELECT 1');
  console.log('[auvvo-worker] MySQL ok');

  const hmacOk = await verifyInternalHmac();
  if (!hmacOk) {
    console.error(
      '[auvvo-worker] HMAC inválido — o PHP em produção rejeitou a assinatura (403 invalid_signature).',
    );
    console.error(
      '[auvvo-worker] Verifique se WORKER_HMAC_SECRET é igual no .env do worker e no .env do PHP.',
    );
    process.exit(1);
  }
  console.log('[auvvo-worker] HMAC ok (process_ai_job.php)');
}

async function tick() {
  if (shuttingDown) {
    console.log('[auvvo-worker] Shutting down — tick skipped');
    return;
  }
  touchWorkerHeartbeat();
  try {
    await processOneAiJob();
    await processCampaignBatch();
    await processAutomationQueue(25);
    await processLtvTriggersIfDue();
  } catch (e) {
    console.error('[auvvo-worker] tick error', e.stack || e.message);
  }
  if (!shuttingDown) {
    setTimeout(tick, config.pollMs);
  }
}

function gracefulShutdown(signal) {
  console.log(`[auvvo-worker] Received ${signal} — waiting for current tick...`);
  shuttingDown = true;
  // Allow up to 30s for current tick to finish
  setTimeout(() => {
    console.log('[auvvo-worker] Forcing exit after shutdown timeout');
    process.exit(0);
  }, 30000);
}

process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));

startup()
  .then(() => tick())
  .catch((e) => {
    console.error('[auvvo-worker] startup failed', e.stack || e.message);
    process.exit(1);
  });
