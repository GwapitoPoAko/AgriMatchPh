import { useMemo, useState } from 'react';
import { CategoryScale, Chart as ChartJS, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js';
import { Line } from 'react-chartjs-2';
import { Warehouse } from 'lucide-react';
import { api } from '../lib/api';
import { formatDateShort, formatPeso } from '../lib/format';
import { ErrorNote, MarketSignalBadge, Skeleton, useAsync } from './ui';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip);

const HUB_COLORS = ['#166534', '#d97706', '#0369a1', '#9f1239', '#6d28d9', '#0f766e', '#a16207', '#475569'];

/**
 * Daily weighted-average price per trading hub (PriceIndexService):
 * verified platform contracts blended with DA Bantay Presyo / AMAS feeds.
 */
export default function HubPriceIndexCard({ defaultVarietyId = 1 }) {
  const [varietyId, setVarietyId] = useState(defaultVarietyId);
  const [range, setRange] = useState('30d');
  const crops = useAsync((signal) => api.crops({ signal }), []);
  const trend = useAsync((signal) => api.hubTrends({ crop_variety_id: varietyId, range }, { signal }), [varietyId, range], 60000);
  const d = trend.data;

  const chart = useMemo(() => {
    if (!d?.datasets?.length) return null;
    return {
      labels: d.labels.map(formatDateShort),
      datasets: d.datasets.map((ds, i) => ({
        label: ds.label,
        data: ds.data,
        borderColor: HUB_COLORS[i % HUB_COLORS.length],
        backgroundColor: HUB_COLORS[i % HUB_COLORS.length],
        borderWidth: 2,
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 4,
        spanGaps: true,
      })),
    };
  }, [d]);

  const options = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,0.92)',
          padding: 10,
          cornerRadius: 10,
          titleFont: { family: 'Inter', weight: '600' },
          bodyFont: { family: 'Inter' },
          callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${formatPeso(ctx.parsed.y)}/kg` },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#94a3b8', maxTicksLimit: 7, font: { size: 11 } }, border: { display: false } },
        y: { grid: { color: 'rgba(148,163,184,0.15)' }, ticks: { color: '#94a3b8', font: { size: 11 }, callback: (v) => `₱${v}` }, border: { display: false } },
      },
    }),
    [],
  );

  return (
    <section className="card p-4 sm:p-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 className="flex items-center gap-2 text-base font-bold text-ink">
            <Warehouse className="h-4 w-4 text-emerald-700" /> Trading hub price index
          </h2>
          <p className="text-xs text-muted">{d?.meta?.method ?? 'Weighted average of verified contracts and official price feeds'}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <select className="input w-auto py-2 text-xs" aria-label="Crop" value={varietyId} onChange={(e) => setVarietyId(Number(e.target.value))}>
            {(crops.data?.data ?? []).flatMap((c) => c.varieties).map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </select>
          <div className="segmented" role="group" aria-label="Range">
            {['14d', '30d', '90d'].map((r) => (
              <button key={r} type="button" aria-pressed={range === r} onClick={() => setRange(r)}>
                {r}
              </button>
            ))}
          </div>
        </div>
      </div>

      <ErrorNote error={trend.error} onRetry={trend.reload} />

      <div className="mt-4 h-64 sm:h-72">{chart ? <Line data={chart} options={options} /> : <Skeleton className="h-full" />}</div>

      {d?.datasets?.length > 0 && (
        <ul className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {d.datasets.map((ds, i) => (
            <li key={ds.id} className="flex items-center gap-2.5 rounded-xl border border-slate-200/70 px-3 py-2">
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: HUB_COLORS[i % HUB_COLORS.length] }} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-xs font-semibold text-ink">{ds.label}</span>
                <span className="num block text-[11px] text-muted">
                  {formatPeso(ds.signal?.index_price)}/kg · {ds.signal?.evidence?.contracts ?? 0} contracts, {ds.signal?.evidence?.feeds ?? 0} feeds
                </span>
              </span>
              {ds.signal?.label ? (
                <MarketSignalBadge signal={ds.signal} variant="pill" />
              ) : (
                <span className="num text-[11px] font-semibold text-slate-500">
                  {ds.signal?.change_7d_pct > 0 ? '+' : ''}
                  {ds.signal?.change_7d_pct ?? 0}%
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
