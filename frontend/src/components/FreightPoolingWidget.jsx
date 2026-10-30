import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowRight,
  CalendarDays,
  Crosshair,
  Fuel,
  Info,
  Loader2,
  MapPin,
  PiggyBank,
  Route,
  Truck,
  UserPlus,
  Users,
  Warehouse,
} from 'lucide-react';
import { ApiError, api, useSession } from '../lib/api';
import { formatDate, formatNumber, formatPeso, formatPesoCompact, formatVolume, toIsoDate } from '../lib/format';
import { EmptyState, ErrorNote, FuelSurchargeBadge, Skeleton, StatusPill, Toast, useAsync } from './ui';
import FarmLocationInput from './FarmLocationInput';

/** Common origin barangays (used when quoting without one of your own listings). */
const tomorrow = () => {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return toIsoDate(d);
};

function useDebounced(value, ms = 400) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/* ------------------------------------------------------------------ */

function FuelBanner({ cost }) {
  if (!cost) return null;
  const f = cost.fuel;
  const up = cost.surcharge_pct > 0;
  const none = cost.surcharge_pct === 0;
  return (
    <div
      className={`flex flex-col gap-2 rounded-2xl border p-4 sm:flex-row sm:items-center ${
        none ? 'border-slate-200 bg-slate-50' : up ? 'border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50/40' : 'border-emerald-200 bg-emerald-50'
      }`}
      role="status"
    >
      <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${up ? 'bg-amber-600 text-white shadow-md shadow-amber-700/25' : 'bg-emerald-700 text-white'}`}>
        <Fuel className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className={`num text-sm font-bold ${up ? 'text-amber-900' : 'text-emerald-900'}`}>
          {cost.surcharge_label ?? 'No fuel surcharge — pump prices at or below baseline'}
        </p>
        <p className="num mt-0.5 text-xs text-slate-600">
          {f.type === 'diesel' ? 'Diesel' : 'Gasoline'} {formatPeso(f.price_per_liter)}/L in Region {f.region_code} ·{' '}
          <b className={f.delta_pct > 0 ? 'text-amber-700' : 'text-emerald-700'}>
            {f.delta_pct > 0 ? '+' : ''}
            {f.delta_pct?.toFixed(1)}%
          </b>{' '}
          vs {formatPeso(f.baseline)} baseline · DOE update {formatDate(f.effective_date)}
        </p>
      </div>
      <span className="num self-start rounded-lg bg-white/80 px-2.5 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 sm:self-center">
        {cost.surcharge_amount >= 0 ? '+' : '−'}
        {formatPeso(Math.abs(cost.surcharge_amount))}
      </span>
    </div>
  );
}

function RouteTimeline({ quote }) {
  return (
    <ol className="relative space-y-0">
      {quote.route.stops.map((s, i) => {
        const last = i === quote.route.stops.length - 1;
        return (
          <li key={s.key + i} className="relative flex gap-3 pb-4 last:pb-0">
            {!last && <span className="absolute left-[15px] top-8 h-[calc(100%-1.5rem)] w-px border-l-2 border-dashed border-slate-200" aria-hidden="true" />}
            <span
              className={`relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold ring-4 ring-white ${
                s.type === 'hub' ? 'bg-emerald-800 text-white' : s.is_you ? 'bg-green-500 text-white' : 'bg-slate-100 text-slate-600'
              }`}
            >
              {s.type === 'hub' ? <Warehouse className="h-4 w-4" /> : s.order}
            </span>
            <div className="min-w-0 flex-1 pt-1">
              <p className="flex flex-wrap items-center gap-x-2 text-sm font-semibold text-ink">
                {s.label}
                {s.is_you && <span className="rounded bg-green-100 px-1.5 py-px text-[10px] font-bold text-green-800">YOU</span>}
              </p>
              <p className="num text-xs text-muted">
                {s.type === 'hub' ? 'Unload at trading hub' : `Brgy. ${s.barangay ?? '—'} · load ${formatVolume(s.volume_kg)}`}
                {!last && s.leg_km > 0 && <span className="text-slate-400"> · then {s.leg_km.toFixed(1)} km</span>}
              </p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function CostSplit({ quote }) {
  return (
    <>
      {/* ≥ md: table */}
      <div className="hidden overflow-hidden rounded-xl border border-slate-200/70 md:block">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-3 py-2.5 text-left font-semibold">Farmer</th>
              <th className="px-3 py-2.5 text-right font-semibold">Load</th>
              <th className="px-3 py-2.5 text-right font-semibold">To hub</th>
              <th className="px-3 py-2.5 text-right font-semibold">Pooled share</th>
              <th className="px-3 py-2.5 text-right font-semibold">Solo truck</th>
              <th className="px-3 py-2.5 text-right font-semibold">Saves</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {quote.members.map((m) => (
              <tr key={m.key} className={m.is_you ? 'bg-green-50/50' : ''}>
                <td className="px-3 py-2.5">
                  <span className="font-semibold text-ink">{m.label}</span>
                  {m.is_you && <span className="ml-1.5 rounded bg-green-100 px-1.5 py-px text-[10px] font-bold text-green-800">YOU</span>}
                  <span className="num block text-[11px] text-muted">{m.weight_pct}% of ton-km</span>
                </td>
                <td className="num px-3 py-2.5 text-right text-slate-700">{formatVolume(m.volume_kg)}</td>
                <td className="num px-3 py-2.5 text-right text-slate-700">{m.distance_to_hub_km.toFixed(1)} km</td>
                <td className="num px-3 py-2.5 text-right font-bold text-ink">
                  {formatPeso(m.cost_share)}
                  <span className="block text-[11px] font-medium text-muted">{formatPeso(m.cost_per_kg)}/kg</span>
                </td>
                <td className="num px-3 py-2.5 text-right text-slate-400 line-through decoration-slate-300">{formatPeso(m.solo_cost)}</td>
                <td className="num px-3 py-2.5 text-right">
                  <span className={`rounded-md px-1.5 py-0.5 text-xs font-bold ${m.savings > 0 ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                    {m.savings > 0 ? `−${formatPeso(m.savings)}` : '—'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* < md: stacked cards */}
      <ul className="space-y-2 md:hidden">
        {quote.members.map((m) => (
          <li key={m.key} className={`rounded-xl border p-3 ${m.is_you ? 'border-green-300 bg-green-50/50' : 'border-slate-200/70 bg-white'}`}>
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-ink">
                  {m.label} {m.is_you && <span className="rounded bg-green-100 px-1.5 py-px text-[10px] font-bold text-green-800">YOU</span>}
                </p>
                <p className="num text-xs text-muted">
                  {formatVolume(m.volume_kg)} · {m.distance_to_hub_km.toFixed(1)} km to hub
                </p>
              </div>
              <div className="text-right">
                <p className="num text-base font-bold text-ink">{formatPeso(m.cost_share)}</p>
                <p className="num text-[11px] text-slate-400 line-through">{formatPeso(m.solo_cost)} solo</p>
              </div>
            </div>
            {m.savings > 0 && (
              <p className="num mt-2 inline-flex rounded-md bg-green-50 px-1.5 py-0.5 text-xs font-bold text-green-700">
                Saves {formatPeso(m.savings)} ({m.savings_pct}%)
              </p>
            )}
          </li>
        ))}
      </ul>
    </>
  );
}

function SurchargeMatrix({ fuel, activeBand }) {
  if (!fuel) return null;
  const dieselRow = fuel.fuels?.diesel?.row;
  const gasRow = fuel.fuels?.gasoline?.row;
  return (
    <div>
      <div className="-mx-1 overflow-x-auto px-1">
        <table className="w-full min-w-[300px] text-xs">
          <thead>
            <tr className="text-[10px] uppercase tracking-wide text-slate-500">
              <th className="py-1.5 pr-2 text-left font-semibold">Pump price vs baseline</th>
              {fuel.columns.map((c) => (
                <th key={c.key} className={`px-1.5 py-1.5 text-center font-semibold ${c.key === activeBand ? 'text-emerald-800' : ''}`}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {fuel.rows.map((r, i) => {
              const isDiesel = i === dieselRow;
              return (
                <tr key={r.label} className={isDiesel ? 'bg-amber-50/70' : ''}>
                  <td className="num whitespace-nowrap py-1.5 pr-2 font-medium text-slate-600">
                    {r.label}
                    {isDiesel && <span className="ml-1 rounded bg-amber-600 px-1 py-px text-[9px] font-bold text-white">DIESEL</span>}
                    {i === gasRow && !isDiesel && <span className="ml-1 rounded bg-slate-700 px-1 py-px text-[9px] font-bold text-white">GAS</span>}
                  </td>
                  {fuel.columns.map((c) => {
                    const active = isDiesel && c.key === activeBand;
                    const v = r[c.key];
                    return (
                      <td key={c.key} className="px-1 py-1 text-center">
                        <span
                          className={`num inline-block min-w-[3rem] rounded-md px-1.5 py-1 font-semibold transition-all ${
                            active
                              ? 'scale-105 bg-amber-600 text-white shadow-md shadow-amber-700/30'
                              : v > 0
                                ? 'bg-amber-50 text-amber-800'
                                : v < 0
                                  ? 'bg-emerald-50 text-emerald-700'
                                  : 'bg-slate-50 text-slate-400'
                          }`}
                        >
                          {v > 0 ? '+' : v < 0 ? '−' : ''}
                          {Math.abs(v)}%
                        </span>
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="mt-2 flex items-start gap-1.5 text-[11px] text-muted">
        <Info className="mt-px h-3 w-3 shrink-0" />
        Tariffs are quoted at baseline fuel. Open pools are re-priced automatically every time a weekly DOE price is logged.
      </p>
    </div>
  );
}

/* ------------------------------------------------------------------ */

/**
 * "Hakot" shared-freight calculator: prices a solo truck vs. every joinable
 * pool heading to the same trading hub, shows the route, the fair ton-km cost
 * split and the dynamic fuel surcharge applied.
 */
export default function FreightPoolingWidget({ initialListingId = null }) {
  const user = useSession();
  const hubs = useAsync((signal) => api.tradingHubs({ signal }), []);
  const mine = useAsync((signal) => (user ? api.myListings({ per_page: 50 }, { signal }) : Promise.resolve({ data: [] })), [user?.id], 15000);
  const myPools = useAsync((signal) => (user ? api.freightPools({ scope: 'mine' }, { signal }) : Promise.resolve({ data: [] })), [user?.id], 15000);

  const [form, setForm] = useState({
    trading_hub_id: '',
    source: initialListingId ? 'listing' : user?.profile?.latitude && user?.profile?.longitude ? 'saved' : 'gps',
    listing_id: initialListingId ? String(initialListingId) : '',
    gps: user?.profile?.latitude && user?.profile?.longitude ? { lat: Number(user.profile.latitude), lng: Number(user.profile.longitude), barangay: user.profile.barangay || 'Saved farm' } : null,
    pickup_hub_id: '',
    volume_kg: 1200,
    departure_date: tomorrow(),
  });
  const [quote, setQuote] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState('best');
  const [joining, setJoining] = useState(null);
  const [bookingSolo, setBookingSolo] = useState(false);
  const [toast, setToast] = useState(null);
  const dismissToast = useCallback(() => setToast(null), []);

  const myListings = mine.data?.data ?? [];
  const listingsWithPickup = useMemo(() => myListings.filter((l) => l.location?.has_pickup_point), [myListings]);
  const sellerListing = listingsWithPickup.find((l) => String(l.id) === form.listing_id);

  // Default to the user's own first listing once loaded.
  useEffect(() => {
    const firstHub = hubs.data?.data?.[0];
    if (firstHub && !form.trading_hub_id) setForm((current) => ({ ...current, trading_hub_id: firstHub.id }));
  }, [hubs.data, form.trading_hub_id]);

  useEffect(() => {
    if (listingsWithPickup.length && !form.listing_id) {
      const first = listingsWithPickup[0];
      setForm((f) => ({ ...f, source: 'listing', listing_id: String(first.id), trading_hub_id: first.location?.trading_hub?.id ?? f.trading_hub_id }));
    }
  }, [listingsWithPickup, form.listing_id]);

  const request = useMemo(() => {
    const base = { trading_hub_id: Number(form.trading_hub_id), departure_date: form.departure_date, volume_kg: Number(form.volume_kg) || undefined };
    if (form.source === 'listing' && form.listing_id) return { ...base, harvest_listing_id: Number(form.listing_id) };
    if (form.source === 'cooperative') {
      const hub = (hubs.data?.data ?? []).find((item) => String(item.id) === String(form.pickup_hub_id));
      if (hub) return { ...base, pickup_latitude: Number(hub.latitude), pickup_longitude: Number(hub.longitude), barangay: hub.barangay || hub.municipality };
    }
    if (!form.gps) return base;
    return { ...base, pickup_latitude: form.gps.lat, pickup_longitude: form.gps.lng, barangay: form.gps.barangay };
  }, [form]);
  const debounced = useDebounced(JSON.stringify(request));

  const runQuote = useCallback(async (body) => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.freightQuote(body);
      setQuote(res.data);
      setSelected(res.data.best_option && res.data.best_option.your_savings > 0 ? 'best' : 'solo');
    } catch (e) {
      setError(e instanceof ApiError && e.errors ? Object.values(e.errors)[0]?.[0] || e.message : e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const body = JSON.parse(debounced);
    if (!body.trading_hub_id || ((!body.volume_kg && !body.harvest_listing_id) || (!body.harvest_listing_id && !body.pickup_latitude))) return;
    runQuote(body);
  }, [debounced, runQuote]);

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e?.target ? e.target.value : e }));

  function locateMe() {
    if (!navigator.geolocation) return setError('Location is not available on this device.');
    navigator.geolocation.getCurrentPosition(
      (pos) => setForm((f) => ({ ...f, source: 'gps', gps: { lat: +pos.coords.latitude.toFixed(6), lng: +pos.coords.longitude.toFixed(6), barangay: 'My farm' } })),
      () => setError('Could not read your location. Choose one of your listings with a saved farm pin.'),
      { enableHighAccuracy: false, timeout: 8000 },
    );
  }

  async function join(option) {
    if (!sellerListing) return;
    setJoining(option.pool.id);
    try {
      const res = await api.joinFreightPool(option.pool.id, { harvest_listing_id: sellerListing.id, volume_kg: Number(form.volume_kg) || sellerListing.available_volume_kg });
      setToast(
        res.queued
          ? { title: 'Saved offline', body: `You'll join ${option.pool.reference_code} as soon as you're back online.` }
          : { title: `Joined ${option.pool.reference_code}`, body: `Your share: ${formatPeso(option.your_share)} — ${formatPeso(option.your_savings)} less than a solo truck.` },
      );
      myPools.reload();
      runQuote(request);
    } catch (e) {
      setError(e.message);
    } finally {
      setJoining(null);
    }
  }

  async function bookSolo() {
    if (!sellerListing) return setError('Choose one of your available listings as the pickup load before booking a solo truck.');
    const volume = Number(form.volume_kg) || sellerListing.available_volume_kg;
    if (volume > sellerListing.available_volume_kg) return setError('The solo load exceeds the listing’s available volume.');
    setBookingSolo(true); setError(null);
    try {
      const result = await api.createFreightPool({ trading_hub_id: Number(form.trading_hub_id), departure_date: form.departure_date, solo: true, loads: [{ harvest_listing_id: sellerListing.id, volume_kg: volume }] });
      setToast({ title: `Solo truck booked · ${result.data.reference_code}`, body: `${formatPeso(result.data.total_cost)} for the exclusive ${result.data.route_distance_km} km trip. Diesel surcharge: ${result.data.fuel_surcharge_pct}%.` });
      myPools.reload();
    } catch (e) { setError(e.message); }
    finally { setBookingSolo(false); }
  }

  const options = quote?.pool_options ?? [];
  const active = selected === 'solo' ? null : selected === 'best' ? quote?.best_option : options.find((o) => o.pool.id === selected);
  const shown = active ? active.quote : quote?.solo;
  const youShown = shown?.members.find((m) => m.is_you);
  const hubName = (hubs.data?.data ?? []).find((h) => h.id === Number(form.trading_hub_id))?.short_name;

  return (
    <div className="space-y-5">
      {/* Inputs */}
      <section className="card p-4 sm:p-5" aria-label="Freight calculator inputs">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div className="lg:col-span-1">
            <label htmlFor="fq-hub" className="label">
              Deliver to trading hub
            </label>
            <select id="fq-hub" className="input" value={form.trading_hub_id} onChange={set('trading_hub_id')}>
              {(hubs.data?.data ?? []).map((h) => (
                <option key={h.id} value={h.id}>
                  {h.short_name} — {h.municipality}
                </option>
              ))}
            </select>
          </div>

          <div className="sm:col-span-2 lg:col-span-1">
            <label htmlFor="fq-origin" className="label">
              Pickup point
            </label>
            <div className="flex gap-2">
              <select
                id="fq-origin"
                className="input min-w-0"
                value={form.source === 'listing' ? `l:${form.listing_id}` : form.source === 'cooperative' ? `hub:${form.pickup_hub_id}` : form.source}
                onChange={(e) => {
                  const v = e.target.value;
                  if (v.startsWith('l:')) {
                    const l = listingsWithPickup.find((x) => String(x.id) === v.slice(2));
                    setForm((f) => ({ ...f, source: 'listing', listing_id: v.slice(2), trading_hub_id: l?.location?.trading_hub?.id ?? f.trading_hub_id }));
                  } else if (v.startsWith('hub:')) setForm((f) => ({ ...f, source: 'cooperative', pickup_hub_id: v.slice(4) }));
                  else setForm((f) => ({ ...f, source: v }));
                }}
              >
                {listingsWithPickup.length > 0 && (
                  <optgroup label="My listings">
                    {listingsWithPickup.map((l) => (
                      <option key={l.id} value={`l:${l.id}`}>
                        {l.reference_code} · {l.crop?.variety} · Brgy. {l.location.barangay}
                      </option>
                    ))}
                  </optgroup>
                )}
                {user?.profile?.latitude && user?.profile?.longitude && <option value="saved">Saved farm address</option>}
                {(hubs.data?.data ?? []).map((hub) => <option key={`hub-${hub.id}`} value={`hub:${hub.id}`}>Cooperative central warehouse / hub · {hub.short_name}</option>)}
                <option value="custom">Custom map pin point</option>
                <option value="gps">Use Current Device GPS</option>
              </select>
              <button type="button" onClick={locateMe} className="btn-ghost shrink-0 px-3" aria-label="Use my current location" title="Use my current location">
                <Crosshair className="h-4 w-4" />
              </button>
            </div>
          </div>

          {form.source === 'custom' && <div className="sm:col-span-2 lg:col-span-4"><FarmLocationInput value={{ latitude: form.gps?.lat || '', longitude: form.gps?.lng || '' }} provinces={[]} onChange={(point) => { if (point.latitude && point.longitude) setForm((f) => ({ ...f, gps: { lat: Number(point.latitude), lng: Number(point.longitude), barangay: point.barangay || 'Custom pin' } })); }} /></div>}
          {form.source === 'cooperative' && <div className="sm:col-span-2 lg:col-span-4"><label className="label" htmlFor="fq-pickup-hub">Pickup warehouse / hub</label><select id="fq-pickup-hub" className="input" value={form.pickup_hub_id} onChange={(e) => setForm((f) => ({ ...f, pickup_hub_id: e.target.value }))}>{(hubs.data?.data ?? []).map((hub) => <option key={hub.id} value={hub.id}>{hub.short_name} · {hub.municipality}</option>)}</select></div>}

          <div>
            <label htmlFor="fq-volume" className="label">
              Load (kg)
            </label>
            <div className="relative">
              <input id="fq-volume" type="number" inputMode="numeric" min="50" step="50" className="input num pr-10" value={form.volume_kg} onChange={set('volume_kg')} />
              <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">kg</span>
            </div>
          </div>

          <div>
            <label htmlFor="fq-date" className="label">
              Departure
            </label>
            <input id="fq-date" type="date" className="input" min={toIsoDate(new Date())} value={form.departure_date} onChange={set('departure_date')} />
          </div>
        </div>
      </section>

      <ErrorNote error={error && { message: error }} onRetry={() => runQuote(request)} />

      {!quote && loading && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Skeleton className="h-40 lg:col-span-2" />
          <Skeleton className="h-40" />
        </div>
      )}

      {quote && shown && (
        <div className={`space-y-5 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          {/* Savings hero */}
          <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-800 via-emerald-800 to-emerald-950 p-5 text-white shadow-xl shadow-emerald-900/20 sm:p-6">
            <div className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-green-400/20 blur-3xl" />
            <div className="relative grid grid-cols-1 gap-5 md:grid-cols-[1.4fr_1fr] md:items-center">
              <div>
                <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-green-300">
                  <PiggyBank className="h-4 w-4" /> Hakot savings to {hubName}
                </p>
                {quote.best_option && quote.best_option.your_savings > 0 ? (
                  <h2 className="mt-2 text-2xl font-extrabold leading-tight sm:text-3xl">
                    Share a truck, save <span className="num text-green-300">{formatPeso(quote.best_option.your_savings)}</span>
                  </h2>
                ) : (
                  <h2 className="mt-2 text-2xl font-extrabold leading-tight sm:text-3xl">No pool forming nearby — start one</h2>
                )}
                <p className="mt-1.5 max-w-lg text-sm text-emerald-100/80">
                  {quote.best_option && quote.best_option.your_savings > 0
                    ? `${quote.best_option.pool.members} farmer${quote.best_option.pool.members === 1 ? '' : 's'} from ${quote.best_option.pool.origin} already booked ${quote.best_option.pool.reference_code} for ${formatDate(quote.best_option.pool.departure_date)}. Costs split by load × distance.`
                    : `${quote.nearby_shippers.length} nearby farmer${quote.nearby_shippers.length === 1 ? ' has' : 's have'} open lots heading to ${hubName}. Invite them to split the truck.`}
                </p>
              </div>
              <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-white/10 ring-1 ring-white/15">
                <div className="bg-emerald-950/30 p-3.5">
                  <p className="text-[11px] text-emerald-100/70">Solo truck</p>
                  <p className="num mt-0.5 text-lg font-bold text-white/70 line-through decoration-white/40">{formatPeso(quote.solo.cost.total)}</p>
                </div>
                <div className="bg-emerald-950/30 p-3.5">
                  <p className="text-[11px] text-emerald-100/70">Your pooled share</p>
                  <p className="num mt-0.5 text-lg font-bold text-green-300">{formatPeso(quote.best_option?.your_share ?? quote.solo.cost.total)}</p>
                </div>
                <div className="col-span-2 bg-emerald-950/30 px-3.5 py-2.5 text-[11px] text-emerald-100/80">
                  <span className="num">{formatPeso((quote.best_option?.your_share ?? quote.solo.cost.total) / (Number(form.volume_kg) || sellerListing?.available_volume_kg || 1))}/kg</span> landed freight ·{' '}
                  <FuelSurchargeBadge label={shown.cost.surcharge_label} pct={shown.cost.surcharge_pct} className="!bg-amber-400/15 !text-amber-200 !ring-amber-300/30" />
                </div>
              </div>
            </div>
          </section>

          {/* Option switcher */}
          <div className="scroll-thin -mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Truck options">
            <button
              type="button"
              role="tab"
              aria-selected={selected === 'solo'}
              onClick={() => setSelected('solo')}
              className={`focus-ring shrink-0 rounded-xl border px-3.5 py-2 text-left transition-all ${
                selected === 'solo' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
              }`}
            >
              <span className="block text-xs font-semibold">Solo truck</span>
              <span className="num block text-[11px] opacity-75">{formatPeso(quote.solo.cost.total)}</span>
            </button>
            {options.map((o) => {
              const isSel = selected === o.pool.id || (selected === 'best' && quote.best_option?.pool.id === o.pool.id);
              return (
                <button
                  key={o.pool.id}
                  type="button"
                  role="tab"
                  aria-selected={isSel}
                  onClick={() => setSelected(o.pool.id)}
                  className={`focus-ring shrink-0 rounded-xl border px-3.5 py-2 text-left transition-all ${
                    isSel ? 'border-green-500 bg-green-50 text-green-900 shadow-glow' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                  }`}
                >
                  <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <Users className="h-3.5 w-3.5" /> {o.pool.reference_code}
                  </span>
                  <span className="num block text-[11px] opacity-80">
                    {formatPeso(o.your_share)} · saves {o.your_savings_pct}%
                  </span>
                </button>
              );
            })}
          </div>

          <FuelBanner cost={shown.cost} />

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
            {/* Route + truck */}
            <section className="card p-4 sm:p-5">
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h3 className="flex items-center gap-2 text-base font-bold text-ink">
                    <Route className="h-4 w-4 text-emerald-700" /> Route
                  </h3>
                  <p className="num text-xs text-muted">
                    {shown.route.distance_km.toFixed(1)} km road · ~{Math.floor(shown.route.distance_km / 30)}h {Math.round((shown.route.distance_km % 30) / 30 * 60)}m · {shown.route.stops.length - 1} pickup{shown.route.stops.length === 2 ? '' : 's'} · departs {formatDate(shown.departure_date)}
                  </p>
                </div>
                {active && <StatusPill status={active.pool.status} />}
              </div>
              <RouteTimeline quote={shown} />
              <div className="mt-5 rounded-xl bg-slate-50 p-3.5">
                <div className="flex items-center justify-between text-sm">
                  <span className="flex items-center gap-2 font-semibold text-ink">
                    <Truck className="h-4 w-4 text-slate-500" />
                    {shown.vehicle.trucks > 1 ? `${shown.vehicle.trucks} × ` : ''}
                    {shown.vehicle.label}
                  </span>
                  <span className="num text-xs text-muted">~{shown.vehicle.est_fuel_liters} L {shown.vehicle.fuel_type}</span>
                </div>
                <div className="mt-2.5 flex items-center justify-between text-[11px] text-muted">
                  <span className="num">
                    {formatVolume(shown.total_volume_kg)} of {formatVolume(shown.vehicle.capacity_kg, { compact: true })}
                  </span>
                  <span className="num font-semibold text-slate-700">{shown.vehicle.utilization_pct}% full</span>
                </div>
                <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-200">
                  <div className="h-full rounded-full bg-gradient-to-r from-emerald-700 to-green-500 transition-[width] duration-700" style={{ width: `${Math.min(100, shown.vehicle.utilization_pct)}%` }} />
                </div>
              </div>
            </section>

            {/* Cost split + breakdown */}
            <section className="card p-4 sm:p-5">
              <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="text-base font-bold text-ink">Fair cost split</h3>
                  <p className="text-xs text-muted">Each farmer pays by load × distance to hub (ton-km).</p>
                </div>
                {!active && selected === 'solo' && user && sellerListing && (
                  <button type="button" className="btn-primary px-3.5 py-2 text-xs" onClick={bookSolo} disabled={bookingSolo || !sellerListing.available_volume_kg}>
                    {bookingSolo ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Truck className="h-3.5 w-3.5" />}
                    Book exclusive solo truck · {formatPeso(quote.solo.cost.total)}
                  </button>
                )}
                {active && user && sellerListing && (
                  <button type="button" className="btn-action px-3.5 py-2 text-xs" onClick={() => join(active)} disabled={joining === active.pool.id}>
                    {joining === active.pool.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <UserPlus className="h-3.5 w-3.5" />}
                    Join {active.pool.reference_code}
                  </button>
                )}
              </div>
              <CostSplit quote={shown} />

              <dl className="num mt-4 grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl border border-slate-200/70 p-3.5 text-sm sm:grid-cols-4">
                <div>
                  <dt className="text-[11px] text-muted">Base tariff</dt>
                  <dd className="font-semibold text-ink">{formatPeso(shown.cost.base)}</dd>
                </div>
                <div>
                  <dt className="text-[11px] text-muted">Fuel surcharge</dt>
                  <dd className={`font-semibold ${shown.cost.surcharge_pct > 0 ? 'text-amber-700' : 'text-emerald-700'}`}>
                    {shown.cost.surcharge_pct > 0 ? '+' : ''}
                    {shown.cost.surcharge_pct}% · {formatPeso(shown.cost.surcharge_amount)}
                  </dd>
                </div>
                <div>
                  <dt className="text-[11px] text-muted">Truck total</dt>
                  <dd className="font-bold text-ink">{formatPeso(shown.cost.total)}</dd>
                </div>
                <div>
                  <dt className="text-[11px] text-muted">Group saves</dt>
                  <dd className="font-bold text-green-700">
                    {formatPesoCompact(shown.totals.savings)} ({shown.totals.savings_pct}%)
                  </dd>
                </div>
              </dl>
              {youShown && !active && (
                <p className="mt-3 text-xs text-muted">Showing a solo trip. Pick a pool above to see your shared price.</p>
              )}
              {(active || selected === 'solo') && !sellerListing && (
                <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
                  {user ? 'Select one of your available listings as the pickup load to book or join a truck.' : 'Sign in as a farmer or coop to book or join this truck.'}
                </p>
              )}
            </section>
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <section className="card p-4 sm:p-5">
              <h3 className="mb-1 flex items-center gap-2 text-base font-bold text-ink">
                <Fuel className="h-4 w-4 text-amber-600" /> Dynamic fuel surcharge matrix
              </h3>
              <p className="mb-3 text-xs text-muted">Region {quote.fuel.region_code} · highlighted: current diesel price × this haul length</p>
              <SurchargeMatrix fuel={quote.fuel} activeBand={shown.cost.distance_band} />
            </section>

            <section className="card p-4 sm:p-5">
              <h3 className="mb-1 flex items-center gap-2 text-base font-bold text-ink">
                <MapPin className="h-4 w-4 text-emerald-700" /> Nearby farmers shipping to {hubName}
              </h3>
              <p className="mb-3 text-xs text-muted">Open lots within 25 km of your pickup — invite them to fill the truck.</p>
              {quote.nearby_shippers.length === 0 ? (
                <p className="rounded-xl bg-slate-50 px-3 py-6 text-center text-sm text-muted">No open lots nearby right now.</p>
              ) : (
                <ul className="divide-y divide-slate-100">
                  {quote.nearby_shippers.map((n) => (
                    <li key={n.listing_id} className="flex items-center gap-3 py-2.5">
                      <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-50 text-[11px] font-bold text-emerald-800">{n.distance_from_you_km.toFixed(0)}k</span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink">{n.seller}</p>
                        <p className="truncate text-xs text-muted">
                          {n.crop} · Brgy. {n.barangay}, {n.municipality}
                        </p>
                      </div>
                      <span className="num shrink-0 text-xs font-semibold text-slate-700">{formatVolume(n.available_kg, { compact: true })}</span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        </div>
      )}

      {/* My pools */}
      {user && (
        <section className="card">
          <div className="flex items-center justify-between px-4 pb-2 pt-4 sm:px-5">
            <div>
              <h3 className="text-base font-bold text-ink">My truck pools</h3>
              <p className="text-xs text-muted">Re-priced automatically when fuel prices change</p>
            </div>
          </div>
          {myPools.loading && !myPools.data ? (
            <div className="space-y-2 p-4">
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
            </div>
          ) : (myPools.data?.data ?? []).length === 0 ? (
            <EmptyState icon={Truck} title="No truck pools yet">
              Quote a trip above and join a forming pool to start saving on hauling.
            </EmptyState>
          ) : (
            <ul className="divide-y divide-slate-100 px-2 pb-2">
              {myPools.data.data.map((p) => (
                <li key={p.id} className="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1.5 rounded-xl px-3 py-3 sm:grid-cols-[1.2fr_1fr_auto_auto]">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                      {p.reference_code} <StatusPill status={p.status} />
                    </p>
                    <p className="truncate text-xs text-muted">
                      {p.origin} <ArrowRight className="inline h-3 w-3" /> {p.hub} · {formatDate(p.departure_date)}
                    </p>
                  </div>
                  <div className="col-span-2 flex flex-wrap items-center gap-2 text-xs text-muted sm:col-span-1">
                    <span className="num inline-flex items-center gap-1">
                      <CalendarDays className="h-3 w-3" /> {p.members} loads · {p.utilization_pct}% full
                    </span>
                    <FuelSurchargeBadge label={p.fuel_surcharge_label} pct={p.fuel_surcharge_pct} />
                  </div>
                  <div className="num text-right text-sm sm:order-none">
                    <p className="font-bold text-ink">{formatPeso(p.your_share ?? p.total_cost)}</p>
                    <p className="text-[11px] text-muted">{p.your_share != null ? 'your share' : 'truck total'}</p>
                  </div>
                  <div className="num hidden text-right text-sm sm:block">
                    <p className="font-bold text-green-700">{p.your_savings != null ? `−${formatPeso(p.your_savings)}` : '—'}</p>
                    <p className="text-[11px] text-muted">vs solo</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
          {(myPools.data?.data ?? []).length > 0 && (
            <p className="num border-t border-slate-100 px-5 py-3 text-xs text-muted">
              Total saved across your pools:{' '}
              <b className="text-green-700">{formatPeso(myPools.data.data.reduce((a, p) => a + (p.your_savings || 0), 0))}</b> · {formatNumber(myPools.data.data.length)} trips
            </p>
          )}
        </section>
      )}

      <Toast toast={toast} onDismiss={dismissToast} />
    </div>
  );
}
