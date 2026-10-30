import { useState } from 'react';
import { ClipboardCheck, Loader2, RefreshCw } from 'lucide-react';
import { api, useSession } from '../lib/api';
import { ErrorNote, Skeleton, useAsync } from './ui';

export default function InspectionQueue({ historyOnly = false }) {
  const user = useSession();
  const queue = useAsync(async (signal) => {
    if (!historyOnly) return api.inspections({ status: 'open', per_page: 50 }, { signal });
    const results = await Promise.all(['passed', 'failed', 'cancelled'].map((status) => api.inspections({ status, per_page: 50 }, { signal })));
    return { data: results.flatMap((result) => result.data?.data ?? result.data ?? []) };
  }, [user?.id, historyOnly], 12000);
  const [forms, setForms] = useState({});
  const [busyId, setBusyId] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const rows = queue.data?.data?.data ?? queue.data?.data ?? [];

  async function accept(item) {
    setBusyId(item.id); setError(null); setNotice(null);
    try { await api.startInspection(item.id); setNotice(`${item.reference_code} accepted and assigned to you.`); queue.reload(); }
    catch (err) { setError(err.message); }
    finally { setBusyId(null); }
  }

  async function decline(item) {
    const remarks = window.prompt('Why are you declining this inspection request?');
    if (!remarks?.trim()) return;
    setBusyId(item.id); setError(null); setNotice(null);
    try { await api.declineInspection(item.id, { remarks }); setNotice(`${item.reference_code} declined; the seller was notified.`); queue.reload(); }
    catch (err) { setError(err.message); }
    finally { setBusyId(null); }
  }

  async function submitResult(item) {
    const value = forms[item.id] || {};
    setBusyId(item.id); setError(null); setNotice(null);
    try {
      await api.recordInspection(item.id, {
        result: value.result || 'passed',
        certified_grade: value.result === 'failed' ? null : value.certified_grade || 'grade_a',
        sample_size_kg: Number(value.sample_size_kg || 1),
        defect_pct: Number(value.defect_pct || 0),
        pesticide_residue_passed: value.pesticide_residue_passed !== false,
        remarks: value.remarks || null,
        image: value.image || undefined,
      });
      setNotice(`${item.reference_code} inspection recorded and the seller notified.`); queue.reload();
    } catch (err) { setError(err.message); }
    finally { setBusyId(null); }
  }

  if (!['quality_inspector', 'admin'].includes(user?.role)) return <div className="card p-6 text-sm text-slate-600">Inspector access is required.</div>;

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div><p className="flex items-center gap-2 text-sm font-semibold text-sky-700"><ClipboardCheck className="h-4 w-4" /> Quality desk</p><h1 className="mt-1 text-2xl font-extrabold text-ink">{historyOnly ? 'Grading History' : 'Inspection Queue'}</h1><p className="mt-1 text-sm text-muted">{historyOnly ? 'Completed inspection outcomes and certificates.' : 'Accept or decline requests, then record the inspected grade.'}</p></div>
        <button type="button" className="btn-ghost" onClick={queue.reload}><RefreshCw className="h-4 w-4" /> Refresh</button>
      </header>
      {error && <ErrorNote error={{ message: error }} />}
      {notice && <p className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
      {queue.error && <ErrorNote error={queue.error} onRetry={queue.reload} />}
      {queue.loading && !queue.data ? <Skeleton className="h-32" /> : rows.length ? (
        <div className="grid gap-4 lg:grid-cols-2">
          {rows.map((item) => {
            const form = forms[item.id] || {};
            return (
              <article key={item.id} className="card space-y-3 p-5">
                <div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold text-sky-700">{item.reference_code} · {item.status}</p><h2 className="mt-1 font-bold text-ink">{item.listing?.title || item.listing?.reference_code}</h2><p className="mt-1 text-xs text-muted">{item.listing?.barangay}, {item.listing?.municipality} · {item.listing?.province?.name}</p></div><span className="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">{item.listing?.stock_volume_kg} kg</span></div>
                {historyOnly ? <div className="rounded-xl bg-slate-50 p-3 text-sm"><b>{item.status === 'passed' ? `Passed · ${item.certified_grade || 'graded'}` : item.status}</b><p className="mt-1 text-xs text-muted">{item.certificate_no || item.remarks || 'No additional grading note.'}</p>{item.image_url && <a className="mt-2 inline-block text-emerald-700 underline" href={item.image_url} target="_blank" rel="noreferrer">View inspection photo</a>}</div> : ['requested', 'scheduled'].includes(item.status) ? (
                  <div className="flex gap-2"><button type="button" className="btn-action" disabled={busyId === item.id} onClick={() => accept(item)}>{busyId === item.id ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Accept inspection'}</button><button type="button" className="btn-ghost" disabled={busyId === item.id} onClick={() => decline(item)}>Decline</button></div>
                ) : (
                  <div className="grid gap-2 sm:grid-cols-2">
                    <label className="text-xs text-slate-600">Result<select className="input mt-1" value={form.result || 'passed'} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, result: e.target.value } })}><option value="passed">Passed</option><option value="failed">Failed</option></select></label>
                    <label className="text-xs text-slate-600">Certified grade<select className="input mt-1" value={form.certified_grade || 'grade_a'} disabled={form.result === 'failed'} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, certified_grade: e.target.value } })}><option value="export">Export</option><option value="grade_a">Grade A</option><option value="class_b">Class B</option><option value="class_c">Class C</option></select></label>
                    <label className="text-xs text-slate-600">Sample size (kg)<input className="input mt-1" type="number" min="1" value={form.sample_size_kg || '1'} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, sample_size_kg: e.target.value } })} /></label>
                    <label className="text-xs text-slate-600">Defects (%)<input className="input mt-1" type="number" min="0" max="100" step="0.1" value={form.defect_pct || '0'} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, defect_pct: e.target.value } })} /></label>
                    <label className="flex items-center gap-2 text-xs text-slate-600 sm:col-span-2"><input type="checkbox" checked={form.pesticide_residue_passed !== false} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, pesticide_residue_passed: e.target.checked } })} /> Pesticide residue test passed</label>
                    <textarea className="input sm:col-span-2" rows={2} placeholder="Inspection notes (required for a failed result)" value={form.remarks || ''} onChange={(e) => setForms({ ...forms, [item.id]: { ...form, remarks: e.target.value } })} />
                    <label className="text-xs text-slate-600 sm:col-span-2">Inspection photo<input className="input mt-1" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setForms({ ...forms, [item.id]: { ...form, image: e.target.files?.[0] || null } })} /></label>
                    <button type="button" className="btn-primary sm:col-span-2" disabled={busyId === item.id} onClick={() => submitResult(item)}>{busyId === item.id ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Record inspection'}</button>
                  </div>
                )}
              </article>
            );
          })}
        </div>
      ) : <div className="card p-8 text-center text-sm text-muted">No inspection requests are waiting for you.</div>}
    </div>
  );
}
