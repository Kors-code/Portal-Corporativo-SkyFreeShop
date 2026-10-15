import { useEffect, useState } from "react";
import { ArrowLeft, BadgeDollarSign, CalendarDays, CheckCircle2, Clock3, RefreshCw, Ticket, TrendingUp } from "lucide-react";
import {
  getMyProfileCommissions,
  type MyProfileCommissions,
} from "../services/commissionProfilesService";

type ProfileRow = MyProfileCommissions["rows"][number];
type RuleRow = NonNullable<ProfileRow["rules"]>[number];

const usd = (value: number | null | undefined) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(Number(value || 0));

const cop = (value: number | null | undefined) =>
  new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 }).format(Number(value || 0));

const number = (value: number | null | undefined) =>
  new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 }).format(Number(value || 0));

function ruleTitle(rule: RuleRow) {
  return [
    rule.provider_name && `Proveedor ${rule.provider_name}`,
    rule.brand && `Marca ${rule.brand}`,
    rule.category_code && `${rule.category_name || "Categoria"} (${rule.category_code})`,
    rule.product_code && `SKU ${rule.product_code}`,
  ].filter(Boolean).join(" + ") || "Regla de comision";
}

function RuleBreakdown({ rule, index }: { rule: RuleRow; index: number }) {
  const fulfillment = rule.fulfillment_pct;
  const progress = fulfillment === null || fulfillment === undefined ? 0 : Math.min(Number(fulfillment), 120) / 120 * 100;
  const color = Number(fulfillment || 0) >= 100 ? "bg-emerald-600" : Number(fulfillment || 0) >= 80 ? "bg-amber-500" : "bg-primary";

  return (
    <div className="border-t border-slate-200 py-5 first:border-t-0">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-bold uppercase text-slate-500">Regla {index + 1}</p>
          <h4 className="mt-1 break-words text-sm font-bold text-slate-900">{ruleTitle(rule)}</h4>
        </div>
        <span className={`text-sm font-bold ${rule.eligible ? "text-emerald-700" : "text-slate-500"}`}>
          {rule.eligible ? "Comisiona" : "Sin comision"}
        </span>
      </div>

      <div className="mt-4 grid gap-4 sm:grid-cols-3">
        <div><p className="text-xs text-slate-500">Ventas</p><p className="text-lg font-bold text-slate-900">{usd(rule.sales_usd)}</p></div>
        <div><p className="text-xs text-slate-500">Meta</p><p className="text-lg font-bold text-slate-900">{usd(rule.target_usd)}</p></div>
        <div><p className="text-xs text-slate-500">Comision</p><p className="text-lg font-bold text-primary">{usd(rule.commission_usd)}</p></div>
      </div>

      <div className="mt-4 flex items-center justify-between text-sm">
        <span className="font-semibold text-slate-600">Cumplimiento</span>
        <strong className="text-slate-900">{fulfillment == null ? "Sin meta" : `${number(fulfillment)}%`}</strong>
      </div>
      <div className="relative mt-2 h-3 overflow-hidden rounded-sm bg-slate-100" role="img" aria-label={`Cumplimiento ${number(fulfillment)} por ciento`}>
        <div className={`h-full ${color}`} style={{ width: `${progress}%` }} />
        <span className="absolute inset-y-0 left-2/3 w-px bg-white" />
        <span className="absolute inset-y-0 left-5/6 w-px bg-white" />
      </div>
      <div className="mt-1 flex justify-between text-xs text-slate-400"><span>0%</span><span>80%</span><span>100%</span><span>120%</span></div>

      <p className="mt-3 text-xs text-slate-500">
        {number(rule.sales_count)} tickets · {number(rule.units)} unidades · {number(rule.assigned_turns)} turnos
        {rule.target_turns ? ` / ${number(rule.target_turns)} base` : ""} · {number(rule.applied_commission_pct)}% aplicado
      </p>
    </div>
  );
}

