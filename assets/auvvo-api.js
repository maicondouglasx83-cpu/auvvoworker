/**
 * Helpers AJAX — CSRF refresh e erros de sessão.
 */
(function (global) {
  function getCsrfInput() {
    return document.querySelector('input[name="csrf_token"]') || document.querySelector('meta[name="csrf-token"]');
  }

  function applyCsrfToken(token) {
    if (!token) return;
    document.querySelectorAll('input[name="csrf_token"]').forEach((el) => {
      el.value = token;
    });
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
    if (typeof global.CSRF !== 'undefined') global.CSRF = token;
    if (typeof global.CSRF_TOKEN !== 'undefined') global.CSRF_TOKEN = token;
    if (global.FLOW_BOOT && typeof global.FLOW_BOOT === 'object') global.FLOW_BOOT.csrf = token;
  }

  async function refreshCsrfToken() {
    try {
      const res = await fetch('backend/api.php?action=csrf_refresh');
      const d = await res.json();
      if (d && d.csrf_token) {
        applyCsrfToken(d.csrf_token);
        return d.csrf_token;
      }
    } catch (e) {
    }
    return null;
  }

  function isCsrfErrorMessage(msg) {
    const s = String(msg || '').toLowerCase();
    return s.includes('sessão expirada') || s.includes('sessao expirada') || s.includes('inválida');
  }

  async function apiFetch(url, options) {
    const res = await fetch(url, options || {});
    let data = {};
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Resposta inválida do servidor.');
    }
    if (data && data.error && isCsrfErrorMessage(data.message)) {
      const newToken = await refreshCsrfToken();
      if (newToken && options && options.body instanceof FormData) {
        options.body.set('csrf_token', newToken);
        const retry = await fetch(url, options);
        return retry.json();
      }
    }
    return data;
  }

  global.AuvvoApi = {
    refreshCsrfToken,
    applyCsrfToken,
    apiFetch,
    isCsrfErrorMessage,
  };

  setInterval(refreshCsrfToken, 25 * 60 * 1000);
})(typeof window !== 'undefined' ? window : globalThis);
