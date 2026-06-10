import { useEffect, useMemo, useRef, useState } from "react";
import type React from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  ComposedChart,
  LabelList,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  Building2,
  CalendarDays,
  Download,
  Store,
  Target,
  TrendingUp,
} from "lucide-react";
import {
  getCashRegisterClosure,
  type CashClosureResponse,
} from "../services/visualizacionesService";

const usd = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 0,
});

const usd2 = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 2,
});

const num = new Intl.NumberFormat("es-CO", {
  maximumFractionDigits: 1,
});

const monthLabels: Record<string, string> = {
  January: "Enero",
  February: "Febrero",
  March: "Marzo",
  April: "Abril",
  May: "Mayo",
  June: "Junio",
  July: "Julio",
  August: "Agosto",
  September: "Septiembre",
  October: "Octubre",
  November: "Noviembre",
  December: "Diciembre",
};

const weekdayLabels: Record<string, string> = {
  Mon: "Lun",
  Tue: "Mar",
  Wed: "Mie",
  Thu: "Jue",
  Fri: "Vie",
  Sat: "Sab",
  Sun: "Dom",
};

const defaultPdvs = ["COLS1", "COLS2"];

function moneyClass(value: number) {
  return value < 0 ? "text-red-700" : "text-emerald-700";
}

function budgetMonthLabel(startDate: string) {
  const month = Number(startDate.slice(5, 7));
  const year = startDate.slice(0, 4);
  const names = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
  return `${names[month - 1] ?? startDate.slice(5, 7)} ${year}`;
}

const dayMs = 24 * 60 * 60 * 1000;

function dateToUtc(date: string) {
  const [year, month, day] = date.split("-").map(Number);
  return Date.UTC(year, month - 1, day);
}

function diffDays(start: string, end: string) {
  if (!start || !end) return 0;
  return Math.round((dateToUtc(end) - dateToUtc(start)) / dayMs);
}

function addDays(start: string, offset: number) {
  if (!start) return "";
  const date = new Date(dateToUtc(start) + offset * dayMs);
  return date.toISOString().slice(0, 10);
}

