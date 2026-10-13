import { useEffect, useMemo, useState } from "react";
import type { CSSProperties, ReactNode } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  Award,
  BadgeCheck,
  BadgeDollarSign,
  ChevronRight,
  Download,
  Gauge,
  Package,
  RefreshCw,
  Search,
  Sparkles,
  Users,
  Wallet,
  X,
} from "lucide-react";
import api from "../../../api/axios";
import {
  commissionProfileEarnersExportUrl,
  getCommissionProfileEarners,
  getCommissionProfiles,
  type CommissionProfile,
  type CommissionProfileSummary,
} from "../services/commissionProfilesService";

const moneyUsd = (value: number) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(Number(value || 0));

const moneyCop = (value: number) =>
  new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 }).format(Number(value || 0));

const num = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });
const trmNum = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 2 });

const palette = ["#840028", "#0f766e", "#2563eb", "#ca8a04", "#7c3aed", "#0891b2", "#16a34a", "#be123c"];

type EarnerRule = NonNullable<CommissionProfileSummary["rows"][number]["rules"]>[number];

type EarnerRow = CommissionProfileSummary["rows"][number] & {
  profile_id: number;
  profile_name: string;
  profile_type: string;
};

function rowKey(row: EarnerRow) {
  return `${row.profile_id}-${row.user_id}`;
}

function ruleLabel(rule: EarnerRule) {
  const categoryName = rule.category_name || (rule.category_code ? `Categoria ${rule.category_code}` : "Categoria ?");
  const categoryLabel = rule.category_code ? `${categoryName} (Cat. ${rule.category_code})` : categoryName;
  const parts = [
    rule.provider_name ? `Prov. ${rule.provider_name}` : null,
    rule.brand ? `Marca ${rule.brand}` : null,
    rule.category_code ? categoryLabel : null,
    rule.product_code ? `SKU ${rule.product_code}` : null,
  ].filter(Boolean);

  return parts.join(" + ") || "Regla sin filtros";
}

function fulfillmentColor(pct: number) {
  if (pct >= 120) return "#16a34a";
  if (pct >= 100) return "#0f766e";
  if (pct >= 80) return "#ca8a04";
  return "#dc2626";
}

