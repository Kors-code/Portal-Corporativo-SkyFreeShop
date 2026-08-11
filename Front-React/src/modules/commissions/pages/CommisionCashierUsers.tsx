import { useEffect, useMemo, useState } from "react";
import {
  ArrowDownAZ,
  ArrowLeft,
  ArrowUpAZ,
  Award,
  BarChart3,
  ChevronRight,
  Download,
  Eye,
  Loader2,
  Medal,
  Search,
  Store,
  Trophy,
  Users,
  WalletCards,
  X,
} from "lucide-react";
import api from "../../../api/axios";

function moneyUSD(v: any): string {
  const val = Number(v ?? 0);
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(val);
}

function moneyCOP(v: any): string {
  const val = Number(v ?? 0);
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(val);
}

function getField(obj: any, ...keys: string[]): any {
  if (!obj) return undefined;
  for (const k of keys) {
    if (obj[k] !== undefined && obj[k] !== null) return obj[k];
  }
  return undefined;
}

function percent(v: any): string {
  return `${Number(v ?? 0).toFixed(2)}%`;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "CJ";
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
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

type SortDir = "asc" | "desc";
type ViewMode = "table" | "cards";

export default function CommisionCashierUsers() {
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState<ReportData | null>(null);
  const [view, setView] = useState<ViewMode>("table");
  const [selectedRow, setSelectedRow] = useState<any>(null);
  const [budgets, setBudgets] = useState<BudgetItem[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [sortDir, setSortDir] = useState<SortDir>("desc");
  const [search, setSearch] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let mounted = true;

    api
      .get("/budgets")
      .then((res) => {
        if (!mounted) return;
        const list = Array.isArray(res.data) ? res.data : [];
        setBudgets(list);
        if (list.length && !budgetId) setBudgetId(list[0].id);
      })
      .catch((err) => {
        console.error("Error loading budgets", err);
        if (mounted) setError("No se pudieron cargar los presupuestos.");
      });

    return () => {
      mounted = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!budgetId) return;
    loadReport(budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  async function loadReport(bid: number): Promise<void> {
    setLoading(true);
    setReport(null);
    setError(null);

    try {
      const res = await api.get("/reports/cashier-awards", { params: { budget_id: bid } });
      const d = res.data || {};

      const totalVentas = getField(d, "total_ventas", "totalVentas", "total_ventas_usd", "totalSalesUsd") ?? 0;
      const prizeAt120 = getField(d, "prize_at_120", "prizeAt120", "premio_base") ?? 0;
      const prizeApplied = getField(d, "prize_applied", "prizeApplied", "premio_aplicado") ?? 0;
      const cumplimiento = getField(d, "cumplimiento", "compliance") ?? 0;
      const rows = d.rows || d.data || [];

      setReport({
        raw: d,
        rows,
        totalVentas: Number(totalVentas),
        prizeAt120: Number(prizeAt120),
        prizeApplied: Number(prizeApplied),
        cumplimiento: Number(cumplimiento),
        period: d.period || d.periodo || null,
      });
    } catch (e) {
      console.error("Error loading awards", e);
      setReport(null);
      setError("No se pudieron cargar los datos de premiacion.");
    } finally {
      setLoading(false);
    }
  }

  const selectedBudget = useMemo(
    () => budgets.find((b) => Number(b.id) === Number(budgetId)) ?? null,
    [budgets, budgetId]
  );

  const rows = report?.rows || [];

  const sortedRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    const mapped = rows.map((r: any) => ({
      ...r,
      __name: String(r.nombre ?? r.name ?? "Sin nombre"),
      __ventas_usd: Number(getField(r, "ventas_usd", "ventasUSD", "sales_usd", "salesUsd") ?? 0),
      __pct: Number(getField(r, "pct", "participation_pct", "participacion", "participation") ?? 0),
      __premiacion: Number(getField(r, "premiacion", "premiation", "prize", "premio") ?? 0),
      __pdv: String(r.pdv ?? r.store ?? r.store_name ?? ""),
    }));

    return mapped
      .filter((r: any) => {
        if (!q) return true;
        return `${r.__name} ${r.__pdv}`.toLowerCase().includes(q);
      })
      .sort((a: any, b: any) => {
        if (a.__ventas_usd === b.__ventas_usd) return 0;
        const cmp = a.__ventas_usd > b.__ventas_usd ? 1 : -1;
        return sortDir === "asc" ? cmp : -cmp;
      });
  }, [rows, sortDir, search]);

  const totals = useMemo(() => {
    if (!report) return { total_ventas: 0, premio_total: 0, cumplimiento: 0 };
    return {
      total_ventas: report.totalVentas || 0,
      premio_total: report.prizeApplied || report.prizeAt120 || 0,
      cumplimiento: report.cumplimiento || 0,
    };
  }, [report]);

  const topCashier = sortedRows[0] ?? null;
  const averagePrize = sortedRows.length ? totals.premio_total / sortedRows.length : 0;
  const complianceWidth = Math.min(Math.max(totals.cumplimiento, 0), 120) / 1.2;

  function toggleSortVentas() {
    setSortDir((prev) => (prev === "desc" ? "asc" : "desc"));
  }

  async function downloadExcel(): Promise<void> {
    if (!budgetId) {
      alert("Selecciona un presupuesto");
      return;
    }

    try {
      const res = await api.get("/reports/cashier-awards/export", {
        params: { budget_id: budgetId },
        responseType: "blob",
      });

      const blob = new Blob([res.data], {
        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `cashier_awards_budget_${budgetId}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      console.error(e);
      alert("Error descargando Excel");
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 p-4 sm:p-6">
        <div className="mx-auto max-w-7xl space-y-5">
          <div className="h-44 animate-pulse rounded-2xl bg-white shadow-sm" />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-28 animate-pulse rounded-xl bg-white shadow-sm" />
            ))}
          </div>
          <div className="h-96 animate-pulse rounded-xl bg-white shadow-sm" />
        </div>
      </div>
    );
  }

  if (!report) {
    return (
      <div className="min-h-screen bg-slate-50 p-4 sm:p-6">
        <div className="mx-auto max-w-3xl rounded-xl border border-red-100 bg-white p-8 text-center shadow-sm">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700">
            <Award size={24} />
          </div>
          <h2 className="text-xl font-bold text-slate-900">No se pudieron cargar los datos</h2>
          <p className="mt-2 text-sm text-slate-500">{error ?? "Intenta cambiar el presupuesto o recargar la pagina."}</p>
        </div>
      </div>
    );
  }

  const ventasArrow = sortDir === "desc" ? <ArrowDownAZ size={16} /> : <ArrowUpAZ size={16} />;

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        <section className="overflow-hidden rounded-2xl bg-[#840028] text-white shadow-xl">
          <div className="grid gap-6 p-5 sm:p-7 lg:grid-cols-[1fr_360px]">
            <div className="flex min-w-0 flex-col justify-between gap-7">
              <div>
                <button
                  onClick={() => {
                    window.location.href = "/welcome";
                  }}
                  className="inline-flex items-center gap-2 rounded-lg bg-white/12 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                  title="Volver"
                >
                  <ArrowLeft size={17} />
                  Volver
                </button>

                <div className="mt-6 flex flex-wrap items-center gap-3">
                  <span className="inline-flex items-center gap-2 rounded-full bg-white/14 px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                    <Trophy size={14} />
                    Premiacion de cajeros
                  </span>
                  {selectedBudget && (
                    <span className="rounded-full bg-white/10 px-3 py-1 text-xs text-white/85">
                      {selectedBudget.start_date} a {selectedBudget.end_date}
                    </span>
                  )}
                </div>

                <h1 className="mt-4 max-w-3xl text-3xl font-bold leading-tight sm:text-4xl">
                  Resumen de premios por ventas
                </h1>
                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/78 sm:text-base">
                  Vista ejecutiva para revisar cumplimiento, participacion y premio estimado por cajero.
                </p>
              </div>

              <div className="grid gap-3 sm:grid-cols-3">
                <HeroMetric label="Ventas totales" value={moneyUSD(totals.total_ventas)} icon={<BarChart3 size={18} />} />
                <HeroMetric label="Premio aplicado" value={moneyUSD(totals.premio_total)} icon={<WalletCards size={18} />} />
                <HeroMetric label="Cajeros" value={String(rows.length)} icon={<Users size={18} />} />
              </div>
            </div>

            <aside className="rounded-xl bg-white p-4 text-slate-900 shadow-lg">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Cumplimiento</div>
                  <div className="mt-1 text-3xl font-bold text-slate-950">{percent(totals.cumplimiento)}</div>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-[#840028]">
                  <Medal size={24} />
                </div>
              </div>

              <div className="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                <div
                  className="h-full rounded-full bg-[#840028] transition-all"
                  style={{ width: `${complianceWidth}%` }}
                />
              </div>
              <div className="mt-2 flex justify-between text-[11px] font-semibold text-slate-400">
                <span>0%</span>
                <span>80%</span>
                <span>100%</span>
                <span>120%</span>
              </div>

              <div className="mt-5 rounded-lg border border-slate-100 bg-slate-50 p-3">
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Mayor venta</div>
                <div className="mt-2 flex items-center gap-3">
                  <Avatar name={topCashier?.__name ?? "Sin datos"} />
                  <div className="min-w-0">
                    <div className="truncate font-semibold text-slate-900">{topCashier?.__name ?? "Sin datos"}</div>
                    <div className="text-sm text-emerald-700">{moneyUSD(topCashier?.__ventas_usd ?? 0)}</div>
                  </div>
                </div>
              </div>
            </aside>
          </div>
        </section>

        <section className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard label="Ventas USD" value={moneyUSD(totals.total_ventas)} detail="Acumulado del periodo" icon={<BarChart3 size={20} />} />
          <SummaryCard label="Tope 120%" value={moneyUSD(report.prizeAt120 || 0)} detail="Premio maximo disponible" icon={<Trophy size={20} />} />
          <SummaryCard label="Promedio por cajero" value={moneyUSD(averagePrize)} detail="Segun premio aplicado" icon={<Users size={20} />} />
          <SummaryCard label="Premio aplicado" value={moneyUSD(report.prizeApplied || 0)} detail="Bolsa distribuida" icon={<Award size={20} />} />
        </section>

        <section className="mt-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div className="grid gap-3 sm:grid-cols-[minmax(220px,360px)_minmax(220px,1fr)]">
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Presupuesto</span>
                <select
                  value={budgetId ?? ""}
                  onChange={(e) => setBudgetId(Number(e.target.value))}
                  className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-[#840028] focus:ring-4 focus:ring-red-900/10"
                >
                  {budgets.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} - {b.start_date} a {b.end_date}
                    </option>
                  ))}
                </select>
              </label>

              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Buscar cajero o PDV</span>
                <div className="mt-1 flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 shadow-sm focus-within:border-[#840028] focus-within:ring-4 focus-within:ring-red-900/10">
                  <Search size={17} className="text-slate-400" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Nombre, punto de venta..."
                    className="h-10 w-full bg-transparent text-sm outline-none"
                  />
                </div>
              </label>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                <button
                  onClick={() => setView("table")}
                  className={`rounded-md px-3 py-2 text-sm font-semibold transition ${
                    view === "table" ? "bg-white text-[#840028] shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Tabla
                </button>
                <button
                  onClick={() => setView("cards")}
                  className={`rounded-md px-3 py-2 text-sm font-semibold transition ${
                    view === "cards" ? "bg-white text-[#840028] shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Tarjetas
                </button>
              </div>

              <button
                onClick={downloadExcel}
                className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                title="Exportar Excel"
              >
                <Download size={17} />
                Excel
              </button>
            </div>
          </div>
        </section>

        {sortedRows.length === 0 ? (
          <div className="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
              <Search size={22} />
            </div>
            <h2 className="mt-4 text-lg font-bold text-slate-900">No hay cajeros para mostrar</h2>
            <p className="mt-1 text-sm text-slate-500">Cambia el presupuesto o ajusta la busqueda.</p>
          </div>
        ) : view === "table" ? (
          <div className="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead className="bg-slate-900 text-white">
                  <tr>
                    <th className="p-3 text-left">Ranking</th>
                    <th className="p-3 text-left">Cajero</th>
                    <th className="cursor-pointer p-3 text-right" onClick={toggleSortVentas}>
                      <div className="flex items-center justify-end gap-2">
                        <span>Ventas USD</span>
                        {ventasArrow}
                      </div>
                    </th>
                    <th className="p-3 text-right">% Participacion</th>
                    <th className="p-3 text-right">Total premio</th>
                    <th className="p-3 text-right">Detalle</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedRows.map((r: any, i: number) => (
                    <tr
                      key={`${r.user_id ?? r.id ?? r.__name}-${i}`}
                      className="border-t border-slate-100 transition hover:bg-red-50/40"
                    >
                      <td className="p-3">
                        <RankBadge rank={i + 1} />
                      </td>
                      <td className="p-3">
                        <div className="flex items-center gap-3">
                          <Avatar name={r.__name} />
                          <div className="min-w-0">
                            <div className="truncate font-semibold text-slate-900">{r.__name}</div>
                            <div className="flex items-center gap-1 text-xs text-slate-500">
                              <Store size={13} />
                              <span>{r.__pdv || "Sin PDV"}</span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="p-3 text-right font-semibold text-emerald-700">{moneyUSD(r.__ventas_usd)}</td>
                      <td className="p-3 text-right text-slate-700">{percent(r.__pct)}</td>
                      <td className="p-3 text-right font-bold text-[#840028]">{moneyUSD(r.__premiacion)}</td>
                      <td className="p-3 text-right">
                        <button
                          onClick={() => setSelectedRow(r)}
                          className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-[#840028] hover:text-[#840028]"
                        >
                          <Eye size={14} />
                          Ver
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="bg-slate-50 font-bold text-slate-900">
                  <tr>
                    <td className="p-3" colSpan={2}>
                      Total
                    </td>
                    <td className="p-3 text-right">{moneyUSD(totals.total_ventas)}</td>
                    <td className="p-3 text-right">100%</td>
                    <td className="p-3 text-right">{moneyUSD(totals.premio_total)}</td>
                    <td className="p-3" />
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        ) : (
          <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {sortedRows.map((r: any, i: number) => (
              <article
                key={`${r.user_id ?? r.id ?? r.__name}-${i}`}
                className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <Avatar name={r.__name} />
                    <div className="min-w-0">
                      <div className="truncate font-bold text-slate-900">{r.__name}</div>
                      <div className="mt-1 flex items-center gap-1 text-xs text-slate-500">
                        <Store size={13} />
                        <span>{r.__pdv || "Sin PDV"}</span>
                      </div>
                    </div>
                  </div>
                  <RankBadge rank={i + 1} />
                </div>

                <div className="mt-4 grid grid-cols-2 gap-3">
                  <MiniStat label="Ventas" value={moneyUSD(r.__ventas_usd)} tone="green" />
                  <MiniStat label="Premio" value={moneyUSD(r.__premiacion)} tone="red" />
                  <MiniStat label="Participacion" value={percent(r.__pct)} />
                  <MiniStat label="Meta / tope" value={r.meta ? moneyUSD(r.meta) : "-"} />
                </div>

                <button
                  onClick={() => setSelectedRow(r)}
                  className="mt-4 flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-[#840028] hover:text-[#840028]"
                >
                  Ver ventas por categoria
                  <ChevronRight size={16} />
                </button>
              </article>
            ))}
          </div>
        )}

        {selectedRow && (
          <CashierCategoryModal selectedRow={selectedRow} budgetId={budgetId} onClose={() => setSelectedRow(null)} />
        )}
      </div>
    </div>
  );
}

function HeroMetric({ label, value, icon }: { label: string; value: string; icon: React.ReactNode }) {
  return (
    <div className="rounded-xl bg-white/12 p-3">
      <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-white/70">
        {icon}
        {label}
      </div>
      <div className="mt-2 truncate text-lg font-bold text-white">{value}</div>
    </div>
  );
}

function SummaryCard({ label, value, detail, icon }: { label: string; value: string; detail: string; icon: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</div>
          <div className="mt-2 truncate text-2xl font-bold text-slate-950">{value}</div>
          <div className="mt-1 text-sm text-slate-500">{detail}</div>
        </div>
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-50 text-[#840028]">{icon}</div>
      </div>
    </div>
  );
}

function Avatar({ name }: { name: string }) {
  return (
    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#840028] text-sm font-bold text-white">
      {initials(name)}
    </div>
  );
}

function RankBadge({ rank }: { rank: number }) {
  const styles =
    rank === 1
      ? "bg-amber-100 text-amber-800"
      : rank === 2
        ? "bg-slate-200 text-slate-700"
        : rank === 3
          ? "bg-orange-100 text-orange-800"
          : "bg-slate-100 text-slate-600";

  return <span className={`inline-flex min-w-10 justify-center rounded-full px-2.5 py-1 text-xs font-bold ${styles}`}>#{rank}</span>;
}

function MiniStat({ label, value, tone = "slate" }: { label: string; value: string; tone?: "slate" | "green" | "red" }) {
  const valueClass = tone === "green" ? "text-emerald-700" : tone === "red" ? "text-[#840028]" : "text-slate-900";
  return (
    <div className="rounded-lg bg-slate-50 p-3">
      <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-1 truncate text-sm font-bold ${valueClass}`}>{value}</div>
    </div>
  );
}

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
    cashierName: selectedRow?.__name || selectedRow?.nombre || "-",
    totalUsd: 0,
    tickets: 0,
    totalCop: 0,
  });
  const [error, setError] = useState<string | null>(null);

  const cashierId = selectedRow?.user_id ?? selectedRow?.id ?? selectedRow?.uid ?? selectedRow?.user?.id ?? null;

  useEffect(() => {
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);

    async function load() {
      if (!cashierId) {
        setError("No se encontro identificador del cajero en la fila seleccionada.");
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const res = await api.get(`/reports/cashier/${cashierId}/categories`, {
          params: { budget_id: budgetId },
        });
        const d = res.data || {};
        const totalCopVal = d.summary?.total_sales_cop ?? 0;

        setCats(d.categories || []);
        setMeta({
          cashierName: d.cashier?.name ?? selectedRow.__name ?? selectedRow.nombre ?? "-",
          totalUsd: d.summary?.total_sales_usd ?? 0,
          tickets: d.summary?.tickets_count ?? 0,
          totalCop: totalCopVal,
        });
      } catch (e) {
        console.error("Error loading cashier categories", e);
        setError("Error cargando categorias. Revisa la consola.");
        setCats([]);
      } finally {
        setLoading(false);
      }
    }

    load();

    return () => {
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = prevOverflow;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedRow, budgetId]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" onClick={onClose} />

      <div className="relative max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 p-5">
          <div className="flex min-w-0 items-center gap-3">
            <Avatar name={meta.cashierName} />
            <div className="min-w-0">
              <h3 className="truncate text-xl font-bold text-slate-950">{meta.cashierName}</h3>
              <div className="text-sm text-slate-500">Ventas por categoria del presupuesto seleccionado</div>
            </div>
          </div>

          <button
            onClick={onClose}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
            title="Cerrar"
          >
            <X size={20} />
          </button>
        </div>

        <div className="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-3">
          <MiniStat label="Ventas USD" value={moneyUSD(meta.totalUsd)} tone="green" />
          <MiniStat label="Ventas COP" value={moneyCOP(meta.totalCop ?? 0)} />
          <MiniStat label="Tickets" value={String(meta.tickets)} tone="red" />
        </div>

        <div className="max-h-[58vh] overflow-auto p-4">
          {loading ? (
            <div className="flex items-center justify-center gap-2 p-10 text-slate-500">
              <Loader2 className="animate-spin" size={20} />
              Cargando categorias...
            </div>
          ) : error ? (
            <div className="rounded-lg bg-red-50 p-6 text-center text-sm font-semibold text-red-700">{error}</div>
          ) : cats.length === 0 ? (
            <div className="rounded-lg bg-slate-50 p-8 text-center text-sm text-slate-500">
              No hay ventas por categoria para este cajero en el presupuesto seleccionado.
            </div>
          ) : (
            <div className="overflow-hidden rounded-xl border border-slate-200">
              <table className="w-full min-w-[620px] text-sm">
                <thead className="bg-slate-100 text-slate-700">
                  <tr>
                    <th className="p-3 text-left">Categoria</th>
                    <th className="p-3 text-right">Ventas USD</th>
                    <th className="p-3 text-right">Ventas COP</th>
                    <th className="p-3 text-right">% del total</th>
                  </tr>
                </thead>
                <tbody>
                  {cats.map((c: any, i: number) => (
                    <tr key={i} className="border-t border-slate-100 hover:bg-slate-50">
                      <td className="p-3 font-semibold text-slate-900">{c.classification || c.category || "Sin categoria"}</td>
                      <td className="p-3 text-right text-emerald-700">{moneyUSD(c.sales_usd)}</td>
                      <td className="p-3 text-right">{moneyCOP(c.sales_cop)}</td>
                      <td className="p-3 text-right">{percent(c.pct_of_total ?? c.pct)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="bg-slate-50 font-bold">
                  <tr>
                    <td className="p-3">Total</td>
                    <td className="p-3 text-right">{moneyUSD(meta.totalUsd)}</td>
                    <td className="p-3 text-right">{moneyCOP(meta.totalCop ?? 0)}</td>
                    <td className="p-3 text-right">100%</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
