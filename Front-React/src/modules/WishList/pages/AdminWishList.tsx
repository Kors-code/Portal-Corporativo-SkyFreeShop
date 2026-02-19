// AdminWishDashboard.tsx
import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';

function CategoryBarsChart({ rows }: { rows: { category: string; total: number }[] }) {
  const maxTotal = Math.max(...rows.map(r => r.total), 1);
  return (
    <div className="space-y-2">
      {rows.map(row => (
        <div key={row.category} className="flex items-center gap-2">
          <div className="w-24 text-xs truncate">{row.category}</div>
          <div className="flex-1 bg-gray-200 rounded h-6 relative overflow-hidden">
            <div
              className="bg-indigo-600 h-full"
              style={{ width: `${(row.total / maxTotal) * 100}%` }}
            />
          </div>
          <div className="w-20 text-right text-xs font-semibold">{row.total.toLocaleString()}</div>
        </div>
      ))}
      {rows.length === 0 && <div className="text-sm text-gray-500">No hay datos.</div>}
    </div>
  );
}

type WishItem = {
  id: number;
  product_text: string;
  category?: string | null;
  catalog_product_id?: number | null;
  indicator?: 'never_had' | 'we_have_or_had' | string | null;
  count?: number | null;
  created_at?: string | null;
  catalog_product?: {
    id?: number;
    sku?: string | null;
    product?: string | null;
    price_sale?: number | null;
    category?: string | null;
  } | null;
};

type Category = { id: string | number; name: string };

type InformeRow = {
  selection_id: number;
  wish_item_id: number | null;
  wish_text?: string | null;
  category?: string | null;
  catalog_product_sku?: string | null;
  catalog_product?: string | null;
  catalog_price_sale?: number | null;
  reported_by?: { id?: number | null; name?: string | null } | null;
  meta?: string | null;
  created_at?: string | null;
};

