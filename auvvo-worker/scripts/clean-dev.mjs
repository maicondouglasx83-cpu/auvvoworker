/**
 * Limpa dados de teste/dev: mensagens, filas, execuções, dedupe, pausas.
 *
 * Uso:
 *   npm run clean-dev              # pede confirmação
 *   npm run clean-dev -- --yes     # executa direto
 *   npm run clean-dev -- --dry-run # só mostra contagens
 *   npm run clean-dev -- --user=12 # só de um user_id
 */
import readline from 'readline';
import { getPool } from '../src/db.js';

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const yes = args.includes('--yes');
const userArg = args.find((a) => a.startsWith('--user='));
const userId = userArg ? parseInt(userArg.split('=')[1], 10) : 0;

const pool = getPool();

async function tableExists(name) {
  const [rows] = await pool.query('SHOW TABLES LIKE ?', [name]);
  return rows.length > 0;
}

async function countSql(sql, params = []) {
  try {
    const [rows] = await pool.query(sql, params);
    return Number(rows[0]?.c || 0);
  } catch {
    return -1;
  }
}

async function deleteSql(sql, params = []) {
  if (dryRun) return 0;
  const [res] = await pool.query(sql, params);
  return res.affectedRows ?? 0;
}

function ask(question) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise((resolve) => {
    rl.question(question, (ans) => {
      rl.close();
      resolve(ans.trim().toLowerCase());
    });
  });
}

const plan = [];

function addPlan(label, countSqlStr, deleteSqlStr, params = []) {
  plan.push({ label, countSql: countSqlStr, deleteSql: deleteSqlStr, params });
}

// Mensagens WhatsApp / IA
addPlan(
  'conversation_logs (mensagens chat)',
  userId > 0
    ? `SELECT COUNT(*) AS c FROM conversation_logs cl INNER JOIN agents a ON a.id = cl.agent_id WHERE a.user_id = ?`
    : 'SELECT COUNT(*) AS c FROM conversation_logs',
  userId > 0
    ? `DELETE cl FROM conversation_logs cl INNER JOIN agents a ON a.id = cl.agent_id WHERE a.user_id = ?`
    : 'DELETE FROM conversation_logs',
  userId > 0 ? [userId] : []
);

addPlan(
  'conversation_summaries',
  userId > 0
    ? `SELECT COUNT(*) AS c FROM conversation_summaries cs INNER JOIN agents a ON a.id = cs.agent_id WHERE a.user_id = ?`
    : 'SELECT COUNT(*) AS c FROM conversation_summaries',
  userId > 0
    ? `DELETE cs FROM conversation_summaries cs INNER JOIN agents a ON a.id = cs.agent_id WHERE a.user_id = ?`
    : 'DELETE FROM conversation_summaries',
  userId > 0 ? [userId] : []
);

addPlan(
  'conversation_events',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM conversation_events WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM conversation_events',
  userId > 0 ? 'DELETE FROM conversation_events WHERE user_id = ?' : 'DELETE FROM conversation_events',
  userId > 0 ? [userId] : []
);

addPlan(
  'brain_action_log',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM brain_action_log WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM brain_action_log',
  userId > 0 ? 'DELETE FROM brain_action_log WHERE user_id = ?' : 'DELETE FROM brain_action_log',
  userId > 0 ? [userId] : []
);

// Automações / fluxos
addPlan(
  'crm_automation_run_steps',
  userId > 0
    ? `SELECT COUNT(*) AS c FROM crm_automation_run_steps s INNER JOIN crm_automation_runs r ON r.id = s.run_id WHERE r.user_id = ?`
    : 'SELECT COUNT(*) AS c FROM crm_automation_run_steps',
  userId > 0
    ? `DELETE s FROM crm_automation_run_steps s INNER JOIN crm_automation_runs r ON r.id = s.run_id WHERE r.user_id = ?`
    : 'DELETE FROM crm_automation_run_steps',
  userId > 0 ? [userId] : []
);

addPlan(
  'crm_automation_runs (execuções/simulações)',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM crm_automation_runs WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM crm_automation_runs',
  userId > 0 ? 'DELETE FROM crm_automation_runs WHERE user_id = ?' : 'DELETE FROM crm_automation_runs',
  userId > 0 ? [userId] : []
);

