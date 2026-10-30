import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle2, CloudOff, FileClock, Loader2, Save, Send, ShieldCheck, Trash2 } from 'lucide-react';
import { ApiError, api, useOnline, useSession } from '../lib/api';
import { drafts as draftStore, newId } from '../lib/offlineDb';
import { formatPeso, formatVolume, toIsoDate } from '../lib/format';
import { ErrorNote, Toast, useAsync } from './ui';
import FarmLocationInput from './FarmLocationInput';

const MANDATORY_INSPECTION_KG = 50000;

const emptyDraft = (user) => ({
  id: newId(),
  crop_variety_id: '',
  province_id: user?.profile?.province_id ? String(user.profile.province_id) : '',
  municipality: user?.profile?.municipality ?? '',
  barangay: user?.profile?.barangay ?? '',
  title: '',
  description: '',
  quality_grade: 'A',
  claimed_grade: '',
  is_organic: false,
  stock_volume_kg: '',
  minimum_order_kg: '',
  unit_price: '',
  price_negotiable: true,
  harvest_date_start: toIsoDate(new Date()),
  harvest_date_end: toIsoDate(new Date(Date.now() + 21 * 86400000)),
  request_inspection: false,
  preferred_inspection_date: '',
  pickup_latitude: '',
  pickup_longitude: '',
  image: null,
});

function Field({ id, label, error, hint, children, className = '' }) {
  return (
    <div className={className}>
      <label htmlFor={id} className="label">
        {label}
      </label>
      {children}
      {hint && !error && <p className="mt-1 text-[11px] text-muted">{hint}</p>}
      {error && <p className="mt-1 text-xs font-medium text-rose-600">{error}</p>}
    </div>
  );
}

/**
 * Offline-first harvest listing form. Every keystroke autosaves to IndexedDB,
 * so a farmer in a low-signal barangay never loses a draft. "Submit" sends
 * immediately when online, or queues in the outbox and syncs on reconnect
 * (idempotency keys guarantee a single listing even if replayed).
 */
