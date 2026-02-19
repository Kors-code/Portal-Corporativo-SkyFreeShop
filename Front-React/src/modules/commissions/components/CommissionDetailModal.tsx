import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
type SaleRow = {
  id?: number | string; // opcional si el backend lo provee
  sale_date: string;
  folio: string;
  product: string;
  amount_cop: number;
  value_usd: number;
  exchange_rate?: number | null;

  // campos adicionales que pueden venir del backend
  provider?: string;
  brand?: string;

  // ⚠️ calculado en frontend
  commission_amount?: number;

  is_provisional?: boolean;
  category_code?: string;

  // clave única generada en frontend (fallback si no hay id)
  rowKey?: string;
};

export default function CommissionDetailModal({
  userId,
  budgetIds,
  onClose
}: {
  userId: number;
  budgetIds: number[]; // ahora recibe un array de presupuestos
  onClose: () => void;
}) {
  const [loading, setLoading] = useState(true);
  const [sales, setSales] = useState<SaleRow[]>([]);
  const [categories, setCategories] = useState<any[]>([]);
  const [totals, setTotals] = useState<any>(null);
  const [budgetInfo, setBudgetInfo] = useState<any>(null); // info retornada por el API (puede ser agregado)
  const [userBudgetUsd, setUserBudgetUsd] = useState<number>(0);
  const [userName, setUserName] = useState<string>('Vendedor');

  const [filterCat, setFilterCat] = useState<string>('ALL');
  const [search, setSearch] = useState('');
  const [categoryView, setCategoryView] = useState<'cards' | 'table'>('cards');

  // nuevos filtros
  const [filterProvider, setFilterProvider] = useState<string>('ALL');
  const [filterBrand, setFilterBrand] = useState<string>('ALL');
  const [filterProduct, setFilterProduct] = useState<string>('ALL');
  const [filterFolios, setFilterFolios] = useState<string[]>([]); // multi-select for folios

  // Turnos: mostramos el total agregado, pero permitimos editar por presupuesto individual
  const [assignedTurnsTotal, setAssignedTurnsTotal] = useState<number>(0); // suma de turnos asignados en budgets seleccionados
  const [selectedBudgetForEdit, setSelectedBudgetForEdit] = useState<number | null>(null); // presupuesto a editar
  const [editedTurns, setEditedTurns] = useState<number>(0); // input editable para el presupuesto seleccionado
  const [savingTurns, setSavingTurns] = useState(false);

  // Guardar una representación string de budgetIds en el effect deps para detectar cambios en order/values
  const budgetIdsKey = (budgetIds || []).join(',');

  // Construye query params con budget_ids[]
  const buildBudgetParams = (ids: number[]) => {
    const p = new URLSearchParams();
    ids.forEach(id => p.append('budget_ids[]', String(id)));
    return p.toString();
  };

  useEffect(() => {
    if (userId && budgetIds && budgetIds.length > 0) {
      // default selected budget to first (usable for editing turns)
      setSelectedBudgetForEdit(budgetIds[0] ?? null);
      load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId, budgetIdsKey]);

  const load = async () => {
    setLoading(true);
    try {
      // Llamada multi-budget: commissions/by-seller/:userId?budget_ids[]=1&budget_ids[]=2...
      const q = buildBudgetParams(budgetIds);
      const res = await api.get(`commissions/by-seller/${userId}?${q}`);
      const d = res.data || {};

      // categories, sales, totals, budget info (el backend ya devuelve agregados para los budgets)
      setCategories(d.categories || []);
      setTotals(d.totals || {});
      setBudgetInfo(d.budget || null);
      setUserBudgetUsd(d.user_budget_usd ?? 0);
      setUserName(d.user?.name ?? d.seller_name ?? 'Vendedor');

      // sales: calculamos la comisión provisional en frontend (igual que exportSellerDetail)
      const avgTrm = Number(d.totals?.avg_trm || 0) || 1;
      const computedSales: SaleRow[] = (d.sales || []).map((s: any, i: number) => {
        const amountCop = Number(s.amount_cop || 0);
        const valueUsd = Number(s.value_usd || 0);

        const cat = (d.categories || []).find((c: any) =>
          String(c.classification_code) === String(s.category_code)
        );

        const pct = Number(cat?.applied_commission_pct || 0);

        const commission =
          amountCop > 0
            ? amountCop * (pct / 100)
            : valueUsd * avgTrm * (pct / 100);

        // clave única por fila. Usa s.id si el backend la trae (recomendado).
        const rowKey = s.id ? String(s.id) : `${s.folio ?? 'nofolio'}-${s.sale_date ?? 'nodate'}-${String(s.product ?? '').slice(0,30)}-${i}`;

        return {
          ...s,
          id: s.id,
          commission_amount: Math.round(commission),
          is_provisional: true,
          rowKey,
        } as SaleRow;
      });

      setSales(computedSales);

      // assigned_turns_for_user llega como agregado (suma) desde backend
      const assigned = Number(d.assigned_turns_for_user ?? 0);
      setAssignedTurnsTotal(assigned);
      setEditedTurns(assigned); // por defecto mostramos la suma; al editar escogeremos budget individual

      // reset filters when loading fresh data
      setFilterProvider('ALL');
      setFilterBrand('ALL');
      setFilterProduct('ALL');
      setFilterFolios([]);

      // Si el backend devuelve información por presupuesto (opcional), podemos inicializar selectedBudgetWithValue
      // pero por simplicidad usamos selectedBudgetForEdit (ya seteado al primero).
    } catch (err) {
      console.error('Error cargando detalle de comisiones', err);
      // limpiar estados en error
      setCategories([]);
      setSales([]);
      setTotals(null);
      setBudgetInfo(null);
      setAssignedTurnsTotal(0);
      setEditedTurns(0);
    } finally {
      setLoading(false);
    }
  };

  // helper para formatear dinero
  const moneyUSD = (v: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v || 0);
  const moneyCOP = (v: number) =>
    new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v || 0);

  /* ================= KPIs ================= */
  const totalSalesUsd = Array.isArray(categories)
    ? categories.reduce((s, c) => s + Number(c.sales_sum_usd || 0), 0)
    : 0;

  const totalCommissionUsd =
    Number(totals?.total_commission_cop || 0) / Number(totals?.avg_trm || 1);

  /* ================= CATEGORY DATA ================= */
  const categoryCards = categories.map((c: any) => {
    const sales = Number(c.sales_sum_usd || 0);
    const ppto = Number(c.category_budget_usd_for_user || 0);
    const diff = sales - ppto;
    const pct = Number(c.pct_user_of_category_budget || 0);

    return {
      code: c.classification_code,
      name: c.category ?? c.classification_desc ?? 'Sin categoría',
      sales,
      ppto,
      diff,
      pct,
      commissionUsd: Number(c.commission_sum_usd || 0),
      appliedPct: Number(c.applied_commission_pct || 0)
    };
  });

  // derive lists for new filters
  const providers = useMemo(() => Array.from(new Set(sales.map(s => s.provider).filter(Boolean))), [sales]);
  const brands = useMemo(() => Array.from(new Set(sales.map(s => s.brand).filter(Boolean))), [sales]);
  const productsList = useMemo(() => Array.from(new Set(sales.map(s => s.product).filter(Boolean))), [sales]);
  const foliosList = useMemo(() => Array.from(new Set(sales.map(s => s.folio).filter(Boolean))), [sales]);

  /**
   * Guardar turnos para el presupuesto seleccionado (el endpoint espera un budgetId individual)
   * Si el usuario seleccionó varios budgets, debe escoger en el select cuál editar.
   */
  const saveAssignedTurns = async () => {
    if (!selectedBudgetForEdit || !userId) return;

    setSavingTurns(true);
    try {
      await api.post(`/commissions/assign-turns/${userId}/${selectedBudgetForEdit}`, {
        assigned_turns: Number(editedTurns)
      });

      // recargar datos para reflejar cambios
      await load();
      // retroalimentación simple
      // (podrías mostrar toast en lugar de alert)
      // alert('Turnos actualizados');
    } catch (e) {
      console.error(e);
      alert('Error al guardar los turnos');
    } finally {
      setSavingTurns(false);
    }
  };

async function downloadExcel() {
  if (!userId || !budgetIds.length) {
    alert('Selecciona al menos un presupuesto');
    return;
  }

  try {
    const params = new URLSearchParams();
    budgetIds.forEach(id => params.append('budget_ids[]', String(id)));

    const res = await api.get(
      `/commissions/by-seller/${userId}/export?${params.toString()}`,
      { responseType: 'blob' }
    );

    const blob = new Blob(
      [res.data],
      { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }
    );

    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `detalle_comisiones_${userName.replace(/\s+/g, '_')}.xlsx`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);

  } catch (e) {
    console.error(e);
    alert('Error descargando el Excel');
  }
}

  

  /* ================= SALES FILTER ================= */
  const filteredSales = useMemo(() => {
    return sales.filter(s => {
      if (filterCat !== 'ALL' && String(s.category_code ?? '') !== String(filterCat)) return false;
      if (filterProvider !== 'ALL' && String(s.provider ?? '') !== String(filterProvider)) return false;
      if (filterBrand !== 'ALL' && String(s.brand ?? '') !== String(filterBrand)) return false;
      if (filterProduct !== 'ALL' && String(filterProduct) !== String(s.product ?? '')) return false;
      if (filterFolios.length > 0 && !filterFolios.includes(String(s.folio ?? ''))) return false;

      if (!search) return true;
      return `${s.product} ${s.folio}`.toLowerCase().includes(search.toLowerCase());
    });
  }, [sales, filterCat, search, filterProvider, filterBrand, filterProduct, filterFolios]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />

      <div className="relative bg-gray-50 rounded-2xl shadow-2xl w-11/12 lg:w-5/6 p-6 overflow-auto max-h-[92vh]">
        {/* HEADER */}
        <div className="flex justify-between items-start mb-8">
          <div>
            <div className="text-xs text-gray-500">Detalle de comisiones</div>
            <h2 className="text-2xl font-bold">{userName}</h2>
            {budgetInfo && (
              <div className="text-xs text-gray-400 mt-1">
                {/* si backend devuelve nombre/periodo multi, lo mostramos.
                    budgetInfo puede ser un objeto con ids/name/start_date/end_date */}
                {budgetInfo.name ? `Presupuestos: ${budgetInfo.name}` : `Presupuestos: ${budgetIds.join(', ')}`}
                {budgetInfo.start_date && budgetInfo.end_date ? ` — ${budgetInfo.start_date} → ${budgetInfo.end_date}` : null}
              </div>
            )}
            {!budgetInfo && (
              <div className="text-xs text-gray-400 mt-1">Presupuestos: {budgetIds.join(', ')}</div>
            )}
          </div>

          <button onClick={onClose} className="text-gray-400 hover:text-gray-800 text-xl">✕</button>
        </div>

        {/* TURNOS */}
        <div className="bg-white rounded-xl shadow border p-4 mb-8">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
              <div className="text-xs text-gray-500">Turnos asignados (suma)</div>
              <div className="text-lg font-semibold">{assignedTurnsTotal}</div>
              <div className="text-xs text-gray-400 mt-1">Sumatoria en los presupuestos seleccionados</div>
            </div>

            <div className="flex flex-col sm:flex-row items-center gap-3">
              <div className="text-xs text-gray-500">Editar turnos por presupuesto</div>

              <select
                value={selectedBudgetForEdit ?? ''}
                onChange={e => {
                  const id = e.target.value ? Number(e.target.value) : null;
                  setSelectedBudgetForEdit(id);
                }}
                className="border rounded-lg px-3 py-2 text-sm bg-white"
              >
                {budgetIds.map((bid, idx) => (
                  <option key={`budget-${bid}-${idx}`} value={bid}> {`Budget ${bid}`} </option>
                ))}
              </select>

              <input
                type="number"
                min={0}
                value={editedTurns}
                onChange={e => setEditedTurns(Number(e.target.value))}
                className="w-28 border rounded-lg px-3 py-2 text-sm"
              />

              <button
                onClick={saveAssignedTurns}
                disabled={savingTurns || !selectedBudgetForEdit}
                className={`px-4 py-2 rounded-lg text-sm font-medium ${
                  savingTurns ? 'bg-gray-300 text-gray-600' : 'bg-indigo-600 text-white hover:bg-indigo-700'
                }`}
              >
                {savingTurns ? 'Guardando…' : 'Guardar'}
              </button>
            </div>
          </div>
        </div>

        {loading ? (
          <div className="p-16 text-center text-gray-500">Cargando información…</div>
        ) : (

          <>
            {/* KPI CARDS */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-5 mb-10">
              <KpiCard label="Ventas USD" value={moneyUSD(totalSalesUsd)} icon="💰" />
              <KpiCard label="PPTO USD (usuario)" value={moneyUSD(userBudgetUsd)} icon="🎯" />
              <KpiCard label="Comisión USD" value={moneyUSD(totalCommissionUsd)} icon="🏆" />
              <KpiCard
                label="Comisión COP"
                value={moneyCOP(totals?.total_commission_cop)}
                sub={`TRM ${Number(totals?.avg_trm || 0).toFixed(2)}`}
                icon="🇨🇴"
              />
            </div>

<div className="flex justify-end mb-6">
  <button
    onClick={downloadExcel}
    className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-md hover:shadow-lg transition-all"
  >
    📥 Descargar Excel
  </button>
</div>


            {/* CATEGORY HEADER + TOGGLE */}
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-semibold">Desempeño por categoría</h3>
              <div className="flex gap-2">
                <button
                  onClick={() => setCategoryView('cards')}
                  className={`px-3 py-1.5 rounded text-sm ${categoryView === 'cards' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'}`}>
                  Cards
                </button>
                <button
                  onClick={() => setCategoryView('table')}
                  className={`px-3 py-1.5 rounded text-sm ${categoryView === 'table' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'}`}>
                  Tabla
                </button>
              </div>
            </div>

            {/* CATEGORY VIEW */}
            {categoryView === 'cards' ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-12">
                {categoryCards.map((c, idx) => {
                  const status = c.pct >= 100 ? 'success' : c.pct >= 90 ? 'warning' : 'danger';
                  return (
                    <div key={`${c.code}-${idx}`} className="bg-white rounded-2xl shadow border p-4 relative">
                      <div className={`absolute top-0 left-0 h-1 w-full rounded-t-2xl ${status === 'success' ? 'bg-green-500' : status === 'warning' ? 'bg-yellow-500' : 'bg-red-500'}`} />
                      <div className="flex justify-between mb-3">
                        <div>
                          <div className="font-semibold">{c.name}</div>
                          <div className="text-xs text-gray-500">% Comisión {c.appliedPct.toFixed(2)}%</div>
                        </div>
                        <div className="text-right font-bold text-green-600">{moneyUSD(c.commissionUsd)}</div>
                      </div>

                      <div className="mb-3">
                        <div className="flex justify-between text-xs mb-1">
                          <span>Cumplimiento</span>
                          <span className="font-semibold">{c.pct.toFixed(1)}%</span>
                        </div>
                        <div className="w-full bg-gray-200 h-2 rounded">
                          <div className={`h-2 rounded ${status === 'success' ? 'bg-green-500' : status === 'warning' ? 'bg-yellow-500' : 'bg-red-500'}`} style={{ width: `${Math.min(100, c.pct)}%` }} />
                        </div>
                      </div>

                      <div className="text-sm grid grid-cols-2 gap-y-1">
                        <span className="text-gray-500">Ventas</span>
                        <span className="text-right">{moneyUSD(c.sales)}</span>
                        <span className="text-gray-500">PPTO</span>
                        <span className="text-right">{moneyUSD(c.ppto)}</span>
                        <span className="text-gray-500">Dif.</span>
                        <span className={`text-right font-medium ${c.diff >= 0 ? 'text-green-600' : 'text-red-600'}`}>{c.diff >= 0 ? '+' : ''}{moneyUSD(c.diff)}</span>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="bg-white rounded-2xl shadow overflow-x-auto mb-12">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-3 text-left">Categoría</th>
                      <th className="p-3 text-right">PPTO</th>
                      <th className="p-3 text-right">Ventas</th>
                      <th className="p-3 text-right">Dif.</th>
                      <th className="p-3 text-right">% Cumpl.</th>
                      <th className="p-3 text-right">% Comisión</th>
                      <th className="p-3 text-right">Comisión USD</th>
                    </tr>
                  </thead>
                  <tbody>
                    {categoryCards.map((c, idx) => (
                      <tr key={`cat-row-${c.code}-${idx}`} className="border-t">
                        <td className="p-3">{c.name}</td>
                        <td className="p-3 text-right">{moneyUSD(c.ppto)}</td>
                        <td className="p-3 text-right">{moneyUSD(c.sales)}</td>
                        <td className={`p-3 text-right ${c.diff >= 0 ? 'text-green-600' : 'text-red-600'}`}>{c.diff >= 0 ? '+' : ''}{moneyUSD(c.diff)}</td>
                        <td className="p-3 text-right">{c.pct.toFixed(1)}%</td>
                        <td className="p-3 text-right">{c.appliedPct.toFixed(2)}%</td>
                        <td className="p-3 text-right font-semibold text-green-600">{moneyUSD(c.commissionUsd)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* SALES FILTER */}
            <h3 className="text-lg font-semibold mb-3">Detalle de ventas</h3>

            <div className="flex flex-col md:flex-row gap-2 mb-4">
              <div className="flex gap-2 w-full md:w-auto">
                <select value={filterCat} onChange={e => setFilterCat(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                  <option value="ALL">Todas las categorías</option>
                  {categoryCards.map((c, idx) => (
                    <option key={`cat-opt-${c.code}-${idx}`} value={c.code}>{c.name}</option>
                  ))}
                </select>

                <select value={filterProvider} onChange={e => setFilterProvider(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                  <option value="ALL">Proveedor: Todos</option>
                  {providers.map((p, idx) => (
                    <option key={`prov-${p}-${idx}`} value={p}>{p}</option>
                  ))}
                </select>

                <select value={filterBrand} onChange={e => setFilterBrand(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                  <option value="ALL">Marca: Todas</option>
                  {brands.map((b, idx) => (
                    <option key={`brand-${b}-${idx}`} value={b}>{b}</option>
                  ))}
                </select>

                <select value={filterProduct} onChange={e => setFilterProduct(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                  <option value="ALL">Producto: Todos</option>
                  {productsList.map((p, idx) => (
                    <option key={`prod-${p}-${idx}`} value={p}>{p}</option>
                  ))}
                </select>
              </div>

              <div className="flex gap-2 w-full">
                <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Buscar producto o folio…" className="border rounded-lg px-3 py-2 text-sm flex-1" />

                {/* folios multi-select */}
                <select
                  multiple
                  value={filterFolios}
                  onChange={e => {
                    const opts = Array.from(e.target.selectedOptions).map(o => o.value);
                    setFilterFolios(opts);
                  }}
                  className="border rounded-lg px-3 py-2 text-sm w-44 bg-white"
                  title="Selecciona varios folios con Ctrl/Cmd+click"
                >
                  {foliosList.map((f, idx) => (
                    <option key={`folio-${f}-${idx}`} value={f}>{f}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* SALES TABLE */}
            <div className="bg-white rounded-2xl shadow overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-3 text-left">Fecha</th>
                    <th className="p-3 text-left">Proveedor</th>
                    <th className="p-3 text-left">Marca</th>
                    <th className="p-3 text-left">Producto</th>
                    <th className="p-3 text-right">USD</th>
                    <th className="p-3 text-right">Comisión</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredSales.map((s, idx) => (
                    <tr key={String((s as any).id ?? (s as any).rowKey ?? `${s.folio}-${s.sale_date}-${idx}`)} className="border-t hover:bg-gray-50">
                      <td className="p-3">{s.sale_date}</td>
                      <td className="p-3">{s.provider ?? '—'}</td>
                      <td className="p-3">{s.brand ?? '—'}</td>
                      <td className="p-3">{s.product || s.folio}</td>
                      <td className="p-3 text-right">{moneyUSD(s.value_usd)}</td>
                      <td className="p-3 text-right font-semibold">{moneyCOP(s.commission_amount || 0)}</td>

                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/* ================= KPI CARD ================= */
function KpiCard({ label, value, sub, icon }: { label: string; value: string; sub?: string; icon: string }) {
  return (
    <div className="bg-white rounded-2xl shadow border p-4">
      <div className="flex justify-between mb-1">
        <div className="text-xs text-gray-500">{label}</div>
        <div className="text-xl">{icon}</div>
      </div>
      <div className="text-xl font-bold">{value}</div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}
