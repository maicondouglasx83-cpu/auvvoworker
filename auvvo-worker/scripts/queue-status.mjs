import { getPool } from '../src/db.js';

const pool = getPool();
const [rows] = await pool.query(
  `SELECT status, COUNT(*) AS c FROM auvvo_ai_jobs GROUP BY status ORDER BY status`
);
console.log('Jobs by status:', rows);
const [active] = await pool.query(
  `SELECT COUNT(*) AS c FROM auvvo_ai_jobs WHERE status IN ('pending','debouncing','processing')`
);
console.log('Active jobs:', active[0].c);

try {
  const [auto] = await pool.query(
    `SELECT status, COUNT(*) AS c FROM crm_automation_queue GROUP BY status`
  );
  console.log('Automation queue:', auto);
} catch (e) {
  console.log('Automation queue: (tabela ausente — rode migrações PHP)');
}

await pool.end();
