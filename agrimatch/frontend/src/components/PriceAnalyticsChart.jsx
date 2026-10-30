import { useCallback, useMemo, useRef, useState } from 'react';
import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import { LineChart, Plus, RefreshCw, X } from 'lucide-react';
import { api } from '../lib/api';
import { formatDateShort, formatDateWeekday, formatPeso, formatPct, formatVolume } from '../lib/format';
import { ErrorNote, Skeleton, TrendDelta, useAsync } from './ui';

/* Vertical crosshair that follows the hovered date. */
const crosshair = {
  id: 'agriCrosshair',
  afterDatasetsDraw(chart) {
    const active = chart.tooltip?.getActiveElements?.() ?? [];
    if (!active.length) return;
    const { ctx, chartArea } = chart;
    const x = active[0].element.x;
    ctx.save();
    ctx.beginPath();
    ctx.setLineDash([4, 4]);
    ctx.moveTo(x, chartArea.top);
    ctx.lineTo(x, chartArea.bottom);
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(100,116,139,0.45)';
    ctx.stroke();
    ctx.restore();
  },
};

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, crosshair);

const SERIES_COLORS = ['#15803d', '#d97706', '#0369a1', '#9f1239'];
const RANGES = [
  { value: '7d', label: '7D' },
  { value: '30d', label: '30D' },
  { value: '90d', label: '90D' },
  { value: '120d', label: '120D' },
];
const METRICS = [
  { value: 'farmgate', label: 'Farm-gate' },
  { value: 'wholesale', label: 'Wholesale' },
  { value: 'retail', label: 'Retail' },
];
const MAX_SERIES = 4;

