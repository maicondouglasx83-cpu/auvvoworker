import { getPool } from '../src/db.js';

const pool = getPool();
const [logs] = await pool.query(
  `SELECT id, type, incoming_msg, response_msg, created_at
   FROM conversation_logs ORDER BY id DESC LIMIT 15`
);
for (const l of logs) {
  console.log(`#${l.id} [${l.type}] ${l.created_at}`);
  console.log(`  IN:  ${(l.incoming_msg || '').slice(0, 60)}`);
  console.log(`  OUT: ${(l.response_msg || '').slice(0, 100)}`);
  console.log('');
}
await pool.end();
