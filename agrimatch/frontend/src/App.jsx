import { useCallback, useEffect, useState } from 'react';
import { Navigate, useLocation, useNavigate as useRouterNavigate } from 'react-router-dom';
import { CloudOff } from 'lucide-react';
import CoopPoolManager from './components/CoopPoolManager';
import DashboardOverview from './components/DashboardOverview';
import FreightPoolingWidget from './components/FreightPoolingWidget';
import HarvestMarketplace from './components/HarvestMarketplace';
import HubPriceIndexCard from './components/HubPriceIndexCard';
import ListingDraftForm from './components/ListingDraftForm';
import LoginDialog from './components/LoginDialog';
import Navbar from './components/Navbar';
import PriceAnalyticsChart from './components/PriceAnalyticsChart';
import InspectionQueue from './components/InspectionQueue';
import Register from './components/Register';
import SuperAdminDashboard from './components/SuperAdminDashboard';
import DriverRoutes from './components/DriverRoutes';
import AccountProfile from './components/AccountProfile';
import Login from './components/Login';
import MyListings from './components/MyListings';
import MyDeliveries from './components/MyDeliveries';
import { Toast } from './components/ui';
import { api, useOnline, useSession } from './lib/api';

const VIEWS = ['dashboard', 'inventory', 'marketplace', 'freight', 'prices', 'post', 'coop', 'admin', 'inspections', 'trip-logs', 'profile', 'governance', 'users', 'settings', 'inspection-history', 'login'];
const ROLE_HOME = { farmer: 'inventory', buyer: 'marketplace', driver: 'freight', quality_inspector: 'inspections', coop_manager: 'coop', admin: 'governance', trader: 'inventory' };
const ROLE_VIEWS = {
  farmer: new Set(['inventory', 'marketplace', 'freight', 'prices']),
  trader: new Set(['inventory', 'marketplace', 'freight', 'prices']),
  buyer: new Set(['marketplace', 'freight', 'prices']),
  driver: new Set(['freight', 'trip-logs']),
  quality_inspector: new Set(['inspections', 'inspection-history']),
  coop_manager: new Set(['coop', 'post', 'prices']),
  admin: new Set(['governance', 'users', 'settings']),
};

function PageHeader({ eyebrow, title, children }) {
  return (
    <header className="animate-fade-up">
      <p className="text-sm font-medium text-emerald-700">{eyebrow}</p>
      <h1 className="mt-1 text-2xl font-extrabold text-ink sm:text-[28px]">{title}</h1>
      {children && <p className="mt-1 max-w-2xl text-sm text-muted">{children}</p>}
    </header>
  );
}