function daysInMonthFromDate(date: string) {
  if (!date) return 0;
  const [year, month] = date.split("-").map(Number);
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function progressColor(value: number) {
  if (value < 80) return "bg-red-600";
  if (value < 100) return "bg-blue-600";
  return "bg-emerald-600";
}

export default function CashClosureDashboard() {
  const [data, setData] = useState<CashClosureResponse | null>(null);
  const [selectedBudgetId, setSelectedBudgetId] = useState<number | "">("");
  const [selectedPdvs, setSelectedPdvs] = useState<string[]>([]);
  const [rangeStart, setRangeStart] = useState("");
  const [rangeEnd, setRangeEnd] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const suppressRangeEffect = useRef(false);

  const load = async (next?: { budgetId?: number | ""; pdvs?: string[]; startDate?: string; endDate?: string }) => {
    const budgetId = next?.budgetId ?? selectedBudgetId;
    const pdvs = next?.pdvs ?? selectedPdvs;
    const startDate = next?.startDate ?? rangeStart;
    const endDate = next?.endDate ?? rangeEnd;

    try {
      setLoading(true);
      setError("");
      const result = await getCashRegisterClosure({
        budget_id: budgetId,
        pdvs,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      });
      setData(result);
      setSelectedBudgetId(result.filters.budget_id ?? "");
      setSelectedPdvs(result.filters.pdvs ?? []);
      suppressRangeEffect.current = true;
      setRangeStart(result.budget.range.start);
      setRangeEnd(result.budget.range.end);
    } catch (err) {
      console.error(err);
      setError("No se pudo cargar la visualizacion.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setSelectedPdvs(defaultPdvs);
    void load({ budgetId: "", pdvs: defaultPdvs });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!rangeStart || !rangeEnd || !selectedBudgetId) return;

    if (suppressRangeEffect.current) {
      suppressRangeEffect.current = false;
      return;
    }

    const timer = window.setTimeout(() => {
      void load({ startDate: rangeStart, endDate: rangeEnd });
    }, 450);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rangeStart, rangeEnd]);

  const monthOptions = useMemo(() => data?.budgets.slice(0, 8) ?? [], [data?.budgets]);
  const dailyRows = data?.daily_performance ?? [];
  const daysWithSales = dailyRows.filter((item) => item.sales_usd > 0);
  const avgDaily = daysWithSales.length
    ? daysWithSales.reduce((sum, item) => sum + item.sales_usd, 0) / daysWithSales.length
    : 0;
  const totalUnits = dailyRows.reduce((sum, item) => sum + item.units, 0);
  const totalTrx = dailyRows.reduce((sum, item) => sum + item.trx, 0);
  const forecastMonthDays = daysInMonthFromDate(rangeStart || data?.budget.period.start || "");
  const projectedSales = avgDaily * forecastMonthDays;
  const avgTicket = totalTrx > 0 ? (data?.budget.month_sales_usd ?? 0) / totalTrx : 0;
  const avgTrx = daysWithSales.length ? totalTrx / daysWithSales.length : 0;
  const unitsPerTicket = totalTrx > 0 ? totalUnits / totalTrx : 0;
  const yearOptions = useMemo(
    () => Array.from(new Set((data?.budgets ?? []).map((budget) => budget.start_date.slice(0, 4)))),
    [data?.budgets]
  );
  const selectedBudget = data?.budgets.find((budget) => budget.id === selectedBudgetId);
  const selectedYear = selectedBudget?.start_date.slice(0, 4) ?? "";
  const selectedMonth = selectedBudget?.start_date.slice(5, 7) ?? "";
  const periodStart = data?.budget.period.start ?? "";
  const periodEnd = data?.budget.period.end ?? "";
  const availableStart = data?.available_period.start ?? periodStart;
  const availableEnd = data?.available_period.end ?? periodEnd;
  const timelineDays = Math.max(diffDays(availableStart, availableEnd), 0);
  const startOffset = rangeStart ? diffDays(availableStart, rangeStart) : 0;
  const endOffset = rangeEnd ? diffDays(availableStart, rangeEnd) : timelineDays;

  const dailyChart = dailyRows.map((item) => ({
    ...item,
    label: String(item.day),
    budget_80_usd: item.budget_daily_usd * 0.8,
  }));

  const categoryChart = (data?.categories ?? []).map((item) => ({
    name: item.category.length > 18 ? `${item.category.slice(0, 18)}...` : item.category,
    sales_usd: item.sales_usd,
  }));

  const togglePdv = (pdv: string) => {
    const next = selectedPdvs.includes(pdv)
      ? selectedPdvs.filter((item) => item !== pdv)
      : [...selectedPdvs, pdv];

    setSelectedPdvs(next);
    void load({ pdvs: next });
  };

  const clearStores = () => {
    const allPdvs = data?.pdvs ?? [];
    setSelectedPdvs(allPdvs);
    void load({ pdvs: allPdvs });
  };

  const changeBudget = (budgetId: number | "") => {
    setSelectedBudgetId(budgetId);
    setRangeStart("");
    setRangeEnd("");
    void load({ budgetId, startDate: "", endDate: "" });
  };

  const changeMonthYear = (year: string, month: string) => {
    const budget = data?.budgets.find(
      (item) => item.start_date.slice(0, 4) === year && item.start_date.slice(5, 7) === month
    );

    if (budget) {
      changeBudget(budget.id);
    }
  };

  const changeRangeStartOffset = (offset: number) => {
    const nextStart = addDays(availableStart, offset);
    if (!nextStart) return;
    const normalizedEnd = rangeEnd && nextStart > rangeEnd ? nextStart : rangeEnd;
    setRangeStart(nextStart);
    setRangeEnd(normalizedEnd);
  };

  const changeRangeEndOffset = (offset: number) => {
    const nextEnd = addDays(availableStart, offset);
    if (!nextEnd) return;
    const normalizedStart = rangeStart && nextEnd < rangeStart ? nextEnd : rangeStart;
    setRangeStart(normalizedStart);
    setRangeEnd(nextEnd);
  };

  const changeRangeInput = (side: "start" | "end", value: string) => {
    const nextStart = side === "start" ? value : rangeStart;
    const nextEnd = side === "end" ? value : rangeEnd;
    setRangeStart(nextStart);
    setRangeEnd(nextEnd);
  };

  const exportCsv = () => {
    if (!data) return;

    const rows = [
      ["year", "month", "day", "weekday", "sales_usd", "budget_daily_usd", "diff_usd", "project_pct", "units", "trx", "tkt_usd"],
      ...data.daily_performance.map((row) => [
        String(row.year),
        monthLabels[row.month] ?? row.month,
        String(row.day),
        weekdayLabels[row.weekday] ?? row.weekday,
        String(row.sales_usd),
        String(row.budget_daily_usd),
        String(row.diff_usd),
        String(row.compliance_pct),
        String(row.units),
        String(row.trx),
        String(row.tkt_usd),
      ]),
    ];

    const csv = rows.map((row) => row.map((cell) => `"${cell.replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `cierre-caja-${data.budget.period.start}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
  };

  return (
    <div className="space-y-5 text-slate-950">
      <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizaciones</p>
          <h1 className="mt-1 text-2xl font-black leading-tight text-slate-950">Cierre de caja diario</h1>
          <p className="mt-2 text-sm font-medium text-slate-500">
            Seguimiento por presupuesto, rango, tiendas, cumplimiento diario y exportacion CSV.
          </p>
        </div>
        <button
          onClick={exportCsv}
          disabled={!data}
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white disabled:opacity-50"
        >
          <Download size={16} />
          CSV
        </button>
      </div>

      <main className="space-y-5">
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="grid gap-4 lg:grid-cols-[minmax(240px,360px)_1fr]">
            <Field label="Presupuesto / periodo" icon={<CalendarDays size={15} />}>
              <select
                value={selectedBudgetId}
                onChange={(event) => changeBudget(event.target.value ? Number(event.target.value) : "")}
                className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900"
              >
                {(data?.budgets ?? []).map((budget) => (
                  <option key={budget.id} value={budget.id}>
                    {budget.name} - {usd.format(budget.target_amount)}
                  </option>
                ))}
              </select>
            </Field>

            <div>
              <div className="grid gap-3 sm:grid-cols-[120px_1fr]">
                <Field label="Año" icon={<Building2 size={15} />}>
                  <select
                    value={selectedYear}
                    onChange={(event) => changeMonthYear(event.target.value, selectedMonth)}
                    className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900"
                  >
                    {yearOptions.map((year) => (
                      <option key={year} value={year}>
                        {year}
                      </option>
                    ))}
                  </select>
                </Field>

                <div>
                  <div className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                    <CalendarDays size={15} />
                    Mes
                  </div>
                  <div className="flex gap-2 overflow-x-auto pb-1">
                    {monthOptions.map((budget) => {
                      const active = selectedBudgetId === budget.id;
                      return (
                        <button
                          key={budget.id}
                          onClick={() => changeBudget(budget.id)}
                          className={`shrink-0 rounded-lg border px-3 py-2 text-left text-xs font-bold transition ${
                            active
                              ? "border-primary bg-primary text-white"
                              : "border-slate-200 bg-slate-50 text-slate-700 hover:border-primary/40"
                          }`}
                        >
                          <span className="block">{budgetMonthLabel(budget.start_date)}</span>
                          <span className={active ? "text-white/80" : "text-slate-500"}>{usd.format(budget.target_amount)}</span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div className="mt-4 border-t border-slate-100 pt-4">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div className="text-xs font-black uppercase tracking-wide text-slate-500">
                Rango de fechas
              </div>
              <div className="text-sm font-black text-primary">
                {rangeStart || "-"} / {rangeEnd || "-"}
              </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <label>
                <span className="mb-1 block text-[11px] font-black uppercase text-slate-400">Desde</span>
                <input
                  type="date"
                  min={availableStart}
                  max={availableEnd}
                  value={rangeStart}
                  onChange={(event) => changeRangeInput("start", event.target.value)}
                  className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm font-bold text-slate-900"
                />
              </label>
              <label>
                <span className="mb-1 block text-[11px] font-black uppercase text-slate-400">Hasta</span>
                <input
                  type="date"
                  min={availableStart}
                  max={availableEnd}
                  value={rangeEnd}
                  onChange={(event) => changeRangeInput("end", event.target.value)}
                  className="h-10 w-full rounded-lg border border-slate-300 px-3 text-sm font-bold text-slate-900"
                />
              </label>
            </div>
            <div className="mt-4 rounded-lg bg-slate-50 p-3">
              <div className="mb-2 flex justify-between text-[11px] font-black uppercase text-slate-400">
                <span>Inicio {rangeStart || "-"}</span>
                <span>Fin {rangeEnd || "-"}</span>
              </div>
              <input
                type="range"
                min={0}
                max={timelineDays}
                value={startOffset}
                onChange={(event) => changeRangeStartOffset(Number(event.target.value))}
                className="w-full accent-[#840028]"
              />
              <input
                type="range"
                min={0}
                max={timelineDays}
                value={endOffset}
                onChange={(event) => changeRangeEndOffset(Number(event.target.value))}
                className="mt-1 w-full accent-slate-950"
              />
            </div>
            <div className="mt-2 flex justify-between text-[11px] font-bold text-slate-400">
              <span>{availableStart}</span>
              <span>{availableEnd}</span>
            </div>
          </div>

          <div className="mt-4 border-t border-slate-100 pt-4">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                <Store size={15} />
                Tiendas
              </div>
              {selectedPdvs.length > 0 && (
                <button onClick={clearStores} className="text-xs font-bold text-primary">
                  Seleccionar todas
                </button>
              )}
            </div>
            <div className="flex gap-2 overflow-x-auto pb-1">
              {(data?.pdvs ?? []).map((pdv) => {
                const active = selectedPdvs.includes(pdv);
                return (
                  <button
                    key={pdv}
                    onClick={() => togglePdv(pdv)}
                    className={`shrink-0 rounded-full border px-3 py-1.5 text-xs font-bold transition ${
                      active
                        ? "border-slate-950 bg-slate-950 text-white"
                        : "border-slate-200 bg-white text-slate-700 hover:border-primary"
                    }`}
                  >
                    {pdv}
                  </button>
                );
              })}
            </div>
          </div>

          {error && <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm font-bold text-red-700">{error}</div>}
          {!loading && data && data.budget.monthly_usd <= 0 && (
            <div className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">
              Este periodo no tiene presupuesto cargado. Budget daily y Project quedan en 0 hasta crear el presupuesto.
            </div>
          )}
        </section>

        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Kpi icon={<Target size={19} />} label="Budget daily" value={usd2.format(data?.budget.budget_daily_usd ?? 0)} />
          <Kpi
            icon={<TrendingUp size={19} />}
            label="Ventas reales rango"
            value={usd.format(data?.budget.month_sales_usd ?? 0)}
            detail={`${num.format(data?.budget.month_compliance_pct ?? 0)}% del esperado`}
          />
          <Kpi icon={<Store size={19} />} label="Diff budget" value={usd.format(data?.budget.month_diff_usd ?? 0)} valueClass={moneyClass(data?.budget.month_diff_usd ?? 0)} />
          <Kpi icon={<CalendarDays size={19} />} label="Promedio diario" value={usd.format(avgDaily)} detail={`${totalTrx} transacciones`} />
          <Kpi icon={<TrendingUp size={19} />} label="Sales forecast" value={usd.format(projectedSales)} detail={`Promedio diario por ${forecastMonthDays} dias del mes`} />
          <Kpi icon={<Target size={19} />} label="Ticket promedio" value={usd2.format(avgTicket)} detail="Ventas rango / transacciones" />
          <Kpi icon={<Store size={19} />} label="Transacciones promedio" value={num.format(avgTrx)} detail="Transacciones / dias vendidos" />
          <Kpi icon={<Target size={19} />} label="Unidades por ticket" value={num.format(unitsPerTicket)} detail="Unidades totales / tickets" />
        </section>

        <ProjectBar value={data?.budget.month_compliance_pct ?? 0} />

        <Card title="Detalle diario" subtitle="Project es el cumplimiento contra Budget daily">
          <div className="space-y-3 md:hidden">
            {dailyRows.map((row) => (
              <div key={row.date} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="text-xs font-black uppercase text-primary">
                      {weekdayLabels[row.weekday] ?? row.weekday} {row.day}
                    </div>
                    <div className="mt-1 text-lg font-black text-slate-950">{usd.format(row.sales_usd)}</div>
                  </div>
                  <div className="text-right">
                    <div className="text-xs font-bold text-slate-500">Project</div>
                    <div className="text-lg font-black text-slate-950">{num.format(row.compliance_pct)}%</div>
                  </div>
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                  <Mini label="Budget daily" value={usd2.format(row.budget_daily_usd)} />
                  <Mini label="Diff" value={usd.format(row.diff_usd)} className={moneyClass(row.diff_usd)} />
                  <Mini label="TRX" value={String(row.trx)} />
                  <Mini label="TKT" value={usd2.format(row.tkt_usd)} />
                </div>
              </div>
            ))}
            {!loading && data && (
              <div className="rounded-lg border-2 border-slate-900 bg-white p-3">
                <div className="text-xs font-black uppercase text-primary">Total periodo</div>
                <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                  <Mini label="Sales" value={usd.format(data.budget.month_sales_usd)} />
                  <Mini label="Budget" value={usd.format(data.budget.range_budget_usd)} />
                  <Mini label="Diff" value={usd.format(data.budget.month_diff_usd)} className={moneyClass(data.budget.month_diff_usd)} />
                  <Mini label="Project" value={`${num.format(data.budget.month_compliance_pct)}%`} />
                  <Mini label="Units" value={num.format(totalUnits)} />
                  <Mini label="TRX" value={String(totalTrx)} />
                </div>
              </div>
            )}
          </div>

          <div className="hidden overflow-x-auto md:block">
            <table className="w-full min-w-[900px] text-sm">
              <thead className="bg-slate-950 text-left text-xs uppercase tracking-wide text-white">
                <tr>
                  <th className="px-3 py-3">Dia</th>
                  <th className="px-3 py-3">Weekday</th>
                  <th className="px-3 py-3 text-right">Sales</th>
                  <th className="px-3 py-3 text-right">Budget daily</th>
                  <th className="px-3 py-3 text-right">Diff budget</th>
                  <th className="px-3 py-3 text-right">Project</th>
                  <th className="px-3 py-3 text-right">Units</th>
                  <th className="px-3 py-3 text-right">TRX</th>
                  <th className="px-3 py-3 text-right">TKT</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading && (
                  <tr>
                    <td colSpan={9} className="px-3 py-10 text-center font-bold text-slate-500">
                      Cargando...
                    </td>
                  </tr>
                )}
                {!loading &&
                  dailyRows.map((row) => (
                    <tr key={row.date} className="hover:bg-slate-50">
                      <td className="px-3 py-2 font-black text-slate-950">{row.day}</td>
                      <td className="px-3 py-2 text-slate-600">{weekdayLabels[row.weekday] ?? row.weekday}</td>
                      <td className="px-3 py-2 text-right font-bold">{usd.format(row.sales_usd)}</td>
                      <td className="px-3 py-2 text-right">{usd2.format(row.budget_daily_usd)}</td>
                      <td className={`px-3 py-2 text-right font-bold ${moneyClass(row.diff_usd)}`}>{usd.format(row.diff_usd)}</td>
                      <td className="px-3 py-2 text-right font-black">{num.format(row.compliance_pct)}%</td>
                      <td className="px-3 py-2 text-right">{num.format(row.units)}</td>
                      <td className="px-3 py-2 text-right">{row.trx}</td>
                      <td className="px-3 py-2 text-right">{usd2.format(row.tkt_usd)}</td>
                    </tr>
                  ))}
              </tbody>
              {!loading && data && (
                <tfoot className="border-t-2 border-slate-950 bg-slate-100 font-black">
                  <tr>
                    <td className="px-3 py-3" colSpan={2}>
                      Total
                    </td>
                    <td className="px-3 py-3 text-right">{usd.format(data.budget.month_sales_usd)}</td>
                    <td className="px-3 py-3 text-right">{usd.format(data.budget.range_budget_usd)}</td>
                    <td className={`px-3 py-3 text-right ${moneyClass(data.budget.month_diff_usd)}`}>{usd.format(data.budget.month_diff_usd)}</td>
                    <td className="px-3 py-3 text-right">{num.format(data.budget.month_compliance_pct)}%</td>
                    <td className="px-3 py-3 text-right">{num.format(totalUnits)}</td>
                    <td className="px-3 py-3 text-right">{totalTrx}</td>
                    <td className="px-3 py-3 text-right">{usd2.format(avgTicket)}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </Card>

        <section className="grid gap-4 xl:grid-cols-[1.6fr_.9fr]">
          <Card title="Cumplimiento diario" subtitle="Ventas vs Budget daily">
            <div className="h-72 sm:h-80">
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={dailyChart} margin={{ top: 24, right: 20, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip
                    formatter={(value, name) => [
                      usd2.format(Number(value)),
                      name === "budget_80_usd" ? "80% budget" : name === "budget_daily_usd" ? "100% budget" : name,
                    ]}
                  />
                  <Bar dataKey="sales_usd" name="Sales" fill="#0f766e" radius={[4, 4, 0, 0]}>
                    <LabelList
                      dataKey="compliance_pct"
                      position="top"
                      formatter={(value) => `${num.format(Number(value) || 0)}%`}
                      className="fill-slate-900 text-[11px] font-black"
                    />
                  </Bar>
                  <Line type="monotone" dataKey="budget_80_usd" name="80% budget" stroke="#facc15" strokeWidth={3} dot={false} />
                  <Line type="monotone" dataKey="budget_daily_usd" name="100% budget" stroke="#16a34a" strokeWidth={3} dot={false} />
                </ComposedChart>
              </ResponsiveContainer>
            </div>
          </Card>

          <Card title="Categorias" subtitle="Mix del periodo seleccionado">
            <div className="h-72 sm:h-80">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={categoryChart} layout="vertical" margin={{ left: 12, right: 72 }}>
                  <XAxis type="number" hide />
                  <YAxis type="category" dataKey="name" width={112} tick={{ fontSize: 11, fontWeight: 700 }} />
                  <Tooltip
                    formatter={(value) => usd2.format(Number(value))}
                    labelFormatter={(label) => `Categoria: ${label}`}
                  />
                  <Bar dataKey="sales_usd" fill="#2563eb" radius={[0, 4, 4, 0]}>
                    <LabelList
                      dataKey="sales_usd"
                      position="right"
                      formatter={(value) => usd.format(Number(value) || 0)}
                      className="fill-slate-900 text-[11px] font-black"
                    />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          </Card>
        </section>
      </main>
    </div>
  );
}

function Field({ label, icon, children }: { label: string; icon?: React.ReactNode; children: React.ReactNode }) {
  return (
    <label>
      <span className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
        {icon}
        {label}
      </span>
      {children}
    </label>
  );
}

function Kpi({
  icon,
  label,
  value,
  detail,
  valueClass = "text-slate-950",
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  detail?: string;
  valueClass?: string;
}) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
        {icon}
      </div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-2xl font-black ${valueClass}`}>{value}</div>
      {detail && <div className="mt-1 text-sm font-semibold text-slate-500">{detail}</div>}
    </div>
  );
}

function ProjectBar({ value }: { value: number }) {
  const normalizedProgress = Math.max(0, Math.min(value, 100));

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div>
          <div className="text-xs font-black uppercase tracking-wide text-slate-500">Project</div>
          <div className="mt-1 text-sm font-semibold text-slate-500">Cumplimiento proyectado contra presupuesto</div>
        </div>
        <div className="text-2xl font-black text-slate-950">{num.format(value)}%</div>
      </div>
      <div className="h-4 overflow-hidden rounded-full bg-slate-100">
        <div
          className={`h-full rounded-full transition-all ${progressColor(value)}`}
          style={{ width: `${normalizedProgress}%` }}
        />
      </div>
    </section>
  );
}

function Card({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-4">
        <h2 className="text-lg font-black text-slate-950">{title}</h2>
        {subtitle && <p className="text-sm font-medium text-slate-500">{subtitle}</p>}
      </div>
      {children}
    </section>
  );
}

function Mini({ label, value, className = "text-slate-900" }: { label: string; value: string; className?: string }) {
  return (
    <div className="rounded-md bg-white px-2 py-2">
      <div className="text-[11px] font-black uppercase text-slate-400">{label}</div>
      <div className={`font-black ${className}`}>{value}</div>
    </div>
  );
}
