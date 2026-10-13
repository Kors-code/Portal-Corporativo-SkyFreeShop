import { useEffect, useMemo, useState } from "react";
import {
  ChevronDown,
  Check,
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
  updateCommissionProfile,
  type CommissionProfile,
  type CommissionProfileOptions,
  type CommissionProfilePayload,
  type CommissionProfileRule,
} from "../services/commissionProfilesService";

type Draft = {
  id?: number;
  budget_id: number | null;
  name: string;
  profile_type: string;
  minimum_fulfillment_pct: string;
  is_active: boolean;
  note: string;
  rules: CommissionProfileRule[];
  user_ids: number[];
};

const emptyRule = (): CommissionProfileRule => ({
  rule_type: "custom",
  provider_name: "",
  brand: "",
  category_id: null,
  category_code: null,
  product_code: "",
  participation_pct: 0,
  target_amount_usd: null,
  target_turns: null,
  commission_percentage: 0,
  commission_percentage100: 0,
  commission_percentage120: 0,
});

const emptyDraft = (budgetId: number | null): Draft => ({
  budget_id: budgetId,
  name: "",
  profile_type: "degustadora",
  minimum_fulfillment_pct: "",
  is_active: true,
  note: "",
  rules: [emptyRule()],
  user_ids: [],
});

const moneyUsd = (value: number) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(Number(value || 0));

// Muestra el numero tal cual (1.5 -> "1.5"), sin forzar 2 decimales (1.5 -> "1.50").
const pct = (value: number | null | undefined) => String(Math.round(Number(value || 0) * 100) / 100);

const numberInput = (value: number | string | null | undefined) => {
  const numeric = Number(value ?? 0);
  return numeric === 0 ? "" : String(value);
};

const normalizeFilterValue = (value: unknown) =>
  String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim()
    .toLowerCase();

const excludedCategoryCodes = ["25", "23"].map(normalizeFilterValue);
const excludedCategoryNames = ["diamantes"].map(normalizeFilterValue);

function deriveRuleType(rule: CommissionProfileRule): CommissionProfileRule["rule_type"] {
  const hasProvider = Boolean(rule.provider_name?.trim());
  const hasBrand = Boolean(rule.brand?.trim());
  const hasCategory = Boolean(rule.category_code || rule.category_id);
  const hasProduct = Boolean(rule.product_code?.trim());
  const count = [hasProvider, hasBrand, hasCategory, hasProduct].filter(Boolean).length;

  if (count !== 1) return "custom";
  if (hasProduct) return "sku";
  if (hasProvider) return "provider";
  if (hasBrand) return "brand";
  return "category";
}

function ruleFilterSummary(rule: CommissionProfileRule, options: CommissionProfileOptions | null) {
  const category = options?.categories.find((item) => Number(item.id) === Number(rule.category_id));
  const parts = [
    category || rule.category_code
      ? `Categoria: ${category?.name ?? "Sin nombre"}${rule.category_code ? ` (${rule.category_code})` : ""}`
      : null,
    rule.provider_name ? `Proveedor: ${rule.provider_name}` : null,
    rule.brand ? `Marca: ${rule.brand}` : null,
    rule.product_code ? `SKU: ${rule.product_code}` : null,
  ].filter(Boolean);

  return parts.join(" | ") || "Sin filtros seleccionados";
}

function draftFromProfile(profile: CommissionProfile): Draft {
  return {
    id: profile.id,
    budget_id: profile.budget_id ?? null,
    name: profile.name,
    profile_type: profile.profile_type,
    minimum_fulfillment_pct: profile.minimum_fulfillment_pct == null ? "" : String(profile.minimum_fulfillment_pct),
    is_active: Boolean(profile.is_active),
    note: profile.note ?? "",
    rules: profile.rules.length
      ? profile.rules.map((rule) => ({ ...rule }))
      : [emptyRule()],
    user_ids: profile.users.map((user) => Number(user.user_id)),
  };
}

