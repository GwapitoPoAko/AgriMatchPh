import { useMemo } from 'react';
import {
  AlertTriangle,
  ArrowRight,
  BellRing,
  Fuel,
  PiggyBank,
  ShieldCheck,
  Truck,
  Users,
  CalendarClock,
  FileSignature,
  Handshake,
  MapPin,
  PackagePlus,
  Radar,
  Sprout,
  TrendingUp,
  Wheat,
} from 'lucide-react';
import { api, useSession } from '../lib/api';
import {
  formatDateRange,
  formatDateWeekday,
  formatNumber,
  formatPeso,
  formatPesoCompact,
  formatPct,
  formatVolume,
} from '../lib/format';
import PriceAnalyticsChart from './PriceAnalyticsChart';
import { CertifiedBadge, ErrorNote, MarketSignalBadge, Skeleton, Sparkline, StatusPill, TrendDelta, VerifiedBadge, useAsync } from './ui';

/** % change of the last 7 points vs the 7 before them. */
function weekOverWeek(series = []) {
  const vals = series.map((p) => p.value);
  if (vals.length < 14) return null;
  const last = vals.slice(-7).reduce((a, b) => a + b, 0);
  const prev = vals.slice(-14, -7).reduce((a, b) => a + b, 0);
  if (prev === 0) return last > 0 ? 100 : null;
  return (last / prev - 1) * 100;
}

function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

/* ------------------------------------------------------------------ */

function KpiCard({ featured = false, icon: Icon, label, value, sub, trend, delta, deltaLabel, tone = 'emerald', children, loading, delay = 0 }) {
  if (loading) {
    return (
      <div className="card p-5">
        <Skeleton className="h-4 w-28" />
        <Skeleton className="mt-4 h-8 w-32" />
        <Skeleton className="mt-3 h-3 w-40" />
      </div>
    );
  }

  return (
    <div
      className={`group relative animate-fade-up overflow-hidden rounded-2xl p-5 transition-all duration-300 hover:-translate-y-0.5 ${
        featured
          ? 'bg-gradient-to-br from-emerald-800 via-emerald-800 to-emerald-900 text-white shadow-xl shadow-emerald-900/20'
          : 'border border-slate-200/70 bg-white shadow-card hover:shadow-lift'
      }`}
      style={{ animationDelay: `${delay}ms` }}
    >
      {featured && (
        <>
          <div className="pointer-events-none absolute -right-10 -top-16 h-44 w-44 rounded-full bg-green-400/20 blur-3xl" />
          <svg className="pointer-events-none absolute inset-0 h-full w-full opacity-[0.07]" aria-hidden="true">
            <defs>
              <pattern id="kpi-rows" width="22" height="22" patternUnits="userSpaceOnUse" patternTransform="rotate(-24)">
                <path d="M0 11h22" stroke="white" strokeWidth="1" />
              </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#kpi-rows)" />
          </svg>
        </>
      )}

      <div className="relative flex items-start justify-between">
        <div className="flex items-center gap-2">
          <span
            className={`grid h-8 w-8 place-items-center rounded-xl ${
              featured
                ? 'bg-white/10 text-green-300 ring-1 ring-white/15'
                : tone === 'amber'
                  ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-600/10'
                  : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-700/10'
            }`}
          >
            <Icon className="h-4 w-4" />
          </span>
          <span className={`text-[13px] font-medium ${featured ? 'text-emerald-100/80' : 'text-muted'}`}>{label}</span>
        </div>
      </div>

      <div className="relative mt-4 flex items-end gap-2">
        <span className={`num font-display text-[28px] font-bold leading-none tracking-tight ${featured ? 'text-white' : 'text-ink'}`}>{value}</span>
        {delta != null && (
          <span
            className={`num mb-0.5 inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-semibold ${
              featured ? 'bg-white/10 text-green-200' : delta >= 0 ? 'bg-green-50 text-green-700' : 'bg-rose-50 text-rose-600'
            }`}
          >
            {formatPct(delta)}
          </span>
        )}
        {trend && (
          <Sparkline
            values={trend}
            width={76}
            height={30}
            tone={featured ? 'emerald' : tone}
            className={`ml-auto shrink-0 ${featured ? '[&_path:last-of-type]:stroke-green-300' : ''}`}
          />
        )}
      </div>
      <p className={`relative mt-2 text-xs ${featured ? 'text-emerald-100/70' : 'text-muted'}`}>
        {sub}
        {deltaLabel && <span className="opacity-70"> · {deltaLabel}</span>}
      </p>
      {children}
    </div>
  );
}

