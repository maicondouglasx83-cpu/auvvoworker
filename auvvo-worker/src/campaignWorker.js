import { getPool } from './db.js';
import { config } from './config.js';
import { fetchWithTimeout } from './httpClient.js';

let sentThisMinute = 0;
let minuteWindow = Math.floor(Date.now() / 60000);

function tryClaimRateSlot() {
  const w = Math.floor(Date.now() / 60000);
  if (w !== minuteWindow) {
    minuteWindow = w;
    sentThisMinute = 0;
  }
  if (sentThisMinute >= config.campaignPerMinute) return false;
  sentThisMinute++;
  return true;
}

async function resolveCampaignSendCredentials(conn, campaignId) {
  const [rows] = await conn.query(
    `SELECT c.user_id,
            wc.evolution_token AS conn_token,
            wc.evolution_instance AS conn_instance,
            a.evolution_token AS agent_token,
            a.evolution_instance AS agent_instance
     FROM campaigns c
     LEFT JOIN whatsapp_connections wc ON wc.id = c.whatsapp_connection_id AND wc.user_id = c.user_id
     LEFT JOIN agents a ON a.id = c.agent_id AND a.user_id = c.user_id
     WHERE c.id = ?
     LIMIT 1`,
    [campaignId]
  );
  if (!rows.length) return null;
  const row = rows[0];
  const token = row.conn_token || row.agent_token;
  if (!token) return null;
  return {
    user_id: row.user_id,
    evolution_token: token,
    evolution_instance: row.conn_instance || row.agent_instance || '',
  };
}

async function sendWhatsApp(conn, item) {
  const cred = await resolveCampaignSendCredentials(conn, item.campaign_id);
  if (!cred) throw new Error('no_whatsapp_connection');

  const [settings] = await conn.query(
    'SELECT evolution_url, evolution_key FROM settings WHERE user_id = ? LIMIT 1',
    [cred.user_id]
  );
  let baseUrl = config.evolution.apiUrl;
  let apiKey = config.evolution.apiKey;
  if (settings[0]?.evolution_url) baseUrl = settings[0].evolution_url;
  if (settings[0]?.evolution_key) apiKey = settings[0].evolution_key;

  const phone = String(item.phone).replace(/\D/g, '');
  const url = `${baseUrl.replace(/\/$/, '')}/send/text`;
  const res = await fetchWithTimeout(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      apikey: cred.evolution_token,
    },
    body: JSON.stringify({ number: phone, text: item.message_rendered, delay: 500 }),
  });
  if (!res.ok) {
    const t = await res.text();
    throw new Error(`evolution_${res.status}:${t.slice(0, 120)}`);
  }
}

export async function processCampaignBatch() {
  if (!tryClaimRateSlot()) return false;
  const pool = getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.query(
      `SELECT q.* FROM campaign_send_queue q
       JOIN campaigns c ON c.id = q.campaign_id
       WHERE q.status = 'pending'
         AND (q.scheduled_at IS NULL OR q.scheduled_at <= NOW())
         AND c.status IN ('running','scheduled')
       ORDER BY q.id ASC
       LIMIT 1
       FOR UPDATE SKIP LOCKED`
    );
    if (!rows.length) {
      await conn.commit();
      return false;
    }
    const item = rows[0];
    await conn.query(
      `UPDATE campaign_send_queue SET status = 'processing' WHERE id = ?`,
      [item.id]
    );
    await conn.commit();

    try {
      await sendWhatsApp(conn, item);
      await conn.query(
        `UPDATE campaign_send_queue SET status = 'sent', sent_at = NOW() WHERE id = ?`,
        [item.id]
      );
      await conn.query(
        `UPDATE campaigns SET sent_count = sent_count + 1 WHERE id = ?`,
        [item.campaign_id]
      );
      const [rem] = await conn.query(
        `SELECT COUNT(*) AS c FROM campaign_send_queue WHERE campaign_id = ? AND status = 'pending'`,
        [item.campaign_id]
      );
      if (rem[0].c === 0) {
        await conn.query(`UPDATE campaigns SET status = 'completed' WHERE id = ?`, [item.campaign_id]);
      }
      return true;
    } catch (e) {
      const attempts = (item.attempts || 0) + 1;
      await conn.query(
        `UPDATE campaign_send_queue SET status = ?, attempts = ?, last_error = ? WHERE id = ?`,
        [attempts >= 3 ? 'failed' : 'pending', attempts, String(e.message).slice(0, 500), item.id]
      );
      return true;
    }
  } catch (e) {
    try { await conn.rollback(); } catch (_) {}
    console.error('[campaign]', e.message);
    return false;
  } finally {
    // Recover stuck processing items (older than 5 minutes)
    try {
      await conn.query(
        `UPDATE campaign_send_queue SET status = 'pending'
         WHERE status = 'processing'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)`
      );
    } catch (_) {}
    conn.release();
  }
}