function SearchableRuleSelect({
  label,
  value,
  options,
  placeholder,
  onChange,
}: {
  label: string;
  value: string;
  options: Array<{ value: string; label: string; searchText?: string }>;
  placeholder: string;
  onChange: (value: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const selected = options.find((option) => option.value === value);
  const active = Boolean(value);
  const term = query.trim().toLowerCase();
  const filtered = term
    ? options
        .filter((option) => `${option.label} ${option.searchText ?? ""}`.toLowerCase().includes(term))
        .slice(0, 80)
    : options.slice(0, 80);

  return (
    <div className="relative min-w-0">
      <Search className="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        value={open ? query : selected?.label ?? ""}
        onFocus={() => {
          setOpen(true);
          setQuery("");
        }}
        onBlur={() => window.setTimeout(() => setOpen(false), 120)}
        onChange={(event) => {
          setQuery(event.target.value);
          setOpen(true);
        }}
        placeholder={active ? label : placeholder}
        className={`h-10 w-full min-w-0 rounded-md border pl-9 pr-8 text-sm font-semibold ${
          active ? "border-slate-200 bg-white text-slate-800" : "border-slate-200 bg-slate-100 text-slate-400"
        }`}
      />
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

      {open && (
        <div className="absolute z-40 mt-1 max-h-64 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg">
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => {
              onChange("");
              setQuery("");
              setOpen(false);
            }}
            className="block w-full px-3 py-2 text-left text-sm font-bold text-slate-400 hover:bg-slate-50"
          >
            {placeholder}
          </button>

          {filtered.map((option) => (
            <button
              key={option.value}
              type="button"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => {
                onChange(option.value);
                setQuery("");
                setOpen(false);
              }}
              className={`block w-full px-3 py-2 text-left text-sm font-semibold hover:bg-primary/5 ${
                option.value === value ? "text-primary" : "text-slate-700"
              }`}
              title={option.label}
            >
              <span className="block truncate">{option.label}</span>
            </button>
          ))}

          {filtered.length === 0 && (
            <div className="px-3 py-3 text-sm font-semibold text-slate-500">Sin resultados</div>
          )}
        </div>
      )}
    </div>
  );
}