function hexToRgba(hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

/**
 * Historical price fluctuations from the Regional Price Index.
 *
 * Props
 *  - defaultVarietyIds  initial crops to plot (max 4)
 *  - defaultRange       '7d' | '30d' | '90d' | '120d'
 *  - provinceIds        optional province filter passed to the API
 *  - compact            hides the per-series stat strip (for dashboard embeds)
 *  - title / subtitle   override header copy
 */
export default function PriceAnalyticsChart({
  defaultVarietyIds = [1, 5, 11],
  defaultRange = '30d',
  provinceIds = [],
  compact = false,
  title = 'Regional Price Index',
  subtitle,
  className = '',
}) {
  const [varietyIds, setVarietyIds] = useState(defaultVarietyIds.slice(0, MAX_SERIES));
  const [range, setRange] = useState(defaultRange);
  const [metric, setMetric] = useState('farmgate');
  const [tooltip, setTooltip] = useState(null);
  const [adding, setAdding] = useState(false);
  const containerRef = useRef(null);

  const crops = useAsync((signal) => api.crops({ signal }), []);
  const varieties = useMemo(
    () => (crops.data?.data ?? []).flatMap((c) => c.varieties.map((v) => ({ ...v, category: c.name }))),
    [crops.data],
  );

  const trends = useAsync(
    (signal) =>
      varietyIds.length
        ? api.priceTrends({ crop_variety_id: varietyIds, province_id: provinceIds, range, metric }, { signal })
        : Promise.resolve({ labels: [], datasets: [], meta: {} }),
    [varietyIds.join(','), provinceIds.join(','), range, metric],
    60000,
  );

  /* Keep series colour stable per variety regardless of API order. */
  const colorFor = useCallback((id) => SERIES_COLORS[Math.max(0, varietyIds.indexOf(id)) % SERIES_COLORS.length], [varietyIds]);

  const datasets = useMemo(() => {
    const raw = trends.data?.datasets ?? [];
    return [...raw].sort((a, b) => varietyIds.indexOf(a.id) - varietyIds.indexOf(b.id));
  }, [trends.data, varietyIds]);

  const externalTooltip = useCallback(({ chart, tooltip: tt }) => {
    if (!tt || tt.opacity === 0 || !tt.dataPoints?.length) {
      setTooltip(null);
      return;
    }
    const idx = tt.dataPoints[0].dataIndex;
    setTooltip({
      x: tt.caretX,
      y: tt.caretY,
      width: chart.width,
      date: chart.data.labels[idx],
      items: tt.dataPoints
        .filter((p) => p.raw != null)
        .sort((a, b) => b.raw - a.raw)
        .map((p) => ({
          label: p.dataset.label,
          color: p.dataset.borderColor,
          value: p.raw,
          low: p.dataset.lowValues?.[idx],
          high: p.dataset.highValues?.[idx],
          volume: p.dataset.volumeValues?.[idx],
          prev: idx > 0 ? p.dataset.data[idx - 1] : null,
        })),
    });
  }, []);

  const chartData = useMemo(
    () => ({
      labels: trends.data?.labels ?? [],
      datasets: datasets.map((ds, i) => {
        const color = colorFor(ds.id);
        return {
          label: ds.label,
          data: ds.data,
          lowValues: ds.low,
          highValues: ds.high,
          volumeValues: ds.volume,
          borderColor: color,
          borderWidth: i === 0 ? 2.5 : 2,
          tension: 0.35,
          spanGaps: true,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBorderWidth: 2.5,
          pointHoverBackgroundColor: '#fff',
          pointHoverBorderColor: color,
          fill: datasets.length === 1 || i === 0 ? 'origin' : false,
          backgroundColor: (ctx) => {
            const { chart } = ctx;
            const { chartArea } = chart;
            if (!chartArea) return 'transparent';
            const g = chart.ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            g.addColorStop(0, hexToRgba(color, 0.18));
            g.addColorStop(1, hexToRgba(color, 0));
            return g;
          },
        };
      }),
    }),
    [trends.data, datasets, colorFor],
  );

  const options = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 500, easing: 'easeOutQuart' },
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: 8, right: 4 } },
      plugins: {
        legend: { display: false },
        tooltip: { enabled: false, external: externalTooltip },
      },
      scales: {
        x: {
          grid: { display: false },
          border: { display: false },
          ticks: {
            color: '#94a3b8',
            font: { size: 11, family: 'Inter' },
            maxRotation: 0,
            autoSkip: true,
            maxTicksLimit: compact ? 5 : 8,
            callback(value) {
              return formatDateShort(this.getLabelForValue(value));
            },
          },
        },
        y: {
          position: 'right',
          grace: '8%',
          border: { display: false },
          grid: { color: 'rgba(148,163,184,0.16)', drawTicks: false },
          ticks: {
            color: '#94a3b8',
            padding: 10,
            font: { size: 11, family: 'Inter' },
            maxTicksLimit: 5,
            callback: (v) => `₱${Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 })}`,
          },
        },
      },
    }),
    [externalTooltip, compact],
  );

  const addable = varieties.filter((v) => !varietyIds.includes(v.id));
  const loadingFirst = trends.loading && !trends.data;
  const meta = trends.data?.meta;

  return (
    <section className={`card flex flex-col ${className}`} aria-label={title}>
      {/* Header */}
      <div className="flex flex-col gap-4 px-5 pt-5 sm:px-6 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-amber-600/10">
              <LineChart className="h-4 w-4" />
            </span>
            <h2 className="text-base font-bold text-ink">{title}</h2>
            {trends.loading && trends.data && <RefreshCw className="h-3.5 w-3.5 animate-spin text-slate-400" aria-label="Updating" />}
          </div>
          <p className="mt-1 text-sm text-muted">
            {subtitle ??
              `${METRICS.find((m) => m.value === metric)?.label} · ₱ per kg · ${meta?.latest_available_date ? `as of ${formatDateShort(meta.latest_available_date)}` : 'loading…'}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="segmented" role="group" aria-label="Price type">
            {METRICS.map((m) => (
              <button key={m.value} type="button" aria-pressed={metric === m.value} onClick={() => setMetric(m.value)}>
                {m.label}
              </button>
            ))}
          </div>
          <div className="segmented" role="group" aria-label="Date range">
            {RANGES.map((r) => (
              <button key={r.value} type="button" aria-pressed={range === r.value} onClick={() => setRange(r.value)}>
                {r.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Series chips */}
      <div className="flex flex-wrap items-center gap-2 px-5 pt-4 sm:px-6">
        {varietyIds.map((id) => {
          const v = varieties.find((x) => x.id === id);
          const ds = datasets.find((d) => d.id === id);
          return (
            <span
              key={id}
              className="group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-2.5 pr-1 text-xs font-medium text-slate-700 shadow-sm"
            >
              <span className="h-2 w-2 rounded-full" style={{ backgroundColor: colorFor(id) }} />
              {v?.name ?? ds?.label ?? `Crop #${id}`}
              {varietyIds.length > 1 && (
                <button
                  type="button"
                  onClick={() => setVarietyIds((ids) => ids.filter((x) => x !== id))}
                  className="focus-ring rounded-full p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                  aria-label={`Remove ${v?.name ?? 'series'}`}
                >
                  <X className="h-3 w-3" />
                </button>
              )}
            </span>
          );
        })}
        {varietyIds.length < MAX_SERIES &&
          (adding ? (
            <select
              autoFocus
              className="focus-ring rounded-full border border-green-500 bg-white px-3 py-1 text-xs font-medium text-slate-700 shadow-glow"
              defaultValue=""
              onBlur={() => setAdding(false)}
              onChange={(e) => {
                const id = Number(e.target.value);
                if (id) setVarietyIds((ids) => [...ids, id]);
                setAdding(false);
              }}
            >
              <option value="" disabled>
                Choose a crop…
              </option>
              {Object.entries(
                addable.reduce((acc, v) => ({ ...acc, [v.category]: [...(acc[v.category] || []), v] }), {}),
              ).map(([cat, list]) => (
                <optgroup key={cat} label={cat}>
                  {list.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.name}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
          ) : (
            <button
              type="button"
              onClick={() => setAdding(true)}
              className="focus-ring inline-flex items-center gap-1 rounded-full border border-dashed border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-500 transition-colors hover:border-green-500 hover:text-green-700"
            >
              <Plus className="h-3 w-3" /> Compare crop
            </button>
          ))}
      </div>

      {/* Stat strip */}
      {!compact && (
        <div className="grid grid-cols-1 gap-px overflow-hidden border-y border-slate-100 bg-slate-100 mt-4 sm:grid-cols-2 xl:grid-cols-4">
          {(loadingFirst ? varietyIds.map((id) => ({ id })) : datasets).map((ds) => (
            <div key={ds.id} className="bg-white px-5 py-3.5 sm:px-6">
              {loadingFirst ? (
                <>
                  <Skeleton className="h-3 w-24" />
                  <Skeleton className="mt-2 h-6 w-20" />
                </>
              ) : (
                <>
                  <div className="flex items-center gap-1.5 text-xs font-medium text-muted">
                    <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: colorFor(ds.id) }} />
                    <span className="truncate">{ds.label}</span>
                  </div>
                  <div className="mt-1 flex items-baseline gap-2">
                    <span className="num text-xl font-bold text-ink">{formatPeso(ds.summary.latest)}</span>
                    <TrendDelta value={ds.summary.change_pct} />
                  </div>
                  <div className="num mt-1 text-[11px] text-slate-400">
                    H {formatPeso(ds.summary.high)} · L {formatPeso(ds.summary.low)} · σ {formatPct(ds.summary.volatility_pct, { signed: false, digits: 2 })}
                  </div>
                </>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Chart */}
      <div className="flex flex-1 flex-col px-3 pb-4 pt-3 sm:px-4">
        <ErrorNote error={trends.error} onRetry={trends.reload} />
        <div ref={containerRef} className={`relative ${compact ? 'min-h-[15rem] flex-1' : 'h-80'}`} onMouseLeave={() => setTooltip(null)}>
          {loadingFirst ? (
            <Skeleton className="h-full w-full rounded-xl" />
          ) : (
            <Line data={chartData} options={options} aria-label={`${title} chart`} role="img" />
          )}

          {tooltip && (
            <div
              className="pointer-events-none absolute z-10 w-56 rounded-xl border border-white/60 bg-white/85 p-3 shadow-lift ring-1 ring-slate-900/5 backdrop-blur-md transition-[left,top] duration-75"
              style={{
                // Flip to the left of the cursor when the card would overflow the chart.
                left: tooltip.x + 16 + 224 > tooltip.width ? Math.max(tooltip.x - 240, 8) : tooltip.x + 16,
                top: Math.max(tooltip.y - 40, 0),
              }}
            >
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{formatDateWeekday(tooltip.date)}</p>
              <ul className="mt-2 space-y-2">
                {tooltip.items.map((it) => {
                  const dod = it.prev ? (it.value / it.prev - 1) * 100 : null;
                  return (
                    <li key={it.label}>
                      <div className="flex items-center justify-between gap-2">
                        <span className="flex min-w-0 items-center gap-1.5 text-xs text-slate-600">
                          <span className="h-2 w-2 shrink-0 rounded-sm" style={{ backgroundColor: it.color }} />
                          <span className="truncate">{it.label}</span>
                        </span>
                        <span className="num text-sm font-bold text-ink">{formatPeso(it.value)}</span>
                      </div>
                      <div className="num mt-0.5 flex justify-between pl-3.5 text-[10px] text-slate-400">
                        <span>
                          {formatPeso(it.low)}–{formatPeso(it.high)}
                        </span>
                        <span className={dod > 0 ? 'text-green-700' : dod < 0 ? 'text-rose-600' : ''}>{formatPct(dod)} d/d</span>
                      </div>
                      {it.volume != null && <div className="num pl-3.5 text-[10px] text-slate-400">{formatVolume(it.volume, { compact: true })} traded</div>}
                    </li>
                  );
                })}
              </ul>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
