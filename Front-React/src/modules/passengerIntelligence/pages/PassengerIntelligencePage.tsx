import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  BarChart3,
  Cloud,
  CheckCircle2,
  FileUp,
  Percent,
  PlaneLanding,
  PlaneTakeoff,
  RefreshCw,
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
  getPassengerBatches,
  getPassengerMonthlyEstimates,
  getPassengerMonthlyFacts,
  getPassengerProfiles,
  getPassengerSummary,
  getPassengerSourceFiles,
  importPassengerExcel,
  importPassengerOneDriveFile,
  recalculatePassengerAll,
  recalculatePassengerExposure,
  syncPassengerOneDriveFiles,
  syncPassengerOfficialSources,
  type PassengerBatch,
  type PassengerCompositionProfile,
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

function formatNumber(value?: number | null) {
  if (value === null || value === undefined) return "Sin dato";
  return number.format(value);
}

function formatPct(value?: number | null) {
  if (value === null || value === undefined) return "Sin perfil";
  return `${decimal.format(value)}%`;
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

export default function PassengerIntelligencePage() {
  const [summary, setSummary] = useState<PassengerSummaryResponse | null>(null);
  const [batches, setBatches] = useState<PassengerBatch[]>([]);
  const [profiles, setProfiles] = useState<PassengerCompositionProfile[]>([]);
  const [sourceFiles, setSourceFiles] = useState<PassengerSourceFile[]>([]);
  const [monthlyFacts, setMonthlyFacts] = useState<PassengerMonthlyFact[]>([]);
  const [monthlyEstimates, setMonthlyEstimates] = useState<PassengerMonthlyEstimate[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [syncingOfficial, setSyncingOfficial] = useState(false);
  const [syncingOneDrive, setSyncingOneDrive] = useState(false);
  const [importingOneDrive, setImportingOneDrive] = useState(false);
  const [recalculatingExposure, setRecalculatingExposure] = useState(false);
  const [recalculatingAll, setRecalculatingAll] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [direction, setDirection] = useState("");
  const [selectedMonth, setSelectedMonth] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const canImport = hasPermission("passenger-intelligence.import");
  const canManage = hasPermission("passenger-intelligence.manage");

  async function load() {
    setLoading(true);
    try {
      const params: Record<string, string> = {};
      if (direction) params.direction = direction;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;

      const estimateParams: { date_from?: string; date_to?: string; direction?: string } = {};
      if (dateFrom) estimateParams.date_from = dateFrom;
      if (dateTo) estimateParams.date_to = dateTo;
      if (direction) estimateParams.direction = direction;

      const [summaryData, batchesData, profilesData, sourceFilesData, monthlyFactsData, monthlyEstimateData] = await Promise.all([
        getPassengerSummary(params),
        getPassengerBatches(),
        getPassengerProfiles(),
        getPassengerSourceFiles(),
        getPassengerMonthlyFacts(),
        getPassengerMonthlyEstimates(estimateParams),
      ]);

      setSummary(summaryData);
      setBatches(batchesData);
      setProfiles(profilesData);
      setSourceFiles(sourceFilesData);
      setMonthlyFacts(monthlyFactsData);
      setMonthlyEstimates(monthlyEstimateData);
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

  async function handleOfficialSync() {
    setSyncingOfficial(true);
    setMessage(null);
    try {
      const result = await syncPassengerOfficialSources();
      const period = result?.target_period;
      setMessage(
        `Fuentes oficiales sincronizadas: ${period?.month_name || ""} ${period?.official_year_used || ""}. Perfil colombiano/extranjero actualizado.`
      );
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo sincronizar Migracion/Aerocivil.");
    } finally {
      setSyncingOfficial(false);
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

  async function handleExposureRecalculate() {
    setRecalculatingExposure(true);
    setMessage(null);
    try {
      const period = summary?.commercial_exposure.period;
      const result = await recalculatePassengerExposure(period);
      setMessage(`Exposicion comercial recalculada para ${result.period.year}-${String(result.period.month).padStart(2, "0")}.`);
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo recalcular la exposicion comercial.");
    } finally {
      setRecalculatingExposure(false);
    }
  }

  async function handleRecalculateAll() {
    setRecalculatingAll(true);
    setMessage(null);
    try {
      const payload: { date_from?: string; date_to?: string; direction?: string } = {};
      if (dateFrom) payload.date_from = dateFrom;
      if (dateTo) payload.date_to = dateTo;
      if (direction) payload.direction = direction;

      const result = await recalculatePassengerAll(payload);
      setMessage(
        `Calculo guardado: ${formatNumber(result.exposure.periods_calculated)} meses de exposicion y ${formatNumber(result.estimates.processed)} vuelos estimados.`
      );
      await load();
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "No se pudo recalcular todo Passenger Intelligence.");
    } finally {
      setRecalculatingAll(false);
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

  function handleDateToChange(value: string) {
    setDateTo(value);
  }

  const directionData = useMemo(
    () =>
      summary?.by_direction.map((item) => ({
        ...item,
        label: directionLabels[item.direction] || item.direction,
      })) || [],
    [summary]
  );

  const exposureRates = summary?.commercial_exposure.rates || [];
  const totalExposure = exposureRates.find((item) => item.direction === "total");
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
        exposurePct: number | null;
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
          exposurePct: null,
        };

        if (fact.fact_type === "skyfree_commercial_observed_pax") {
          current.skyfreePax = Number(fact.value || 0);
        }

        if (fact.fact_type === "airport_official_international_pax") {
          current.officialPax = Number(fact.value || 0);
        }

        current.difference = current.skyfreePax - current.officialPax;
        current.exposurePct = current.officialPax > 0 ? Number(((current.skyfreePax / current.officialPax) * 100).toFixed(1)) : null;
        rows.set(period, current);
      });

    return Array.from(rows.values()).sort((a, b) => (a.year === b.year ? a.month - b.month : a.year - b.year));
  }, [monthlyFacts]);
  const selectedMonthComparison = selectedMonth
    ? monthlyComparisonData.find((row) => row.period === selectedMonth)
    : null;
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
        highConfidence: number;
        mediumConfidence: number;
        lowConfidence: number;
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
        highConfidence: 0,
        mediumConfidence: 0,
        lowConfidence: 0,
      };

      current.flights += Number(item.flights || 0);
      current.commercialPax += Number(item.commercial_exposed_pax || 0);
      current.colombianPax += Number(item.colombian_pax || 0);
      current.foreignPax += Number(item.foreign_pax || 0);
      current.highConfidence += Number(item.high_confidence || 0);
      current.mediumConfidence += Number(item.medium_confidence || 0);
      current.lowConfidence += Number(item.low_confidence || 0);
      current.colombianPct = current.commercialPax > 0 ? Number(((current.colombianPax / current.commercialPax) * 100).toFixed(1)) : 0;
      current.foreignPct = current.commercialPax > 0 ? Number(((current.foreignPax / current.commercialPax) * 100).toFixed(1)) : 0;
      rows.set(item.period, current);
    });

    return Array.from(rows.values()).sort((a, b) => (a.year === b.year ? a.month - b.month : a.year - b.year));
  }, [monthlyEstimates]);
  const selectedNationalityMonth = selectedMonth
    ? nationalityMonthlyData.find((row) => row.period === selectedMonth)
    : null;

  return (
    <div className="space-y-6 pb-10">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p className="text-sm font-bold uppercase tracking-wide text-primary">Passenger Intelligence</p>
          <h1 className="mt-1 text-3xl font-black text-slate-950">Flujo internacional MDE</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Flujo operativo por vuelos internacionales y composicion colombiano/extranjero con trazabilidad de fuente.
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
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
          />
          <input
            type="date"
            value={dateTo}
            onChange={(event) => handleDateToChange(event.target.value)}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          <select
            value={direction}
            onChange={(event) => setDirection(event.target.value)}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            <option value="">Total</option>
            <option value="departure">Salidas</option>
            <option value="arrival">Llegadas</option>
          </select>
          <button
            type="button"
            onClick={load}
            className="inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white"
          >
            <RefreshCw className="h-4 w-4" />
            Actualizar
          </button>
        </div>
      </div>

      {message && (
        <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm font-semibold text-slate-700 shadow-sm">
          {message}
        </div>
      )}

      {loading ? (
        <div className="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm font-semibold text-slate-500 shadow-sm">
          Cargando datos...
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <Kpi
              title="PAX internos"
              value={formatNumber(summary?.summary.total_pax)}
              sub={`${formatNumber(summary?.summary.observed_pax)} observados · ${formatNumber(summary?.summary.total_flights)} vuelos`}
              icon={Users}
            />
            <Kpi title="Colombianos est." value={formatNumber(summary?.summary.colombian_pax)} sub={formatPct(summary?.summary.colombian_pct)} icon={CheckCircle2} />
            <Kpi title="Extranjeros est." value={formatNumber(summary?.summary.foreign_pax)} sub={formatPct(summary?.summary.foreign_pct)} icon={Users} />
            <Kpi title="Promedio diario" value={formatNumber(summary?.summary.avg_pax_per_day)} sub={`${formatNumber(summary?.summary.days)} dias`} icon={BarChart3} />
            <Kpi title="Exposición comercial" value={formatPct(totalExposure?.exposure_pct)} sub="Sky Free vs Aerocivil" icon={Percent} />
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
                    Fuente: {summary.composition.source_name} · Metodo: {summary.composition.method} · Confianza: {summary.composition.confidence_level}
                  </p>
                )}
              </div>
              {canManage && (
                <button
                  type="button"
                  onClick={handleOfficialSync}
                  disabled={syncingOfficial}
                  className="inline-flex shrink-0 items-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60"
                >
                  <RefreshCw className="h-4 w-4" />
                  {syncingOfficial ? "Sincronizando" : "Sincronizar fuentes"}
                </button>
              )}
            </div>
          </div>

          <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-lg font-black text-slate-900">Exposición comercial Sky Free</h2>
                <p className="mt-1 text-sm leading-6 text-slate-600">
                  Compara los PAX observados internos contra el total internacional oficial de Aerocivil para estimar que porcentaje del aeropuerto pasa por el flujo comercial.
                </p>
              </div>
              {canManage && (
                <div className="flex shrink-0 flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={handleExposureRecalculate}
                    disabled={recalculatingExposure || recalculatingAll}
                    className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 disabled:opacity-60"
                  >
                    <RefreshCw className="h-4 w-4" />
                    {recalculatingExposure ? "Calculando..." : "Solo exposicion"}
                  </button>
                  <button
                    type="button"
                    onClick={handleRecalculateAll}
                    disabled={recalculatingAll || recalculatingExposure}
                    className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                  >
                    <RefreshCw className="h-4 w-4" />
                    {recalculatingAll ? "Guardando..." : "Recalcular todo"}
                  </button>
                </div>
              )}
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
              {["total", "departure", "arrival"].map((key) => {
                const rate = exposureRates.find((item) => item.direction === key);
                return (
                  <div key={key} className="rounded-md border border-slate-200 p-3">
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-sm font-black text-slate-900">{directionLabels[key]}</p>
                      <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{rate?.confidence_level || "SIN DATO"}</span>
                    </div>
                    <p className="mt-2 text-2xl font-black text-slate-950">{formatPct(rate?.exposure_pct)}</p>
                    <p className="mt-1 text-xs leading-5 text-slate-500">
                      {formatNumber(rate?.commercial_pax)} PAX Sky Free / {formatNumber(rate?.official_airport_pax)} PAX Aerocivil
                    </p>
                  </div>
                );
              })}
            </div>
          </section>

          <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <h2 className="text-lg font-black text-slate-900">Colombianos / extranjeros por mes</h2>
                <p className="mt-1 text-sm text-slate-600">Porcentaje estimado aplicado sobre PAX observado de Sky Free.</p>
              </div>
              <span className="text-xs font-bold uppercase tracking-wide text-slate-400">
                {direction ? directionLabels[direction] : "Total llegadas + salidas"}
              </span>
            </div>

            {selectedMonth && (
              <div className="mb-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                {selectedNationalityMonth ? (
                  <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Mes</p>
                      <p className="mt-1 font-black text-slate-900">{selectedNationalityMonth.period}</p>
                    </div>
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">PAX observado</p>
                      <p className="mt-1 font-black text-slate-900">{formatNumber(selectedNationalityMonth.commercialPax)}</p>
                    </div>
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Colombianos</p>
                      <p className="mt-1 font-black text-slate-900">
                        {formatPct(selectedNationalityMonth.colombianPct)} · {formatNumber(selectedNationalityMonth.colombianPax)}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Extranjeros</p>
                      <p className="mt-1 font-black text-slate-900">
                        {formatPct(selectedNationalityMonth.foreignPct)} · {formatNumber(selectedNationalityMonth.foreignPax)}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Vuelos</p>
                      <p className="mt-1 font-black text-slate-900">{formatNumber(selectedNationalityMonth.flights)}</p>
                    </div>
                  </div>
                ) : (
                  <p className="text-sm font-semibold text-slate-600">No hay composicion estimada guardada para {selectedMonth}.</p>
                )}
              </div>
            )}

            {nationalityMonthlyData.length > 0 ? (
              <>
                <div className="h-80">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={nationalityMonthlyData} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                      <XAxis dataKey="period" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} domain={[0, 100]} tickFormatter={(value) => `${value}%`} />
                      <Tooltip formatter={tooltipPercent} />
                      <Legend />
                      <Bar dataKey="colombianPct" stackId="nationality" fill="#0f766e" name="Colombianos" radius={[0, 0, 0, 0]} />
                      <Bar dataKey="foreignPct" stackId="nationality" fill="#7c3aed" name="Extranjeros" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
                <div className="mt-4 overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Mes</th>
                        <th className="px-3 py-2 text-right">PAX</th>
                        <th className="px-3 py-2 text-right">% Colombianos</th>
                        <th className="px-3 py-2 text-right">% Extranjeros</th>
                        <th className="px-3 py-2 text-right">Vuelos</th>
                        <th className="px-3 py-2 text-right">Confianza media</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {nationalityMonthlyData.map((row) => (
                        <tr key={row.period}>
                          <td className="px-3 py-2 font-bold text-slate-900">{row.period}</td>
                          <td className="px-3 py-2 text-right">{formatNumber(row.commercialPax)}</td>
                          <td className="px-3 py-2 text-right font-bold text-teal-700">{formatPct(row.colombianPct)}</td>
                          <td className="px-3 py-2 text-right font-bold text-violet-700">{formatPct(row.foreignPct)}</td>
                          <td className="px-3 py-2 text-right">{formatNumber(row.flights)}</td>
                          <td className="px-3 py-2 text-right">{formatNumber(row.mediumConfidence)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            ) : (
              <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-8 text-center text-sm text-slate-600">
                Todavia no hay estimaciones mensuales guardadas.
              </div>
            )}
          </section>

          <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <h2 className="text-lg font-black text-slate-900">Comparativo mensual</h2>
                <p className="mt-1 text-sm text-slate-600">Sky Free observado frente a Aerocivil oficial.</p>
              </div>
              <span className="text-xs font-bold uppercase tracking-wide text-slate-400">MDE · Total internacional</span>
            </div>
            {selectedMonth && (
              <div className="mb-4 rounded-md border border-slate-200 bg-slate-50 p-3">
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
                      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Exposición</p>
                      <p className="mt-1 font-black text-slate-900">{formatPct(selectedMonthComparison.exposurePct)}</p>
                    </div>
                  </div>
                ) : (
                  <p className="text-sm font-semibold text-slate-600">No hay comparativo guardado para {selectedMonth}.</p>
                )}
              </div>
            )}
            {monthlyComparisonData.length > 0 ? (
              <>
                <div className="h-80">
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
                <div className="mt-4 grid grid-cols-1 gap-2 md:grid-cols-3">
                  {monthlyComparisonData.slice(-3).map((row) => (
                    <div key={row.period} className="rounded-md border border-slate-200 p-3">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-black text-slate-900">{row.period}</p>
                        <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{formatPct(row.exposurePct)}</span>
                      </div>
                      <p className="mt-1 text-xs leading-5 text-slate-500">
                        Diferencia: {formatNumber(row.difference)} PAX
                      </p>
                    </div>
                  ))}
                </div>
              </>
            ) : (
              <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-8 text-center text-sm text-slate-600">
                Todavia no hay meses comparables guardados.
              </div>
            )}
          </section>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1.5fr_1fr]">
            <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-lg font-black text-slate-900">Flujo por hora</h2>
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
              <h2 className="mb-4 text-lg font-black text-slate-900">Llegadas / Salidas</h2>
              <div className="h-80">
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
              <h2 className="mb-4 text-lg font-black text-slate-900">Aerolíneas principales</h2>
              <div className="space-y-3">
                {summary?.airlines.map((item) => (
                  <div key={item.airline}>
                    <div className="mb-1 flex justify-between text-sm">
                      <span className="font-bold text-slate-800">{item.airline}</span>
                      <span className="text-slate-500">{formatNumber(item.pax)} PAX</span>
                    </div>
                    <div className="h-2 rounded-full bg-slate-100">
                      <div
                        className="h-2 rounded-full bg-teal-700"
                        style={{ width: `${Math.min(100, (item.pax / Math.max(summary.summary.total_pax, 1)) * 100)}%` }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <h2 className="mb-4 text-lg font-black text-slate-900">Rutas principales</h2>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {summary?.routes.map((item) => (
                  <div key={`${item.route}-${item.direction}`} className="rounded-md border border-slate-200 p-3">
                    <div className="flex items-center justify-between gap-2">
                      <p className="font-black text-slate-900">{item.route}</p>
                      {item.direction === "arrival" ? <PlaneLanding className="h-4 w-4 text-blue-700" /> : <PlaneTakeoff className="h-4 w-4 text-teal-700" />}
                    </div>
                    <p className="mt-1 text-sm text-slate-500">{formatNumber(item.pax)} PAX · {formatNumber(item.flights)} vuelos</p>
                  </div>
                ))}
              </div>
            </section>
          </div>

          <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-lg font-black text-slate-900">Vuelos recientes importados</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Fecha</th>
                    <th className="px-3 py-2">Hora</th>
                    <th className="px-3 py-2">Tipo</th>
                    <th className="px-3 py-2">Vuelo</th>
                    <th className="px-3 py-2">Aerolínea</th>
                    <th className="px-3 py-2">Ruta</th>
                    <th className="px-3 py-2 text-right">PAX</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {summary?.latest_flights.map((flight, index) => (
                    <tr key={`${flight.date}-${flight.time}-${flight.flight_code}-${index}`}>
                      <td className="px-3 py-2 text-slate-600">{flight.date}</td>
                      <td className="px-3 py-2 font-bold text-slate-900">{flight.time}</td>
                      <td className="px-3 py-2">{directionLabels[flight.direction] || flight.direction}</td>
                      <td className="px-3 py-2 font-bold text-slate-900">{flight.flight_code}</td>
                      <td className="px-3 py-2">{flight.airline}</td>
                      <td className="px-3 py-2">{flight.origin || "MDE"} - {flight.destination || "MDE"}</td>
                      <td className="px-3 py-2 text-right font-bold">{formatNumber(flight.pax)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
            {canImport && (
              <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 className="mb-4 text-lg font-black text-slate-900">Importar Excel PAX</h2>
                <label className="flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 p-8 text-center transition hover:bg-slate-100">
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
                        <p className="text-xs text-slate-500">{batch.period_start} - {batch.period_end}</p>
                      </div>
                      <span className="font-bold text-slate-700">{formatNumber(batch.total_pax)} PAX</span>
                    </div>
                  ))}
                </div>
              </section>
            )}

            <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h2 className="text-lg font-black text-slate-900">OneDrive PAX Col</h2>
                  <p className="mt-1 text-sm leading-6 text-slate-600">
                    Archivos detectados en la carpeta interna de PAX comerciales para usarlos como fuente observada.
                  </p>
                </div>
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
                  sourceFiles.slice(0, 5).map((file) => (
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

            <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <h2 className="text-lg font-black text-slate-900">Fuentes oficiales</h2>
                  <p className="mt-1 text-sm leading-6 text-slate-600">
                    El porcentaje colombiano/extranjero se calcula automaticamente cruzando Migracion Colombia con Aerocivil.
                  </p>
                </div>
                {canManage && (
                  <button
                    type="button"
                    onClick={handleOfficialSync}
                    disabled={syncingOfficial}
                    className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                  >
                    <RefreshCw className="h-4 w-4" />
                    {syncingOfficial ? "Sincronizando..." : "Sincronizar ahora"}
                  </button>
                )}
              </div>

              <div className="mt-4 space-y-2">
                {profiles.length === 0 ? (
                  <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Aun no hay perfil oficial guardado. El resumen intentara generarlo automaticamente al cargar.
                  </div>
                ) : (
                  profiles.slice(0, 5).map((profile) => (
                    <div key={profile.id} className="rounded-md border border-slate-200 px-3 py-2 text-sm">
                      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <p className="font-bold text-slate-900">{profile.name}</p>
                        <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{profile.confidence_level}</span>
                      </div>
                      <p className="mt-1 text-slate-500">
                        {formatPct(profile.colombian_pct)} colombianos · {formatPct(profile.foreign_pct)} extranjeros · {profile.method}
                      </p>
                      <p className="mt-1 text-xs text-slate-400">
                        Fuente: {profile.source_name} · Vigencia: {profile.valid_from || "sin inicio"} - {profile.valid_to || "sin fin"}
                      </p>
                    </div>
                  ))
                )}
              </div>
            </section>
          </div>

          <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="mb-4 text-lg font-black text-slate-900">Hechos mensuales auditables</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Periodo</th>
                    <th className="px-3 py-2">Tipo</th>
                    <th className="px-3 py-2">Flujo</th>
                    <th className="px-3 py-2">Fuente</th>
                    <th className="px-3 py-2 text-right">Valor</th>
                    <th className="px-3 py-2">Confianza</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {monthlyFacts.slice(0, 12).map((fact) => (
                    <tr key={fact.id}>
                      <td className="px-3 py-2 font-bold text-slate-900">
                        {fact.year}-{String(fact.month).padStart(2, "0")}
                      </td>
                      <td className="px-3 py-2 text-slate-600">{fact.fact_type}</td>
                      <td className="px-3 py-2">{directionLabels[fact.direction] || fact.direction}</td>
                      <td className="px-3 py-2 text-slate-600">{fact.source_type}</td>
                      <td className="px-3 py-2 text-right font-bold">{formatNumber(fact.value)}</td>
                      <td className="px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-500">{fact.confidence_level}</td>
                    </tr>
                  ))}
                  {monthlyFacts.length === 0 && (
                    <tr>
                      <td className="px-3 py-6 text-center text-slate-500" colSpan={6}>
                        Todavia no hay hechos mensuales generados.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}
    </div>
  );
}
