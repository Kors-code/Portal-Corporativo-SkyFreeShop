import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  BarChart3,
  CheckCircle2,
  Database,
  FileUp,
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
  getPassengerProfiles,
  getPassengerSummary,
  importPassengerExcel,
  syncPassengerOfficialSources,
  type PassengerBatch,
  type PassengerCompositionProfile,
  type PassengerSummaryResponse,
} from "../services/passengerIntelligenceService";

const number = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });
const decimal = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });

const directionLabels: Record<string, string> = {
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
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [syncingOfficial, setSyncingOfficial] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [direction, setDirection] = useState("");
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

      const [summaryData, batchesData, profilesData] = await Promise.all([
        getPassengerSummary(params),
        getPassengerBatches(),
        getPassengerProfiles(),
      ]);

      setSummary(summaryData);
      setBatches(batchesData);
      setProfiles(profilesData);
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

  const directionData = useMemo(
    () =>
      summary?.by_direction.map((item) => ({
        ...item,
        label: directionLabels[item.direction] || item.direction,
      })) || [],
    [summary]
  );

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
            type="date"
            value={dateFrom}
            onChange={(event) => setDateFrom(event.target.value)}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          />
          <input
            type="date"
            value={dateTo}
            onChange={(event) => setDateTo(event.target.value)}
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
            <Kpi title="PAX internacionales" value={formatNumber(summary?.summary.total_pax)} sub={`${formatNumber(summary?.summary.total_flights)} vuelos`} icon={Users} />
            <Kpi title="Colombianos est." value={formatNumber(summary?.summary.colombian_pax)} sub={formatPct(summary?.summary.colombian_pct)} icon={CheckCircle2} />
            <Kpi title="Extranjeros est." value={formatNumber(summary?.summary.foreign_pax)} sub={formatPct(summary?.summary.foreign_pct)} icon={Users} />
            <Kpi title="Promedio diario" value={formatNumber(summary?.summary.avg_pax_per_day)} sub={`${formatNumber(summary?.summary.days)} dias`} icon={BarChart3} />
            <Kpi title="PAX por vuelo" value={formatNumber(summary?.summary.avg_pax_per_flight)} sub="Promedio operativo" icon={Database} />
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

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
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
        </>
      )}
    </div>
  );
}
