import { useEffect, useState } from 'react';
import { RefreshCw, ShieldCheck, UserPlus } from 'lucide-react';
import { api, useSession } from '../lib/api';
import { formatDate, formatPeso } from '../lib/format';
import { ErrorNote, Skeleton, useAsync } from './ui';

const STAFF_ROLES = [
  ['driver', 'Driver'],
  ['quality_inspector', 'Quality inspector'],
  ['coop_manager', 'Cooperative manager'],
];

export default function SuperAdminDashboard({ section = 'governance' }) {
  const user = useSession();
  const staff = useAsync((signal) => api.adminStaff({ signal }), [user?.id], 15000);
  const disputes = useAsync((signal) => api.adminDisputes({ signal }), [user?.id], 15000);
  const fuel = useAsync((signal) => api.fuelPolicy({ signal }), [user?.id], 30000);
  const provinces = useAsync((signal) => api.provinces({ signal }), []);
  const audit = useAsync((signal) => api.auditLogs({ per_page: 12 }, { signal }), [user?.id], 15000);
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: 'password', role: 'driver' });
  const [policy, setPolicy] = useState({ baseline_diesel: '', surcharge_multiplier: '' });
  const [fuelLog, setFuelLog] = useState({ region_code: '', fuel_type: 'diesel', effective_date: new Date().toISOString().slice(0, 10), price_per_liter: '', source: 'DOE Oil Monitor' });
  const [resolution, setResolution] = useState({});
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const data = fuel.data?.data;
    if (data) setPolicy({ baseline_diesel: data.baseline_diesel, surcharge_multiplier: data.surcharge_multiplier });
  }, [fuel.data]);
  useEffect(() => {
    const regions = [...new Set((provinces.data?.data ?? []).map((province) => province.region_code))];
    if (regions.length && !fuelLog.region_code) setFuelLog((current) => ({ ...current, region_code: regions[0] }));
  }, [provinces.data, fuelLog.region_code]);

  const reload = () => { staff.reload(); disputes.reload(); fuel.reload(); audit.reload(); };
  if (user?.role !== 'admin') return <div className="card p-6 text-sm text-slate-600">Platform administrator access is required.</div>;

  async function createStaff(event) {
    event.preventDefault();
    setBusy(true); setError(null); setNotice(null);
    try {
      await api.createStaff(form);
      setForm({ name: '', email: '', phone: '', password: 'password', role: 'driver' });
      setNotice('Staff account created.'); staff.reload(); audit.reload();
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  }

  async function updateStaff(id, body) {
    setError(null);
    try { await api.updateStaff(id, body); staff.reload(); audit.reload(); }
    catch (err) { setError(err.message); }
  }

  async function saveFuelPolicy(event) {
    event.preventDefault(); setBusy(true); setError(null); setNotice(null);
    try {
      await api.updateFuelPolicy({ baseline_diesel: Number(policy.baseline_diesel), surcharge_multiplier: Number(policy.surcharge_multiplier) });
      setNotice('Fuel policy saved and open freight pools repriced.'); fuel.reload(); audit.reload();
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  }

  async function recordFuelPrice(event) {
    event.preventDefault(); setBusy(true); setError(null); setNotice(null);
    try {
      await api.recordFuelPrice({ ...fuelLog, price_per_liter: Number(fuelLog.price_per_liter) });
      setNotice('DOE pump price recorded and affected open freight pools repriced.');
      setFuelLog((current) => ({ ...current, price_per_liter: '' })); fuel.reload(); audit.reload();
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  }

  async function resolve(contractId, status) {
    const value = resolution[contractId];
    if (!value?.notes?.trim()) return setError('Enter a resolution note for the dispute.');
    setError(null);
    try {
      await api.resolveDispute(contractId, { resolution: status, notes: value.notes });
      setNotice('Dispute resolution recorded and both parties notified.');
      disputes.reload(); audit.reload();
    } catch (err) { setError(err.message); }
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="flex items-center gap-2 text-sm font-semibold text-emerald-700"><ShieldCheck className="h-4 w-4" /> Super admin</p>
          <h1 className="mt-1 text-2xl font-extrabold text-ink">Platform governance</h1>
          <p className="mt-1 text-sm text-muted">Manage internal staff, contract disputes, audit history and DOE-linked Hakot fuel policy.</p>
        </div>
        <button type="button" className="btn-ghost" onClick={reload}><RefreshCw className="h-4 w-4" /> Refresh</button>
      </header>

      {error && <ErrorNote error={{ message: error }} />}
      {notice && <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{notice}</p>}

      <div className="grid gap-5 xl:grid-cols-2">
        {(section === 'all' || section === 'users') && <section className="card p-5">
          <h2 className="text-base font-bold text-ink">Internal staff accounts</h2>
          <form onSubmit={createStaff} className="mt-4 grid gap-3 sm:grid-cols-2">
            <input className="input" aria-label="Staff name" placeholder="Full name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            <input className="input" aria-label="Staff email" placeholder="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
            <input className="input" aria-label="Staff phone" placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            <input className="input" aria-label="Temporary password" placeholder="Temporary password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
            <select className="input" aria-label="Staff role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
              {STAFF_ROLES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <button className="btn-primary" type="submit" disabled={busy}><UserPlus className="h-4 w-4" /> Add staff</button>
          </form>
          <div className="mt-5 divide-y divide-slate-100">
            {(staff.data?.data ?? []).map((member) => (
              <div key={member.id} className="flex flex-wrap items-center gap-2 py-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-ink">{member.name}</p>
                  <p className="truncate text-xs text-muted">{member.email} · {member.profile?.business_name || member.role}</p>
                </div>
                <select className="input w-auto py-2 text-xs" aria-label={`Role for ${member.name}`} value={member.role} onChange={(e) => updateStaff(member.id, { role: e.target.value })}>
                  {STAFF_ROLES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
                <button type="button" className={member.status === 'active' ? 'btn-ghost px-2 py-1.5 text-xs' : 'btn-action px-2 py-1.5 text-xs'} onClick={() => updateStaff(member.id, { status: member.status === 'active' ? 'suspended' : 'active' })}>
                  {member.status === 'active' ? 'Suspend' : 'Reactivate'}
                </button>
              </div>
            ))}
            {staff.loading && !staff.data && <Skeleton className="mt-4 h-16" />}
            {!staff.loading && !(staff.data?.data ?? []).length && <p className="py-5 text-sm text-muted">No internal staff accounts yet.</p>}
          </div>
        </section>}

        {(section === 'all' || section === 'settings') && <section className="card p-5">
          <h2 className="text-base font-bold text-ink">DOE diesel baseline and surcharge</h2>
          <p className="mt-1 text-xs text-muted">Baseline compares current regional DOE logs against the configured benchmark; multiplier scales the distance-band surcharge matrix.</p>
          <form onSubmit={saveFuelPolicy} className="mt-4 grid gap-3 sm:grid-cols-3">
            <label className="text-xs font-medium text-slate-600">Baseline diesel (₱/L)
              <input className="input mt-1" type="number" step="0.01" min="20" max="200" value={policy.baseline_diesel} onChange={(e) => setPolicy({ ...policy, baseline_diesel: e.target.value })} required />
            </label>
            <label className="text-xs font-medium text-slate-600">Surcharge multiplier
              <input className="input mt-1" type="number" step="0.05" min="0" max="3" value={policy.surcharge_multiplier} onChange={(e) => setPolicy({ ...policy, surcharge_multiplier: e.target.value })} required />
            </label>
            <div className="flex items-end"><button className="btn-primary w-full" type="submit" disabled={busy || fuel.data?.data?.persisted === false}>Save policy</button></div>
          </form>
          <p className="mt-3 text-xs text-muted">Latest diesel log: {fuel.data?.data?.latest_doe_logged_diesel ? `${formatPeso(fuel.data.data.latest_doe_logged_diesel)}/L` : 'No DOE log recorded yet'}.</p>
          {fuel.data?.data?.persisted === false && <p className="mt-2 text-xs text-amber-700">Apply the additive platform settings migration before saving these controls.</p>}
        </section>}
        {(section === 'all' || section === 'settings') && <section className="card p-5 xl:col-span-2">
          <h2 className="text-base font-bold text-ink">Record a DOE pump price</h2>
          <form onSubmit={recordFuelPrice} className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label className="text-xs font-medium text-slate-600">Region<select className="input mt-1" value={fuelLog.region_code} onChange={(e) => setFuelLog({ ...fuelLog, region_code: e.target.value })}>{[...new Set((provinces.data?.data ?? []).map((province) => province.region_code))].map((code) => <option key={code} value={code}>{code}</option>)}</select></label>
            <label className="text-xs font-medium text-slate-600">Fuel<select className="input mt-1" value={fuelLog.fuel_type} onChange={(e) => setFuelLog({ ...fuelLog, fuel_type: e.target.value })}><option value="diesel">Diesel</option><option value="gasoline">Gasoline</option></select></label>
            <label className="text-xs font-medium text-slate-600">Effective date<input className="input mt-1" type="date" value={fuelLog.effective_date} onChange={(e) => setFuelLog({ ...fuelLog, effective_date: e.target.value })} required /></label>
            <label className="text-xs font-medium text-slate-600">Price (₱/L)<input className="input mt-1" type="number" min="20" max="200" step="0.01" value={fuelLog.price_per_liter} onChange={(e) => setFuelLog({ ...fuelLog, price_per_liter: e.target.value })} required /></label>
            <div className="flex items-end"><button className="btn-action w-full" type="submit" disabled={busy || !fuelLog.region_code}>Record DOE price</button></div>
          </form>
        </section>}
      </div>

      {(section === 'all' || section === 'governance') && <section className="card p-5">
        <h2 className="text-base font-bold text-ink">Open contract disputes</h2>
        <div className="mt-4 grid gap-3 lg:grid-cols-2">
          {(disputes.data?.data ?? []).map((item) => (
            <article key={item.id} className="rounded-xl border border-amber-200 bg-amber-50/40 p-4">
              <div className="flex flex-wrap justify-between gap-2"><b className="text-sm text-ink">{item.contract_number}</b><span className="text-xs text-muted">{item.listing?.reference_code} · {formatPeso(item.total_value)}</span></div>
              <p className="mt-1 text-xs text-slate-600">Buyer: {item.buyer?.name || '—'} · Seller: {item.seller?.name || '—'}</p>
              <p className="mt-2 line-clamp-2 text-xs text-slate-500">{item.notes || 'No dispute notes supplied.'}</p>
              <textarea className="input mt-3" rows={2} placeholder="Document the resolution and reasoning" value={resolution[item.id]?.notes ?? ''} onChange={(e) => setResolution({ ...resolution, [item.id]: { notes: e.target.value } })} />
              <div className="mt-2 flex gap-2">
                <button className="btn-action px-3 py-2 text-xs" type="button" onClick={() => resolve(item.id, 'completed')}>Resolve as completed</button>
                <button className="btn-ghost px-3 py-2 text-xs" type="button" onClick={() => resolve(item.id, 'cancelled')}>Cancel contract</button>
              </div>
            </article>
          ))}
          {disputes.loading && !disputes.data && <Skeleton className="h-32" />}
          {!disputes.loading && !(disputes.data?.data ?? []).length && <p className="text-sm text-muted">No open contract disputes.</p>}
        </div>
      </section>}

      {(section === 'all' || section === 'governance') && <section className="card p-5">
        <h2 className="text-base font-bold text-ink">Recent audit logs</h2>
        <div className="mt-3 divide-y divide-slate-100">
          {(audit.data?.data ?? []).map((entry) => (
            <div key={entry.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
              <span className="font-medium text-slate-700">{entry.event}</span>
              <span className="text-xs text-muted">{entry.user?.name || 'System'} · {formatDate(entry.created_at)}</span>
            </div>
          ))}
          {audit.loading && !audit.data && <Skeleton className="h-24" />}
        </div>
      </section>}
    </div>
  );
}