addPlan(
  'crm_automation_dedupe (permite re-disparar fluxos)',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM crm_automation_dedupe WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM crm_automation_dedupe',
  userId > 0 ? 'DELETE FROM crm_automation_dedupe WHERE user_id = ?' : 'DELETE FROM crm_automation_dedupe',
  userId > 0 ? [userId] : []
);

addPlan(
  'crm_automation_wait_states (aguardar resposta)',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM crm_automation_wait_states WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM crm_automation_wait_states',
  userId > 0 ? 'DELETE FROM crm_automation_wait_states WHERE user_id = ?' : 'DELETE FROM crm_automation_wait_states',
  userId > 0 ? [userId] : []
);

addPlan(
  'crm_automation_queue (fila worker)',
  userId > 0 ? 'SELECT COUNT(*) AS c FROM crm_automation_queue WHERE user_id = ?' : 'SELECT COUNT(*) AS c FROM crm_automation_queue',
  userId > 0 ? 'DELETE FROM crm_automation_queue WHERE user_id = ?' : 'DELETE FROM crm_automation_queue',
  userId > 0 ? [userId] : []
);

addPlan(
  'auvvo_ai_jobs (fila IA)',
  userId > 0
    ? `SELECT COUNT(*) AS c FROM auvvo_ai_jobs j INNER JOIN agents a ON a.id = j.agent_id WHERE a.user_id = ?`
    : 'SELECT COUNT(*) AS c FROM auvvo_ai_jobs',
  userId > 0
    ? `DELETE j FROM auvvo_ai_jobs j INNER JOIN agents a ON a.id = j.agent_id WHERE a.user_id = ?`
    : 'DELETE FROM auvvo_ai_jobs',
  userId > 0 ? [userId] : []
);

addPlan(
  'conversation_states (pausa IA)',
  userId > 0
    ? `SELECT COUNT(*) AS c FROM conversation_states cs INNER JOIN agents a ON a.id = cs.agent_id WHERE a.user_id = ?`
    : 'SELECT COUNT(*) AS c FROM conversation_states',
  userId > 0
    ? `DELETE cs FROM conversation_states cs INNER JOIN agents a ON a.id = cs.agent_id WHERE a.user_id = ?`
    : 'DELETE FROM conversation_states',
  userId > 0 ? [userId] : []
);

console.log('\n[Auvvo] Limpeza dev — mensagens, filas, execuções, dedupe\n');
if (userId > 0) console.log(`Filtro: user_id = ${userId}`);
if (dryRun) console.log('Modo: dry-run (nada será apagado)\n');

const rows = [];
let total = 0;
for (const item of plan) {
  const exists = await tableExists(item.label.split(' ')[0]);
  if (!exists && item.label.startsWith('crm_')) {
    const t = item.label.split(' ')[0];
    if (!(await tableExists(t))) {
      rows.push({ label: item.label, count: 0, skip: true });
      continue;
    }
  }
  const c = await countSql(item.countSql, item.params);
  if (c > 0) total += c;
  rows.push({ ...item, count: c });
}

for (const r of rows) {
  if (r.skip) continue;
  console.log(`  ${String(r.count).padStart(6)}  ${r.label}`);
}
console.log(`\n  Total: ~${total} registro(s)\n`);

if (total === 0) {
  console.log('Nada para limpar.');
  await pool.end();
  process.exit(0);
}

if (!dryRun && !yes) {
  const ans = await ask('Apagar tudo acima? [s/N] ');
  if (ans !== 's' && ans !== 'sim' && ans !== 'y' && ans !== 'yes') {
    console.log('Cancelado.');
    await pool.end();
    process.exit(0);
  }
}

console.log(dryRun ? 'Dry-run — fim.' : 'Limpando...\n');
let deleted = 0;
for (const r of rows) {
  if (r.skip || r.count <= 0) continue;
  const n = await deleteSql(r.deleteSql, r.params);
  if (!dryRun) console.log(`  ✓ ${r.label}: ${n} removido(s)`);
  deleted += n;
}

console.log(dryRun ? '\n(dry-run concluído)' : `\nConcluído — ${deleted} registro(s) removidos.`);
console.log('Fluxos, agentes e contatos NÃO foram apagados.\n');
await pool.end();
