import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  BarChart3,
  BrainCircuit,
  CalendarDays,
  CheckCircle2,
  Cloud,
  FileUp,
  PlaneLanding,
  PlaneTakeoff,
  RefreshCw,
  Table2,
  Users,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { hasPermission } from "../../../auth/auth";
import {
  generatePassengerForecast,
  getPassengerBatches,
  getPassengerExternalSignalImpact,
  getPassengerExternalSignals,
  getPassengerForecasts,
  getPassengerMonthlyEstimates,
  getPassengerMonthlyFacts,
  getPassengerSourceFiles,
  getPassengerSummary,
  importPassengerExcel,
  importPassengerMigrationMicrodata,
  importPassengerOneDriveFile,
  recalculatePassengerAll,
  syncPassengerExternalSignals,
  syncPassengerOneDriveFiles,
  type PassengerBatch,
  type PassengerExternalSignal,
  type PassengerExternalSignalImpact,
  type PassengerForecastRun,
  type PassengerMonthlyEstimate,
  type PassengerMonthlyFact,
  type PassengerSourceFile,
  type PassengerSummaryResponse,
} from "../services/passengerIntelligenceService";

const number = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });
const decimal = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });

const directionLabels: Record<string, string> = {
  total: "Total",
  arrival: "Llegadas",
  departure: "Salidas",
};

const views = [
  { id: "analysis", label: "Analisis", icon: BarChart3 },
  { id: "forecast", label: "Forecast IA", icon: BrainCircuit },
  { id: "monthly", label: "Mensual", icon: CalendarDays },
  { id: "operation", label: "Operacion", icon: PlaneTakeoff },
  { id: "data", label: "Datos", icon: Table2 },
] as const;

type ViewId = (typeof views)[number]["id"];

function formatNumber(value?: number | null) {
  if (value === null || value === undefined) return "Sin dato";
  return number.format(value);
}

function formatPct(value?: number | null) {
  if (value === null || value === undefined) return "Sin perfil";
  return `${decimal.format(value)}%`;
}

function formatDate(value?: string | null) {
  if (!value) return "Sin fecha";
  return value.slice(0, 10);
}

function formatBytes(value?: number | null) {
  if (!value) return "0 KB";
  if (value < 1024 * 1024) return `${decimal.format(value / 1024)} KB`;
  return `${decimal.format(value / 1024 / 1024)} MB`;
}

function monthDateRange(monthValue: string) {
  const [year, month] = monthValue.split("-").map(Number);
  if (!year || !month) return { from: "", to: "" };

  const lastDay = new Date(year, month, 0).getDate();

  return {
    from: `${year}-${String(month).padStart(2, "0")}-01`,
    to: `${year}-${String(month).padStart(2, "0")}-${String(lastDay).padStart(2, "0")}`,
  };
}

function monthFromDate(dateValue: string) {
  return dateValue ? dateValue.slice(0, 7) : "";
}

function tooltipPax(value: unknown) {
  const numeric = typeof value === "number" ? value : Number(value || 0);
  return [number.format(numeric), "PAX"];
}

function tooltipMonthly(value: unknown, name: unknown) {
  const numeric = typeof value === "number" ? value : Number(value || 0);
  const label =
    name === "skyfreePax"
      ? "Sky Free observado"
      : name === "officialPax"
        ? "Aerocivil oficial"
        : name === "difference"
          ? "Diferencia"
          : String(name);

  return [number.format(numeric), label];
}

function tooltipPercent(value: unknown, name: unknown) {
  const numeric = typeof value === "number" ? value : Number(value || 0);
  const label = name === "colombianPct" ? "Colombianos" : name === "foreignPct" ? "Extranjeros" : String(name);

  return [`${decimal.format(numeric)}%`, label];
}

function Kpi({
  title,
  value,
  sub,
  icon: Icon,
}: {
  title: string;
  value: string;
  sub?: string;
  icon: typeof Users;
}) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{title}</p>
          <p className="mt-2 text-2xl font-black text-slate-950">{value}</p>
          {sub && <p className="mt-1 text-sm text-slate-500">{sub}</p>}
        </div>
        <div className="rounded-md bg-slate-100 p-2 text-slate-700">
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </div>
  );
}

function TextList({ items, empty }: { items?: string[]; empty: string }) {
  const visibleItems = (items || []).filter(Boolean);

  if (visibleItems.length === 0) {
    return <p className="text-sm leading-6 text-slate-500">{empty}</p>;
  }

  return (
    <div className="space-y-2">
      {visibleItems.map((item) => (
        <p key={item} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm leading-6 text-slate-700">
          {item}
        </p>
      ))}
    </div>
  );
}

function SectionHeader({ title, sub }: { title: string; sub?: string }) {
  return (
    <div>
      <h2 className="text-lg font-black text-slate-900">{title}</h2>
      {sub && <p className="mt-1 text-sm leading-6 text-slate-600">{sub}</p>}
    </div>
  );
}

