import { useState } from 'react';
import { Loader2, LogIn, ShieldCheck } from 'lucide-react';
import { ApiError, api } from '../lib/api';
import { Modal } from './ui';

/** Seeded demo accounts (password: "password") — one per role. */
const DEMO_ACCOUNTS = [
  { label: 'Buyer', email: 'procurement@gaisanofresh.test', hint: 'Gaisano Fresh · CDO' },
  { label: 'Farmer', email: 'romeo.dagatan@agrimatch.test', hint: 'Lantapan, Bukidnon' },
  { label: 'Coop manager', email: 'ramil.manggubat@agrimatch.test', hint: 'Lantapan Highland MPC' },
  { label: 'Inspector', email: 'mylene.tabanag@agrimatch.test', hint: 'Quality desk' },
  { label: 'Driver', email: 'junrey.abellanosa@agrimatch.test', hint: 'Elf 3T · CDO' },
  { label: 'Admin', email: 'admin@agrimatch.test', hint: 'Fuel & Audit Desk' },
];

export default function LoginDialog({ open, onClose, onSignedIn, onRegister, onStandalone }) {
  const [email, setEmail] = useState(DEMO_ACCOUNTS[0].email);
  const [password, setPassword] = useState('password');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api.login(email.trim(), password);
      onSignedIn?.(res.user);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.errors?.email?.[0] || err.message : 'Could not sign in.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="max-w-md"
      title="Sign in to AgriMatch"
      subtitle="Secure session with CSRF protection — no tokens stored in your browser."
      footer={
        <button type="submit" form="login-form" className="btn-primary w-full" disabled={busy}>
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogIn className="h-4 w-4" />}
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
      }
    >
      <form id="login-form" onSubmit={submit} className="space-y-4" noValidate>
        <div>
          <label htmlFor="login-email" className="label">
            Email
          </label>
          <input id="login-email" type="email" autoComplete="username" className="input" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div>
          <label htmlFor="login-password" className="label">
            Password
          </label>
          <input id="login-password" type="password" autoComplete="current-password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </div>
        {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{error}</p>}

        <fieldset>
          <legend className="label flex items-center gap-1.5">
            <ShieldCheck className="h-3.5 w-3.5 text-green-600" /> Demo accounts (password: <code className="font-mono">password</code>)
          </legend>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {DEMO_ACCOUNTS.map((a) => (
              <button
                key={a.email}
                type="button"
                onClick={() => {
                  setEmail(a.email);
                  setPassword('password');
                }}
                className={`focus-ring rounded-xl border px-2.5 py-2 text-left transition-all ${
                  email === a.email ? 'border-green-500 bg-green-50/60 shadow-glow' : 'border-slate-200 hover:border-slate-300'
                }`}
              >
                <span className="block text-xs font-semibold text-ink">{a.label}</span>
                <span className="block truncate text-[10px] text-muted">{a.hint}</span>
              </button>
            ))}
          </div>
        </fieldset>
        {onRegister && (
          <p className="text-center text-sm text-muted">
            New to AgriMatch?{' '}
            <button type="button" className="font-semibold text-emerald-800 underline-offset-2 hover:underline" onClick={onRegister}>Register as a farmer or buyer</button>
          </p>
        )}
        {onStandalone && <p className="text-center text-xs text-muted"><button type="button" className="font-semibold text-emerald-800 underline-offset-2 hover:underline" onClick={onStandalone}>Open standalone sign-in page</button></p>}
      </form>
    </Modal>
  );
}