export default function ListingDraftForm({ onDone = () => {} }) {
  const user = useSession();
  const online = useOnline();
  const crops = useAsync((signal) => api.crops({ signal }), []);
  const provinces = useAsync((signal) => api.provinces({ signal }), []);

  const [draft, setDraft] = useState(() => emptyDraft(user));
  const [saved, setSaved] = useState([]);
  const [savedAt, setSavedAt] = useState(null);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [toast, setToast] = useState(null);
  const dismissToast = useCallback(() => setToast(null), []);
  const dirty = useRef(false);

  const refreshDrafts = useCallback(() => draftStore.list().then(setSaved), []);
  useEffect(() => {
    refreshDrafts();
  }, [refreshDrafts]);

  // Debounced autosave to IndexedDB.
  useEffect(() => {
    if (!dirty.current) return undefined;
    const t = setTimeout(async () => {
      await draftStore.save(draft);
      setSavedAt(new Date());
      refreshDrafts();
    }, 600);
    return () => clearTimeout(t);
  }, [draft, refreshDrafts]);

  // Server rejected a queued listing during background sync → reopen the draft with errors.
  useEffect(() => {
    const onRejected = (e) => {
      const { item, errors: errs, message } = e.detail || {};
      if (!item?.draftId) return;
      draftStore.get(item.draftId).then((d) => {
        if (d) {
          setDraft(d);
          setErrors(Object.fromEntries(Object.entries(errs || {}).map(([k, v]) => [k, v[0]])));
          setFormError(`Your queued listing was not accepted: ${message}`);
        }
      });
    };
    window.addEventListener('agrimatch:outbox-rejected', onRejected);
    return () => window.removeEventListener('agrimatch:outbox-rejected', onRejected);
  }, []);

  const set = (key) => (e) => {
    const value = e?.target ? (e.target.type === 'checkbox' ? e.target.checked : e.target.value) : e;
    dirty.current = true;
    setDraft((d) => ({ ...d, [key]: value }));
    setErrors((er) => ({ ...er, [key]: undefined }));
  };

  const volume = Number(draft.stock_volume_kg) || 0;
  const inspectionRequired = draft.claimed_grade === 'export' || volume >= MANDATORY_INSPECTION_KG;
  useEffect(() => {
    if (inspectionRequired && !draft.request_inspection) setDraft((d) => ({ ...d, request_inspection: true }));
  }, [inspectionRequired, draft.request_inspection]);

  const varietyName = useMemo(() => {
    for (const c of crops.data?.data ?? []) {
      const v = c.varieties.find((x) => String(x.id) === String(draft.crop_variety_id));
      if (v) return v.name;
    }
    return null;
  }, [crops.data, draft.crop_variety_id]);

  function payload() {
    const num = (v) => (v === '' || v == null ? undefined : Number(v));
    return {
      crop_variety_id: num(draft.crop_variety_id),
      province_id: num(draft.province_id),
      municipality: draft.municipality.trim(),
      barangay: draft.barangay.trim(),
      title: draft.title.trim() || (varietyName ? `${varietyName} — Brgy. ${draft.barangay}` : ''),
      description: draft.description.trim() || undefined,
      quality_grade: draft.quality_grade,
      claimed_grade: draft.claimed_grade || undefined,
      is_organic: draft.is_organic,
      stock_volume_kg: num(draft.stock_volume_kg),
      minimum_order_kg: num(draft.minimum_order_kg),
      unit_price: num(draft.unit_price),
      price_negotiable: draft.price_negotiable,
      harvest_date_start: draft.harvest_date_start,
      harvest_date_end: draft.harvest_date_end,
      request_inspection: draft.request_inspection,
      preferred_inspection_date: draft.request_inspection ? draft.preferred_inspection_date || undefined : undefined,
      pickup_latitude: num(draft.pickup_latitude),
      pickup_longitude: num(draft.pickup_longitude),
      image: draft.image,
    };
  }

  async function submit(e) {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    await draftStore.save(draft);
    try {
      const listingPayload = payload();
      const body = listingPayload.image ? (() => { const form = new FormData(); Object.entries(listingPayload).forEach(([key, value]) => { if (value !== undefined && value !== null && value !== '') form.append(key, value instanceof File ? value : String(value)); }); return form; })() : listingPayload;
      const res = await api.createListing(body, { draftId: draft.id });
      if (res.queued) {
        setToast({ title: 'Saved offline — will post automatically', body: 'We’ll send this listing as soon as you have signal.' });
      } else {
        await draftStore.remove(draft.id);
        setToast({ title: `${res.data.reference_code} is live`, body: res.data.quality?.inspection_status === 'pending' ? 'Inspection requested — a quality technician will contact you.' : 'Buyers can now send offers.' });
        dirty.current = false;
        setDraft(emptyDraft(user));
        setTimeout(() => onDone(res.data), 1200);
      }
      refreshDrafts();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
        setFormError('Please fix the highlighted fields.');
      } else {
        setFormError(err.message);
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!user) {
    return (
      <div className="card p-8 text-center">
        <p className="text-sm text-muted">Sign in as a farmer, cooperative manager or trader to post a harvest.</p>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
      <form onSubmit={submit} className="card space-y-6 p-4 sm:p-6" noValidate>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-ink">Harvest details</h2>
            <p className="text-xs text-muted">Autosaves on this device — works without signal.</p>
          </div>
          <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${online ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-900 text-white'}`}>
            {online ? <Save className="h-3 w-3" /> : <CloudOff className="h-3 w-3" />}
            {savedAt ? `Draft saved ${savedAt.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : online ? 'Online' : 'Offline mode'}
          </span>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field id="d-crop" label="Crop variety" error={errors.crop_variety_id} className="sm:col-span-2">
            <select id="d-crop" className="input" value={draft.crop_variety_id} onChange={set('crop_variety_id')} required>
              <option value="">Select crop…</option>
              {(crops.data?.data ?? []).map((c) => (
                <optgroup key={c.id} label={c.name}>
                  {c.varieties.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.name} ({v.local_name})
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
          </Field>
          <Field id="d-title" label="Listing title" error={errors.title} hint="Leave blank to auto-generate" className="sm:col-span-2">
            <input id="d-title" className="input" maxLength={200} placeholder={varietyName ? `${varietyName} — Brgy. ${draft.barangay || '…'}` : 'e.g. Tight-head cabbage, 1.2–1.8 kg'} value={draft.title} onChange={set('title')} />
          </Field>
        </div>

        <fieldset className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <legend className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Volume & price</legend>
          <Field id="d-stock" label="Total stock (kg)" error={errors.stock_volume_kg}>
            <input id="d-stock" type="number" inputMode="decimal" min="50" className="input num" value={draft.stock_volume_kg} onChange={set('stock_volume_kg')} />
          </Field>
          <Field id="d-min" label="Minimum order (kg)" error={errors.minimum_order_kg}>
            <input id="d-min" type="number" inputMode="decimal" min="0" className="input num" value={draft.minimum_order_kg} onChange={set('minimum_order_kg')} />
          </Field>
          <Field id="d-price" label="Asking price (₱/kg)" error={errors.unit_price}>
            <input id="d-price" type="number" inputMode="decimal" step="0.25" min="0.5" className="input num" value={draft.unit_price} onChange={set('unit_price')} />
          </Field>
          {volume > 0 && Number(draft.unit_price) > 0 && (
            <p className="num text-xs text-muted sm:col-span-3">
              Lot value <b className="text-ink">{formatPeso(volume * Number(draft.unit_price))}</b> for {formatVolume(volume, { compact: true })}
            </p>
          )}
        </fieldset>

        <fieldset className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <legend className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Harvest window</legend>
          <Field id="d-start" label="Harvest starts" error={errors.harvest_date_start}>
            <input id="d-start" type="date" className="input" value={draft.harvest_date_start} onChange={set('harvest_date_start')} />
          </Field>
          <Field id="d-end" label="Harvest ends" error={errors.harvest_date_end}>
            <input id="d-end" type="date" className="input" min={draft.harvest_date_start} value={draft.harvest_date_end} onChange={set('harvest_date_end')} />
          </Field>
        </fieldset>

        <fieldset className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <legend className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Farm location</legend>
          <Field id="d-prov" label="Province" error={errors.province_id}>
            <select id="d-prov" className="input" value={draft.province_id} onChange={set('province_id')}><option value="">Select…</option>{(provinces.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
          </Field>
          <Field id="d-muni" label="Municipality" error={errors.municipality}>
            <input id="d-muni" className="input" value={draft.municipality} onChange={set('municipality')} />
          </Field>
          <Field id="d-brgy" label="Barangay" error={errors.barangay}>
            <input id="d-brgy" className="input" value={draft.barangay} onChange={set('barangay')} />
          </Field>
          <FarmLocationInput value={{ latitude: draft.pickup_latitude, longitude: draft.pickup_longitude }} provinces={provinces.data?.data ?? []} onChange={(values) => { dirty.current = true; setDraft((current) => ({ ...current, ...(values.latitude !== undefined ? { pickup_latitude: values.latitude } : {}), ...(values.longitude !== undefined ? { pickup_longitude: values.longitude } : {}), ...(values.province_id ? { province_id: values.province_id } : {}), ...(values.municipality !== undefined ? { municipality: values.municipality } : {}), ...(values.barangay !== undefined ? { barangay: values.barangay } : {}) })); }} />
          {(errors.pickup_latitude || errors.pickup_longitude) && <p className="text-xs text-rose-600 sm:col-span-3">{errors.pickup_latitude || errors.pickup_longitude}</p>}
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Quality</legend>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <span className="label">Your grade</span>
              <div className="segmented w-full" role="radiogroup" aria-label="Quality grade">
                {['A', 'B', 'C'].map((g) => (
                  <button key={g} type="button" role="radio" aria-checked={draft.quality_grade === g} aria-pressed={draft.quality_grade === g} onClick={() => set('quality_grade')(g)} className="flex-1">
                    Grade {g}
                  </button>
                ))}
              </div>
            </div>
            <Field id="d-claim" label="Certification target" error={errors.claimed_grade}>
              <select id="d-claim" className="input" value={draft.claimed_grade} onChange={set('claimed_grade')}>
                <option value="">None</option>
                <option value="export">Export Quality</option>
                <option value="grade_a">Grade A (certified)</option>
                <option value="class_b">Class B</option>
              </select>
            </Field>
          </div>

          <div className={`rounded-2xl border p-4 ${draft.request_inspection ? 'border-green-300 bg-green-50/40' : 'border-slate-200'}`}>
            <label className="flex cursor-pointer items-start gap-3">
              <input type="checkbox" className="mt-0.5 h-4 w-4 accent-green-600" checked={draft.request_inspection} disabled={inspectionRequired} onChange={set('request_inspection')} />
              <span>
                <span className="flex items-center gap-1.5 text-sm font-semibold text-ink">
                  <ShieldCheck className="h-4 w-4 text-green-600" /> Request a quality inspection
                </span>
                <span className="mt-0.5 block text-xs text-muted">
                  {inspectionRequired
                    ? draft.claimed_grade === 'export'
                      ? 'Required: export-grade claims must be verified by a quality technician.'
                      : `Required for lots of ${formatVolume(MANDATORY_INSPECTION_KG, { compact: true })} or more.`
                    : 'Certified lots get a verified grade badge and sell faster to supermarkets and exporters.'}
                </span>
              </span>
            </label>
            {draft.request_inspection && (
              <Field id="d-insp" label="Preferred inspection date" error={errors.preferred_inspection_date || errors.request_inspection} className="mt-3 sm:max-w-xs">
                <input id="d-insp" type="date" className="input" min={toIsoDate(new Date())} value={draft.preferred_inspection_date} onChange={set('preferred_inspection_date')} />
              </Field>
            )}
          </div>

          <div className="flex flex-wrap gap-4">
            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" className="h-4 w-4 accent-green-600" checked={draft.is_organic} onChange={set('is_organic')} /> Organic practice
            </label>
            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" className="h-4 w-4 accent-green-600" checked={draft.price_negotiable} onChange={set('price_negotiable')} /> Price negotiable
            </label>
          </div>
        </fieldset>

        <Field id="d-desc" label="Notes for buyers (optional)" error={errors.description}>
          <textarea id="d-desc" rows={3} maxLength={5000} className="input resize-none" placeholder="Sorting, packaging, cold storage, pickup times…" value={draft.description} onChange={set('description')} />
        </Field>
        <Field id="d-photo" label="Crop photo (optional)" error={errors.image} hint="JPEG, PNG, or WebP up to 5 MB.">
          <input id="d-photo" className="input" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => set('image')(e.target.files?.[0] ?? null)} />
        </Field>

        {formError && <ErrorNote error={{ message: formError }} />}

        <div className="sticky bottom-0 -mx-4 -mb-4 flex flex-col-reverse gap-2 border-t border-slate-100 bg-white/90 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:mb-0 sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:p-0">
          <button
            type="button"
            className="btn-ghost"
            onClick={() => {
              dirty.current = false;
              setDraft(emptyDraft(user));
              setErrors({});
              setFormError(null);
            }}
          >
            New draft
          </button>
          <button type="submit" className="btn-action min-w-[180px]" disabled={submitting}>
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : online ? <Send className="h-4 w-4" /> : <CloudOff className="h-4 w-4" />}
            {online ? 'Post listing' : 'Queue for sync'}
          </button>
        </div>
      </form>

      <aside className="card h-fit p-4 lg:sticky lg:top-24">
        <h3 className="flex items-center gap-2 text-sm font-bold text-ink">
          <FileClock className="h-4 w-4 text-amber-600" /> Drafts on this device
        </h3>
        <p className="mt-0.5 text-[11px] text-muted">Stored in your phone&apos;s browser (IndexedDB), not on the server.</p>
        {saved.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 px-3 py-5 text-center text-xs text-muted">No saved drafts yet.</p>
        ) : (
          <ul className="mt-3 space-y-2">
            {saved.map((d) => (
              <li key={d.id} className={`flex items-center gap-2 rounded-xl border p-2.5 ${d.id === draft.id ? 'border-green-400 bg-green-50/50' : 'border-slate-200'}`}>
                <button
                  type="button"
                  className="min-w-0 flex-1 text-left"
                  onClick={() => {
                    dirty.current = false;
                    setDraft(d);
                    setErrors({});
                  }}
                >
                  <span className="block truncate text-xs font-semibold text-ink">{d.title || 'Untitled draft'}</span>
                  <span className="num block text-[11px] text-muted">
                    {d.stock_volume_kg ? formatVolume(Number(d.stock_volume_kg)) : '— kg'} · {new Date(d.updatedAt).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
                  </span>
                </button>
                {d.id === draft.id && <CheckCircle2 className="h-4 w-4 shrink-0 text-green-600" aria-label="Editing" />}
                <button
                  type="button"
                  onClick={async () => {
                    await draftStore.remove(d.id);
                    refreshDrafts();
                  }}
                  className="focus-ring rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                  aria-label={`Delete draft ${d.title || ''}`}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </aside>
      <Toast toast={toast} onDismiss={dismissToast} />
    </div>
  );
}
