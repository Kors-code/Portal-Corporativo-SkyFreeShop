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

const LINE_ROLE_ID: Record<Line, number> = { montblanc: 5, parbel: 4 };
const LINE_LABEL: Record<Line, string> = { montblanc: 'Montblanc', parbel: 'Parbel' };
console.log(LINE_ROLE_ID)
const BRAND = {
  primary: 'bg-[#9C0E0E] hover:bg-[#7C0707]',
  primaryText: 'text-[#9C0E0E]',
  primarySoft: 'bg-[#FFF5F5] text-[#9C0E0E]',
  primaryBorder: 'border-[#9C0E0E]',
  ring: 'focus:ring-[#9C0E0E]',
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
  highlight = false,
}: {
  label: string;
  value: string;
  sub?: string;
  highlight?: boolean;
}) {
  return (
    <div
      className={`rounded-2xl border px-4 py-4 shadow-sm transition ${
        highlight
          ? 'border-[#9C0E0E]/30 bg-[#FFF5F5]'
          : 'border-slate-200 bg-white'
      }`}
    >
      <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </div>
      <div
        className={`mt-2 text-lg font-semibold ${
          highlight ? 'text-[#9C0E0E]' : 'text-slate-900'
        }`}
      >
        {value}
      </div>
      {sub ? <div className="mt-1 text-xs text-slate-500">{sub}</div> : null}
    </div>
  );
}

