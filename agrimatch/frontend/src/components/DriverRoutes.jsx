import { useState } from 'react';
import { History, MapPin, RefreshCw, Route, Truck } from 'lucide-react';
import { api, useSession } from '../lib/api';
import { ErrorNote, Skeleton, useAsync } from './ui';

export default function DriverRoutes({ historyOnly = false }) {
  const user = useSession();
  const pools = useAsync((signal) => api.freightPools({ scope: 'mine', ...(historyOnly ? { status: 'delivered' } : {}) }, { signal }), [user?.id, historyOnly], 12000);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const rows = pools.data?.data?.data ?? pools.data?.data ?? [];
  const select = async (pool) => {
    setSelectedId(pool.id); setLoadingDetail(true);
    try { const response = await api.freightPool(pool.id); setDetail(response.data); }
    catch { setDetail(null); }
    finally { setLoadingDetail(false); }
  };
  const route = detail?.quote?.route;
  const stops = route?.stops ?? [];
  const first = stops.find((s) => s.type === 'pickup');
  const last = stops.find((s) => s.type === 'hub');
  const validStops = stops.filter((s) => Number.isFinite(Number(s.lat)) && Number.isFinite(Number(s.lng)));
  const bbox = validStops.length ? `${Math.min(...validStops.map((s) => Number(s.lng))) - 0.05}%2C${Math.min(...validStops.map((s) => Number(s.lat))) - 0.05}%2C${Math.max(...validStops.map((s) => Number(s.lng))) + 0.05}%2C${Math.max(...validStops.map((s) => Number(s.lat))) + 0.05}` : null;
  const km = Number(route?.distance_km ?? detail?.pool?.route_distance_km ?? 0);
  const duration = km ? `${Math.floor(km / 30)} hrs ${Math.round((km % 30) / 30 * 60)} mins` : '—';
  if (user?.role !== 'driver' && user?.role !== 'admin') return <div className="card p-6 text-sm text-muted">Driver access is required.</div>;
  return <div className="space-y-5">
    <header className="flex items-end justify-between gap-3"><div><p className="text-sm font-semibold text-emerald-700">Driver dispatch</p><h1 className="mt-1 text-2xl font-extrabold text-ink">{historyOnly ? 'Trip Logs' : 'My Routes & Freight'}</h1><p className="mt-1 text-sm text-muted">Assigned freight pools, pickup addresses, route distance and trip estimates.</p></div><button className="btn-ghost" onClick={pools.reload}><RefreshCw className="h-4 w-4" /> Refresh</button></header>
    {pools.error && <ErrorNote error={pools.error} onRetry={pools.reload} />}
    {pools.loading && !pools.data && <Skeleton className="h-24" />}
    <div className="grid gap-4 lg:grid-cols-[minmax(260px,0.8fr)_1.2fr]">
      <div className="space-y-2">{rows.map((pool) => <button key={pool.id} onClick={() => select(pool)} className={`card w-full p-4 text-left ${selectedId === pool.id ? 'ring-2 ring-emerald-500' : ''}`}><span className="flex items-center justify-between gap-2"><b className="text-sm">{pool.reference_code}</b><span className="text-xs capitalize text-muted">{pool.status?.replaceAll('_', ' ')}</span></span><span className="mt-2 block text-xs text-muted">{pool.origin || pool.origin_municipality || 'Farm pickup'} → {pool.hub?.short_name || pool.trading_hub?.short_name || 'Trading hub'}</span><span className="num mt-1 block text-xs font-semibold">{pool.route_distance_km ? `${pool.route_distance_km} km` : 'Route details'}</span></button>)}{!pools.loading && !rows.length && <div className="card p-6 text-sm text-muted">No {historyOnly ? 'completed trips' : 'assigned freight pools'} found.</div>}</div>
      <section className="card min-h-72 overflow-hidden p-4">
        {!selectedId ? <div className="grid h-64 place-items-center text-center text-sm text-muted"><div><Truck className="mx-auto mb-2 h-8 w-8 text-emerald-700"/>Select a freight pool to view its route.</div></div> : loadingDetail ? <Skeleton className="h-64"/> : detail ? <div className="space-y-3"><div className="flex items-start justify-between gap-3"><div><h2 className="font-bold text-ink">{detail.pool?.origin_municipality || first?.label || 'Farm pickup'} to {last?.label || 'Trading hub'}</h2><p className="num mt-1 text-sm text-muted">{km.toFixed(0)} km · ~{duration}</p></div><Route className="h-5 w-5 text-emerald-700"/></div>
          <div className="grid gap-2 sm:grid-cols-2">{stops.map((stop) => <div key={stop.key} className="rounded-xl bg-slate-50 p-3"><p className="flex items-center gap-1 text-xs font-bold"><MapPin className="h-3.5 w-3.5 text-emerald-700"/>{stop.label}</p><p className="mt-1 text-xs text-muted">{[stop.barangay && `Brgy. ${stop.barangay}`, stop.municipality, stop.province].filter(Boolean).join(', ') || 'Address supplied with pool stop'}</p><p className="num mt-1 text-[11px] text-muted">{Number(stop.lat).toFixed(5)}, {Number(stop.lng).toFixed(5)}</p></div>)}</div>
          {bbox && <iframe title="Freight pool route map" className="h-64 w-full rounded-xl border border-slate-200" loading="lazy" src={`https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${last?.lat || first?.lat}%2C${last?.lng || first?.lng}`} />}
          {first?.lat && last?.lat && <a className="btn-ghost" target="_blank" rel="noreferrer" href={`https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${first.lat}%2C${first.lng}%3B${last.lat}%2C${last.lng}`}><Route className="h-4 w-4"/> Open driving route map</a>}
          <p className="text-xs text-muted"><History className="mr-1 inline h-3.5 w-3.5"/>Estimated duration uses a 30 km/h average for mountain roads.</p>
        </div> : <p className="text-sm text-muted">Route details are unavailable.</p>}
      </section>
    </div>
  </div>;
}
