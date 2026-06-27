import { createHmac } from 'crypto';
import { workerHmacSecret } from './config.js';

export function sign(body) {
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = createHmac('sha256', workerHmacSecret()).update(`${ts}.${body}`).digest('hex');
  return { ts, sig };
}
