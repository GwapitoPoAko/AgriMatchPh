import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowDownRight, ArrowUpRight, BadgeCheck, ClipboardCheck, Fuel, Minus, ShieldCheck, TrendingDown, TrendingUp, Users, X } from 'lucide-react';
import { formatPct } from '../lib/format';

/* ------------------------------------------------------------------ */
/* Data hook                                                           */
/* ------------------------------------------------------------------ */

/**
 * Runs an async loader whenever `deps` change; aborts stale requests.
 * Keeps the previous data while reloading so cards don't flash empty.
 */
export function useAsync(loader, deps, refreshMs = 0) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    loader(controller.signal)
      .then((data) => !controller.signal.aborted && setState({ data, error: null, loading: false }))
      .catch((error) => {
        if (error?.name === 'AbortError' || controller.signal.aborted) return;
        setState((s) => ({ ...s, error, loading: false }));
      });
    const refresh = refreshMs > 0 ? window.setInterval(() => setTick((value) => value + 1), refreshMs) : null;
    return () => {
      controller.abort();
      if (refresh !== null) window.clearInterval(refresh);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick, refreshMs]);

  return { ...state, reload: () => setTick((t) => t + 1) };
}

/* ------------------------------------------------------------------ */
/* Sparkline                                                           */
/* ------------------------------------------------------------------ */

export function Sparkline({ values = [], width = 120, height = 36, tone = 'emerald', strokeWidth = 1.75, className = '' }) {
  const id = useId().replace(/:/g, '');
  const clean = values.filter((v) => v != null);
  if (clean.length < 2) return <svg width={width} height={height} className={className} aria-hidden="true" />;

  const colors = {
    emerald: '#16a34a',
    amber: '#d97706',
    rose: '#e11d48',
    slate: '#94a3b8',
  };
  const color = colors[tone] || colors.emerald;
  const min = Math.min(...clean);
  const max = Math.max(...clean);
  const span = max - min || 1;
  const pad = 2;
  const step = (width - pad * 2) / (clean.length - 1);
  const pts = clean.map((v, i) => [pad + i * step, pad + (height - pad * 2) * (1 - (v - min) / span)]);

  // Smooth with a light Catmull-Rom → Bézier conversion.
  const d = pts.reduce((acc, [x, y], i, arr) => {
    if (i === 0) return `M${x},${y}`;
    const [x0, y0] = arr[i - 2] || arr[i - 1];
    const [x1, y1] = arr[i - 1];
    const [x3, y3] = arr[i + 1] || [x, y];
    const c1x = x1 + (x - x0) / 6;
    const c1y = y1 + (y - y0) / 6;
    const c2x = x - (x3 - x1) / 6;
    const c2y = y - (y3 - y1) / 6;
    return `${acc} C${c1x},${c1y} ${c2x},${c2y} ${x},${y}`;
  }, '');
  const [lx, ly] = pts[pts.length - 1];

  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} className={className} aria-hidden="true">
      <defs>
        <linearGradient id={`sg-${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.22" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={`${d} L${lx},${height} L${pts[0][0]},${height} Z`} fill={`url(#sg-${id})`} />
      <path d={d} fill="none" stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={lx} cy={ly} r="2.5" fill="#fff" stroke={color} strokeWidth="1.5" />
    </svg>
  );
}

/* ------------------------------------------------------------------ */
/* Badges & pills                                                      */
/* ------------------------------------------------------------------ */

