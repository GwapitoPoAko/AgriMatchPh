import { useEffect, useRef, useState } from 'react';
import {
  CloudOff,
  Layers,
  LayoutDashboard,
  LineChart,
  LogIn,
  LogOut,
  Menu,
  PackagePlus,
  RefreshCw,
  ShieldCheck,
  Store,
  Truck,
  UserRound,
  History,
  X,
} from 'lucide-react';
import { api, flushOutbox, useOnline, useOutboxCount, useSession } from '../lib/api';
import { initials } from '../lib/format';
import logoMark from '../assets/agrimatch-mark.jpg';
import NotificationBell from './NotificationBell';

export const NAV_ITEMS = [
  { id: 'dashboard', label: 'Overview', icon: LayoutDashboard },
  { id: 'marketplace', label: 'Marketplace', icon: Store },
  { id: 'freight', label: 'Hakot Freight', icon: Truck },
  { id: 'prices', label: 'Price Index', icon: LineChart },
];

/** Extra workspace tabs shown only to certain roles. */
const ROLE_NAV = [
  { id: 'coop', label: 'Coop Pools', icon: Layers, roles: ['coop_manager', 'admin'] },
  { id: 'inspections', label: 'Inspections', icon: ShieldCheck, roles: ['quality_inspector', 'admin'] },
  { id: 'admin', label: 'Governance', icon: ShieldCheck, roles: ['admin'] },
];
const navFor = (user) => {
  if (['farmer', 'trader'].includes(user?.role)) return [
    { id: 'inventory', label: 'My Listings / Post Harvest', icon: PackagePlus },
    { id: 'marketplace', label: 'Marketplace', icon: Store },
    { id: 'freight', label: 'Hakot Freight', icon: Truck },
    { id: 'prices', label: 'Price Index', icon: LineChart },
  ];
  if (user?.role === 'buyer') return [
    { id: 'marketplace', label: 'Marketplace Bidding', icon: Store },
    { id: 'prices', label: 'Price Index', icon: LineChart },
    { id: 'freight', label: 'My Deliveries / Hakot', icon: Truck },
  ];
  if (user?.role === 'driver') return [
    { id: 'freight', label: 'Assigned Freight Routes', icon: Truck },
    { id: 'trip-logs', label: 'Trip Logs / GPS', icon: History },
  ];
  if (user?.role === 'quality_inspector') return [
    { id: 'inspections', label: 'Inspection Queue', icon: ShieldCheck },
    { id: 'inspection-history', label: 'Grading History', icon: History },
  ];
  if (user?.role === 'admin') return [
    { id: 'governance', label: 'Overview & Audit', icon: ShieldCheck },
    { id: 'users', label: 'Staff Management', icon: UserRound },
    { id: 'settings', label: 'Price & Fuel Settings', icon: Layers },
  ];
  if (user?.role === 'coop_manager') return [
    { id: 'coop', label: 'Coop Member Pools', icon: Layers },
    { id: 'post', label: 'Harvest Aggregation', icon: PackagePlus },
    { id: 'prices', label: 'Price Index', icon: LineChart },
  ];
  return [...NAV_ITEMS, ...ROLE_NAV.filter((n) => user && n.roles.includes(user.role))];
};

const ROLE_LABELS = {
  farmer: 'Farmer',
  coop_manager: 'Coop Manager',
  quality_inspector: 'Quality Inspector',
  trader: 'Trader',
  buyer: 'Buyer',
  driver: 'Driver',
  admin: 'Admin',
};

export function Logo({ compact = false }) {
  return (
    <span className="flex items-center gap-2.5">
      <img src={logoMark} alt="" className="h-9 w-9 shrink-0 rounded-xl bg-white object-cover ring-1 ring-slate-200/80" />
      {!compact && (
        <span className="hidden leading-tight min-[390px]:block">
          <span className="block font-display text-[15px] font-extrabold tracking-tight text-ink">
            AgriMatch<span className="text-green-600">PH</span>
          </span>
          <span className="hidden text-[10px] font-medium uppercase tracking-[0.14em] text-slate-400 sm:block">Supply · Grading · Hakot</span>
        </span>
      )}
    </span>
  );
}