function TierBadge({ pct }: { pct: number }) {
  let label = 'Sin premio';
  let cls = 'bg-slate-100 text-slate-500';
  if (pct >= 120) {
    label = 'Tier 120%';
    cls = 'bg-[#9C0E0E] text-white';
  } else if (pct >= 100) {
    label = 'Tier 100%';
    cls = 'bg-[#C8102E] text-white';
  } else if (pct >= 80) {
    label = 'Tier 80%';
    cls = 'bg-[#FFD1D1] text-[#9C0E0E]';
  }
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${cls}`}>
      {label}
    </span>
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

  const [savingOverrides, setSavingOverrides] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [assigningLine, setAssigningLine] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [loadingAdvisorLine, setLoadingAdvisorLine] = useState<Record<Line, boolean>>({
    montblanc: false,
    parbel: false,
  });

  const [filterProvider, setFilterProvider] = useState<string>('ALL');
  const [filterBrand, setFilterBrand] = useState<string>('ALL');
  const [filterProduct, setFilterProduct] = useState<string>('ALL');
  const [search, setSearch] = useState<string>('');

  const currentLine = viewLine;
  const currentData = lineData[currentLine];
  const currentCategories = currentData?.categories ?? [];
  const currentSales = currentData?.sales ?? [];
  const currentOverrides = lineOverrides[currentLine];
  const currentSpecialists = lineSpecialists[currentLine];
  const currentActiveSpecialist = lineActive[currentLine];
  const currentSelectedAdvisorId = selectedAdvisorId[currentLine];

  const currentTotals = currentData?.totals ?? {};

  // ---------- CÁLCULO EN VIVO ----------
const liveTrm = useMemo(() => {
  const srvSalesUsd = num(currentTotals.total_sales_usd ?? currentTotals.sales_usd ?? 0);
  const srvSalesCop = num(currentTotals.total_sales_cop ?? currentTotals.sales_cop ?? 0);
  return (
    num(currentTotals.avg_trm) ||
    num((currentData as any)?.avg_trm) ||
    (srvSalesUsd > 0 ? srvSalesCop / srvSalesUsd : 0)
  );
}, [currentTotals, currentData]);

const liveBreakdown = useMemo(() => {
  return currentCategories.map((c) => {
    const code = String(c.classification_code);
    const sales = num(c.sales_sum_usd);
    const ppto = num(c.category_budget_usd_for_user);
    const appliedPct =
      currentOverrides[code] !== undefined
        ? Number(currentOverrides[code])
        : num(c.applied_commission_pct);
    const commUsd = sales * (appliedPct / 100);
    const commCop = commUsd * liveTrm;
    const pctCumpl = ppto > 0 ? (sales / ppto) * 100 : 0;
    return { ...c, code, sales, ppto, appliedPct, commUsd, commCop, pctCumpl };
  });
}, [currentCategories, currentOverrides, liveTrm]);

const liveTotals = useMemo(() => {
  let sumSales = 0, sumPpto = 0, sumComm = 0;
  for (const r of liveBreakdown) {
    sumSales += r.sales;
    sumPpto += r.ppto;
    sumComm += r.commUsd;
  }
  const compliance = sumPpto > 0 ? (sumSales / sumPpto) * 100 : 0;
  const weightedPct = sumSales > 0 ? (sumComm / sumSales) * 100 : 0;
  return {
    sumSales,
    sumPpto,
    sumComm,
    sumCommCop: sumComm * liveTrm,
    compliance,
    weightedPct,
    trm: liveTrm,
  };
}, [liveBreakdown, liveTrm]);

  const salesUsd =
    liveTotals.sumSales || num(currentTotals.total_sales_usd ?? currentTotals.sales_usd ?? 0);
  const salesCop = num(currentTotals.total_sales_cop ?? currentTotals.sales_cop ?? 0);
  const compliancePct = liveTotals.compliance;
  const commissionPct = liveTotals.weightedPct;
  const commissionUsd = liveTotals.sumComm;
  const commissionCop = liveTotals.sumCommCop;
  const ticketsCount = num(currentTotals.tickets_count ?? currentTotals.tickets ?? 0);
  const turnsCount = num(currentData?.assigned_turns_for_user ?? currentTotals.turns_count ?? 0);
console.log(ticketsCount)
console.log(turnsCount)
  const getCommissionLevel = (c: number) => {
    if (c >= 120) return 'Tier 120%';
    if (c >= 100) return 'Tier 100%';
    if (c >= 80) return 'Tier 80%';
    return 'Sin premio';
  };

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

  // ---------- API calls ----------
  async function loadSeller(userId: number, budgetId: number, line: Line): Promise<SellerPayload> {
    const res = await api.get('/advisors/active-sales', {
      params: { budget_id: budgetId, business_line: line, user_id: userId },
    });

    const breakdown = res.data?.breakdown || {};

    const categories: CategorySummary[] = Object.values(breakdown).map((row: any) => ({
      classification_code: String(row.classification_key ?? row.classification_code ?? ''),
      sales_sum_usd: num(row.sales_usd),
      sales_sum_cop: num(row.sales_cop),                          // ← nuevo
      category: row.classification_name ?? row.category_name ?? row.category ?? '',
      category_budget_usd_for_user: num(row.category_budget_usd_for_user ?? 0),
      category_budget_cop_for_user: num(row.category_budget_cop_for_user ?? 0), // ← nuevo
      pct_user_of_category_budget: num(row.pct_user_of_category_budget ?? 0),
      applied_commission_pct: num(row.applied_commission_pct ?? 0),
      commission_sum_usd: num(row.commission_usd ?? 0),
      commission_sum_cop: num(row.commission_cop ?? 0),           // ← nuevo
    }));

    if (res.data?.specialist?.id && res.data.specialist?.name) {
      setUsersMap((prev) => ({
        ...prev,
        [Number(res.data.specialist.id)]: res.data.specialist.name,
      }));
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
          const pct = Number(mapForBudget[classification]?.applied_commission_pct);
          if (!Number.isNaN(pct)) merged[String(classification)] = pct;
        });
      });

      return merged;
    } catch {
      return {};
    }
  }

  async function loadLineState(line: Line, budgetId: number) {
    const specialistRes = await api.get('/advisors/specialists', {
      params: { budget_id: budgetId, business_line: line, include_inherited: 1 },
    });

    const specialistPayload = specialistRes.data;
    let specialists: Specialist[] = Array.isArray(specialistPayload)
      ? specialistPayload
      : Array.isArray(specialistPayload?.rows)
        ? specialistPayload.rows
        : [];
    const inheritedSpecialist: Specialist | null = Array.isArray(specialistPayload)
      ? null
      : specialistPayload?.inherited ?? null;
    const active = specialists.find((s) => !s.valid_to) ?? specialists[0] ?? null;

    if (!active && inheritedSpecialist?.user_id) {
      await api.post('/advisors/specialists', {
        budget_id: budgetId,
        user_id: inheritedSpecialist.user_id,
        business_line: line,
        note: `Asignado automaticamente desde ${inheritedSpecialist.user_name ?? 'asesor anterior'}`,
      });

      const refreshedRes = await api.get('/advisors/specialists', {
        params: { budget_id: budgetId, business_line: line },
      });
      specialists = Array.isArray(refreshedRes.data) ? refreshedRes.data : [];
    }

    const resolvedActive = specialists.find((s) => !s.valid_to) ?? specialists[0] ?? null;

    setLineSpecialists((prev) => ({ ...prev, [line]: specialists }));
    setLineActive((prev) => ({ ...prev, [line]: resolvedActive }));
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
      : resolvedActive?.user_id ?? specialists[0]?.user_id ?? null;

    if (resolvedAdvisorId && resolvedAdvisorId !== selectedAdvisorId[line]) {
      setSelectedAdvisorId((prev) => ({ ...prev, [line]: Number(resolvedAdvisorId) }));
    }

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

  const selectAdvisorForLine = async (line: Line, userId: number | null) => {
    setSelectedAdvisorId((prev) => ({ ...prev, [line]: userId }));

    if (!selectedBudgetId || !userId) {
      setLineData((prev) => ({ ...prev, [line]: null }));
      setLineOverrides((prev) => ({ ...prev, [line]: {} }));
      return;
    }

    setLoadingAdvisorLine((prev) => ({ ...prev, [line]: true }));
    try {
      const [payload, overrides] = await Promise.all([
        loadSeller(userId, selectedBudgetId, line),
        fetchOverridesFor(userId, selectedBudgetId),
      ]);

      setLineData((prev) => ({ ...prev, [line]: payload }));
      setLineOverrides((prev) => ({ ...prev, [line]: overrides }));
    } catch (e) {
      console.error('advisor preview load failed', e);
      setMessage({ type: 'error', text: 'Error cargando datos del asesor seleccionado' });
      setTimeout(() => setMessage(null), 2600);
    } finally {
      setLoadingAdvisorLine((prev) => ({ ...prev, [line]: false }));
    }
  };

  useEffect(() => {
    let mounted = true;

    async function loadMeta() {
      try {
        const bRes = await api.get('/budgets');
        if (!mounted) return;

        const budgetsList = Array.isArray(bRes.data) ? bRes.data : [];

        setBudgets(budgetsList);

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
      setAdvisors([]);
      return;
    }

    let mounted = true;

    api.get('/advisors/budget-sellers', { params: { budget_id: selectedBudgetId } })
      .then((res) => {
        if (!mounted) return;
        const usersList = Array.isArray(res.data) ? res.data : [];
        setAdvisors(usersList);
        setUsersMap((prev) => ({ ...prev, ...buildUsersMapFromArray(usersList) }));
      })
      .catch((e) => {
        console.warn('advisors load failed', e);
        if (mounted) setAdvisors([]);
      });

    return () => {
      mounted = false;
    };
  }, [selectedBudgetId]);

  useEffect(() => {
    if (!selectedBudgetId) {
      setLineData({ montblanc: null, parbel: null });
      setLineOverrides({ montblanc: {}, parbel: {} });
      setLineSpecialists({ montblanc: [], parbel: [] });
      setLineActive({ montblanc: null, parbel: null });
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

    const overrides = lineOverrides[line];

    const header = [
      'classification_code',
      'category',
      'sales_usd',
      'category_budget_usd',
      'pct_cumplimiento',
      'applied_pct',
      'commission_usd',
    ];

    const lines = [header.join(',')];

    (payload.categories ?? []).forEach((c) => {
      const code = String(c.classification_code);
      const sales = num(c.sales_sum_usd);
      const ppto = num(c.category_budget_usd_for_user);
      const applied =
        overrides[code] !== undefined ? Number(overrides[code]) : num(c.applied_commission_pct);
      const commUsd = sales * (applied / 100);
      const pct = ppto > 0 ? (sales / ppto) * 100 : 0;

      lines.push(
        [
          `"${code}"`,
          `"${String(c.category ?? '').replace(/"/g, '""')}"`,
          sales.toFixed(2),
          ppto.toFixed(2),
          pct.toFixed(2),
          applied.toFixed(3),
          commUsd.toFixed(2),
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

  const formatTrm = (v: number) =>
  new Intl.NumberFormat('es-CO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(v);

  const activeSpecialistName = currentActiveSpecialist
    ? getUserLabel(
        currentActiveSpecialist.user_id,
        currentActiveSpecialist.user?.name ??
          currentActiveSpecialist.user_name ??
          'Especialista activo'
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

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        <div className="mb-5 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          {/* Header */}
          <div className="border-b border-slate-100 bg-gradient-to-r from-white via-white to-[#FFF5F5] px-5 py-5 sm:px-6">
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                  <div className="mt-1 h-10 w-1 rounded-full bg-[#9C0E0E]" />
                  <div>
                    <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
                      Administración de Comisiones
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">
                      Revisa ventas, cumplimiento y comisión por línea.
                    </p>
                  </div>
                </div>

                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => exportCsvFor(currentLine)}
                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                  >
                    Exportar CSV
                  </button>

                  <button
                    type="button"
                    onClick={() => saveOverridesForLine(currentLine)}
                    disabled={savingOverrides[currentLine] || !currentSelectedAdvisorId}
                    className={`rounded-xl px-4 py-2 text-sm font-medium text-white shadow-sm transition ${
                      savingOverrides[currentLine] || !currentSelectedAdvisorId
                        ? 'cursor-not-allowed bg-slate-300'
                        : BRAND.primary
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
                    onChange={(e) =>
                      setSelectedBudgetId(e.target.value ? Number(e.target.value) : null)
                    }
                    className={`min-w-[280px] rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 ${BRAND.ring}`}
                  >
                    <option value="">Selecciona presupuesto</option>
                    {budgets.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name} — {b.start_date} → {b.end_date}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="ml-auto flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
                  <button
                    type="button"
                    onClick={() => setViewLine('montblanc')}
                    className={`rounded-lg px-4 py-1.5 text-sm font-medium transition ${
                      viewLine === 'montblanc'
                        ? 'bg-[#9C0E0E] text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900'
                    }`}
                  >
                    Montblanc
                  </button>
                  <button
                    type="button"
                    onClick={() => setViewLine('parbel')}
                    className={`rounded-lg px-4 py-1.5 text-sm font-medium transition ${
                      viewLine === 'parbel'
                        ? 'bg-[#9C0E0E] text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900'
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
                    ? 'border-[#9C0E0E]/20 bg-[#FFF5F5] text-[#9C0E0E]'
                    : 'border-red-300 bg-red-50 text-red-800'
                }`}
              >
                {message.text}
              </div>
            )}

            {/* Stat cards */}
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
              <StatCard label="Ventas USD" value={formatUSD(salesUsd)} sub="Total acumulado" />
              <StatCard label="Ventas COP" value={formatCOP(salesCop)} sub="Referencia contable" />
              <StatCard
                label="Cumplimiento"
                value={`${compliancePct.toFixed(2)}%`}
                sub={getCommissionLevel(compliancePct)}
              />
              <StatCard
                label="Comisión %"
                value={`${commissionPct.toFixed(2)}%`}
                sub="Tasa ponderada"
              />
              <StatCard
                  label="TRM Promedio"
                  value={liveTrm > 0 ? formatTrm(liveTrm) : '—'}
                  sub="Promedio del periodo"
                />
              <StatCard
                label="Comisión USD"
                value={formatUSD(commissionUsd)}
                sub={formatCOP(commissionCop)}
                highlight
              />
              <StatCard
                label="Comisión COP"
                value={formatCOP(commissionCop)}

              />
            </div>

            <div className="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-12">
              {/* Left main panel */}
              <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-8">
                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                  <div>
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                      Línea {LINE_LABEL[currentLine]}
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">
                      {loadingAdvisorLine[currentLine] ? 'Cargando asesor...' : visibleAdvisorName}
                    </div>
                    <div className="mt-1 text-sm text-slate-500">
                      PPTO usuario:{' '}
                      <span className="font-semibold text-[#9C0E0E]">
                        {formatUSD(currentData?.user_budget_usd ?? 0)}
                      </span>
                      
                    </div>
                  </div>

                  <TierBadge pct={compliancePct} />
                </div>

                {/* Tabla categorías */}
                <div className="overflow-hidden rounded-2xl border border-slate-200">
                  <table className="w-full min-w-[820px] border-collapse">
                    <thead className="bg-slate-50">
                      <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th className="px-4 py-3">Categoría</th>
                        <th className="px-4 py-3 text-right">PPTO</th>
                        <th className="px-4 py-3 text-right">Ventas</th>
                        <th className="px-4 py-3 text-right">% Cumpl.</th>
                        <th className="px-4 py-3 text-right">% Comisión</th>
                        <th className="px-4 py-3 text-right">Comisión USD</th>
                        <th className="px-4 py-3 text-right">Comisión COP</th>
                      </tr>
                    </thead>
                    <tbody>
                      {liveBreakdown.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">
                            No hay categorías para la vista actual
                          </td>
                        </tr>
                      ) : (
                        liveBreakdown.map((row, idx) => (
                          <tr
                            key={`cat-${viewLine}-${row.code}-${idx}`}
                            className="border-t border-slate-100 hover:bg-[#FFF5F5]/40"
                          >
                            <td className="px-4 py-3">
                              <div className="font-medium text-slate-900">
                                {row.category ?? row.code}
                              </div>
                              <div className="text-xs text-slate-400">{row.code}</div>
                            </td>
                            <td className="px-4 py-3 text-right text-sm text-slate-700">
                              {formatUSD(row.ppto)}
                            </td>
                            <td className="px-4 py-3 text-right text-sm text-slate-700">
                              {formatUSD(row.sales)}
                            </td>
                            <td className="px-4 py-3 text-right text-sm text-slate-700">
                              {row.pctCumpl.toFixed(1)}%
                            </td>
                            <td className="px-4 py-3 text-right">
                              <input
                                type="number"
                                step="0.01"
                                min={0}
                                value={
                                  currentOverrides[row.code] !== undefined
                                    ? currentOverrides[row.code]
                                    : row.appliedPct
                                }
                                onChange={(e) =>
                                  setLineOverrides((prev) => ({
                                    ...prev,
                                    [currentLine]: {
                                      ...prev[currentLine],
                                      [row.code]: Number(e.target.value || 0),
                                    },
                                  }))
                                }
                                className={`w-24 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-right text-sm outline-none focus:ring-2 ${BRAND.ring}`}
                              />
                            </td>
                            <td className="px-4 py-3 text-right text-sm font-semibold text-[#9C0E0E]">
                              {formatUSD(row.commUsd)}
                            </td>
                            <td className="px-4 py-3 text-right text-sm text-slate-700">
                              {formatCOP(row.commCop)}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Summary mini stats */}
                <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Total categorías
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">
                      {liveBreakdown.length}
                    </div>
                  </div>
                  <div className="rounded-2xl border border-[#9C0E0E]/20 bg-[#FFF5F5] px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-[#9C0E0E]/80">
                      Comisión estimada
                    </div>
                    <div className="mt-1 text-xl font-semibold text-[#9C0E0E]">
                      {formatUSD(commissionUsd)}
                    </div>
                    <div className="mt-1 text-xs text-[#9C0E0E]/70">
                      {formatCOP(commissionCop)}
                    </div>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Tier alcanzado
                    </div>
                    <div className="mt-1 text-xl font-semibold text-slate-900">
                      {getCommissionLevel(compliancePct)}
                    </div>
                  </div>
                </div>

                {/* Ventas detalladas */}
                <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
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
                      className={`rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 ${BRAND.ring}`}
                    />

                    <select
                      value={filterProvider}
                      onChange={(e) => setFilterProvider(e.target.value)}
                      className={`rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 ${BRAND.ring}`}
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
                      className={`rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 ${BRAND.ring}`}
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
                      className={`rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 ${BRAND.ring}`}
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
                          <th className="px-4 py-3 text-right">USD</th>
                          <th className="px-4 py-3 text-right">Comisión (COP)</th>
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

              {/* Right aside: especialistas */}
              <aside className="xl:col-span-4">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                  <div className="flex items-center gap-2">
                    <div className="h-6 w-1 rounded-full bg-[#9C0E0E]" />
                    <h3 className="text-base font-semibold text-slate-900">
                      Especialistas por línea
                    </h3>
                  </div>

                  <div className={`mt-4 rounded-2xl ${BRAND.primarySoft} p-4`}>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-[#9C0E0E]/80">
                      Activo · {LINE_LABEL[currentLine]}
                    </div>
                    <div className="mt-1 text-sm font-semibold text-[#9C0E0E]">
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
                        selectAdvisorForLine(
                          currentLine,
                          e.target.value ? Number(e.target.value) : null
                        )
                      }
                      disabled={loadingAdvisorLine[currentLine]}
                      className={`w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 ${BRAND.ring}`}
                    >
                      <option value="">Selecciona asesor</option>

                      {currentSpecialists.length > 0 && (
                        <optgroup label="Especialistas del presupuesto">
                          {currentSpecialists.map((s) => (
                            <option
                              key={`spec-${currentLine}-${s.user_id}-${s.valid_from ?? ''}`}
                              value={s.user_id}
                            >
                              {getUserLabel(
                                s.user_id,
                                s.user?.name ?? s.user_name ?? `Usuario ${s.user_id}`
                              )}
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
                      type="button"
                      onClick={() =>
                        currentSelectedAdvisorId &&
                        assignSpecialistForLine(currentLine, currentSelectedAdvisorId)
                      }
                      disabled={assigningLine[currentLine] || loadingAdvisorLine[currentLine] || !currentSelectedAdvisorId}
                      className={`mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-medium text-white shadow-sm transition ${
                        assigningLine[currentLine] || loadingAdvisorLine[currentLine] || !currentSelectedAdvisorId
                          ? 'cursor-not-allowed bg-slate-300'
                          : BRAND.primary
                      }`}
                    >
                      {assigningLine[currentLine]
                        ? 'Asignando…'
                        : loadingAdvisorLine[currentLine]
                          ? 'Cargando asesor…'
                        : `Asignar a ${LINE_LABEL[currentLine]}`}
                    </button>
                  </div>

                  <div className="mt-5">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Historial
                    </div>
                    <div className="mt-2 max-h-56 space-y-2 overflow-auto pr-1">
                      {currentSpecialists.length ? (
                        currentSpecialists.map((h) => (
                          <div
                            key={`${currentLine}-${h.user_id}-${h.valid_from ?? ''}`}
                            className={`rounded-xl border px-3 py-2 ${
                              !h.valid_to
                                ? 'border-[#9C0E0E]/30 bg-[#FFF5F5]'
                                : 'border-slate-200 bg-white'
                            }`}
                          >
                            <div className="text-sm font-medium text-slate-900">
                              {getUserLabel(
                                h.user_id,
                                h.user?.name ?? h.user_name ?? `Usuario ${h.user_id}`
                              )}
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
              </aside>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
