import { useCallback, useEffect, useId, useRef, useState } from 'react';
import {
  Bell,
  BellRing,
  CheckCheck,
  ClipboardCheck,
  FileSignature,
  Fuel,
  Handshake,
  Loader2,
  MessageSquareText,
  TrendingUp,
  Truck,
  Users,
  X,
} from 'lucide-react';
import { api } from '../lib/api';
import { timeAgo } from './ui';

const POLL_MS = 15_000;

const TYPE_META = {
  offer: { icon: Handshake, tone: 'bg-amber-50 text-amber-700 ring-amber-600/15' },
  contract: { icon: FileSignature, tone: 'bg-emerald-50 text-emerald-800 ring-emerald-700/15' },
  coop_pool: { icon: Users, tone: 'bg-emerald-50 text-emerald-800 ring-emerald-700/15' },
  inspection: { icon: ClipboardCheck, tone: 'bg-sky-50 text-sky-700 ring-sky-600/15' },
  freight: { icon: Truck, tone: 'bg-slate-100 text-slate-700 ring-slate-500/15' },
  fuel: { icon: Fuel, tone: 'bg-amber-50 text-amber-700 ring-amber-600/15' },
  price: { icon: TrendingUp, tone: 'bg-rose-50 text-rose-700 ring-rose-600/15' },
};
const metaFor = (type = '') => TYPE_META[type.split('.')[0]] || { icon: BellRing, tone: 'bg-slate-100 text-slate-600 ring-slate-500/15' };

/** Map server action URLs to in-app views (hash routes). */
const VIEW_FOR_URL = { '/freight': 'freight', '/prices': 'prices', '/marketplace': 'marketplace', '/offers': 'marketplace', '/contracts': 'dashboard', '/inspections': 'inspections' };

/**
 * Bell + popover drawer. Desktop: anchored dropdown. Phones (<640px): a
 * full-width sheet pinned under the header so it never overflows a 360px screen.
 */
