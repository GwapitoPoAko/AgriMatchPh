import { Truck } from 'lucide-react';
import { api, useSession } from '../lib/api';
import { formatDate, formatPeso, formatVolume } from '../lib/format';
import { ErrorNote, Skeleton, useAsync } from './ui';

export default function MyDeliveries() {
  const user = useSession();
  const contracts = useAsync((signal) => api.contracts({ per_page: 50 }, { signal }), [user?.id], 15000);
  const rows = contracts.data?.data?.data ?? contracts.data?.data ?? [];
  return <section className="card p-4 sm:p-5">
    <header className="mb-4 flex items-start gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Truck className="h-5 w-5"/></span><div><h2 className="text-lg font-bold text-ink">My Deliveries</h2><p className="text-xs text-muted">Contracted orders, delivery windows and Hakot transport estimates.</p></div></header>
    {contracts.error && <ErrorNote error={contracts.error} onRetry={contracts.reload}/>}
    {contracts.loading && !contracts.data ? <Skeleton className="h-24"/> : rows.length ? <div className="grid gap-3 md:grid-cols-2">{rows.map((item) => <article key={item.id} className="rounded-xl border border-slate-200 p-4"><div className="flex items-start justify-between gap-2"><div><p className="font-bold text-ink">{item.listing?.variety?.name || item.listing?.title || 'AgriMatch delivery'}</p><p className="mt-1 text-xs text-muted">{item.contract_number} · {item.status?.replaceAll('_', ' ')}</p></div><span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase text-emerald-800">{item.delivery_terms?.replaceAll('_', ' ')}</span></div><div className="num mt-3 grid grid-cols-2 gap-2 text-xs"><span>{formatVolume(item.agreed_volume_kg)} contracted</span><b className="text-right">{formatPeso(item.total_value)}</b><span className="text-muted">Delivery window</span><span className="text-right text-muted">{item.delivery_start ? formatDate(item.delivery_start) : 'To be scheduled'}{item.delivery_end ? ` – ${formatDate(item.delivery_end)}` : ''}</span></div></article>)}</div> : <div className="rounded-xl bg-slate-50 p-5 text-center text-sm text-muted">No contracted deliveries yet. Accepted marketplace offers will appear here.</div>}
  </section>;
}
