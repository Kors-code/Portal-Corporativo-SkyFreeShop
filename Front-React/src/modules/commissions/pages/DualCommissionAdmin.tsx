// DualCommissionAdmin.tsx
import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
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
};

type User = { id: number; name: string };

/* ================= COMPONENT ================= */

export default function DualCommissionAdmin({
  advisorAId: initialAdvisorAId,
  advisorBId: initialAdvisorBId,
  budgetIds: initialBudgetIds,
  onClose,
}: {
  advisorAId?: number;
  advisorBId?: number;
  budgetIds?: number[]; // optional initial selection(s)
  onClose: () => void;
}) {
  const [loading, setLoading] = useState(true);
  const [aData, setAData] = useState<SellerPayload | null>(null);
  const [bData, setBData] = useState<SellerPayload | null>(null);

  const [aOverrides, setAOverrides] = useState<Record<string, number>>({});
  const [bOverrides, setBOverrides] = useState<Record<string, number>>({});

  const [savingA, setSavingA] = useState(false);
  const [savingB, setSavingB] = useState(false);

  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  // filters / search for sales tables
  const [filterProviderA, setFilterProviderA] = useState<string>('ALL');
  const [filterBrandA, setFilterBrandA] = useState<string>('ALL');
  const [filterProductA, setFilterProductA] = useState<string>('ALL');
  const [searchA, setSearchA] = useState<string>('');

  const [filterProviderB, setFilterProviderB] = useState<string>('ALL');
  const [filterBrandB, setFilterBrandB] = useState<string>('ALL');
  const [filterProductB, setFilterProductB] = useState<string>('ALL');
  const [searchB, setSearchB] = useState<string>('');

  // budgets (to allow viewing per-budget)
  const [budgets, setBudgets] = useState<{ id: number; name: string }[]>([]);
  const [selectedBudgetId, setSelectedBudgetId] = useState<number | null>(initialBudgetIds && initialBudgetIds.length ? initialBudgetIds[0] : null);

  // specialists per line (used to filter advisor dropdowns)
  const [montSpecialists, setMontSpecialists] = useState<Specialist[]>([]);
  const [parbelSpecialists, setParbelSpecialists] = useState<Specialist[]>([]);

  // mapping userId -> name (from /users/sellers)
  const [usersMap, setUsersMap] = useState<Record<number, string>>({});

  // selected advisors (local controlled, inicializados desde props si vienen)
  const [selectedAId, setSelectedAId] = useState<number | null>(initialAdvisorAId ?? null); // Asesor A = Montblanc
  const [selectedBId, setSelectedBId] = useState<number | null>(initialAdvisorBId ?? null); // Asesor B = Parbel

  // split UI
  const [advisorAPct, setAdvisorAPct] = useState<number>(50);
  const [advisorBPct, setAdvisorBPct] = useState<number>(50);
  const [advisorSplit, setAdvisorSplit] = useState<any>(null);
  const [savingSplit, setSavingSplit] = useState(false);

  const budgetsKey = (initialBudgetIds || []).join(',');
console.log(budgetsKey)
  // money formatters
  const moneyUSD = (v: any) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(v || 0));
  const moneyCOP = (v: any) =>
    new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v || 0));

  const aCategories = useMemo(() => aData?.categories ?? [], [aData]);
  const bCategories = useMemo(() => bData?.categories ?? [], [bData]);

  const computeCommissionUsd = (salesUsd: number, pct: number) => {
    return Math.round((Number(salesUsd || 0) * (Number(pct || 0) / 100)) * 100) / 100;
  };

  // --- initial meta load: budgets + users ---
  useEffect(() => {
    (async () => {
      try {
        const [bRes, uRes] = await Promise.all([api.get('budgets'), api.get('/users/sellers')]);
        const budgetsList = Array.isArray(bRes.data) ? bRes.data : [];
        setBudgets(budgetsList);
        if (!selectedBudgetId && budgetsList.length) setSelectedBudgetId(budgetsList[0].id);

        const usersList: User[] = Array.isArray(uRes.data) ? uRes.data : [];
        const map: Record<number, string> = {};
        usersList.forEach(u => { if (u && u.id) map[u.id] = u.name; });
        setUsersMap(map);
      } catch (e) {
        console.warn('meta load failed', e);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // --- when selectedBudgetId changes: load specialists for each line and reload advisor data ---
  useEffect(() => {
    if (!selectedBudgetId) {
      setMontSpecialists([]);
      setParbelSpecialists([]);
      setAData(null);
      setBData(null);
      return;
    }

    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        // specialists per business line
        try {
          const [mRes, pRes] = await Promise.all([
            api.get('advisors/specialists', { params: { budget_id: selectedBudgetId, business_line: 'montblanc' } }),
            api.get('advisors/specialists', { params: { budget_id: selectedBudgetId, business_line: 'parbel' } }),
          ]);
          if (!cancelled) {
            setMontSpecialists(Array.isArray(mRes.data) ? mRes.data : []);
            setParbelSpecialists(Array.isArray(pRes.data) ? pRes.data : []);
            // if there is an active specialist and none selected, auto-select active ones
            const activeMont = (Array.isArray(mRes.data) ? mRes.data : []).find(s => !s.valid_to) ?? null;
            const activePar = (Array.isArray(pRes.data) ? pRes.data : []).find(s => !s.valid_to) ?? null;
            if (activeMont && !selectedAId) setSelectedAId(activeMont.user_id ?? null);
            if (activePar && !selectedBId) setSelectedBId(activePar.user_id ?? null);
          }
        } catch (e) {
          // ignore if endpoint unsupported
          console.warn('specialists fetch failed', e);
        }

        // load seller payloads for currently selected advisors (if any)
        if (selectedAId) {
          const resA = await loadSeller(selectedAId, selectedBudgetId);
          if (!cancelled) setAData(resA ?? null);
        } else {
          setAData(null);
        }
        if (selectedBId) {
          const resB = await loadSeller(selectedBId, selectedBudgetId);
          if (!cancelled) setBData(resB ?? null);
        } else {
          setBData(null);
        }
      } catch (err) {
        console.error('load on budget change failed', err);
        setMessage({ type: 'error', text: 'Error cargando datos para el presupuesto seleccionado' });
        setTimeout(() => setMessage(null), 3000);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedBudgetId]);

  // when selected advisor ids change, reload their seller payloads (using currently selectedBudgetId)
  useEffect(() => {
    if (!selectedBudgetId) return;
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        if (selectedAId) {
          const resA = await loadSeller(selectedAId, selectedBudgetId);
          if (!cancelled) setAData(resA ?? null);
        } else {
          setAData(null);
        }
        if (selectedBId) {
          const resB = await loadSeller(selectedBId, selectedBudgetId);
          if (!cancelled) setBData(resB ?? null);
        } else {
          setBData(null);
        }
      } catch (e) {
        console.warn('reload sellers on advisor change failed', e);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedAId, selectedBId]);

  // --- API helpers (updated to accept budgetId single) ---
  async function loadSeller(userId: number, budgetId: number) {
    const res = await api.get<SellerPayload>(`commissions/by-seller/${userId}`, {
      params: { budget_ids: [budgetId] },
    });
    return res.data;
  }

  async function fetchOverridesFor(userId: number, budgetId: number) {
    try {
      const res = await api.get('commissions/category-commissions/overrides', {
        params: { user_id: userId, budget_ids: [budgetId] },
      });
      const rows = res.data?.overrides ?? {};
      const merged: Record<string, number> = {};
      Object.keys(rows).forEach(bid => {
        const mapForBudget = rows[bid];
        Object.keys(mapForBudget).forEach((classification: string) => {
          const entry = mapForBudget[classification];
          const pct = Number(entry?.applied_commission_pct ?? entry?.applied_commission_pct === 0 ? entry.applied_commission_pct : null);
          if (!Number.isNaN(pct)) merged[String(classification)] = pct;
        });
      });
      return merged;
    } catch (e: any) {
      console.warn('No overrides for user', userId, e?.response?.status ?? e);
      return {};
    }
  }

  async function saveOverridesFor(
    userId: number,
    overridesMap: Record<string, number>,
    setSaving: (v: boolean) => void
  ) {
    if (!userId || !selectedBudgetId) return;
    setSaving(true);
    try {
      const overridesArray = Object.entries(overridesMap).map(([classification_code, applied_commission_pct]) => ({
        classification_code,
        applied_commission_pct: Number(applied_commission_pct ?? 0),
      }));
      const payload = { budget_ids: [selectedBudgetId], user_id: userId, overrides: overridesArray };
      await api.post('commissions/category-commissions/overrides', payload);
      setMessage({ type: 'ok', text: `Overrides guardados para user ${userId}` });

      // recargar data del user guardado
      const fresh = await loadSeller(userId, selectedBudgetId);
      if (userId === selectedAId) {
        setAData(fresh ?? null);
        const freshOverrides = await fetchOverridesFor(userId, selectedBudgetId);
        setAOverrides(prev => ({ ...freshOverrides, ...prev }));
      } else if (userId === selectedBId) {
        setBData(fresh ?? null);
        const freshOverrides = await fetchOverridesFor(userId, selectedBudgetId);
        setBOverrides(prev => ({ ...freshOverrides, ...prev }));
      }

      setTimeout(() => setMessage(null), 2200);
    } catch (e: any) {
      console.error('Error saving overrides', e);
      const status = e?.response?.status;
      let text = 'Error guardando overrides';
      if (status === 422) text = 'Datos inválidos al guardar overrides';
      if (status === 401 || status === 403) text = 'No autorizado (inicia sesión)';
      setMessage({ type: 'error', text });
      setTimeout(() => setMessage(null), 4200);
      console.log(e?.response?.data);
    } finally {
      setSaving(false);
    }
  }

  // export CSV helper (unchanged except uses selectedBudgetId for filename)
  function exportCsvFor(userLabel: string, categories: CategorySummary[], overrides: Record<string, number>) {
    const header = ['classification_code', 'category', 'ppto_usd', 'sales_usd', 'pct_cumpl', 'applied_pct', 'commission_usd'];
    const lines = [header.join(',')];
    categories.forEach(c => {
      const code = String(c.classification_code);
      const applied = overrides[code] ?? Number(c.applied_commission_pct ?? 0);
      const ppto = Number(c.category_budget_usd_for_user ?? 0);
      const sales = Number(c.sales_sum_usd ?? 0);
      const comm = computeCommissionUsd(sales, applied);
      lines.push([
        `"${code}"`,
        `"${(c.category ?? '').replace(/"/g, '""')}"`,
        ppto.toFixed(2),
        sales.toFixed(2),
        (Number(c.pct_user_of_category_budget || 0)).toFixed(2),
        applied.toFixed(3),
        comm.toFixed(2),
      ].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const budgetLabel = selectedBudgetId ?? 'all';
    a.download = `commissions_${userLabel}_budget_${budgetLabel}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  }

  // filtered sales (same)
  const providersA = useMemo(() => Array.from(new Set((aData?.sales || []).map(s => s.provider).filter(Boolean))), [aData]);
  const brandsA = useMemo(() => Array.from(new Set((aData?.sales || []).map(s => s.brand).filter(Boolean))), [aData]);
  const productsA = useMemo(() => Array.from(new Set((aData?.sales || []).map(s => s.product).filter(Boolean))), [aData]);

  const providersB = useMemo(() => Array.from(new Set((bData?.sales || []).map(s => s.provider).filter(Boolean))), [bData]);
  const brandsB = useMemo(() => Array.from(new Set((bData?.sales || []).map(s => s.brand).filter(Boolean))), [bData]);
  const productsB = useMemo(() => Array.from(new Set((bData?.sales || []).map(s => s.product).filter(Boolean))), [bData]);

  const filteredSalesA = useMemo(() => {
    return (aData?.sales || []).filter(s => {
      if (filterProviderA !== 'ALL' && String(s.provider ?? '') !== String(filterProviderA)) return false;
      if (filterBrandA !== 'ALL' && String(s.brand ?? '') !== String(filterBrandA)) return false;
      if (filterProductA !== 'ALL' && String(s.product ?? '') !== String(filterProductA)) return false;
      if (!searchA) return true;
      return `${s.product ?? ''} ${s.folio ?? ''}`.toLowerCase().includes(searchA.toLowerCase());
    });
  }, [aData, filterProviderA, filterBrandA, filterProductA, searchA]);

  const filteredSalesB = useMemo(() => {
    return (bData?.sales || []).filter(s => {
      if (filterProviderB !== 'ALL' && String(s.provider ?? '') !== String(filterProviderB)) return false;
      if (filterBrandB !== 'ALL' && String(s.brand ?? '') !== String(filterBrandB)) return false;
      if (filterProductB !== 'ALL' && String(s.product ?? '') !== String(filterProductB)) return false;
      if (!searchB) return true;
      return `${s.product ?? ''} ${s.folio ?? ''}`.toLowerCase().includes(searchB.toLowerCase());
    });
  }, [bData, filterProviderB, filterBrandB, filterProductB, searchB]);

  // --- advisor split helpers (use selectedAId / selectedBId and selectedBudgetId) ---
  const calculateAdvisorSplit = async () => {
    if (!selectedBudgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    if (!selectedAId || !selectedBId) { setMessage({ type: 'error', text: 'Selecciona ambos asesores (Montblanc/Parbel)' }); setTimeout(()=>setMessage(null),1500); return; }
    try {
      const res = await api.get('advisors/split-pool', {
        params: {
          budget_id: selectedBudgetId,
          advisor_a_id: selectedAId,
          advisor_b_id: selectedBId,
          advisor_a_pct: advisorAPct,
          advisor_b_pct: advisorBPct,
        }
      });
      setAdvisorSplit(res.data);
    } catch (e) {
      console.error('calc advisor split error', e);
      setMessage({ type: 'error', text: 'Error calculando split asesores' });
      setTimeout(()=>setMessage(null),1500);
    }
  };

  const saveAdvisorSplit = async () => {
    if (!selectedBudgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    if (!selectedAId || !selectedBId) { setMessage({ type: 'error', text: 'Selecciona ambos asesores (Montblanc/Parbel)' }); setTimeout(()=>setMessage(null),1500); return; }
    setSavingSplit(true);
    try {
      const payload = {
        budget_id: selectedBudgetId,
        advisor_a_id: selectedAId,
        advisor_a_pct: Number(advisorAPct || 0),
        advisor_b_id: selectedBId,
        advisor_b_pct: Number(advisorBPct || 0),
      };
      await api.post('advisors/save-split', payload);
      const res = await api.get('advisors/get-split', { params: { budget_id: selectedBudgetId } });
      setAdvisorSplit(res.data);
      setMessage({ type: 'ok', text: 'Distribución guardada' });
    } catch (e) {
      console.error('save split error', e);
      setMessage({ type: 'error', text: 'Error guardando distribución' });
    } finally {
      setSavingSplit(false);
      setTimeout(()=>setMessage(null),1800);
    }
  };

  const findUserName = (id?: number | null) => {
    if (!id) return '';
    return usersMap[id] ?? `User ${id}`;
  };

  if (loading) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div className="absolute inset-0 bg-black/40" onClick={onClose} />
        <div className="relative bg-white rounded-xl shadow p-8 text-center">
          <div className="text-gray-600">Cargando datos…</div>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-auto">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />

      <div className="relative bg-gray-50 rounded-2xl shadow-2xl w-full max-w-7xl p-6 overflow-visible">
        <div className="flex justify-between items-start mb-6">
          <div>
            <h2 className="text-2xl font-bold">Administración Comisiones — Comparativa</h2>
            <div className="text-sm text-gray-500">Presupuesto: &nbsp;
              <select value={selectedBudgetId ?? ''} onChange={e => setSelectedBudgetId(e.target.value ? Number(e.target.value) : null)} className="border px-2 py-1 rounded">
                <option value="">Selecciona presupuesto</option>
                {budgets.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
            </div>
          </div>

          <div className="flex gap-2">
            <button onClick={() => exportCsvFor(`advisor_${selectedAId ?? 'A'}`, aCategories, aOverrides)} className="px-3 py-2 rounded bg-sky-600 text-white">Export A CSV</button>
            <button onClick={() => exportCsvFor(`advisor_${selectedBId ?? 'B'}`, bCategories, bOverrides)} className="px-3 py-2 rounded bg-sky-600 text-white">Export B CSV</button>
            <button onClick={() => { setBOverrides(prev => ({ ...prev, ...aOverrides })); setMessage({ type: 'ok', text: 'Copiado A → B (recuerda guardar)' }); setTimeout(()=>setMessage(null),1500); }} className="px-3 py-2 rounded bg-amber-500 text-white">Copiar A → B</button>
            <button onClick={onClose} className="px-3 py-2 rounded bg-gray-200">Cerrar</button>
          </div>
        </div>

        {message && <div className={`mb-4 p-3 rounded ${message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.text}</div>}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

          {/* LEFT: Asesor A (Montblanc) */}
          <div className="bg-white rounded-2xl shadow p-4">
            <div className="flex items-center justify-between mb-3">
              <div>
                <div className="text-sm text-gray-500">Asesor A (Montblanc)</div>
                <div className="font-semibold">{findUserName(selectedAId) || `User ${selectedAId ?? ''}`}</div>
              </div>

              <div className="flex items-center gap-2">
                <div className="text-sm text-gray-500 text-right">
                  <div>PPTO usuario</div>
                  <div className="font-bold">{moneyUSD(aData?.user_budget_usd ?? 0)}</div>
                </div>
                <div>
                  <button onClick={() => saveOverridesFor(selectedAId ?? 0, aOverrides, setSavingA)} disabled={savingA || !selectedAId || !selectedBudgetId} className={`px-3 py-2 rounded ${savingA ? 'bg-gray-300 text-gray-600' : 'bg-emerald-600 text-white'}`}>{savingA ? 'Guardando...' : 'Guardar comisiones'}</button>
                </div>
              </div>
            </div>

            <div className="mb-3 grid grid-cols-1 sm:grid-cols-2 gap-2 items-center">
              <div>
                <label className="text-xs">Seleccionar Asesor (Montblanc)</label>
                <select value={selectedAId ?? ''} onChange={e => setSelectedAId(e.target.value ? Number(e.target.value) : null)} className="border rounded px-2 py-2 text-sm w-full">
                  <option value="">-- Selecciona Asesor Montblanc --</option>
                  {montSpecialists.map(s => <option key={`msp-${s.user_id}`} value={s.user_id}>{findUserName(s.user_id)}</option>)}
                </select>
              </div>

              <div>
                <label className="text-xs">Filtrar proveedor</label>
                <select value={filterProviderA} onChange={e => setFilterProviderA(e.target.value)} className="border rounded px-2 py-2 text-sm w-full">
                  <option value="ALL">Proveedor: Todos</option>
                  {providersA.map((p, i) => <option key={`provA-${p}-${i}`} value={p}>{p}</option>)}
                </select>
              </div>
            </div>

            {/* categories table */}
            <div className="overflow-x-auto mb-4">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-2 text-left">Categoría</th>
                    <th className="p-2 text-right">PPTO</th>
                    <th className="p-2 text-right">Ventas</th>
                    <th className="p-2 text-right">% Cumpl.</th>
                    <th className="p-2 text-right">% Comisión</th>
                    <th className="p-2 text-right">Comisión USD</th>
                  </tr>
                </thead>
                <tbody>
                  {aCategories.map((c, idx) => {
                    const code = String(c.classification_code);
                    const ppto = Number(c.category_budget_usd_for_user ?? 0);
                    const sales = Number(c.sales_sum_usd ?? 0);
                    const pct = Number(c.pct_user_of_category_budget ?? 0);
                    const applied = aOverrides[code] ?? Number(c.applied_commission_pct ?? 0);
                    const commUsd = computeCommissionUsd(sales, applied);
                    return (
                      <tr key={`a-cat-${code}-${idx}`} className="border-t">
                        <td className="p-2">{c.category ?? code}</td>
                        <td className="p-2 text-right">{moneyUSD(ppto)}</td>
                        <td className="p-2 text-right">{moneyUSD(sales)}</td>
                        <td className="p-2 text-right">{pct.toFixed(1)}%</td>
                        <td className="p-2 text-right">
                          <input type="number" step="0.01" value={applied} onChange={e => {
                            const v = Number(e.target.value || 0);
                            setAOverrides(prev => ({ ...prev, [code]: v }));
                          }} className="w-20 text-right border rounded px-1 py-1" /> %
                        </td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyUSD(commUsd)}</td>
                      </tr>
                    );
                  })}
                  {aCategories.length === 0 && (
                    <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay categorías para el Asesor A</td></tr>
                  )}
                </tbody>
              </table>
            </div>

            {/* sales list */}
            <div className="mb-3 grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
              <select value={filterBrandA} onChange={e => setFilterBrandA(e.target.value)} className="border rounded px-2 py-2 text-sm">
                <option value="ALL">Marca: Todas</option>
                {brandsA.map((b, i) => <option key={`brandA-${b}-${i}`} value={b}>{b}</option>)}
              </select>
              <select value={filterProductA} onChange={e => setFilterProductA(e.target.value)} className="border rounded px-2 py-2 text-sm">
                <option value="ALL">Producto: Todos</option>
                {productsA.map((p, i) => <option key={`prodA-${p}-${i}`} value={p}>{p}</option>)}
              </select>
              <input value={searchA} onChange={e => setSearchA(e.target.value)} placeholder="Buscar…" className="border rounded px-2 py-2 text-sm" />
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="p-2 text-left">Fecha</th>
                    <th className="p-2 text-left">Proveedor</th>
                    <th className="p-2 text-left">Marca</th>
                    <th className="p-2 text-left">Producto</th>
                    <th className="p-2 text-right">USD</th>
                    <th className="p-2 text-right">Comisión (COP)</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredSalesA.map((s, idx) => (
                    <tr key={`a-sale-${s.id ?? s.rowKey ?? idx}`} className="border-t hover:bg-gray-50">
                      <td className="p-2">{s.sale_date}</td>
                      <td className="p-2">{s.provider ?? '—'}</td>
                      <td className="p-2">{s.brand ?? '—'}</td>
                      <td className="p-2">{s.product ?? s.folio ?? '—'}</td>
                      <td className="p-2 text-right">{moneyUSD(s.value_usd ?? 0)}</td>
                      <td className="p-2 text-right">{moneyCOP(s.commission_amount ?? 0)}</td>
                    </tr>
                  ))}
                  {filteredSalesA.length === 0 && (
                    <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay ventas para el Asesor A (según filtros)</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* RIGHT: Asesor B (Parbel) */}
          <div className="bg-white rounded-2xl shadow p-4">
            <div className="flex items-center justify-between mb-3">
              <div>
                <div className="text-sm text-gray-500">Asesor B (Parbel)</div>
                <div className="font-semibold">{findUserName(selectedBId) || `User ${selectedBId ?? ''}`}</div>
              </div>

              <div className="flex items-center gap-2">
                <div className="text-sm text-gray-500 text-right">
                  <div>PPTO usuario</div>
                  <div className="font-bold">{moneyUSD(bData?.user_budget_usd ?? 0)}</div>
                </div>
                <div>
                  <button onClick={() => saveOverridesFor(selectedBId ?? 0, bOverrides, setSavingB)} disabled={savingB || !selectedBId || !selectedBudgetId} className={`px-3 py-2 rounded ${savingB ? 'bg-gray-300 text-gray-600' : 'bg-emerald-600 text-white'}`}>{savingB ? 'Guardando...' : 'Guardar comisiones'}</button>
                </div>
              </div>
            </div>

            <div className="mb-3 grid grid-cols-1 sm:grid-cols-2 gap-2 items-center">
              <div>
                <label className="text-xs">Seleccionar Asesor (Parbel)</label>
                <select value={selectedBId ?? ''} onChange={e => setSelectedBId(e.target.value ? Number(e.target.value) : null)} className="border rounded px-2 py-2 text-sm w-full">
                  <option value="">-- Selecciona Asesor Parbel --</option>
                  {parbelSpecialists.map(s => <option key={`psp-${s.user_id}`} value={s.user_id}>{findUserName(s.user_id)}</option>)}
                </select>
              </div>

              <div>
                <label className="text-xs">Filtrar proveedor</label>
                <select value={filterProviderB} onChange={e => setFilterProviderB(e.target.value)} className="border rounded px-2 py-2 text-sm w-full">
                  <option value="ALL">Proveedor: Todos</option>
                  {providersB.map((p, i) => <option key={`provB-${p}-${i}`} value={p}>{p}</option>)}
                </select>
              </div>
            </div>

            <div className="overflow-x-auto mb-4">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-2 text-left">Categoría</th>
                    <th className="p-2 text-right">PPTO</th>
                    <th className="p-2 text-right">Ventas</th>
                    <th className="p-2 text-right">% Cumpl.</th>
                    <th className="p-2 text-right">% Comisión</th>
                    <th className="p-2 text-right">Comisión USD</th>
                  </tr>
                </thead>
                <tbody>
                  {bCategories.map((c, idx) => {
                    const code = String(c.classification_code);
                    const ppto = Number(c.category_budget_usd_for_user ?? 0);
                    const sales = Number(c.sales_sum_usd ?? 0);
                    const pct = Number(c.pct_user_of_category_budget ?? 0);
                    const applied = bOverrides[code] ?? Number(c.applied_commission_pct ?? 0);
                    const commUsd = computeCommissionUsd(sales, applied);
                    return (
                      <tr key={`b-cat-${code}-${idx}`} className="border-t">
                        <td className="p-2">{c.category ?? code}</td>
                        <td className="p-2 text-right">{moneyUSD(ppto)}</td>
                        <td className="p-2 text-right">{moneyUSD(sales)}</td>
                        <td className="p-2 text-right">{pct.toFixed(1)}%</td>
                        <td className="p-2 text-right">
                          <input type="number" step="0.01" value={applied} onChange={e => {
                            const v = Number(e.target.value || 0);
                            setBOverrides(prev => ({ ...prev, [code]: v }));
                          }} className="w-20 text-right border rounded px-1 py-1" /> %
                        </td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyUSD(commUsd)}</td>
                      </tr>
                    );
                  })}
                  {bCategories.length === 0 && (
                    <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay categorías para el Asesor B</td></tr>
                  )}
                </tbody>
              </table>
            </div>

            <div className="mb-3 grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
              <select value={filterBrandB} onChange={e => setFilterBrandB(e.target.value)} className="border rounded px-2 py-2 text-sm">
                <option value="ALL">Marca: Todas</option>
                {brandsB.map((b, i) => <option key={`brandB-${b}-${i}`} value={b}>{b}</option>)}
              </select>
              <select value={filterProductB} onChange={e => setFilterProductB(e.target.value)} className="border rounded px-2 py-2 text-sm">
                <option value="ALL">Producto: Todos</option>
                {productsB.map((p, i) => <option key={`prodB-${p}-${i}`} value={p}>{p}</option>)}
              </select>
              <input value={searchB} onChange={e => setSearchB(e.target.value)} placeholder="Buscar…" className="border rounded px-2 py-2 text-sm" />
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="p-2 text-left">Fecha</th>
                    <th className="p-2 text-left">Proveedor</th>
                    <th className="p-2 text-left">Marca</th>
                    <th className="p-2 text-left">Producto</th>
                    <th className="p-2 text-right">USD</th>
                    <th className="p-2 text-right">Comisión (COP)</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredSalesB.map((s, idx) => (
                    <tr key={`b-sale-${s.id ?? s.rowKey ?? idx}`} className="border-t hover:bg-gray-50">
                      <td className="p-2">{s.sale_date}</td>
                      <td className="p-2">{s.provider ?? '—'}</td>
                      <td className="p-2">{s.brand ?? '—'}</td>
                      <td className="p-2">{s.product ?? s.folio ?? '—'}</td>
                      <td className="p-2 text-right">{moneyUSD(s.value_usd ?? 0)}</td>
                      <td className="p-2 text-right">{moneyCOP(s.commission_amount ?? 0)}</td>
                    </tr>
                  ))}
                  {filteredSalesB.length === 0 && (
                    <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay ventas para el Asesor B (según filtros)</td></tr>
                  )}
                </tbody>
              </table>
            </div>

            {/* Split panel (below tables for visibility) */}
            <div className="mt-4 border-t pt-3">
              <h4 className="font-semibold">Split Montblanc ↔ Parbel</h4>
              <div className="mt-2 text-sm text-gray-700">
                <div className="mb-2">
                  <div className="text-xxs text-gray-500">Asesor A (Montblanc)</div>
                  <div className="font-medium">{findUserName(selectedAId) || <em className="text-red-500">No seleccionado</em>}</div>
                  <div className="text-xs text-gray-500">PPTO: <strong>{moneyUSD(aData?.user_budget_usd ?? 0)}</strong></div>
                </div>

                <div className="mb-2">
                  <div className="text-xxs text-gray-500">Asesor B (Parbel)</div>
                  <div className="font-medium">{findUserName(selectedBId) || <em className="text-red-500">No seleccionado</em>}</div>
                  <div className="text-xs text-gray-500">PPTO: <strong>{moneyUSD(bData?.user_budget_usd ?? 0)}</strong></div>
                </div>

                <div className="grid grid-cols-2 gap-2 mb-2">
                  <div>
                    <label className="text-xs">Asesor A %</label>
                    <input type="number" min={0} max={100} value={advisorAPct} onChange={e => setAdvisorAPct(Number(e.target.value || 0))} className="w-full rounded px-2 py-1 text-sm border" />
                  </div>
                  <div>
                    <label className="text-xs">Asesor B %</label>
                    <input type="number" min={0} max={100} value={advisorBPct} onChange={e => setAdvisorBPct(Number(e.target.value || 0))} className="w-full rounded px-2 py-1 text-sm border" />
                  </div>
                </div>

                <div className="flex gap-2">
                  <button onClick={calculateAdvisorSplit} className="px-3 py-2 rounded text-white bg-indigo-600">Calcular distribución</button>
                  <button onClick={saveAdvisorSplit} disabled={savingSplit || !selectedAId || !selectedBId} className={`px-3 py-2 rounded text-white ${savingSplit ? 'bg-gray-400' : (!selectedAId || !selectedBId ? 'bg-gray-300 cursor-not-allowed' : 'bg-emerald-600')}`}>{savingSplit ? 'Guardando...' : 'Guardar distribución'}</button>
                </div>

                {advisorSplit && (
                  <div className="mt-2 text-sm text-gray-700">
                    <div><strong>Pool total:</strong> {Number(advisorSplit.advisor_pool_usd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                    <div>Asesor A: {Number(advisorSplit.advisor_a?.assigned_usd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                    <div>Asesor B: {Number(advisorSplit.advisor_b?.assigned_usd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                  </div>
                )}
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}