export default function AdminWishDashboard({ apiBase = '/api/v1' }: { apiBase?: string }) {
  // fechas
  const [start, setStart] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 29);
    return d.toISOString().slice(0, 10);
  });
  const [end, setEnd] = useState(() => new Date().toISOString().slice(0, 10));

  const [category, setCategory] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<'all' | 'wish' | 'negados'>('all');

  const [categories, setCategories] = useState<Category[]>([]);
  const [byDay, setByDay] = useState<{ day: string; total: number }[]>([]);
  const [byWish, setByWish] = useState<{ wish_item_id: number | null; total: number }[]>([]);
  const [wishItems, setWishItems] = useState<WishItem[]>([]);
  const [informeRows, setInformeRows] = useState<InformeRow[]>([]);

  const [loading, setLoading] = useState(false);
  const [loadingInforme, setLoadingInforme] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // modal
  const [modalOpen, setModalOpen] = useState(false);
  const [modalWish, setModalWish] = useState<WishItem | null>(null);

  // tabs
  const [activeTab, setActiveTab] = useState<'dashboard' | 'informe'>('dashboard');

  // ---------------- Helpers ----------------
  // Normaliza categorías para mostrar (capitaliza) y unir fragancias
  function normalizeCategory(raw?: string | null) {
    if (!raw) return '';
    const s = String(raw).trim();
    const lower = s.toLowerCase();

    const fragIndicadores = ['frag', 'fragr', 'perfume', 'perfumes', 'colonia', 'fragancias', 'fragances', 'fragance'];
    const genderIndicadores = ['unisex', 'men', 'women', 'male', 'female', 'mujer', 'hombre'];


    console.log(error)
    console.log(setModalWish)
    console.log(genderIndicadores)
    if (
      fragIndicadores.some(t => lower.includes(t)) ||
      ((lower.includes('men') || lower.includes('women') || lower.includes('unisex')) && !lower.includes('frag'))
    ) {
      return 'Fragancia';
    }

    // Capitalize first letter
    return lower.charAt(0).toUpperCase() + lower.slice(1);
  }

  // ---------------- Loaders ----------------
  async function loadCategories() {
    try {
      const res = await axios.get(`${apiBase}/catalog/categories`);
      const raw: Category[] = Array.isArray(res.data)
        ? res.data.map((c: any) => ({ id: c.id ?? c, name: c.name ?? String(c) }))
        : [];
      // dedupe usando normalizeCategory
      const seen = new Map<string, Category>();
      for (const c of raw) {
        const norm = normalizeCategory(c.name) || c.name;
        if (!seen.has(norm)) seen.set(norm, { id: c.id, name: norm });
      }
      setCategories(Array.from(seen.values()));
    } catch {
      setCategories([]);
    }
  }

  async function loadAll() {
    setLoading(true);
    setError(null);
    try {
      const params: any = { start, end };
      if (category) params.category = category;

      const statsRes = await axios.get(`${apiBase}/wish-items/stats`, { params });
      const byDayRes = (statsRes.data?.by_day ?? []).map((r: any) => ({ day: r.day, total: Number(r.total) }));
      const byWishRes = (statsRes.data?.by_wish ?? []).map((r: any) => ({ wish_item_id: r.wish_item_id, total: Number(r.total) }));
      setByDay(byDayRes);
      setByWish(byWishRes);

      const wRes = await axios.get(`${apiBase}/wish-items`, { params });
      setWishItems(Array.isArray(wRes.data) ? wRes.data : []);
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'Error cargando datos');
    } finally {
      setLoading(false);
    }
  }

  async function loadInforme() {
    setLoadingInforme(true);
    setError(null);
    try {
      const params: any = { start, end };
      if (category) params.category = category;
      const res = await axios.get(`${apiBase}/wish-items/selections`, { params, withCredentials: true });
      setInformeRows(Array.isArray(res.data) ? res.data : []);
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'Error cargando informe');
      setInformeRows([]);
    } finally {
      setLoadingInforme(false);
    }
  }

  // ---------------- Effects ----------------
  // initial load
  useEffect(() => {
    loadCategories();
    loadAll();
    loadInforme();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // auto-refresh when filters change (applies both to dashboard and informe)
  useEffect(() => {
    loadAll();
    if (activeTab === 'informe') loadInforme();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [start, end, category, viewMode, activeTab]);

  // listen for external updates (other pages should dispatch window.dispatchEvent(new Event('wish:updated')))
  useEffect(() => {
    const handler = () => {
      loadAll();
      if (activeTab === 'informe') loadInforme();
    };
    window.addEventListener('wish:updated', handler);
    return () => window.removeEventListener('wish:updated', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  // ---------------- Derived lookups & aggregation ----------------
  // map wish_item_id -> total from byWish
  const wishCountLookup = useMemo(() => {
    const m = new Map<number, number>();
    for (const b of byWish) {
      if (b.wish_item_id != null) m.set(Number(b.wish_item_id), Number(b.total));
    }
    return m;
  }, [byWish]);

  // totals grouped by SKU (helps when multiple wish_items point to same SKU)
  const bySkuTotals = useMemo(() => {
    const m = new Map<string, number>();
    for (const b of byWish) {
      if (b.wish_item_id == null) continue;
      const wishId = Number(b.wish_item_id);
      const wish = wishItems.find(w => w.id === wishId);
      const sku = wish?.catalog_product?.sku?.trim() ?? '';
      const key = sku || `wish:${wishId}`;
      m.set(key, (m.get(key) ?? 0) + Number(b.total ?? 0));
    }
    return m;
  }, [byWish, wishItems]);

  // filtered wishes according to viewMode (for dashboard)
  const filteredWishItems = useMemo(() => {
    if (viewMode === 'wish') return wishItems.filter(w => (w.indicator ?? 'never_had') === 'never_had');
    if (viewMode === 'negados') return wishItems.filter(w => (w.indicator ?? '') === 'we_have_or_had');
    return wishItems;
  }, [wishItems, viewMode]);

  // aggregated summary (group by SKU or clean text) -> used by chart + right-list
  const aggregatedWishes = useMemo(() => {
    const map = new Map<string, {
      key: string;
      ids: number[];
      product_text: string;
      category: string;
      count: number;
      ventaPerdida: number;
      catalog_product?: WishItem['catalog_product'] | null;
      created_at?: string | null;
    }>();

    for (const w of filteredWishItems) {
      const normalizedText = (w.product_text ?? '').trim().toLowerCase();
      const key = (w.catalog_product?.sku?.trim() ?? (normalizedText || `wish:${w.id}`));

      // prefer w.count, else wishCountLookup[w.id], else bySkuTotals[sku]
      const skuKey = (w.catalog_product?.sku?.trim() ?? '') || `wish:${w.id}`;
      const cnt = Number(
        w.count ??
        wishCountLookup.get(w.id) ??
        bySkuTotals.get(skuKey) ??
        0
      );

      const price = Number(w.catalog_product?.price_sale ?? 0);
      const venta = cnt * (isFinite(price) ? price : 0);

      if (!map.has(key)) {
        map.set(key, {
          key,
          ids: [w.id],
          product_text: w.product_text ?? '',
          category: normalizeCategory(w.category ?? w.catalog_product?.category ?? '') || '',
          count: cnt,
          ventaPerdida: venta,
          catalog_product: w.catalog_product ?? null,
          created_at: w.created_at ?? null
        });
      } else {
        const cur = map.get(key)!;
        cur.ids.push(w.id);
        // When merging, avoid double counting by using cnt as additional (this is safe because cnt is "total" for that wish)
        cur.count += cnt;
        cur.ventaPerdida += venta;
        if (!cur.catalog_product && w.catalog_product) cur.catalog_product = w.catalog_product;
        if (!cur.created_at && w.created_at) cur.created_at = w.created_at;
      }
    }

    return Array.from(map.values()).map(x => ({ ...x, ventaPerdida: parseFloat((x.ventaPerdida ?? 0).toFixed(2)) }));
  }, [filteredWishItems, wishCountLookup, bySkuTotals]);

  // byCategoryVenta uses aggregatedWishes (normalized categories for dashboard)
  const byCategoryVenta = useMemo(() => {
    const map = new Map<string, number>();
    for (const w of aggregatedWishes) {
      const cat = w.category || 'Sin categoría';
      map.set(cat, (map.get(cat) ?? 0) + (w.ventaPerdida ?? 0));
    }
    const arr = Array.from(map.entries()).map(([category, total]) => ({ category, total }));
    arr.sort((a, b) => b.total - a.total);
    return arr.slice(0, 12);
  }, [aggregatedWishes]);

  // totals
  const totalSelections = useMemo(() => byDay.reduce((s, r) => s + (r.total || 0), 0), [byDay]);
  const daysCount = byDay.length;
  const topDay = useMemo(() => (byDay.length ? [...byDay].sort((a,b)=>b.total-a.total)[0] : null), [byDay]);

  const topWishes = useMemo(() => {
    return [...aggregatedWishes].sort((a,b) => (b.ventaPerdida - a.ventaPerdida)).slice(0, 50);
  }, [aggregatedWishes]);

  // filtered informe rows (aplica viewMode si corresponde). IMPORTANT: NO normaliza categoría para mantener original en informe
  const filteredInformeRows = useMemo(() => {
    if (!informeRows.length) return [];

    if (viewMode === 'all') return informeRows;

    return informeRows.filter(r => {
      if (!r.wish_item_id) return false;
      const wish = wishItems.find(w => w.id === r.wish_item_id);
      if (!wish) return false;
      if (viewMode === 'wish') return (wish.indicator ?? 'never_had') === 'never_had';
      if (viewMode === 'negados') return (wish.indicator ?? '') === 'we_have_or_had';
      return true;
    });
  }, [informeRows, viewMode, wishItems]);

  // ---------------- Export helpers ----------------
  function exportCSV(data: any[], filename = 'export.csv') {
    if (!data || !data.length) return;

    const headers = [
      { key: 'catalog_product_sku', label: 'SKU' },
      { key: 'wish_text', label: 'Texto' },
      { key: 'category', label: 'Categoría' },
      { key: 'catalog_price_sale', label: 'Precio venta' },
      { key: 'reported_by_name', label: 'Reportó (Nombre)' },
      { key: 'created_at', label: 'Fecha' }
    ];

    const delimiter = ';';
    const headerLine = headers.map(h => `"${h.label}"`).join(delimiter);

    const rows = data.map(row =>
      headers.map(h => {
        const value = row[h.key] ?? '';
        const clean = String(value)
          .replace(/"/g, '""')
          .replace(/\r?\n/g, ' ');
        return `"${clean}"`;
      }).join(delimiter)
    );

    const csvContent = '\uFEFF' + [headerLine, ...rows].join('\r\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  function exportVistaActual() {
    if (!aggregatedWishes.length) return;

    const headers = [
      { key: 'sku', label: 'SKU / ID' },
      { key: 'product_text', label: 'Texto' },
      { key: 'category', label: 'Categoría' },
      { key: 'count', label: 'Veces' },
      { key: 'ventaPerdida', label: 'Venta perdida' },
      { key: 'created_at', label: 'Fecha creación' }
    ];

    const delimiter = ';';
    const headerLine = headers.map(h => `"${h.label}"`).join(delimiter);

    const rows = aggregatedWishes.map(w => {
      const normalized = {
        sku: w.catalog_product?.sku ?? w.ids.join('|'),
        product_text: w.product_text ?? '',
        category: w.category ?? '',
        count: w.count ?? 0,
        ventaPerdida: (w as any).ventaPerdida ?? 0,
        created_at: w.created_at ? w.created_at.slice(0,19).replace('T',' ') : ''
      };

      return headers.map(h => {
        const value = normalized[h.key as keyof typeof normalized] ?? '';
        return `"${String(value).replace(/"/g, '""')}"`;
      }).join(delimiter);
    });

    const csvContent = '\uFEFF' + [headerLine, ...rows].join('\r\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `wish_export_${viewMode}_${start}_${end}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  function exportInforme() {
    const normalized = filteredInformeRows.map(r => ({
      catalog_product_sku: r.catalog_product_sku ?? '',
      wish_text: r.wish_text ?? '',
      category: r.category ?? '',
      catalog_price_sale: r.catalog_price_sale ?? '',
      reported_by_name: r.reported_by?.name ?? '',
      created_at: r.created_at ? (r.created_at.slice(0,19).replace('T',' ')) : ''
    }));
    exportCSV(normalized, `informe_${viewMode}_${start}_${end}.csv`);
  }

  // ---------------- Render ----------------
  return (

  
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      
      <div className="flex items-center justify-between">
        <div className="fixed top-4 left-4 z-50 flex gap-3">
          <button
            onClick={() => window.location.href = '/panel/CatalogMatchPage'}
            className="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg shadow-md"
          >
            ← Volver
          </button>


        </div>
        <h1 className="text-2xl font-bold text-[#840028]"> Wish & Denied</h1>

        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <div className="text-sm text-gray-600">Vista:</div>
            <div className="flex rounded bg-gray-100 p-1">
              <button onClick={() => setViewMode('all')} className={`px-3 py-1 rounded ${viewMode === 'all' ? 'bg-white shadow' : ''}`}>Todas</button>
              <button onClick={() => setViewMode('wish')} className={`px-3 py-1 rounded ${viewMode === 'wish' ? 'bg-white shadow' : ''}`}>Wish list</button>
              <button onClick={() => setViewMode('negados')} className={`px-3 py-1 rounded ${viewMode === 'negados' ? 'bg-white shadow' : ''}`}>Negados</button>
            </div>
          </div>

          <div className="bg-gray-100 rounded p-1 ml-3">
            <button className={`px-3 py-1 rounded ${activeTab === 'dashboard' ? 'bg-white shadow' : ''}`} onClick={() => setActiveTab('dashboard')}>Dashboard</button>
            <button className={`px-3 py-1 rounded ${activeTab === 'informe' ? 'bg-white shadow' : ''}`} onClick={() => setActiveTab('informe')}>Informe completo</button>
            <button
            onClick={() => window.location.href = '/panel/CatalogMatchPage'}
            className="px-4 py-2 bg-[#840028]  text-white rounded-lg shadow-md"
            > Reportar Productos
          </button>
          </div>
        </div>
      </div>

      {/* filtros */}
      <div className="bg-white p-4 rounded shadow">
        <div className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
          <div>
            <label className="text-xs text-gray-600">Desde</label>
            <input type="date" value={start} onChange={e => setStart(e.target.value)} className="border rounded px-2 py-1" />
          </div>
          <div>
            <label className="text-xs text-gray-600">Hasta</label>
            <input type="date" value={end} onChange={e => setEnd(e.target.value)} className="border rounded px-2 py-1" />
          </div>
          <div>
            <label className="text-xs text-gray-600">Categoría</label>
            <select value={category ?? ''} onChange={e => setCategory(e.target.value || null)} className="border rounded px-2 py-1">
              <option value="">-- Todas --</option>
              {categories.map(c => <option key={String(c.id)} value={c.name}>{c.name}</option>)}
            </select>
          </div>

          <div className="md:col-span-2">
            {activeTab === 'dashboard' ? (
              <>
                <button onClick={loadAll} className="px-4 py-2 bg-[#840028] text-white rounded mr-2">Actualizar</button>
                <button onClick={exportVistaActual} className="px-3 py-2 border rounded mr-2">Exportar {viewMode === 'all' ? 'Todas' : viewMode === 'wish' ? 'Wish List' : 'Negados'} Excel</button>
                
              </>
            ) : (
              <>
                <button onClick={() => loadInforme()} className="px-3 py-2 border rounded mr-2">Refrescar informe</button>
                <button onClick={exportInforme} className="px-3 py-2 bg-indigo-600 text-white rounded">Exportar informe Excel</button>
              </>
            )}
          </div>

          <div className="text-right">
            <div className="text-sm text-gray-500">Estado: { activeTab === 'informe' ? (loadingInforme ? 'Cargando informe…' : 'Listo') : (loading ? 'Cargando…' : 'Listo') }</div>
          </div>
        </div>
      </div>

      {/* contenido */}
      {activeTab === 'dashboard' ? (
        <>
          {/* KPIs */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div className="bg-white p-4 rounded shadow">
              <div className="text-sm text-gray-500">Total Reportes</div>
              <div className="text-2xl font-bold">{totalSelections}</div>
            </div>
            <div className="bg-white p-4 rounded shadow">
              <div className="text-sm text-gray-500">Días en rango</div>
              <div className="text-2xl font-bold">{daysCount}</div>
            </div>
            <div className="bg-white p-4 rounded shadow">
              <div className="text-sm text-gray-500">Top día</div>
              <div className="text-lg font-semibold">{topDay ? `${topDay.day} — ${topDay.total}` : '—'}</div>
            </div>
            <div className="bg-white p-4 rounded shadow">
              <div className="text-sm text-gray-500">Venta perdida total (vista)</div>
              <div className="text-2xl font-bold">
                {byCategoryVenta.reduce((s, r) => s + r.total, 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </div>
            </div>
          </div>

          {/* Chart + top table */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="bg-white p-4 rounded shadow md:col-span-2">
              <h3 className="font-semibold mb-2">Venta perdida por categoría ({viewMode})</h3>
              <CategoryBarsChart rows={byCategoryVenta} />
            </div>

            <div className="bg-white p-4 rounded shadow">
              <h3 className="font-semibold mb-2">Top wishes por venta perdida</h3>
              <div className="max-h-[380px] overflow-auto">
                <table className="w-full text-left text-sm">
                  <thead className="text-xs text-gray-500 uppercase">
                    <tr>
                      <th className="p-2">Categoría</th>
                      <th className="p-2 text-right">Veces</th>
                      <th className="p-2 text-right">Venta perdida</th>
                    </tr>
                  </thead>
                  <tbody>
                    {topWishes.map((w, idx) => (
                      <tr key={w.key + '_' + idx} className="border-t">
                        <td className="p-2">{w.category ?? w.catalog_product?.category ?? '—'}</td>
                        <td className="p-2 text-right">{w.count ?? 0}</td>
                        <td className="p-2 text-right">{(w as any).ventaPerdida?.toLocaleString?.() ?? '0'}</td>
                      </tr>
                    ))}
                    {topWishes.length === 0 && <tr><td colSpan={6} className="p-4 text-sm text-gray-500">No hay datos.</td></tr>}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          {/* Recent wish items (resumen) */}
          <div className="bg-white p-4 rounded shadow">
            <h3 className="font-semibold mb-2">Aportes / Wish items (most recent - resumen)</h3>
            <div className="max-h-[360px] overflow-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-xs text-gray-500 uppercase">
                  <tr>
                    <th className="p-2">ID / SKU</th>
                    <th className="p-2">Texto</th>
                    <th className="p-2">Categoría</th>
                    <th className="p-2">Count</th>
                    <th className="p-2">Venta perdida</th>
                    <th className="p-2">Creado</th>
                  </tr>
                </thead>
                <tbody>
                  {aggregatedWishes.map(w => (
                    <tr key={w.key} className="border-t">
                      <td className="p-2">{w.catalog_product?.sku ?? w.ids.join('|')}</td>
                      <td className="p-2">{w.product_text}</td>
                      <td className="p-2">{w.category ?? '—'}</td>
                      <td className="p-2">{w.count ?? 0}</td>
                      <td className="p-2">{(w as any).ventaPerdida?.toLocaleString?.() ?? '0'}</td>
                      <td className="p-2">{w.created_at ? w.created_at.slice(0,19).replace('T',' ') : '—'}</td>
                    </tr>
                  ))}
                  {aggregatedWishes.length === 0 && <tr><td colSpan={6} className="p-4 text-sm text-gray-500">No hay datos.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        </>
      ) : (
        <>
          {/* Informe completo */}
          <div className="bg-white p-4 rounded shadow">
            <h3 className="font-semibold mb-2">Informe completo — registro por registro</h3>
            {loadingInforme ? (
              <div className="text-sm text-gray-500">Cargando informe…</div>
            ) : (
              <div className="max-h-[600px] overflow-auto">
                <table className="w-full text-left text-sm">
                  <thead className="text-xs text-gray-500 uppercase">
                    <tr>

                      <th className="p-2">SKU</th>
                      <th className="p-2">Producto</th>
                      <th className="p-2">Categoría</th>
                      <th className="p-2">Precio</th>
                      <th className="p-2">Reportó (Nombre)</th>
                      <th className="p-2">Creado</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredInformeRows.map(r => (
                      <tr key={r.selection_id} className="border-t">

                        <td className="p-2">{r.catalog_product_sku ?? '—'}</td>
                        <td className="p-2">{r.wish_text ?? '—'}</td>
                        {/* mostramos la categoría ORIGINAL en el informe */}
                        <td className="p-2">{r.category ?? '—'}</td>
                        <td className="p-2">{r.catalog_price_sale ?? '—'}</td>
                        <td className="p-2">{r.reported_by?.name ?? `user:${r.reported_by?.id ?? ''}`}</td>
                        <td className="p-2">{r.created_at ? (r.created_at.slice(0,19).replace('T',' ')) : '—'}</td>
                      </tr>
                    ))}
                    {filteredInformeRows.length === 0 && <tr><td colSpan={10} className="p-4 text-sm text-gray-500">No hay registros en el rango.</td></tr>}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}

      {/* Modal detalle */}
      {modalOpen && modalWish && (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-6 bg-black/40">
          <div className="bg-white rounded p-6 max-w-2xl w-full">
            <div className="flex justify-between items-center mb-4">
              <h3 className="font-semibold">Detalle Wish #{modalWish.id}</h3>
              <button onClick={() => setModalOpen(false)} className="text-gray-600">Cerrar</button>
            </div>

            <div className="space-y-3 text-sm">
              <div><strong>Texto:</strong> {modalWish.product_text}</div>
              <div><strong>Categoría:</strong> {normalizeCategory(modalWish.category ?? modalWish.catalog_product?.category ?? '') || '—'}</div>
              <div><strong>SKU catálogo:</strong> {modalWish.catalog_product?.sku ?? '—'}</div>
              <div><strong>Precio venta:</strong> {modalWish.catalog_product?.price_sale ?? '—'}</div>
              <div><strong>Indicador:</strong> {modalWish.indicator ?? '—'}</div>
              <div><strong>Count total:</strong> {modalWish.count ?? 0}</div>
              <div><strong>Venta perdida (vista):</strong> {((Number(modalWish.count ?? 0) * Number(modalWish.catalog_product?.price_sale ?? 0))).toLocaleString()}</div>
              <div><strong>Creado:</strong> {modalWish.created_at ?? '—'}</div>
            </div>

            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setModalOpen(false)} className="px-3 py-2 bg-indigo-600 text-white rounded">Cerrar</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
