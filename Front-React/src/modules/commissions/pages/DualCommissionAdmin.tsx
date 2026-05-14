import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

type Line = 'montblanc' | 'parbel';

type CategorySummary = {
  classification_code: string;
  category?: string;
  sales_sum_usd?: number;
  category_budget_usd_for_user?: number;
  pct_user_of_category_budget?: number;
  applied_commission_pct?: number;
  commission_sum_usd?: number;
};

type SaleRow = {
  id?: number | string;
  sale_date?: string;
  folio?: string;
  product?: string;
  amount_cop?: number;
  value_usd?: number;
  provider?: string;
  brand?: string;
  category_code?: string;
  commission_amount?: number;
  rowKey?: string;
};

type SellerPayload = {
  user?: any;
  categories?: CategorySummary[];
  sales?: SaleRow[];
  totals?: any;
  assigned_turns_for_user?: number;
  user_budget_usd?: number;
  days_worked?: any[];
};

type Specialist = {
  id?: number;
  budget_id: number;
  user_id: number;
  business_line?: string | null;
  category_id?: number | null;
  valid_from?: string;
  valid_to?: string | null;
  note?: string | null;
  user?: { id?: number; name?: string };
  user_name?: string;
};

type Props = {
  advisorAId?: number;
  advisorBId?: number;
  budgetIds?: number[];
};

const LINE_ROLE_ID: Record<Line, number> = {
  montblanc: 5,
  parbel: 4,
};

const LINE_LABEL: Record<Line, string> = {
  montblanc: 'Montblanc',
  parbel: 'Parbel',
};

const LINE_THEME: Record<Line, { accent: string; accentSoft: string }> = {
  montblanc: {
    accent: 'bg-indigo-600 hover:bg-indigo-700',
    accentSoft: 'bg-indigo-50 text-indigo-700',
  },
  parbel: {
    accent: 'bg-emerald-600 hover:bg-emerald-700',
    accentSoft: 'bg-emerald-50 text-emerald-700',
  },
};

const formatUSD = (v: any) =>
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
  }).format(Number(v || 0));

const formatCOP = (v: any) =>
  new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(Number(v || 0));

const num = (v: any) => Number(v || 0);

function StatCard({
  label,
  value,
  sub,
}: {
  label: string;
  value: string;
  sub?: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
      <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </div>
      <div className="mt-2 text-lg font-semibold text-slate-900">{value}</div>
      {sub ? <div className="mt-1 text-xs text-slate-500">{sub}</div> : null}
    </div>
  );
}

function SmallStat({
  label,
  value,
}: {
  label: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
      <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </div>
      <div className="mt-2 text-sm font-semibold text-slate-900">{value}</div>
    </div>
  );
}

