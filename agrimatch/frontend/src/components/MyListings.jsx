import { useEffect, useState } from 'react';
import { Archive, PackagePlus, RefreshCw, Trash2 } from 'lucide-react';
import { api, useSession } from '../lib/api';
import { ErrorNote, Skeleton } from './ui';
import ListingDraftForm from './ListingDraftForm';

export default function MyListings() {
  const user = useSession();
  const [refresh, setRefresh] = useState(0);
  const [showForm, setShowForm] = useState(false);
  const [volumes, setVolumes] = useState({});
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError(null);
    api.myListings({ status: ['draft', 'active', 'upcoming', 'reserved', 'pooled', 'sold_out', 'expired'], per_page: 50 }).then((result) => { if (alive) setData(result); }).catch((err) => { if (alive) setError(err); }).finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [user?.id, refresh]);
  const listings = data?.data ?? [];
  const reload = () => setRefresh((value) => value + 1);

  async function saveStock(listing) {
    const available = Number(volumes[listing.id] ?? listing.available_volume_kg);
    if (!Number.isFinite(available) || available < 0) return setError(new Error('Enter an available volume of zero or more.'));
    setBusyId(listing.id); setError(null); setNotice(null);
    try {
      const status = available === 0 ? 'sold_out' : new Date(`${listing.harvest_date_start}T00:00:00`) > new Date() ? 'upcoming' : 'active';
      await api.updateListing(listing.id, { available_volume_kg: available, status });
      setNotice(`${listing.reference_code} inventory updated.`); reload();
    } catch (err) { setError(err); }
    finally { setBusyId(null); }
  }

  async function remove(listing) {
    if (!window.confirm(`Delete ${listing.title}? This cannot be undone.`)) return;
    setBusyId(listing.id); setError(null); setNotice(null);
    try { await api.deleteListing(listing.id); setNotice(`${listing.reference_code} was deleted.`); reload(); }
    catch (err) { setError(err); }
    finally { setBusyId(null); }
  }

  if (!['farmer', 'coop_manager', 'trader'].includes(user?.role)) return <div className="card p-6 text-sm text-muted">Farmer inventory access is required.</div>;
  return <div className="space-y-5">
    <header className="flex flex-wrap items-end justify-between gap-3"><div><p className="text-sm font-semibold text-emerald-700">Farmer inventory</p><h1 className="mt-1 text-2xl font-extrabold text-ink">My Listings / Post Harvest</h1><p className="mt-1 text-sm text-muted">Manage available volumes, mark stock out of stock, or remove eligible listings.</p></div><div className="flex gap-2"><button className="btn-ghost" onClick={reload}><RefreshCw className="h-4 w-4"/> Refresh</button><button className="btn-action" onClick={() => setShowForm((value) => !value)}><PackagePlus className="h-4 w-4"/>{showForm ? 'Hide form' : 'Post harvest'}</button></div></header>
    {showForm && <ListingDraftForm onDone={() => { setShowForm(false); reload(); }} />}
    {error && <ErrorNote error={error} />}{notice && <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
    {loading ? <Skeleton className="h-40"/> : listings.length ? <div className="grid gap-3 lg:grid-cols-2">{listings.map((listing) => {
      const out = Number(listing.available_volume_kg) <= 0 || listing.status === 'sold_out';
      return <article key={listing.id} className={`card flex flex-col gap-4 p-4 sm:flex-row ${out ? 'grayscale-[.65] opacity-75' : ''}`}>
        {listing.image_url && <img src={listing.image_url} alt={listing.title} className="h-24 w-28 rounded-xl object-cover"/>}
        <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold text-ink">{listing.title}</h2><span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase ${out ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'}`}>{out ? 'Out of Stock' : listing.status}</span></div><p className="mt-1 text-xs text-muted">{listing.reference_code} · {listing.location?.barangay}, {listing.location?.municipality} · {listing.crop?.variety}</p><p className="num mt-1 text-xs text-muted">{listing.available_volume_kg} kg available of {listing.stock_volume_kg} kg</p>
          <div className="mt-3 flex flex-wrap items-center gap-2"><input aria-label={`Available kilograms for ${listing.title}`} className="input num w-32" type="number" min="0" max={listing.stock_volume_kg} value={volumes[listing.id] ?? listing.available_volume_kg} onChange={(e) => setVolumes((current) => ({ ...current, [listing.id]: e.target.value }))}/><button className="btn-action px-3 py-2 text-xs" onClick={() => saveStock(listing)} disabled={busyId === listing.id}>{Number(volumes[listing.id] ?? listing.available_volume_kg) === 0 && <Archive className="h-3.5 w-3.5"/>}{Number(volumes[listing.id] ?? listing.available_volume_kg) === 0 ? 'Mark out of stock' : 'Save stock'}</button><button className="btn-ghost px-3 py-2 text-xs text-rose-700" onClick={() => remove(listing)} disabled={busyId === listing.id}><Trash2 className="h-3.5 w-3.5"/> Delete Product</button></div>
        </div>
      </article>;
    })}</div> : <div className="card p-8 text-center text-sm text-muted">You have no harvest listings yet. Use Post harvest to create one.</div>}
  </div>;
}
