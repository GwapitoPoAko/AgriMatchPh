import { useState } from 'react';
import { Loader2, UserPlus } from 'lucide-react';
import { ApiError, api } from '../lib/api';
import { ErrorNote, Modal, useAsync } from './ui';

export default function Register({ open, onClose, onSignedIn }) {
  const provinces = useAsync((signal) => api.provinces({ signal }), []);
  const [role, setRole] = useState('farmer');
  const [form, setForm] = useState({ name: '', email: '', phone: '', business_name: '', province_id: '', municipality: '', barangay: '', latitude: '', longitude: '', password: '', password_confirmation: '' });
  const [error, setError] = useState(null);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      const body = { ...form, role, province_id: form.province_id || null };
      if (!body.latitude || !body.longitude) {
        delete body.latitude;
        delete body.longitude;
      }
      await api.register(body);
      const session = await api.login(form.email.trim(), form.password);
      onSignedIn?.(session.user);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
      setError(err.message || 'Could not create your account.');
    } finally {
      setBusy(false);
    }
  }

  const fieldError = (key) => errors[key] && <p className="mt-1 text-xs text-rose-600">{Array.isArray(errors[key]) ? errors[key][0] : errors[key]}</p>;
  const input = (id, label, key, props = {}) => (
    <div>
      <label className="label" htmlFor={id}>{label}</label>
      <input id={id} className="input" value={form[key]} onChange={set(key)} {...props} />
      {fieldError(key)}
    </div>
  );

  return (
    <Modal open={open} onClose={onClose} size="max-w-xl" title="Create an AgriMatch account" subtitle="Register as a farmer or buyer. Staff accounts are managed by platform administrators." footer={
      <button type="submit" form="register-form" className="btn-primary w-full" disabled={busy}>
        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
        {busy ? 'Creating account…' : 'Create account'}
      </button>
    }>
      <form id="register-form" onSubmit={submit} className="space-y-4" noValidate>
        <div className="grid grid-cols-2 gap-2" role="tablist" aria-label="Account type">
          {[['farmer', 'Farmer'], ['buyer', 'Buyer']].map(([value, label]) => (
            <button key={value} type="button" role="tab" aria-selected={role === value} onClick={() => setRole(value)} className={`rounded-xl border px-4 py-2.5 text-sm font-semibold ${role === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-600'}`}>
              {label}
            </button>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          {input('register-name', 'Full name', 'name', { autoComplete: 'name', required: true })}
          {input('register-business', role === 'farmer' ? 'Farm or business name' : 'Buyer business name', 'business_name', { required: true })}
          {input('register-email', 'Email', 'email', { type: 'email', autoComplete: 'email', required: true })}
          {input('register-phone', 'Phone (optional)', 'phone', { type: 'tel', autoComplete: 'tel' })}
          <div>
            <label className="label" htmlFor="register-province">Province</label>
            <select id="register-province" className="input" value={form.province_id} onChange={set('province_id')}>
              <option value="">Choose a province</option>
              {(provinces.data?.data ?? []).map((province) => <option key={province.id} value={province.id}>{province.name}</option>)}
            </select>
            {fieldError('province_id')}
          </div>
          {input('register-municipality', 'Municipality / city', 'municipality')}
          {input('register-barangay', 'Barangay', 'barangay')}
          {input('register-latitude', 'Latitude (optional)', 'latitude', { type: 'number', step: 'any', min: '4.5', max: '21.5' })}
          {input('register-longitude', 'Longitude (optional)', 'longitude', { type: 'number', step: 'any', min: '116', max: '127' })}
          {input('register-password', 'Password', 'password', { type: 'password', autoComplete: 'new-password', minLength: 8, required: true })}
          {input('register-password-confirm', 'Confirm password', 'password_confirmation', { type: 'password', autoComplete: 'new-password', minLength: 8, required: true })}
        </div>
        {error && <ErrorNote error={{ message: error }} />}
      </form>
    </Modal>
  );
}
