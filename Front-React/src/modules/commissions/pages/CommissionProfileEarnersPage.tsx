import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
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
  Percent,
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
  if (rule.rule_type === "provider") return rule.provider_name || "Cualquier proveedor";
  if (rule.rule_type === "category") return `Categoria ${rule.category_code ?? "?"}`;
  return `${rule.provider_name || "Prov."} + Cat. ${rule.category_code ?? "?"}`;
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
    <div className="space-y-5 pb-10 text-slate-950">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <p className="text-xs font-black uppercase tracking-wide text-primary">Perfiles de comision</p>
            <h1 className="mt-1 text-2xl font-black leading-tight text-slate-950">Asignados y comisiones</h1>
            <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-500">
              Selecciona una persona para ver su desglose de ventas por regla, su cumplimiento y su comision, con
              graficos en detalle.
            </p>
          </div>

          <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-[200px_200px_140px_44px]">
            <select
              value={budgetId ?? ""}
              onChange={(event) => setBudgetId(Number(event.target.value) || null)}
              className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700"
            >
              <option value="">Todos los presupuestos</option>
              {budgets.map((budget) => (
                <option key={budget.id} value={budget.id}>
                  {budget.name}
                </option>
              ))}
            </select>

            <select
              value={profileId ?? ""}
              onChange={(event) => setProfileId(Number(event.target.value) || null)}
              className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700"
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

      <section className="grid gap-4 xl:grid-cols-[360px_1fr]">
        <aside className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div>
            <div className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
              <Search size={15} />
              Personas
            </div>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar perfil o persona"
                className="h-11 w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 text-sm font-semibold text-slate-900"
              />
            </div>
          </div>

          <div className="max-h-[640px] space-y-2 overflow-auto pr-1">
            {loading && <div className="py-8 text-center text-sm font-bold text-slate-500">Cargando...</div>}
            {!loading &&
              rankedRows.map((row, index) => {
                const key = rowKey(row);
                const active = key === selectedKey;
                const fulfillment = row.fulfillment_pct;

                return (
                  <button
                    key={key}
                    onClick={() => setSelectedKey(active ? null : key)}
                    className={`w-full rounded-lg border p-3 text-left transition ${
                      active
                        ? "border-primary bg-primary text-white"
                        : "border-slate-200 bg-slate-50 text-slate-900 hover:border-primary/50"
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          {index === 0 && row.commission_usd > 0 && (
                            <Award size={14} className={active ? "text-amber-200" : "text-amber-500"} />
                          )}
                          <div className="truncate font-black leading-tight">{row.user_name || `Usuario ${row.user_id}`}</div>
                        </div>
                        <div className={`mt-1 truncate text-xs font-bold ${active ? "text-white/75" : "text-slate-400"}`}>
                          {row.profile_name} · {row.seller_code || "Sin codigo"}
                        </div>
                      </div>
                      <ChevronRight size={17} className={active ? "text-white" : "text-slate-400"} />
                    </div>

                    <div className="mt-3 flex items-end justify-between gap-2">
                      <div className={`text-lg font-black ${active ? "text-white" : "text-slate-950"}`}>
                        {moneyUsd(row.commission_usd)}
                      </div>
                      <span
                        className={`rounded-full px-2 py-0.5 text-[10px] font-black uppercase ${
                          row.eligible
                            ? active
                              ? "bg-white/20 text-white"
                              : "bg-emerald-100 text-emerald-700"
                            : active
                            ? "bg-white/20 text-white/80"
                            : "bg-amber-100 text-amber-700"
                        }`}
                      >
                        {row.eligible ? "Comisiona" : "No aplica"}
                      </span>
                    </div>

                    {fulfillment != null && (
                      <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-black/10">
                        <div
                          className="h-full rounded-full"
                          style={{
                            width: `${Math.min(fulfillment, 130) / 130 * 100}%`,
                            backgroundColor: active ? "#fff" : fulfillmentColor(fulfillment),
                            opacity: active ? 0.85 : 1,
                          }}
                        />
                      </div>
                    )}
                  </button>
                );
              })}
            {!loading && rankedRows.length === 0 && (
              <div className="py-8 text-center text-sm font-bold text-slate-500">
                No hay personas asignadas con estos filtros.
              </div>
            )}
          </div>
        </aside>

        <main className="space-y-4">
          {selectedRow ? (
            <PersonDetail row={selectedRow} onClose={() => setSelectedKey(null)} />
          ) : (
            <OverviewPanel rows={filteredRows} loading={loading} onSelect={(row) => setSelectedKey(rowKey(row))} />
          )}
        </main>
      </section>
    </div>
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
                    <td className="px-3 py-2.5 text-slate-600">{String(row.profile_name)}</td>
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
  const fulfillment = row.fulfillment_pct;

  const ruleChartData = rules.map((rule) => ({
    label: ruleLabel(rule),
    sales_usd: rule.sales_usd,
    commission_usd: rule.commission_usd,
  }));

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
        <Kpi icon={<Percent size={18} />} label="% aplicado" value={`${Number(row.applied_commission_pct || 0).toFixed(2)}%`} />
      </section>

      <section className="grid gap-4 xl:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="flex items-center gap-2 text-sm font-black text-slate-800">
              <Gauge size={16} className="text-primary" />
              Cumplimiento de meta
            </h3>
            {fulfillment != null && (
              <span className="text-2xl font-black" style={{ color: fulfillmentColor(fulfillment) }}>
                {fulfillment.toFixed(1)}%
              </span>
            )}
          </div>

          {fulfillment == null ? (
            <p className="text-sm font-semibold text-slate-500">Este perfil no tiene una meta USD definida.</p>
          ) : (
            <>
              <div className="relative h-5 w-full overflow-hidden rounded-full bg-slate-100">
                <div className="absolute inset-y-0 left-0 bg-red-100" style={{ width: `${(80 / 130) * 100}%` }} />
                <div
                  className="absolute inset-y-0 bg-amber-100"
                  style={{ left: `${(80 / 130) * 100}%`, width: `${((100 - 80) / 130) * 100}%` }}
                />
                <div className="absolute inset-y-0 bg-emerald-100" style={{ left: `${(100 / 130) * 100}%`, right: 0 }} />
                {[80, 100, 120].map((mark) => (
                  <div key={mark} className="absolute top-0 h-full w-px bg-white" style={{ left: `${(mark / 130) * 100}%` }} />
                ))}
                <div
                  className="absolute inset-y-0 left-0 rounded-full transition-all"
                  style={{
                    width: `${(Math.min(fulfillment, 130) / 130) * 100}%`,
                    backgroundColor: fulfillmentColor(fulfillment),
                  }}
                />
              </div>
              <div className="mt-1.5 flex justify-between text-[10px] font-bold text-slate-400">
                <span>0%</span>
                <span>80%</span>
                <span>100%</span>
                <span>120%+</span>
              </div>
            </>
          )}

          <div className="mt-4 rounded-lg bg-slate-50 p-3 text-sm">
            <p className="font-bold text-slate-700">Comision de esta persona</p>
            <p className="mt-1 text-2xl font-black text-primary">{moneyUsd(row.commission_usd)}</p>
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <h3 className="mb-3 flex items-center gap-2 text-sm font-black text-slate-800">
            <BadgeDollarSign size={16} className="text-primary" />
            Desglose por regla ({rules.length})
          </h3>

          {rules.length === 0 ? (
            <p className="text-sm font-semibold text-slate-500">No hay desglose por regla disponible.</p>
          ) : (
            <>
              <ResponsiveContainer width="100%" height={Math.max(200, rules.length * 60)}>
                <BarChart data={ruleChartData} layout="vertical" margin={{ top: 4, right: 24, left: 8, bottom: 4 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => moneyUsd(Number(v))} />
                  <YAxis type="category" dataKey="label" width={150} tick={{ fontSize: 11 }} />
                  <Tooltip formatter={(value, name) => [moneyUsd(Number(value)), name === "sales_usd" ? "Ventas" : "Comision"]} />
                  <Legend formatter={(value) => (value === "sales_usd" ? "Ventas" : "Comision")} />
                  <Bar dataKey="sales_usd" name="sales_usd" fill="#cbd5e1" radius={[0, 4, 4, 0]} />
                  <Bar dataKey="commission_usd" name="commission_usd" fill="#840028" radius={[0, 4, 4, 0]} />
                </BarChart>
              </ResponsiveContainer>

              <div className="mt-3 space-y-2">
                {rules.map((rule, index) => (
                  <div key={rule.rule_id ?? index} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs">
                    <div className="min-w-0">
                      <p className="truncate font-black text-slate-800">{ruleLabel(rule)}</p>
                      <p className="text-slate-500">
                        {moneyUsd(rule.sales_usd)} en ventas · {Number(rule.applied_commission_pct || 0).toFixed(2)}% aplicado
                      </p>
                    </div>
                    <p className="shrink-0 font-black text-primary">{moneyUsd(rule.commission_usd)}</p>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </section>
    </div>
  );
}

function Kpi({
  icon,
  label,
  value,
  valueClass = "text-slate-950",
  valueStyle,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  valueClass?: string;
  valueStyle?: React.CSSProperties;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">{icon}</div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-xl font-black ${valueClass}`} style={valueStyle}>
        {value}
      </div>
    </section>
  );
}