export default function NotificationBell({ onNavigate = () => {} }) {
  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState(0);
  const [items, setItems] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [filter, setFilter] = useState('all');
  const rootRef = useRef(null);
  const buttonRef = useRef(null);
  const panelId = useId();

  const refreshCount = useCallback(() => {
    api.unreadCount().then((r) => setUnread(r.unread_count)).catch(() => {});
  }, []);

  const load = useCallback(async (onlyUnread) => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.notifications({ per_page: 20, unread_only: onlyUnread });
      setItems(res.data);
      setUnread(res.unread_count);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  // Poll the unread badge while the tab is visible.
  useEffect(() => {
    refreshCount();
    const id = setInterval(() => document.visibilityState === 'visible' && refreshCount(), POLL_MS);
    const onFocus = () => refreshCount();
    window.addEventListener('focus', onFocus);
    return () => {
      clearInterval(id);
      window.removeEventListener('focus', onFocus);
    };
  }, [refreshCount]);

  useEffect(() => {
    if (open) load(filter === 'unread');
  }, [open, filter, load]);

  // Close on outside click / Escape; return focus to the bell.
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => rootRef.current && !rootRef.current.contains(e.target) && setOpen(false);
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  async function openItem(n) {
    if (!n.read_at) {
      setItems((list) => list.map((i) => (i.id === n.id ? { ...i, read_at: new Date().toISOString() } : i)));
      setUnread((u) => Math.max(0, u - 1));
      api.markNotificationRead(n.id).catch(() => {});
    }
    const view = VIEW_FOR_URL[n.action_url];
    if (view) {
      setOpen(false);
      onNavigate(view);
    }
  }

  async function markAll() {
    setItems((list) => list?.map((i) => ({ ...i, read_at: i.read_at || new Date().toISOString() })));
    setUnread(0);
    try {
      await api.markAllNotificationsRead();
    } catch {
      refreshCount();
    }
  }

  return (
    <div className="relative" ref={rootRef}>
      <button
        ref={buttonRef}
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-controls={panelId}
        aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
        className={`focus-ring relative grid h-10 w-10 place-items-center rounded-xl transition-colors ${open ? 'bg-slate-100 text-ink' : 'text-slate-500 hover:bg-slate-100 hover:text-ink'}`}
      >
        <Bell className={`h-[18px] w-[18px] ${unread ? 'origin-top animate-[wiggle_1.2s_ease-in-out_1]' : ''}`} />
        {unread > 0 && (
          <span className="num absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-amber-600 px-1 text-[10px] font-bold text-white ring-2 ring-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          id={panelId}
          role="dialog"
          aria-label="Notifications"
          className="fixed inset-x-2 top-[4.25rem] z-50 flex max-h-[calc(100dvh-5.5rem)] animate-scale-in flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-slate-900/15 ring-1 ring-slate-900/5 sm:absolute sm:inset-x-auto sm:right-0 sm:top-12 sm:w-[400px] sm:max-h-[min(560px,calc(100dvh-6rem))]"
        >
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <div>
              <h2 className="text-sm font-bold text-ink">Notifications</h2>
              <p className="hidden text-[11px] text-muted sm:block">Offers, contracts, pools & price alerts · also sent by SMS</p>
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={markAll}
                disabled={!unread}
                className="focus-ring inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40"
              >
                <CheckCheck className="h-3.5 w-3.5" /> <span className="whitespace-nowrap">Mark all read</span>
              </button>
              <button type="button" onClick={() => setOpen(false)} className="focus-ring rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 sm:hidden" aria-label="Close notifications">
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>

          <div className="segmented mx-4 mt-3 self-start" role="group" aria-label="Filter notifications">
            <button type="button" aria-pressed={filter === 'all'} onClick={() => setFilter('all')}>
              All
            </button>
            <button type="button" aria-pressed={filter === 'unread'} onClick={() => setFilter('unread')}>
              Unread{unread ? ` (${unread})` : ''}
            </button>
          </div>

          <ul className="scroll-thin mt-2 flex-1 divide-y divide-slate-100 overflow-y-auto pb-2" aria-live="polite">
            {loading && !items && (
              <li className="flex items-center justify-center gap-2 py-10 text-sm text-muted">
                <Loader2 className="h-4 w-4 animate-spin" /> Loading…
              </li>
            )}
            {error && <li className="px-4 py-6 text-center text-sm text-rose-600">{error}</li>}
            {items?.length === 0 && !loading && (
              <li className="px-6 py-12 text-center">
                <span className="mx-auto mb-3 grid h-10 w-10 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                  <Bell className="h-4 w-4" />
                </span>
                <p className="text-sm font-semibold text-ink">You&apos;re all caught up</p>
                <p className="mt-0.5 text-xs text-muted">New offers and price spikes will show up here.</p>
              </li>
            )}
            {items?.map((n) => {
              const meta = metaFor(n.type);
              const Icon = meta.icon;
              return (
                <li key={n.id}>
                  <button
                    type="button"
                    onClick={() => openItem(n)}
                    className={`focus-ring group flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-slate-50 ${n.read_at ? '' : 'bg-emerald-50/40'}`}
                  >
                    <span className={`mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-xl ring-1 ring-inset ${meta.tone}`}>
                      <Icon className="h-4 w-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-start justify-between gap-2">
                        <span className={`text-[13px] leading-snug ${n.read_at ? 'font-medium text-slate-700' : 'font-semibold text-ink'}`}>{n.title}</span>
                        {!n.read_at && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-green-500" aria-label="Unread" />}
                      </span>
                      <span className="mt-0.5 line-clamp-2 block text-xs text-muted">{n.body}</span>
                      <span className="mt-1.5 flex flex-wrap items-center gap-2 text-[10px] font-medium text-slate-400">
                        <span>{timeAgo(n.created_at)}</span>
                        {n.priority === 'high' && <span className="rounded bg-amber-100 px-1.5 py-px font-semibold text-amber-800">Action needed</span>}
                        {n.channels?.includes('sms') && (
                          <span
                            className={`inline-flex items-center gap-0.5 rounded px-1.5 py-px font-semibold ${
                              n.sms_status === 'sent' ? 'bg-green-50 text-green-700' : n.sms_status === 'failed' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'
                            }`}
                            title="Also delivered by SMS to basic phones"
                          >
                            <MessageSquareText className="h-2.5 w-2.5" /> SMS {n.sms_status || 'queued'}
                          </span>
                        )}
                      </span>
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}
