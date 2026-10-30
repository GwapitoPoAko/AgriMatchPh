import { useEffect, useMemo, useState } from 'react';

const tileXY = (lat, lon, z) => {
  const n = 2 ** z;
  const x = ((lon + 180) / 360) * n;
  const rad = (lat * Math.PI) / 180;
  const y = ((1 - Math.asinh(Math.tan(rad)) / Math.PI) / 2) * n;
  return { x, y };
};
const geo = (x, y, z) => {
  const n = 2 ** z;
  return { longitude: (x / n) * 360 - 180, latitude: (Math.atan(Math.sinh(Math.PI * (1 - (2 * y) / n))) * 180) / Math.PI };
};

/** Lightweight OSM pin map with debounced Nominatim reverse lookup. */
export default function FarmLocationInput({ value, onChange, provinces = [] }) {
  const zoom = 12;
  const [center, setCenter] = useState(() => ({ latitude: Number(value.latitude) || 8.0, longitude: Number(value.longitude) || 125.0 }));
  const [lookup, setLookup] = useState('');
  const origin = useMemo(() => tileXY(center.latitude, center.longitude, zoom), [center]);
  const pin = useMemo(() => value.latitude && value.longitude ? tileXY(Number(value.latitude), Number(value.longitude), zoom) : null, [value.latitude, value.longitude]);

  useEffect(() => {
    if (!value.latitude || !value.longitude) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      setLookup('Finding address…');
      try {
        const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=${value.latitude}&lon=${value.longitude}`;
        const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Address lookup unavailable');
        const { address = {} } = await response.json();
        const municipality = address.municipality || address.city || address.town || address.village || address.county || '';
        const barangay = address.city_district || address.suburb || address.neighbourhood || address.quarter || address.village || address.hamlet || '';
        const provinceName = address.province || address.state || address.region || '';
        const province = provinces.find((p) => p.name.toLowerCase() === provinceName.toLowerCase() || provinceName.toLowerCase().includes(p.name.toLowerCase()));
        onChange({ municipality, barangay, ...(province ? { province_id: String(province.id) } : {}) });
        setLookup([barangay, municipality, provinceName].filter(Boolean).join(', ') || 'Pin saved');
      } catch (error) {
        if (error.name !== 'AbortError') setLookup('Pin saved; address lookup unavailable');
      }
    }, 900);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [value.latitude, value.longitude]);

  const tiles = [];
  for (let dy = -1; dy <= 1; dy += 1) for (let dx = -1; dx <= 1; dx += 1) {
    const x = Math.floor(origin.x) + dx;
    const y = Math.floor(origin.y) + dy;
    tiles.push({ x, y, key: `${x}-${y}`, left: `calc(50% + ${(x - origin.x) * 256}px)`, top: `calc(50% + ${(y - origin.y) * 256}px)` });
  }
  const setPinAt = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    const x = origin.x + (event.clientX - rect.left - rect.width / 2) / 256;
    const y = origin.y + (event.clientY - rect.top - rect.height / 2) / 256;
    const point = geo(x, y, zoom);
    setCenter(point);
    onChange({ latitude: point.latitude.toFixed(6), longitude: point.longitude.toFixed(6) });
  };
  const useGps = () => navigator.geolocation?.getCurrentPosition((pos) => {
    const point = { latitude: pos.coords.latitude, longitude: pos.coords.longitude };
    setCenter(point);
    onChange({ latitude: point.latitude.toFixed(6), longitude: point.longitude.toFixed(6) });
  }, () => setLookup('Could not access device location'), { enableHighAccuracy: true, timeout: 10000 });

  return <div className="space-y-2 sm:col-span-3">
    <div className="flex flex-wrap items-center justify-between gap-2"><p className="text-xs font-semibold text-slate-700">Farm pin · click map to place or move pin</p><button type="button" onClick={useGps} className="btn-ghost px-3 py-1.5 text-xs">Use device GPS</button></div>
    <div onClick={setPinAt} className="relative h-64 cursor-crosshair overflow-hidden rounded-xl border border-slate-200 bg-emerald-50" role="application" aria-label="Map for choosing farm location">
      {tiles.map((t) => <img key={t.key} alt="" draggable="false" src={`https://tile.openstreetmap.org/${zoom}/${((t.x % 2 ** zoom) + 2 ** zoom) % 2 ** zoom}/${t.y}.png`} className="pointer-events-none absolute h-64 w-64 max-w-none" style={{ left: t.left, top: t.top, transform: 'translate(-50%, -50%)' }} />)}
      {pin && <span className="pointer-events-none absolute left-1/2 top-1/2 z-10 -translate-x-1/2 -translate-y-full drop-shadow" style={{ marginLeft: `${(pin.x - origin.x) * 256}px`, marginTop: `${(pin.y - origin.y) * 256}px` }}><span className="block h-5 w-5 rounded-full border-[5px] border-white bg-rose-600 shadow-lg" /></span>}
      {!pin && <span className="pointer-events-none absolute inset-0 grid place-items-center text-xs font-semibold text-slate-600">Click to place farm pin</span>}
      <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer" onClick={(event) => event.stopPropagation()} className="absolute bottom-1 right-1 z-20 rounded bg-white/85 px-1 text-[9px] text-slate-600">© OpenStreetMap contributors</a>
    </div>
    <div className="flex flex-wrap justify-between gap-2 text-[11px] text-muted"><span>{lookup || 'Reverse geocoding fills barangay and municipality when available.'}</span><span className="num">{value.latitude && value.longitude ? `${value.latitude}, ${value.longitude}` : 'No pin selected'}</span></div>
    <span className="sr-only">Map coordinates set: {value.latitude}, {value.longitude}</span>
  </div>;
}
