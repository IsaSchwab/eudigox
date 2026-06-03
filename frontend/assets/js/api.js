/**
 * SGX — Cliente da API REST
 *
 * Usa cookies de sessão PHP (credentials: 'include').
 * Não precisa de localStorage de token.
 * Funciona sem mod_rewrite (adiciona .php automaticamente nas URLs).
 */

const API_BASE = 'http://localhost/sgx/backend/api';

const USER_KEY = 'sgx.user';

const api = (() => {

  // O 'user' guardado no localStorage é só pra UI (mostrar nome, role na sidebar).
  // A autenticação real é feita pelo cookie de sessão PHP (httpOnly, automático).
  const getUser = () => {
    const raw = localStorage.getItem(USER_KEY);
    try { return raw ? JSON.parse(raw) : null; } catch { return null; }
  };
  const setUser = (u) => u
    ? localStorage.setItem(USER_KEY, JSON.stringify(u))
    : localStorage.removeItem(USER_KEY);

  const clearSession = () => { setUser(null); };

  /**
   * Adiciona .php no final do path se ainda não tiver, preservando query string.
   */
  function ensurePhp(path) {
    if (!path) return path;
    const [base, query] = path.split('?');
    if (/\.php$/.test(base)) return path;
    if (base === '' || base === '/') return path;
    return base + '.php' + (query ? '?' + query : '');
  }

  async function request(path, { method = 'GET', body = null } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const opts = {
      method,
      headers,
      credentials: 'include', // ← envia o cookie de sessão automaticamente
    };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);

    const finalPath = ensurePhp(path);

    let res;
    try {
      res = await fetch(`${API_BASE}${finalPath}`, opts);
    } catch (err) {
      throw new ApiError('Erro de rede. Verifique sua conexão.', 0, null);
    }

    let payload = null;
    try { payload = await res.json(); } catch { /* sem body */ }

    if (!res.ok) {
      const msg  = payload?.error || `Erro ${res.status}`;
      const code = payload?.code  || null;
      throw new ApiError(msg, res.status, code, payload?.details || {});
    }
    return payload?.data ?? payload;
  }

  /**
   * Faz upload de arquivo via multipart/form-data.
   * NÃO seta Content-Type manual — o navegador adiciona o boundary correto.
   *
   * @param {string} path - caminho do endpoint (ex: "/uploads/create")
   * @param {File} file - o arquivo
   * @param {object} extraFields - campos extras do form (ex: { kind: "photo_front" })
   * @param {function} onProgress - opcional, (percent 0-100) => void
   */
  function upload(path, file, extraFields = {}, onProgress = null) {
    return new Promise((resolve, reject) => {
      const form = new FormData();
      form.append('file', file);
      Object.entries(extraFields).forEach(([k, v]) => form.append(k, v));

      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${API_BASE}${ensurePhp(path)}`, true);
      xhr.withCredentials = true;

      if (onProgress) {
        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
        };
      }

      xhr.onerror = () => reject(new ApiError('Erro de rede ao enviar o arquivo.', 0, null));
      xhr.onload  = () => {
        let payload = null;
        try { payload = JSON.parse(xhr.responseText); } catch { /* sem body */ }
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve(payload?.data ?? payload);
        } else {
          const msg  = payload?.error || `Erro ${xhr.status}`;
          const code = payload?.code  || null;
          reject(new ApiError(msg, xhr.status, code, payload?.details || {}));
        }
      };
      xhr.send(form);
    });
  }

  return {
    get:    (path, opts)        => request(path, { ...opts, method: 'GET' }),
    post:   (path, body, opts)  => request(path, { ...opts, method: 'POST',   body }),
    put:    (path, body, opts)  => request(path, { ...opts, method: 'PUT',    body }),
    patch:  (path, body, opts)  => request(path, { ...opts, method: 'PATCH',  body }),
    del:    (path, opts)        => request(path, { ...opts, method: 'DELETE' }),
    upload,
    getUser, setUser, clearSession,
    isAuthenticated: () => !!getUser(),
  };
})();

class ApiError extends Error {
  constructor(message, status, code, details = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

/* ====== Toast utility ====== */
const toast = (() => {
  let container;
  const ensure = () => {
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
  };
  const show = (message, type = '', duration = 3500) => {
    ensure();
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = message;
    container.appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateX(20px)';
      setTimeout(() => el.remove(), 250);
    }, duration);
  };
  return {
    success: (m, d) => show(m, 'success', d),
    error:   (m, d) => show(m, 'error',   d || 5000),
    warning: (m, d) => show(m, 'warning', d),
    info:    (m, d) => show(m, '',        d),
  };
})();

/* ====== Helpers DOM ====== */
const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/* ====== Guarda de rota baseada em role ====== */
function requireAuth(allowedRoles = []) {
  if (!api.isAuthenticated()) {
    location.href = '/sgx/frontend/pages/login.html';
    return false;
  }
  if (allowedRoles.length > 0) {
    const user = api.getUser();
    if (!user || !allowedRoles.includes(user.role)) {
      toast.error('Você não tem permissão para acessar esta página.');
      setTimeout(() => location.href = '/sgx/frontend/pages/login.html', 1500);
      return false;
    }
  }
  return true;
}
