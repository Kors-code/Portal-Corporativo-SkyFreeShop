import { useEffect, useMemo, useState } from "react";
import {
  BadgeDollarSign,
  Check,
  Edit3,
  Percent,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  UserPlus,
  X,
} from "lucide-react";
import api from "../../../api/axios";
import {
  createCommissionProfile,
  deleteCommissionProfile,
  getCommissionProfileOptions,
  getCommissionProfiles,
  getCommissionProfileSummary,
  updateCommissionProfile,
  type CommissionProfile,
  type CommissionProfileOptions,
  type CommissionProfilePayload,
  type CommissionProfileRule,
  type CommissionProfileSummary,
} from "../services/commissionProfilesService";

type Draft = {
  id?: number;
  budget_id: number | null;
  name: string;
  profile_type: string;
  minimum_fulfillment_pct: string;
  target_amount_usd: string;
  is_active: boolean;
  note: string;
  rules: CommissionProfileRule[];
  user_ids: number[];
};

const emptyDraft = (budgetId: number | null): Draft => ({
  budget_id: budgetId,
  name: "",
  profile_type: "degustadora",
  minimum_fulfillment_pct: "",
  target_amount_usd: "",
  is_active: true,
  note: "",
  rules: [{ rule_type: "provider", provider_name: "", category_id: null, category_code: null, commission_percentage: 0, commission_percentage100: 0, commission_percentage120: 0 }],
  user_ids: [],
});

const moneyUsd = (value: number) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(Number(value || 0));

const moneyCop = (value: number) =>
  new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 }).format(Number(value || 0));

function draftFromProfile(profile: CommissionProfile): Draft {
  return {
    id: profile.id,
    budget_id: profile.budget_id ?? null,
    name: profile.name,
    profile_type: profile.profile_type,
    minimum_fulfillment_pct: profile.minimum_fulfillment_pct == null ? "" : String(profile.minimum_fulfillment_pct),
    target_amount_usd: profile.target_amount_usd == null ? "" : String(profile.target_amount_usd),
    is_active: Boolean(profile.is_active),
    note: profile.note ?? "",
    rules: profile.rules.length
      ? profile.rules.map((rule) => ({ ...rule }))
      : [{ rule_type: "provider", provider_name: "", category_id: null, category_code: null, commission_percentage: 0, commission_percentage100: 0, commission_percentage120: 0 }],
    user_ids: profile.users.map((user) => Number(user.user_id)),
  };
}