export default function CommissionProfilesPage() {
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [profiles, setProfiles] = useState<CommissionProfile[]>([]);
  const [options, setOptions] = useState<CommissionProfileOptions | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [draft, setDraft] = useState<Draft>(() => emptyDraft(null));
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [userSearch, setUserSearch] = useState("");
  const [showUserPicker, setShowUserPicker] = useState(false);
  const [tierModalIndex, setTierModalIndex] = useState<number | null>(null);

  const selectedProfile = useMemo(
    () => profiles.find((profile) => profile.id === selectedId) ?? null,
    [profiles, selectedId]
  );

  const filteredUsers = useMemo(() => {
    const term = userSearch.trim().toLowerCase();
    const users = options?.users ?? [];
    if (!term && !showUserPicker) return [];
    if (!term) return users.slice(0, 80);

    return users
      .filter((user) =>
        [user.name, user.email, user.codigo_vendedor]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(term))
      )
      .slice(0, 80);
  }, [options, userSearch, showUserPicker]);

  const assignedTargetUsd = useMemo(
    () => draft.rules.reduce((sum, rule) => sum + Number(rule.target_amount_usd || 0), 0),
    [draft.rules]
  );

  function updateRuleTargetUsd(index: number, dollarValue: number) {
    updateRule(index, { target_amount_usd: dollarValue });
  }

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const [budgetRes, opt] = await Promise.all([api.get("/budgets"), getCommissionProfileOptions()]);
        const budgetRows = budgetRes.data || [];
        setBudgets(budgetRows);
        if (budgetRows.length) {
          setBudgetId((current) => current ?? Number(budgetRows[0].id));
        }
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
      return;
    }

    setDraft(draftFromProfile(selectedProfile));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedProfile?.id]);

  async function loadProfiles() {
    setError(null);
    if (!budgetId) {
      setProfiles([]);
      setSelectedId(null);
      return;
    }
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

  function updateRule(index: number, patch: Partial<CommissionProfileRule>) {
    setDraft((current) => ({
      ...current,
      rules: current.rules.map((rule, ruleIndex) =>
        ruleIndex === index
          ? { ...rule, ...patch }
          : rule
      ),
    }));
  }

  function addRule() {
    setDraft((current) => ({
      ...current,
      rules: [...current.rules, emptyRule()],
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
      target_amount_usd: null,
      is_active: true,
      valid_from: null,
      valid_to: null,
      note: draft.note.trim() || null,
      rules: draft.rules.map((rule) => ({
        ...rule,
        rule_type: deriveRuleType(rule),
        provider_name: rule.provider_name?.trim() || null,
        brand: rule.brand?.trim() || null,
        product_code: rule.product_code?.trim() || null,
        target_amount_usd: rule.target_amount_usd == null ? null : Number(rule.target_amount_usd),
        target_turns: rule.target_turns == null ? null : Number(rule.target_turns),
        commission_percentage: Number(rule.commission_percentage || 0),
        commission_percentage100: Number(rule.commission_percentage100 || 0),
        commission_percentage120: Number(rule.commission_percentage120 || 0),
      })),
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
    <div className="mx-auto w-full max-w-6xl space-y-6 overflow-x-hidden pb-10">
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
              onChange={(event) => setBudgetId(Number(event.target.value))}
              className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700"
            >
              <option value="" disabled>
                Selecciona presupuesto
              </option>
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
              disabled={!budgetId}
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-bold text-white shadow-sm hover:brightness-95 disabled:cursor-not-allowed disabled:bg-slate-300"
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

      <div className="grid min-w-0 gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
        <aside className="min-w-0 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
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
                  </div>
                  <p className="mt-2 text-xs text-slate-500">
                    {profile.rules.length} regla(s) · {profile.users.length} persona(s)
                  </p>
                </button>
              ))
            )}
          </div>
        </aside>

        <main className="grid min-w-0 gap-5">
          <section className="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
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

            <div className="grid min-w-0 gap-4 md:grid-cols-2">
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Nombre</span>
                <input
                  value={draft.name}
                  onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value }))}
                  placeholder="Degustadora Mil Demonios"
                  className="h-10 min-w-0 w-full rounded-md border border-slate-200 px-3 text-sm"
                />
              </label>

              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Tipo</span>
                <select
                  value={draft.profile_type}
                  onChange={(event) => setDraft((current) => ({ ...current, profile_type: event.target.value }))}
                  className="h-10 min-w-0 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                >
                  {(options?.profile_types ?? []).map((type) => (
                    <option key={type.value} value={type.value}>
                      {type.label}
                    </option>
                  ))}
                </select>
              </label>

            </div>

            <div className="mt-6">
              <div className="mb-4 grid gap-3 md:grid-cols-2">
                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                  <p className="text-xs font-black uppercase tracking-wide text-slate-500">Meta total de reglas</p>
                  <p className="mt-1 text-lg font-black text-slate-950">{moneyUsd(assignedTargetUsd)}</p>
                </div>
              </div>

              <div className="mb-3 flex items-center justify-between">
                <h3 className="font-black text-slate-900">Reglas y metas</h3>
                <button type="button" onClick={addRule} className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50">
                  <Plus className="h-4 w-4" />
                  Agregar regla
                </button>
              </div>

              <div className="space-y-3">
                {draft.rules.map((rule, index) => (
                  <div key={index} className="rounded-md border border-slate-200 p-3">
                    <div className="mb-3 flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="text-sm font-black text-slate-900">Regla {index + 1}</p>
                        <p className="mt-1 truncate text-xs font-semibold text-slate-500" title={ruleFilterSummary(rule, options)}>
                          {ruleFilterSummary(rule, options)}
                        </p>
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        <button
                          type="button"
                          onClick={() => removeRule(index)}
                          disabled={draft.rules.length === 1}
                          className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-40"
                          aria-label="Quitar regla"
                        >
                          <X className="h-4 w-4" />
                        </button>
                      </div>
                    </div>

                    <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_170px]">
                      <SearchableRuleSelect
                        label="Categoria"
                        value={rule.category_id ? String(rule.category_id) : ""}
                        placeholder="Categoria: No aplica"
                        options={(options?.categories ?? [])
                          .filter((category) => {
                            const code = normalizeFilterValue(category.code);
                            const name = normalizeFilterValue(category.name);
                            return !excludedCategoryCodes.includes(code) && !excludedCategoryNames.includes(name);
                          })
                          .map((category) => ({
                            value: String(category.id),
                            label: `${category.name} (${category.code})`,
                            searchText: `${category.name} ${category.code}`,
                          }))}
                        onChange={(value) => {
                          const category = options?.categories.find((item) => item.id === Number(value));
                          updateRule(index, {
                            category_id: category?.id ?? null,
                            category_code: category?.code ?? null,
                          });
                        }}
                      />

                      <SearchableRuleSelect
                        label="Proveedor"
                        value={rule.provider_name ?? ""}
                        placeholder="Proveedor: No aplica"
                        options={(options?.providers ?? []).map((provider) => ({
                          value: provider,
                          label: provider,
                        }))}
                        onChange={(value) => updateRule(index, { provider_name: value || null })}
                      />

                      <SearchableRuleSelect
                        label="Marca"
                        value={rule.brand ?? ""}
                        placeholder="Marca: No aplica"
                        options={(options?.brands ?? []).map((brand) => ({
                          value: brand,
                          label: brand,
                        }))}
                        onChange={(value) => updateRule(index, { brand: value || null })}
                      />

                      <SearchableRuleSelect
                        label="SKU"
                        value={rule.product_code ?? ""}
                        placeholder="SKU: No aplica"
                        options={(options?.products ?? []).map((product) => ({
                          value: product.code,
                          label: `${product.code}${product.description ? ` - ${product.description}` : ""}`,
                          searchText: [
                            product.description,
                            product.brand,
                            product.sku_mia,
                            product.upc,
                            product.upc2,
                            product.upc3,
                          ].filter(Boolean).join(" "),
                        }))}
                        onChange={(value) => updateRule(index, { product_code: value || null })}
                      />

                      <button
                        type="button"
                        onClick={() => setTierModalIndex(index)}
                        title={`Meta regla: ${moneyUsd(Number(rule.target_amount_usd || 0))}${Number(rule.target_turns || 0) > 0 ? ` para ${numberInput(rule.target_turns)} turnos` : ""}`}
                        className="inline-flex h-10 min-w-0 items-center justify-between rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
                      >
                        <span className="min-w-0 truncate">
                          80: {pct(rule.commission_percentage)} · 100: {pct(rule.commission_percentage100)} · 120: {pct(rule.commission_percentage120)}
                        </span>
                        <Percent className="h-4 w-4 shrink-0 text-primary" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="mt-6 grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,340px)]">
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
                  <div className="flex gap-2">
                    <input
                      value={userSearch}
                      onChange={(event) => {
                        setUserSearch(event.target.value);
                        setShowUserPicker(true);
                      }}
                      onFocus={() => userSearch.trim() && setShowUserPicker(true)}
                      placeholder="Nombre, email o codigo"
                      className="h-10 min-w-0 flex-1 rounded-md border border-slate-200 px-3 text-sm"
                    />
                    <button
                      type="button"
                      onClick={() => setShowUserPicker((current) => !current)}
                      className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                      aria-label="Mostrar personas"
                    >
                      <ChevronDown className={`h-4 w-4 transition ${showUserPicker ? "rotate-180" : ""}`} />
                    </button>
                  </div>
                </label>
                <div className={`max-h-48 overflow-auto rounded-md border border-slate-200 ${showUserPicker || userSearch.trim() ? "block" : "hidden"}`}>
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
                  {filteredUsers.length === 0 && (
                    <div className="px-3 py-4 text-sm font-semibold text-slate-500">
                      Escribe para buscar o abre la lista.
                    </div>
                  )}
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

            <div className="mb-4 grid gap-4 sm:grid-cols-2">
              <label className="block space-y-1">
                <span className="text-sm font-bold text-slate-700">Meta regla</span>
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-2.5 text-sm font-bold text-slate-400">$</span>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={numberInput(draft.rules[tierModalIndex].target_amount_usd)}
                    onChange={(event) => updateRuleTargetUsd(tierModalIndex, Number(event.target.value || 0))}
                    placeholder="Ej. 25000"
                    className="h-10 w-full rounded-md border border-slate-200 pl-6 pr-3 text-sm font-semibold"
                  />
                </div>
              </label>
              <label className="block space-y-1">
                <span className="text-sm font-bold text-slate-700">Turnos base</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={numberInput(draft.rules[tierModalIndex].target_turns)}
                  onChange={(event) => updateRule(tierModalIndex, { target_turns: Number(event.target.value || 0) })}
                  placeholder="Ej. 25"
                  className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm font-semibold"
                />
              </label>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <label className="space-y-1">
                <span className="text-sm font-bold text-slate-700">Desde 80%</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={numberInput(draft.rules[tierModalIndex].commission_percentage)}
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
                  value={numberInput(draft.rules[tierModalIndex].commission_percentage100)}
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
                  value={numberInput(draft.rules[tierModalIndex].commission_percentage120)}
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
