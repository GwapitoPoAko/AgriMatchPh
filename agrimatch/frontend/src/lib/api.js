/**
 * Axios client for the AgriMatch Laravel API (/api/v1).
 *
 * Security
 *  - withCredentials: Sanctum SPA auth rides on the HttpOnly session cookie.
 *  - CSRF: before the first state-changing request we fetch /sanctum/csrf-cookie,
 *    then inject the XSRF-TOKEN cookie as the X-XSRF-TOKEN header on every
 *    mutation. A 419 (expired token) refreshes the cookie and retries once.
 *  - No tokens are kept in localStorage; only a non-sensitive user snapshot
 *    for instant UI on reload (re-validated against /auth/me).
 *
 * Offline-first
 *  - Mutations sent with `{ offline: true }` that fail for lack of network are
 *    queued in IndexedDB (lib/offlineDb.js) and resolve with { queued: true }.
 *  - The outbox flushes automatically on `online` and when the service worker
 *    relays a Background Sync event. Every queued request carries an
 *    X-Idempotency-Key so replays never double-create.
 */
import axios from 'axios';
import { useSyncExternalStore } from 'react';
import { newId, outbox } from './offlineDb';

const API_URL = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '');
// Same origin as the API host; the Vite dev server proxies /sanctum and /api to Laravel.
const ORIGIN = API_URL.startsWith('http') ? new URL(API_URL).origin : '';
const MUTATING = new Set(['post', 'put', 'patch', 'delete']);

export class ApiError extends Error {
  constructor(status, message, errors = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

/* ---------------- axios instance --------------------------------------- */

export const http = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  timeout: 20000,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

function readCookie(name) {
  // During local development a browser can retain both a prior Domain cookie
  // and the new host-only cookie. Prefer the newest matching value so the CSRF
  // header always corresponds to the cookie Laravel just issued.
  const matches = document.cookie.split('; ').filter((c) => c.startsWith(`${name}=`));
  const match = matches[matches.length - 1];
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}

let csrfPromise = null;
export function ensureCsrfCookie(force = false) {
  if (!force && readCookie('XSRF-TOKEN')) return Promise.resolve();
  if (!csrfPromise || force) {
    csrfPromise = axios
      .get(`${ORIGIN}/sanctum/csrf-cookie`, { withCredentials: true, headers: { Accept: 'application/json' } })
      .finally(() => {
        csrfPromise = null;
      });
  }
  return csrfPromise;
}

// Request: CSRF header injection + idempotency key on every mutation.
http.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase();
  if (MUTATING.has(method)) {
    await ensureCsrfCookie();
    const token = readCookie('XSRF-TOKEN');
    if (token) config.headers['X-XSRF-TOKEN'] = token;
    if (!config.headers['X-Idempotency-Key']) config.headers['X-Idempotency-Key'] = newId();
  }
  return config;
});

// Response: unwrap data, normalise errors, refresh CSRF on 419, queue offline mutations.
http.interceptors.response.use(
  (res) => res,
  async (error) => {
    const { config, response } = error;
    if (axios.isCancel(error)) throw error;

    if (response?.status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true;
      await ensureCsrfCookie(true);
      config.headers['X-XSRF-TOKEN'] = readCookie('XSRF-TOKEN');
      return http.request(config);
    }

    const method = (config?.method || 'get').toLowerCase();
    const isNetworkFailure = !response && (error.code === 'ERR_NETWORK' || error.code === 'ECONNABORTED' || !navigator.onLine);
    if (isNetworkFailure && config?.offline && MUTATING.has(method)) {
      await outbox.add({
        method,
        url: config.url,
        data: typeof config.data === 'string' ? JSON.parse(config.data) : config.data,
        idempotencyKey: config.headers['X-Idempotency-Key'],
        label: config.offlineLabel || `${method.toUpperCase()} ${config.url}`,
        draftId: config.draftId,
      });
      requestBackgroundSync();
      return { data: { queued: true }, status: 202, config, headers: {} };
    }

    const payload = response?.data;
    throw new ApiError(
      response?.status ?? 0,
      payload?.message || (response ? `Request failed (${response.status})` : 'You appear to be offline. Check your connection.'),
      payload?.errors || {},
    );
  },
);

/* ---------------- outbox replay ---------------------------------------- */

