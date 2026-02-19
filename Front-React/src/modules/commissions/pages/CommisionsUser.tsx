import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
type SaleRow = {
  id?: number | string;
  sale_date: string;
  folio: string;
  product: string;
  amount_cop: number;
  value_usd: number;
  exchange_rate?: number | null;
  provider?: string;
  brand?: string;
  commission_amount?: number | null;
  is_provisional?: boolean;
  category_code?: string;
  rowKey?: string;
};

type Category = {
  classification_code: string;
  category: string;
  sales_sum_usd: number;
  category_budget_usd_for_user: number;
  pct_user_of_category_budget: number | null;
  applied_commission_pct: number;
  commission_sum_usd: number | null;
};

type TicketItem = {
  folio: string;
  ticket_usd: number;
  ticket_cop: number;
  avg_units_per_ticket: number;
  lines_count: number;
  units_count: number;
  sale_date: string;
};

const MIN_PCT_TO_QUALIFY = 80; // umbral que pediste

export default function MyCommissionsPage() {
  const [loading, setLoading] = useState(true);

  // data returned from backend
  const [categories, setCategories] = useState<Category[]>([]);
  const [totals, setTotals] = useState<any>(null);
  const [budget, setBudget] = useState<any>(null);
  const [userName, setUserName] = useState('Mis comisiones');
  const [userBudgetUsd, setUserBudgetUsd] = useState<number>(0);

  // budgets selection
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  // sales + filters (new)
  const [sales, setSales] = useState<SaleRow[]>([]);
  const [filterCat, setFilterCat] = useState<string>('ALL');
  const [filterProvider, setFilterProvider] = useState<string>('ALL');
  const [filterBrand, setFilterBrand] = useState<string>('ALL');
  const [filterProduct, setFilterProduct] = useState<string>('ALL');
  const [filterFolios, setFilterFolios] = useState<string[]>([]);
  const [search, setSearch] = useState<string>('');
  const [categoryView, setCategoryView] = useState<'cards'|'table'>('cards');

  // días laborados (lista proveniente del backend)
  const [daysWorked, setDaysWorked] = useState<{ date: string; tickets_count: number; sales_usd:number; sales_cop:number; lines_count:number }[]>([]);
  const [filterDate, setFilterDate] = useState<string | null>(null);

  // tickets (desde backend)
  const [tickets, setTickets] = useState<TicketItem[]>([]);
  const [ticketsSummary, setTicketsSummary] = useState<any>(null);

  // UI mobile: mostrar todas las categorías o sólo la fila grande
  const [showAllCategoriesMobile, setShowAllCategoriesMobile] = useState(false);

  // const turnos Totales 
  const [turnosTotales, setTurnosTotales] = useState<number>(0);
console.log(turnosTotales)
  /* ================= EFFECTS ================= */
  useEffect(() => {
    // load budgets list
    api.get('/budgets')
      .then(res => {
        const data = res.data || [];
        setBudgets(data);
        if (data.length) setBudgetId(data[0].id);
      })
      .catch(() => {
        setBudgets([]);
      });
  }, []);

  useEffect(() => {
    if (budgetId) {
      load();
    } else {
      // reset
      setCategories([]);
      setSales([]);
      setTotals(null);
      setBudget(null);
      setUserBudgetUsd(0);
      setUserName('Mis comisiones');
      setTickets([]);
      setTicketsSummary(null);
      setDaysWorked([]);
      setFilterDate(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  const load = async () => {
    setLoading(true);
    try {
      const res = await api.get(`/commissions/my?budget_id=${budgetId}`);
      const d = res.data || {};

      // categories and totals (vienen del backend bySellerDetail)
      setCategories(d.categories || []);
      setTotals(d.totals || null);
      setBudget(d.budget || null);
      setUserBudgetUsd(Number(d.user_budget_usd || 0));
      setUserName(d.user?.name ?? d.seller_name ?? 'Mis comisiones');

      // tickets y resumen vienen directamente del backend
      setTickets(d.tickets || []);
      setTicketsSummary(d.tickets_summary || null);

      // turnos 
      setTurnosTotales(d.assigned_turns_for_user || 0);

      // días laborados
      setDaysWorked(d.days_worked || []);
      setFilterDate(null);

      // Sales: no recalcular comisión en frontend; usamos lo que venga del backend
      const computedSales: SaleRow[] = (d.sales || []).map((s: any, i: number) => {
        const rowKey = s.id ? String(s.id) : `${s.folio ?? 'nofolio'}-${s.sale_date ?? 'nodate'}-${String(s.product ?? '').slice(0,30)}-${i}`;
        return {
          ...s,
          id: s.id,
          commission_amount: s.commission_amount ?? null,
          is_provisional: Boolean(s.commission_amount),
          rowKey,
        } as SaleRow;
      });

      setSales(computedSales);

      // reset filters for fresh load
      setFilterCat('ALL');
      setFilterProvider('ALL');
      setFilterBrand('ALL');
      setFilterProduct('ALL');
      setFilterFolios([]);
      setSearch('');
      setShowAllCategoriesMobile(false); // reset UI state
    } catch (e) {
      console.error('Error cargando comisiones:', e);
      setCategories([]);
      setSales([]);
      setTotals(null);
      setBudget(null);
      setUserBudgetUsd(0);
      setUserName('Mis comisiones');
      setTickets([]);
      setTicketsSummary(null);
    } finally {
      setLoading(false);
    }
  };

  /* ================= HELPERS ================= */
  const moneyUSD = (v:number) =>
    new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v||0);

  const moneyCOP = (v:number) =>
    new Intl.NumberFormat('es-CO',{
      style:'currency',
      currency:'COP',
      maximumFractionDigits:0
    }).format(v||0);

  const totalSalesUsd = categories.reduce((s,c)=>s+Number(c.sales_sum_usd||0),0);

  const totalCommissionUsd =
    Number(totals?.total_commission_cop||0) / Number(totals?.avg_trm||1);

  const avgUnitsPerTicket = Number(
    ticketsSummary?.avg_units_per_ticket ??
    (tickets.length
      ? tickets.reduce((acc, t) => acc + Number(t.avg_units_per_ticket || 0), 0) / tickets.length
      : 0)
  );

  // cumplimiento del usuario respecto a su presupuesto
  const userPct = userBudgetUsd > 0 ? (totalSalesUsd / userBudgetUsd) * 100 : 0;
  const userPctRounded = Math.round((userPct + Number.EPSILON) * 100) / 100;
  const meetsBudget = userPct >= MIN_PCT_TO_QUALIFY;

  const categoryCards = useMemo(() => {
    return categories.map(c => {
      const sales = Number(c.sales_sum_usd||0);
      const ppto  = Number(c.category_budget_usd_for_user||0);
      return {
        code: c.classification_code,
        name: c.category ?? 'Sin categoría',
        sales,
        ppto,
        diff: sales - ppto,
        pct: Number(c.pct_user_of_category_budget||0),
        commissionUsd: Number(c.commission_sum_usd||0),
        appliedPct: Number(c.applied_commission_pct||0)
      };
    });
  }, [categories]);

  /* ================= filters derived lists ================= */
  const providers = useMemo(() => Array.from(new Set(sales.map(s => s.provider).filter(Boolean))), [sales]);
  const brands = useMemo(() => Array.from(new Set(sales.map(s => s.brand).filter(Boolean))), [sales]);
  const productsList = useMemo(() => Array.from(new Set(sales.map(s => s.product).filter(Boolean))), [sales]);

  /* ================= SALES FILTER ================= */
  const filteredSales = useMemo(() => {
    return sales.filter(s => {
      if (filterDate && String(s.sale_date ?? '') !== String(filterDate)) return false;
      if (filterCat !== 'ALL' && String(s.category_code ?? '') !== String(filterCat)) return false;
      if (filterProvider !== 'ALL' && String(s.provider ?? '') !== String(filterProvider)) return false;
      if (filterBrand !== 'ALL' && String(s.brand ?? '') !== String(filterBrand)) return false;
      if (filterProduct !== 'ALL' && String(filterProduct) !== String(s.product ?? '')) return false;
      if (filterFolios.length > 0 && !filterFolios.includes(String(s.folio ?? ''))) return false;

      if (!search) return true;
      return `${s.product} ${s.folio}`.toLowerCase().includes(search.toLowerCase());
    });
  }, [sales, filterCat, search, filterProvider, filterBrand, filterProduct, filterFolios, filterDate]);

  /* ================= EXPORT EXCEL ================= */
  async function downloadExcel() {
    if (!budgetId) {
      alert('Selecciona un presupuesto');
      return;
    }

    try {
      const res = await api.get(`/commissions/my/export?budget_id=${budgetId}`, {
        responseType: 'blob'
      });

      const blob = new Blob(
        [res.data],
        { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }
      );

      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      const safeName = String(userName || 'mis_comisiones').replace(/\s+/g, '_');
      a.download = `detalle_comisiones_${safeName}_budget_${budgetId}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      console.error(e);
      alert('Error descargando el Excel');
    }
  }

  const ticketsCount =
    Number(ticketsSummary?.tickets_count ?? (tickets.length || 0));

  // ================= NEW: Tickets por día laborado =================
  const diasLaboradosCount = daysWorked.length;

  const ticketsPorDia =
    diasLaboradosCount > 0
      ? (ticketsCount / diasLaboradosCount).toFixed(2)
      : '—';

  // Optional: dynamic badge color for daysWorked
  const badgeColor =
    diasLaboradosCount >= 20 ? 'text-green-600' :
    diasLaboradosCount >= 10 ? 'text-yellow-600' :
    'text-gray-700';

  /* ================= MOBILE SALES LIST (render helper) ================= */
  function MobileSalesList({ rows }: { rows: SaleRow[] }) {
    if (!rows.length) {
      return <div className="p-4 text-center text-gray-500">No hay ventas para mostrar</div>;
    }
    return (
      <div className="space-y-3">
        {rows.map((s, idx) => (
          <div key={String(s.id ?? s.rowKey ?? `${s.folio}-${s.sale_date}-${idx}`)} className="bg-white p-4 rounded-lg shadow">
            <div className="flex justify-between items-start gap-3">
              <div className="min-w-0">
                <div className="text-base font-semibold truncate">{s.product || s.folio}</div>
                <div className="text-sm text-gray-500 mt-0.5">{s.provider ?? '—'} · {s.brand ?? '—'}</div>
                <div className="text-xs text-gray-400 mt-1">{s.sale_date} · Folio: {s.folio}</div>
              </div>
              <div className="text-right">
                <div className="text-base font-bold">{moneyUSD(Number(s.value_usd || 0))}</div>
                <div className="text-sm text-gray-500">{s.commission_amount != null ? moneyCOP(Number(s.commission_amount)) : 'Calculada por categoría'}</div>
              </div>
            </div>
            <div className="mt-3 text-xs text-gray-500 flex gap-3">
              <div>{s.amount_cop ? moneyCOP(s.amount_cop) : null}</div>
              {s.is_provisional && <div className="px-2 py-0.5 text-xs bg-yellow-100 rounded text-yellow-800">Provisional</div>}
            </div>
          </div>
        ))}
      </div>
    );
  }

  /* ================= RENDER ================= */
  return (
    <div className="min-h-screen bg-slate-50 p-4 sm:p-6">
      <div className="w-full max-w-none mx-auto">

        {/* PRESUPUESTO */}
        <div className="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
          <div className="flex-1 w-full">
            <label className="text-xs text-gray-500 block mb-1">Presupuesto</label>
            <select
              value={budgetId ?? ''}
              onChange={e=>setBudgetId(Number(e.target.value))}
              className="w-full sm:w-72 border rounded px-3 py-3 text-sm bg-white"
            >
              {budgets.map(b=>(
                <option key={b.id} value={b.id}>
                  {b.name} — {b.start_date} → {b.end_date}
                </option>
              ))}
            </select>
          </div>

          <div className="flex items-center gap-3">
            <button
              onClick={() => window.history.back()}
              className="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-sm font-medium"
            >
              ← Volver
            </button>

            <button
              onClick={downloadExcel}
              className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-md"
            >
              📥 Descargar Excel
            </button>
          </div>
        </div>

        {/* HEADER + badge días laborados */}
        <div className="mb-6 flex items-center justify-between flex-wrap gap-3">
          <div>
            <div className="text-sm text-gray-500">Comisiones de</div>
            <h1 className="text-2xl sm:text-3xl font-bold leading-tight">{userName}</h1>
            {budget && (
              <div className="text-sm text-gray-400">
                Periodo {budget.start_date} → {budget.end_date}
              </div>
            )}
          </div>

          {/* Badge sutil con días laborados */}
          <div className="bg-white shadow-sm border rounded-xl px-4 py-2 text-sm flex items-center gap-3">
            <div className="text-xs text-gray-500">📅 Días laborados</div>
            <div className={`font-semibold ${badgeColor}`}>{diasLaboradosCount}</div>
            <div className="text-xs text-gray-400">·</div>
            <div className="text-xs text-gray-500">{ticketsCount} tickets</div>
            {/* Turnos asignados */}
            <div className="flex flex-col items-center">
              <span className="text-xs text-gray-400">Turnos</span>
              <span className="font-semibold text-indigo-600">{turnosTotales}</span>
            </div>
          </div>
        </div>

        {loading ? (
          <div className="p-10 text-center text-gray-400">Cargando información…</div>
        ) : (
          <>
            {/* KPIS: versión responsiva con DOS FILAS en sm+/md+ y scroll horizontal en XS */}
            <div className="mb-6">
              {/* MOBILE: horizontal scroll (visible en <sm) */}
              <div className="flex gap-4 overflow-x-auto pb-2 sm:hidden">
                <Kpi className="min-w-[220px] p-4" label="Ventas USD" value={moneyUSD(totalSalesUsd) } icon="💰"/>
                <Kpi className="min-w-[200px] p-4" label="PPTO USD" value={moneyUSD(userBudgetUsd)} icon="🎯"/>
                <KpiProgress className="min-w-[220px] p-4" label="Cumplimiento" pct={userPctRounded} minPct={MIN_PCT_TO_QUALIFY}/>
                <Kpi className="min-w-[220px] p-4" label="Comisión USD" value={meetsBudget ? moneyUSD(totalCommissionUsd) : '—'} icon="🏆" sub={!meetsBudget ? 'No cumple 80%' : undefined}/>
                <Kpi 
                  className="p-4" 
                  label="Tickets por Día" 
                  value={ticketsPorDia} 
                  sub={`${ticketsCount} tickets / ${diasLaboradosCount} días`} 
                  icon="📅"
                />
                <Kpi className="min-w-[160px] p-4" label="Tickets" value={String(ticketsSummary?.tickets_count ?? (tickets.length || 0))} icon="🧾"/>
                <Kpi className="min-w-[200px] p-4" label="Ticket Promedio" value={moneyUSD(Number(ticketsSummary?.avg_ticket_usd ?? 0))} icon="📊"/>
                <Kpi className="min-w-[180px] p-4" label="Unidades x Ticket" value={avgUnitsPerTicket.toFixed(2)} icon="📦"/>
              </div>

              {/* SM+: grid en DOS FILAS (4 columnas por fila en md, 2 columnas en sm) */}
              <div className="hidden sm:block">
                {/* Primera fila */}
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                  <Kpi className="p-4" label="Ventas USD" value={ moneyUSD(totalSalesUsd)} icon="💰"/>
                  <Kpi className="p-4" label="PPTO USD" value={moneyUSD(userBudgetUsd)} icon="🎯"/>
                  <KpiProgress className="p-4" label="Cumplimiento" pct={userPctRounded} minPct={MIN_PCT_TO_QUALIFY}/>
                  <Kpi className="p-4" label="Comisión USD" value={meetsBudget ? moneyUSD(totalCommissionUsd) : '—'} icon="🏆" sub={!meetsBudget ? 'No cumple 80%' : undefined}/>
                </div>

                {/* Segunda fila */}
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                  <Kpi 
                    className="p-4" 
                    label="Tickets por Día" 
                    value={ticketsPorDia} 
                    sub={`${ticketsCount} tickets / ${diasLaboradosCount} días`} 
                    icon="📅"
                  />
                  <Kpi className="p-4" label="Tickets" value={String(ticketsSummary?.tickets_count ?? (tickets.length || 0))} icon="🧾"/>
                  <Kpi className="p-4" label="Ticket Promedio" value={moneyUSD(Number(ticketsSummary?.avg_ticket_usd ?? 0))} icon="📊"/>
                  <Kpi className="p-4" label="Unidades x Ticket" value={avgUnitsPerTicket.toFixed(2)} icon="📦"/>
                </div>
              </div>
            </div>

            {/* TOGGLE CATEGORY VIEW */}
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 gap-3">
              <h3 className="text-lg font-semibold">Desempeño por categoría</h3>
              <div className="flex gap-2">
                <button
                  onClick={()=>setCategoryView('cards')}
                  className={`px-3 py-1 rounded text-sm ${categoryView==='cards'?'bg-indigo-600 text-white':'bg-gray-200'}`}
                >Cards</button>
                <button
                  onClick={()=>setCategoryView('table')}
                  className={`px-3 py-1 rounded text-sm ${categoryView==='table'?'bg-indigo-600 text-white':'bg-gray-200'}`}
                >Tabla</button>
              </div>
            </div>

            {/* CATEGORY VIEW (siempre visible aunque no cumpla) */}
            {categoryView === 'cards' ? (
              <>
                {/* ===== MOBILE: single row horizontal large cards ===== */}
                <div className="md:hidden mb-4">
                  {!showAllCategoriesMobile ? (
                    <>
                      <div className="flex gap-4 overflow-x-auto pb-3 px-1">
                        {categoryCards.slice(0, Math.max(3, Math.min(6, categoryCards.length))).map(c => {
                          const status = c.pct >= 100 ? 'success' : c.pct >= 80 ? 'warning' : 'danger';
                          return (
                            <div key={c.code} className="bg-white rounded-2xl shadow-lg border p-5 min-w-[300px] flex-shrink-0">
                              <div className={`h-1 w-full rounded-t-2xl mb-3 ${status==='success'?'bg-green-500':status==='warning'?'bg-yellow-500':'bg-red-500'}`} />
                              <div className="flex justify-between items-start gap-2 mb-2">
                                <div className="min-w-0">
                                  <div className="font-semibold text-lg truncate">{c.name}</div>
                                  <div className="text-xs text-gray-500 mt-1">% Comisión {c.appliedPct.toFixed(2)}%</div>
                                </div>
                                <div className="font-bold text-right text-xl">{moneyUSD(c.sales)}</div>
                              </div>

                              <div className="mb-3">
                                <div className="flex justify-between text-sm mb-1">
                                  <span>Cumplimiento</span>
                                  <span className="font-semibold">{c.pct.toFixed(1)}%</span>
                                </div>
                                <div className="w-full bg-gray-200 h-2 rounded">
                                  <div className={`h-2 rounded ${status==='success'?'bg-green-500':status==='warning'?'bg-yellow-500':'bg-red-500'}`} style={{width:`${Math.min(100,c.pct)}%`}} />
                                </div>
                              </div>

                              <div className="text-sm grid grid-cols-2 gap-y-1">
                                <span className="text-gray-500">Comisión</span>
                                <span className="text-right">{moneyUSD(c.commissionUsd)}</span>

                                <span className="text-gray-500">PPTO</span>
                                <span className="text-right">{moneyUSD(c.ppto)}</span>

                                <span className="text-gray-500">Dif.</span>
                                <span className={`text-right font-medium ${c.diff>=0?'text-green-600':'text-red-600'}`}>{c.diff>=0?'+':''}{moneyUSD(c.diff)}</span>
                              </div>
                            </div>
                          );
                        })}
                      </div>

                      {/* Mostrar todas button */}
                      {categoryCards.length >  (Math.max(3, Math.min(6, categoryCards.length))) && (
                        <div className="flex justify-center mt-3">
                          <button
                            onClick={() => setShowAllCategoriesMobile(true)}
                            className="px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold"
                          >
                            Mostrar todas
                          </button>
                        </div>
                      )}
                    </>
                  ) : (
                    /* Mostrar todas: lista vertical (mejor legibilidad) */
                    <>
                      <div className="space-y-4">
                        {categoryCards.map(c => {
                          const status = c.pct >= 100 ? 'success' : c.pct >= 80 ? 'warning' : 'danger';
                          return (
                            <div key={c.code} className="bg-white rounded-xl shadow p-4 border">
                              <div className="flex justify-between items-start gap-3 mb-2">
                                <div className="min-w-0">
                                  <div className="font-semibold">{c.name}</div>
                                  <div className="text-xs text-gray-500 mt-1">% Comisión {c.appliedPct.toFixed(2)}%</div>
                                </div>
                                <div className="font-bold text-right">{moneyUSD(c.sales)}</div>
                              </div>

                              <div className="flex items-center justify-between text-sm mb-2">
                                <div className="text-gray-500">Cumplimiento</div>
                                <div className="font-semibold">{c.pct.toFixed(1)}%</div>
                              </div>
                              <div className="w-full bg-gray-200 h-2 rounded mb-3">
                                <div className={`h-2 rounded ${status==='success'?'bg-green-500':status==='warning'?'bg-yellow-500':'bg-red-500'}`} style={{width:`${Math.min(100,c.pct)}%`}} />
                              </div>

                              <div className="text-sm grid grid-cols-2 gap-y-1">
                                <span className="text-gray-500">Comisión</span>
                                <span className="text-right">{moneyUSD(c.commissionUsd)}</span>

                                <span className="text-gray-500">PPTO</span>
                                <span className="text-right">{moneyUSD(c.ppto)}</span>

                                <span className="text-gray-500">Dif.</span>
                                <span className={`text-right font-medium ${c.diff>=0?'text-green-600':'text-red-600'}`}>{c.diff>=0?'+':''}{moneyUSD(c.diff)}</span>
                              </div>
                            </div>
                          );
                        })}
                      </div>

                      <div className="flex justify-center mt-4">
                        <button
                          onClick={() => setShowAllCategoriesMobile(false)}
                          className="px-4 py-2 rounded-lg bg-gray-200 font-semibold"
                        >
                          Mostrar menos
                        </button>
                      </div>
                    </>
                  )}
                </div>

                {/* ===== DESKTOP / MD+: grid normal (3 columnas) ===== */}
                <div className="hidden md:grid md:grid-cols-3 gap-4 mb-8">
                  {categoryCards.map(c=> {
                    const status = c.pct >= 100 ? 'success' : c.pct >= 80 ? 'warning' : 'danger';
                    return (
                      <div key={c.code} className="bg-white rounded-xl shadow border p-5 relative">
                        <div className={`absolute top-0 left-0 h-1 w-full rounded-t-2xl ${status==='success'?'bg-green-500':status==='warning'?'bg-yellow-500':'bg-red-500'}`} />
                        <div className="flex justify-between mb-3 items-start gap-2">
                          <div className="min-w-0">
                            <div className="font-semibold text-base truncate">{c.name}</div>
                            <div className="text-xs text-gray-500 mt-1">% Comisión {c.appliedPct.toFixed(2)}%</div>
                          </div>
                          <div className="font-bold text-right text-lg md:text-xl">{moneyUSD(c.sales)}</div>
                        </div>

                        <div className="mb-3">
                          <div className="flex justify-between text-xs mb-1">
                            <span>Cumplimiento</span>
                            <span className="font-semibold">{c.pct.toFixed(1)}%</span>
                          </div>
                          <div className="w-full bg-gray-200 h-2 rounded">
                            <div className={`h-2 rounded ${status==='success'?'bg-green-500':status==='warning'?'bg-yellow-500':'bg-red-500'}`} style={{width:`${Math.min(100,c.pct)}%`}} />
                          </div>
                        </div>

                        <div className="text-sm grid grid-cols-2 gap-y-1">
                          <span className="text-gray-500">Comisión</span>
                          <span className="text-right">{moneyUSD(c.commissionUsd)}</span>

                          <span className="text-gray-500">PPTO</span>
                          <span className="text-right">{moneyUSD(c.ppto)}</span>

                          <span className="text-gray-500">Dif.</span>
                          <span className={`text-right font-medium ${c.diff>=0?'text-green-600':'text-red-600'}`}>{c.diff>=0?'+':''}{moneyUSD(c.diff)}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </>
            ) : (
              <div className="bg-white rounded-xl shadow overflow-x-auto mb-8">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-3 text-left">Categoría</th>
                      <th className="p-3 text-right">PPTO</th>
                      <th className="p-3 text-right">Comisión USD</th>
                      <th className="p-3 text-right">Dif.</th>
                      <th className="p-3 text-right">% Cumpl.</th>
                      <th className="p-3 text-right">Ventas</th>
                    </tr>
                  </thead>
                  <tbody>
                    {categoryCards.map(c=>(
                      <tr key={c.code} className="border-t">
                        <td className="p-3">{c.name}</td>
                        <td className="p-3 text-right">{moneyUSD(c.ppto)}</td>
                        <td className="p-3 text-right font-semibold text-green-600">{moneyUSD(c.commissionUsd)}</td>
                        <td className="p-3 text-right">{moneyUSD(c.diff)}</td>
                        <td className="p-3 text-right">{c.pct.toFixed(1)}%</td>
                        <td className="p-3 text-right font-semibold">{moneyUSD(c.sales)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* --- Filters for sales --- */}
            <div>
              <div className="mb-4">
                <h3 className="text-lg font-semibold mb-2">Detalle de ventas</h3>

                <div className="flex flex-col md:flex-row gap-2 mb-3">
                  <div className="flex gap-2 w-full md:w-auto flex-wrap">
                    <select value={filterCat} onChange={e=>setFilterCat(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                      <option value="ALL">Todas las categorías</option>
                      {categoryCards.map((c, idx) => (
                        <option key={`cat-opt-${c.code}-${idx}`} value={c.code}>{c.name}</option>
                      ))}
                    </select>

                    <select value={filterProvider} onChange={e=>setFilterProvider(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                      <option value="ALL">Proveedor: Todos</option>
                      {providers.map((p, idx) => (<option key={`prov-${p}-${idx}`} value={p}>{p}</option>))}
                    </select>

                    <select value={filterBrand} onChange={e=>setFilterBrand(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                      <option value="ALL">Marca: Todas</option>
                      {brands.map((b, idx) => (<option key={`brand-${b}-${idx}`} value={b}>{b}</option>))}
                    </select>

                    <select value={filterProduct} onChange={e=>setFilterProduct(e.target.value)} className="border rounded-lg px-3 py-2 text-sm bg-white">
                      <option value="ALL">Producto: Todos</option>
                      {productsList.map((p, idx) => (<option key={`prod-${p}-${idx}`} value={p}>{p}</option>))}
                    </select>
                  </div>
                </div>
              </div>

              {/* --- Sales table --- */}
              {/* Table for md+ */}
              <div className="hidden md:block bg-white rounded-xl shadow overflow-x-auto mb-8">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-3 text-left">Fecha</th>
                      <th className="p-3 text-left">Proveedor</th>
                      <th className="p-3 text-left">Marca</th>
                      <th className="p-3 text-left">Producto</th>
                      <th className="p-3 text-right">Comisión</th>
                      <th className="p-3 text-right">USD</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredSales.map((s, idx) => (
                      <tr key={String(s.id ?? s.rowKey ?? `${s.folio}-${s.sale_date}-${idx}`)} className="border-t hover:bg-gray-50">
                        <td className="p-3">{s.sale_date}</td>
                        <td className="p-3">{s.provider ?? '—'}</td>
                        <td className="p-3">{s.brand ?? '—'}</td>
                        <td className="p-3">{s.product || s.folio}</td>
                        <td className="p-3 text-right font-semibold">
                          {s.commission_amount != null
                            ? moneyCOP(Number(s.commission_amount))
                            : <span className="text-xs text-gray-500 italic">Calculada por categoría</span>
                          }
                        </td>
                        <td className="p-3 text-right">{meetsBudget ? moneyUSD(Number(s.value_usd || 0)) : '—'}</td>
                      </tr>
                    ))}

                    {filteredSales.length === 0 && (
                      <tr>
                        <td className="p-6 text-center text-gray-500" colSpan={6}>No hay ventas para mostrar</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>

              {/* Mobile list for <md */}
              <div className="md:hidden mb-8">
                <MobileSalesList rows={filteredSales} />
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/* ================= KPI ================= */
function Kpi({ label, value, sub, icon, className }: { label: string; value: string; sub?: string; icon: string; className?: string }) {
  return (
    <div className={`bg-white rounded-2xl shadow-lg border p-4 flex-shrink-0 ${className ?? ''}`}>
      <div className="flex justify-between items-start gap-2">
        <div>
          <div className="text-sm text-gray-500">{label}</div>
          <div className="text-lg sm:text-xl font-bold leading-tight">{value}</div>
        </div>
        <div className="text-2xl">{icon}</div>
      </div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}

/* ================= KPI PROGRESS (nuevo) ================= */
function KpiProgress({ label, pct, minPct, className }: { label: string; pct: number; minPct: number; className?: string }) {
  const pctClamped = Math.max(0, Math.min(100, pct));
  let colorClass = 'bg-red-500';
  if (pctClamped >= 100) colorClass = 'bg-green-500';
  else if (pctClamped >= minPct) colorClass = 'bg-yellow-500';

  return (
    <div className={`bg-white rounded-2xl shadow-lg border p-4 flex-shrink-0 ${className ?? ''}`}>
      <div className="flex justify-between items-start gap-2 mb-3">
        <div>
          <div className="text-sm text-gray-500">{label}</div>
          <div className="text-lg sm:text-xl font-bold leading-tight">{pct.toFixed(2)}%</div>
        </div>
        <div className="text-2xl">📈</div>
      </div>

      <div className="w-full bg-gray-200 h-3 rounded overflow-hidden">
        <div className={`${colorClass} h-3`} style={{ width: `${pctClamped}%`, transition: 'width 400ms ease' }} />
      </div>

      <div className="mt-2 text-xs text-gray-500">
        <span>Umbral: {minPct}% — </span>
        <span>{pct < minPct ? 'No comisiona' : pct < 100 ? 'En progreso' : 'Objetivo alcanzado'}</span>
      </div>
    </div>
  );
}
