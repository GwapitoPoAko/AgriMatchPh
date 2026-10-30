import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Apple,
  CalendarClock,
  Carrot,
  ChevronLeft,
  ChevronRight,
  Eye,
  Handshake,
  Leaf,
  Loader2,
  MapPin,
  Mountain,
  Search,
  SlidersHorizontal,
  Sprout,
  Star,
  Store,
  Truck,
  Users,
  Wheat,
  X,
} from 'lucide-react';
import { ApiError, api, useSession } from '../lib/api';
import {
  daysBetween,
  formatDateRange,
  formatNumber,
  formatPeso,
  formatPct,
  formatVolume,
  initials,
  toIsoDate,
} from '../lib/format';
import { CertifiedBadge, CoopTag, EmptyState, ErrorNote, GradeBadge, MarketSignalBadge, Modal, Skeleton, StatusPill, Toast, VerifiedBadge, useAsync } from './ui';

const CATEGORY_ICONS = { sprout: Sprout, leaf: Leaf, mountain: Mountain, apple: Apple, wheat: Wheat, carrot: Carrot };

const STATUS_TABS = [
  { value: 'tradable', label: 'All open', statuses: ['active', 'upcoming'] },
  { value: 'active', label: 'Harvesting now', statuses: ['active'] },
  { value: 'upcoming', label: 'Upcoming', statuses: ['upcoming'] },
  { value: 'reserved', label: 'Reserved', statuses: ['reserved'] },
  { value: 'sold_out', label: 'Out of stock', statuses: ['sold_out'] },
];

const SORTS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'harvest_soonest', label: 'Harvest soonest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
  { value: 'volume_desc', label: 'Largest volume' },
  { value: 'popular', label: 'Most viewed' },
];

const VOLUME_PRESETS = [
  { value: '', label: 'Any volume' },
  { value: '1000', label: '1 MT +' },
  { value: '5000', label: '5 MT +' },
  { value: '20000', label: '20 MT +' },
  { value: '100000', label: '100 MT +' },
];

const DEFAULT_FILTERS = {
  q: '',
  province_id: '',
  crop_variety_id: '',
  harvest_from: '',
  harvest_to: '',
  min_volume: '',
  verified_only: false,
  organic_only: false,
  certified_only: false,
  coop_pools_only: false,
  sort: 'newest',
};

