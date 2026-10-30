import { useSession } from '../lib/api';

export default function AccountProfile() {
  const user = useSession();
  return <section className="card mx-auto max-w-2xl p-6"><p className="text-sm font-semibold text-emerald-700">Account Profile</p><h1 className="mt-1 text-2xl font-extrabold text-ink">{user?.name}</h1><dl className="mt-5 grid gap-4 sm:grid-cols-2">{[['Email', user?.email], ['Role', user?.role?.replaceAll('_', ' ')], ['Business', user?.profile?.business_name], ['Location', [user?.profile?.barangay, user?.profile?.municipality, user?.profile?.province?.name].filter(Boolean).join(', ')], ['Phone', user?.phone]].map(([label,value]) => <div key={label}><dt className="text-xs uppercase text-muted">{label}</dt><dd className="mt-1 text-sm font-medium text-ink">{value || 'Not provided'}</dd></div>)}</dl></section>;
}