export default function CommissionProfilesPage() {
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [profiles, setProfiles] = useState<CommissionProfile[]>([]);
  const [options, setOptions] = useState<CommissionProfileOptions | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [draft, setDraft] = useState<Draft>(() => emptyDraft(null));
  const [summary, setSummary] = useState<CommissionProfileSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [userSearch, setUserSearch] = useState("");
  const [tierModalIndex, setTierModalIndex] = useState<number | null>(null);

  const selectedProfile = useMemo(
    () => profiles.find((profile) => profile.id === selectedId) ?? null,
    [profiles, selectedId]
  );

  const filteredUsers = useMemo(() => {
    const term = userSearch.trim().toLowerCase();
    const users = options?.users ?? [];
    if (!term) return users.slice(0, 80);

    return users
      .filter((user) =>
        [user.name, user.email, user.codigo_vendedor]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(term))
      )
      .slice(0, 80);
  }, [options, userSearch]);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const [budgetRes, opt] = await Promise.all([api.get("/budgets"), getCommissionProfileOptions()]);
        const budgetRows = budgetRes.data || [];
        setBudgets(budgetRows);
        setOptions(opt);
      } catch (err: any) {
        setError(err?.response?.data?.message ?? "No se pudo cargar la configuracion inicial.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  useEffect(() => {
    loadProfiles();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  useEffect(() => {
    if (!selectedProfile) {
      setDraft(emptyDraft(budgetId));
      setSummary(null);
      return;
    }

    setDraft(draftFromProfile(selectedProfile));
    loadSummary(selectedProfile.id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedProfile?.id]);

  async function loadProfiles() {
    setError(null);
    try {
      const rows = await getCommissionProfiles({ budget_id: budgetId, search });
      setProfiles(rows);
      if (rows.length && !rows.some((profile) => profile.id === selectedId)) {
        setSelectedId(rows[0].id);
      }
      if (!rows.length) {
        setSelectedId(null);
      }
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudieron cargar los perfiles.");
    }
  }

  async function loadSummary(profileId = selectedId) {
    if (!profileId) return;
    try {
      const data = await getCommissionProfileSummary(profileId, budgetId);
      setSummary(data);
    } catch {
      setSummary(null);
    }
  }

  function updateRule(index: number, patch: Partial<CommissionProfileRule>) {
    setDraft((current) => ({
      ...current,
      rules: current.rules.map((rule, ruleIndex) =>
        ruleIndex === index
          ? {
              ...rule,
              ...patch,
              ...(patch.rule_type === "provider" ? { category_id: null, category_code: null } : {}),
              ...(patch.rule_type === "category" ? { provider_name: "" } : {}),
            }
          : rule
      ),
    }));
  }

  function addRule() {
    setDraft((current) => ({
      ...current,
      rules: [...current.rules, { rule_type: "provider", provider_name: "", category_id: null, category_code: null, commission_percentage: 0, commission_percentage100: 0, commission_percentage120: 0 }],
    }));
  }

  function removeRule(index: number) {
    setDraft((current) => ({
      ...current,
      rules: current.rules.filter((_, ruleIndex) => ruleIndex !== index),
    }));
  }

  function toggleUser(userId: number) {
    setDraft((current) => ({
      ...current,
      user_ids: current.user_ids.includes(userId)
        ? current.user_ids.filter((id) => id !== userId)
        : [...current.user_ids, userId],
    }));
  }

  function toPayload(): CommissionProfilePayload {
    return {
      budget_id: draft.budget_id,
      name: draft.name.trim(),
      profile_type: draft.profile_type,
      category_role_id: null,
      commission_mode: "fixed",
      commission_percentage: 0,
      commission_percentage100: 0,
      commission_percentage120: 0,
      minimum_fulfillment_pct: null,
      target_amount_usd: draft.target_amount_usd === "" ? null : Number(draft.target_amount_usd),
      is_active: draft.is_active,
      valid_from: null,
      valid_to: null,
      note: draft.note.trim() || null,
      rules: draft.rules,
      user_ids: draft.user_ids,
    };
  }

  async function saveProfile() {
    setSaving(true);
    setError(null);
    setMessage(null);

    try {
      const payload = toPayload();
      const saved = draft.id
        ? await updateCommissionProfile(draft.id, payload)
        : await createCommissionProfile(payload);

      setMessage(draft.id ? "Perfil actualizado." : "Perfil creado.");
      setSelectedId(saved.id);
      await loadProfiles();
      await loadSummary(saved.id);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudo guardar el perfil.");
    } finally {
      setSaving(false);
    }
  }

  async function removeProfile() {
    if (!draft.id) return;
    if (!window.confirm("Eliminar este perfil de comision?")) return;

    setSaving(true);
    try {
      await deleteCommissionProfile(draft.id);
      setMessage("Perfil eliminado.");
      setSelectedId(null);
      await loadProfiles();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? "No se pudo eliminar el perfil.");
    } finally {
      setSaving(false);
    }
  }

  const selectedUsers = options?.users.filter((user) => draft.user_ids.includes(user.id)) ?? [];

  return (
    <div className="space-y-6 pb-10">
      <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-primary">Comisiones</p>
            <h1 className="mt-1 text-2xl font-black text-slate-950">Perfiles de comision</h1>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
              Crea perfiles flexibles para comisionar por proveedor, categoria o una mezcla de ambos, y asigna las personas que aplican.
            </p>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <select
              value={budgetId ?? ""}
              onChange={(event) => setBudgetId(Number(event.target.value) || null)}
              className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700"
            >
              <option value="">Todos los presupuestos</option>
              {budgets.map((budget) => (
                <option key={budget.id} value={budget.id}>
                  {budget.name} ({budget.start_date} - {budget.end_date})
                </option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => {
                setSelectedId(null);
                setDraft({
                  ...emptyDraft(budgetId),
                });
              }}
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-bold text-white shadow-sm hover:brightness-95"
            >
              <Plus className="h-4 w-4" />
              Nuevo perfil
            </button>
          </div>
        </div>
      </section>

      {(message || error) && (
        <div className={`rounded-md border px-4 py-3 text-sm font-semibold ${error ? "border-red-200 bg-red-50 text-red-700" : "border-emerald-200 bg-emerald-50 text-emerald-700"}`}>
          {error || message}
        </div>
      )}

      <div className="grid gap-5 xl:grid-cols-[340px_1fr]">
        <aside className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-2">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onKeyDown={(event) => event.key === "Enter" && loadProfiles()}
                placeholder="Buscar perfil"
                className="h-10 w-full rounded-md border border-slate-200 pl-9 pr-3 text-sm"
              />
            </div>
            <button
              type="button"
              onClick={loadProfiles}
              className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
              aria-label="Actualizar perfiles"
            >
              <RefreshCw className="h-4 w-4" />
            </button>
          </div>

          <div className="space-y-2">
            {loading ? (
              <div className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Cargando perfiles...</div>
            ) : profiles.length === 0 ? (
              <div className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Aun no hay perfiles para este presupuesto.</div>
            ) : (
              profiles.map((profile) => (
                <button
                  key={profile.id}
                  type="button"
                  onClick={() => setSelectedId(profile.id)}
                  className={`w-full rounded-md border p-3 text-left transition ${
                    selectedId === profile.id ? "border-primary bg-primary/5" : "border-slate-200 hover:bg-slate-50"
                  }`}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-bold text-slate-900">{profile.name}</p>
                      <p className="mt-1 text-xs uppercase tracking-wide text-slate-500">{profile.profile_type.replaceAll("_", " ")}</p>
                    </div>
                    <span className={`rounded-full px-2 py-1 text-xs font-bold ${profile.is_active ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-500"}`}>
                      {profile.is_active ? "Activo" : "Inactivo"}
                    </span>
                  </div>
                  <p className="mt-2 text-xs text-slate-500">
                    {profile.rules.length} regla(s) · {profile.users.length} persona(s)
                  </p>
                </button>
              ))
            )}
          </div>
        </aside>

        <main className="grid gap-5 2xl:grid-cols-[1fr_420px]">
          <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-5 flex items-center justify-between gap-3">
              <div>
                <p className="text-sm font-bold text-slate-500">{draft.id ? "Editar perfil" : "Nuevo perfil"}</p>
                <h2 className="text-xl font-black text-slate-950">{draft.name || "Perfil sin nombre"}</h2>
              </div>
              <div className="flex gap-2">
                {draft.id && (
                  <button
                    type="button"
                    onClick={removeProfile}
                    disabled={saving}
                    className="inline-flex h-10 items-center gap-2 rounded-md border border-red-200 px-3 text-sm font-bold text-red-700 hover:bg-red-50"
                  >
                    <Trash2 className="h-4 w-4" />
                    Eliminar
                  </button>
                )}
                <button
                  type="button"
                  onClick={saveProfile}
                  disabled={saving}
                  className="inline-flex h-10 items-center gap-2 rounded-md bg-primary px-4 text-sm font-bold text-white shadow-sm hover:brightness-95 disabled:opacity-60"
                >
                  <Check className="h-4 w-4" />
                  {saving ? "Guardando" : "Guardar"}
                </button>
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Nombre</span>
                <input
                  value={draft.name}
                  onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))}
                  placeholder="Degustadora Mil Demonios"
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>

              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Tipo</span>
                <select
                  value={draft.profile_type}
                  onChange={(event) => setDraft((current) => ({ ...current, profile_type: event.target.value }))}
                  className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                >
                  {(options?.profile_types ?? []).map((type) => (
                    <option key={type.value} value={type.value}>
                      {type.label}
                    </option>
                  ))}
                </select>
              </label>

              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Meta USD opcional</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={draft.target_amount_usd}
                  onChange={(event) => setDraft((current) => ({ ...current, target_amount_usd: event.target.value }))}
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>

              <label className="flex items-center gap-3 rounded-md border border-slate-200 px-3 py-2">
                <input
                  type="checkbox"
                  checked={draft.is_active}
                  onChange={(event) => setDraft((current) => ({ ...current, is_active: event.target.checked }))}
                  className="h-4 w-4"
                />
                <span className="text-sm font-bold text-slate-700">Habilitado para cálculo</span>
              </label>
            </div>

            <div className="mt-6">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="font-black text-slate-900">Reglas del perfil</h3>
                <button type="button" onClick={addRule} className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50">
                  <Plus className="h-4 w-4" />
                  Agregar regla
                </button>
              </div>

              <div className="space-y-3">
                {draft.rules.map((rule, index) => (
                  <div key={index} className="grid gap-3 rounded-md border border-slate-200 p-3 lg:grid-cols-[190px_1fr_1fr_180px_42px]">
                    <select
                      value={rule.rule_type}
                      onChange={(event) => updateRule(index, { rule_type: event.target.value as CommissionProfileRule["rule_type"] })}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm"
                    >
                      {(options?.rule_types ?? []).map((type) => (
                        <option key={type.value} value={type.value}>
                          {type.label}
                        </option>
                      ))}
                    </select>

                    <select
                      value={rule.provider_name ?? ""}
                      onChange={(event) => updateRule(index, { provider_name: event.target.value })}
                      disabled={rule.rule_type === "category"}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                    >
                      <option value="">Proveedor</option>
                      {(options?.providers ?? []).map((provider) => (
                        <option key={provider} value={provider}>
                          {provider}
                        </option>
                      ))}
                    </select>

                    <select
                      value={rule.category_id ?? ""}
                      onChange={(event) => {
                        const category = options?.categories.find((item) => item.id === Number(event.target.value));
                        updateRule(index, { category_id: category?.id ?? null, category_code: category?.code ?? null });
                      }}
                      disabled={rule.rule_type === "provider"}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                    >
                      <option value="">Categoria</option>
                      {(options?.categories ?? []).map((category) => (
                        <option key={category.id} value={category.id}>
                          {category.name} ({category.code})
                        </option>
                      ))}
                    </select>

                    <button
                      type="button"
                      onClick={() => setTierModalIndex(index)}
                      className="inline-flex h-10 items-center justify-between rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
                    >
                      <span>
                        80: {Number(rule.commission_percentage || 0).toFixed(2)} · 100: {Number(rule.commission_percentage100 || 0).toFixed(2)} · 120: {Number(rule.commission_percentage120 || 0).toFixed(2)}
                      </span>
                      <Percent className="h-4 w-4 text-primary" />
                    </button>

                    <button
                      type="button"
                      onClick={() => removeRule(index)}
                      disabled={draft.rules.length === 1}
                      className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-40"
                      aria-label="Quitar regla"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  </div>
                ))}
              </div>
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-[1fr_1fr]">
              <div>
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-black text-slate-900">Personas asignadas</h3>
                  <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{draft.user_ids.length}</span>
                </div>
                <div className="min-h-28 rounded-md border border-slate-200 p-3">
                  {selectedUsers.length === 0 ? (
                    <p className="text-sm text-slate-500">Selecciona una o varias personas para este perfil.</p>
                  ) : (
                    <div className="flex flex-wrap gap-2">
                      {selectedUsers.map((user) => (
                        <button
                          key={user.id}
                          type="button"
                          onClick={() => toggleUser(user.id)}
                          className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1.5 text-sm font-bold text-primary"
                        >
                          {user.name}
                          <X className="h-3.5 w-3.5" />
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              <div>
                <label className="mb-3 block space-y-1">
                  <span className="text-sm font-bold text-slate-700">Buscar persona</span>
                  <input
                    value={userSearch}
                    onChange={(event) => setUserSearch(event.target.value)}
                    placeholder="Nombre, email o codigo"
                    className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                  />
                </label>
                <div className="max-h-48 overflow-auto rounded-md border border-slate-200">
                  {filteredUsers.map((user) => {
                    const active = draft.user_ids.includes(user.id);
                    return (
                      <button
                        key={user.id}
                        type="button"
                        onClick={() => toggleUser(user.id)}
                        className={`flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-left text-sm last:border-0 ${active ? "bg-primary/5" : "hover:bg-slate-50"}`}
                      >
                        <span>
                          <span className="block font-bold text-slate-800">{user.name}</span>
                          <span className="block text-xs text-slate-500">{user.codigo_vendedor || user.email || "Sin codigo"}</span>
                        </span>
                        {active ? <Check className="h-4 w-4 text-primary" /> : <UserPlus className="h-4 w-4 text-slate-400" />}
                      </button>
                    );
                  })}
                </div>
              </div>
            </div>

            <label className="mt-6 block space-y-1">
              <span className="text-sm font-bold text-slate-700">Notas</span>
              <textarea
                value={draft.note}
                onChange={(event) => setDraft((current) => ({ ...current, note: event.target.value }))}
                rows={3}
                className="w-full rounded-md border border-slate-200 px-3 py-2 text-sm"
              />
            </label>
          </section>

          <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between gap-3">
              <div>
                <p className="text-sm font-bold text-slate-500">Vista calculada</p>
                <h2 className="text-xl font-black text-slate-950">Resumen</h2>
              </div>
              <button
                type="button"
                onClick={() => loadSummary()}
                disabled={!selectedId}
                className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                aria-label="Actualizar resumen"
              >
                <RefreshCw className="h-4 w-4" />
              </button>
            </div>

            {!selectedId ? (
              <div className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Guarda o selecciona un perfil para ver ventas y comision.</div>
            ) : (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-md bg-slate-50 p-3">
                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Ventas</p>
                    <p className="mt-1 text-lg font-black text-slate-950">{moneyUsd(summary?.totals.sales_usd ?? 0)}</p>
                  </div>
                  <div className="rounded-md bg-slate-50 p-3">
                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Comision</p>
                    <p className="mt-1 text-lg font-black text-slate-950">{moneyUsd(summary?.totals.commission_usd ?? 0)}</p>
                  </div>
                </div>

                <div className="space-y-2">
                  {(summary?.rows ?? []).map((row) => (
                    <div key={row.user_id} className="rounded-md border border-slate-200 p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-bold text-slate-900">{row.user_name || `Usuario ${row.user_id}`}</p>
                          <p className="text-xs text-slate-500">{row.seller_code || "Sin codigo"} · {row.sales_count} ventas</p>
                        </div>
                        <span className={`rounded-full px-2 py-1 text-xs font-bold ${row.eligible ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}`}>
                          {row.eligible ? "Comisiona" : "No aplica"}
                        </span>
                      </div>
                      <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div>
                          <p className="text-xs font-bold text-slate-500">Ventas USD</p>
                          <p className="font-black text-slate-900">{moneyUsd(row.sales_usd)}</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold text-slate-500">Comision USD</p>
                          <p className="font-black text-slate-900">{moneyUsd(row.commission_usd)}</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold text-slate-500">Ventas COP</p>
                          <p className="font-semibold text-slate-700">{moneyCop(row.sales_cop)}</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold text-slate-500">Cumplimiento</p>
                          <p className="font-semibold text-slate-700">{row.fulfillment_pct == null ? "Sin meta" : `${row.fulfillment_pct.toFixed(2)}%`}</p>
                        </div>
                        <div>
                          <p className="text-xs font-bold text-slate-500">% aplicado</p>
                          <p className="font-semibold text-slate-700">{Number(row.applied_commission_pct || 0).toFixed(2)}%</p>
                        </div>
                      </div>
                    </div>
                  ))}

                  {summary && summary.rows.length === 0 && (
                    <div className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Este perfil no tiene personas asignadas.</div>
                  )}
                </div>
              </div>
            )}

            {selectedProfile && (
              <div className="mt-5 rounded-md border border-slate-200 p-3">
                <div className="flex items-center gap-2 text-sm font-black text-slate-900">
                  <BadgeDollarSign className="h-4 w-4 text-primary" />
                  Regla activa
                </div>
                <div className="mt-3 space-y-2">
                  {selectedProfile.rules.map((rule) => (
                    <div key={rule.id} className="rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">
                      {rule.rule_type.replace("_", " + ")} · {rule.provider_name || "cualquier proveedor"} · {rule.category_code || "cualquier categoria"} · 80: {Number(rule.commission_percentage || 0).toFixed(2)} / 100: {Number(rule.commission_percentage100 || 0).toFixed(2)} / 120: {Number(rule.commission_percentage120 || 0).toFixed(2)}
                    </div>
                  ))}
                </div>
                <button
                  type="button"
                  onClick={() => setDraft(draftFromProfile(selectedProfile))}
                  className="mt-3 inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                  <Edit3 className="h-4 w-4" />
                  Restaurar cambios
                </button>
              </div>
            )}
          </section>
        </main>
      </div>

      {tierModalIndex !== null && draft.rules[tierModalIndex] && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/40 px-4">
          <div className="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl">
            <div className="mb-5 flex items-start justify-between gap-3">
              <div>
                <p className="text-sm font-bold text-primary">Tabla 80 / 100 / 120</p>
                <h3 className="text-xl font-black text-slate-950">Porcentajes de la regla</h3>
              </div>
              <button
                type="button"
                onClick={() => setTierModalIndex(null)}
                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                aria-label="Cerrar"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Desde 80%</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={draft.rules[tierModalIndex].commission_percentage ?? 0}
                  onChange={(event) => updateRule(tierModalIndex, { commission_percentage: Number(event.target.value || 0) })}
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Desde 100%</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={draft.rules[tierModalIndex].commission_percentage100 ?? 0}
                  onChange={(event) => updateRule(tierModalIndex, { commission_percentage100: Number(event.target.value || 0) })}
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Desde 120%</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={draft.rules[tierModalIndex].commission_percentage120 ?? 0}
                  onChange={(event) => updateRule(tierModalIndex, { commission_percentage120: Number(event.target.value || 0) })}
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>
            </div>

            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setTierModalIndex(null)}
                className="inline-flex h-10 items-center rounded-md border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50"
              >
                Listo
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
