const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pesoWhole = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });
const number = new Intl.NumberFormat('en-PH');

export const formatPeso = (v) => (v == null || Number.isNaN(v) ? '—' : peso.format(v));

/** ₱1.2M, ₱845K — for headline KPIs. */
export function formatPesoCompact(v) {
  if (v == null) return '—';
  const abs = Math.abs(v);
  if (abs >= 1_000_000) return `₱${(v / 1_000_000).toFixed(abs >= 10_000_000 ? 1 : 2)}M`;
  if (abs >= 10_000) return `₱${(v / 1_000).toFixed(0)}K`;
  return pesoWhole.format(v);
}

/** 12,400 kg · 18.5 MT for large lots. */
export function formatVolume(kg, { compact = false } = {}) {
  if (kg == null) return '—';
  if (compact && kg >= 1000) {
    const mt = kg / 1000;
    return `${mt >= 100 ? mt.toFixed(0) : mt.toFixed(1)} MT`;
  }
  return `${number.format(Math.round(kg))} kg`;
}

export const formatNumber = (v) => (v == null ? '—' : number.format(v));

export function formatPct(v, { signed = true, digits = 1 } = {}) {
  if (v == null || Number.isNaN(v)) return '—';
  const s = Math.abs(v).toFixed(digits);
  if (!signed) return `${s}%`;
  return `${v > 0 ? '+' : v < 0 ? '−' : ''}${s}%`;
}

const dShort = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' });
const dLong = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
const dWeekday = new Intl.DateTimeFormat('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

/** Parse YYYY-MM-DD as a local calendar date (avoids UTC off-by-one). */
export function parseDate(iso) {
  if (!iso) return null;
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
  return new Date(y, m - 1, d);
}

export const formatDate = (iso) => (iso ? dLong.format(parseDate(iso)) : '—');
export const formatDateShort = (iso) => (iso ? dShort.format(parseDate(iso)) : '—');
export const formatDateWeekday = (iso) => (iso ? dWeekday.format(parseDate(iso)) : '—');

export function formatDateRange(start, end) {
  if (!start) return '—';
  const s = parseDate(start);
  const e = parseDate(end);
  if (!e || s.getTime() === e.getTime()) return dLong.format(s);
  if (s.getFullYear() === e.getFullYear()) return `${dShort.format(s)} – ${dLong.format(e)}`;
  return `${dLong.format(s)} – ${dLong.format(e)}`;
}

export function daysBetween(fromIso, toIso) {
  const a = parseDate(fromIso);
  const b = parseDate(toIso);
  return Math.round((b - a) / 86_400_000);
}

export function toIsoDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function initials(name = '') {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');
}