const STATUS_STYLES = {
  active: { label: 'Harvesting', dot: 'bg-green-500', cls: 'bg-green-50 text-green-700 ring-green-600/15' },
  upcoming: { label: 'Upcoming', dot: 'bg-amber-500', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  reserved: { label: 'Reserved', dot: 'bg-sky-500', cls: 'bg-sky-50 text-sky-700 ring-sky-600/15' },
  sold_out: { label: 'Sold out', dot: 'bg-slate-400', cls: 'bg-slate-100 text-slate-600 ring-slate-500/15' },
  expired: { label: 'Expired', dot: 'bg-slate-300', cls: 'bg-slate-50 text-slate-500 ring-slate-400/15' },
  draft: { label: 'Draft', dot: 'bg-slate-300', cls: 'bg-slate-50 text-slate-500 ring-slate-400/15' },
  pooled: { label: 'In coop pool', dot: 'bg-emerald-600', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-700/15' },
  forming: { label: 'Forming', dot: 'bg-amber-500', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  confirmed: { label: 'Confirmed', dot: 'bg-green-500', cls: 'bg-green-50 text-green-700 ring-green-600/15' },
  in_transit: { label: 'In transit', dot: 'bg-sky-500', cls: 'bg-sky-50 text-sky-700 ring-sky-600/15' },
  delivered: { label: 'Delivered', dot: 'bg-slate-400', cls: 'bg-slate-100 text-slate-600 ring-slate-500/15' },
  pending: { label: 'Pending', dot: 'bg-amber-500', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
};

export function StatusPill({ status, pulse = false, className = '' }) {
  const s = STATUS_STYLES[status] || STATUS_STYLES.draft;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${s.cls} ${className}`}>
      <span className="relative flex h-1.5 w-1.5">
        {pulse && status === 'active' && <span className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-60 ${s.dot}`} />}
        <span className={`relative inline-flex h-1.5 w-1.5 rounded-full ${s.dot}`} />
      </span>
      {s.label}
    </span>
  );
}

export function VerifiedBadge({ compact = false }) {
  return (
    <span
      className="inline-flex items-center gap-1 rounded-full bg-emerald-800/[0.06] px-2 py-0.5 text-[11px] font-semibold text-emerald-800"
      title="Identity, farm location and harvest volume verified by AgriMatch field officers"
    >
      <BadgeCheck className="h-3.5 w-3.5 text-green-600" strokeWidth={2.25} />
      {!compact && 'Verified'}
    </span>
  );
}

export function GradeBadge({ grade }) {
  const tone = { A: 'text-emerald-800 bg-emerald-50', B: 'text-amber-700 bg-amber-50', C: 'text-slate-600 bg-slate-100' }[grade];
  return <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-bold ${tone}`}>Grade {grade}</span>;
}

export function TrendDelta({ value, suffix = '', invert = false, className = '' }) {
  if (value == null) return <span className={`text-xs text-slate-400 ${className}`}>—</span>;
  const up = value > 0.005;
  const down = value < -0.005;
  // For buyers rising prices are "bad"; `invert` lets a card flip the colour semantics.
  const good = invert ? down : up;
  const bad = invert ? up : down;
  const Icon = up ? ArrowUpRight : down ? ArrowDownRight : Minus;
  const tone = good ? 'text-green-700 bg-green-50' : bad ? 'text-rose-600 bg-rose-50' : 'text-slate-500 bg-slate-100';
  return (
    <span className={`num inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold ${tone} ${className}`}>
      <Icon className="h-3.5 w-3.5" strokeWidth={2.5} />
      {formatPct(Math.abs(value), { signed: false })}
      {suffix}
    </span>
  );
}

export function Skeleton({ className = '' }) {
  return (
    <div className={`relative overflow-hidden rounded-lg bg-slate-100 ${className}`}>
      <div className="absolute inset-0 -translate-x-full animate-shimmer bg-gradient-to-r from-transparent via-white/70 to-transparent" />
    </div>
  );
}

export function EmptyState({ icon: Icon, title, children, action }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
      {Icon && (
        <div className="mb-4 grid h-12 w-12 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 ring-8 ring-emerald-50/50">
          <Icon className="h-5 w-5" />
        </div>
      )}
      <h3 className="text-base font-semibold text-ink">{title}</h3>
      {children && <p className="mt-1 max-w-sm text-sm text-muted">{children}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export function ErrorNote({ error, onRetry }) {
  if (!error) return null;
  return (
    <div className="flex items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      <span>{error.message || 'Something went wrong.'}</span>
      {onRetry && (
        <button type="button" onClick={onRetry} className="font-semibold underline-offset-2 hover:underline">
          Retry
        </button>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Modal                                                               */
/* ------------------------------------------------------------------ */

export function Modal({ open, onClose, title, subtitle, children, footer, size = 'max-w-xl' }) {
  const panelRef = useRef(null);
  const onCloseRef = useRef(onClose);
  const titleId = useId();
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) return undefined;
    const prevFocus = document.activeElement;
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const onKey = (e) => {
      if (e.key === 'Escape') onCloseRef.current();
      if (e.key === 'Tab' && panelRef.current) {
        const focusables = panelRef.current.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        const list = [...focusables].filter((el) => !el.disabled);
        if (!list.length) return;
        const first = list[0];
        const last = list[list.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(() => {
      const panel = panelRef.current;
      (panel?.querySelector('input:not([type=hidden]):not(.sr-only), select, textarea') || panel?.querySelector('button'))?.focus();
    });

    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
      prevFocus?.focus?.();
    };
  }, [open]);

  if (!open) return null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center p-2 sm:items-center sm:p-6">
      <div className="absolute inset-0 animate-fade-up bg-slate-900/40 backdrop-blur-[3px]" onClick={onClose} aria-hidden="true" />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={`relative flex max-h-[calc(100dvh-1rem)] w-full ${size} animate-scale-in flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5 sm:max-h-[92dvh] sm:rounded-3xl`}
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-4 pb-4 pt-5 sm:px-6">
          <div className="min-w-0">
            <h2 id={titleId} className="text-lg font-bold text-ink">
              {title}
            </h2>
            {subtitle && <div className="mt-0.5 text-sm text-muted">{subtitle}</div>}
          </div>
          <button type="button" onClick={onClose} className="focus-ring -mr-2 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>
        <div className="scroll-thin flex-1 overflow-y-auto px-4 py-5 sm:px-6">{children}</div>
        {footer && <div className="border-t border-slate-100 bg-slate-50/60 px-4 py-4 sm:px-6">{footer}</div>}
      </div>
    </div>,
    document.body,
  );
}

export function Toast({ toast, onDismiss }) {
  useEffect(() => {
    if (!toast) return undefined;
    const t = setTimeout(onDismiss, 4200);
    return () => clearTimeout(t);
  }, [toast, onDismiss]);

  if (!toast) return null;
  return createPortal(
    <div className="fixed bottom-6 left-1/2 z-[60] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 animate-scale-in">
      <div className="flex items-start gap-3 rounded-2xl bg-ink/95 px-4 py-3.5 text-sm text-white shadow-lift ring-1 ring-white/10 backdrop-blur">
        <span className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-green-500">
          <BadgeCheck className="h-3.5 w-3.5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{toast.title}</p>
          {toast.body && <p className="mt-0.5 text-white/70">{toast.body}</p>}
        </div>
        <button type="button" onClick={onDismiss} className="text-white/50 hover:text-white" aria-label="Dismiss">
          <X className="h-4 w-4" />
        </button>
      </div>
    </div>,
    document.body,
  );
}

/* ------------------------------------------------------------------ */
/* Quality, coop & market-signal badges                                */
/* ------------------------------------------------------------------ */

const CERT_STYLES = {
  export: { label: 'Export Quality', cls: 'bg-gradient-to-r from-emerald-800 to-emerald-700 text-white ring-emerald-900/20 shadow-sm shadow-emerald-900/20' },
  grade_a: { label: 'Grade A', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-700/20' },
  class_b: { label: 'Class B', cls: 'bg-amber-50 text-amber-800 ring-amber-600/20' },
  class_c: { label: 'Class C', cls: 'bg-slate-100 text-slate-700 ring-slate-500/20' },
  rejected: { label: 'Failed inspection', cls: 'bg-rose-50 text-rose-700 ring-rose-600/20' },
};

/** Official grade from the inspection workflow (vs. the seller-declared GradeBadge). */
export function CertifiedBadge({ quality, compact = false }) {
  if (!quality) return null;
  if (quality.certified_grade && CERT_STYLES[quality.certified_grade]) {
    const s = CERT_STYLES[quality.certified_grade];
    const Icon = quality.certified_grade === 'rejected' ? X : ShieldCheck;
    return (
      <span
        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${s.cls}`}
        title={quality.certified_grade === 'rejected' ? 'Did not pass the last quality inspection' : `Certified ${s.label} by an AgriMatch quality technician`}
      >
        <Icon className="h-3 w-3" strokeWidth={2.5} />
        {!compact && s.label}
      </span>
    );
  }
  if (quality.inspection_status === 'pending') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/15" title="Inspection requested — awaiting a quality technician">
        <ClipboardCheck className="h-3 w-3" />
        {!compact && 'Inspection pending'}
      </span>
    );
  }
  return null;
}

