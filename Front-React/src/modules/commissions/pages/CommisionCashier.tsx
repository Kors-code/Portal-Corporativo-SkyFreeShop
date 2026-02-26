import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ---------- Helpers ---------- */
function moneyUSD(v: any): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(v || 0));
}
function moneyCOP(v: any): string {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v || 0));
}
function getField(obj: any, ...keys: string[]): any {
  if (!obj) return undefined;
  for (const k of keys) {
    if (obj[k] !== undefined && obj[k] !== null) return obj[k];
  }
  return undefined;
}
function formatThousands(value: any): string {
  if (value === '' || value === null || value === undefined) return '';
  return Number(value).toLocaleString('en-US');
}
function unformatThousands(value: any): string {
  return String(value).replace(/,/g, '');
}

/* ---------- Types ---------- */
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
  metaUsd: number;
  period: any;
}
interface SaveMessage {
  type: 'error' | 'success';
  text: string;
}

/* ---------- Component ---------- */
export default function CommisionCashier() {
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState<ReportData | null>(null);
  const [view, setView] = useState<'table' | 'cards'>('table');
  const [selectedRow, setSelectedRow] = useState<any>(null);

  const [budgets, setBudgets] = useState<BudgetItem[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  const [budgetPrizeDraft, setBudgetPrizeDraft] = useState('');
  const [savingPrize, setSavingPrize] = useState(false);
  const [saveMessage, setSaveMessage] = useState<SaveMessage | null>(null);

  const [sortBy, setSortBy] = useState<'ventas' | 'premio' | 'pct'>('ventas');
  const [sortDir, setSortDir] = useState<'desc' | 'asc'>('desc'); // default mayor->menor

  /* Load budgets */
  useEffect(() => {
    let mounted = true;
    api.get('/budgets')
      .then(res => {
        if (!mounted) return;
        const list = res.data || [];
        setBudgets(list);
        if (list.length && !budgetId) {
          setBudgetId(list[0].id);
          const prizeVal = getField(list[0], 'cashier_prize', 'cashierPrize', 'prize_at_120', 'prizeAt120');
          setBudgetPrizeDraft(prizeVal ?? '');
        }
      })
      .catch(err => console.error('Error loading budgets', err));
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /* When budgetId changes */
  useEffect(() => {
    if (!budgetId) return;
    const b = budgets.find(bb => Number(bb.id) === Number(budgetId));
    const prizeVal = getField(b, 'cashier_prize', 'cashierPrize', 'prize_at_120', 'prizeAt120');
    setBudgetPrizeDraft(prizeVal ?? '');
    loadReport(budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId, budgets]);

  /* Load report */
  async function loadReport(bid: number): Promise<void> {
    setLoading(true);
    setReport(null);
    try {
      const res = await api.get('/reports/cashier-awards', { params: { budget_id: bid } });
      const d = res.data || {};
      const totalVentas = getField(d, 'total_ventas', 'totalVentas', 'total_ventas_usd', 'totalSalesUsd') ?? 0;
      const prizeAt120 = getField(d, 'prize_at_120', 'prizeAt120', 'prize_at_120', 'premio_base') ?? getField(d, 'premio_base');
      const prizeApplied = getField(d, 'prize_applied', 'prizeApplied', 'prize_aplicado', 'premio_aplicado') ?? 0;
      const cumplimiento = getField(d, 'cumplimiento', 'cumplimiento', 'cumpliment', 'compliance') ?? 0;
      const rows = Array.isArray(d.rows) ? d.rows : (Array.isArray(d.data) ? d.data : []);
      setReport({
        raw: d,
        rows,
        totalVentas: Number(totalVentas),
        prizeAt120: Number(prizeAt120),
        prizeApplied: Number(prizeApplied),
        cumplimiento: Number(cumplimiento),
        metaUsd: Number(d.meta_usd || d.metaUSD || d.meta || 0),
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

  /* Sorted rows */
  const sortedRows = useMemo(() => {
    const mult = sortDir === 'desc' ? 1 : -1;
    return [...rows].sort((a, b) => {
      const va = sortBy === 'ventas' ? Number(a.ventas_usd || 0) : sortBy === 'premio' ? Number(a.premiacion || 0) : Number(a.pct || 0);
      const vb = sortBy === 'ventas' ? Number(b.ventas_usd || 0) : sortBy === 'premio' ? Number(b.premiacion || 0) : Number(b.pct || 0);
      return (vb - va) * mult;
    });
  }, [rows, sortBy, sortDir]);

  const totals = useMemo(() => {
    if (!report) return { total_ventas: 0, premio_total: 0, cumplimiento: 0 };
    return {
      total_ventas: report.totalVentas || 0,
      premio_total: report.prizeApplied || report.prizeAt120 || 0,
      cumplimiento: report.cumplimiento || 0
    };
  }, [report]);

  /* Save prize */
  async function handleSavePrize(): Promise<void> {
    if (!budgetId) {
      setSaveMessage({ type: 'error', text: 'Selecciona un presupuesto primero.' });
      return;
    }
    const parsed = Number(String(budgetPrizeDraft).replace(/[^0-9.-]+/g, '')) || 0;
    setSavingPrize(true);
    setSaveMessage(null);
    try {
      await api.patch(`/budgets/${budgetId}/cashier-prize`, { cashier_prize: parsed });
      setBudgets(prev => prev.map(b => Number(b.id) === Number(budgetId) ? { ...b, cashier_prize: Math.round(parsed) } : b));
      await loadReport(budgetId);
      setSaveMessage({ type: 'success', text: 'Premio guardado.' });
    } catch (err) {
      console.error('Error saving prize', err);
      setSaveMessage({ type: 'error', text: 'No se pudo guardar el premio.' });
    } finally {
      setSavingPrize(false);
      setTimeout(() => setSaveMessage(null), 3500);
    }
  }

  /* Export */
  async function downloadExcel(): Promise<void> {
    if (!budgetId) {
      alert('Selecciona un presupuesto');
      return;
    }
    try {
      const res = await api.get('/reports/cashier-awards/export', { params: { budget_id: budgetId }, responseType: 'blob' });
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

  if (loading) return <div className="p-6"><div className="text-gray-500">Cargando…</div></div>;
  if (!report) return <div className="p-6"><div className="text-red-600 font-semibold">No se pudieron cargar los datos.</div></div>;

  /* ---------- Render ---------- */
  return (
    <div className="p-4 sm:p-6 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 mb-4">
        <div>
          <h2 className="text-lg sm:text-2xl font-bold text-red-700">CAJEROS — Comisiones</h2>
          <div className="text-sm text-gray-500 mt-1">Premiación por cajero — presupuesto seleccionado</div>
        </div>

        {/* Controls area: left group (budget + prize) & right group (export, sort, view) */}
        <div className="w-full lg:w-auto flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          {/* Left group */}
          <div className="flex items-center gap-3 bg-white rounded p-2 shadow-sm">
            <div className="flex flex-col">
              <label className="text-xs text-gray-500">Presupuesto</label>
              <select
                value={budgetId ?? ''}
                onChange={e => setBudgetId(Number(e.target.value))}
                className="border rounded px-2 py-1 text-sm bg-white min-w-[220px]"
                aria-label="Presupuesto"
              >
                {budgets.map(b => (
                  <option key={b.id} value={b.id}>
                    {b.name} — {b.start_date}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex flex-col">
              <label className="text-xs text-gray-500">Premio 120%</label>
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  inputMode="numeric"
                  value={formatThousands(budgetPrizeDraft)}
                  onChange={(e) => {
                    const raw = unformatThousands(e.target.value);
                    if (/^\d*$/.test(raw)) setBudgetPrizeDraft(raw);
                  }}
                  className="w-36 border rounded px-2 py-1 text-sm text-right"
                  placeholder="2,400,000"
                />
                <button
                  onClick={handleSavePrize}
                  disabled={savingPrize}
                  className={`px-2 py-1 rounded text-sm ${savingPrize ? 'bg-gray-300 text-gray-700' : 'bg-red-700 text-white'}`}
                  title="Guardar premio"
                >
                  {savingPrize ? 'Guardando' : 'Guardar'}
                </button>
              </div>
              {saveMessage && <div className={`text-xs mt-1 ${saveMessage.type === 'error' ? 'text-red-600' : 'text-green-600'}`}>{saveMessage.text}</div>}
            </div>
          </div>

          {/* Right group (separated visually) */}
          <div className="flex items-center gap-2 ml-auto">
            <div className="hidden sm:flex items-center gap-2 bg-white rounded p-2 shadow-sm">
              {/* Export */}
              <button onClick={downloadExcel} className="flex items-center gap-2 px-3 py-1 rounded bg-green-600 text-white text-sm hover:bg-green-700">
                {/* icon */}
                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v6h-2V4H5v12h6v2H4a1 1 0 01-1-1V3z" />
                  <path d="M9 7h2v6h3l-4 4-4-4h3V7z" />
                </svg>
                Exportar
              </button>

              {/* Sort select */}
              <div className="flex items-center gap-1 border rounded px-2 py-1">
                <select
                  value={sortBy}
                  onChange={(e) => setSortBy(e.target.value as any)}
                  className="text-sm bg-transparent outline-none"
                  aria-label="Ordenar por"
                >
                  <option value="ventas">Ventas USD</option>
                  <option value="premio">Premiación</option>
                  <option value="pct">% Participación</option>
                </select>
                <button
                  onClick={() => setSortDir(d => d === 'desc' ? 'asc' : 'desc')}
                  className="px-2 py-1 rounded text-xs border"
                  title="Invertir orden"
                >
                  {sortDir === 'desc' ? '▼' : '▲'}
                </button>
              </div>

              {/* View toggles */}
              <div className="flex items-center gap-1 border rounded px-2 py-1 bg-white">
                <button onClick={() => setView('table')} className={`px-2 py-1 rounded text-sm ${view === 'table' ? 'bg-red-700 text-white' : 'text-gray-700'}`}>Tabla</button>
                <button onClick={() => setView('cards')} className={`px-2 py-1 rounded text-sm ${view === 'cards' ? 'bg-red-700 text-white' : 'text-gray-700'}`}>Cards</button>
              </div>
            </div>

            {/* On very small screens show a compact menu */}
            <div className="sm:hidden">
              <div className="flex items-center gap-2">
                <button onClick={downloadExcel} className="px-2 py-1 rounded bg-green-600 text-white text-xs">Exportar</button>
                <button onClick={() => setSortDir(d => d === 'desc' ? 'asc' : 'desc')} className="px-2 py-1 rounded border text-xs">{sortDir === 'desc' ? '▼' : '▲'}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Summary small bar */}
      <div className="mb-3 flex flex-wrap gap-3 text-sm text-gray-600">
        <div>Meta: <b className="ml-1 text-black">{moneyUSD(report.metaUsd || 0)}</b></div>
        <div>Ventas: <b className="ml-1 text-black">{moneyUSD(totals.total_ventas)}</b></div>
        <div>Premio aplicado: <b className="ml-1 text-black">{moneyUSD(report.prizeApplied || 0)}</b></div>
        <div>Cumplimiento: <b className="ml-1 text-black">{totals.cumplimiento}%</b></div>
      </div>

      {/* Content */}
      {view === 'table' ? (
        <div className="bg-white rounded-lg shadow overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-red-700 text-white">
              <tr>
                <th className="p-3 text-left">Cajero</th>
                <th className="p-3 text-right">Ventas USD</th>
                <th className="p-3 text-right">% Participación</th>
                <th className="p-3 text-right">Total Premiación</th>
              </tr>
            </thead>
            <tbody>
              {sortedRows.map((r: any, i: number) => (
                <tr key={r.user_id ?? r.id ?? i} className="border-t hover:bg-slate-50 cursor-pointer" onClick={() => setSelectedRow(r)}>
                  <td className="p-3">{r.nombre}</td>
                  <td className="p-3 text-right text-green-700">{moneyUSD(r.ventas_usd)}</td>
                  <td className="p-3 text-right">{Number(r.pct || 0).toFixed(2)}%</td>
                  <td className="p-3 text-right font-semibold">{moneyUSD(r.premiacion)}</td>
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
            <article key={r.user_id ?? r.id ?? i} className="bg-white rounded-xl shadow p-4 hover:shadow-xl transform hover:-translate-y-1 transition cursor-pointer" onClick={() => setSelectedRow(r)}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="text-sm text-gray-500">Cajero</div>
                  <div className="font-semibold text-slate-800">{r.nombre}</div>
                </div>
                <div className="text-right">
                  <div className="text-xs text-gray-400">Premiación</div>
                  <div className="font-bold text-lg text-red-700">{moneyUSD(r.premiacion)}</div>
                  <div className="text-xs text-gray-500 mt-1">{Number(r.pct || 0).toFixed(2)}%</div>
                </div>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div className="text-gray-500">Ventas</div>
                <div className="text-right font-medium text-green-700">{moneyUSD(r.ventas_usd)}</div>
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

      {/* Modal */}
      {selectedRow && (
        <CashierCategoryModal selectedRow={selectedRow} budgetId={budgetId} onClose={() => setSelectedRow(null)} />
      )}
    </div>
  );
}

/* ---------- Modal ---------- */
interface CashierCategoryModalProps { selectedRow: any; budgetId: number | null; onClose: () => void; }
interface CategoryMeta { cashierName: string; totalUsd: number; tickets: number; totalCop?: number; }

function CashierCategoryModal({ selectedRow, budgetId, onClose }: CashierCategoryModalProps) {
  const [loading, setLoading] = useState(true);
  const [cats, setCats] = useState<any[]>([]);
  const [meta, setMeta] = useState<CategoryMeta>({ cashierName: selectedRow?.nombre || '—', totalUsd: 0, tickets: 0, totalCop: 0 });
  const [error, setError] = useState<string | null>(null);
  const cashierId = selectedRow?.user_id ?? selectedRow?.id ?? selectedRow?.uid ?? selectedRow?.user?.id ?? null;

  useEffect(() => {
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);

    async function load() {
      if (!cashierId) {
        setError('No se encontró identificador del cajero.');
        setLoading(false);
        return;
      }
      setLoading(true);
      setError(null);
      try {
        const res = await api.get(`/reports/cashier/${cashierId}/categories`, { params: { budget_id: budgetId } });
        const d = res.data || {};
        setCats(d.categories || []);
        setMeta({
          cashierName: d.cashier?.name ?? selectedRow.nombre ?? '—',
          totalUsd: d.summary?.total_sales_usd ?? 0,
          tickets: d.summary?.tickets_count ?? 0,
          totalCop: d.summary?.total_sales_cop ?? 0
        });
      } catch (e) {
        console.error('Error loading cashier categories', e);
        setError('Error cargando categorías.');
        setCats([]);
      } finally {
        setLoading(false);
      }
    }

    load();

    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
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
          {loading ? <div className="p-8 text-center text-gray-500">Cargando categorías…</div> :
            error ? <div className="p-6 text-center text-red-600">{error}</div> :
            cats.length === 0 ? <div className="p-6 text-center text-gray-500">No hay ventas por categoría.</div> :
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
                        <td className="p-2 text-right">{(Number(c.pct_of_total || c.pct || 0)).toFixed(2)}%</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50 font-semibold">
                    <tr>
                      <td className="p-2">Total</td>
                      <td className="p-2 text-right">{moneyUSD(meta.totalUsd)}</td>
                      <td className="p-2 text-right">{moneyCOP(meta.totalCop || 0)}</td>
                      <td className="p-2 text-right">100%</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
          }
        </div>

        <div className="p-4 border-t flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 rounded-md bg-gray-100 hover:bg-gray-200">Cerrar</button>
        </div>
      </div>
    </div>
  );
}