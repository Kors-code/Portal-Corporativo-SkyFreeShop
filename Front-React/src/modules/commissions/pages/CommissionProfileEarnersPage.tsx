import { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw, Search } from "lucide-react";
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

export default function CommissionProfileEarnersPage() {
  const [budgets, setBudgets] = useState<any[]>([]);
  const [profiles, setProfiles] = useState<CommissionProfile[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [profileId, setProfileId] = useState<number | null>(null);
  const [summary, setSummary] = useState<CommissionProfileSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const filteredRows = useMemo(() => {
    const rows = summary?.rows ?? [];
    const term = search.trim().toLowerCase();
    if (!term) return rows;

    return rows.filter((row: any) =>
      [row.profile_name, row.user_name, row.seller_code]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term))
    );
  }, [summary, search]);

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

  return (
    <div className="space-y-6 pb-10">
      <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-primary">Perfiles de comision</p>
            <h1 className="mt-1 text-2xl font-black text-slate-950">Asignados y comisiones</h1>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
              Consulta las personas asignadas a cada perfil, sus ventas filtradas por regla y el estado de la comision.
            </p>
          </div>

          <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-[220px_220px_180px_44px]">
            <select
              value={budgetId ?? ""}
              onChange={(event) => setBudgetId(Number(event.target.value) || null)}
              className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700"
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
              className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700"
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
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-bold text-white shadow-sm hover:brightness-95"
            >
              <Download className="h-4 w-4" />
              Excel
            </a>

            <button
              type="button"
              onClick={loadRows}
              className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
              aria-label="Actualizar"
            >
              <RefreshCw className="h-4 w-4" />
            </button>
          </div>
        </div>
      </section>

      {error && <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{error}</div>}

      <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div className="mb-4 grid gap-3 md:grid-cols-[1fr_220px_220px_220px]">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Buscar perfil o persona"
              className="h-10 w-full rounded-md border border-slate-200 pl-9 pr-3 text-sm"
            />
          </div>
          <div className="rounded-md bg-slate-50 p-3">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Ventas</p>
            <p className="mt-1 font-black text-slate-950">{moneyUsd(summary?.totals.sales_usd ?? 0)}</p>
          </div>
          <div className="rounded-md bg-slate-50 p-3">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Comision</p>
            <p className="mt-1 font-black text-slate-950">{moneyUsd(summary?.totals.commission_usd ?? 0)}</p>
          </div>
          <div className="rounded-md bg-slate-50 p-3">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Registros</p>
            <p className="mt-1 font-black text-slate-950">{filteredRows.length}</p>
          </div>
        </div>

        <div className="overflow-x-auto rounded-md border border-slate-200">
          <table className="w-full min-w-[980px] text-sm">
            <thead className="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-3">Perfil</th>
                <th className="px-3 py-3">Persona</th>
                <th className="px-3 py-3 text-right">Ventas</th>
                <th className="px-3 py-3 text-right">Ventas USD</th>
                <th className="px-3 py-3 text-right">Ventas COP</th>
                <th className="px-3 py-3 text-right">Cumplimiento</th>
                <th className="px-3 py-3 text-right">% Aplicado</th>
                <th className="px-3 py-3 text-right">Comision</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={8} className="px-3 py-8 text-center text-slate-500">Cargando...</td>
                </tr>
              ) : filteredRows.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-3 py-8 text-center text-slate-500">No hay personas asignadas con estos filtros.</td>
                </tr>
              ) : (
                filteredRows.map((row: any) => (
                  <tr key={`${row.profile_id}-${row.user_id}`} className="border-t border-slate-100">
                    <td className="px-3 py-3">
                      <p className="font-bold text-slate-900">{row.profile_name}</p>
                      <p className="text-xs text-slate-500">{String(row.profile_type || "").replaceAll("_", " ")}</p>
                    </td>
                    <td className="px-3 py-3">
                      <p className="font-bold text-slate-900">{row.user_name || `Usuario ${row.user_id}`}</p>
                      <p className="text-xs text-slate-500">{row.seller_code || "Sin codigo"}</p>
                    </td>
                    <td className="px-3 py-3 text-right">{row.sales_count}</td>
                    <td className="px-3 py-3 text-right font-bold">{moneyUsd(row.sales_usd)}</td>
                    <td className="px-3 py-3 text-right">{moneyCop(row.sales_cop)}</td>
                    <td className="px-3 py-3 text-right">{row.fulfillment_pct == null ? "Sin meta" : `${Number(row.fulfillment_pct).toFixed(2)}%`}</td>
                    <td className="px-3 py-3 text-right">{Number(row.applied_commission_pct || 0).toFixed(2)}%</td>
                    <td className="px-3 py-3 text-right font-black text-primary">{moneyUsd(row.commission_usd)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
