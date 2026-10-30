import { useState } from 'react';
import { ArrowRight } from 'lucide-react';
import farmImage from '../assets/Magsasaka.jpg';
import logoMark from '../assets/agrimatch-mark.jpg';
import { api } from '../lib/api';
import { ErrorNote } from './ui';

const DEMO_ACCOUNTS = [
  ['Buyer', 'procurement@gaisanofresh.test'],
  ['Farmer', 'romeo.dagatan@agrimatch.test'],
  ['Coop Manager', 'ramil.manggubat@agrimatch.test'],
  ['Inspector', 'mylene.tabanag@agrimatch.test'],
  ['Driver', 'junrey.abellanosa@agrimatch.test'],
  ['Admin', 'admin@agrimatch.test'],
];

export default function Login({ onRegister, onSignedIn }) {
  const [form, setForm] = useState({ email: '', password: '' });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await api.login(form.email, form.password);
      onSignedIn(response.user);
    } catch (err) {
      setError(err.message || 'Unable to sign in.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="relative isolate flex min-h-screen min-h-[100svh] w-full items-center justify-center overflow-hidden p-5 pt-28 sm:p-8 sm:pt-32 md:p-12 md:pt-36">
      <img
        src={farmImage}
        alt="Farmers gathering a rice harvest in the fields"
        className="absolute inset-0 -z-20 h-full w-full object-cover object-center"
      />
      <div className="absolute inset-0 -z-10 bg-black/40" aria-hidden="true" />

      <header className="absolute left-5 top-5 z-20 sm:left-8 sm:top-6 md:left-12 md:top-8">
        <div className="flex items-center gap-3">
          <img src={logoMark} alt="" className="h-11 w-11 shrink-0 rounded-xl bg-white object-cover ring-1 ring-white/30" />
          <div>
            <p className="text-sm font-extrabold tracking-[0.16em] text-white">AGRI MATCH PH</p>
            <p className="mt-0.5 text-xs text-slate-300">From the farm to the market, together.</p>
          </div>
        </div>
      </header>

      <main className="z-10 mx-auto grid w-full max-w-6xl grid-cols-1 items-center gap-6 md:grid-cols-12 md:gap-8">
        <article className="w-full rounded-2xl border border-white/10 bg-black/40 p-6 text-white shadow-xl backdrop-blur-md sm:p-8 md:col-span-6 md:p-10 xl:col-span-7">
          <span className="mb-2 block text-xs font-semibold tracking-wider text-emerald-400 uppercase">
            A STRONGER HARVEST STARTS HERE
          </span>
          <h1 className="mb-4 text-3xl leading-tight font-bold md:text-5xl">
            Good harvests move farther together.
          </h1>
          <p className="text-sm leading-relaxed text-slate-200 md:text-base">
            Sign in to manage listings, routes, inspections, and trade across the AgriMatch PH community.
          </p>
        </article>

        <form onSubmit={submit} className="w-full rounded-2xl border border-slate-100 bg-white p-6 text-slate-800 shadow-2xl sm:p-8 md:col-span-6 xl:col-span-5">
          <div className="mb-6">
            <h2 className="text-2xl font-bold text-slate-900">Sign in</h2>
            <p className="mt-1 text-xs text-slate-500">Access your AgriMatch PH account.</p>
          </div>

          {error && <div className="mb-4"><ErrorNote error={{ message: error }} /></div>}

          <label className="mb-4 block text-xs font-semibold text-slate-700">
            Email
            <input
              className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600"
              type="email"
              autoComplete="username"
              value={form.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
              required
            />
          </label>
          <label className="mb-5 block text-xs font-semibold text-slate-700">
            Password
            <input
              className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600"
              type="password"
              autoComplete="current-password"
              value={form.password}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
              required
            />
          </label>

          <button
            className="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-700 py-3 font-medium text-white transition-colors hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-700/20 disabled:pointer-events-none disabled:opacity-60"
            disabled={busy}
          >
            <span>{busy ? 'Signing in…' : 'Sign in securely'}</span>
            <ArrowRight className="h-4 w-4" />
          </button>

          <div className="mt-5">
            <p className="mb-2 text-xs text-slate-500">Demo accounts · password: password</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {DEMO_ACCOUNTS.map(([label, email]) => (
                <button
                  key={email}
                  type="button"
                  onClick={() => setForm({ email, password: 'password' })}
                  className={`min-w-0 rounded-lg border p-2 text-left text-xs transition-colors ${form.email === email ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-slate-50 hover:bg-slate-100'}`}
                >
                  <b className="block truncate font-semibold text-slate-800">{label}</b>
                  <span className="mt-0.5 block truncate text-[10px] text-slate-500">{email}</span>
                </button>
              ))}
            </div>
          </div>

          <p className="mt-5 text-center text-xs font-medium text-slate-600">
            New to AgriMatch?{' '}
            <button type="button" onClick={onRegister} className="font-semibold text-emerald-700 hover:text-emerald-800 hover:underline">
              Sign up / Register
            </button>
          </p>
        </form>
      </main>
    </section>
  );
}