function QuickAction({ icon: Icon, title, description, onClick, accent = 'emerald', badge }) {
  const accents = {
    emerald: 'bg-emerald-800 text-white shadow-emerald-900/25',
    green: 'bg-green-500 text-white shadow-green-600/30',
    amber: 'bg-amber-600 text-white shadow-amber-700/25',
    slate: 'bg-slate-900 text-white shadow-slate-900/20',
  };
  return (
    <button
      type="button"
      onClick={onClick}
      className="focus-ring group relative flex items-center gap-3.5 rounded-2xl border border-slate-200/70 bg-white p-4 text-left shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lift"
    >
      <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl shadow-md transition-transform duration-200 group-hover:scale-105 ${accents[accent]}`}>
        <Icon className="h-[18px] w-[18px]" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 text-sm font-semibold text-ink">
          {title}
          {badge != null && badge > 0 && (
            <span className="num rounded-full bg-amber-100 px-1.5 py-px text-[10px] font-bold text-amber-700">{badge}</span>
          )}
        </span>
        <span className="mt-0.5 block truncate text-xs text-muted">{description}</span>
      </span>
      <ArrowRight className="h-4 w-4 shrink-0 text-slate-300 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-green-600" />
    </button>
  );
}

/* ------------------------------------------------------------------ */

const CERT_LABEL = { export: 'Export', grade_a: 'Grade A', class_b: 'Class B', class_c: 'Class C' };

function CoopPoolsCard({ pools, onNavigate }) {
  const user = useSession();
  const manager = user?.role === 'coop_manager';
  return (
    <div className="card flex flex-col xl:col-span-2">
      <div className="flex flex-wrap items-start justify-between gap-3 px-5 pb-3 pt-5">
        <div>
          <h2 className="flex items-center gap-2 text-base font-bold text-ink">
            <Users className="h-4 w-4 text-emerald-700" /> Active cooperative pools
          </h2>
          <p className="text-xs text-muted">Smallholder lots aggregated into one institutional-size contract</p>
        </div>
        {pools && (
          <div className="flex flex-wrap items-center gap-2 text-xs">
            {manager && (
              <button type="button" onClick={() => onNavigate('coop')} className="btn-action px-3 py-1.5 text-xs">
                <Users className="h-3.5 w-3.5" /> Build a pool
              </button>
            )}
            <span className="num rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-800">{formatVolume(pools.pooled_volume_kg, { compact: true })} pooled</span>
            <span className="num rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600">{pools.member_lots} member lots</span>
          </div>
        )}
      </div>

      {!pools ? (
        <div className="space-y-2 px-5 pb-5">
          <Skeleton className="h-12" />
          <Skeleton className="h-12" />
          <Skeleton className="h-12" />
        </div>
      ) : (
        <>
          {/* ≥ md: table */}
          <div className="hidden px-2 pb-2 md:block">
            <table className="w-full text-sm">
              <thead className="text-[11px] uppercase tracking-wide text-slate-400">
                <tr>
                  <th className="px-3 py-2 text-left font-semibold">Pool</th>
                  <th className="px-3 py-2 text-left font-semibold">Cooperative</th>
                  <th className="px-3 py-2 text-right font-semibold">Farmers</th>
                  <th className="px-3 py-2 text-left font-semibold">Sold</th>
                  <th className="px-3 py-2 text-right font-semibold">Price</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {pools.items.map((p) => (
                  <tr key={p.id} className="cursor-pointer transition-colors hover:bg-slate-50" onClick={() => onNavigate('marketplace')}>
                    <td className="px-3 py-3">
                      <p className="font-semibold text-ink">{p.crop}</p>
                      <p className="flex items-center gap-1.5 text-[11px] text-muted">
                        <span className="font-mono">{p.reference_code}</span>
                        {p.certified_grade && (
                          <span className="inline-flex items-center gap-0.5 rounded bg-emerald-50 px-1 font-semibold text-emerald-800">
                            <ShieldCheck className="h-2.5 w-2.5" /> {CERT_LABEL[p.certified_grade]}
                          </span>
                        )}
                        {p.pending_offers > 0 && <span className="rounded bg-amber-50 px-1 font-semibold text-amber-700">{p.pending_offers} offer{p.pending_offers > 1 ? 's' : ''}</span>}
                      </p>
                    </td>
                    <td className="max-w-[220px] px-3 py-3">
                      <p className="truncate text-slate-700">{p.cooperative}</p>
                      <p className="text-[11px] text-muted">{p.municipality}</p>
                    </td>
                    <td className="num px-3 py-3 text-right font-semibold text-slate-700">{p.members}</td>
                    <td className="w-40 px-3 py-3">
                      <div className="num flex justify-between text-[11px] text-muted">
                        <span>{formatVolume(p.stock_volume_kg - p.available_volume_kg, { compact: true })}</span>
                        <span>{p.sold_pct}%</span>
                      </div>
                      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div className="h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-600" style={{ width: `${Math.max(p.sold_pct, 2)}%` }} />
                      </div>
                    </td>
                    <td className="num px-3 py-3 text-right font-bold text-ink">
                      {formatPeso(p.unit_price)}
                      <span className="text-[11px] font-medium text-muted">/kg</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* < md: stacked cards */}
          <ul className="space-y-2 px-4 pb-4 md:hidden">
            {pools.items.map((p) => (
              <li key={p.id}>
                <button type="button" onClick={() => onNavigate('marketplace')} className="focus-ring w-full rounded-xl border border-slate-200/70 p-3 text-left transition-colors hover:bg-slate-50">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-ink">{p.crop}</p>
                      <p className="truncate text-[11px] text-muted">{p.cooperative}</p>
                    </div>
                    <p className="num shrink-0 text-sm font-bold text-ink">{formatPeso(p.unit_price)}</p>
                  </div>
                  <div className="num mt-2 flex items-center justify-between text-[11px] text-muted">
                    <span>
                      {p.members} farmers · {formatVolume(p.stock_volume_kg, { compact: true })}
                    </span>
                    <span className="font-semibold text-amber-700">{p.sold_pct}% sold</span>
                  </div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-600" style={{ width: `${Math.max(p.sold_pct, 2)}%` }} />
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}

function LogisticsCard({ logistics, onNavigate }) {
  if (!logistics) {
    return (
      <div className="card space-y-3 p-5">
        <Skeleton className="h-4 w-36" />
        <Skeleton className="h-10 w-40" />
        <Skeleton className="h-20" />
      </div>
    );
  }
  const f = logistics.fuel;
  return (
    <div className="card flex flex-col overflow-hidden">
      <div className="relative bg-gradient-to-br from-emerald-800 to-emerald-950 p-5 text-white">
        <div className="pointer-events-none absolute -right-8 -top-10 h-32 w-32 rounded-full bg-green-400/20 blur-2xl" />
        <p className="relative flex items-center gap-2 text-[13px] font-medium text-emerald-100/80">
          <PiggyBank className="h-4 w-4 text-green-300" /> Hakot freight savings
        </p>
        <p className="num relative mt-2 font-display text-[28px] font-bold leading-none">{formatPesoCompact(logistics.total_savings)}</p>
        <p className="num relative mt-1.5 text-xs text-emerald-100/75">
          {logistics.savings_pct}% less than solo trucking · {logistics.farmers_pooled} farmers · {formatVolume(logistics.pooled_volume_kg, { compact: true })}
        </p>
      </div>
      <div className="flex flex-1 flex-col gap-3 p-5">
        <div className="grid grid-cols-3 gap-2 text-center">
          {[
            ['Forming', logistics.open_pools],
            ['In transit', logistics.in_transit],
            ['Delivered', logistics.delivered_pools],
          ].map(([label, v]) => (
            <div key={label} className="rounded-xl bg-slate-50 py-2">
              <p className="num text-lg font-bold text-ink">{v}</p>
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted">{label}</p>
            </div>
          ))}
        </div>
        {f?.surcharge && (
          <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-3">
            <p className="flex items-center gap-1.5 text-xs font-bold text-amber-900">
              <Fuel className="h-3.5 w-3.5" /> +{f.surcharge.short}% fuel surcharge applied
            </p>
            <p className="num mt-0.5 text-[11px] text-amber-800/80">
              Diesel {formatPeso(f.diesel_price)}/L (Region {f.region_code}) · {f.diesel_delta_pct > 0 ? '+' : ''}
              {f.diesel_delta_pct}% vs baseline · short / medium / long haul +{f.surcharge.short}% / +{f.surcharge.medium}% / +{f.surcharge.long}%
            </p>
          </div>
        )}
        <button type="button" onClick={() => onNavigate('freight')} className="btn-ghost mt-auto w-full text-xs">
          <Truck className="h-3.5 w-3.5" /> Open Hakot calculator
        </button>
      </div>
    </div>
  );
}

function MarketSignalsStrip({ signals, onNavigate }) {
  if (signals && signals.length === 0) return null;
  return (
    <section aria-label="Hub price alerts">
      <div className="mb-3 flex items-end justify-between">
        <div>
          <h2 className="flex items-center gap-2 text-base font-bold text-ink">
            <AlertTriangle className="h-4 w-4 text-amber-600" /> Trading hub price alerts
          </h2>
          <p className="text-xs text-muted">7-day change in the weighted hub index (verified contracts + DA price feeds)</p>
        </div>
        <button type="button" onClick={() => onNavigate('prices')} className="focus-ring rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
          Price index
        </button>
      </div>
      <div className="scroll-thin -mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-5">
        {(signals ?? Array.from({ length: 5 }, (_, i) => ({ key: i }))).map((sig, i) =>
          sig.label ? (
            <div key={`${sig.hub_id}-${sig.crop_variety_id}`} className="card w-[240px] shrink-0 snap-start p-4 sm:w-auto">
              <p className="truncate text-sm font-semibold text-ink">{sig.variety}</p>
              <p className="truncate text-[11px] text-muted">
                {sig.hub} · {formatPeso(sig.index_price)}/kg
              </p>
              <MarketSignalBadge signal={sig} variant="pill" className="mt-2.5" />
            </div>
          ) : (
            <div key={i} className="card w-[240px] shrink-0 space-y-2 p-4 sm:w-auto">
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-3 w-20" />
              <Skeleton className="h-5 w-32 rounded-full" />
            </div>
          ),
        )}
      </div>
    </section>
  );
}

export default function DashboardOverview({ onNavigate = () => {} }) {
  const user = useSession();
  const summary = useAsync((signal) => api.dashboardSummary({ signal }), [user?.id], 15000);
  const ticker = useAsync((signal) => api.priceTicker({ limit: 6 }, { signal }), [], 30000);
  const fresh = useAsync((signal) => api.listings({ sort: 'newest', per_page: 4, status: ['active', 'upcoming'] }, { signal }), [], 15000);

  const s = summary.data;
  const firstName = user?.name?.split(' ')[0];

  const trends = useMemo(() => {
    if (!s) return {};
    return {
      listings: s.trends.listings_created.map((p) => p.value),
      offers: s.trends.offers_created.map((p) => p.value),
      contracts: s.trends.contract_value.map((p) => p.value),
      listingsWow: weekOverWeek(s.trends.listings_created),
      offersWow: weekOverWeek(s.trends.offers_created),
    };
  }, [s]);

  const topMover = ticker.data?.data?.[0];

  return (
    <div className="space-y-6">
      {/* Header */}
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="animate-fade-up">
          <p className="text-sm font-medium text-emerald-700">{formatDateWeekday(new Date().toISOString().slice(0, 10))}</p>
          <h1 className="mt-1 text-2xl font-extrabold text-ink sm:text-[28px]">
            {greeting()}
            {firstName ? `, ${firstName}` : ''}
          </h1>
          <p className="mt-1 max-w-xl text-sm text-muted">
            {topMover ? (
              <>
                <span className="font-medium text-slate-700">{topMover.name}</span> moved{' '}
                <span className={topMover.change_7d_pct >= 0 ? 'font-semibold text-amber-600' : 'font-semibold text-green-700'}>
                  {formatPct(topMover.change_7d_pct)}
                </span>{' '}
                this week. Here&apos;s what&apos;s moving across the supply chain.
              </>
            ) : (
              "Here's what's moving across the supply chain today."
            )}
          </p>
        </div>
        <div className="flex gap-2">
          <button type="button" className="btn-ghost" onClick={() => onNavigate('prices')}>
            <TrendingUp className="h-4 w-4" /> Price index
          </button>
          <button type="button" className="btn-primary" onClick={() => onNavigate('marketplace')}>
            <Handshake className="h-4 w-4" /> Open marketplace
          </button>
        </div>
      </header>

      <ErrorNote error={summary.error} onRetry={summary.reload} />

      {/* KPI row */}
      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key metrics">
        <KpiCard
          featured
          loading={!s}
          icon={Wheat}
          label="Tradable supply"
          value={s && formatVolume(s.listings.available_volume_kg, { compact: true })}
          sub={s && `${s.listings.active} harvesting · ${s.listings.upcoming} upcoming lots`}
          trend={trends.listings}
          delta={trends.listingsWow}
          deltaLabel="new lots vs last week"
        />
        <KpiCard
          loading={!s}
          delay={60}
          icon={Sprout}
          label="Listed market value"
          value={s && formatPesoCompact(s.listings.market_value)}
          sub={s && `${formatPct(s.listings.verified_pct, { signed: false, digits: 0 })} from verified sellers`}
        >
          {s && (
            <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full rounded-full bg-gradient-to-r from-emerald-700 to-green-500 transition-[width] duration-700" style={{ width: `${s.listings.verified_pct}%` }} />
            </div>
          )}
        </KpiCard>
        <KpiCard
          loading={!s}
          delay={120}
          tone="amber"
          icon={BellRing}
          label="Pending offers"
          value={s && formatNumber(s.offers.pending)}
          sub={s && `${formatPesoCompact(s.offers.pending_value)} under negotiation`}
          trend={trends.offers}
          delta={trends.offersWow}
          deltaLabel="w/w"
        />
        <KpiCard
          loading={!s}
          delay={180}
          icon={FileSignature}
          label="Live contracts"
          value={s && formatPesoCompact(s.contracts.live_value)}
          sub={s && `${s.contracts.live} contracts · ${formatVolume(s.contracts.live_volume_kg, { compact: true })}`}
          trend={trends.contracts}
        >
          {s && (
            <div className="mt-3">
              <div className="flex justify-between text-[11px] text-muted">
                <span>Delivered</span>
                <span className="num font-semibold text-slate-700">{formatPct(s.contracts.delivered_pct, { signed: false, digits: 0 })}</span>
              </div>
              <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full bg-green-500 transition-[width] duration-700" style={{ width: `${s.contracts.delivered_pct}%` }} />
              </div>
            </div>
          )}
        </KpiCard>
      </section>

      {/* Quick actions */}
      <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Quick actions">
        {user?.role !== 'admin' && <QuickAction icon={PackagePlus} title="Post a harvest" description="Drafts save offline in the field" onClick={() => onNavigate(user ? 'post' : 'marketplace')} />}
        <QuickAction
          icon={Radar}
          accent="green"
          title="Match my demand"
          description={s ? `${s.demand.count} open buyer requests · ${formatVolume(s.demand.volume_kg, { compact: true })}` : 'Find nearby supply that fits'}
          onClick={() => onNavigate('marketplace')}
        />
        <QuickAction
          icon={Truck}
          accent="amber"
          title="Hakot calculator"
          description={s ? `${s.logistics.open_pools} trucks forming · save ~${Math.round(s.logistics.savings_pct)}%` : 'Share a truck to the bagsakan'}
          onClick={() => onNavigate('freight')}
        />
        <QuickAction icon={TrendingUp} accent="slate" title="Price alerts" description="Track farm-gate swings by crop" onClick={() => onNavigate('prices')} />
      </section>

      {/* Chart + movers */}
      <section className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <PriceAnalyticsChart className="xl:col-span-2" compact defaultVarietyIds={[5, 8, 9]} title="Price pulse" />

        <div className="card flex flex-col">
          <div className="flex items-center justify-between px-5 pb-3 pt-5">
            <div>
              <h2 className="text-base font-bold text-ink">Market movers</h2>
              <p className="text-xs text-muted">7-day change · national farm-gate avg</p>
            </div>
            <button type="button" onClick={() => onNavigate('prices')} className="focus-ring rounded-lg px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
              View all
            </button>
          </div>
          <ul className="flex-1 divide-y divide-slate-100 px-2 pb-2">
            {(ticker.data?.data ?? Array.from({ length: 6 }, (_, i) => ({ crop_variety_id: `s${i}` }))).map((t) => (
              <li key={t.crop_variety_id} className="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors hover:bg-slate-50">
                {t.name ? (
                  <>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-ink">{t.name}</p>
                      <p className="truncate text-[11px] text-muted">{t.local_name} · {t.category}</p>
                    </div>
                    <Sparkline values={t.sparkline} width={64} height={26} tone={t.change_7d_pct >= 0 ? 'amber' : 'emerald'} />
                    <div className="w-20 text-right">
                      <p className="num text-sm font-bold text-ink">{formatPeso(t.price)}</p>
                      {/* Price rises are a cost signal for buyers → amber/rose; drops → green */}
                      <TrendDelta value={t.change_7d_pct} invert className="mt-0.5" />
                    </div>
                  </>
                ) : (
                  <>
                    <div className="flex-1 space-y-1.5">
                      <Skeleton className="h-3.5 w-32" />
                      <Skeleton className="h-2.5 w-20" />
                    </div>
                    <Skeleton className="h-7 w-16" />
                  </>
                )}
              </li>
            ))}
          </ul>
        </div>
      </section>

      {/* Coop pools + logistics */}
      <section className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <CoopPoolsCard pools={s?.coop_pools} onNavigate={onNavigate} />
        <LogisticsCard logistics={s?.logistics} onNavigate={onNavigate} />
      </section>

      {/* Hub market signals */}
      <MarketSignalsStrip signals={s?.market_signals} onNavigate={onNavigate} />

      {/* Fresh listings */}
      <section className="card">
        <div className="flex items-center justify-between px-5 pb-2 pt-5">
          <div>
            <h2 className="text-base font-bold text-ink">Fresh on the marketplace</h2>
            <p className="text-xs text-muted">Newest harvest lots from verified farms and consolidators</p>
          </div>
          <button type="button" onClick={() => onNavigate('marketplace')} className="btn-ghost px-3 py-2 text-xs">
            Browse all <ArrowRight className="h-3.5 w-3.5" />
          </button>
        </div>
        <div className="grid grid-cols-1 gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-4">
          {(fresh.data?.data ?? Array.from({ length: 4 }, (_, i) => ({ id: `s${i}` }))).map((l) =>
            l.title ? (
              <button
                key={l.id}
                type="button"
                onClick={() => onNavigate('marketplace')}
                className="focus-ring group bg-white p-5 text-left transition-colors hover:bg-slate-50/70"
              >
                <div className="flex flex-wrap items-center gap-1.5">
                  <StatusPill status={l.status} pulse />
                  <CertifiedBadge quality={l.quality} compact />
                  {l.is_verified && <VerifiedBadge compact />}
                  {l.market_signal?.label && <MarketSignalBadge signal={l.market_signal} variant="pill" />}
                </div>
                <p className="mt-3 line-clamp-1 text-sm font-semibold text-ink group-hover:text-emerald-800">{l.crop.variety}</p>
                <p className="mt-0.5 flex items-center gap-1 text-xs text-muted">
                  <MapPin className="h-3 w-3" /> {l.location.municipality}, {l.location.province}
                </p>
                <div className="mt-3 flex items-end justify-between">
                  <div>
                    <p className="num text-lg font-bold text-ink">
                      {formatPeso(l.unit_price)}
                      <span className="text-xs font-medium text-muted">/kg</span>
                    </p>
                    <p className="num text-[11px] text-muted">{formatVolume(l.available_volume_kg, { compact: true })} available</p>
                  </div>
                  <p className="flex items-center gap-1 text-[11px] text-slate-500">
                    <CalendarClock className="h-3 w-3" />
                    {formatDateRange(l.harvest_date_start, l.harvest_date_end).split(' – ')[0]}
                  </p>
                </div>
              </button>
            ) : (
              <div key={l.id} className="space-y-3 bg-white p-5">
                <Skeleton className="h-5 w-24 rounded-full" />
                <Skeleton className="h-4 w-36" />
                <Skeleton className="h-6 w-24" />
              </div>
            ),
          )}
        </div>
      </section>
    </div>
  );
}