function useDebounced(value, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/* ------------------------------------------------------------------ */
/* Listing card                                                         */
/* ------------------------------------------------------------------ */

function harvestTiming(listing) {
  const today = toIsoDate(new Date());
  const toStart = daysBetween(today, listing.harvest_date_start);
  const toEnd = daysBetween(today, listing.harvest_date_end);
  if (toStart > 0) return { text: `Harvest starts in ${toStart} day${toStart === 1 ? '' : 's'}`, tone: 'text-amber-700' };
  if (toEnd >= 0) return { text: toEnd === 0 ? 'Harvest window closes today' : `${toEnd} day${toEnd === 1 ? '' : 's'} left in window`, tone: 'text-green-700' };
  return { text: 'Stored stock — harvest completed', tone: 'text-slate-500' };
}

function ListingCard({ listing, category, onOffer, onLocation, index }) {
  const Icon = CATEGORY_ICONS[category?.icon] || Sprout;
  const color = category?.color_hex || '#15803d';
  const timing = harvestTiming(listing);
  const tradable = ['active', 'upcoming'].includes(listing.status) && listing.available_volume_kg > 0;
  const outOfStock = Number(listing.available_volume_kg) <= 0 || listing.status === 'sold_out';
  const availablePct = listing.stock_volume_kg ? (listing.available_volume_kg / listing.stock_volume_kg) * 100 : 0;

  return (
    <article
      className={`group flex animate-fade-up flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-card transition-all duration-300 ${outOfStock ? 'grayscale-[.7] opacity-75' : 'hover:-translate-y-1 hover:border-slate-300/80 hover:shadow-lift'}`}
      style={{ animationDelay: `${Math.min(index, 8) * 40}ms` }}
    >
      {/* Crop header */}
      <div className="relative px-5 pb-4 pt-5" style={{ background: `linear-gradient(135deg, ${color}14 0%, ${color}05 55%, transparent 100%)` }}>
        {listing.image_url && <img src={listing.image_url} alt={`${listing.crop.variety} harvest`} loading="lazy" className="mb-3 h-36 w-full rounded-xl object-cover" />}
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <span
              className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white shadow-sm ring-1 ring-slate-900/5 transition-transform duration-300 group-hover:scale-105"
              style={{ color }}
            >
              <Icon className="h-5 w-5" strokeWidth={2} />
            </span>
            <div className="min-w-0">
              <h3 className="truncate text-[15px] font-bold text-ink">{listing.crop.variety}</h3>
              <p className="truncate text-xs text-muted">
                {listing.crop.local_name} · {listing.crop.category}
              </p>
            </div>
          </div>
          {outOfStock ? <span className="rounded-full bg-rose-700 px-3 py-1.5 text-[11px] font-extrabold uppercase tracking-wide text-white shadow">Out of Stock</span> : <StatusPill status={listing.status} pulse />}
        </div>
        <p className="mt-3 line-clamp-1 text-[13px] text-slate-600" title={listing.title}>
          {listing.title.split(' — ')[1] ?? listing.title}
        </p>
        <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
          <CertifiedBadge quality={listing.quality} />
          {listing.is_verified && <VerifiedBadge compact={Boolean(listing.quality?.certified_grade)} />}
          <CoopTag coop={listing.coop} />
          {!listing.quality?.certified_grade && <GradeBadge grade={listing.quality_grade} />}
          {listing.is_organic && <span className="rounded-md bg-lime-50 px-1.5 py-0.5 text-[11px] font-bold text-lime-700">Organic</span>}
          <span className="font-mono text-[10px] text-slate-400">{listing.reference_code}</span>
        </div>
      </div>

      {/* Body */}
      <div className="flex flex-1 flex-col gap-4 px-5 pb-5">
        <div className="flex items-end justify-between border-t border-slate-100 pt-4">
          <div>
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Asking price</p>
            <p className="num mt-0.5 font-display text-2xl font-bold text-ink">
              {formatPeso(listing.unit_price)}
              <span className="ml-0.5 text-sm font-medium text-muted">/kg</span>
            </p>
          </div>
          <div className="flex flex-col items-end gap-1">
            {listing.price_negotiable && (
              <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/15">Negotiable</span>
            )}
            {listing.market_signal?.vs_index_pct != null && (
              <span className="num text-[11px] text-muted" title={`${listing.market_signal.hub} hub index ₱${listing.market_signal.index_price}/kg`}>
                {listing.market_signal.vs_index_pct > 0 ? '+' : ''}
                {listing.market_signal.vs_index_pct}% vs {listing.market_signal.hub}
              </span>
            )}
          </div>
        </div>

        <MarketSignalBadge signal={listing.market_signal} />

        <div>
          <div className="flex items-baseline justify-between text-xs">
            <span className="num font-semibold text-slate-800">{formatVolume(listing.available_volume_kg)} available</span>
            <span className="num text-slate-400">of {formatVolume(listing.stock_volume_kg, { compact: true })}</span>
          </div>
          <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-gradient-to-r from-emerald-700 to-green-500 transition-[width] duration-700"
              style={{ width: `${Math.max(availablePct, listing.available_volume_kg > 0 ? 3 : 0)}%` }}
            />
          </div>
          <p className="num mt-1 text-[11px] text-slate-400">Min. order {formatVolume(listing.minimum_order_kg)}</p>
        </div>

        <dl className="space-y-2 text-xs">
          <div className="flex items-start gap-2">
            <CalendarClock className="mt-px h-3.5 w-3.5 shrink-0 text-slate-400" />
            <div>
              <dt className="sr-only">Harvest window</dt>
              <dd className="font-medium text-slate-700">{formatDateRange(listing.harvest_date_start, listing.harvest_date_end)}</dd>
              <dd className={`mt-0.5 ${timing.tone}`}>{timing.text}</dd>
            </div>
          </div>
          <div className="flex items-start gap-2">
            <MapPin className="mt-px h-3.5 w-3.5 shrink-0 text-slate-400" />
            <div>
              <dt className="sr-only">Location</dt>
              <dd className="text-slate-700">
                Brgy. {listing.location.barangay}, {listing.location.municipality}
              </dd>
              <dd className="text-slate-400">
                {listing.location.province} · {listing.location.region}
              </dd>
            </div>
          </div>
        </dl>

        {/* Seller */}
        <div className="mt-auto flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-emerald-700 to-emerald-900 text-[11px] font-bold text-white">
            {initials(listing.seller.business_name || listing.seller.name)}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-xs font-semibold text-slate-800">{listing.seller.business_name || listing.seller.name}</p>
            <p className="flex items-center gap-1 text-[11px] text-muted">
              <Star className="h-3 w-3 fill-amber-400 text-amber-400" />
              <span className="num font-semibold text-slate-600">{listing.seller.rating?.toFixed(1) ?? '—'}</span>
              <span>· {formatNumber(listing.seller.total_transactions)} deals · {listing.seller.role}</span>
            </p>
          </div>
        </div>

        <div className="flex items-center justify-between gap-2">
          <span className="flex items-center gap-1.5 text-[11px] text-slate-500">
            {listing.pending_offers_count > 0 ? (
              <>
                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                <span className="font-medium text-amber-700">
                  {listing.pending_offers_count} buyer{listing.pending_offers_count === 1 ? '' : 's'} negotiating
                </span>
              </>
            ) : (
              <>
                <Eye className="h-3.5 w-3.5" /> {formatNumber(listing.views_count)} views
              </>
            )}
          </span>
          <div className="flex shrink-0 gap-2">
            <button type="button" className="btn-ghost px-2.5 py-2 text-xs" onClick={() => onLocation(listing)}>
              <MapPin className="h-3.5 w-3.5" /> Farm pin
            </button>
            <button type="button" className="btn-action px-3 py-2 text-xs" disabled={!tradable} onClick={() => onOffer(listing)}>
              <Handshake className="h-3.5 w-3.5" />
              {outOfStock ? 'Out of Stock' : tradable ? 'Make offer' : 'Unavailable'}
            </button>
          </div>
        </div>
      </div>
    </article>
  );
}

function FarmLocationDialog({ listing, onClose }) {
  const user = useSession();
  const detail = useAsync((signal) => (listing && user ? api.listing(listing.id, { signal }) : Promise.resolve(null)), [listing?.id, user?.id]);
  if (!listing) return null;

  const record = detail.data?.data ?? detail.data;
  const location = record?.location ?? listing.location;
  const point = location?.farm_coordinates;
  const hub = location?.trading_hub;
  const mapUrl = point
    ? `https://www.openstreetmap.org/export/embed.html?bbox=${point.longitude - 0.025}%2C${point.latitude - 0.018}%2C${point.longitude + 0.025}%2C${point.latitude + 0.018}&layer=mapnik&marker=${point.latitude}%2C${point.longitude}`
    : null;

  return (
    <Modal open={Boolean(listing)} onClose={onClose} size="max-w-2xl" title="Farm location" subtitle={`${listing.location.barangay}, ${listing.location.municipality} · ${listing.location.province}`}>
      {detail.loading ? (
        <Skeleton className="h-72 w-full" />
      ) : point ? (
        <div className="space-y-3">
          <iframe title={`Farm map for ${listing.reference_code}`} src={mapUrl} className="h-72 w-full rounded-xl border border-slate-200" loading="lazy" referrerPolicy="no-referrer" />
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span className="font-mono text-slate-600">{point.latitude.toFixed(6)}, {point.longitude.toFixed(6)}</span>
            <span className="text-slate-600">
              {hub ? `${hub.short_name}: ${location.distance_to_hub_km ?? '—'} km estimated road distance` : 'No trading hub is assigned yet'}
            </span>
          </div>
          <p className="text-xs text-muted">Exact pickup coordinates are shown only to signed-in buyers, traders, the listing owner, and platform admins.</p>
        </div>
      ) : (
        <div className="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">
          {!user ? 'Sign in as a buyer to view the farm pin.' : 'This listing does not have a precise pickup point yet.'}
        </div>
      )}
    </Modal>
  );
}

function CardSkeleton() {
  return (
    <div className="card space-y-4 p-5">
      <div className="flex gap-3">
        <Skeleton className="h-11 w-11 rounded-2xl" />
        <div className="flex-1 space-y-2">
          <Skeleton className="h-4 w-3/4" />
          <Skeleton className="h-3 w-1/2" />
        </div>
      </div>
      <Skeleton className="h-3 w-full" />
      <Skeleton className="h-8 w-28" />
      <Skeleton className="h-2 w-full rounded-full" />
      <Skeleton className="h-12 w-full rounded-xl" />
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Offer / negotiation modal                                           */
/* ------------------------------------------------------------------ */

const DELIVERY_TERMS = [
  { value: 'farm_gate', label: 'Farm gate', icon: Sprout, hint: 'You arrange pickup' },
  { value: 'delivered', label: 'Delivered', icon: Truck, hint: 'Seller delivers to you' },
  { value: 'pickup_hub', label: 'Pickup hub', icon: Store, hint: 'Consolidation point' },
];

const PAYMENT_TERMS = [
  { value: 'cod', label: 'Cash on delivery' },
  { value: 'net_15', label: 'Net 15 days' },
  { value: 'net_30', label: 'Net 30 days' },
  { value: 'lc_at_sight', label: 'Letter of credit at sight' },
];

function OfferModal({ listing, onClose, onSubmitted }) {
  const user = useSession();
  const open = Boolean(listing);
  const minVol = listing ? Math.max(listing.minimum_order_kg, 1) : 1;
  const maxVol = listing?.available_volume_kg ?? 1;

  const [form, setForm] = useState(null);
  const [auth, setAuth] = useState({ email: 'procurement@gaisanofresh.test', password: 'password' });
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (!listing) return;
    const suggested = Math.min(maxVol, Math.max(minVol, Math.round((maxVol * 0.25) / 50) * 50));
    const deliver = new Date();
    deliver.setDate(deliver.getDate() + 5);
    const harvestStart = new Date(`${listing.harvest_date_start}T00:00:00`);
    setForm({
      volume_kg: suggested,
      unit_price: listing.unit_price,
      delivery_terms: 'farm_gate',
      delivery_date: toIsoDate(harvestStart > deliver ? harvestStart : deliver),
      payment_terms: 'net_15',
      message: '',
    });
    setErrors({});
    setFormError(null);
  }, [listing, maxVol, minVol]);

  const detail = useAsync((signal) => (listing ? api.listing(listing.id, { signal }) : Promise.resolve(null)), [listing?.id]);
  const market = detail.data?.meta?.market_comparison;

  if (!open || !form) return null;

  const set = (key) => (e) => {
    const value = e?.target ? e.target.value : e;
    setForm((f) => ({ ...f, [key]: value }));
    setErrors((er) => ({ ...er, [key]: undefined }));
  };

  const volume = Number(form.volume_kg) || 0;
  const price = Number(form.unit_price) || 0;
  const total = volume * price;
  const vsAsking = listing.unit_price ? (price / listing.unit_price - 1) * 100 : null;
  const vsIndex = market?.farmgate_price ? (price / market.farmgate_price - 1) * 100 : null;
  const needsLogin = !user;
  const clientInvalid = volume < minVol || volume > maxVol || price < 0.5;

  const pricePresets = [
    { label: 'Asking', value: listing.unit_price },
    { label: '−3%', value: listing.unit_price * 0.97 },
    { label: '−5%', value: listing.unit_price * 0.95 },
    ...(market?.farmgate_price ? [{ label: 'Index', value: market.farmgate_price }] : []),
  ];
  const volumePresets = [
    { label: 'Min', value: minVol },
    { label: '25%', value: Math.max(minVol, Math.round((maxVol * 0.25) / 50) * 50) },
    { label: '50%', value: Math.max(minVol, Math.round((maxVol * 0.5) / 50) * 50) },
    { label: 'All', value: maxVol },
  ];

  async function submit(e) {
    e.preventDefault();
    setSubmitting(true);
    setFormError(null);
    try {
      if (needsLogin) await api.login(auth.email, auth.password);
      const res = await api.createOffer({
        harvest_listing_id: listing.id,
        volume_kg: volume,
        unit_price: Math.round(price * 100) / 100,
        delivery_terms: form.delivery_terms,
        delivery_date: form.delivery_date || null,
        payment_terms: form.payment_terms,
        message: form.message || null,
      });
      onSubmitted(res.data, listing);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(Object.fromEntries(Object.entries(err.errors || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
        if (!Object.keys(err.errors || {}).length) setFormError(err.message);
      } else {
        setFormError(err.message || 'Could not send your offer.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  const fieldError = (key) => errors[key] && <p className="mt-1.5 text-xs font-medium text-rose-600">{errors[key]}</p>;

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="max-w-2xl"
      title="Make an offer"
      subtitle={
        <span className="flex flex-wrap items-center gap-x-2">
          <span className="font-medium text-slate-700">{listing.crop.variety}</span>
          <span className="text-slate-300">•</span>
          <span>{listing.seller.business_name}</span>
          <span className="text-slate-300">•</span>
          <span className="font-mono text-xs">{listing.reference_code}</span>
        </span>
      }
      footer={
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Offer total</p>
            <p className="num font-display text-2xl font-bold text-ink">{formatPeso(total)}</p>
          </div>
          <div className="flex gap-2">
            <button type="button" className="btn-ghost" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" form="offer-form" className="btn-primary min-w-[150px]" disabled={submitting || clientInvalid}>
              {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Handshake className="h-4 w-4" />}
              {submitting ? 'Sending…' : 'Send offer'}
            </button>
          </div>
        </div>
      }
    >
      <form id="offer-form" onSubmit={submit} className="space-y-6" noValidate>
        {(listing.market_signal?.label || listing.coop?.is_pool_parent || listing.quality?.certified_grade) && (
          <div className="space-y-2">
            <MarketSignalBadge signal={listing.market_signal} />
            {listing.coop?.is_pool_parent && (
              <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs text-amber-900">
                <Users className="mt-px h-3.5 w-3.5 shrink-0" />
                <span>
                  <b>Cooperative pool</b> — {detail.data?.meta?.coop_pool?.member_lots ?? listing.coop.member_count} member lots from{' '}
                  {detail.data?.meta?.coop_pool?.barangays?.join(', ') || listing.location.municipality}. One contract; the coop pays members pro-rata.
                </span>
              </div>
            )}
            {listing.quality?.latest_inspection?.certificate_no && (
              <p className="text-[11px] text-muted">
                Certificate {listing.quality.latest_inspection.certificate_no}
                {listing.quality.latest_inspection.valid_until && ` · valid until ${listing.quality.latest_inspection.valid_until}`}
              </p>
            )}
          </div>
        )}
        {/* Market context */}
        <div className="grid grid-cols-3 gap-px overflow-hidden rounded-2xl border border-slate-200/70 bg-slate-200/70">
          <div className="bg-white p-3.5">
            <p className="text-[11px] text-muted">Asking</p>
            <p className="num mt-0.5 text-base font-bold text-ink">{formatPeso(listing.unit_price)}</p>
          </div>
          <div className="bg-white p-3.5">
            <p className="text-[11px] text-muted">Farm-gate index</p>
            {detail.loading ? <Skeleton className="mt-1.5 h-5 w-16" /> : <p className="num mt-0.5 text-base font-bold text-amber-600">{formatPeso(market?.farmgate_price)}</p>}
          </div>
          <div className="bg-white p-3.5">
            <p className="text-[11px] text-muted">Available</p>
            <p className="num mt-0.5 text-base font-bold text-ink">{formatVolume(maxVol, { compact: true })}</p>
          </div>
        </div>

        {/* Volume */}
        <div>
          <div className="flex items-center justify-between">
            <label htmlFor="offer-volume" className="label mb-0">
              Volume
            </label>
            <div className="flex gap-1">
              {volumePresets.map((p) => (
                <button
                  key={p.label}
                  type="button"
                  onClick={() => set('volume_kg')(p.value)}
                  className={`focus-ring rounded-md px-2 py-0.5 text-[11px] font-semibold transition-colors ${
                    volume === p.value ? 'bg-emerald-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
          </div>
          <div className="mt-2 flex items-center gap-4">
            <input
              type="range"
              aria-label="Volume slider"
              min={minVol}
              max={maxVol}
              step={Math.max(1, Math.round(maxVol / 200))}
              value={Math.min(Math.max(volume, minVol), maxVol)}
              onChange={(e) => set('volume_kg')(Number(e.target.value))}
              className="flex-1"
            />
            <div className="relative w-36">
              <input id="offer-volume" type="number" inputMode="decimal" className="input num pr-9 text-right" value={form.volume_kg} min={minVol} max={maxVol} onChange={set('volume_kg')} />
              <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">kg</span>
            </div>
          </div>
          <p className="num mt-1.5 text-[11px] text-slate-400">
            Min. {formatVolume(minVol)} · Max {formatVolume(maxVol)}
          </p>
          {fieldError('volume_kg')}
        </div>

        {/* Price */}
        <div>
          <div className="flex items-center justify-between">
            <label htmlFor="offer-price" className="label mb-0">
              Your price per kg
            </label>
            <div className="flex gap-1">
              {pricePresets.map((p) => (
                <button
                  key={p.label}
                  type="button"
                  onClick={() => set('unit_price')(Math.round(p.value * 4) / 4)}
                  className="focus-ring rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-200"
                >
                  {p.label}
                </button>
              ))}
            </div>
          </div>
          <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative sm:w-44">
              <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-slate-400">₱</span>
              <input id="offer-price" type="number" inputMode="decimal" step="0.25" min="0.5" className="input num pl-8 text-base font-semibold" value={form.unit_price} onChange={set('unit_price')} />
            </div>
            <div className="flex flex-wrap gap-2 text-xs">
              <span className={`num rounded-lg px-2.5 py-1.5 font-medium ${vsAsking < -8 ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'}`}>
                {formatPct(vsAsking)} vs asking
              </span>
              {vsIndex != null && (
                <span className="num rounded-lg bg-amber-50 px-2.5 py-1.5 font-medium text-amber-700">{formatPct(vsIndex)} vs farm-gate index</span>
              )}
            </div>
          </div>
          {vsAsking < -8 && <p className="mt-1.5 text-xs text-rose-600">Offers more than 8% under asking are often declined. Consider adding context in your message.</p>}
          {fieldError('unit_price')}
        </div>

        {/* Delivery */}
        <fieldset>
          <legend className="label">Delivery terms</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {DELIVERY_TERMS.map((t) => {
              const selected = form.delivery_terms === t.value;
              return (
                <label
                  key={t.value}
                  className={`relative flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-all ${
                    selected ? 'border-green-500 bg-green-50/50 shadow-glow' : 'border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <input type="radio" name="delivery_terms" value={t.value} checked={selected} onChange={set('delivery_terms')} className="sr-only" />
                  <t.icon className={`h-4 w-4 ${selected ? 'text-green-600' : 'text-slate-400'}`} />
                  <span>
                    <span className="block text-sm font-semibold text-ink">{t.label}</span>
                    <span className="block text-[11px] text-muted">{t.hint}</span>
                  </span>
                </label>
              );
            })}
          </div>
          {fieldError('delivery_terms')}
        </fieldset>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="offer-date" className="label">
              Target delivery date
            </label>
            <input id="offer-date" type="date" className="input" value={form.delivery_date} min={toIsoDate(new Date())} onChange={set('delivery_date')} />
            {fieldError('delivery_date')}
          </div>
          <div>
            <label htmlFor="offer-payment" className="label">
              Payment terms
            </label>
            <select id="offer-payment" className="input" value={form.payment_terms} onChange={set('payment_terms')}>
              {PAYMENT_TERMS.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div>
          <label htmlFor="offer-message" className="label">
            Message to seller <span className="font-normal text-slate-400">(optional)</span>
          </label>
          <textarea
            id="offer-message"
            rows={3}
            maxLength={2000}
            className="input resize-none"
            placeholder="e.g. Can you crate in 20 kg plastic crates? We can pick up Tuesday and Friday mornings."
            value={form.message}
            onChange={set('message')}
          />
        </div>

        {needsLogin && (
          <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
            <p className="text-sm font-semibold text-amber-800">Sign in as a buyer to send this offer</p>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <input className="input" type="email" autoComplete="username" aria-label="Email" value={auth.email} onChange={(e) => setAuth((a) => ({ ...a, email: e.target.value }))} />
              <input className="input" type="password" autoComplete="current-password" aria-label="Password" value={auth.password} onChange={(e) => setAuth((a) => ({ ...a, password: e.target.value }))} />
            </div>
            {fieldError('email')}
          </div>
        )}

        {(formError || errors.harvest_listing_id) && <ErrorNote error={{ message: formError || errors.harvest_listing_id }} />}
      </form>
    </Modal>
  );
}

/* ------------------------------------------------------------------ */
/* Marketplace                                                          */
/* ------------------------------------------------------------------ */

export default function HarvestMarketplace() {
  const [filters, setFilters] = useState(DEFAULT_FILTERS);
  const [tab, setTab] = useState('tradable');
  const [page, setPage] = useState(1);
  const [offerFor, setOfferFor] = useState(null);
  const [locationFor, setLocationFor] = useState(null);
  const [toast, setToast] = useState(null);
  const [showFilters, setShowFilters] = useState(false);
  const q = useDebounced(filters.q);
  const dismissToast = useCallback(() => setToast(null), []);

  const provinces = useAsync((signal) => api.provinces({ signal }), []);
  const crops = useAsync((signal) => api.crops({ signal }), []);

  const categoryByVariety = useMemo(() => {
    const map = {};
    (crops.data?.data ?? []).forEach((c) => c.varieties.forEach((v) => (map[v.id] = c)));
    return map;
  }, [crops.data]);

  const statuses = STATUS_TABS.find((t) => t.value === tab).statuses;
  const params = {
    ...filters,
    q,
    status: statuses,
    page,
    per_page: 9,
  };

  const listings = useAsync((signal) => api.listings(params, { signal }), [JSON.stringify(params)], 15000);

  useEffect(() => setPage(1), [q, filters.province_id, filters.crop_variety_id, filters.harvest_from, filters.harvest_to, filters.min_volume, filters.verified_only, filters.organic_only, filters.certified_only, filters.coop_pools_only, filters.sort, tab]);

  const update = (key) => (e) => setFilters((f) => ({ ...f, [key]: e?.target ? (e.target.type === 'checkbox' ? e.target.checked : e.target.value) : e }));
  const activeCount = Object.entries(filters).filter(([k, v]) => k !== 'sort' && v !== DEFAULT_FILTERS[k]).length;

  const meta = listings.data?.meta;
  const facets = meta?.facets?.status ?? {};
  const tabCount = (t) => t.statuses.reduce((a, s) => a + (Number(facets[s]) || 0), 0);
  const rows = listings.data?.data ?? [];

  const filterControls = (
    <>
      <div>
        <label htmlFor="f-province" className="label">
          Province
        </label>
        <select id="f-province" className="input" value={filters.province_id} onChange={update('province_id')}>
          <option value="">All provinces</option>
          {Object.entries((provinces.data?.data ?? []).reduce((acc, p) => ({ ...acc, [p.region]: [...(acc[p.region] || []), p] }), {})).map(([region, list]) => (
            <optgroup key={region} label={region}>
              {list.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </div>
      <div>
        <label htmlFor="f-crop" className="label">
          Crop variety
        </label>
        <select id="f-crop" className="input" value={filters.crop_variety_id} onChange={update('crop_variety_id')}>
          <option value="">All crops</option>
          {(crops.data?.data ?? []).map((c) => (
            <optgroup key={c.id} label={c.name}>
              {c.varieties.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </div>
      <div className="sm:col-span-2 lg:col-span-2">
        <span className="label">Harvest window</span>
        <div className="flex items-center gap-2">
          <input type="date" aria-label="Harvest from" className="input px-2.5" value={filters.harvest_from} onChange={update('harvest_from')} />
          <span className="text-slate-300">–</span>
          <input type="date" aria-label="Harvest to" className="input px-2.5" value={filters.harvest_to} min={filters.harvest_from || undefined} onChange={update('harvest_to')} />
        </div>
      </div>
      <div>
        <label htmlFor="f-volume" className="label">
          Minimum available
        </label>
        <select id="f-volume" className="input" value={filters.min_volume} onChange={update('min_volume')}>
          {VOLUME_PRESETS.map((v) => (
            <option key={v.value} value={v.value}>
              {v.label}
            </option>
          ))}
        </select>
      </div>
    </>
  );

  return (
    <div className="space-y-6">
      {/* Header */}
      <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div className="animate-fade-up">
          <p className="text-sm font-medium text-emerald-700">B2B Marketplace</p>
          <h1 className="mt-1 text-2xl font-extrabold text-ink sm:text-[28px]">Harvest listings</h1>
          <p className="mt-1 text-sm text-muted">Source directly from farms, cooperatives and consolidators — negotiate volume, price and delivery in one thread.</p>
        </div>
        {meta && (
          <div className="flex flex-wrap gap-2 text-xs">
            <span className="num rounded-full bg-white px-3 py-1.5 font-medium text-slate-600 shadow-sm ring-1 ring-slate-200/70">
              <b className="text-ink">{formatNumber(meta.total)}</b> lots
            </span>
            <span className="num rounded-full bg-white px-3 py-1.5 font-medium text-slate-600 shadow-sm ring-1 ring-slate-200/70">
              <b className="text-ink">{formatVolume(meta.aggregates.total_volume_kg, { compact: true })}</b> available
            </span>
            <span className="num rounded-full bg-amber-50 px-3 py-1.5 font-medium text-amber-700 ring-1 ring-amber-600/15">
              avg <b>{formatPeso(meta.aggregates.avg_unit_price)}</b>/kg
            </span>
            {meta.aggregates.coop_pools > 0 && (
              <span className="num hidden rounded-full bg-white px-3 py-1.5 font-medium text-slate-600 shadow-sm ring-1 ring-slate-200/70 sm:inline">
                <b className="text-ink">{meta.aggregates.coop_pools}</b> coop pools · <b className="text-ink">{meta.aggregates.certified}</b> certified
              </span>
            )}
          </div>
        )}
      </header>

      {/* Filters */}
      <section className="card p-4 sm:p-5" aria-label="Filters">
        <div className="flex flex-col gap-3 md:flex-row md:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              type="search"
              className="input pl-10"
              placeholder="Search crop, barangay, municipality or reference…"
              aria-label="Search listings"
              value={filters.q}
              onChange={update('q')}
            />
          </div>
          <div className="flex items-center gap-2">
            <select className="input w-auto" aria-label="Sort" value={filters.sort} onChange={update('sort')}>
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
            <button type="button" className="btn-ghost lg:hidden" onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>
              <SlidersHorizontal className="h-4 w-4" />
              Filters
              {activeCount > 0 && <span className="num grid h-5 min-w-5 place-items-center rounded-full bg-emerald-800 px-1 text-[10px] text-white">{activeCount}</span>}
            </button>
          </div>
        </div>

        <div className={`${showFilters ? 'grid' : 'hidden'} mt-4 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid lg:grid-cols-5`}>{filterControls}</div>

        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <div className="flex flex-wrap items-center gap-2">
            {[
              { key: 'certified_only', label: 'Certified grade' },
              { key: 'coop_pools_only', label: 'Coop pools' },
              { key: 'verified_only', label: 'Verified sellers' },
              { key: 'organic_only', label: 'Organic' },
            ].map((t) => (
              <label
                key={t.key}
                className={`inline-flex cursor-pointer select-none items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all ${
                  filters[t.key] ? 'border-green-500 bg-green-50 text-green-800' : 'border-slate-200 text-slate-600 hover:border-slate-300'
                }`}
              >
                <input type="checkbox" className="sr-only" checked={filters[t.key]} onChange={update(t.key)} />
                <span className={`grid h-3.5 w-3.5 place-items-center rounded-full border ${filters[t.key] ? 'border-green-500 bg-green-500' : 'border-slate-300'}`}>
                  {filters[t.key] && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                </span>
                {t.label}
              </label>
            ))}
          </div>
          {activeCount > 0 && (
            <button type="button" onClick={() => setFilters(DEFAULT_FILTERS)} className="focus-ring inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800">
              <X className="h-3.5 w-3.5" /> Clear {activeCount} filter{activeCount === 1 ? '' : 's'}
            </button>
          )}
        </div>
      </section>

      {/* Status tabs */}
      <nav className="scroll-thin -mx-1 flex gap-1 overflow-x-auto px-1" aria-label="Listing status">
        {STATUS_TABS.map((t) => {
          const selected = tab === t.value;
          return (
            <button
              key={t.value}
              type="button"
              onClick={() => setTab(t.value)}
              aria-pressed={selected}
              className={`focus-ring inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition-all ${
                selected ? 'bg-emerald-800 text-white shadow-md shadow-emerald-900/20' : 'text-slate-500 hover:bg-white hover:text-slate-800'
              }`}
            >
              {t.label}
              <span className={`num rounded-md px-1.5 py-px text-[11px] ${selected ? 'bg-white/15 text-white' : 'bg-slate-200/70 text-slate-600'}`}>{meta ? tabCount(t) : '·'}</span>
            </button>
          );
        })}
      </nav>

      <ErrorNote error={listings.error} onRetry={listings.reload} />

      {/* Grid */}
      {listings.loading && !listings.data ? (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }, (_, i) => (
            <CardSkeleton key={i} />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <div className="card">
          <EmptyState
            icon={Sprout}
            title="No harvests match these filters"
            action={
              activeCount > 0 && (
                <button type="button" className="btn-ghost" onClick={() => setFilters(DEFAULT_FILTERS)}>
                  Reset filters
                </button>
              )
            }
          >
            Try widening the harvest window or including more provinces. Upcoming lots appear as soon as farmers post planting schedules.
          </EmptyState>
        </div>
      ) : (
        <div className={`grid grid-cols-1 gap-4 transition-opacity md:grid-cols-2 xl:grid-cols-3 ${listings.loading ? 'opacity-60' : ''}`}>
          {rows.map((l, i) => (
            <ListingCard key={l.id} index={i} listing={l} category={categoryByVariety[l.crop.variety_id]} onOffer={setOfferFor} onLocation={setLocationFor} />
          ))}
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
          <p className="num text-sm text-muted">
            Showing <b className="text-slate-700">{meta.from}</b>–<b className="text-slate-700">{meta.to}</b> of <b className="text-slate-700">{meta.total}</b>
          </p>
          <div className="flex items-center gap-1">
            <button type="button" className="btn-ghost px-2.5" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label="Previous page">
              <ChevronLeft className="h-4 w-4" />
            </button>
            {Array.from({ length: meta.last_page }, (_, i) => i + 1).filter((n) => meta.last_page <= 5 || Math.abs(n - page) <= 1 || n === 1 || n === meta.last_page).map((n) => (
              <button
                key={n}
                type="button"
                onClick={() => setPage(n)}
                aria-current={n === page ? 'page' : undefined}
                className={`focus-ring num h-9 min-w-9 rounded-xl px-2 text-sm font-semibold transition-colors ${
                  n === page ? 'bg-emerald-800 text-white' : 'text-slate-600 hover:bg-white'
                }`}
              >
                {n}
              </button>
            ))}
            <button type="button" className="btn-ghost px-2.5" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} aria-label="Next page">
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}

      <OfferModal
        listing={offerFor}
        onClose={() => setOfferFor(null)}
        onSubmitted={(offer, listing) => {
          setOfferFor(null);
          setToast({
            title: `Offer ${offer.reference_code} sent`,
            body: `${formatVolume(offer.volume_kg)} of ${listing.crop.variety} at ${formatPeso(offer.unit_price)}/kg — ${listing.seller.business_name} has 5 days to respond.`,
          });
          listings.reload();
        }}
      />
      <FarmLocationDialog listing={locationFor} onClose={() => setLocationFor(null)} />
      <Toast toast={toast} onDismiss={dismissToast} />
    </div>
  );
}