let flushing = false;
export async function flushOutbox() {
  if (flushing || !navigator.onLine) return { sent: 0, failed: 0 };
  flushing = true;
  let sent = 0;
  let failed = 0;
  try {
    await ensureCsrfCookie(true);
    for (const item of await outbox.all()) {
      try {
        await http.request({ method: item.method, url: item.url, data: item.data, headers: { 'X-Idempotency-Key': item.idempotencyKey } });
        await outbox.remove(item.id);
        sent += 1;
      } catch (err) {
        // 4xx (validation/authorization) will never succeed on replay — drop it and surface the error.
        if (err instanceof ApiError && err.status >= 400 && err.status < 500 && err.status !== 401 && err.status !== 419) {
          await outbox.remove(item.id);
          window.dispatchEvent(new CustomEvent('agrimatch:outbox-rejected', { detail: { item, message: err.message, errors: err.errors } }));
        } else {
          await outbox.bump(item.id, err.message);
          failed += 1;
          if (err.status === 0) break; // still offline
        }
      }
    }
  } finally {
    flushing = false;
  }
  if (sent) window.dispatchEvent(new CustomEvent('agrimatch:outbox-flushed', { detail: { sent } }));
  return { sent, failed };
}

function requestBackgroundSync() {
  navigator.serviceWorker?.ready
    .then((reg) => reg.sync?.register('agrimatch-outbox'))
    .catch(() => {});
}

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => flushOutbox());
  navigator.serviceWorker?.addEventListener('message', (e) => {
    if (e.data?.type === 'agrimatch:flush-outbox') flushOutbox();
  });
}

export const useOutboxCount = () =>
  useSyncExternalStore(
    (cb) => outbox.subscribe(() => cb()),
    () => outboxCountSnapshot,
  );
let outboxCountSnapshot = 0;
outbox.subscribe((n) => {
  outboxCountSnapshot = n;
});

export const useOnline = () =>
  useSyncExternalStore(
    (cb) => {
      window.addEventListener('online', cb);
      window.addEventListener('offline', cb);
      return () => {
        window.removeEventListener('online', cb);
        window.removeEventListener('offline', cb);
      };
    },
    () => navigator.onLine,
  );

/* ---------------- session (observable) --------------------------------- */

const USER_KEY = 'agrimatch.user';
let currentUser = (() => {
  try {
    return JSON.parse(window.localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
})();
const userListeners = new Set();
let sessionRevision = 0;

function setUser(user) {
  sessionRevision += 1;
  currentUser = user;
  try {
    if (user) window.localStorage.setItem(USER_KEY, JSON.stringify(user));
    else window.localStorage.removeItem(USER_KEY);
  } catch {
    /* storage unavailable: session stays in memory */
  }
  userListeners.forEach((l) => l());
}

export const session = {
  get user() {
    return currentUser;
  },
  clear() {
    setUser(null);
  },
};

export const useSession = () =>
  useSyncExternalStore(
    (cb) => {
      userListeners.add(cb);
      return () => userListeners.delete(cb);
    },
    () => currentUser,
  );

/* ---------------- endpoint helpers ------------------------------------- */

const get = (url, params, opts = {}) => http.get(url, { params: serialise(params), signal: opts.signal }).then((r) => r.data);
const send = (method, url, data, opts = {}) => http.request({ method, url, data, ...opts }).then((r) => r.data);

/** Arrays become comma lists (Laravel FormRequests accept both); empty values are dropped. */
function serialise(params = {}) {
  const out = {};
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v == null || v === '' || v === false || (Array.isArray(v) && !v.length)) return;
    out[k] = Array.isArray(v) ? v.join(',') : v === true ? 1 : v;
  });
  return out;
}

