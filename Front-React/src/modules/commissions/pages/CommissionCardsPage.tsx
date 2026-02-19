import  { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';
import CommissionDetailModal from '../components/CommissionDetailModal';

type TicketMetrics = {
  tickets_count: number;
  avg_ticket_usd?: number | null;
  avg_units_per_ticket?: number | null;
  max_ticket_usd?: number | null;
};

type SellerRow = {
  user_id: number;
  seller: string;
  assignedTurns: number;
  total_commission_cop: number;
  total_commission_usd: number;
  total_sales_cop: number;
  total_sales_usd?: number | null;
  avg_trm: number;
  tickets?: TicketMetrics;
};

type CategoryRow = {
  classification: string;
  participation_pct?: number;
  category_budget_usd?: number;
  sales_usd?: number;
  sales_cop?: number;
  pct_of_category?: number | null;
  qualifies?: boolean;
  applied_commission_pct?: number;
  projected_commission_usd?: number | null;
  commission_cop?: number;
  commission_usd?: number | null;
  commission_sum_usd?: number | null;
};

function StatBox({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="min-w-[12rem] flex-shrink-0 bg-gray-900 text-white rounded-lg p-3 shadow-md">
      <div className="text-xxs text-gray-300">{label}</div>
      <div className="text-xl font-semibold">{value}</div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}

export default function CommissionCardsPage() {
  const [budgetProgress, setBudgetProgress] = useState<any>(null);
  const [categoriesSummaryGlobal, setCategoriesSummaryGlobal] = useState<CategoryRow[]>([]);
  const [rows, setRows] = useState<SellerRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedSellerId, setSelectedSellerId] = useState<number | null>(null);


  // viewMode admite 'cards' | 'table' | 'tickets'
  const [viewMode, setViewMode] = useState<'cards' | 'table' | 'tickets'>('cards');
  const [sortBy, setSortBy] = useState<'sales_cop' | 'sales_usd' | 'commission_cop' | 'name'>('sales_cop');
  const [query, setQuery] = useState<string>('');
  const [showCategories, setShowCategories] = useState<boolean>(false); // oculto por defecto

  // budgets & selection (now supports multiple)
  const [budgetIds, setBudgetIds] = useState<number[]>([]);
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetFilter, setBudgetFilter] = useState<string>(''); // simple text filter for sidebar

  // Tickets summary global
  const [ticketsSummary, setTicketsSummary] = useState<any>(null);
  const avgUnitsPerTicket = ticketsSummary?.avg_units_per_ticket;
  const [turnsSummary, setTurnsSummary] = useState<any>(null);

  // helper: build query params for budget_ids[]
  const buildBudgetParams = (ids: number[]) => {
    const params = new URLSearchParams();
    ids.forEach(id => params.append('budget_ids[]', String(id)));
    return params.toString();
  };

  // initial budgets load
  useEffect(() => {
    let mounted = true;
    api.get('/budgets')
      .then(res => {
        if (!mounted) return;
        const data = res.data || [];
        const arr = Array.isArray(data) ? data : [];
        setBudgets(arr);
        if (arr.length > 0 && budgetIds.length === 0) {
          setBudgetIds([arr[0].id]); // select first by default
        }
      })
      .catch(err => {
        console.error('Error loading budgets', err);
      });
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // auto-load when budgetIds changes
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetIds]);

  const load = async () => {
    setLoading(true);
    try {
      if (!budgetIds || budgetIds.length === 0) {
        // clear
        setRows([]);
        setBudgetProgress(null);
        setCategoriesSummaryGlobal([]);
        setTicketsSummary(null);
        setTurnsSummary(null);
        setLoading(false);
        return;
      }

      const q = buildBudgetParams(budgetIds);
      const url = `/commissions/by-seller?${q}`;
      const res = await api.get(url);

      if (res.data?.active) {
        const sellers = res.data.sellers ?? [];
        setRows(Array.isArray(sellers) ? sellers : []);
        setBudgetProgress(res.data.progress ?? {});
        setCategoriesSummaryGlobal(res.data.categories_summary ?? []);
        setTicketsSummary(res.data.tickets_summary ?? null);
        setTurnsSummary(res.data.turns ?? null);
      } else {
        setRows([]);
        setBudgetProgress(null);
        setCategoriesSummaryGlobal([]);
        setTicketsSummary(null);
      }
    } catch (e) {
      console.error('load commissions error', e);
      setRows([]);
      setBudgetProgress(null);
      setCategoriesSummaryGlobal([]);
      setTicketsSummary(null);
      setTurnsSummary(null);
    } finally {
      setLoading(false);
    }
  };

  const onGenerate = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de generar comisiones.');
      return;
    }
    if (!confirm(`¿Deseas generar/actualizar las comisiones para ${budgetIds.length} presupuesto(s) seleccionado(s)?`)) return;

    try {
      // For each selected budget call the generate endpoint (the backend expects single budget_id)
      const promises = budgetIds.map(id => api.post(`commissions/generate?budget_id=${id}`));
      const results = await Promise.allSettled(promises);

      // summarize results
      let created = 0, updated = 0, errors = 0;
      results.forEach(r => {
        if (r.status === 'fulfilled') {
          const d = r.value.data;
          if (d?.created) created += Number(d.created) || 0;
          if (d?.updated) updated += Number(d.updated) || 0;
        } else {
          errors++;
        }
      });

      alert(`Generación completada. creadas ${created} — actualizadas ${updated}${errors ? ` — errores en ${errors} partidas` : ''}`);
      await load();
    } catch (err) {
      console.error(err);
      alert('Error al generar comisiones');
    }
  };

  const moneyUSD = (v:number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v || 0);
  const moneyCOP = (v:number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(v || 0);

  const avgTicketUsd = ticketsSummary?.avg_ticket_usd;


  const totalUsd = (budgetProgress?.total_usd ?? categoriesSummaryGlobal.reduce((s:any,c:any)=> s + Number(c.sales_usd || 0), 0));
  const pptoUsd = budgetProgress?.required_usd ?? (budgetProgress?.budget?.target_amount ?? 0);
  const commissionUsd = budgetProgress?.total_commission_usd;

  const totalCommissionCop = (budgetProgress?.total_commission_cop ?? rows.reduce((s,r)=> s + Number(r.total_commission_cop || 0), 0));
  const trm = (() => {
    if (budgetProgress?.trm) return budgetProgress.trm;
    const totalCop = categoriesSummaryGlobal.reduce((s:any,c:any) => s + Number(c.sales_cop || 0), 0);
    const totalUsdCats = categoriesSummaryGlobal.reduce((s:any,c:any) => s + Number(c.sales_usd || 0), 0);
    if (totalUsdCats > 0) return (totalCop / totalUsdCats).toFixed(2);
    const avgTrm = rows.reduce((acc,r)=> acc + (Number(r.avg_trm || 0)), 0);
    if (rows.length > 0) return (avgTrm / rows.length).toFixed(2);
    return '—';
  })();

  

  // filtered & sorted rows
  const displayedRows = useMemo(() => {
    const q = query.trim().toLowerCase();
    let list = rows.slice();
    if (q) list = list.filter(r => (r.seller || '').toLowerCase().includes(q));
    switch (sortBy) {
      case 'sales_usd': return list.sort((a,b) => (b.total_sales_usd||0) - (a.total_sales_usd||0));
      case 'commission_cop': return list.sort((a,b) => (b.total_commission_cop||0) - (a.total_commission_cop||0));
      case 'name': return list.sort((a,b) => (a.seller||'').localeCompare(b.seller||''));
      default: return list.sort((a,b) => (b.total_sales_cop||0) - (a.total_sales_cop||0));
    }
  }, [rows, sortBy, query]);

  // Excel export (supports multiple budgets)
  const downloadExcel = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de exportar.');
      return;
    }
    try {
      const q = buildBudgetParams(budgetIds);
      const res = await api.get(`/commissions/export?${q}`, { responseType: 'blob' });
      const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `commissions_${budgetIds.join('_')}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error('Error downloading Excel', err);
      alert('Error al descargar Excel');
    }
  };

  // budget sidebar helpers
  const toggleBudget = (id: number) => {
    setBudgetIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  };
  const selectAll = () => setBudgetIds(budgets.map(b => b.id));
  const clearAll = () => setBudgetIds([]);
  const filteredBudgets = budgets.filter(b => {
    if (!budgetFilter) return true;
    const f = budgetFilter.toLowerCase();
    return String(b.name).toLowerCase().includes(f) ||
           String(b.start_date).includes(f) ||
           String(b.end_date).includes(f);
  });



  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto">
      <div className="flex gap-6">
        {/* LEFT SIDEBAR: budgets */}
        <aside className="w-72 hidden lg:block">
          <div className="bg-white rounded-lg shadow p-4 sticky top-6">
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-semibold">Presupuestos</h4>
              <div className="text-xs text-gray-400">{budgetIds.length} seleccionados</div>
            </div>

            <div className="mb-3">
              <input
                placeholder="Filtrar presupuestos (mes/año/nombre)"
                value={budgetFilter}
                onChange={e => setBudgetFilter(e.target.value)}
                className="w-full border rounded px-3 py-2 text-sm"
              />
            </div>

            <div className="flex gap-2 mb-3">
              <button onClick={selectAll} className="flex-1 text-xs px-2 py-1 bg-indigo-600 text-white rounded">Todos</button>
              <button onClick={clearAll} className="flex-1 text-xs px-2 py-1 bg-gray-100 rounded">Ninguno</button>
            </div>

            <div className="max-h-[48vh] overflow-auto -mx-2 px-2">
              {filteredBudgets.length === 0 ? (
                <div className="text-xs text-gray-500 p-2">No hay presupuestos</div>
              ) : (
                filteredBudgets.map(b => (
                  <label key={b.id} className="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={budgetIds.includes(b.id)}
                      onChange={() => toggleBudget(b.id)}
                      className="w-4 h-4"
                    />
                    <div className="text-sm">
                      <div className="font-medium">{b.name}</div>
                      <div className="text-xs text-gray-500">{b.start_date} → {b.end_date}</div>
                    </div>
                  </label>
                ))
              )}
            </div>

            <div className="mt-3 space-y-2">
              <button onClick={load} className="w-full text-sm px-3 py-2 bg-indigo-600 text-white rounded">Cargar selección</button>
              <button onClick={onGenerate} className="w-full text-sm px-3 py-2 bg-green-600 text-white rounded">Generar comisiones</button>
              <button onClick={downloadExcel} className="w-full text-sm px-3 py-2 bg-blue-600 text-white rounded">Exportar Excel</button>
            </div>
          </div>
        </aside>

        {/* MAIN */}
        <main className="flex-1">
          {/* Mobile compact header (shows select when sidebar is hidden) */}
          <div className="lg:hidden mb-4">
            <div className="flex items-center gap-2">
              <select
                multiple
                value={budgetIds.map(String)}
                onChange={(e) => {
                  const selected = Array.from(e.target.selectedOptions).map(o => Number(o.value));
                  setBudgetIds(selected);
                }}
                className="w-full border rounded px-3 py-2 text-sm h-36"
              >
                {budgets.map(b => (
                  <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>
                ))}
              </select>
            </div>

            <div className="flex gap-2 mt-2">
              <button onClick={load} className="flex-1 px-3 py-2 bg-indigo-600 text-white rounded">Cargar</button>
              <button onClick={onGenerate} className="flex-1 px-3 py-2 bg-green-600 text-white rounded">Generar</button>
              <button onClick={downloadExcel} className="flex-1 px-3 py-2 bg-blue-600 text-white rounded">Exportar</button>
            </div>
          </div>

          {/* STATS — horizontal scroll en móvil */}
          <div className="mb-4">
            <div className="flex gap-3 overflow-x-auto pb-2">
              {turnsSummary && (
                <StatBox
                  label="Turnos"
                  value={`${turnsSummary.assigned_total} / ${turnsSummary.total}`}
                  sub={`Disponibles: ${turnsSummary.remaining}`}
                />
              )}
              <StatBox label="Ticket promedio (USD)" value={avgTicketUsd ? moneyUSD(avgTicketUsd) : '—'} sub="Promedio por factura" />
              <StatBox label="Ítems por ticket" value={typeof avgUnitsPerTicket === 'number' ? avgUnitsPerTicket.toFixed(2) : '—'} sub="Unidades avg" />
              <StatBox label="Ventas USD" value={moneyUSD(Number(totalUsd || 0))} sub={`PPTO: ${moneyUSD(Number(pptoUsd || 0))}`} />
              <StatBox label="Comisiones USD" value={ typeof commissionUsd === 'number'? moneyUSD(commissionUsd): '—'} />
              
              <div className="min-w-[12rem] flex-shrink-0 bg-white rounded-lg p-3 shadow-md flex flex-col justify-between">
                <div>
                  <div className="text-xxs text-gray-500">Comisiones COP</div>
                  <div className="text-lg font-semibold text-green-600">{moneyCOP(Number(totalCommissionCop || 0))}</div>
                  <div className="text-xs text-gray-400 mt-1">TRM {trm ?? '—'}</div>
                </div>
              </div>
            </div>
          </div>

          {/* TOOLBAR: search, ordenar, view buttons (apilable en mobile) */}
          <div className="bg-white rounded-lg p-3 shadow mb-4">
            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
              <div className="flex items-center gap-2 w-full sm:w-1/2">
                <input
                  placeholder="Buscar vendedor..."
                  value={query}
                  onChange={e=>setQuery(e.target.value)}
                  className="w-full border rounded px-3 py-2 text-sm"
                />
              </div>

              <div className="flex items-center gap-2 sm:ml-auto">
                <label className="text-xs text-gray-500 hidden sm:block">Ordenar</label>
                <select value={sortBy} onChange={e => setSortBy(e.target.value as any)} className="border rounded px-3 py-2 text-sm">
                  <option value="sales_cop">Ventas (COP)</option>
                  <option value="sales_usd">Ventas (USD)</option>
                  <option value="commission_cop">Comisión (COP)</option>
                  <option value="name">Nombre</option>
                </select>

                <div className="flex items-center gap-2 ml-2">
                  <button onClick={() => setViewMode('cards')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='cards' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Cards</button>
                  <button onClick={() => setViewMode('table')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='table' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Tabla</button>
                  <button onClick={() => setViewMode('tickets')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='tickets' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>KPI´s</button>
                </div>

                <button onClick={() => setShowCategories(s => !s)} className="ml-2 px-3 py-2 bg-gray-50 rounded-md text-sm border">
                  {showCategories ? 'Ocultar categorías' : 'Mostrar categorías'}
                </button>
              </div>
            </div>
          </div>

          {/* CATEGORÍAS (oculto por defecto) */}
          {showCategories && (
            <div className="mb-6 bg-white rounded shadow overflow-x-auto">
              <div className="p-3 border-b">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-sm font-semibold">Totales por categoría</div>
                    <div className="text-xs text-gray-500">Resumen de ventas y comisiones por categoría</div>
                  </div>
                </div>
              </div>

              <table className="w-full text-sm">
                <thead className="bg-gray-100 text-gray-700">
                  <tr>
                    <th className="p-2 text-left">Categoría</th>
                    <th className="p-2 text-right">Participación %</th>
                    <th className="p-2 text-right">Presupuesto (USD)</th>
                    <th className="p-2 text-right">Ventas (USD)</th>
                    <th className="p-2 text-right">% de categoría</th>
                    <th className="p-2 text-right">Califica</th>
                    <th className="p-2 text-right">Comisión (USD)</th>
                    <th className="p-2 text-right">Comisión (COP)</th>
                  </tr>
                </thead>
                <tbody>
                  {categoriesSummaryGlobal.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="p-4 text-center text-gray-500">No hay datos de categorías</td>
                    </tr>
                  ) : categoriesSummaryGlobal.map((c, i) => (
                    <tr key={c.classification + i} className="border-t hover:bg-gray-50">
                      <td className="p-2">{(c.classification === 'fragancias' || String(c.classification ?? '').toLowerCase().includes('frag')) ? 'FRAGANCIAS' : (c.classification || 'Sin categoría')}</td>
                      <td className="p-2 text-right">{(c.participation_pct ?? 0).toFixed(2)}%</td>
                      <td className="p-2 text-right">{moneyUSD(Number(c.category_budget_usd ?? 0))}</td>
                      <td className="p-2 text-right">{Number(c.sales_usd ?? 0).toFixed(2)}</td>
                      <td className="p-2 text-right">{c.pct_of_category === null || typeof c.pct_of_category === 'undefined' ? '—' : `${Number(c.pct_of_category).toFixed(2)}%`}</td>
                      <td className="p-2 text-right">{c.qualifies ? 'Sí' : 'No'}</td>
                      <td className="p-2 text-right">{c.projected_commission_usd ? moneyUSD(Number(c.projected_commission_usd)) : (c.commission_usd ? moneyUSD(Number(c.commission_usd)) : '—')}</td>
                      <td className="p-2 text-right">{moneyCOP(Number(c.commission_cop ?? 0))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* CONTENT: Cards / Tickets / Table */}
          {loading ? (
            <div className="p-6 text-center text-gray-600">Cargando…</div>
          ) : displayedRows.length === 0 ? (
            <div className="p-6 text-center text-gray-600">No hay datos</div>
          ) : viewMode === 'cards' ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {displayedRows.map(r => (
                <div key={r.user_id} className="relative">
                  <div
                    onClick={() => setSelectedSellerId(r.user_id)}
                    className="text-left bg-white shadow-md rounded-lg p-4 hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200 cursor-pointer group"
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">
                          {r.seller ? r.seller.charAt(0).toUpperCase() : '?'}
                        </div>
                        <div>
                          <div className="text-xs text-gray-500">Vendedor</div>
                          <div className="text-lg font-semibold">{r.seller}</div>
                        </div>
                      </div>

                      <div className="text-right">
                        <div className="text-xs text-gray-500">Sales (USD)</div>
                        <div className="text-xl font-bold text-green-600">{Number(r.total_sales_usd || 0).toFixed(2)}</div>
                      </div>
                    </div>

                    <div className="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                      <div className="bg-gray-50 rounded p-2">
                        <div className="text-xxs text-gray-400">Turnos</div>
                        <div className="font-medium">{r.assignedTurns ?? 0}</div>
                      </div>
                      <div className="bg-gray-50 rounded p-2">
                        <div className="text-xxs text-gray-400">Comision COP</div>
                        <div className="font-medium">{moneyCOP(r.total_commission_cop ?? 0)} </div>
                      </div>
                      <div className="bg-gray-50 rounded p-2">
                        <div className="text-xxs text-gray-400">TRX</div>
                        <div className="font-medium">{r.tickets?.tickets_count ?? 0}</div>
                      </div>
                    </div>

                    <div className="mt-3 text-sm text-gray-500 flex items-center justify-between">
                      <div>TRM avg: {Number(r.avg_trm || 0).toFixed(2)}</div>
                      <div className="text-xs text-gray-400">Toca para ver opciones</div>
                    </div>
                  </div>

                  
                </div>
              ))}
            </div>
          ) : viewMode === 'tickets' ? (
            // Tickets: responsive list for móvil + table for md+
            <div className="space-y-3">
              {/* mobile list */}
              <div className="sm:hidden space-y-2">
                {displayedRows.map(r => (
                  <div key={r.user_id} onClick={() => setSelectedSellerId(r.user_id)} className="bg-white rounded-md p-3 shadow-sm cursor-pointer">
                    <div className="flex justify-between items-center">
                      <div className="font-medium">{r.seller}</div>
                      <div className="text-xs text-gray-500">{r.tickets?.tickets_count ?? 0} tickets</div>
                    </div>
                    <div className="mt-2 flex gap-3 text-sm text-gray-600">
                      <div>Ítems/ticket: {typeof r.tickets?.avg_units_per_ticket === 'number' ? r.tickets.avg_units_per_ticket.toFixed(2) : '—'}</div>
                      <div>Avg USD: {typeof r.tickets?.avg_ticket_usd === 'number' ? moneyUSD(r.tickets!.avg_ticket_usd || 0) : '—'}</div>
                    </div>
                  </div>
                ))}
              </div>

              {/* desktop table */}
              <div className="hidden sm:block bg-white rounded shadow overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-800 text-white">
                    <tr>
                      <th className="p-2 text-left">Vendedor</th>
                      <th className="p-2 text-right">TRX</th>
                      <th className="p-2 text-right">Unidad Ticket</th>
                      <th className="p-2 text-right">Avg Ticket (USD)</th>
                      <th className="p-2 text-right">Max (USD)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayedRows.map(r => (
                      <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => setSelectedSellerId(r.user_id)}>
                        <td className="p-2">{r.seller}</td>
                        <td className="p-2 text-right">{r.tickets?.tickets_count ?? 0}</td>
                        <td className="p-2 text-right font-medium">{typeof r.tickets?.avg_units_per_ticket === 'number' ? r.tickets!.avg_units_per_ticket!.toFixed(2) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.avg_ticket_usd === 'number' ? moneyUSD(r.tickets!.avg_ticket_usd || 0) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.max_ticket_usd === 'number' ? moneyUSD(r.tickets!.max_ticket_usd || 0) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            // Table view (sales/commission) - mobile friendly
            <div className="space-y-3">
              {/* mobile stacked rows */}
              <div className="sm:hidden space-y-2">
                {displayedRows.map(r => (
                  <div key={r.user_id} onClick={() => setSelectedSellerId(r.user_id)} className="bg-white rounded-md p-3 shadow-sm cursor-pointer">
                    <div className="flex justify-between items-start">
                      <div>
                        <div className="font-medium">{r.seller}</div>
                        <div className="text-xs text-gray-500">Turnos: {r.assignedTurns}</div>
                      </div>
                      <div className="text-right">
                        <div className="text-sm font-semibold text-green-600">{moneyCOP(r.total_commission_cop)}</div>
                        <div className="text-xs text-gray-500">{moneyCOP(r.total_sales_cop)} / {Number(r.total_sales_usd || 0).toFixed(2)} USD</div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {/* desktop table */}
              <div className="hidden sm:block bg-white rounded shadow overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-800 text-white">
                    <tr>
                      <th className="p-2 text-left">Vendedor</th>
                      <th className="p-2 text-right">Turnos</th>
                      <th className="p-2 text-right">Ventas (USD)</th>
                      <th className="p-2 text-right">Comisión (USD)</th>
                      <th className="p-2 text-right">Comisión (COP)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayedRows.map(r => (
                      <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => setSelectedSellerId(r.user_id)}>
                        <td className="p-2">{r.seller}</td>
                        <td className="p-2 text-right">{r.assignedTurns}</td>
                        <td className="p-2 text-right">{Number(r.total_sales_usd || 0).toFixed(2)}</td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyUSD(r.total_commission_usd)}</td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyCOP(r.total_commission_cop)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Modal: use first selected budget for seller detail (bySellerDetail currently expects single budget) */}
          {selectedSellerId && (
            budgetIds && budgetIds.length > 0 ? (
              <CommissionDetailModal
              userId={selectedSellerId}
              budgetIds={budgetIds} 
              onClose={() => { setSelectedSellerId(null); load(); }}
            />

            ) : (
              <div className="fixed bottom-6 right-6 z-50 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-900 p-3 rounded shadow">
                <div className="text-sm">Selecciona primero un presupuesto para ver detalle.</div>
                <div className="text-xs text-gray-500">(Selecciona uno en la barra izquierda o en móvil)</div>
              </div>
            )
          )}
        </main>
      </div>
    </div>
  );
}

/* small animation utility (Tailwind doesn't include animate-fade-in by default; if you have your tailwind config add it) */