export default function DualCommissionAdmin({
  advisorAId: initialAdvisorAId,
  advisorBId: initialAdvisorBId,
  budgetIds: initialBudgetIds,
}: Props) {
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  const [budgets, setBudgets] = useState<
    { id: number; name: string; start_date?: string; end_date?: string; target_amount?: number }[]
  >([]);

  const [selectedBudgetId, setSelectedBudgetId] = useState<number | null>(
    initialBudgetIds?.length ? initialBudgetIds[0] : null
  );

  const [usersMap, setUsersMap] = useState<Record<number, string>>({});
  const [advisors, setAdvisors] = useState<any[]>([]);

  const [viewLine, setViewLine] = useState<Line>('montblanc');

  const [selectedAdvisorId, setSelectedAdvisorId] = useState<Record<Line, number | null>>({
    montblanc: initialAdvisorAId ?? null,
    parbel: initialAdvisorBId ?? null,
  });

  const [lineData, setLineData] = useState<Record<Line, SellerPayload | null>>({
    montblanc: null,
    parbel: null,
  });

  const [lineOverrides, setLineOverrides] = useState<Record<Line, Record<string, number>>>({
    montblanc: {},
    parbel: {},
  });

  const [lineSpecialists, setLineSpecialists] = useState<Record<Line, Specialist[]>>({
    montblanc: [],
    parbel: [],
  });

  const [lineActive, setLineActive] = useState<Record<Line, Specialist | null>>({
    montblanc: null,
    parbel: null,
  });

  const [lineAdvisorBudget, setLineAdvisorBudget] = useState<Record<Line, number>>({
    montblanc: 0,
    parbel: 0,
  });

  const [savingAdvisorBudget, setSavingAdvisorBudget] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [savingOverrides, setSavingOverrides] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [assigningLine, setAssigningLine] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [filterProvider, setFilterProvider] = useState<string>('ALL');
  const [filterBrand, setFilterBrand] = useState<string>('ALL');
  const [filterProduct, setFilterProduct] = useState<string>('ALL');
  const [search, setSearch] = useState<string>('');

  const currentLine = viewLine;
  const currentTheme = LINE_THEME[currentLine];
  const currentData = lineData[currentLine];
  const currentCategories = currentData?.categories ?? [];
  const currentSales = currentData?.sales ?? [];
  const currentOverrides = lineOverrides[currentLine];
  const currentSpecialists = lineSpecialists[currentLine];
  const currentActiveSpecialist = lineActive[currentLine];
  const currentSelectedAdvisorId = selectedAdvisorId[currentLine];
  const currentAdvisorBudget = lineAdvisorBudget[currentLine];

  const currentTotals = currentData?.totals ?? {};
  const salesUsd = num(currentTotals.total_sales_usd ?? currentTotals.sales_usd ?? 0);
  const salesCop = num(currentTotals.total_sales_cop ?? currentTotals.sales_cop ?? 0);
  const compliancePct = num(
    currentTotals.compliance_pct ?? currentTotals.compliance ?? currentTotals.cumplimiento ?? 0
  );
  const commissionPct = num(currentTotals.applied_commission_pct ?? currentTotals.commission_pct ?? 0);
  const commissionUsd = num(currentTotals.commission_usd ?? currentTotals.commission_sum_usd ?? 0);
  const ticketsCount = num(currentTotals.tickets_count ?? currentTotals.tickets ?? 0);
  const turnsCount = num(currentData?.assigned_turns_for_user ?? currentTotals.turns_count ?? 0);

  const currentBudgetGlobal = useMemo(() => {
    const found = budgets.find((b) => Number(b.id) === Number(selectedBudgetId));
    return num(found?.target_amount ?? 0);
  }, [budgets, selectedBudgetId]);

  const buildUsersMapFromArray = (arr: any[] = []) => {
    const map: Record<number, string> = {};
    arr.forEach((u) => {
      const id = u?.id ?? u?.user?.id ?? u?.user_id;
      const name = u?.name ?? u?.user?.name ?? u?.user_name ?? u?.display_name ?? null;
      if (id && name) map[Number(id)] = String(name);
    });
    return map;
  };

  const getUserLabel = (id?: number | null, fallback?: string) => {
    if (!id) return fallback ?? 'Sin asignar';
    return usersMap[id] ?? fallback ?? 'Sin asignar';
  };
console.log(currentBudgetGlobal)
  const getAppliedCommissionPct = (row: CategorySummary) => {
    const code = String(row.classification_code ?? '');
    const override = currentOverrides[code];
    return Number.isFinite(override) ? Number(override) : Number(row.applied_commission_pct ?? 0);
  };

  const getCommissionLevel = (totals: any) => {
    const compliance = num(totals?.compliance_pct ?? totals?.compliance ?? totals?.cumplimiento);
    if (compliance >= 120) return '120%';
    if (compliance >= 100) return '100%';
    if (compliance >= 80) return '80%';
    return 'Sin premio';
  };
  console.log(getAppliedCommissionPct)

  async function loadSeller(userId: number, budgetId: number, line: Line): Promise<SellerPayload> {
    const res = await api.get('/advisors/active-sales', {
      params: { budget_id: budgetId, business_line: line, user_id: userId },
    });

    const breakdown = res.data?.breakdown || {};

    const categories: CategorySummary[] = Object.values(breakdown).map((row: any) => ({
      classification_code: String(row.classification_key ?? row.classification_code ?? ''),
      sales_sum_usd: num(row.sales_usd),
      category: row.classification_name ?? row.category_name ?? row.category ?? '',
      category_budget_usd_for_user: num(row.category_budget_usd_for_user ?? 0),
      pct_user_of_category_budget: num(row.pct_user_of_category_budget ?? 0),
      applied_commission_pct: num(row.applied_commission_pct ?? 0),
      commission_sum_usd: num(row.commission_usd ?? 0),
    }));

    if (res.data?.specialist?.id && res.data.specialist?.name) {
      setUsersMap((prev) => ({ ...prev, [Number(res.data.specialist.id)]: res.data.specialist.name }));
    }

    return {
      user: res.data?.specialist,
      user_budget_usd: num(res.data?.user_budget_usd ?? 0),
      categories,
      sales: Array.isArray(res.data?.sales) ? res.data.sales : [],
      totals: res.data?.totals ?? {},
      assigned_turns_for_user: num(res.data?.assigned_turns_for_user ?? 0),
      days_worked: Array.isArray(res.data?.days_worked) ? res.data.days_worked : [],
    };
  }

  async function fetchOverridesFor(userId: number, budgetId: number) {
    try {
      const res = await api.get('/commissions/category-commissions/overrides', {
        params: { user_id: userId, budget_ids: [budgetId] },
      });

      const rows = res.data?.overrides ?? {};
      const merged: Record<string, number> = {};

      Object.keys(rows).forEach((bid) => {
        const mapForBudget = rows[bid];
        Object.keys(mapForBudget).forEach((classification: string) => {
          const entry = mapForBudget[classification];
          const pct = Number(entry?.applied_commission_pct);
          if (!Number.isNaN(pct)) merged[String(classification)] = pct;
        });
      });

      return merged;
    } catch {
      return {};
    }
  }

  async function loadLineState(line: Line, budgetId: number) {
    const [specialistRes, budgetRes] = await Promise.all([
      api.get('/advisors/specialists', {
        params: { budget_id: budgetId, business_line: line },
      }),
      api.get('/advisor-budgets', {
        params: { budget_id: budgetId, role_id: LINE_ROLE_ID[line] },
      }),
    ]);

    const specialists: Specialist[] = Array.isArray(specialistRes.data) ? specialistRes.data : [];
    const active = specialists.find((s) => !s.valid_to) ?? specialists[0] ?? null;

    setLineSpecialists((prev) => ({ ...prev, [line]: specialists }));
    setLineActive((prev) => ({ ...prev, [line]: active }));
    setUsersMap((prev) => ({
      ...prev,
      ...buildUsersMapFromArray(
        specialists.map((s) => (s.user ? s.user : { id: s.user_id, name: s.user_name }))
      ),
    }));

    const existingSelected = selectedAdvisorId[line];
    const hasExisting = existingSelected
      ? specialists.some((s) => Number(s.user_id) === Number(existingSelected))
      : false;

    const resolvedAdvisorId = hasExisting
      ? existingSelected
      : active?.user_id ?? specialists[0]?.user_id ?? null;

    if (resolvedAdvisorId && resolvedAdvisorId !== selectedAdvisorId[line]) {
      setSelectedAdvisorId((prev) => ({ ...prev, [line]: Number(resolvedAdvisorId) }));
    }

    const budgetUsd = num(budgetRes.data?.budget_usd ?? 0);
    setLineAdvisorBudget((prev) => ({ ...prev, [line]: budgetUsd }));

    if (resolvedAdvisorId) {
      const [payload, overrides] = await Promise.all([
        loadSeller(Number(resolvedAdvisorId), budgetId, line),
        fetchOverridesFor(Number(resolvedAdvisorId), budgetId),
      ]);

      setLineData((prev) => ({ ...prev, [line]: payload }));
      setLineOverrides((prev) => ({ ...prev, [line]: overrides }));
    } else {
      setLineData((prev) => ({ ...prev, [line]: null }));
      setLineOverrides((prev) => ({ ...prev, [line]: {} }));
    }
  }

  useEffect(() => {
    let mounted = true;

    async function loadMeta() {
      try {
        const [bRes, uRes] = await Promise.all([api.get('/budgets'), api.get('/advisors/budget-sellers')]);
        if (!mounted) return;

        const budgetsList = Array.isArray(bRes.data) ? bRes.data : [];
        const usersList = Array.isArray(uRes.data) ? uRes.data : [];

        setBudgets(budgetsList);
        setAdvisors(usersList);
        setUsersMap((prev) => ({ ...prev, ...buildUsersMapFromArray(usersList) }));

        if (!selectedBudgetId && budgetsList.length) {
          setSelectedBudgetId(Number(budgetsList[0].id));
        }
      } catch (e) {
        console.warn('meta load failed', e);
      }
    }

    loadMeta().finally(() => {
      if (mounted) setLoading(false);
    });

    return () => {
      mounted = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!selectedBudgetId) {
      setLineData({ montblanc: null, parbel: null });
      setLineOverrides({ montblanc: {}, parbel: {} });
      setLineSpecialists({ montblanc: [], parbel: [] });
      setLineActive({ montblanc: null, parbel: null });
      setLineAdvisorBudget({ montblanc: 0, parbel: 0 });
      return;
    }

    let cancelled = false;

    (async () => {
      setLoading(true);
      try {
        await loadLineState('montblanc', selectedBudgetId);
        if (cancelled) return;
        await loadLineState('parbel', selectedBudgetId);
      } catch (e) {
        console.error('load budget line failed', e);
        if (!cancelled) {
          setMessage({ type: 'error', text: 'Error cargando datos del presupuesto seleccionado' });
          setTimeout(() => setMessage(null), 2500);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedBudgetId]);

  useEffect(() => {
    if (!selectedBudgetId) return;

    let cancelled = false;

    (async () => {
      try {
        setLoading(true);
        await Promise.all([
          loadLineState('montblanc', selectedBudgetId),
          loadLineState('parbel', selectedBudgetId),
        ]);
      } catch (e) {
        if (!cancelled) {
          console.error('reload line data failed', e);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedBudgetId, selectedAdvisorId.montblanc, selectedAdvisorId.parbel]);

  const saveAdvisorBudgetForLine = async (line: Line) => {
    if (!selectedBudgetId) return;

    setSavingAdvisorBudget((prev) => ({ ...prev, [line]: true }));
    try {
      await api.post('/advisor-budgets', {
        budget_id: selectedBudgetId,
        role_id: LINE_ROLE_ID[line],
        budget_usd: Number(lineAdvisorBudget[line] ?? 0),
      });

      setMessage({
        type: 'ok',
        text: `Presupuesto del asesor (${LINE_LABEL[line]}) guardado correctamente`,
      });

      setTimeout(() => setMessage(null), 2200);
    } catch (e) {
      console.error('save advisor budget error', e);
      setMessage({ type: 'error', text: 'No se pudo guardar el presupuesto del asesor' });
      setTimeout(() => setMessage(null), 2600);
    } finally {
      setSavingAdvisorBudget((prev) => ({ ...prev, [line]: false }));
    }
  };

  const assignSpecialistForLine = async (line: Line, userIdToAssign: number) => {
    if (!selectedBudgetId) return;

    setAssigningLine((prev) => ({ ...prev, [line]: true }));
    try {
      await api.post('/advisors/specialists', {
        budget_id: selectedBudgetId,
        user_id: userIdToAssign,
        business_line: line,
      });

      await loadLineState(line, selectedBudgetId);

      setMessage({ type: 'ok', text: `Especialista asignado a ${LINE_LABEL[line]}` });
      setTimeout(() => setMessage(null), 1800);
    } catch (e) {
      console.error('assign specialist error', e);
      setMessage({ type: 'error', text: 'Error asignando especialista' });
      setTimeout(() => setMessage(null), 2600);
    } finally {
      setAssigningLine((prev) => ({ ...prev, [line]: false }));
    }
  };

  const saveOverridesForLine = async (line: Line) => {
    const userId = selectedAdvisorId[line];
    if (!userId || !selectedBudgetId) return;

    setSavingOverrides((prev) => ({ ...prev, [line]: true }));

    try {
      const overridesArray = Object.entries(lineOverrides[line]).map(
        ([classification_code, applied_commission_pct]) => ({
          classification_code,
          applied_commission_pct: Number(applied_commission_pct ?? 0),
        })
      );

      await api.post('/commissions/category-commissions/overrides', {
        budget_ids: [selectedBudgetId],
        user_id: userId,
        overrides: overridesArray,
      });

      const [fresh, freshOverrides] = await Promise.all([
        loadSeller(userId, selectedBudgetId, line),
        fetchOverridesFor(userId, selectedBudgetId),
      ]);

      setLineData((prev) => ({ ...prev, [line]: fresh }));
      setLineOverrides((prev) => ({ ...prev, [line]: freshOverrides }));

      setMessage({ type: 'ok', text: `Comisiones guardadas para ${LINE_LABEL[line]}` });
      setTimeout(() => setMessage(null), 2200);
    } catch (e: any) {
      console.error('save overrides error', e);
      const status = e?.response?.status;
      let text = 'Error guardando comisiones';
      if (status === 422) text = 'Datos inválidos al guardar comisiones';
      if (status === 401 || status === 403) text = 'No autorizado';
      setMessage({ type: 'error', text });
      setTimeout(() => setMessage(null), 3000);
    } finally {
      setSavingOverrides((prev) => ({ ...prev, [line]: false }));
    }
  };

  const exportCsvFor = (line: Line) => {
    const payload = lineData[line];
    if (!payload) return;

    const categories = payload.categories ?? [];
    const overrides = lineOverrides[line];

    const header = [
      'classification_code',
      'category',
      'sales_usd',
      'category_budget_usd_for_user',
      'pct_user_of_category_budget',
      'applied_commission_pct',
      'commission_usd',
    ];

    const lines = [header.join(',')];

    categories.forEach((c) => {
      const code = String(c.classification_code);
      const applied = Number(overrides[code] ?? c.applied_commission_pct ?? 0);

      lines.push(
        [
          `"${code}"`,
          `"${String(c.category ?? '').replace(/"/g, '""')}"`,
          num(c.sales_sum_usd).toFixed(2),
          num(c.category_budget_usd_for_user).toFixed(2),
          num(c.pct_user_of_category_budget).toFixed(2),
          applied.toFixed(3),
          num(c.commission_sum_usd).toFixed(2),
        ].join(',')
      );
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `commissions_${line}_${selectedBudgetId ?? 'budget'}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  };

  const providers = useMemo(
    () => Array.from(new Set(currentSales.map((s) => s.provider).filter(Boolean))),
    [currentSales]
  );

  const brands = useMemo(
    () => Array.from(new Set(currentSales.map((s) => s.brand).filter(Boolean))),
    [currentSales]
  );

  const products = useMemo(
    () => Array.from(new Set(currentSales.map((s) => s.product).filter(Boolean))),
    [currentSales]
  );

  const filteredSales = useMemo(() => {
    return currentSales.filter((s) => {
      if (filterProvider !== 'ALL' && String(s.provider ?? '') !== filterProvider) return false;
      if (filterBrand !== 'ALL' && String(s.brand ?? '') !== filterBrand) return false;
      if (filterProduct !== 'ALL' && String(s.product ?? '') !== filterProduct) return false;
      if (!search) return true;
      return `${s.product ?? ''} ${s.folio ?? ''}`.toLowerCase().includes(search.toLowerCase());
    });
  }, [currentSales, filterProvider, filterBrand, filterProduct, search]);

  const visibleAdvisorName = getUserLabel(
    currentSelectedAdvisorId,
    currentActiveSpecialist?.user?.name ?? currentActiveSpecialist?.user_name ?? 'Sin asignar'
  );

  const specialistCount = currentSpecialists.length;
  const activeSpecialistName = currentActiveSpecialist
    ? getUserLabel(
        currentActiveSpecialist.user_id,
        currentActiveSpecialist.user?.name ?? currentActiveSpecialist.user_name ?? 'Especialista activo'
      )
    : 'Sin especialista activo';

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 p-6">
        <div className="mx-auto max-w-7xl rounded-3xl border border-slate-200 bg-white px-6 py-8 shadow-sm">
          <div className="text-slate-600">Cargando datos…</div>
        </div>
      </div>
    );
  }

  const currentAdvisorBudgetLabel =
    currentAdvisorBudget > 0 ? formatUSD(currentAdvisorBudget) : 'Sin presupuesto';

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        <div className="mb-5 rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-100 px-5 py-5 sm:px-6">
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
                    Administración Comisiones
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">
                    Vista simple para revisar ventas, cumplimiento y comisión por línea.
                  </p>
                </div>

                <div className="flex gap-2">
                  <button
                    onClick={() => exportCsvFor(currentLine)}
                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                  >
                    Exportar CSV
                  </button>

                  <button
                    onClick={() => saveOverridesForLine(currentLine)}
                    disabled={savingOverrides[currentLine] || !currentSelectedAdvisorId}
                    className={`rounded-xl px-4 py-2 text-sm font-medium text-white transition ${
                      savingOverrides[currentLine] || !currentSelectedAdvisorId
                        ? 'cursor-not-allowed bg-slate-300'
                        : currentTheme.accent
                    }`}
                  >
                    {savingOverrides[currentLine] ? 'Guardando…' : 'Guardar comisiones'}
                  </button>
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <div>
                  <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                    Presupuesto
                  </label>
                  <select
                    value={selectedBudgetId ?? ''}
                    onChange={(e) => setSelectedBudgetId(e.target.value ? Number(e.target.value) : null)}
                    className="min-w-[260px] rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="">Selecciona presupuesto</option>
                    {budgets.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name} — {b.start_date} → {b.end_date}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="ml-auto flex gap-2">
                  <button
                    onClick={() => setViewLine('montblanc')}
                    className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
                      viewLine === 'montblanc'
                        ? 'bg-indigo-600 text-white shadow-sm'
                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    Montblanc
                  </button>
                  <button
                    onClick={() => setViewLine('parbel')}
                    className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
                      viewLine === 'parbel'
                        ? 'bg-emerald-600 text-white shadow-sm'
                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    Parbel
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div className="px-5 py-5 sm:px-6">
            {message && (
              <div
                className={`mb-4 rounded-2xl border px-4 py-3 text-sm ${
                  message.type === 'ok'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-rose-200 bg-rose-50 text-rose-700'
                }`}
              >
                {message.text}
              </div>
            )}

            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
              <StatCard label="Ventas USD" value={formatUSD(salesUsd)} sub="Total acumulado" />
              <StatCard label="Ventas COP" value={formatCOP(salesCop)} sub="Referencia contable" />
              <StatCard
                label="Cumplimiento"
                value={`${compliancePct.toFixed(2)}%`}
                sub={getCommissionLevel(currentTotals)}
              />
              <StatCard label="Comisión %" value={`${commissionPct.toFixed(2)}%`} sub="Tasa aplicada" />
              <StatCard label="Comisión USD" value={formatUSD(commissionUsd)} sub="Valor generado" />
              <StatCard label="Turnos" value={String(turnsCount || 0)} sub={`${ticketsCount} tickets`} />
            </div>

            <div className="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-12">
              <div className="xl:col-span-8 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <div className="text-sm text-slate-500">
                      Mostrando: <span className="font-semibold text-slate-900">{LINE_LABEL[currentLine]}</span>
                    </div>
                    <div className="mt-1 text-lg font-semibold text-slate-900">
                      {visibleAdvisorName}
                    </div>
                    <div className="mt-1 text-sm text-slate-500">
                      PPTO usuario: <span className="font-medium text-slate-700">{formatUSD(currentData?.user_budget_usd ?? 0)}</span>
                    </div>
                  </div>

                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-right">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Base actual
                    </div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">{currentAdvisorBudgetLabel}</div>
                    <button
                      onClick={() => saveAdvisorBudgetForLine(currentLine)}
                      disabled={savingAdvisorBudget[currentLine]}
                      className={`mt-3 rounded-xl px-4 py-2 text-sm font-medium text-white transition ${
                        savingAdvisorBudget[currentLine] ? 'cursor-not-allowed bg-slate-300' : currentTheme.accent
                      }`}
                    >
                      {savingAdvisorBudget[currentLine] ? 'Guardando…' : 'Guardar base'}
                    </button>
                  </div>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200">
                  <table className="w-full min-w-[900px] border-collapse">
                    <thead className="bg-slate-50">
                      <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th className="px-4 py-3">Categoría</th>
                        <th className="px-4 py-3">PPTO</th>
                        <th className="px-4 py-3">Ventas</th>
                        <th className="px-4 py-3">% Cumpl.</th>
                        <th className="px-4 py-3">% Comisión</th>
                        <th className="px-4 py-3">Comisión USD</th>
                      </tr>
                    </thead>
                    <tbody>
                      {currentCategories.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">
                            No hay categorías para la vista actual
                          </td>
                        </tr>
                      ) : (
                        currentCategories.map((c, idx) => {
                          const code = String(c.classification_code);
                          const ppto = Number(c.category_budget_usd_for_user ?? 0);
                          const sales = Number(c.sales_sum_usd ?? 0);
                          const pct = Number(c.pct_user_of_category_budget ?? 0);
                          const applied = currentOverrides[code] ?? Number(c.applied_commission_pct ?? 0);
                          const commUsd = Number(c.commission_sum_usd ?? 0);

                          return (
                            <tr key={`cat-${viewLine}-${code}-${idx}`} className="border-t border-slate-100">
                              <td className="px-4 py-3">
                                <div className="font-medium text-slate-900">{c.category ?? code}</div>
                                <div className="text-xs text-slate-500">{code}</div>
                              </td>
                              <td className="px-4 py-3 text-right text-sm text-slate-700">{formatUSD(ppto)}</td>
                              <td className="px-4 py-3 text-right text-sm text-slate-700">{formatUSD(sales)}</td>
                              <td className="px-4 py-3 text-right text-sm text-slate-700">{pct.toFixed(1)}%</td>
                              <td className="px-4 py-3 text-right">
                                <input
                                  type="number"
                                  step="0.01"
                                  min={0}
                                  value={currentOverrides[code] ?? applied}
                                  onChange={(e) =>
                                    setLineOverrides((prev) => ({
                                      ...prev,
                                      [currentLine]: {
                                        ...prev[currentLine],
                                        [code]: Number(e.target.value || 0),
                                      },
                                    }))
                                  }
                                  className="w-28 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                              </td>
                              <td className="px-4 py-3 text-right text-sm font-semibold text-emerald-700">
                                {formatUSD(commUsd)}
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>

                <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Total categorías
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">{currentCategories.length}</div>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Comisión estimada
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">
                      {formatUSD(commissionUsd)}
                    </div>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Nivel de cumplimiento
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">
                      {getCommissionLevel(currentTotals)}
                    </div>
                  </div>
                </div>

                <div className="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                  <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <h3 className="text-base font-semibold text-slate-900">Ventas detalladas</h3>
                      <p className="text-sm text-slate-500">
                        Filtra por proveedor, marca, producto o texto libre.
                      </p>
                    </div>
                    <div className="text-sm text-slate-500">{filteredSales.length} resultado(s)</div>
                  </div>

                  <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <input
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder="Buscar por producto o folio"
                      className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    />

                    <select
                      value={filterProvider}
                      onChange={(e) => setFilterProvider(e.target.value)}
                      className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="ALL">Todos los proveedores</option>
                      {providers.map((p) => (
                        <option key={String(p)} value={String(p)}>
                          {String(p)}
                        </option>
                      ))}
                    </select>

                    <select
                      value={filterBrand}
                      onChange={(e) => setFilterBrand(e.target.value)}
                      className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="ALL">Todas las marcas</option>
                      {brands.map((b) => (
                        <option key={String(b)} value={String(b)}>
                          {String(b)}
                        </option>
                      ))}
                    </select>

                    <select
                      value={filterProduct}
                      onChange={(e) => setFilterProduct(e.target.value)}
                      className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="ALL">Todos los productos</option>
                      {products.map((p) => (
                        <option key={String(p)} value={String(p)}>
                          {String(p)}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="mt-4 overflow-hidden rounded-2xl border border-slate-200">
                    <table className="w-full min-w-[980px] border-collapse">
                      <thead className="bg-slate-50">
                        <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                          <th className="px-4 py-3">Fecha</th>
                          <th className="px-4 py-3">Proveedor</th>
                          <th className="px-4 py-3">Marca</th>
                          <th className="px-4 py-3">Producto</th>
                          <th className="px-4 py-3">USD</th>
                          <th className="px-4 py-3">Comisión (COP)</th>
                        </tr>
                      </thead>
                      <tbody>
                        {filteredSales.length === 0 ? (
                          <tr>
                            <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">
                              No hay ventas (según filtros)
                            </td>
                          </tr>
                        ) : (
                          filteredSales.map((s, idx) => (
                            <tr
                              key={`sale-${viewLine}-${s.id ?? s.rowKey ?? idx}`}
                              className="border-t border-slate-100 hover:bg-slate-50/60"
                            >
                              <td className="px-4 py-3 text-sm text-slate-700">{s.sale_date ?? '—'}</td>
                              <td className="px-4 py-3 text-sm text-slate-700">{s.provider ?? '—'}</td>
                              <td className="px-4 py-3 text-sm text-slate-700">{s.brand ?? '—'}</td>
                              <td className="px-4 py-3 text-sm text-slate-700">
                                {s.product ?? s.folio ?? '—'}
                              </td>
                              <td className="px-4 py-3 text-right text-sm font-medium text-slate-900">
                                {formatUSD(s.value_usd ?? 0)}
                              </td>
                              <td className="px-4 py-3 text-right text-sm text-slate-700">
                                {formatCOP(s.commission_amount ?? 0)}
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <aside className="xl:col-span-4 space-y-4">
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <h3 className="text-base font-semibold text-slate-900">Especialistas por línea</h3>

                  <div className="mt-3 flex gap-2">
                    <button
                      onClick={() => setViewLine('montblanc')}
                      className={`rounded-xl px-3 py-1.5 text-sm font-medium transition ${
                        viewLine === 'montblanc'
                          ? 'bg-indigo-600 text-white'
                          : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                      }`}
                    >
                      Montblanc
                    </button>
                    <button
                      onClick={() => setViewLine('parbel')}
                      className={`rounded-xl px-3 py-1.5 text-sm font-medium transition ${
                        viewLine === 'parbel'
                          ? 'bg-emerald-600 text-white'
                          : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                      }`}
                    >
                      Parbel
                    </button>
                  </div>

                  <div className="mt-4 rounded-2xl bg-slate-50 p-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Activo
                    </div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">
                      {activeSpecialistName}
                    </div>
                  </div>

                  <div className="mt-4">
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                      Selecciona asesor
                    </label>
                    <select
                      value={currentSelectedAdvisorId ?? ''}
                      onChange={(e) =>
                        setSelectedAdvisorId((prev) => ({
                          ...prev,
                          [currentLine]: e.target.value ? Number(e.target.value) : null,
                        }))
                      }
                      className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="">Selecciona asesor</option>

                      {currentSpecialists.length > 0 && (
                        <optgroup label="Especialistas del presupuesto">
                          {currentSpecialists.map((s) => (
                            <option key={`spec-${currentLine}-${s.user_id}-${s.valid_from ?? ''}`} value={s.user_id}>
                              {getUserLabel(s.user_id, s.user?.name ?? s.user_name ?? `Usuario ${s.user_id}`)}
                            </option>
                          ))}
                        </optgroup>
                      )}

                      <optgroup label="Todos los vendedores">
                        {advisors.map((u) => (
                          <option key={`adv-${u.id}`} value={u.id}>
                            {u.name ?? u.display_name ?? `Usuario ${u.id}`}
                          </option>
                        ))}
                      </optgroup>
                    </select>

                    <button
                      onClick={() => currentSelectedAdvisorId && assignSpecialistForLine(currentLine, currentSelectedAdvisorId)}
                      disabled={assigningLine[currentLine] || !currentSelectedAdvisorId}
                      className={`mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-medium text-white transition ${
                        assigningLine[currentLine] || !currentSelectedAdvisorId
                          ? 'cursor-not-allowed bg-slate-300'
                          : currentTheme.accent
                      }`}
                    >
                      {assigningLine[currentLine]
                        ? 'Asignando…'
                        : `Asignar ${LINE_LABEL[currentLine]}`}
                    </button>
                  </div>

                  <div className="mt-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Historial
                    </div>
                    <div className="mt-2 max-h-44 space-y-2 overflow-auto pr-1">
                      {currentSpecialists.length ? (
                        currentSpecialists.map((h) => (
                          <div
                            key={`${currentLine}-${h.user_id}-${h.valid_from ?? ''}`}
                            className="rounded-xl border border-slate-200 px-3 py-2"
                          >
                            <div className="text-sm font-medium text-slate-900">
                              {getUserLabel(h.user_id, h.user?.name ?? h.user_name ?? `Usuario ${h.user_id}`)}
                            </div>
                            <div className="text-xs text-slate-500">
                              {h.valid_from ?? '-'} → {h.valid_to ?? 'activo'}
                            </div>
                          </div>
                        ))
                      ) : (
                        <div className="text-sm text-slate-400">Sin historial</div>
                      )}
                    </div>
                  </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        Presupuesto del asesor
                      </div>
                      <div className="mt-1 text-lg font-semibold text-slate-900">
                        {visibleAdvisorName}
                      </div>
                      <div className="mt-1 text-sm text-slate-500">
                        Base actual: {currentAdvisorBudgetLabel}
                      </div>
                    </div>
                  </div>

                  <div className="mt-4">
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                      Valor del presupuesto
                    </label>
                    <input
                      type="number"
                      min={0}
                      step={0.01}
                      value={currentAdvisorBudget}
                      onChange={(e) =>
                        setLineAdvisorBudget((prev) => ({
                          ...prev,
                          [currentLine]: Number(e.target.value || 0),
                        }))
                      }
                      className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <div className="mt-2 text-xs text-slate-500">
                      Este valor será la base para calcular la participación de la línea actual.
                    </div>

                    <button
                      onClick={() => saveAdvisorBudgetForLine(currentLine)}
                      disabled={savingAdvisorBudget[currentLine]}
                      className={`mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-medium text-white transition ${
                        savingAdvisorBudget[currentLine]
                          ? 'cursor-not-allowed bg-slate-300'
                          : currentTheme.accent
                      }`}
                    >
                      {savingAdvisorBudget[currentLine] ? 'Guardando…' : 'Guardar base'}
                    </button>
                  </div>

                  <div className={`mt-4 rounded-2xl ${currentTheme.accentSoft} p-4`}>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Vista activa
                    </div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">
                      {LINE_LABEL[currentLine]}
                    </div>
                  </div>

                  <div className="mt-4 grid grid-cols-2 gap-3">
                    <SmallStat label="Especialista activo" value={activeSpecialistName} />
                    <SmallStat label="Especialistas" value={String(specialistCount)} />
                  </div>
                </div>
              </aside>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
          <SmallStat label="Línea activa" value={LINE_LABEL[currentLine]} />
          <SmallStat label="Especialista" value={visibleAdvisorName} />
          <SmallStat label="Base presupuesto" value={formatUSD(currentAdvisorBudget || currentData?.user_budget_usd || 0)} />
          <SmallStat label="Cumplimiento" value={getCommissionLevel(currentTotals)} />
        </div>
      </div>
    </div>
  );
}