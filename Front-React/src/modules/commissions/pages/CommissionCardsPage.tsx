import { useEffect, useMemo, useState } from 'react';
import * as XLSX from 'xlsx';
import api from '../../../api/axios';
import CommissionDetailModal from '../components/CommissionDetailModal';

type TicketMetrics = {
  tickets_count: number;
  avg_ticket_usd?: number | null;
  avg_units_per_ticket?: number | null;
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
  target_usd?: number | null;
  pct_cumplimiento?: number | null;
  cumplimiento?: number | null;
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
  const [budgetInfo, setBudgetInfo] = useState<any>(null);
  const [categoriesSummaryGlobal, setCategoriesSummaryGlobal] = useState<CategoryRow[]>([]);
  const [rows, setRows] = useState<SellerRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [rectifyingRoles, setRectifyingRoles] = useState(false);
  const [generatingCommissions, setGeneratingCommissions] = useState(false);
  const [exportingCombined, setExportingCombined] = useState(false);
  const [exportingMonthly, setExportingMonthly] = useState(false);
  const [selectedSellerId, setSelectedSellerId] = useState<number | null>(null);
  const selectedSeller = useMemo(
    () => rows.find(r => Number(r.user_id) === Number(selectedSellerId)) ?? null,
    [rows, selectedSellerId]
  );


  // viewMode admite 'cards' | 'table' | 'tickets'
  const [viewMode, setViewMode] = useState<'cards' | 'table' | 'tickets'>('cards');
  const [sortBy, setSortBy] = useState<'sales_cop' | 'sales_usd' | 'commission_cop' | 'commission_usd' | 'compliance' | 'name'>('sales_cop');
  const [query, setQuery] = useState<string>('');

  // budgets & selection (now supports multiple)
  const [budgetIds, setBudgetIds] = useState<number[]>([]);
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetFilter, setBudgetFilter] = useState<string>(''); // simple text filter for sidebar
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');

  // Tickets summary global
  const [ticketsSummary, setTicketsSummary] = useState<any>(null);
  const avgUnitsPerTicket = ticketsSummary?.avg_units_per_ticket;
  const [turnsSummary, setTurnsSummary] = useState<any>(null);

  // helper: build query params for budget_ids[]
  const buildBudgetParams = (ids: number[]) => {
    const params = new URLSearchParams();
    ids.forEach(id => params.append('budget_ids[]', String(id)));
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
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
  }, [budgetIds, dateFrom, dateTo]);

  const load = async () => {
    setLoading(true);
    try {
      if (!budgetIds || budgetIds.length === 0) {
        // clear
        setRows([]);
        setBudgetProgress(null);
        setBudgetInfo(null);
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
        setBudgetInfo(res.data.budget ?? null);
      } else {
        setRows([]);
        setBudgetProgress(null);
        setBudgetInfo(null);
        setCategoriesSummaryGlobal([]);
        setTicketsSummary(null);
        setTurnsSummary(null);
      }
    } catch (e) {
      console.error('load commissions error', e);
      setRows([]);
      setBudgetProgress(null);
      setBudgetInfo(null);
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
    if (budgetIds.length !== 1) {
      alert('Para generar comisiones selecciona solo un presupuesto.');
      return;
    }

    const selectedBudget = budgets.find(b => Number(b.id) === Number(budgetIds[0]));
    if (selectedBudget?.is_closed) {
      alert('Ese presupuesto esta cerrado. No se pueden generar comisiones.');
      return;
    }
    if (!confirm(`¿Deseas generar/actualizar las comisiones para ${budgetIds.length} presupuesto(s) seleccionado(s)?`)) return;

    try {
      setGeneratingCommissions(true);
      const promises = [api.post(`commissions/generate?budget_id=${budgetIds[0]}`)];
      const results = await Promise.allSettled(promises);
      if (results[0]?.status === 'rejected') {
        throw results[0].reason;
      }
      const processed = Number(results[0]?.value.data?.users_processed ?? 0);
      alert(`Generacion completada. usuarios procesados: ${processed}`);
      await load();
      return;

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
    } finally {
      setGeneratingCommissions(false);
    }
  };

  const onRectifyRoles = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de rectificar roles.');
      return;
    }

    try {
      setRectifyingRoles(true);
      const preview = await api.post('commissions/rectify-sales-roles', {
        budget_ids: budgetIds,
        dry_run: true,
      });
      const previewData = preview.data ?? {};
      const apply = confirm(
        `Previsualizacion lista. Presupuestos: ${previewData.budgets_count ?? budgetIds.length}. Usuarios: ${previewData.users_count ?? 0}. Rangos: ${previewData.ranges_count ?? 0}.\n\nAplicar cambios ahora en la BD local?`
      );

      if (!apply) return;

      const res = await api.post('commissions/rectify-sales-roles', {
        budget_ids: budgetIds,
        apply: true,
      });
      const data = res.data ?? {};
      const backupKeys = Array.isArray(data.results)
        ? data.results.map((r: any) => r.backup_key).filter(Boolean)
        : [];
      alert(`Roles rectificados. Presupuestos: ${data.budgets_count ?? budgetIds.length}. Usuarios: ${data.users_count ?? 0}. Rangos: ${data.ranges_count ?? 0}. Insertados: ${data.inserted_rows ?? 0}. Fusionados: ${data.merged_rows ?? 0}. Backup filas: ${data.backup_rows ?? 0}.${backupKeys.length ? `\nBackups: ${backupKeys.join(', ')}` : ''}`);
      await load();
    } catch (err: any) {
      console.error(err);
      const message = err?.response?.data?.message ?? 'Error al rectificar roles';
      alert(message);
    } finally {
      setRectifyingRoles(false);
    }
  };
  const moneyUSD = (v:number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v || 0);
  const moneyCOP = (v:number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(v || 0);
  const pct = (v?: number | null) => `${Number(v ?? 0).toFixed(1)}%`;
  const sellerCompliance = (r: SellerRow) => Number(r.pct_cumplimiento ?? r.cumplimiento ?? 0);
  const complianceClasses = (value: number) => {
    if (value >= 100) return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    if (value >= 80) return 'border-amber-200 bg-amber-50 text-amber-700';
    return 'border-rose-200 bg-rose-50 text-rose-700';
  };

  const avgTicketUsd = ticketsSummary?.avg_ticket_usd;


  const totalUsd = (budgetProgress?.total_usd ?? categoriesSummaryGlobal.reduce((s:any,c:any)=> s + Number(c.sales_usd || 0), 0));
  const pptoUsd = budgetInfo?.target_amount ?? 0;
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
      case 'commission_usd': return list.sort((a,b) => (b.total_commission_usd||0) - (a.total_commission_usd||0));
      case 'compliance': return list.sort((a,b) => sellerCompliance(b) - sellerCompliance(a));
      case 'name': return list.sort((a,b) => (a.seller||'').localeCompare(b.seller||''));
      default: return list.sort((a,b) => (b.total_sales_cop||0) - (a.total_sales_cop||0));
    }
  }, [rows, sortBy, query]);

  const selectedBudgets = useMemo(
    () => budgets.filter(b => budgetIds.includes(Number(b.id))),
    [budgets, budgetIds]
  );

  const exportSellerRow = (r: SellerRow) => ({
    Vendedor: r.seller || '',
    Turnos: r.assignedTurns ?? 0,
    'Ventas USD': Number(r.total_sales_usd || 0),
    'Ventas COP': Number(r.total_sales_cop || 0),
    'Comision USD': Number(r.total_commission_usd || 0),
    'Comision COP': Number(r.total_commission_cop || 0),
    TRX: Number(r.tickets?.tickets_count || 0),
    'Unidad ticket': typeof r.tickets?.avg_units_per_ticket === 'number' ? r.tickets.avg_units_per_ticket : '',
    'Ticket promedio USD': typeof r.tickets?.avg_ticket_usd === 'number' ? r.tickets.avg_ticket_usd : '',
    'TRM promedio': Number(r.avg_trm || 0),
    'Cumplimiento %': sellerCompliance(r),
  });

  const safeSheetName = (name: string) => {
    const clean = name.replace(/[:\\/?*[\]]/g, ' ').replace(/\s+/g, ' ').trim();
    return (clean || 'Hoja').slice(0, 31);
  };

  const downloadTableAndKpisExcel = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de exportar.');
      return;
    }
    try {
      setExportingCombined(true);
      const workbook = XLSX.utils.book_new();
      const budgetNames = selectedBudgets.map(b => b.name).join(', ') || budgetIds.join(', ');
      const kpis = [
        { Indicador: 'Presupuestos', Valor: budgetNames },
        { Indicador: 'Asesores visibles', Valor: displayedRows.length },
        { Indicador: 'Turnos asignados', Valor: turnsSummary ? `${turnsSummary.assigned_total} / ${turnsSummary.total}` : '' },
        { Indicador: 'Turnos disponibles', Valor: turnsSummary?.remaining ?? '' },
        { Indicador: 'Ventas USD', Valor: Number(totalUsd || 0) },
        { Indicador: 'Presupuesto USD', Valor: Number(pptoUsd || 0) },
        { Indicador: 'Comisiones USD', Valor: Number(commissionUsd || 0) },
        { Indicador: 'Comisiones COP', Valor: Number(totalCommissionCop || 0) },
        { Indicador: 'Ticket promedio USD', Valor: Number(avgTicketUsd || 0) },
        { Indicador: 'Items por ticket', Valor: typeof avgUnitsPerTicket === 'number' ? avgUnitsPerTicket : '' },
        { Indicador: 'TRM', Valor: trm },
      ];

      XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(kpis), 'KPIs');
      XLSX.utils.book_append_sheet(workbook, XLSX.utils.json_to_sheet(displayedRows.map(exportSellerRow)), 'Tabla asesores');
      XLSX.writeFile(workbook, `tabla_kpis_asesores_${budgetIds.join('_')}.xlsx`);
    } catch (err) {
      console.error('Error exporting KPI Excel', err);
      alert('Error al descargar Excel');
    } finally {
      setExportingCombined(false);
    }
  };

  const downloadMonthlyAdvisorsExcel = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de exportar asesores.');
      return;
    }

    try {
      setExportingMonthly(true);
      const workbook = XLSX.utils.book_new();
      const budgetsToExport = selectedBudgets.length > 0
        ? selectedBudgets
        : budgetIds.map(id => ({ id, name: `Presupuesto ${id}` }));

      for (const [index, budget] of budgetsToExport.entries()) {
        const id = Number(budget.id);
        const q = buildBudgetParams([id]);
        const res = await api.get(`/commissions/by-seller?${q}`);
        const sellers = Array.isArray(res.data?.sellers) ? res.data.sellers as SellerRow[] : [];
        const sheetRows = sellers.map(exportSellerRow);
        const sheet = XLSX.utils.json_to_sheet(sheetRows);
        XLSX.utils.book_append_sheet(workbook, sheet, safeSheetName(`${index + 1} ${budget.name ?? id}`));
      }

      XLSX.writeFile(workbook, `asesores_por_presupuesto_${budgetIds.join('_')}.xlsx`);
    } catch (err) {
      console.error('Error exporting advisors by budget', err);
      alert('Error al exportar asesores por presupuesto');
    } finally {
      setExportingMonthly(false);
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

            <div className="mb-3 grid grid-cols-2 gap-2">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Desde</label>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={e => setDateFrom(e.target.value)}
                  className="w-full border rounded px-2 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Hasta</label>
                <input
                  type="date"
                  value={dateTo}
                  onChange={e => setDateTo(e.target.value)}
                  className="w-full border rounded px-2 py-2 text-sm"
                />
              </div>
              {(dateFrom || dateTo) && (
                <button
                  type="button"
                  onClick={() => { setDateFrom(''); setDateTo(''); }}
                  className="col-span-2 text-xs px-2 py-1 bg-gray-100 rounded"
                >
                  Limpiar fechas
                </button>
              )}
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
              <button
                onClick={onRectifyRoles}
                disabled={rectifyingRoles}
                className="w-full text-sm px-3 py-2 bg-amber-600 text-white rounded disabled:opacity-60"
              >
                {rectifyingRoles ? 'Rectificando roles...' : 'Rectificar roles'}
              </button>
              <button
                onClick={onGenerate}
                disabled={generatingCommissions}
                className="w-full text-sm px-3 py-2 bg-green-600 text-white rounded disabled:opacity-60"
              >
                {generatingCommissions ? 'Generando...' : 'Generar comisiones'}
              </button>
              <button
                onClick={downloadTableAndKpisExcel}
                disabled={exportingCombined}
                className="w-full text-sm px-3 py-2 bg-blue-600 text-white rounded disabled:opacity-60"
              >
                {exportingCombined ? 'Exportando...' : 'Exportar tabla + KPI'}
              </button>
              <button
                onClick={downloadMonthlyAdvisorsExcel}
                disabled={exportingMonthly}
                className="w-full text-sm px-3 py-2 bg-slate-800 text-white rounded disabled:opacity-60"
              >
                {exportingMonthly ? 'Preparando...' : 'Asesores por presupuesto'}
              </button>
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

            <div className="grid grid-cols-2 gap-2 mt-2">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Desde</label>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={e => setDateFrom(e.target.value)}
                  className="w-full border rounded px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Hasta</label>
                <input
                  type="date"
                  value={dateTo}
                  onChange={e => setDateTo(e.target.value)}
                  className="w-full border rounded px-3 py-2 text-sm"
                />
              </div>
            </div>
            {(dateFrom || dateTo) && (
              <button
                type="button"
                onClick={() => { setDateFrom(''); setDateTo(''); }}
                className="mt-2 w-full text-xs px-2 py-1 bg-gray-100 rounded"
              >
                Limpiar fechas
              </button>
            )}

            <div className="flex flex-wrap gap-2 mt-2">
              <button onClick={load} className="flex-1 min-w-[8rem] px-3 py-2 bg-indigo-600 text-white rounded">Cargar</button>
              <button
                onClick={onRectifyRoles}
                disabled={rectifyingRoles}
                className="flex-1 min-w-[8rem] px-3 py-2 bg-amber-600 text-white rounded disabled:opacity-60"
              >
                {rectifyingRoles ? 'Roles...' : 'Roles'}
              </button>
              <button
                onClick={onGenerate}
                disabled={generatingCommissions}
                className="flex-1 min-w-[8rem] px-3 py-2 bg-green-600 text-white rounded disabled:opacity-60"
              >
                {generatingCommissions ? 'Generando...' : 'Generar'}
              </button>
              <button
                onClick={downloadTableAndKpisExcel}
                disabled={exportingCombined}
                className="flex-1 min-w-[8rem] px-3 py-2 bg-blue-600 text-white rounded disabled:opacity-60"
              >
                {exportingCombined ? 'Excel...' : 'Tabla + KPI'}
              </button>
              <button
                onClick={downloadMonthlyAdvisorsExcel}
                disabled={exportingMonthly}
                className="flex-1 min-w-[8rem] px-3 py-2 bg-slate-800 text-white rounded disabled:opacity-60"
              >
                {exportingMonthly ? 'Mes...' : 'Mes a mes'}
              </button>
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
                  <option value="compliance">Cumplimiento mayor a menor</option>
                  <option value="sales_cop">Ventas COP mayor a menor</option>
                  <option value="sales_usd">Ventas USD mayor a menor</option>
                  <option value="commission_cop">Comisión COP mayor a menor</option>
                  <option value="commission_usd">Comisión USD mayor a menor</option>
                  <option value="name">Nombre</option>
                </select>

                <div className="flex items-center gap-2 ml-2">
                  <button onClick={() => setViewMode('cards')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='cards' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Cards</button>
                  <button onClick={() => setViewMode('table')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='table' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Tabla</button>
                  <button onClick={() => setViewMode('tickets')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='tickets' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>KPI´s</button>
                </div>
              </div>
            </div>
          </div>

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

                    <div className="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center text-sm">
                      <div className="bg-gray-50 rounded p-2">
                        <div className="text-xxs text-gray-400">Turnos</div>
                        <div className="font-medium">{r.assignedTurns ?? 0}</div>
                      </div>
                      <div className={`rounded border p-2 ${complianceClasses(sellerCompliance(r))}`}>
                        <div className="text-xxs opacity-70">Cumpl.</div>
                        <div className="font-semibold">{pct(sellerCompliance(r))}</div>
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
                    <div className="mt-3 flex justify-end">
                      <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${complianceClasses(sellerCompliance(r))}`}>
                        Cumplimiento {pct(sellerCompliance(r))}
                      </span>
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
                      <th className="p-2 text-right">Cumplimiento</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayedRows.map(r => (
                      <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => setSelectedSellerId(r.user_id)}>
                        <td className="p-2">{r.seller}</td>
                        <td className="p-2 text-right">{r.tickets?.tickets_count ?? 0}</td>
                        <td className="p-2 text-right font-medium">{typeof r.tickets?.avg_units_per_ticket === 'number' ? r.tickets!.avg_units_per_ticket!.toFixed(2) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.avg_ticket_usd === 'number' ? moneyUSD(r.tickets!.avg_ticket_usd || 0) : '—'}</td>
                        <td className="p-2 text-right">
                          <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${complianceClasses(sellerCompliance(r))}`}>
                            {pct(sellerCompliance(r))}
                          </span>
                        </td>
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
                    <div className="mt-3 flex justify-end">
                      <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${complianceClasses(sellerCompliance(r))}`}>
                        Cumplimiento {pct(sellerCompliance(r))}
                      </span>
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
                      <th className="p-2 text-right">Cumplimiento</th>
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
                        <td className="p-2 text-right">
                          <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold ${complianceClasses(sellerCompliance(r))}`}>
                            {pct(sellerCompliance(r))}
                          </span>
                        </td>
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
              dateFrom={dateFrom}
              dateTo={dateTo}
              summaryTotals={selectedSeller ? {
                total_sales_usd: selectedSeller.total_sales_usd,
                total_sales_cop: selectedSeller.total_sales_cop,
              } : undefined}
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