export const api = {
  // lookups
  provinces: (opts) => get('/lookups/provinces', null, opts),
  crops: (opts) => get('/lookups/crops', null, opts),
  tradingHubs: (opts) => get('/lookups/trading-hubs', null, opts),

  dashboardSummary: (opts) => get('/dashboard/summary', null, opts),

  // marketplace
  listings: (params, opts) => get('/harvest-listings', params, opts),
  listing: (id, opts) => get(`/harvest-listings/${id}`, null, opts),
  createListing: (body, opts) => send('post', '/harvest-listings', body, { offline: !(body instanceof FormData), offlineLabel: `Listing: ${body.title || 'photo listing'}`, ...opts }),
  freightEstimate: (id, params, opts) => get(`/harvest-listings/${id}/freight-estimate`, params, opts),
  myListings: (params, opts) => get('/harvest-listings', { ...params, seller_id: currentUser?.id, status: params?.status ?? ['active', 'upcoming', 'reserved'] }, opts),
  updateListing: (id, body) => send('patch', `/harvest-listings/${id}`, body),
  deleteListing: (id) => send('delete', `/harvest-listings/${id}`),

  // price & fuel index
  priceTrends: (params, opts) => get('/price-index/trends', params, opts),
  priceTicker: (params, opts) => get('/price-index/ticker', params, opts),
  hubTrends: (params, opts) => get('/price-index/hubs', params, opts),
  marketSignals: (params, opts) => get('/price-index/signals', params, opts),
  fuelPrices: (params, opts) => get('/fuel-prices', params, opts),
  recordFuelPrice: (body) => send('post', '/fuel-prices', body),

  // coop pools
  coopPools: (params, opts) => get('/coop-pools', params, opts),
  poolCandidates: (coopId, params, opts) => get(`/cooperatives/${coopId}/pool-candidates`, params, opts),
  createCoopPool: (body) => send('post', '/coop-pools', body),
  dissolveCoopPool: (listingId) => send('post', `/coop-pools/${listingId}/dissolve`),

  // freight ("Hakot")
  freightQuote: (body) => send('post', '/freight/quote', body),
  freightPools: (params, opts) => get('/freight/pools', params, opts),
  freightPool: (id, opts) => get(`/freight/pools/${id}`, null, opts),
  createFreightPool: (body) => send('post', '/freight/pools', body),
  joinFreightPool: (id, body) => send('post', `/freight/pools/${id}/join`, body, { offline: true, offlineLabel: 'Join truck pool' }),

  // public registration
  register: (body) => send('post', '/auth/register', body),

  // super-admin workspace
  adminStaff: (opts) => get('/admin/staff', null, opts),
  createStaff: (body) => send('post', '/admin/staff', body),
  updateStaff: (id, body) => send('patch', `/admin/staff/${id}`, body),
  adminDisputes: (opts) => get('/admin/disputes', null, opts),
  resolveDispute: (id, body) => send('patch', `/admin/disputes/${id}`, body),
  fuelPolicy: (opts) => get('/admin/fuel-policy', null, opts),
  updateFuelPolicy: (body) => send('patch', '/admin/fuel-policy', body),
  auditLogs: (params, opts) => get('/audit-logs', params, opts),
  inspections: (params, opts) => get('/inspections', params, opts),
  startInspection: (id) => send('post', `/inspections/${id}/start`),
  declineInspection: (id, body) => send('post', `/inspections/${id}/decline`, body),
  recordInspection: (id, body) => {
    if (!(body?.image instanceof File)) return send('post', `/inspections/${id}/result`, body);
    const form = new FormData();
    Object.entries(body).forEach(([key, value]) => { if (value !== undefined && value !== null) form.append(key, value instanceof File ? value : String(value)); });
    return send('post', `/inspections/${id}/result`, form);
  },

  // negotiation
  createOffer: (body) => send('post', '/offers', body),
  contracts: (params, opts) => get('/contracts', params, opts),

  // notifications
  notifications: (params, opts) => get('/notifications', params, opts),
  unreadCount: (opts) => get('/notifications/unread-count', null, opts),
  markNotificationRead: (id) => send('post', `/notifications/${id}/read`),
  markAllNotificationsRead: () => send('post', '/notifications/read-all'),

  // auth (Sanctum SPA)
  async login(email, password) {
    await ensureCsrfCookie(true);
    const res = await send('post', '/auth/login', { email, password });
    // The login response rotates the session and refreshes XSRF-TOKEN. Avoid a
    // second CSRF-cookie request here: it can create a new anonymous session
    // before the authenticated session cookie has been committed by the proxy.
    setUser(res.user);
    return res;
  },
  async logout() {
    try {
      await send('post', '/auth/logout');
    } finally {
      setUser(null);
    }
  },
  async refreshMe() {
    // A session check can be in flight while the user signs in. Ignore its
    // stale response so an earlier anonymous 401 cannot erase a newer login.
    const revisionAtStart = sessionRevision;
    try {
      const res = await get('/auth/me');
      if (sessionRevision !== revisionAtStart) return currentUser;
      setUser(res.data);
      return res.data;
    } catch (e) {
      if (e.status === 401 && sessionRevision === revisionAtStart) setUser(null);
      return null;
    }
  },
};