export function CoopTag({ coop }) {
  if (!coop) return null;
  if (coop.is_pool_parent) {
    return (
      <span
        className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20"
        title={`${coop.cooperative?.name ?? 'Cooperative'} — one contract, pro-rata payout to member farmers`}
      >
        <Users className="h-3 w-3" />
        Coop pool{coop.member_count ? ` · ${coop.member_count} farmers` : ''}
      </span>
    );
  }
  if (coop.cooperative) {
    return (
      <span className="inline-flex max-w-[180px] items-center gap-1 truncate rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600" title={coop.cooperative.name}>
        <Users className="h-3 w-3 shrink-0" />
        <span className="truncate">Coop member</span>
      </span>
    );
  }
  return null;
}

const SIGNAL_STYLES = {
  spike: { cls: 'border-rose-200 bg-rose-50 text-rose-700', icon: TrendingUp },
  rising: { cls: 'border-amber-200 bg-amber-50 text-amber-800', icon: TrendingUp },
  falling: { cls: 'border-emerald-200 bg-emerald-50 text-emerald-800', icon: TrendingDown },
  drop: { cls: 'border-emerald-300 bg-emerald-50 text-emerald-800', icon: TrendingDown },
};

/** "+12% market spike at Agora CDO" — hub price-index warning for a listing. */
export function MarketSignalBadge({ signal, variant = 'strip', className = '' }) {
  if (!signal?.label || !SIGNAL_STYLES[signal.signal]) return null;
  const s = SIGNAL_STYLES[signal.signal];
  const Icon = s.icon;
  if (variant === 'pill') {
    return (
      <span className={`num inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${s.cls} ${className}`}>
        <Icon className="h-3 w-3" strokeWidth={2.5} />
        {signal.label}
      </span>
    );
  }
  return (
    <div className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-xs ${s.cls} ${className}`} role="status">
      <Icon className="h-3.5 w-3.5 shrink-0" strokeWidth={2.5} />
      <span className="num shrink-0 whitespace-nowrap font-semibold">{signal.label}</span>
      <span className="min-w-0 truncate opacity-80">at {signal.hub}</span>
    </div>
  );
}

/** "+5% fuel surcharge applied" — dynamic fuel surcharge matrix result. */
export function FuelSurchargeBadge({ label, pct, className = '' }) {
  if (!label) return null;
  const rebate = pct < 0;
  return (
    <span
      className={`num inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${
        rebate ? 'bg-emerald-50 text-emerald-800 ring-emerald-700/20' : 'bg-amber-50 text-amber-800 ring-amber-600/25'
      } ${className}`}
    >
      <Fuel className="h-3 w-3" />
      {label}
    </span>
  );
}

/** Relative time ("5m ago", "3d ago") for notification lists. */
export function timeAgo(iso) {
  if (!iso) return '';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 86400 * 7) return `${Math.floor(diff / 86400)}d ago`;
  return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}
