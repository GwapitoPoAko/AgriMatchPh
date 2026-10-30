/**
 * IndexedDB persistence for offline-first data entry (via `idb`).
 *
 *  drafts  – harvest listing drafts a farmer is still editing (autosaved)
 *  outbox  – mutating API requests captured while offline, replayed in order
 *            by api.js when connectivity returns (each carries an
 *            X-Idempotency-Key so a replay can never create duplicates)
 *
 * Every call degrades gracefully: private windows or blocked storage simply
 * behave like an empty database instead of throwing into the UI.
 */
import { openDB } from 'idb';

const DB_NAME = 'agrimatch-offline';
const DB_VERSION = 1;

let dbPromise = null;

function db() {
  if (typeof indexedDB === 'undefined') return Promise.resolve(null);
  if (!dbPromise) {
    dbPromise = openDB(DB_NAME, DB_VERSION, {
      upgrade(database) {
        if (!database.objectStoreNames.contains('drafts')) {
          const drafts = database.createObjectStore('drafts', { keyPath: 'id' });
          drafts.createIndex('updatedAt', 'updatedAt');
        }
        if (!database.objectStoreNames.contains('outbox')) {
          const outbox = database.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
          outbox.createIndex('createdAt', 'createdAt');
        }
      },
    }).catch(() => null);
  }
  return dbPromise;
}

async function safely(fn, fallback) {
  try {
    const database = await db();
    return database ? await fn(database) : fallback;
  } catch {
    return fallback;
  }
}

/* ---------------- drafts ---------------------------------------------- */

export const drafts = {
  list: () => safely(async (d) => (await d.getAllFromIndex('drafts', 'updatedAt')).reverse(), []),
  get: (id) => safely((d) => d.get('drafts', id), null),
  save: (draft) => safely((d) => d.put('drafts', { ...draft, updatedAt: Date.now() }), null),
  remove: (id) => safely((d) => d.delete('drafts', id), null),
};

/* ---------------- outbox ---------------------------------------------- */

const listeners = new Set();
const emit = async () => {
  const count = await outbox.count();
  listeners.forEach((l) => l(count));
};

export const outbox = {
  /** @param {{method:string,url:string,data?:any,idempotencyKey:string,label?:string,draftId?:string}} entry */
  async add(entry) {
    const id = await safely((d) => d.add('outbox', { ...entry, attempts: 0, createdAt: Date.now() }), null);
    emit();
    return id;
  },
  all: () => safely((d) => d.getAllFromIndex('outbox', 'createdAt'), []),
  count: () => safely((d) => d.count('outbox'), 0),
  async remove(id) {
    await safely((d) => d.delete('outbox', id), null);
    emit();
  },
  async bump(id, error) {
    await safely(async (d) => {
      const item = await d.get('outbox', id);
      if (item) await d.put('outbox', { ...item, attempts: item.attempts + 1, lastError: error });
    }, null);
    emit();
  },
  subscribe(listener) {
    listeners.add(listener);
    outbox.count().then(listener);
    return () => listeners.delete(listener);
  },
};

export const newId = () =>
  typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