export default function PassengerIntelligencePage() {
  const [summary, setSummary] = useState<PassengerSummaryResponse | null>(null);
  const [batches, setBatches] = useState<PassengerBatch[]>([]);
  const [sourceFiles, setSourceFiles] = useState<PassengerSourceFile[]>([]);
  const [monthlyFacts, setMonthlyFacts] = useState<PassengerMonthlyFact[]>([]);
  const [monthlyEstimates, setMonthlyEstimates] = useState<PassengerMonthlyEstimate[]>([]);
  const [forecasts, setForecasts] = useState<PassengerForecastRun[]>([]);
  const [externalSignals, setExternalSignals] = useState<PassengerExternalSignal[]>([]);
  const [externalImpact, setExternalImpact] = useState<PassengerExternalSignalImpact | null>(null);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [syncingOneDrive, setSyncingOneDrive] = useState(false);
  const [importingOneDrive, setImportingOneDrive] = useState(false);
  const [importingMigration, setImportingMigration] = useState(false);
  const [recalculatingAll, setRecalculatingAll] = useState(false);
  const [generatingForecast, setGeneratingForecast] = useState(false);
  const [syncingSignals, setSyncingSignals] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [activeView, setActiveView] = useState<ViewId>("analysis");
  const [direction, setDirection] = useState("");
  const [selectedMonth, setSelectedMonth] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const canImport = hasPermission("passenger-intelligence.import");
  const canManage = hasPermission("passenger-intelligence.manage");
  const canForecast = canManage || hasPermission("passenger-intelligence.forecast");
  const canManageSignals = canManage || hasPermission("passenger-intelligence.signals.manage");

  async function load() {
    setLoading(true);
    try {
      const params: Record<string, string> = { data_type: "observed" };
      if (direction) params.direction = direction;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;

      const estimateParams: { date_from?: string; date_to?: string; direction?: string; data_type?: string } = {
        data_type: "observed",
      };
      if (dateFrom) estimateParams.date_from = dateFrom;
      if (dateTo) estimateParams.date_to = dateTo;
      if (direction) estimateParams.direction = direction;

      const [summaryData, batchesData, sourceFilesData, monthlyFactsData, monthlyEstimateData, forecastData, signalData, signalImpactData] = await Promise.all([
        getPassengerSummary(params),
        getPassengerBatches(),
        getPassengerSourceFiles(),
        getPassengerMonthlyFacts(),
        getPassengerMonthlyEstimates(estimateParams),
        getPassengerForecasts(),
        getPassengerExternalSignals(dateFrom || dateTo ? { date_from: dateFrom || undefined, date_to: dateTo || undefined } : undefined),
        getPassengerExternalSignalImpact(),
      ]);

      setSummary(summaryData);
      setBatches(batchesData);
      setSourceFiles(sourceFilesData);
      setMonthlyFacts(monthlyFactsData);
      setMonthlyEstimates(monthlyEstimateData);
      setForecasts(forecastData);
      setExternalSignals(signalData);
      setExternalImpact(signalImpactData);
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo cargar Passenger Intelligence.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleUpload(file?: File | null) {
    if (!file) return;
    setUploading(true);
    setMessage(null);
    try {
      const result = await importPassengerExcel(file);
      setMessage(`Importacion completada: ${formatNumber(result.rows_imported)} filas, ${formatNumber(result.total_pax)} PAX.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo importar el Excel.");
    } finally {
      setUploading(false);
    }
  }

  async function handleOneDriveSync() {
    setSyncingOneDrive(true);
    setMessage(null);
    try {
      const result = await syncPassengerOneDriveFiles();
      setMessage(`OneDrive sincronizado: ${formatNumber(result.files.length)} archivos PAX detectados.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo sincronizar la carpeta PAX Col de OneDrive.");
    } finally {
      setSyncingOneDrive(false);
    }
  }

  async function handleOneDriveImport(sourceFileId?: number) {
    setImportingOneDrive(true);
    setMessage(null);
    try {
      const result = await importPassengerOneDriveFile(sourceFileId);
      const imported = result?.results?.reduce((sum: number, row: any) => sum + Number(row.rows_imported || 0), 0) || 0;
      setMessage(`Importacion OneDrive lista: ${formatNumber(imported)} filas procesadas.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo importar desde OneDrive.");
    } finally {
      setImportingOneDrive(false);
    }
  }

  async function handleMigrationMicrodataImport(file?: File | null) {
    if (!file) return;
    setImportingMigration(true);
    setMessage(null);
    try {
      const result = await importPassengerMigrationMicrodata(file, true);
      const profiles = Array.isArray(result?.profiles) ? result.profiles.length : 0;
      setMessage(`Microdatos Migracion listos: ${formatNumber(profiles)} perfiles creados/actualizados.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.error || error?.response?.data?.message || "No se pudieron importar los microdatos de Migracion.");
    } finally {
      setImportingMigration(false);
    }
  }

  async function handleRecalculateAll() {
    setRecalculatingAll(true);
    setMessage(null);
    try {
      const payload: { date_from?: string; date_to?: string; direction?: string; data_type?: string } = { data_type: "observed" };
      if (dateFrom) payload.date_from = dateFrom;
      if (dateTo) payload.date_to = dateTo;
      if (direction) payload.direction = direction;

      const result = await recalculatePassengerAll(payload);
      setMessage(`Calculo guardado: ${formatNumber(result.estimates.processed)} vuelos estimados.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo recalcular Passenger Intelligence.");
    } finally {
      setRecalculatingAll(false);
    }
  }

  async function handleGenerateForecast(sendEmail = false) {
    setGeneratingForecast(true);
    setMessage(null);
    try {
      const result = await generatePassengerForecast({
        send_email: sendEmail,
        email: "sebastian.cruz@dutyfreepartners.com",
      });
      setMessage(
        `Forecast IA generado para ${result.forecast.target_period}: ${formatNumber(result.forecast.predicted_total_pax)} PAX predichos.`
      );
      await load();
      setActiveView("forecast");
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo generar el forecast con IA.");
    } finally {
      setGeneratingForecast(false);
    }
  }

  async function handleSyncSignals() {
    setSyncingSignals(true);
    setMessage(null);
    try {
      const result = await syncPassengerExternalSignals();
      setMessage(`Festivos y eventos sincronizados: ${formatNumber(result.created)} nuevos, ${formatNumber(result.updated)} actualizados, ${formatNumber(result.total)} totales.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudieron sincronizar los festivos y eventos.");
    } finally {
      setSyncingSignals(false);
    }
  }

  function handleMonthChange(value: string) {
    setSelectedMonth(value);

    if (!value) {
      setDateFrom("");
      setDateTo("");
      return;
    }

    const range = monthDateRange(value);
    setDateFrom(range.from);
    setDateTo(range.to);
  }

  function handleDateFromChange(value: string) {
    setDateFrom(value);
    setSelectedMonth(monthFromDate(value));
  }

  function clearFilters() {
    setDirection("");
    setSelectedMonth("");
    setDateFrom("");
    setDateTo("");
  }

  const directionData = useMemo(
    () =>
      summary?.by_direction.map((item) => ({
        ...item,
        label: directionLabels[item.direction] || item.direction,
      })) || [],
    [summary]
  );

  const monthlyComparisonData = useMemo(() => {
    const rows = new Map<
      string,
      {
        period: string;
        year: number;
        month: number;
        skyfreePax: number;
        officialPax: number;
        difference: number;
        coveragePct: number | null;
      }
    >();

    monthlyFacts
      .filter((fact) => fact.direction === "total")
      .forEach((fact) => {
        const period = `${fact.year}-${String(fact.month).padStart(2, "0")}`;
        const current = rows.get(period) || {
          period,
          year: fact.year,
          month: fact.month,
          skyfreePax: 0,
          officialPax: 0,
          difference: 0,
          coveragePct: null,
        };

        if (fact.fact_type === "skyfree_commercial_observed_pax") {
          current.skyfreePax = Number(fact.value || 0);
        }

        if (fact.fact_type === "airport_official_international_pax") {
          current.officialPax = Number(fact.value || 0);
        }

        current.difference = current.skyfreePax - current.officialPax;
        current.coveragePct = current.officialPax > 0 ? Number(((current.skyfreePax / current.officialPax) * 100).toFixed(1)) : null;
        rows.set(period, current);
      });

    return Array.from(rows.values()).sort((a, b) => (a.year === b.year ? a.month - b.month : a.year - b.year));
  }, [monthlyFacts]);

  const selectedMonthComparison = selectedMonth ? monthlyComparisonData.find((row) => row.period === selectedMonth) : null;

  const nationalityMonthlyData = useMemo(() => {
    const rows = new Map<
      string,
      {
        period: string;
        year: number;
        month: number;
        flights: number;
        commercialPax: number;
        colombianPax: number;
        foreignPax: number;
        colombianPct: number;
        foreignPct: number;
      }
    >();

    monthlyEstimates.forEach((item) => {
      const current = rows.get(item.period) || {
        period: item.period,
        year: item.year,
        month: item.month,
        flights: 0,
        commercialPax: 0,
        colombianPax: 0,
        foreignPax: 0,
        colombianPct: 0,
        foreignPct: 0,
      };

      current.flights += Number(item.flights || 0);
      current.commercialPax += Number(item.commercial_exposed_pax || 0);
      current.colombianPax += Number(item.colombian_pax || 0);
      current.foreignPax += Number(item.foreign_pax || 0);
      current.colombianPct = current.commercialPax > 0 ? Number(((current.colombianPax / current.commercialPax) * 100).toFixed(1)) : 0;
      current.foreignPct = current.commercialPax > 0 ? Number(((current.foreignPax / current.commercialPax) * 100).toFixed(1)) : 0;
      rows.set(item.period, current);
    });

    return Array.from(rows.values()).sort((a, b) => (a.year === b.year ? a.month - b.month : a.year - b.year));
  }, [monthlyEstimates]);

  const selectedNationalityMonth = selectedMonth ? nationalityMonthlyData.find((row) => row.period === selectedMonth) : null;
  const latestForecast = forecasts[0];
  const recentMonths = nationalityMonthlyData.slice(-8).reverse();
  const signalImpactMonths = useMemo(
    () => externalImpact?.months.slice().sort((a, b) => (a.year === b.year ? b.month - a.month : b.year - a.year)) || [],
    [externalImpact]
  );
  const selectedSignalImpactMonth = selectedMonth ? signalImpactMonths.find((row) => row.period === selectedMonth) : null;
  const visibleSignalImpactMonths = selectedSignalImpactMonth ? [selectedSignalImpactMonth] : signalImpactMonths.slice(0, 8);
  const latestForecastSignals = ((latestForecast?.input_sources?.external_signals as PassengerExternalSignal[] | undefined) || []).slice(0, 5);
  const latestForecastCalculation = (latestForecast?.input_sources?.calculation as Record<string, any> | undefined) || null;

  return (
    <div className="space-y-5 pb-10">
      <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_auto] xl:items-end">
          <div>
            <p className="text-sm font-bold uppercase tracking-wide text-primary">Passenger Intelligence</p>
            <h1 className="mt-1 text-3xl font-black text-slate-950">Flujo internacional MDE</h1>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
              Vista de analisis para PAX observados de Sky Free y composicion estimada colombiano/extranjero.
            </p>
          </div>

          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[160px_160px_160px_150px_auto_auto]">
            <input
              type="month"
              value={selectedMonth}
              onChange={(event) => handleMonthChange(event.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold"
              aria-label="Seleccionar mes"
            />
            <input
              type="date"
              value={dateFrom}
              onChange={(event) => handleDateFromChange(event.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm"
              aria-label="Fecha inicial"
            />
            <input
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm"
              aria-label="Fecha final"
            />
            <select
              value={direction}
              onChange={(event) => setDirection(event.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm"
              aria-label="Direccion"
            >
              <option value="">Total</option>
              <option value="departure">Salidas</option>
              <option value="arrival">Llegadas</option>
            </select>
            <button
              type="button"
              onClick={load}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white"
            >
              <RefreshCw className="h-4 w-4" />
              Aplicar
            </button>
            <button
              type="button"
              onClick={clearFilters}
              className="inline-flex items-center justify-center rounded-md border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700"
            >
              Limpiar
            </button>
          </div>
        </div>

        <div className="mt-4 flex gap-2 overflow-x-auto pb-1">
          {views.map((view) => {
            const Icon = view.icon;
            const active = activeView === view.id;
            return (
              <button
                key={view.id}
                type="button"
                onClick={() => setActiveView(view.id)}
                className={`inline-flex shrink-0 items-center gap-2 rounded-md px-4 py-2 text-sm font-bold transition ${
                  active ? "bg-slate-950 text-white" : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-50"
                }`}
              >
                <Icon className="h-4 w-4" />
                {view.label}
              </button>
            );
          })}
        </div>
      </section>

      {message && <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm font-semibold text-slate-700 shadow-sm">{message}</div>}

      {loading ? (
        <div className="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm font-semibold text-slate-500 shadow-sm">
          Cargando datos...
        </div>
      ) : (
        <>
          {activeView === "analysis" && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Kpi
                  title="PAX observados"
                  value={formatNumber(summary?.summary.total_pax)}
                  sub={`${formatNumber(summary?.summary.total_flights)} vuelos · ${formatNumber(summary?.summary.days)} dias`}
                  icon={Users}
                />
                <Kpi title="Colombianos est." value={formatNumber(summary?.summary.colombian_pax)} sub={formatPct(summary?.summary.colombian_pct)} icon={CheckCircle2} />
                <Kpi title="Extranjeros est." value={formatNumber(summary?.summary.foreign_pax)} sub={formatPct(summary?.summary.foreign_pct)} icon={Users} />
                <Kpi title="Promedio diario" value={formatNumber(summary?.summary.avg_pax_per_day)} sub={`${direction ? directionLabels[direction] : "Total MDE"}`} icon={BarChart3} />
              </div>

              <div className={`rounded-lg border p-4 shadow-sm ${summary?.composition ? "border-emerald-200 bg-emerald-50" : "border-amber-200 bg-amber-50"}`}>
                <div className="flex items-start gap-3">
                  {summary?.composition ? <CheckCircle2 className="mt-0.5 h-5 w-5 text-emerald-700" /> : <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-700" />}
                  <div className="flex-1">
                    <p className="font-black text-slate-900">
                      {summary?.composition ? summary.composition.name : "Composicion colombiano/extranjero pendiente"}
                    </p>
                    <p className="mt-1 text-sm leading-6 text-slate-700">{summary?.quality.veracity_note}</p>
                    {summary?.composition && (
                      <p className="mt-1 text-xs font-bold uppercase tracking-wide text-slate-500">
                        Fuente: {summary.composition.source_name} · Metodo: {summary.composition.method}
                      </p>
                    )}
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1.25fr_0.75fr]">
                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <SectionHeader title="Colombianos / extranjeros" sub="Porcentaje estimado sobre PAX observados en el rango seleccionado." />
                    <span className="text-xs font-bold uppercase tracking-wide text-slate-400">
                      {direction ? directionLabels[direction] : "Total llegadas + salidas"}
                    </span>
                  </div>
                  <div className="h-80">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart data={nationalityMonthlyData} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                        <XAxis dataKey="period" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} domain={[0, 100]} tickFormatter={(value) => `${value}%`} />
                        <Tooltip formatter={tooltipPercent} />
                        <Legend />
                        <Bar dataKey="colombianPct" stackId="nationality" fill="#0f766e" name="Colombianos" />
                        <Bar dataKey="foreignPct" stackId="nationality" fill="#475569" name="Extranjeros" radius={[4, 4, 0, 0]} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <SectionHeader title={selectedMonth ? `Mes ${selectedMonth}` : "Ultimos meses"} sub="Selecciona un mes arriba para ver el detalle puntual." />
                  <div className="mt-4 space-y-2">
                    {(selectedNationalityMonth ? [selectedNationalityMonth] : recentMonths).map((row) => (
                      <button
                        key={row.period}
                        type="button"
                        onClick={() => handleMonthChange(row.period)}
                        className="w-full rounded-md border border-slate-200 px-3 py-2 text-left transition hover:bg-slate-50"
                      >
                        <div className="flex items-center justify-between gap-3">
                          <p className="font-black text-slate-900">{row.period}</p>
                          <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-600">
                            {formatNumber(row.flights)} vuelos
                          </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-600">
                          {formatPct(row.colombianPct)} colombianos · {formatPct(row.foreignPct)} extranjeros
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                          {formatNumber(row.commercialPax)} PAX · {formatNumber(row.flights)} vuelos
                        </p>
                      </button>
                    ))}
                  </div>
                </section>
              </div>
            </div>
          )}

          {activeView === "forecast" && (
            <div className="space-y-4">
              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <SectionHeader
                    title="Forecast IA mensual"
                    sub="Prediccion mensual con resultado numerico, explicacion, riesgos y plan de seguimiento."
                  />
                  {canForecast && (
                    <div className="flex shrink-0 flex-wrap gap-2">
                      <button
                        type="button"
                        onClick={() => handleGenerateForecast(false)}
                        disabled={generatingForecast}
                        className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 disabled:opacity-60"
                      >
                        <BrainCircuit className="h-4 w-4" />
                        {generatingForecast ? "Generando..." : "Generar IA"}
                      </button>
                      <button
                        type="button"
                        onClick={() => handleGenerateForecast(true)}
                        disabled={generatingForecast}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                      >
                        <BrainCircuit className="h-4 w-4" />
                        Enviar correo
                      </button>
                    </div>
                  )}
                </div>

                {latestForecast ? (
                  <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[0.8fr_1.2fr]">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
                      <Kpi title="Periodo predicho" value={latestForecast.target_period} sub={`Analisis: ${formatDate(latestForecast.run_date)}`} icon={CalendarDays} />
                      <Kpi title="PAX predicho" value={formatNumber(latestForecast.predicted_total_pax)} sub={`Metodo ${latestForecast.method}`} icon={Users} />
                      <Kpi title="% Colombianos" value={formatPct(latestForecast.predicted_colombian_pct)} sub={formatPct(latestForecast.predicted_foreign_pct) + " extranjeros"} icon={CheckCircle2} />
                      <Kpi title="Fecha de corte" value={formatDate(latestForecast.cutoff_date)} sub="Base usada para analizar" icon={BrainCircuit} />
                      {latestForecastCalculation && (
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                          <h3 className="font-black text-slate-900">Formula auditable</h3>
                          <p className="mt-2 text-xs leading-5 text-slate-600">
                            60% mismo mes año pasado ajustado por tendencia + 30% ultimos 3 meses + 10% promedio historico.
                          </p>
                          <div className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-3">
                              <span className="text-slate-500">Ultimos 3 meses</span>
                              <span className="font-bold text-slate-900">{formatNumber(Number(latestForecastCalculation.last_three_months_avg || 0))}</span>
                            </div>
                            <div className="flex justify-between gap-3">
                              <span className="text-slate-500">Mismos 3 meses año pasado</span>
                              <span className="font-bold text-slate-900">{formatNumber(Number(latestForecastCalculation.last_year_same_three_months_avg || 0))}</span>
                            </div>
                            <div className="flex justify-between gap-3">
                              <span className="text-slate-500">Factor crecimiento</span>
                              <span className="font-bold text-slate-900">
                                {latestForecastCalculation.year_over_year_recent_growth_factor
                                  ? `${decimal.format(Number(latestForecastCalculation.year_over_year_recent_growth_factor) * 100)}%`
                                  : "Sin dato"}
                              </span>
                            </div>
                            <div className="flex justify-between gap-3">
                              <span className="text-slate-500">Mes año pasado ajustado</span>
                              <span className="font-bold text-slate-900">{formatNumber(Number(latestForecastCalculation.same_month_last_year_adjusted_by_recent_trend || 0))}</span>
                            </div>
                            <div className="flex justify-between gap-3">
                              <span className="text-slate-500">Promedio historico</span>
                              <span className="font-bold text-slate-900">{formatNumber(Number(latestForecastCalculation.overall_avg || 0))}</span>
                            </div>
                          </div>
                          <div className="mt-3 border-t border-slate-200 pt-3 text-xs leading-5 text-slate-500">
                            <p>Ventana actual: {Array.isArray(latestForecastCalculation.recent_window_periods) ? latestForecastCalculation.recent_window_periods.join(", ") : "Sin dato"}</p>
                            <p>Ventana año pasado: {Array.isArray(latestForecastCalculation.last_year_window_periods) ? latestForecastCalculation.last_year_window_periods.join(", ") : "Sin dato"}</p>
                            {latestForecastCalculation.fallback_note && <p className="mt-2 text-amber-700">{latestForecastCalculation.fallback_note}</p>}
                          </div>
                        </div>
                      )}
                    </div>

                    <div className="space-y-4">
                      <div className="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 className="font-black text-slate-900">Analisis del resultado</h3>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                          {latestForecast.explanation?.executive_summary || "Sin resumen generado."}
                        </p>
                        {latestForecast.explanation?.openai_error && (
                          <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {latestForecast.explanation.openai_error}
                          </p>
                        )}
                      </div>

                      <div className="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 className="font-black text-slate-900">Festivos y eventos del mes</h3>
                        <div className="mt-3 space-y-2">
                          {latestForecastSignals.length === 0 ? (
                            <p className="text-sm leading-6 text-slate-500">
                              Este forecast no guardo festivos o eventos como insumo.
                            </p>
                          ) : (
                            latestForecastSignals.map((signal) => (
                              <a
                                key={`${signal.name}-${signal.date_from}`}
                                href={signal.source_url || undefined}
                                target="_blank"
                                rel="noreferrer"
                                className="block rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:bg-white"
                              >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                  <span className="font-black text-slate-900">{signal.name}</span>
                                  <span className="rounded-md bg-slate-200 px-2 py-1 text-xs font-bold uppercase text-slate-700">
                                    {signal.expected_impact}
                                  </span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                  {signal.date_from} - {signal.date_to} · {signal.source_name}
                                </p>
                              </a>
                            ))
                          )}
                        </div>
                      </div>

                      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                          <h3 className="mb-3 font-black text-slate-900">Por que dio ese resultado</h3>
                          <TextList items={latestForecast.explanation?.forecast_drivers} empty="Sin drivers guardados para este forecast." />
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                          <h3 className="mb-3 font-black text-slate-900">Riesgos</h3>
                          <TextList items={latestForecast.explanation?.risks} empty="Sin riesgos reportados." />
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                          <h3 className="mb-3 font-black text-slate-900">Seguimiento</h3>
                          <TextList items={latestForecast.explanation?.accuracy_monitoring_plan} empty="Sin plan de seguimiento." />
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-white p-4">
                          <h3 className="mb-3 font-black text-slate-900">Posibles fallos</h3>
                          <TextList items={latestForecast.explanation?.failure_modes} empty="Sin fallos adicionales reportados." />
                        </div>
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-10 text-center text-sm text-slate-600">
                    Todavia no hay forecast IA generado.
                  </div>
                )}
              </section>
            </div>
          )}

          {activeView === "monthly" && (
            <div className="space-y-4">
              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                  <SectionHeader title="Tabla mensual de composicion" sub="Resumen mensual de PAX observados y composicion colombiano/extranjero estimada." />
                  {canManageSignals && (
                    <button
                      type="button"
                      onClick={handleRecalculateAll}
                      disabled={recalculatingAll}
                      className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                    >
                      <RefreshCw className="h-4 w-4" />
                      {recalculatingAll ? "Calculando..." : "Recalcular rango"}
                    </button>
                  )}
                </div>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Mes</th>
                        <th className="px-3 py-2 text-right">PAX</th>
                        <th className="px-3 py-2 text-right">Colombianos</th>
                        <th className="px-3 py-2 text-right">Extranjeros</th>
                        <th className="px-3 py-2 text-right">Vuelos</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {nationalityMonthlyData.map((row) => (
                        <tr key={row.period} className={selectedMonth === row.period ? "bg-slate-50" : undefined}>
                          <td className="px-3 py-2">
                            <button type="button" onClick={() => handleMonthChange(row.period)} className="font-bold text-slate-900 hover:underline">
                              {row.period}
                            </button>
                          </td>
                          <td className="px-3 py-2 text-right">{formatNumber(row.commercialPax)}</td>
                          <td className="px-3 py-2 text-right font-bold text-teal-700">
                            {formatPct(row.colombianPct)} · {formatNumber(row.colombianPax)}
                          </td>
                          <td className="px-3 py-2 text-right font-bold text-slate-700">
                            {formatPct(row.foreignPct)} · {formatNumber(row.foreignPax)}
                          </td>
                          <td className="px-3 py-2 text-right">{formatNumber(row.flights)}</td>
                        </tr>
                      ))}
                      {nationalityMonthlyData.length === 0 && (
                        <tr>
                          <td className="px-3 py-8 text-center text-slate-500" colSpan={5}>
                            Todavia no hay estimaciones mensuales guardadas.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <SectionHeader title="Comparativo mensual Sky Free vs Aerocivil" sub="Este bloque concentra el contraste con el total oficial disponible." />
                {selectedMonth && (
                  <div className="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    {selectedMonthComparison ? (
                      <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Mes</p>
                          <p className="mt-1 font-black text-slate-900">{selectedMonthComparison.period}</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Sky Free observado</p>
                          <p className="mt-1 font-black text-slate-900">{formatNumber(selectedMonthComparison.skyfreePax)} PAX</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Aerocivil oficial</p>
                          <p className="mt-1 font-black text-slate-900">{formatNumber(selectedMonthComparison.officialPax)} PAX</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Cobertura</p>
                          <p className="mt-1 font-black text-slate-900">{formatPct(selectedMonthComparison.coveragePct)}</p>
                        </div>
                      </div>
                    ) : (
                      <p className="text-sm font-semibold text-slate-600">No hay comparativo guardado para {selectedMonth}.</p>
                    )}
                  </div>
                )}
                <div className="mt-4 h-80">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={monthlyComparisonData} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                      <XAxis dataKey="period" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} tickFormatter={(value) => number.format(value)} />
                      <Tooltip formatter={tooltipMonthly} />
                      <Legend />
                      <Bar dataKey="skyfreePax" fill="#0f766e" name="Sky Free observado" radius={[4, 4, 0, 0]} />
                      <Bar dataKey="officialPax" fill="#2563eb" name="Aerocivil oficial" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </section>

              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <SectionHeader
                    title="Festivos y eventos verificables"
                    sub="Cruce descriptivo entre PAX mensual observado y festivos/eventos guardados. No prueba causalidad, sirve para explicar y ajustar forecast."
                  />
                  {canManageSignals && (
                    <button
                      type="button"
                      onClick={handleSyncSignals}
                      disabled={syncingSignals}
                      className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 disabled:opacity-60"
                    >
                      <RefreshCw className="h-4 w-4" />
                      {syncingSignals ? "Sincronizando..." : "Actualizar festivos"}
                    </button>
                  )}
                </div>

                {externalImpact && (
                  <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                    <Kpi title="Meses analizados" value={formatNumber(externalImpact.summary.months_analyzed)} sub={`${formatNumber(externalImpact.summary.months_with_signals)} con festivos/eventos`} icon={CalendarDays} />
                    <Kpi title="PAX con festivos" value={formatNumber(externalImpact.summary.avg_pax_with_signals)} sub="Promedio mensual" icon={Users} />
                    <Kpi title="PAX sin festivos" value={formatNumber(externalImpact.summary.avg_pax_without_signals)} sub="Promedio mensual" icon={Users} />
                    <Kpi title="Diferencia" value={formatPct(externalImpact.summary.difference_pct)} sub="Comparativo descriptivo" icon={BarChart3} />
                  </div>
                )}

                <div className="mt-4 overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Mes</th>
                        <th className="px-3 py-2 text-right">PAX</th>
                        <th className="px-3 py-2 text-right">Vs 3 meses</th>
                        <th className="px-3 py-2">Festivos / eventos</th>
                        <th className="px-3 py-2">Lectura</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {visibleSignalImpactMonths.map((row) => (
                        <tr key={row.period} className={selectedMonth === row.period ? "bg-slate-50" : undefined}>
                          <td className="px-3 py-3">
                            <button type="button" onClick={() => handleMonthChange(row.period)} className="font-black text-slate-900 hover:underline">
                              {row.period}
                            </button>
                            <p className="mt-1 text-xs text-slate-500">{formatNumber(row.flights)} vuelos</p>
                          </td>
                          <td className="px-3 py-3 text-right font-bold text-slate-900">{formatNumber(row.pax)}</td>
                          <td className={`px-3 py-3 text-right font-bold ${Number(row.lift_vs_previous_3_pct || 0) >= 0 ? "text-teal-700" : "text-rose-700"}`}>
                            {formatPct(row.lift_vs_previous_3_pct)}
                            <p className="mt-1 text-xs font-normal text-slate-500">
                              Base: {formatNumber(row.previous_3_month_avg)}
                            </p>
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex max-w-xs flex-wrap gap-1">
                              {row.top_signals.length === 0 ? (
                                <span className="text-xs text-slate-500">Sin festivos/eventos</span>
                              ) : (
                                row.top_signals.map((signal) => (
                                  <span key={`${row.period}-${signal.name}`} className="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">
                                    {signal.name}
                                  </span>
                                ))
                              )}
                            </div>
                          </td>
                          <td className="max-w-sm px-3 py-3 text-slate-600">{row.analysis}</td>
                        </tr>
                      ))}
                      {visibleSignalImpactMonths.length === 0 && (
                        <tr>
                          <td className="px-3 py-8 text-center text-slate-500" colSpan={5}>
                            Todavia no hay meses con PAX observado para cruzar contra festivos/eventos.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </section>
            </div>
          )}

          {activeView === "operation" && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1.5fr_1fr]">
                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="mb-4 flex items-center justify-between">
                    <SectionHeader title="Flujo por hora" />
                    <span className="text-xs font-bold uppercase tracking-wide text-slate-400">America/Bogota</span>
                  </div>
                  <div className="h-80">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={summary?.hourly || []} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                        <XAxis dataKey="hour" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} tickFormatter={(value) => number.format(value)} />
                        <Tooltip formatter={tooltipPax} />
                        <Line type="monotone" dataKey="pax" stroke="#0f766e" strokeWidth={3} dot={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <SectionHeader title="Llegadas / salidas" />
                  <div className="mt-4 h-80">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart data={directionData} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                        <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                        <YAxis tick={{ fontSize: 11 }} tickFormatter={(value) => number.format(value)} />
                        <Tooltip formatter={tooltipPax} />
                        <Bar dataKey="pax" radius={[4, 4, 0, 0]}>
                          {directionData.map((entry) => (
                            <Cell key={entry.direction} fill={entry.direction === "arrival" ? "#2563eb" : "#0f766e"} />
                          ))}
                        </Bar>
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </section>
              </div>

              <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <SectionHeader title="Aerolineas principales" />
                  <div className="mt-4 space-y-3">
                    {summary?.airlines.map((item) => (
                      <div key={item.airline}>
                        <div className="mb-1 flex justify-between text-sm">
                          <span className="font-bold text-slate-800">{item.airline}</span>
                          <span className="text-slate-500">{formatNumber(item.pax)} PAX</span>
                        </div>
                        <div className="h-2 rounded-full bg-slate-100">
                          <div
                            className="h-2 rounded-full bg-teal-700"
                            style={{ width: `${Math.min(100, (item.pax / Math.max(summary?.summary.total_pax || 1, 1)) * 100)}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <SectionHeader title="Rutas principales" />
                  <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {summary?.routes.map((item) => (
                      <div key={`${item.route}-${item.direction}`} className="rounded-md border border-slate-200 p-3">
                        <div className="flex items-center justify-between gap-2">
                          <p className="font-black text-slate-900">{item.route}</p>
                          {item.direction === "arrival" ? <PlaneLanding className="h-4 w-4 text-blue-700" /> : <PlaneTakeoff className="h-4 w-4 text-teal-700" />}
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                          {formatNumber(item.pax)} PAX · {formatNumber(item.flights)} vuelos
                        </p>
                      </div>
                    ))}
                  </div>
                </section>
              </div>

            </div>
          )}

          {activeView === "data" && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                {canImport && (
                  <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <SectionHeader title="Importar Excel PAX" sub="Carga manual para archivos puntuales o validaciones." />
                    <label className="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 p-8 text-center transition hover:bg-slate-100">
                      <FileUp className="h-8 w-8 text-slate-500" />
                      <span className="mt-3 text-sm font-bold text-slate-800">{uploading ? "Importando..." : "Seleccionar archivo .xlsx"}</span>
                      <input
                        type="file"
                        accept=".xlsx,.xls"
                        className="hidden"
                        disabled={uploading}
                        onChange={(event) => handleUpload(event.target.files?.[0])}
                      />
                    </label>
                    <div className="mt-4 space-y-2">
                      {batches.slice(0, 5).map((batch) => (
                        <div key={batch.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm">
                          <div>
                            <p className="font-bold text-slate-900">{batch.filename}</p>
                            <p className="text-xs text-slate-500">
                              {batch.period_start} - {batch.period_end}
                            </p>
                          </div>
                          <span className="font-bold text-slate-700">{formatNumber(batch.total_pax)} PAX</span>
                        </div>
                      ))}
                    </div>
                  </section>
                )}

                {canImport && (
                  <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <SectionHeader
                      title="Microdatos Migracion"
                      sub="Fuente para calcular % colombianos/extranjeros sin restar contra Aerocivil."
                    />
                    <label className="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-teal-300 bg-teal-50 p-8 text-center transition hover:bg-teal-100">
                      <FileUp className="h-8 w-8 text-teal-700" />
                      <span className="mt-3 text-sm font-bold text-slate-800">
                        {importingMigration ? "Importando..." : "Seleccionar .csv, .xlsx o .xls"}
                      </span>
                      <span className="mt-2 text-xs leading-5 text-slate-600">
                        Debe contener fecha/anio-mes, puesto de control, movimiento y nacionalidad.
                      </span>
                      <input
                        type="file"
                        accept=".csv,.txt,.xlsx,.xls"
                        className="hidden"
                        disabled={importingMigration}
                        onChange={(event) => handleMigrationMicrodataImport(event.target.files?.[0])}
                      />
                    </label>
                    <div className="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">
                      Prioridad: si este perfil existe para el mes, el calculo usa microdatos Migracion antes que el fallback Migracion + Aerocivil.
                    </div>
                  </section>
                )}

                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <SectionHeader title="OneDrive PAX Col" sub="Fuente operativa principal para traer los PAX comerciales reales por mes." />
                    <div className="flex shrink-0 gap-2">
                      {canManage && (
                        <button
                          type="button"
                          onClick={handleOneDriveSync}
                          disabled={syncingOneDrive}
                          className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-60"
                        >
                          <Cloud className="h-4 w-4" />
                          {syncingOneDrive ? "Buscando..." : "Buscar"}
                        </button>
                      )}
                      {canImport && (
                        <button
                          type="button"
                          onClick={() => handleOneDriveImport()}
                          disabled={importingOneDrive}
                          className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60"
                        >
                          <FileUp className="h-4 w-4" />
                          {importingOneDrive ? "Importando..." : "Importar"}
                        </button>
                      )}
                    </div>
                  </div>

                  <div className="mt-4 space-y-2">
                    {sourceFiles.length === 0 ? (
                      <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        Aun no hay archivos OneDrive indexados.
                      </div>
                    ) : (
                      sourceFiles.slice(0, 6).map((file) => (
                        <div key={file.id} className="rounded-md border border-slate-200 px-3 py-2 text-sm">
                          <div className="flex items-start justify-between gap-3">
                            <div>
                              <p className="font-bold text-slate-900">{file.name}</p>
                              <p className="mt-1 text-xs text-slate-500">
                                {formatBytes(file.size)} · {file.status} · {file.source_last_modified_at || "sin fecha"}
                              </p>
                            </div>
                            {canImport && (
                              <button
                                type="button"
                                onClick={() => handleOneDriveImport(file.id)}
                                disabled={importingOneDrive}
                                className="rounded-md border border-slate-200 px-2 py-1 text-xs font-bold text-slate-700 disabled:opacity-60"
                              >
                                Importar
                              </button>
                            )}
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </section>
              </div>

              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <SectionHeader title="Hechos mensuales auditables" sub="Registros base que explican de donde sale cada total mensual." />
                <div className="mt-4 overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Periodo</th>
                        <th className="px-3 py-2">Tipo</th>
                        <th className="px-3 py-2">Flujo</th>
                        <th className="px-3 py-2">Fuente</th>
                        <th className="px-3 py-2 text-right">Valor</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {monthlyFacts.slice(0, 24).map((fact) => (
                        <tr key={fact.id}>
                          <td className="px-3 py-2 font-bold text-slate-900">
                            {fact.year}-{String(fact.month).padStart(2, "0")}
                          </td>
                          <td className="px-3 py-2 text-slate-600">{fact.fact_type}</td>
                          <td className="px-3 py-2">{directionLabels[fact.direction] || fact.direction}</td>
                          <td className="px-3 py-2 text-slate-600">{fact.source_type}</td>
                          <td className="px-3 py-2 text-right font-bold">{formatNumber(fact.value)}</td>
                        </tr>
                      ))}
                      {monthlyFacts.length === 0 && (
                        <tr>
                          <td className="px-3 py-6 text-center text-slate-500" colSpan={5}>
                            Todavia no hay hechos mensuales generados.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <SectionHeader title="Festivos y eventos auditables" sub="Festivos, eventos de ciudad y puntos calientes usados como contexto verificable para el forecast." />
                  {canManage && (
                    <button
                      type="button"
                      onClick={handleSyncSignals}
                      disabled={syncingSignals}
                      className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-60"
                    >
                      <RefreshCw className="h-4 w-4" />
                      {syncingSignals ? "Actualizando..." : "Actualizar"}
                    </button>
                  )}
                </div>
                <div className="mt-4 grid grid-cols-1 gap-2 lg:grid-cols-2">
                  {externalSignals.slice(0, 12).map((signal) => (
                    <a
                      key={signal.id}
                      href={signal.source_url || undefined}
                      target="_blank"
                      rel="noreferrer"
                      className="rounded-md border border-slate-200 px-3 py-2 text-sm transition hover:bg-slate-50"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-black text-slate-900">{signal.name}</span>
                        <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-600">
                          {signal.expected_impact}
                        </span>
                      </div>
                      <p className="mt-1 text-xs text-slate-500">
                        {signal.date_from} - {signal.date_to} · {signal.source_name}
                      </p>
                    </a>
                  ))}
                  {externalSignals.length === 0 && (
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500 lg:col-span-2">
                      No hay festivos/eventos guardados.
                    </div>
                  )}
                </div>
              </section>
            </div>
          )}
        </>
      )}
    </div>
  );
}
