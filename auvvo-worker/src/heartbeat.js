import { mkdirSync, writeFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dir = dirname(fileURLToPath(import.meta.url));

function heartbeatPath() {
  const custom = (process.env.AUVVO_WORKER_HEARTBEAT || '').trim();
  if (custom) return custom;
  return join(__dir, '..', '..', 'storage', 'worker_heartbeat.txt');
}

/** Mesmo arquivo que `auvvo_worker_touch_heartbeat()` no PHP. */
export function touchWorkerHeartbeat() {
  const path = heartbeatPath();
  try {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, String(Math.floor(Date.now() / 1000)));
  } catch {
    /* ignore — UI só deixa de mostrar "worker ativo" */
  }
}
