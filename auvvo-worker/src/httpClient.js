export function fetchWithTimeout(url, options = {}, timeoutMs = 30000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  return fetch(url, { ...options, signal: controller.signal }).finally(() => clearTimeout(timer));
}

export async function fetchJson(url, options = {}, timeoutMs = 30000) {
  const res = await fetchWithTimeout(url, options, timeoutMs);
  const text = await res.text();
  let data = {};
  try { data = JSON.parse(text); } catch { data = { ok: false, error: text.slice(0, 200) }; }
  return { status: res.status, data };
}
