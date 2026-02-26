import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

function moneyUSD(v: any): string {
  const val = Number(v ?? 0);
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val);
}
function moneyCOP(v: any): string {
  const val = Number(v ?? 0);
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(val);
}

function getField(obj: any, ...keys: string[]): any {
  if (!obj) return undefined;
  for (const k of keys) {
    if (obj[k] !== undefined && obj[k] !== null) return obj[k];
  }
  return undefined;
}

interface BudgetItem {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  cashier_prize?: number;
}

interface ReportData {
  raw: any;
  rows: any[];
  totalVentas: number;
  prizeAt120: number;
  prizeApplied: number;
  cumplimiento: number;
  period: any;
}

type SortDir = 'asc' | 'desc';

export default function CommisionCashierUsers() {
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState<ReportData | null>(null);
  const [view, setView] = useState<'table' | 'cards'>('table');
  const [selectedRow, setSelectedRow] = useState<any>(null);
  const [budgets, setBudgets] = useState<BudgetItem[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  // sorting: only by ventas_usd
  const [sortDir, setSortDir] = useState<SortDir>('desc'); // default: mayor a menor

  // cargar presupuestos
  useEffect(() => {
    let mounted = true;
    api.get('/budgets')
      .then(res => {
        if (!mounted) return;
        const list = res.data || [];
        setBudgets(list);
        if (list.length && !budgetId) {
          setBudgetId(list[0].id);
        }
      })
      .catch(err => console.error('Error loading budgets', err));
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!budgetId) return;
    loadReport(budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId, budgets]);

  async function loadReport(bid: number): Promise<void> {
    setLoading(true);
    setReport(null);
    try {
      const res = await api.get('/reports/cashier-awards', { params: { budget_id: bid } });
      const d = res.data || {};

      const totalVentas = getField(d, 'total_ventas', 'totalVentas', 'total_ventas_usd', 'totalSalesUsd') ?? 0;
      const prizeAt120 = getField(d, 'prize_at_120', 'prizeAt120', 'premio_base') ?? 0;
      const prizeApplied = getField(d, 'prize_applied', 'prizeApplied', 'premio_aplicado') ?? 0;
      const cumplimiento = getField(d, 'cumplimiento', 'compliance') ?? 0;
      const rows = d.rows || d.data || [];

      setReport({
        raw: d,
        rows,
        totalVentas: Number(totalVentas),
        prizeAt120: Number(prizeAt120),
        prizeApplied: Number(prizeApplied),
        cumplimiento: Number(cumplimiento),
        period: d.period || d.periodo || null
      });
    } catch (e) {
      console.error('Error loading awards', e);
      setReport(null);
    } finally {
      setLoading(false);
    }
  }

  const rows = report?.rows || [];

  // Sorted only by ventas_usd (asc/desc)
  const sortedRows = useMemo(() => {
    if (!rows || rows.length === 0) return [];

    const mapped = rows.map((r: any) => ({
      ...r,
      __ventas_usd: Number(getField(r, 'ventas_usd', 'ventasUSD', 'sales_usd', 'salesUsd') ?? r.ventas_usd ?? 0),
      __pct: Number(getField(r, 'pct', 'participation_pct', 'participacion', 'participation') ?? r.pct ?? 0),
      __premiacion: Number(getField(r, 'premiacion', 'premiation', 'prize', 'premio') ?? r.premiacion ?? r.premio ?? 0)
    }));

    const sorted = mapped.sort((a: any, b: any) => {
      const av = a.__ventas_usd;
      const bv = b.__ventas_usd;
      if (av === bv) return 0;
      const cmp = av > bv ? 1 : -1;
      return sortDir === 'asc' ? cmp : -cmp;
    });

    return sorted;
  }, [rows, sortDir]);

  const totals = useMemo(() => {
    if (!report) return { total_ventas: 0, premio_total: 0, cumplimiento: 0 };
    return {
      total_ventas: report.totalVentas || 0,
      premio_total: report.prizeApplied || report.prizeAt120 || 0,
      cumplimiento: report.cumplimiento || 0
    };
  }, [report]);

  function toggleSortVentas() {
    setSortDir(prev => (prev === 'desc' ? 'asc' : 'desc'));
  }

  async function downloadExcel(): Promise<void> {
    if (!budgetId) {
      alert('Selecciona un presupuesto');
      return;
    }

    try {
      const res = await api.get('/reports/cashier-awards/export', {
        params: { budget_id: budgetId },
        responseType: 'blob'
      });

      const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `cashier_awards_budget_${budgetId}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      console.error(e);
      alert('Error descargando Excel');
    }
  }

  if (loading) return (
    <div className="p-6">
      <div className="text-gray-500">Cargando…</div>
    </div>
  );

  if (!report) return (
    <div className="p-6">
      <div className="text-red-600 font-semibold">No se pudieron cargar los datos.</div>
    </div>
  );

  const ventasArrow = sortDir === 'desc' ? '▼' : '▲';

  return (
    <div className="p-4 sm:p-6 max-w-6xl mx-auto">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
          <button
            onClick={() => window.location.href = 'https://skyfreeshopdutyfree.com/welcome'}
            className="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-sm font-medium"
          >
            ← Volver
          </button>

          <h2 className="text-lg sm:text-2xl font-bold text-red-700 mt-3">
            CAJEROS — Comisiones
          </h2>
          <div className="text-sm text-gray-500 mt-1">Premiación por cajero — presupuesto seleccionado</div>
        </div>

        <div className="flex flex-col sm:flex-row gap-3 mb-4">
          <div>
            <label className="text-xs text-gray-500">Presupuesto</label>
            <select
              value={budgetId ?? ''}
              onChange={e => setBudgetId(Number(e.target.value))}
              className="w-full sm:w-72 border rounded px-3 py-2 text-sm bg-white"
            >
              {budgets.map(b => (
                <option key={b.id} value={b.id}>
                  {b.name} — {b.start_date} → {b.end_date}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <div className="hidden sm:flex gap-3">
            <div className="bg-white rounded-lg shadow p-3 text-sm">
              <div className="text-xxs text-gray-400">Ventas USD</div>
              <div className="font-semibold text-lg">{moneyUSD(totals.total_ventas)}</div>
            </div>

            <div className="bg-white rounded-lg rounded-lg shadow p-3 text-sm">
              <div className="text-xxs text-gray-400">Prize (tope 120%)</div>
              <div className="font-semibold text-lg">{moneyUSD(report.prizeAt120 || 0)}</div>
            </div>

            <div className="bg-white rounded-lg shadow p-3 text-sm">
              <div className="text-xxs text-gray-400">Premio aplicado</div>
              <div className="font-semibold text-lg">{moneyUSD(report.prizeApplied || 0)}</div>
            </div>

            <div className="bg-white rounded-lg shadow p-3 text-sm">
              <div className="text-xxs text-gray-400">Cumplimiento</div>
              <div className="font-semibold text-lg">{totals.cumplimiento}%</div>
            </div>
          </div>

          <div className="flex items-center gap-2 ml-2">
            <button
              onClick={() => setView('table')}
              className={`px-3 py-1 rounded text-sm ${view === 'table' ? 'bg-red-700 text-white' : 'bg-gray-100 text-gray-700'}`}
            >
              Tabla
            </button>
            <button
              onClick={() => setView('cards')}
              className={`px-3 py-1 rounded text-sm ${view === 'cards' ? 'bg-red-700 text-white' : 'bg-gray-100 text-gray-700'}`}
            >
              Cards
            </button>
          </div>
        </div>
      </div>

      <div className="mb-4">
        <button
          onClick={downloadExcel}
          className="px-3 py-1 rounded text-sm bg-green-600 text-white hover:bg-green-700"
        >
          Exportar Excel
        </button>
      </div>

      {view === 'table' ? (
        <div className="bg-white rounded-lg shadow overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-red-700 text-white">
              <tr>
                <th className="p-3 text-left">Cajero</th>
                <th className="p-3 text-right cursor-pointer" onClick={toggleSortVentas}>
                  <div className="flex items-center justify-end gap-2">
                    <span>Ventas USD</span>
                    <span className="text-xs">{ventasArrow}</span>
                  </div>
                </th>
                <th className="p-3 text-right">% Participación</th>
                <th className="p-3 text-right">Total Premiación</th>
              </tr>
            </thead>
            <tbody>
              {sortedRows.map((r: any, i: number) => (
                <tr
                  key={i}
                  className="border-t hover:bg-slate-50 cursor-pointer"
                  onClick={() => setSelectedRow(r)}
                >
                  <td className="p-3">{r.nombre ?? r.name ?? '—'}</td>
                  <td className="p-3 text-right text-green-700">{moneyUSD(r.__ventas_usd)}</td>
                  <td className="p-3 text-right">{Number(r.__pct ?? r.pct ?? 0).toFixed(2)}%</td>
                  <td className="p-3 text-right font-semibold">{moneyUSD(r.__premiacion)}</td>
                </tr>
              ))}
            </tbody>

            <tfoot className="bg-gray-50 font-semibold">
              <tr>
                <td className="p-3">Total</td>
                <td className="p-3 text-right">{moneyUSD(totals.total_ventas)}</td>
                <td className="p-3 text-right">100%</td>
                <td className="p-3 text-right">{moneyUSD(report.prizeApplied || report.prizeAt120 || 0)}</td>
              </tr>
            </tfoot>
          </table>

          <div className="p-4 text-right font-bold">Cumplimiento total: <span className="text-red-700">{totals.cumplimiento}%</span></div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {sortedRows.map((r: any, i: number) => (
            <article
              key={i}
              className="bg-white rounded-xl shadow p-4 hover:shadow-xl transform hover:-translate-y-1 transition cursor-pointer"
              onClick={() => setSelectedRow(r)}
            >
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="text-sm text-gray-500">Cajero</div>
                  <div className="font-semibold text-slate-800">{r.nombre ?? r.name ?? '—'}</div>
                </div>
                <div className="text-right">
                  <div className="text-xs text-gray-400">Premiación</div>
                  <div className="font-bold text-lg text-red-700">{moneyUSD(r.__premiacion)}</div>
                  <div className="text-xs text-gray-500 mt-1">{Number(r.__pct ?? r.pct ?? 0).toFixed(2)}%</div>
                </div>
              </div>

              <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div className="text-gray-500">Ventas</div>
                <div className="text-right font-medium text-green-700">{moneyUSD(r.__ventas_usd)}</div>

                <div className="text-gray-500">Meta / Tope</div>
                <div className="text-right">{r.meta ? moneyUSD(r.meta) : '—'}</div>

                <div className="text-gray-500">PDV</div>
                <div className="text-right">{r.pdv || '—'}</div>

                <div className="text-gray-500">Notas</div>
                <div className="text-right">{r.note || '—'}</div>
              </div>
            </article>
          ))}
        </div>
      )}

      {/* Modal: detalle por categoría */}
      {selectedRow && (
        <CashierCategoryModal
          selectedRow={selectedRow}
          budgetId={budgetId}
          onClose={() => setSelectedRow(null)}
        />
      )}
    </div>
  );
}

/* ================= Modal: ventas por categoría para un cajero ================= */
interface CashierCategoryModalProps {
  selectedRow: any;
  budgetId: number | null;
  onClose: () => void;
}

interface CategoryMeta {
  cashierName: string;
  totalUsd: number;
  tickets: number;
  totalCop?: number;
}

function CashierCategoryModal({ selectedRow, budgetId, onClose }: CashierCategoryModalProps) {
  const [loading, setLoading] = useState(true);
  const [cats, setCats] = useState<any[]>([]);
  const [meta, setMeta] = useState<CategoryMeta>({
    cashierName: selectedRow?.nombre || '—',
    totalUsd: 0,
    tickets: 0,
    totalCop: 0
  });
  const [error, setError] = useState<string | null>(null);

  // calcular posible id del cajero (defensivo)
  const cashierId = selectedRow?.user_id ?? selectedRow?.id ?? selectedRow?.uid ?? selectedRow?.user?.id ?? null;

  useEffect(() => {
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);

    async function load() {
      if (!cashierId) {
        setError('No se encontró identificador del cajero en la fila seleccionada.');
        setLoading(false);
        return;
      }
      setLoading(true);
      setError(null);
      try {
        const res = await api.get(`/reports/cashier/${cashierId}/categories`, {
          params: { budget_id: budgetId }
        });
        const d = res.data || {};
        setCats(d.categories || []);
        const totalCopVal = d.summary?.total_sales_cop ?? 0;
        setMeta({
          cashierName: d.cashier?.name ?? selectedRow.nombre ?? '—',
          totalUsd: d.summary?.total_sales_usd ?? 0,
          tickets: d.summary?.tickets_count ?? 0,
          totalCop: totalCopVal
        });
      } catch (e) {
        console.error('Error loading cashier categories', e);
        setError('Error cargando categorías. Revisa la consola.');
        setCats([]);
      } finally {
        setLoading(false);
      }
    }

    load();

    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedRow, budgetId]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />

      <div className="relative max-w-3xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div className="flex items-start justify-between p-6 border-b">
          <div>
            <h3 className="text-lg font-bold">{meta.cashierName}</h3>
            <div className="text-xs text-gray-500">Ventas por categoría (presupuesto seleccionado)</div>
          </div>
          <div className="text-sm text-gray-600 mr-4 space-y-1 text-right">
            <div>Ventas: <b>{moneyUSD(meta.totalUsd)}</b></div>
            <div className="font-semibold text-green-700">Tickets: {meta.tickets}</div>
          </div>
        </div>

        <div className="p-4">
          {loading ? (
            <div className="p-8 text-center text-gray-500">Cargando categorías…</div>
          ) : error ? (
            <div className="p-6 text-center text-red-600">{error}</div>
          ) : cats.length === 0 ? (
            <div className="p-6 text-center text-gray-500">No hay ventas por categoría para este cajero en el presupuesto seleccionado.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-2 text-left">Categoría</th>
                    <th className="p-2 text-right">Ventas USD</th>
                    <th className="p-2 text-right">Ventas COP</th>
                    <th className="p-2 text-right">% del total</th>
                  </tr>
                </thead>
                <tbody>
                  {cats.map((c: any, i: number) => (
                    <tr key={i} className="border-t hover:bg-slate-50">
                      <td className="p-2">{c.classification || c.category || 'Sin categoría'}</td>
                      <td className="p-2 text-right">{moneyUSD(c.sales_usd)}</td>
                      <td className="p-2 text-right">{moneyCOP(c.sales_cop)}</td>
                      <td className="p-2 text-right">{(Number(c.pct_of_total ?? c.pct ?? 0)).toFixed(2)}%</td>
                    </tr>
                  ))}
                </tbody>

                <tfoot className="bg-gray-50 font-semibold">
                  <tr>
                    <td className="p-2">Total</td>
                    <td className="p-2 text-right">{moneyUSD(meta.totalUsd)}</td>
                    <td className="p-2 text-right">{moneyCOP(meta.totalCop ?? 0)}</td>
                    <td className="p-2 text-right">100%</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </div>

        <div className="p-4 border-t flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 rounded-md bg-gray-100 hover:bg-gray-200">Cerrar</button>
        </div>
      </div>
    </div>
  );
}