export default function CommissionProfileEarnersPage() {
  const [budgets, setBudgets] = useState<any[]>([]);
  const [profiles, setProfiles] = useState<CommissionProfile[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [profileId, setProfileId] = useState<number | null>(null);
  const [summary, setSummary] = useState<CommissionProfileSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [selectedKey, setSelectedKey] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<"personas" | "resumen">("personas");

  const allRows = (summary?.rows ?? []) as EarnerRow[];

  const filteredRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return allRows;

    return allRows.filter((row) =>
      [row.profile_name, row.user_name, row.seller_code]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term))
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [summary, search]);

  const rankedRows = useMemo(
    () => [...filteredRows].sort((a, b) => (b.commission_usd || 0) - (a.commission_usd || 0)),
    [filteredRows]
  );

  const selectedRow = useMemo(
    () => filteredRows.find((row) => rowKey(row) === selectedKey) ?? null,
    [filteredRows, selectedKey]
  );

  const avgFulfillment = useMemo(() => {
    const withTarget = filteredRows.filter((row) => row.fulfillment_pct != null);
    if (!withTarget.length) return null;
    return withTarget.reduce((sum, row) => sum + Number(row.fulfillment_pct || 0), 0) / withTarget.length;
  }, [filteredRows]);

  const eligibleCount = filteredRows.filter((row) => row.eligible).length;

  async function loadInitial() {
    setLoading(true);
    setError(null);
    try {
      const [budgetRes, profileRows] = await Promise.all([api.get("/budgets"), getCommissionProfiles()]);
      setBudgets(budgetRes.data || []);
      setProfiles(profileRows);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudo cargar la vista.");
    } finally {
      setLoading(false);
    }
  }

  async function loadRows() {
    setLoading(true);
    setError(null);
    try {
      setSummary(await getCommissionProfileEarners({ budget_id: budgetId, profile_id: profileId }));
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudo cargar quienes comisionan.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadInitial();
  }, []);

  useEffect(() => {
    loadRows();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId, profileId]);

  useEffect(() => {
    if (selectedKey && !filteredRows.some((row) => rowKey(row) === selectedKey)) {
      setSelectedKey(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredRows]);

  return (
    <div className="mx-auto w-full max-w-7xl space-y-5 pb-10 text-slate-950">
      <section className="grid gap-4 xl:grid-cols-[280px_minmax(0,1fr)]">
        <BudgetSidebar budgets={budgets} budgetId={budgetId} onSelect={setBudgetId} loading={loading && !summary} />

        <main className="min-w-0 space-y-4">
          <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
              <div>
                <p className="text-xs font-black uppercase tracking-wide text-primary">Perfiles de comision</p>
                <h1 className="mt-1 text-2xl font-black leading-tight text-slate-950">Asignados y comisiones</h1>
                <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-500">
                  Revisa personas, reglas aplicadas, ventas, tickets y comisiones por cada presupuesto.
                </p>
              </div>

              <div className="grid gap-2 sm:grid-cols-[220px_140px_44px]">
                <select
                  value={profileId ?? ""}
                  onChange={(event) => setProfileId(Number(event.target.value) || null)}
                  className="h-10 min-w-0 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700"
                >
                  <option value="">Todos los perfiles</option>
                  {profiles.map((profile) => (
                    <option key={profile.id} value={profile.id}>
                      {profile.name}
                    </option>
                  ))}
                </select>

                <a
                  href={commissionProfileEarnersExportUrl({ budget_id: budgetId, profile_id: profileId })}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-bold text-white shadow-sm hover:brightness-95"
                >
                  <Download className="h-4 w-4" />
                  Excel
                </a>

                <button
                  type="button"
                  onClick={loadRows}
                  className="inline-flex h-10 items-center justify-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50"
                  aria-label="Actualizar"
                >
                  <RefreshCw className="h-4 w-4" />
                </button>
              </div>
            </div>
          </section>

          {error && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
              {error}
            </div>
          )}

          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <Kpi icon={<Wallet size={18} />} label="Ventas totales" value={moneyUsd(summary?.totals.sales_usd ?? 0)} />
            <Kpi
              icon={<BadgeDollarSign size={18} />}
              label="Comision total"
              value={moneyUsd(summary?.totals.commission_usd ?? 0)}
              sub={
                summary?.totals.commission_cop
                  ? `${moneyCop(summary.totals.commission_cop)} COP${
                      summary.totals.avg_trm ? ` · TRM ${trmNum.format(summary.totals.avg_trm)}` : ""
                    }`
                  : undefined
              }
              valueClass="text-primary"
            />
            <Kpi icon={<Users size={18} />} label="Personas" value={String(filteredRows.length)} />
            <Kpi
              icon={<BadgeCheck size={18} />}
              label="Comisionando"
              value={`${eligibleCount} / ${filteredRows.length}`}
              valueClass="text-emerald-700"
            />
            <Kpi
              icon={<Gauge size={18} />}
              label="Cumplimiento prom."
              value={avgFulfillment == null ? "Sin meta" : `${num.format(avgFulfillment)}%`}
              valueClass={avgFulfillment == null ? "text-slate-400" : ""}
              valueStyle={avgFulfillment == null ? undefined : { color: fulfillmentColor(avgFulfillment) }}
            />
          </section>

          <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div className="inline-flex w-fit rounded-lg border border-slate-200 bg-slate-50 p-1">
                {[
                  ["personas", "Personas"],
                  ["resumen", "Resumen"],
                ].map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setViewMode(value as "personas" | "resumen")}
                    className={`h-9 rounded-md px-4 text-sm font-black transition ${
                      viewMode === value ? "bg-primary text-white shadow-sm" : "text-slate-600 hover:bg-white"
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>

              <div className="relative w-full lg:max-w-sm">
                <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Buscar persona, codigo o perfil"
                  className="h-10 w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 text-sm font-semibold text-slate-900"
                />
              </div>
            </div>
          </section>

          {viewMode === "personas" ? (
            <PeopleCards rows={rankedRows} loading={loading} onSelect={(row) => setSelectedKey(rowKey(row))} />
          ) : (
            <OverviewPanel rows={filteredRows} loading={loading} onSelect={(row) => setSelectedKey(rowKey(row))} />
          )}
        </main>
      </section>

      {selectedRow && (
        <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/45 px-4 py-6">
          <div className="max-h-[92vh] w-full max-w-6xl overflow-auto rounded-2xl bg-slate-50 p-4 shadow-2xl">
            <PersonDetail row={selectedRow} onClose={() => setSelectedKey(null)} />
          </div>
        </div>
      )}
    </div>
  );
}

function BudgetSidebar({
  budgets,
  budgetId,
  loading,
  onSelect,
}: {
  budgets: any[];
  budgetId: number | null;
  loading: boolean;
  onSelect: (id: number | null) => void;
}) {
  return (
    <aside className="h-fit rounded-xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-20">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-black uppercase tracking-wide text-primary">Presupuestos</p>
          <h2 className="text-lg font-black text-slate-950">Periodo</h2>
        </div>
        <span className="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-500">{budgets.length}</span>
      </div>

      <div className="space-y-2">
        <button
          type="button"
          onClick={() => onSelect(null)}
          className={`w-full rounded-lg border px-3 py-2 text-left transition ${
            budgetId == null ? "border-primary bg-primary text-white" : "border-slate-200 bg-slate-50 text-slate-700 hover:border-primary/40"
          }`}
        >
          <span className="block text-sm font-black">Todos los presupuestos</span>
          <span className={`mt-0.5 block text-xs font-semibold ${budgetId == null ? "text-white/75" : "text-slate-500"}`}>
            Vista consolidada
          </span>
        </button>

        <div className="max-h-[66vh] space-y-2 overflow-auto pr-1">
          {budgets.map((budget) => {
            const active = Number(budget.id) === Number(budgetId);
            return (
              <button
                key={budget.id}
                type="button"
                onClick={() => onSelect(Number(budget.id))}
                className={`w-full rounded-lg border px-3 py-2 text-left transition ${
                  active ? "border-primary bg-primary text-white" : "border-slate-200 bg-white text-slate-800 hover:border-primary/40"
                }`}
              >
                <span className="block truncate text-sm font-black">{budget.name}</span>
                <span className={`mt-0.5 block text-xs font-semibold ${active ? "text-white/75" : "text-slate-500"}`}>
                  {budget.start_date && budget.end_date ? `${budget.start_date} - ${budget.end_date}` : "Presupuesto"}
                </span>
              </button>
            );
          })}
          {loading && <div className="py-6 text-center text-sm font-bold text-slate-500">Cargando...</div>}
        </div>
      </div>
    </aside>
  );
}

function PeopleCards({
  rows,
  loading,
  onSelect,
}: {
  rows: EarnerRow[];
  loading: boolean;
  onSelect: (row: EarnerRow) => void;
}) {
  if (loading) {
    return (
      <section className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
        {Array.from({ length: 6 }).map((_, index) => (
          <div key={index} className="h-52 animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm" />
        ))}
      </section>
    );
  }

  if (rows.length === 0) {
    return (
      <section className="flex h-[360px] flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white shadow-sm">
        <Sparkles className="text-slate-300" size={32} />
        <p className="text-sm font-bold text-slate-500">No hay personas asignadas con estos filtros.</p>
      </section>
    );
  }

  return (
    <section className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
      {rows.map((row, index) => {
        const rules = row.rules ?? [];
        const fulfillment = row.fulfillment_pct;
        return (
          <button
            key={rowKey(row)}
            type="button"
            onClick={() => onSelect(row)}
            className="group flex min-h-[220px] flex-col rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="flex items-center gap-1.5">
                  {index === 0 && row.commission_usd > 0 && <Award size={15} className="shrink-0 text-amber-500" />}
                  <h3 className="truncate text-base font-black text-slate-950">{row.user_name || `Usuario ${row.user_id}`}</h3>
                </div>
                <p className="mt-1 truncate text-xs font-bold uppercase text-slate-400">
                  {row.profile_name} · {row.seller_code || "Sin codigo"}
                </p>
              </div>
              <ChevronRight size={18} className="shrink-0 text-slate-300 transition group-hover:text-primary" />
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
              {rules.slice(0, 3).map((rule, ruleIndex) => (
                <span
                  key={rule.rule_id ?? ruleIndex}
                  className="max-w-full truncate rounded-full bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-600"
                  title={ruleLabel(rule)}
                >
                  {ruleLabel(rule)}
                </span>
              ))}
              {rules.length > 3 && (
                <span className="rounded-full bg-primary/10 px-2 py-1 text-[11px] font-black text-primary">
                  +{rules.length - 3} reglas
                </span>
              )}
            </div>

            <div className="mt-4 grid grid-cols-2 gap-2 text-xs">
              <div className="rounded-lg bg-slate-50 p-2">
                <p className="font-black uppercase text-slate-400">Ventas</p>
                <p className="mt-1 font-black text-slate-950">{moneyUsd(row.sales_usd)}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-2">
                <p className="font-black uppercase text-slate-400">Tickets</p>
                <p className="mt-1 font-black text-slate-950">{num.format(row.sales_count)}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-2">
                <p className="font-black uppercase text-slate-400">Unidades</p>
                <p className="mt-1 font-black text-slate-950">{num.format(row.units)}</p>
              </div>
              <div className="rounded-lg bg-primary/10 p-2">
                <p className="font-black uppercase text-primary/70">Comision</p>
                <p className="mt-1 font-black text-primary">{moneyUsd(row.commission_usd)}</p>
              </div>
            </div>

            <div className="mt-auto pt-4">
              <div className="mb-1 flex items-center justify-between gap-2 text-xs font-black">
                <span className={row.eligible ? "text-emerald-700" : "text-amber-700"}>
                  {row.eligible ? "Comisiona" : "No aplica"}
                </span>
                <span className="text-slate-500">{fulfillment == null ? "Sin meta" : `${num.format(fulfillment)}%`}</span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div
                  className="h-full rounded-full"
                  style={{
                    width: `${(Math.min(fulfillment ?? 0, 130) / 130) * 100}%`,
                    backgroundColor: fulfillment == null ? "#cbd5e1" : fulfillmentColor(fulfillment),
                  }}
                />
              </div>
            </div>
          </button>
        );
      })}
    </section>
  );
}

function OverviewPanel({
  rows,
  loading,
  onSelect,
}: {
  rows: EarnerRow[];
  loading: boolean;
  onSelect: (row: EarnerRow) => void;
}) {
  const topEarners = useMemo(
    () =>
      [...rows]
        .sort((a, b) => (b.commission_usd || 0) - (a.commission_usd || 0))
        .slice(0, 8)
        .map((row) => ({ ...row, label: row.user_name || `Usuario ${row.user_id}` }))
        .reverse(),
    [rows]
  );

  const profileMix = useMemo(() => {
    const map = new Map<string, number>();
    rows.forEach((row) => {
      const key = String(row.profile_type || "otro").replaceAll("_", " ");
      map.set(key, (map.get(key) ?? 0) + Number(row.commission_usd || 0));
    });
    return Array.from(map.entries())
      .filter(([, value]) => value > 0)
      .map(([name, value]) => ({ name, value }));
  }, [rows]);

  if (loading) {
    return (
      <section className="flex h-[420px] items-center justify-center rounded-xl border border-slate-200 bg-white shadow-sm">
        <p className="text-sm font-bold text-slate-500">Cargando panorama general...</p>
      </section>
    );
  }

  if (rows.length === 0) {
    return (
      <section className="flex h-[420px] flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white shadow-sm">
        <Sparkles className="text-slate-300" size={32} />
        <p className="text-sm font-bold text-slate-500">No hay personas asignadas con estos filtros.</p>
      </section>
    );
  }

  return (
    <div className="space-y-4">
      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="mb-1 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
          <Sparkles size={15} className="text-primary" />
          Panorama general
        </div>
        <p className="mb-4 text-sm font-semibold text-slate-500">
          Elige una persona en la izquierda para ver su detalle completo con graficos.
        </p>

        <div className="grid gap-4 xl:grid-cols-[1.4fr_1fr]">
          <div>
            <h3 className="mb-2 text-sm font-black text-slate-800">Top comisiones</h3>
            <ResponsiveContainer width="100%" height={Math.max(240, topEarners.length * 40)}>
              <BarChart data={topEarners} layout="vertical" margin={{ top: 4, right: 24, left: 8, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => moneyUsd(Number(v))} />
                <YAxis type="category" dataKey="label" width={150} tick={{ fontSize: 11 }} />
                <Tooltip formatter={(value) => [moneyUsd(Number(value)), "Comision"]} />
                <Bar dataKey="commission_usd" name="Comision" fill="#840028" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>

          <div>
            <h3 className="mb-2 text-sm font-black text-slate-800">Comision por tipo de perfil</h3>
            <ResponsiveContainer width="100%" height={240}>
              <PieChart>
                <Pie data={profileMix} dataKey="value" nameKey="name" innerRadius="52%" outerRadius="82%" paddingAngle={2}>
                  {profileMix.map((_, index) => (
                    <Cell key={index} fill={palette[index % palette.length]} />
                  ))}
                </Pie>
                <Tooltip formatter={(value) => moneyUsd(Number(value))} />
              </PieChart>
            </ResponsiveContainer>
            <div className="mt-1 space-y-1.5">
              {profileMix.map((entry, index) => (
                <div key={entry.name} className="flex items-center justify-between gap-2 text-xs">
                  <span className="flex items-center gap-2 font-bold capitalize text-slate-700">
                    <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: palette[index % palette.length] }} />
                    {entry.name}
                  </span>
                  <span className="font-black text-slate-950">{moneyUsd(entry.value)}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h3 className="mb-3 text-sm font-black text-slate-800">Todas las personas</h3>
        <div className="overflow-x-auto rounded-lg border border-slate-200">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-2.5">Persona</th>
                <th className="px-3 py-2.5">Perfil</th>
                <th className="px-3 py-2.5 text-right">Ventas USD</th>
                <th className="px-3 py-2.5 text-right">Cumplimiento</th>
                <th className="px-3 py-2.5 text-right">Comision</th>
              </tr>
            </thead>
            <tbody>
              {[...rows]
                .sort((a, b) => (b.commission_usd || 0) - (a.commission_usd || 0))
                .map((row) => (
                  <tr
                    key={rowKey(row)}
                    onClick={() => onSelect(row)}
                    className="cursor-pointer border-t border-slate-100 hover:bg-slate-50"
                  >
                    <td className="px-3 py-2.5 font-bold text-slate-900">{row.user_name || `Usuario ${row.user_id}`}</td>
                    <td className="px-3 py-2.5 text-slate-600">
                      <div className="font-semibold text-slate-700">{String(row.profile_name)}</div>
                      <div className="mt-1 max-w-[320px] truncate text-xs text-slate-500">
                        {(row.rules ?? []).map(ruleLabel).join(" | ") || "Sin reglas"}
                      </div>
                    </td>
                    <td className="px-3 py-2.5 text-right">{moneyUsd(row.sales_usd)}</td>
                    <td className="px-3 py-2.5 text-right">
                      {row.fulfillment_pct == null ? "Sin meta" : `${Number(row.fulfillment_pct).toFixed(1)}%`}
                    </td>
                    <td className="px-3 py-2.5 text-right font-black text-primary">{moneyUsd(row.commission_usd)}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function PersonDetail({ row, onClose }: { row: EarnerRow; onClose: () => void }) {
  const rules = row.rules ?? [];

  return (
    <div className="space-y-4">
      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs font-black uppercase tracking-wide text-primary">
              {String(row.profile_name)} · {String(row.profile_type || "").replaceAll("_", " ")}
            </p>
            <h2 className="mt-1 text-2xl font-black text-slate-950">{row.user_name || `Usuario ${row.user_id}`}</h2>
            <p className="mt-1 text-sm font-bold text-slate-500">{row.seller_code || "Sin codigo de vendedor"}</p>
          </div>
          <div className="flex items-center gap-2">
            <span
              className={`rounded-full px-3 py-1.5 text-xs font-black uppercase ${
                row.eligible ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"
              }`}
            >
              {row.eligible ? "Comisiona" : "No aplica"}
            </span>
            <button
              onClick={onClose}
              className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200"
              title="Quitar seleccion"
            >
              <X size={17} />
            </button>
          </div>
        </div>
      </section>

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi icon={<Wallet size={18} />} label="Ventas USD" value={moneyUsd(row.sales_usd)} />
        <Kpi icon={<Wallet size={18} />} label="Ventas COP" value={moneyCop(row.sales_cop)} />
        <Kpi icon={<Package size={18} />} label="Unidades / tickets" value={`${num.format(row.units)} / ${row.sales_count}`} />
        <Kpi icon={<Wallet size={18} />} label="Comision COP" value={`${Number(row.commission_cop || 0).toFixed(2)} COP`} />
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h3 className="flex items-center gap-2 text-sm font-black text-slate-800">
            <BadgeDollarSign size={16} className="text-primary" />
            Reglas individuales ({rules.length})
          </h3>
          <div className="rounded-lg bg-slate-50 px-3 py-2 text-right">
            <p className="text-[11px] font-black uppercase text-slate-400">Comision total</p>
            <p className="text-lg font-black text-primary">{moneyUsd(row.commission_usd)}</p>
            <p className="text-[11px] font-bold text-slate-500">
              {moneyCop(row.commission_cop ?? 0)} COP{row.avg_trm ? ` · TRM ${trmNum.format(row.avg_trm)}` : ""}
            </p>
          </div>
        </div>

        {rules.length === 0 ? (
          <p className="text-sm font-semibold text-slate-500">No hay desglose por regla disponible.</p>
        ) : (
          <div className="space-y-3">
            {rules.map((rule, index) => (
              <RuleDetailCard key={rule.rule_id ?? index} rule={rule} index={index} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function RuleDetailCard({ rule, index }: { rule: EarnerRule; index: number }) {
  const fulfillment = rule.fulfillment_pct;
  const target = Number(rule.target_usd || 0);
  const sales = Number(rule.sales_usd || 0);
  const progress = fulfillment == null ? 0 : Math.min(fulfillment, 130);
  const remaining = Math.max(target - sales, 0);
  const chartData = [
    { name: "Ventas", value: sales, color: "#0f766e" },
    { name: "Meta", value: target, color: "#94a3b8" },
    { name: "Comision", value: Number(rule.commission_usd || 0), color: "#840028" },
  ];

  return (
    <article className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="grid gap-0 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="min-w-0 p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0">
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-primary text-xs font-black text-white">
                  {index + 1}
                </span>
                <span
                  className={`rounded-full px-2.5 py-1 text-[11px] font-black ${
                    rule.eligible ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"
                  }`}
                >
                  {rule.eligible ? "Comisiona" : "No aplica"}
                </span>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-500">
                  {Number(rule.applied_commission_pct || 0).toFixed(2)}% aplicado
                </span>
              </div>
              <h4 className="text-base font-black text-slate-950">{ruleLabel(rule)}</h4>
              {!!rule.target_turns && (
                <p className="mt-1 text-xs font-bold text-slate-500">
                  {num.format(Number(rule.assigned_turns || 0))} turnos asignados / {num.format(Number(rule.target_turns || 0))} turnos base
                </p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:min-w-[500px] xl:min-w-0 xl:grid-cols-2">
              <MiniMetric label="Ventas" value={moneyUsd(sales)} />
              <MiniMetric label="Meta" value={moneyUsd(target)} />
              <MiniMetric label="Comision" value={moneyUsd(rule.commission_usd)} strong />
              <MiniMetric label="Faltante" value={moneyUsd(remaining)} />
            </div>
          </div>

          <div className="mt-5">
            <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
              <div>
                <p className="text-xs font-black uppercase tracking-wide text-slate-400">Cumplimiento de esta regla</p>
                <p className="text-sm font-bold text-slate-600">
                  {num.format(rule.sales_count)} tickets · {num.format(Number(rule.units || 0))} unidades
                </p>
              </div>
              <span className="text-3xl font-black" style={{ color: fulfillment == null ? "#94a3b8" : fulfillmentColor(fulfillment) }}>
                {fulfillment == null ? "Sin meta" : `${num.format(fulfillment)}%`}
              </span>
            </div>

            <div className="relative h-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
              <div className="absolute inset-y-0 left-0 bg-red-100" style={{ width: `${(80 / 130) * 100}%` }} />
              <div
                className="absolute inset-y-0 bg-amber-100"
                style={{ left: `${(80 / 130) * 100}%`, width: `${((100 - 80) / 130) * 100}%` }}
              />
              <div className="absolute inset-y-0 bg-emerald-100" style={{ left: `${(100 / 130) * 100}%`, right: 0 }} />
              {[80, 100, 120].map((mark) => (
                <div key={mark} className="absolute top-0 h-full w-px bg-white/90" style={{ left: `${(mark / 130) * 100}%` }} />
              ))}
              <div
                className="absolute inset-y-0 left-0 rounded-xl transition-all"
                style={{
                  width: `${(progress / 130) * 100}%`,
                  backgroundColor: fulfillment == null ? "#cbd5e1" : fulfillmentColor(fulfillment),
                }}
              />
            </div>
            <div className="mt-1.5 grid grid-cols-4 text-[10px] font-black text-slate-400">
              <span>0%</span>
              <span className="text-center">80%</span>
              <span className="text-center">100%</span>
              <span className="text-right">120%+</span>
            </div>
          </div>

          <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs font-bold text-slate-500">
            <span>{moneyCop(rule.sales_cop ?? 0)} COP en ventas</span>
            <span>{moneyCop(rule.commission_cop ?? 0)} COP comision</span>
            {rule.avg_trm ? <span>TRM {trmNum.format(rule.avg_trm)}</span> : null}
          </div>
        </div>

        <div className="border-t border-slate-200 bg-slate-50 p-4 xl:border-l xl:border-t-0">
          <div className="mb-2 flex items-center justify-between gap-2">
            <p className="text-xs font-black uppercase tracking-wide text-slate-500">Ventas vs meta</p>
            <p className="text-xs font-black text-primary">{moneyUsd(rule.commission_usd)} comision</p>
          </div>
          <ResponsiveContainer width="100%" height={178}>
            <BarChart data={chartData} layout="vertical" margin={{ top: 4, right: 12, left: 8, bottom: 4 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
              <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(value) => moneyUsd(Number(value))} />
              <YAxis type="category" dataKey="name" width={64} tick={{ fontSize: 11, fontWeight: 700 }} />
              <Tooltip formatter={(value) => moneyUsd(Number(value))} />
              <Bar dataKey="value" radius={[0, 6, 6, 0]}>
                {chartData.map((entry) => (
                  <Cell key={entry.name} fill={entry.color} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
          <div className="mt-2 rounded-lg bg-white px-3 py-2 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
            {remaining > 0 ? `Faltan ${moneyUsd(remaining)} para cumplir la meta.` : "Meta cumplida o superada."}
          </div>
        </div>
      </div>
    </article>
  );
}

function MiniMetric({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
      <p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-sm font-black ${strong ? "text-primary" : "text-slate-900"}`}>{value}</p>
    </div>
  );
}

function Kpi({
  icon,
  label,
  value,
  sub,
  valueClass = "text-slate-950",
  valueStyle,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  sub?: string;
  valueClass?: string;
  valueStyle?: CSSProperties;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">{icon}</div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-xl font-black ${valueClass}`} style={valueStyle}>
        {value}
      </div>
      {sub && <div className="mt-1 text-xs font-bold text-slate-500">{sub}</div>}
    </section>
  );
}