export default function App() {
  const location = useLocation();
  const routerNavigate = useRouterNavigate();
  const [authReady, setAuthReady] = useState(false);
  const [loginOpen, setLoginOpen] = useState(false);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [toast, setToast] = useState(null);
  const user = useSession();
  const online = useOnline();
  const pathId = location.pathname.replace(/^\/+|\/+$/g, '').split('/')[0];
  const view = pathId ? (VIEWS.includes(pathId) ? pathId : 'login') : (user ? ROLE_HOME[user.role] || 'marketplace' : 'login');
  const dismissToast = useCallback(() => setToast(null), []);

  useEffect(() => {
    api.refreshMe().finally(() => setAuthReady(true));
  }, []);

  // Surface background outbox activity.
  useEffect(() => {
    const flushed = (e) => setToast({ title: 'Back online — changes synced', body: `${e.detail.sent} queued item${e.detail.sent === 1 ? '' : 's'} sent to AgriMatch.` });
    const rejected = (e) => setToast({ title: 'A queued change was rejected', body: e.detail.message });
    window.addEventListener('agrimatch:outbox-flushed', flushed);
    window.addEventListener('agrimatch:outbox-rejected', rejected);
    return () => {
      window.removeEventListener('agrimatch:outbox-flushed', flushed);
      window.removeEventListener('agrimatch:outbox-rejected', rejected);
    };
  }, []);

  const navigate = useCallback((id) => {
    routerNavigate(id === 'dashboard' ? '/' : `/${id}`);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [routerNavigate]);

  if (!authReady && view !== 'login') return <div className="grid min-h-screen place-items-center bg-canvas text-sm text-muted">Checking your AgriMatch session…</div>;
  if (authReady && !user && view !== 'login') return <Navigate to="/login" replace />;
  if (authReady && user && view !== 'login' && !ROLE_VIEWS[user.role]?.has(view)) return <Navigate to={`/${ROLE_HOME[user.role] || 'marketplace'}`} replace />;

  return (
    <div className="min-h-screen bg-canvas">
      {view !== 'login' && <div className="pointer-events-none fixed inset-x-0 top-0 h-72 bg-gradient-to-b from-emerald-50/80 via-canvas to-transparent" />}

      {view !== 'login' && <Navbar view={view} onNavigate={navigate} onSignIn={() => setLoginOpen(true)} />}

      {!online && (
        <div className="relative z-30 flex items-center justify-center gap-2 bg-slate-900 px-4 py-2 text-center text-xs font-medium text-white" role="status">
          <CloudOff className="h-3.5 w-3.5 shrink-0" />
          Offline — showing saved market data. Listings you draft are stored on this device and sync automatically.
        </div>
      )}

      <main className={view === 'login' ? 'relative z-10 w-full' : 'relative mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8'}>
        {view === 'login' && <Login onRegister={() => setRegisterOpen(true)} onSignedIn={(account) => { setToast({ title: `Welcome, ${account?.name?.split(' ')[0] || 'back'}`, body: 'Signed in securely.' }); navigate(ROLE_HOME[account?.role] || 'marketplace'); }} />}
        {view === 'inventory' && <MyListings />}
        {view === 'dashboard' && <DashboardOverview onNavigate={navigate} />}

        {view === 'marketplace' && <HarvestMarketplace />}

        {view === 'freight' && user?.role === 'driver' && <DriverRoutes />}
        {view === 'trip-logs' && <DriverRoutes historyOnly />}
        {view === 'profile' && <AccountProfile />}
        {view === 'freight' && user?.role !== 'driver' && (
          <div className="space-y-6">
            {user?.role === 'buyer' && <MyDeliveries />}
            <PageHeader eyebrow="Shared logistics" title={user?.role === 'buyer' ? 'My Deliveries / Hakot' : 'Hakot freight calculator'}>
              Pool your harvest with nearby farmers heading to the same bagsakan. Truck costs are split by load × distance, with a live fuel
              surcharge from weekly DOE pump prices.
            </PageHeader>
            <FreightPoolingWidget />
          </div>
        )}

        {view === 'prices' && (
          <div className="space-y-6">
            <PageHeader eyebrow="Price & Fuel Index Engine" title="Crop price trends">
              Daily farm-gate, wholesale and retail prices by province, plus a weighted trading-hub index built from verified contracts and DA
              price feeds.
            </PageHeader>
            <HubPriceIndexCard defaultVarietyId={1} />
            <PriceAnalyticsChart defaultVarietyIds={[1, 2, 3]} defaultRange="90d" title="Alliums & aromatics" />
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
              <PriceAnalyticsChart defaultVarietyIds={[5, 6]} defaultRange="30d" title="Lowland vegetables" />
              <PriceAnalyticsChart defaultVarietyIds={[11, 12, 13]} defaultRange="30d" title="Banana varieties" />
            </div>
          </div>
        )}

        {view === 'coop' && (
          <div className="space-y-6">
            <PageHeader eyebrow="Coop Group Pooling Engine" title="Build a cooperative pool">
              Aggregate smallholder lots into one high-volume listing for supermarkets, feed mills and exporters. One contract for the buyer;
              pro-rata payouts for every member farmer.
            </PageHeader>
            <CoopPoolManager onNavigate={navigate} />
          </div>
        )}

        {view === 'admin' && <SuperAdminDashboard />}
        {view === 'governance' && <SuperAdminDashboard section="governance" />}
        {view === 'users' && <SuperAdminDashboard section="users" />}
        {view === 'settings' && <SuperAdminDashboard section="settings" />}
        {view === 'inspections' && <InspectionQueue />}
        {view === 'inspection-history' && <InspectionQueue historyOnly />}

        {view === 'post' && (
          <div className="space-y-6">
            <PageHeader eyebrow="Offline-ready" title="Post a harvest">
              Fill this in even without signal — your draft saves on this phone and posts itself when you reconnect.
            </PageHeader>
            {user ? (
              <ListingDraftForm onDone={() => navigate(user.role === 'coop_manager' ? 'coop' : 'marketplace')} />
            ) : (
              <div className="card p-8 text-center">
                <p className="text-sm text-muted">Sign in as a farmer, cooperative manager or trader to post a harvest.</p>
                <button type="button" className="btn-primary mt-4" onClick={() => setLoginOpen(true)}>
                  Sign in
                </button>
              </div>
            )}
          </div>
        )}
      </main>

      {view !== 'login' && (
        <footer className="relative mx-auto max-w-[1400px] px-4 pb-10 pt-2 text-[11px] text-slate-400 sm:px-6 lg:px-8">
          AgriMatch PH · Prices in PHP per kg · Hub index: verified contracts × 1.0 + DA Bantay Presyo / AMAS feeds × 0.6
        </footer>
      )}

      <LoginDialog
        open={loginOpen}
        onClose={() => setLoginOpen(false)}
        onRegister={() => { setLoginOpen(false); setRegisterOpen(true); }}
        onStandalone={() => { setLoginOpen(false); navigate('login'); }}
        onSignedIn={(u) => setToast({ title: `Welcome, ${u.name.split(' ')[0]}`, body: 'Signed in securely.' })}
      />
      <Register
        open={registerOpen}
        onClose={() => setRegisterOpen(false)}
        onSignedIn={(u) => { setToast({ title: `Welcome, ${u.name.split(' ')[0]}`, body: 'Your AgriMatch account is ready.' }); navigate(ROLE_HOME[u.role] || 'marketplace'); }}
      />
      <Toast toast={toast} onDismiss={dismissToast} />
    </div>
  );
}