function Avatar({ user, size = 'h-9 w-9' }) {
  return (
    <span className={`grid ${size} shrink-0 place-items-center rounded-full bg-gradient-to-br from-amber-500 to-amber-700 text-xs font-bold text-white ring-2 ring-white`}>
      {initials(user?.name || 'Guest')}
    </span>
  );
}

function SyncPill() {
  const online = useOnline();
  const queued = useOutboxCount();
  if (online && !queued) return null;
  return (
    <button
      type="button"
      onClick={() => flushOutbox()}
      disabled={!online}
      title={online ? 'Send queued changes now' : 'You are offline — changes are saved on this device and sync automatically'}
      className={`focus-ring inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${
        online ? 'bg-amber-50 text-amber-800 ring-amber-600/20 hover:bg-amber-100' : 'bg-slate-900 text-white ring-slate-900'
      }`}
    >
      {online ? <RefreshCw className="h-3 w-3" /> : <CloudOff className="h-3 w-3" />}
      <span className="hidden sm:inline">{online ? `${queued} to sync` : 'Offline'}</span>
      {!online && queued > 0 && <span className="num rounded-full bg-white/20 px-1.5">{queued}</span>}
    </button>
  );
}

function UserMenu({ user, onSignOut }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
    const esc = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', esc);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', esc);
    };
  }, [open]);

  return (
    <div className="relative hidden md:block" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-haspopup="menu"
        className="focus-ring flex items-center gap-2 rounded-xl py-1 pl-1 pr-2 transition-colors hover:bg-slate-100"
      >
        <Avatar user={user} />
        <span className="hidden text-left leading-tight xl:block">
          <span className="block max-w-[140px] truncate text-[13px] font-semibold text-ink">{user.name}</span>
          <span className="block text-[11px] text-muted">{ROLE_LABELS[user.role]}</span>
        </span>
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-12 z-50 w-64 animate-scale-in overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-slate-900/10">
          <div className="border-b border-slate-100 px-4 py-3">
            <p className="truncate text-sm font-semibold text-ink">{user.name}</p>
            <p className="truncate text-xs text-muted">{user.profile?.business_name}</p>
            <div className="mt-2 flex flex-wrap gap-1.5">
              <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800">{ROLE_LABELS[user.role]}</span>
              {user.profile?.cooperative && (
                <span className="truncate rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">{user.profile.cooperative.name}</span>
              )}
            </div>
          </div>
          <button type="button" role="menuitem" onClick={onSignOut} className="focus-ring flex w-full items-center gap-2 px-4 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-rose-600">
            <LogOut className="h-4 w-4" /> Sign out
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Responsive app header: inline nav on ≥ lg, hamburger drawer below that.
 * Right side: offline/sync status, "Post harvest" (sellers), notification bell, avatar.
 */
export default function Navbar({ view, onNavigate, onSignIn }) {
  const user = useSession();
  const [drawer, setDrawer] = useState(false);
  const drawerRef = useRef(null);

  useEffect(() => {
    if (!drawer) return undefined;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const esc = (e) => e.key === 'Escape' && setDrawer(false);
    document.addEventListener('keydown', esc);
    requestAnimationFrame(() => drawerRef.current?.querySelector('button')?.focus());
    return () => {
      document.body.style.overflow = prev;
      document.removeEventListener('keydown', esc);
    };
  }, [drawer]);

  const go = (id) => {
    setDrawer(false);
    onNavigate(id);
  };
  const signOut = async () => {
    setDrawer(false);
    await api.logout();
    onNavigate('dashboard');
  };

  return (
    <>
      <header className="sticky top-0 z-40 border-b border-slate-200/60 bg-white/75 backdrop-blur-xl supports-[backdrop-filter]:bg-white/65">
        <div className="mx-auto flex h-16 max-w-[1400px] items-center gap-2 px-3 sm:gap-3 sm:px-6 lg:px-8">
          <button
            type="button"
            onClick={() => setDrawer(true)}
            className="focus-ring -ml-1 grid h-10 w-10 place-items-center rounded-xl text-slate-600 hover:bg-slate-100 lg:hidden"
            aria-label="Open navigation menu"
            aria-expanded={drawer}
            aria-controls="mobile-drawer"
          >
            <Menu className="h-5 w-5" />
          </button>

          <button type="button" onClick={() => go('dashboard')} className="focus-ring rounded-xl" aria-label="AgriMatch home">
            <Logo />
          </button>

          <nav className="ml-3 hidden h-full min-w-0 flex-1 items-center justify-center gap-0.5 overflow-x-auto overflow-y-hidden lg:flex" aria-label="Main">
            {navFor(user).map((n) => {
              const active = view === n.id;
              return (
                <button
                  key={n.id}
                  type="button"
                  onClick={() => go(n.id)}
                  aria-current={active ? 'page' : undefined}
                  className={`focus-ring relative inline-flex h-full shrink-0 items-center gap-1.5 whitespace-nowrap rounded-xl px-2 py-2 text-[13px] font-semibold transition-colors ${
                    active ? 'bg-emerald-800/[0.07] text-emerald-800' : 'text-slate-500 hover:bg-slate-100/80 hover:text-slate-800'
                  }`}
                >
                  <n.icon className={`h-4 w-4 ${active ? 'text-emerald-700' : 'text-slate-400'}`} />
                  {n.label}
                  {active && <span className="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-green-500" />}
                </button>
              );
            })}
          </nav>

          <div className="ml-auto flex items-center gap-1.5 sm:gap-2">
            <SyncPill />
            {user ? (
              <>
                <NotificationBell onNavigate={go} />
                <UserMenu user={user} onSignOut={signOut} />
              </>
            ) : (
              <button type="button" onClick={onSignIn} className="btn-primary px-3 py-2 text-xs">
                <LogIn className="h-4 w-4" /> <span>Sign in</span>
              </button>
            )}
          </div>
        </div>
      </header>

      {/* Mobile / tablet drawer */}
      <div className={`fixed inset-0 z-50 lg:hidden ${drawer ? '' : 'pointer-events-none'}`} aria-hidden={!drawer}>
        <div className={`absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity duration-300 ${drawer ? 'opacity-100' : 'opacity-0'}`} onClick={() => setDrawer(false)} />
        <aside
          id="mobile-drawer"
          ref={drawerRef}
          role="dialog"
          aria-modal="true"
          aria-label="Navigation"
          className={`absolute inset-y-0 left-0 flex w-[85vw] max-w-xs flex-col bg-white shadow-2xl transition-transform duration-300 ease-out ${drawer ? 'translate-x-0' : '-translate-x-full'}`}
        >
          <div className="flex items-center justify-between px-4 py-4">
            <Logo />
            <button type="button" onClick={() => setDrawer(false)} className="focus-ring grid h-10 w-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100" aria-label="Close menu">
              <X className="h-5 w-5" />
            </button>
          </div>

          {user && (
            <div className="mx-3 flex items-center gap-3 rounded-2xl bg-gradient-to-br from-emerald-800 to-emerald-950 p-3 text-white">
              <Avatar user={user} size="h-10 w-10" />
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold">{user.name}</p>
                <p className="truncate text-xs text-emerald-100/75">
                  {ROLE_LABELS[user.role]} · {user.profile?.business_name}
                </p>
              </div>
            </div>
          )}

          <nav className="mt-3 flex-1 space-y-1 overflow-y-auto px-3" aria-label="Main mobile">
            {navFor(user).map((n) => {
              const active = view === n.id;
              return (
                <button
                  key={n.id}
                  type="button"
                  onClick={() => go(n.id)}
                  aria-current={active ? 'page' : undefined}
                  className={`focus-ring flex w-full items-center gap-3 rounded-xl px-3 py-3 text-[15px] font-semibold ${
                    active ? 'bg-emerald-800/[0.07] text-emerald-800' : 'text-slate-600 hover:bg-slate-100'
                  }`}
                >
                  <n.icon className={`h-5 w-5 ${active ? 'text-emerald-700' : 'text-slate-400'}`} />
                  {n.label}
                </button>
              );
            })}
          </nav>

          <div className="border-t border-slate-100 p-3">
            {user ? (
              <button type="button" onClick={signOut} className="btn-ghost w-full">
                <LogOut className="h-4 w-4" /> Sign out
              </button>
            ) : (
              <button
                type="button"
                onClick={() => {
                  setDrawer(false);
                  onSignIn();
                }}
                className="btn-primary w-full"
              >
                <LogIn className="h-4 w-4" /> Sign in
              </button>
            )}
          </div>
        </aside>
      </div>
    </>
  );
}
