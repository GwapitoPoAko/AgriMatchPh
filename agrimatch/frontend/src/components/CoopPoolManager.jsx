import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  BadgeCheck,
  Building2,
  CalendarClock,
  Check,
  Layers,
  Loader2,
  MapPin,
  Sprout,
  Unlink,
  Users,
  Wallet,
} from 'lucide-react';
import { ApiError, api, useSession } from '../lib/api';
import { formatDateRange, formatPeso, formatPesoCompact, formatVolume } from '../lib/format';
import { EmptyState, ErrorNote, Skeleton, StatusPill, Toast, useAsync } from './ui';

const GRADE_RANK = { A: 1, B: 2, C: 3 };
const CERT_LABEL = { export: 'Export', grade_a: 'Grade A', class_b: 'Class B', class_c: 'Class C' };
const roundQuarter = (v) => Math.round(v * 4) / 4;

/* ------------------------------------------------------------------ */

function CoopHeader({ coop, pools }) {
  return (
    <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-800 via-emerald-800 to-emerald-950 p-5 text-white shadow-xl shadow-emerald-900/20 sm:p-6">
      <div className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-green-400/20 blur-3xl" />
      <div className="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div className="min-w-0">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-green-300">
            <Building2 className="h-4 w-4" /> Cooperative workspace
          </p>
          <h2 className="mt-2 text-xl font-extrabold leading-tight sm:text-2xl">{coop?.name ?? <Skeleton className="h-7 w-72 bg-white/10" />}</h2>
          {coop && (
            <p className="mt-1 text-sm text-emerald-100/75">
              CDA Reg. {coop.cda_registration_no} · {coop.municipality} · {coop.member_count} members · {coop.coop_fee_pct}% coop fee on pooled sales
            </p>
          )}
        </div>
        <dl className="grid grid-cols-3 gap-px overflow-hidden rounded-2xl bg-white/10 text-center ring-1 ring-white/15">
          {[
            ['Open pools', pools?.count ?? '—'],
            ['Pooled', pools ? formatVolume(pools.pooled_volume_kg, { compact: true }) : '—'],
            ['Member lots', pools?.member_lots ?? '—'],
          ].map(([label, v]) => (
            <div key={label} className="bg-emerald-950/30 px-4 py-3">
              <dt className="text-[10px] font-semibold uppercase tracking-wide text-emerald-100/70">{label}</dt>
              <dd className="num mt-0.5 text-lg font-bold">{v}</dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  );
}

function LotCard({ lot, selection, onToggle, onVolume }) {
  const selected = Boolean(selection);
  const volume = selection?.volume_kg ?? lot.available_volume_kg;
  const presets = [
    ['25%', Math.round((lot.available_volume_kg * 0.25) / 50) * 50],
    ['50%', Math.round((lot.available_volume_kg * 0.5) / 50) * 50],
    ['All', lot.available_volume_kg],
  ];
  return (
    <li
      className={`rounded-2xl border bg-white p-4 transition-all ${
        selected ? 'border-green-500 shadow-glow' : 'border-slate-200/70 shadow-card hover:border-slate-300'
      }`}
    >
      <label className="flex cursor-pointer items-start gap-3">
        <span
          className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-md border-2 transition-colors ${
            selected ? 'border-green-500 bg-green-500 text-white' : 'border-slate-300 bg-white'
          }`}
        >
          {selected && <Check className="h-3.5 w-3.5" strokeWidth={3} />}
        </span>
        <input type="checkbox" className="sr-only" checked={selected} onChange={() => onToggle(lot)} />
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="truncate text-sm font-semibold text-ink">{lot.farmer}</span>
            {lot.is_verified && <BadgeCheck className="h-4 w-4 shrink-0 text-green-600" aria-label="Verified" />}
          </span>
          <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-3 w-3" /> Brgy. {lot.barangay}
            </span>
            <span className="font-mono text-[10px] text-slate-400">{lot.reference_code}</span>
          </span>
          <span className="mt-2 flex flex-wrap items-center gap-1.5">
            <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-bold ${lot.quality_grade === 'A' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-700'}`}>
              Grade {lot.quality_grade}
            </span>
            {lot.certified_grade && (
              <span className="rounded-md bg-emerald-800 px-1.5 py-0.5 text-[11px] font-bold text-white">{CERT_LABEL[lot.certified_grade] ?? lot.certified_grade}</span>
            )}
            <StatusPill status={lot.status} />
          </span>
        </span>
        <span className="shrink-0 text-right">
          <span className="num block text-sm font-bold text-ink">{formatPeso(lot.unit_price)}</span>
          <span className="num block text-[11px] text-muted">{formatVolume(lot.available_volume_kg, { compact: true })} avail.</span>
        </span>
      </label>

      <p className="num mt-2 flex items-center gap-1 pl-8 text-[11px] text-slate-500">
        <CalendarClock className="h-3 w-3" /> {formatDateRange(lot.harvest_date_start, lot.harvest_date_end)}
      </p>

      {selected && (
        <div className="mt-3 animate-fade-up rounded-xl bg-slate-50 p-3 sm:ml-8">
          <div className="flex items-center justify-between gap-2">
            <label htmlFor={`commit-${lot.id}`} className="text-xs font-medium text-slate-600">
              Commit to pool
            </label>
            <div className="flex gap-1">
              {presets.map(([label, v]) => (
                <button
                  key={label}
                  type="button"
                  onClick={() => onVolume(lot, v)}
                  className={`focus-ring rounded-md px-2 py-0.5 text-[11px] font-semibold transition-colors ${
                    Number(volume) === v ? 'bg-emerald-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
          <div className="mt-2 flex items-center gap-3">
            <input
              type="range"
              aria-label={`Volume from ${lot.farmer}`}
              min={50}
              max={lot.available_volume_kg}
              step={50}
              value={Math.min(Number(volume) || 0, lot.available_volume_kg)}
              onChange={(e) => onVolume(lot, Number(e.target.value))}
              className="min-w-0 flex-1"
            />
            <div className="relative w-28 shrink-0">
              <input
                id={`commit-${lot.id}`}
                type="number"
                inputMode="numeric"
                min={1}
                max={lot.available_volume_kg}
                className="input num py-2 pr-8 text-right"
                value={volume}
                onChange={(e) => onVolume(lot, e.target.value)}
              />
              <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] text-slate-400">kg</span>
            </div>
          </div>
          {Number(volume) > lot.available_volume_kg && <p className="mt-1 text-xs font-medium text-rose-600">Only {formatVolume(lot.available_volume_kg)} available.</p>}
        </div>
      )}
    </li>
  );
}

function PoolCard({ pool, onDissolve, busy }) {
  const [confirming, setConfirming] = useState(false);
  const blocked = pool.pending_offers > 0;
  return (
    <li className="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-card">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-ink">{pool.crop}</p>
          <p className="flex flex-wrap items-center gap-x-2 text-[11px] text-muted">
            <span className="font-mono">{pool.reference_code}</span>
            <span>
              {pool.members} farmers · {formatPeso(pool.unit_price)}/kg
            </span>
          </p>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <StatusPill status={pool.status} />
          {pool.certified_grade ? (
            <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800">{CERT_LABEL[pool.certified_grade]}</span>
          ) : (
            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">Uncertified</span>
          )}
        </div>
      </div>
      <div className="num mt-3 flex justify-between text-[11px] text-muted">
        <span>
          Sold {formatVolume(pool.stock_volume_kg - pool.available_volume_kg, { compact: true })} of {formatVolume(pool.stock_volume_kg, { compact: true })}
        </span>
        <span className="font-semibold text-slate-700">{pool.sold_pct}%</span>
      </div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className="h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-600" style={{ width: `${Math.max(pool.sold_pct, 2)}%` }} />
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        {pool.pending_offers > 0 ? (
          <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-700">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> {pool.pending_offers} buyer offer{pool.pending_offers > 1 ? 's' : ''} pending
          </span>
        ) : (
          <span className="text-[11px] text-muted">No pending offers</span>
        )}
        {confirming ? (
          <span className="flex gap-1.5">
            <button type="button" className="btn-ghost px-2.5 py-1.5 text-xs" onClick={() => setConfirming(false)}>
              Keep
            </button>
            <button
              type="button"
              className="btn px-2.5 py-1.5 text-xs bg-rose-600 text-white hover:bg-rose-700"
              disabled={busy}
              onClick={() => onDissolve(pool)}
            >
              {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Unlink className="h-3.5 w-3.5" />} Return volume
            </button>
          </span>
        ) : (
          <button
            type="button"
            className="btn-ghost px-2.5 py-1.5 text-xs"
            disabled={blocked}
            title={blocked ? 'Resolve pending offers before dissolving' : 'Return unsold volume to member lots'}
            onClick={() => setConfirming(true)}
          >
            <Unlink className="h-3.5 w-3.5" /> Dissolve
          </button>
        )}
      </div>
    </li>
  );
}

/* ------------------------------------------------------------------ */

/**
 * Coop Manager workspace: aggregate smallholder lots of one crop into a
 * single high-volume "parent listing", preview the pooled terms and each
 * member's payout, and manage open pools.
 */
export default function CoopPoolManager({ onNavigate = () => {} }) {
  const user = useSession();
  const coopId = user?.managed_cooperative_id;
  const [cropId, setCropId] = useState(null);
  const [selection, setSelection] = useState({}); // listingId -> { volume_kg }
  const [terms, setTerms] = useState({ unit_price: '', minimum_order_kg: '1000', title: '' });
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [dissolving, setDissolving] = useState(null);
  const [toast, setToast] = useState(null);
  const dismissToast = useCallback(() => setToast(null), []);

  const summary = useAsync((signal) => (coopId ? api.poolCandidates(coopId, {}, { signal }) : Promise.resolve(null)), [coopId]);
  const lots = useAsync(
    (signal) => (coopId && cropId ? api.poolCandidates(coopId, { crop_variety_id: cropId }, { signal }) : Promise.resolve(null)),
    [coopId, cropId],
  );
  const pools = useAsync((signal) => (coopId ? api.coopPools({ cooperative_id: coopId, limit: 20 }, { signal }) : Promise.resolve(null)), [coopId]);

  const coop = summary.data?.meta?.cooperative;
  const minMembers = summary.data?.meta?.min_members ?? 2;
  const crops = summary.data?.data ?? [];
  const lotList = lots.data?.data ?? [];

  // Pick the first poolable crop by default.
  useEffect(() => {
    if (!cropId && crops.length) setCropId((crops.find((c) => c.poolable) ?? crops[0]).crop_variety_id);
  }, [crops, cropId]);

  useEffect(() => {
    setSelection({});
    setErrors({});
    setFormError(null);
    setTerms((t) => ({ ...t, unit_price: '', title: '' }));
  }, [cropId]);

  const toggle = (lot) =>
    setSelection((sel) => {
      const next = { ...sel };
      if (next[lot.id]) delete next[lot.id];
      else next[lot.id] = { volume_kg: lot.available_volume_kg };
      return next;
    });
  const setVolume = (lot, v) => setSelection((sel) => ({ ...sel, [lot.id]: { volume_kg: v } }));

  const preview = useMemo(() => {
    const chosen = lotList.filter((l) => selection[l.id]).map((l) => ({ lot: l, kg: Number(selection[l.id].volume_kg) || 0 }));
    const total = chosen.reduce((a, c) => a + c.kg, 0);
    const vwap = total > 0 ? chosen.reduce((a, c) => a + c.lot.unit_price * c.kg, 0) / total : 0;
    const price = Number(terms.unit_price) || roundQuarter(vwap);
    const worst = chosen.map((c) => c.lot.quality_grade).sort((a, b) => GRADE_RANK[b] - GRADE_RANK[a])[0];
    const certs = chosen.map((c) => c.lot.certified_grade);
    const starts = chosen.map((c) => c.lot.harvest_date_start).sort();
    const ends = chosen.map((c) => c.lot.harvest_date_end).sort();
    const fee = coop?.coop_fee_pct ?? 0;
    const overDrawn = chosen.some((c) => c.kg > c.lot.available_volume_kg || c.kg <= 0);
    return {
      chosen,
      total,
      vwap,
      price,
      worst,
      certified: certs.length && !certs.includes(null) ? certs : null,
      window: starts.length ? [starts[starts.length - 1], ends[ends.length - 1]] : null,
      gross: total * price,
      fee,
      payouts: chosen.map((c) => ({ ...c, gross: c.kg * price, net: c.kg * price * (1 - fee / 100), share: total ? (c.kg / total) * 100 : 0 })),
      ready: chosen.length >= minMembers && !overDrawn,
      overDrawn,
    };
  }, [lotList, selection, terms.unit_price, coop, minMembers]);

  const cropName = crops.find((c) => c.crop_variety_id === cropId)?.name ?? 'Crop';

  async function createPool(e) {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.createCoopPool({
        cooperative_id: coopId,
        crop_variety_id: cropId,
        contributions: preview.chosen.map((c) => ({ harvest_listing_id: c.lot.id, volume_kg: c.kg })),
        unit_price: terms.unit_price ? Number(terms.unit_price) : undefined,
        minimum_order_kg: terms.minimum_order_kg ? Number(terms.minimum_order_kg) : undefined,
        title: terms.title.trim() || undefined,
      });
      setToast({
        title: `${res.data.reference_code} is live on the marketplace`,
        body: `${formatVolume(res.data.stock_volume_kg, { compact: true })} of ${cropName} from ${preview.chosen.length} farmers. Members were notified by SMS.`,
      });
      setSelection({});
      setTerms({ unit_price: '', minimum_order_kg: '1000', title: '' });
      summary.reload();
      lots.reload();
      pools.reload();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(err.errors);
        setFormError(Object.values(err.errors)[0]?.[0] ?? err.message);
      } else {
        setFormError(err.message);
      }
    } finally {
      setSubmitting(false);
    }
  }

  async function dissolve(pool) {
    setDissolving(pool.id);
    try {
      await api.dissolveCoopPool(pool.id);
      setToast({ title: `${pool.reference_code} dissolved`, body: 'Unsold volume was returned to each member lot pro-rata.' });
      summary.reload();
      lots.reload();
      pools.reload();
    } catch (err) {
      setToast({ title: 'Could not dissolve pool', body: err.message });
    } finally {
      setDissolving(null);
    }
  }

  if (!user || (user.role !== 'coop_manager' && user.role !== 'admin')) {
    return (
      <div className="card">
        <EmptyState icon={Users} title="Cooperative managers only">
          Sign in with a coop manager account (e.g. ramil.manggubat@agrimatch.test) to build pools.
        </EmptyState>
      </div>
    );
  }
  if (!coopId) {
    return (
      <div className="card">
        <EmptyState icon={Building2} title="No cooperative linked">
          Your account isn&apos;t set as manager of a cooperative yet. Ask a platform admin to link it.
        </EmptyState>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <CoopHeader coop={coop} pools={pools.data?.data} />
      <ErrorNote error={summary.error} onRetry={summary.reload} />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
        {/* Builder */}
        <div className="space-y-5">
          <section className="card p-4 sm:p-5" aria-labelledby="step-crop">
            <h3 id="step-crop" className="flex items-center gap-2 text-base font-bold text-ink">
              <span className="grid h-6 w-6 place-items-center rounded-full bg-emerald-800 text-[11px] font-bold text-white">1</span> Choose a crop
            </h3>
            <p className="mt-0.5 text-xs text-muted">Crops with open member lots. A pool needs at least {minMembers} lots of the same variety.</p>
            <div className="scroll-thin -mx-1 mt-3 flex gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:overflow-visible">
              {summary.loading && !summary.data
                ? Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-14 w-40 shrink-0 rounded-xl" />)
                : crops.map((c) => {
                    const active = c.crop_variety_id === cropId;
                    return (
                      <button
                        key={c.crop_variety_id}
                        type="button"
                        onClick={() => setCropId(c.crop_variety_id)}
                        aria-pressed={active}
                        className={`focus-ring shrink-0 rounded-xl border px-3.5 py-2.5 text-left transition-all ${
                          active ? 'border-green-500 bg-green-50/60 shadow-glow' : 'border-slate-200 bg-white hover:border-slate-300'
                        } ${c.poolable ? '' : 'opacity-60'}`}
                      >
                        <span className="block text-sm font-semibold text-ink">{c.name}</span>
                        <span className="num block text-[11px] text-muted">
                          {c.lots} lot{c.lots === 1 ? '' : 's'} · {formatVolume(c.available_kg, { compact: true })}
                          {!c.poolable && ' · need more'}
                        </span>
                      </button>
                    );
                  })}
              {summary.data && crops.length === 0 && <p className="text-sm text-muted">No open member lots right now.</p>}
            </div>
          </section>

          <section aria-labelledby="step-lots">
            <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
              <div>
                <h3 id="step-lots" className="flex items-center gap-2 text-base font-bold text-ink">
                  <span className="grid h-6 w-6 place-items-center rounded-full bg-emerald-800 text-[11px] font-bold text-white">2</span> Select member lots
                </h3>
                <p className="mt-0.5 text-xs text-muted">Committed volume is reserved out of each farmer&apos;s listing so it can&apos;t be sold twice.</p>
              </div>
              {lotList.length > 0 && (
                <button
                  type="button"
                  className="focus-ring rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                  onClick={() =>
                    setSelection(
                      Object.keys(selection).length === lotList.length
                        ? {}
                        : Object.fromEntries(lotList.map((l) => [l.id, { volume_kg: l.available_volume_kg }])),
                    )
                  }
                >
                  {Object.keys(selection).length === lotList.length ? 'Clear all' : 'Select all'}
                </button>
              )}
            </div>
            <ErrorNote error={lots.error} onRetry={lots.reload} />
            {lots.loading && !lots.data ? (
              <div className="space-y-3">
                <Skeleton className="h-28 rounded-2xl" />
                <Skeleton className="h-28 rounded-2xl" />
              </div>
            ) : lotList.length === 0 ? (
              <div className="card">
                <EmptyState icon={Sprout} title="No open lots for this crop">
                  Member farmers can post lots from their phones (even offline); they&apos;ll appear here.
                </EmptyState>
              </div>
            ) : (
              <ul className={`grid grid-cols-1 gap-3 xl:grid-cols-2 ${lots.loading ? 'opacity-60' : ''}`}>
                {lotList.map((lot) => (
                  <LotCard key={lot.id} lot={lot} selection={selection[lot.id]} onToggle={toggle} onVolume={setVolume} />
                ))}
              </ul>
            )}
          </section>
        </div>

        {/* Preview & terms */}
        <form onSubmit={createPool} className="card h-fit overflow-hidden lg:sticky lg:top-24" aria-labelledby="step-terms">
          <div className="border-b border-slate-100 bg-gradient-to-br from-amber-50/80 to-white p-4 sm:p-5">
            <h3 id="step-terms" className="flex items-center gap-2 text-base font-bold text-ink">
              <span className="grid h-6 w-6 place-items-center rounded-full bg-emerald-800 text-[11px] font-bold text-white">3</span> Pooled listing
            </h3>
            <div className="mt-3 flex items-end justify-between gap-3">
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Total volume</p>
                <p className="num font-display text-3xl font-bold text-ink">{formatVolume(preview.total, { compact: true })}</p>
              </div>
              <div className="text-right">
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Farmers</p>
                <p className="num font-display text-3xl font-bold text-ink">
                  {preview.chosen.length}
                  <span className="text-base font-medium text-muted">/{minMembers}+</span>
                </p>
              </div>
            </div>
            <dl className="num mt-3 grid grid-cols-2 gap-2 text-xs">
              <div className="rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200/70">
                <dt className="text-muted">Weighted avg ask</dt>
                <dd className="font-semibold text-ink">{preview.total ? formatPeso(preview.vwap) : '—'}</dd>
              </div>
              <div className="rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200/70">
                <dt className="text-muted">Pool grade</dt>
                <dd className="font-semibold text-ink">
                  {preview.worst ? `Grade ${preview.worst}` : '—'}
                  {preview.certified && <span className="ml-1 text-emerald-700">· certified</span>}
                </dd>
              </div>
              <div className="col-span-2 rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200/70">
                <dt className="text-muted">Deliverable window</dt>
                <dd className="font-semibold text-ink">{preview.window ? formatDateRange(preview.window[0], preview.window[1]) : '—'}</dd>
              </div>
            </dl>
            {preview.chosen.length > 0 && !preview.certified && (
              <p className="mt-2 flex items-start gap-1.5 text-[11px] text-amber-800">
                <AlertTriangle className="mt-px h-3 w-3 shrink-0" /> Some lots are uncertified, so the pool lists uncertified. Request an inspection after creating it.
              </p>
            )}
          </div>

          <div className="space-y-4 p-4 sm:p-5">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label htmlFor="pool-price" className="label">
                  Pool price (₱/kg)
                </label>
                <input
                  id="pool-price"
                  type="number"
                  inputMode="decimal"
                  step="0.25"
                  min="0.5"
                  className="input num"
                  placeholder={preview.total ? roundQuarter(preview.vwap).toFixed(2) : 'auto'}
                  value={terms.unit_price}
                  onChange={(e) => setTerms((t) => ({ ...t, unit_price: e.target.value }))}
                />
              </div>
              <div>
                <label htmlFor="pool-min" className="label">
                  Min. order (kg)
                </label>
                <input
                  id="pool-min"
                  type="number"
                  inputMode="numeric"
                  min="0"
                  className="input num"
                  value={terms.minimum_order_kg}
                  onChange={(e) => setTerms((t) => ({ ...t, minimum_order_kg: e.target.value }))}
                />
              </div>
            </div>
            <div>
              <label htmlFor="pool-title" className="label">
                Title <span className="font-normal text-slate-400">(optional)</span>
              </label>
              <input
                id="pool-title"
                className="input"
                maxLength={200}
                placeholder={`${cropName} — Coop pooled lot from ${preview.chosen.length || 'N'} smallholders`}
                value={terms.title}
                onChange={(e) => setTerms((t) => ({ ...t, title: e.target.value }))}
              />
            </div>

            <div>
              <p className="label flex items-center gap-1.5">
                <Wallet className="h-3.5 w-3.5 text-emerald-700" /> Member payout if fully sold
              </p>
              {preview.payouts.length === 0 ? (
                <p className="rounded-xl bg-slate-50 px-3 py-4 text-center text-xs text-muted">Select lots to see each farmer&apos;s share.</p>
              ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200/70">
                  {preview.payouts.map((p) => (
                    <li key={p.lot.id} className="flex items-center gap-2 px-3 py-2">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-semibold text-ink">{p.lot.farmer}</span>
                        <span className="num block text-[11px] text-muted">
                          {formatVolume(p.kg)} · {p.share.toFixed(1)}%
                        </span>
                      </span>
                      <span className="num shrink-0 text-right text-xs font-bold text-ink">{formatPeso(p.net)}</span>
                    </li>
                  ))}
                  <li className="num flex items-center justify-between bg-slate-50 px-3 py-2 text-xs">
                    <span className="text-muted">
                      Gross {formatPesoCompact(preview.gross)} · {preview.fee}% coop fee
                    </span>
                    <span className="font-bold text-emerald-800">{formatPeso(preview.gross * (1 - preview.fee / 100))}</span>
                  </li>
                </ul>
              )}
            </div>

            {formError && <ErrorNote error={{ message: formError }} />}
            {errors.contributions && !formError && <p className="text-xs text-rose-600">{errors.contributions[0]}</p>}

            <button type="submit" className="btn-action w-full py-3" disabled={!preview.ready || submitting}>
              {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Layers className="h-4 w-4" />}
              {preview.ready
                ? `Create pool · ${formatVolume(preview.total, { compact: true })}`
                : preview.overDrawn
                  ? 'Fix lot volumes'
                  : `Select ${Math.max(minMembers - preview.chosen.length, 0)} more lot${minMembers - preview.chosen.length === 1 ? '' : 's'}`}
            </button>
            <p className="text-center text-[11px] text-muted">Buyers negotiate once and sign one contract. Payouts are split pro-rata automatically.</p>
          </div>
        </form>
      </div>

      {/* Existing pools */}
      <section aria-labelledby="open-pools">
        <div className="mb-3 flex items-end justify-between">
          <div>
            <h3 id="open-pools" className="text-base font-bold text-ink">Your open pools</h3>
            <p className="text-xs text-muted">Dissolving returns unsold volume to each member lot pro-rata.</p>
          </div>
          <button type="button" onClick={() => onNavigate('marketplace')} className="focus-ring rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
            View on marketplace
          </button>
        </div>
        {pools.loading && !pools.data ? (
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Skeleton className="h-36 rounded-2xl" />
            <Skeleton className="h-36 rounded-2xl" />
          </div>
        ) : (pools.data?.data?.items ?? []).length === 0 ? (
          <div className="card">
            <EmptyState icon={Layers} title="No open pools yet">
              Build your first pool above to reach supermarkets, feed mills and exporters.
            </EmptyState>
          </div>
        ) : (
          <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            {pools.data.data.items.map((p) => (
              <PoolCard key={p.id} pool={p} busy={dissolving === p.id} onDissolve={dissolve} />
            ))}
          </ul>
        )}
      </section>

      <Toast toast={toast} onDismiss={dismissToast} />
    </div>
  );
}