export default function MyProfileCommissionsPage() {
  const [data, setData] = useState<MyProfileCommissions | null>(null);
  const [selectedProfileIndex, setSelectedProfileIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load(budgetId?: number) {
    setLoading(true);
    setError(null);
    try {
      const result = await getMyProfileCommissions(budgetId);
      setData(result);
      setSelectedProfileIndex(0);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudieron cargar tus comisiones.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void load(); }, []);

  const budget = data?.budgets.find((item) => item.id === data.selected_budget_id);
  const profile = data?.rows[selectedProfileIndex];
  const closed = data?.source === "snapshot";
  const today = new Date().toLocaleDateString("en-CA", { timeZone: "America/Bogota" });
  const statusLabel = closed ? "Cerrado" : budget && today > budget.end_date ? "Por cerrar" : "En curso";

  return (
    <div className="mx-auto w-full max-w-6xl space-y-5 pb-10">
      <header className="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <a href="/welcome" className="mb-4 inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"><ArrowLeft className="h-4 w-4" />Volver</a>
          <p className="text-xs font-bold uppercase text-primary">Comisiones</p>
          <h1 className="mt-1 text-2xl font-black text-slate-950">Mis perfiles</h1>
        </div>
        <div className="flex w-full gap-2 sm:w-auto">
          <label className="min-w-0 flex-1 sm:w-80">
            <span className="sr-only">Presupuesto</span>
            <select
              value={data?.selected_budget_id ?? ""}
              onChange={(event) => void load(Number(event.target.value))}
              disabled={loading || !data?.budgets.length}
              className="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 disabled:opacity-60"
            >
              {!data?.budgets.length && <option value="">Sin presupuestos</option>}
              {data?.budgets.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </label>
          <button type="button" onClick={() => void load(data?.selected_budget_id ?? undefined)} disabled={loading} title="Actualizar" aria-label="Actualizar" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 disabled:opacity-60"><RefreshCw className="h-4 w-4" /></button>
        </div>
      </header>

      {error && <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}
      {loading && !data && <p className="py-12 text-center text-sm text-slate-500">Cargando comisiones...</p>}
      {!loading && data && !data.budgets.length && <div className="border border-slate-200 bg-white px-5 py-12 text-center text-sm text-slate-600">Todavia no tienes perfiles de comision asociados a un presupuesto.</div>}

      {data && budget && (
        <>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
            <span className="inline-flex items-center gap-2"><CalendarDays className="h-4 w-4" />{budget.start_date} al {budget.end_date}</span>
            <span className={`inline-flex items-center gap-1.5 font-bold ${closed ? "text-emerald-700" : "text-sky-700"}`}>
              {closed ? <CheckCircle2 className="h-4 w-4" /> : <Clock3 className="h-4 w-4" />}
              {statusLabel}
            </span>
          </div>

          <section className="grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-4" aria-label="Totales del presupuesto">
            <div className="bg-white p-4"><p className="text-xs font-bold uppercase text-slate-500">Ventas</p><p className="mt-2 text-xl font-black text-slate-950">{usd(data.totals.sales_usd)}</p><p className="text-xs text-slate-500">{cop(data.totals.sales_cop)}</p></div>
            <div className="bg-white p-4"><p className="text-xs font-bold uppercase text-slate-500">Meta</p><p className="mt-2 text-xl font-black text-slate-950">{usd(data.totals.target_usd)}</p></div>
            <div className="bg-white p-4"><p className="inline-flex items-center gap-1 text-xs font-bold uppercase text-slate-500"><Ticket className="h-3.5 w-3.5" />Tickets</p><p className="mt-2 text-xl font-black text-slate-950">{number(data.totals.sales_count)}</p></div>
            <div className="bg-white p-4"><p className="inline-flex items-center gap-1 text-xs font-bold uppercase text-slate-500"><BadgeDollarSign className="h-3.5 w-3.5" />Comision</p><p className="mt-2 text-xl font-black text-primary">{usd(data.totals.commission_usd)}</p><p className="text-xs text-slate-500">{cop(data.totals.commission_cop)}</p></div>
          </section>

          {data.rows.length === 0 ? (
            <div className="border-t border-slate-200 py-10 text-sm text-slate-600">No hay comisiones registradas para este presupuesto.</div>
          ) : (
            <section>
              <h2 className="mb-3 text-base font-black text-slate-950">Mis perfiles</h2>
              <div className="flex gap-2 overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Perfiles de comision">
                {data.rows.map((row, index) => (
                  <button
                    key={`${row.profile_id ?? "historico"}-${index}`}
                    type="button"
                    role="tab"
                    aria-selected={selectedProfileIndex === index}
                    onClick={() => setSelectedProfileIndex(index)}
                    className={`min-w-0 shrink-0 border-b-2 px-3 py-3 text-left text-sm font-semibold ${selectedProfileIndex === index ? "border-primary text-primary" : "border-transparent text-slate-600 hover:text-slate-900"}`}
                  >
                    {row.profile_name}
                  </button>
                ))}
              </div>

              {profile && <div className="pt-5" role="tabpanel">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div><p className="text-xs font-bold uppercase text-slate-500">{profile.profile_type?.replaceAll("_", " ")}</p><h3 className="mt-1 text-lg font-black text-slate-950">{profile.profile_name}</h3></div>
                  <p className="text-lg font-black text-primary">{usd(profile.commission_usd)}</p>
                </div>
                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-y border-slate-200 py-3 text-sm text-slate-700">
                  <span><strong>Ventas:</strong> {usd(profile.sales_usd)}</span>
                  <span><strong>Meta:</strong> {usd(profile.target_usd)}</span>
                  <span className="inline-flex items-center gap-1"><TrendingUp className="h-4 w-4" /><strong>Cumplimiento:</strong> {profile.fulfillment_pct == null ? "Sin meta" : `${number(profile.fulfillment_pct)}%`}</span>
                </div>
                <div className="mt-2">{profile.rules?.map((rule, index) => <RuleBreakdown key={`${rule.rule_id ?? index}-${index}`} rule={rule} index={index} />)}</div>
              </div>}
            </section>
          )}
        </>
      )}
    </div>
  );
}
