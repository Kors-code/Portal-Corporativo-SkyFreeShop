import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES (sin cambios) ================= */
type CategorySummary = { /* ...igual que antes... */ classification_code: string; category?: string; sales_sum_usd?: number; category_budget_usd_for_user?: number; pct_user_of_category_budget?: number; applied_commission_pct?: number; commission_sum_usd?: number; };
type SaleRow = { id?: number | string; sale_date?: string; folio?: string; product?: string; amount_cop?: number; value_usd?: number; provider?: string; brand?: string; category_code?: string; commission_amount?: number; rowKey?: string; };
type SellerPayload = { user?: any; categories?: CategorySummary[]; sales?: SaleRow[]; totals?: any; assigned_turns_for_user?: number; user_budget_usd?: number; days_worked?: any[]; };
type Specialist = { id?: number; budget_id: number; user_id: number; business_line?: string | null; category_id?: number | null; valid_from?: string; valid_to?: string | null; note?: string | null; user?: { id?: number; name?: string }; user_name?: string; };

/* ================= COMPONENT ================= */

export default function DualCommissionAdmin({
  advisorAId: initialAdvisorAId,
  advisorBId: initialAdvisorBId,
  budgetIds: initialBudgetIds,
  onClose,
}: {
  advisorAId?: number;
  advisorBId?: number;
  budgetIds?: number[];
  onClose?: () => void;
}) {
  // loading / data
  const [loading, setLoading] = useState(true);
  const [aData, setAData] = useState<SellerPayload | null>(null);
  const [bData, setBData] = useState<SellerPayload | null>(null);

  const [aOverrides, setAOverrides] = useState<Record<string, number>>({});
  const [bOverrides, setBOverrides] = useState<Record<string, number>>({});

  const [savingA, setSavingA] = useState(false);
  const [savingB, setSavingB] = useState(false);

  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);

  // filters / search for sales table
  const [filterProvider, setFilterProvider] = useState<string>('ALL');
  const [filterBrand, setFilterBrand] = useState<string>('ALL');
  const [filterProduct, setFilterProduct] = useState<string>('ALL');
  const [search, setSearch] = useState<string>('');

  // budgets (per-budget view)
  const [budgets, setBudgets] = useState<{ id: number; name: string }[]>([]);
  const [selectedBudgetId, setSelectedBudgetId] = useState<number | null>(initialBudgetIds && initialBudgetIds.length ? initialBudgetIds[0] : null);

  // specialists per line (options)
  const [montSpecialists, setMontSpecialists] = useState<Specialist[]>([]);
  const [parbelSpecialists, setParbelSpecialists] = useState<Specialist[]>([]);

  // mapping userId -> name
  const [usersMap, setUsersMap] = useState<Record<number, string>>({});

  // ALL advisors (from users table or budget-sellers)
  const [advisors, setAdvisors] = useState<any[]>([]);

  // selected advisors (these drive loadSeller)
  const [selectedAId, setSelectedAId] = useState<number | null>(initialAdvisorAId ?? null); // Montblanc
  const [selectedBId, setSelectedBId] = useState<number | null>(initialAdvisorBId ?? null); // Parbel

  // UI: which line's data to show in the big table (single-table mode)
  const [viewLine, setViewLine] = useState<'montblanc' | 'parbel'>('montblanc');

  // local caches for specialist active (for sidebar display & assign)
  const [activeMont, setActiveMont] = useState<Specialist | null>(null);
  const [activePar, setActivePar] = useState<Specialist | null>(null);
  const [historyMont, setHistoryMont] = useState<Specialist[]>([]);
  const [historyPar, setHistoryPar] = useState<Specialist[]>([]);
  const [assigning, setAssigning] = useState(false);

  // money formatters
  const moneyUSD = (v: any) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(v || 0));
  const moneyCOP = (v: any) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v || 0));

  const aCategories = useMemo(() => aData?.categories ?? [], [aData]);
  const bCategories = useMemo(() => bData?.categories ?? [], [bData]);

  const computeCommissionUsd = (salesUsd: number, pct: number) => {
    return Math.round((Number(salesUsd || 0) * (Number(pct || 0) / 100)) * 100) / 100;
  };

  // helper para construir mapping robusto userId -> name desde arrays variados
  const buildUsersMapFromArray = (arr: any[] = []) => {
    const map: Record<number, string> = {};
    arr.forEach(u => {
      const id = u?.id ?? u?.user?.id ?? u?.user_id;
      const name = u?.name ?? u?.user?.name ?? u?.user_name ?? u?.display_name ?? null;
      if (id && name) map[id] = name;
    });
    return map;
  };

  /* ---------------- INITIAL META LOAD (budgets + all advisors) ----------------
     - traemos budgets y lista general de sellers (advisors/budget-sellers sin budget_id)
     - dejamos loading = false para no bloquear la UI
  */
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [bRes, uRes] = await Promise.all([
          api.get('budgets'),
          api.get('advisors/budget-sellers') // listado general de vendedores
        ]);
        if (cancelled) return;

        const budgetsList = Array.isArray(bRes.data) ? bRes.data : [];
        setBudgets(budgetsList);
        if (!selectedBudgetId && budgetsList.length) setSelectedBudgetId(budgetsList[0].id);

        const usersList: any[] = Array.isArray(uRes.data) ? uRes.data : [];
        setAdvisors(usersList);
        const mapFromUsers = buildUsersMapFromArray(usersList);
        setUsersMap(prev => ({ ...prev, ...mapFromUsers }));
      } catch (e) {
        console.warn('meta load failed', e);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /* ---------------- when budget changes: load specialists (per line) AND advisors scoped to that budget ----------------
     - also decide active specialists and load their seller payloads
  */
  useEffect(() => {
    if (!selectedBudgetId) {
      setMontSpecialists([]);
      setParbelSpecialists([]);
      setAData(null);
      setBData(null);
      setActiveMont(null);
      setActivePar(null);
      setHistoryMont([]);
      setHistoryPar([]);
      setAOverrides({});
      setBOverrides({});
      return;
    }

    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        // fetch specialists by line (may fail independently)
        const [mRes, pRes, advisorsRes] = await Promise.all([
          api.get('advisors/specialists', { params: { budget_id: selectedBudgetId, business_line: 'montblanc' } }),
          api.get('advisors/specialists', { params: { budget_id: selectedBudgetId, business_line: 'parbel' } }),
          api.get('advisors/budget-sellers', { params: { budget_id: selectedBudgetId } }) // advisors filtered by budget
        ]);

        if (cancelled) return;

        const montList: Specialist[] = Array.isArray(mRes.data) ? mRes.data : [];
        const parList: Specialist[] = Array.isArray(pRes.data) ? pRes.data : [];
        const advisorsList: any[] = Array.isArray(advisorsRes.data) ? advisorsRes.data : [];

        // merge maps
        const montMap = buildUsersMapFromArray(montList.map(s => s.user ? s.user : { id: s.user_id, name: s.user_name }));
        const parMap = buildUsersMapFromArray(parList.map(s => s.user ? s.user : { id: s.user_id, name: s.user_name }));
        const advMap = buildUsersMapFromArray(advisorsList);

        setUsersMap(prev => ({ ...prev, ...montMap, ...parMap, ...advMap }));

        setMontSpecialists(montList);
        setParbelSpecialists(parList);
        setAdvisors(advisorsList);

        const activeM = montList.find((s: any) => !s.valid_to) ?? montList[0] ?? null;
        const activeP = parList.find((s: any) => !s.valid_to) ?? parList[0] ?? null;

        setActiveMont(activeM);
        setActivePar(activeP);
        setHistoryMont(montList);
        setHistoryPar(parList);

        // Determine which user ids to load
        const aIdToLoad = selectedAId ?? (activeM ? activeM.user_id : null);
        const bIdToLoad = selectedBId ?? (activeP ? activeP.user_id : null);

        // Keep selected* state in sync with active if not set before.
        if (!selectedAId && activeM?.user_id) setSelectedAId(activeM.user_id);
        if (!selectedBId && activeP?.user_id) setSelectedBId(activeP.user_id);

        // load initial seller payloads for resolved advisor ids
        if (aIdToLoad) {
          try {
            const resA = await loadSeller(aIdToLoad, selectedBudgetId, 'montblanc');
            if (!cancelled) {
              setAData(resA ?? null);
              const ovA = await fetchOverridesFor(aIdToLoad, selectedBudgetId);
              if (!cancelled) setAOverrides(ovA);
            }
          } catch (err) {
            if (!cancelled) { console.warn('load seller A failed', err); setAData(null); setAOverrides({}); }
          }
        } else {
          setAData(null); setAOverrides({});
        }

        if (bIdToLoad) {
          try {
            const resB = await loadSeller(bIdToLoad, selectedBudgetId, 'parbel');
            if (!cancelled) {
              setBData(resB ?? null);
              const ovB = await fetchOverridesFor(bIdToLoad, selectedBudgetId);
              if (!cancelled) setBOverrides(ovB);
            }
          } catch (err) {
            if (!cancelled) { console.warn('load seller B failed', err); setBData(null); setBOverrides({}); }
          }
        } else {
          setBData(null); setBOverrides({});
        }

      } catch (err) {
        console.error('load on budget change failed', err);
        if (!cancelled) setMessage({ type: 'error', text: 'Error cargando datos para el presupuesto seleccionado' });
        setTimeout(() => setMessage(null), 3000);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedBudgetId]);

  // reload seller payloads when selectedAId/selectedBId change
  useEffect(() => {
    if (!selectedBudgetId) return;
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        if (selectedAId) {
          try {
            const resA = await loadSeller(selectedAId, selectedBudgetId , 'montblanc');
            if (!cancelled) {
              setAData(resA ?? null);
              const ovA = await fetchOverridesFor(selectedAId, selectedBudgetId);
              if (!cancelled) setAOverrides(ovA);
            }
          } catch (err) {
            if (!cancelled) { console.warn('reload seller A failed', err); setAData(null); setAOverrides({}); }
          }
        } else {
          setAData(null); setAOverrides({});
        }

        if (selectedBId) {
          try {
            const resB = await loadSeller(selectedBId, selectedBudgetId, 'parbel');
            if (!cancelled) {
              setBData(resB ?? null);
              const ovB = await fetchOverridesFor(selectedBId, selectedBudgetId);
              if (!cancelled) setBOverrides(ovB);
            }
          } catch (err) {
            if (!cancelled) { console.warn('reload seller B failed', err); setBData(null); setBOverrides({}); }
          }
        } else {
          setBData(null); setBOverrides({});
        }
      } catch (e) {
        console.warn('reload sellers on advisor change failed', e);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [selectedAId, selectedBId, selectedBudgetId]);

  // --- API helpers (single-budget) ---
  async function loadSeller(
    userId: number,
    budgetId: number,
    line: 'montblanc' | 'parbel'
  ) {
    const res = await api.get('advisors/active-sales', {
      params: { budget_id: budgetId, business_line: line, user_id: userId }
    });

    const breakdown = res.data.breakdown || {};

    const categories: CategorySummary[] = Object.values(breakdown).map((row: any) => ({
      classification_code: row.classification_key,
      sales_sum_usd: row.sales_usd,
      category: row.classification_name,
      category_budget_usd_for_user: row.category_budget_usd_for_user ?? 0,
      pct_user_of_category_budget: row.pct_user_of_category_budget ?? 0,
      applied_commission_pct: row.applied_commission_pct ?? 0,
      commission_sum_usd: row.commission_usd ?? 0,
    }));

    // si la API devuelve el especialista con nombre, aprovechamos para mapearlo
    if (res.data?.specialist?.id && res.data.specialist?.name) {
      setUsersMap(prev => ({ ...prev, [res.data.specialist.id]: res.data.specialist.name }));
    }

    return {
      user: res.data.specialist,
      user_budget_usd: Number(res.data.user_budget_usd ?? 0),
      categories,
      sales: res.data.sales ?? [],
      totals: res.data.totals
    };
  }

  async function fetchOverridesFor(userId: number, budgetId: number) {
    try {
      const res = await api.get('commissions/category-commissions/overrides', { params: { user_id: userId, budget_ids: [budgetId] } });
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
      return {};
    }
  }

  async function saveOverridesFor(userId: number, overridesMap: Record<string, number>, setSaving: (v: boolean) => void) {
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

      // reload seller and overrides
      const line = userId === selectedAId ? 'montblanc' : 'parbel';
      const fresh = await loadSeller(userId, selectedBudgetId, line);
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
    } finally {
      setSaving(false);
    }
  }

  // export CSV helper (igual que antes)
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

  // filtered sales for currently visible advisor
  const currentData = viewLine === 'montblanc' ? aData : bData;
  const currentCategories = viewLine === 'montblanc' ? aCategories : bCategories;
  const currentOverrides = viewLine === 'montblanc' ? aOverrides : bOverrides;
  const currentSaveOverrides = viewLine === 'montblanc' ? ((v: Record<string, number>) => saveOverridesFor(selectedAId ?? 0, v, setSavingA)) : ((v: Record<string, number>) => saveOverridesFor(selectedBId ?? 0, v, setSavingB));

  const providers = useMemo(() => Array.from(new Set((currentData?.sales || []).map(s => s.provider).filter(Boolean))), [currentData]);
  const brands = useMemo(() => Array.from(new Set((currentData?.sales || []).map(s => s.brand).filter(Boolean))), [currentData]);
  const products = useMemo(() => Array.from(new Set((currentData?.sales || []).map(s => s.product).filter(Boolean))), [currentData]);

  const filteredSales = useMemo(() => {
    return (currentData?.sales || []).filter(s => {
      if (filterProvider !== 'ALL' && String(s.provider ?? '') !== String(filterProvider)) return false;
      if (filterBrand !== 'ALL' && String(s.brand ?? '') !== String(filterBrand)) return false;
      if (filterProduct !== 'ALL' && String(s.product ?? '') !== String(filterProduct)) return false;
      if (!search) return true;
      return `${s.product ?? ''} ${s.folio ?? ''}`.toLowerCase().includes(search.toLowerCase());
    });
  }, [currentData, filterProvider, filterBrand, filterProduct, search]);

  // --- Assign specialist for a line ---
  const assignSpecialistForLine = async (userIdToAssign: number, line: 'montblanc' | 'parbel') => {
    if (!selectedBudgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    setAssigning(true);
    try {
      await api.post('advisors/specialists', { budget_id: selectedBudgetId, user_id: userIdToAssign, business_line: line });
      // reload that line's specialists
      const res = await api.get('advisors/specialists', { params: { budget_id: selectedBudgetId, business_line: line } });
      const list: Specialist[] = Array.isArray(res.data) ? res.data : [];
      if (line === 'montblanc') {
        const active = list.find(s => !s.valid_to) ?? list[0] ?? null;
        setActiveMont(active);
        setHistoryMont(list);
        if (active?.user_id) {
          setSelectedAId(active.user_id);
        } else {
          setSelectedAId(userIdToAssign);
        }
      } else {
        const active = list.find(s => !s.valid_to) ?? list[0] ?? null;
        setActivePar(active);
        setHistoryPar(list);
        if (active?.user_id) {
          setSelectedBId(active.user_id);
        } else {
          setSelectedBId(userIdToAssign);
        }
      }

      // enrich usersMap (if specialist items contain user)
      const newMap = buildUsersMapFromArray(list.map(s => s.user ? s.user : { id: s.user_id, name: s.user_name }));
      setUsersMap(prev => ({ ...prev, ...newMap }));

      setMessage({ type: 'ok', text: 'Asesor asignado' });
    } catch (e) {
      console.error('assign error', e);
      setMessage({ type: 'error', text: 'Error asignando asesor' });
    } finally {
      setAssigning(false);
      setTimeout(() => setMessage(null), 1800);
    }
  };

  const findUserName = (id?: number | null) => {
    if (!id) return '';
    return usersMap[id] ?? `Usuario ${id}`;
  };

  // UI: loading indicator for page (not modal)
  if (loading) {
    return (
      <div className="p-6">
        <div className="text-gray-600">Cargando datos…</div>
      </div>
    );
  }

  // helpers to render the single selector section
  const currentActive = viewLine === 'montblanc' ? activeMont : activePar;
  const currentHistory = viewLine === 'montblanc' ? historyMont : historyPar;
  const currentOptions = viewLine === 'montblanc' ? montSpecialists : parbelSpecialists;

  // --- RENDER (page layout: header + single big table + sidebar) ---
  return (
    <div className="p-6 w-full max-w-7xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-2xl font-bold">Administración Comisiones — Comparativa</h2>
          <div className="text-sm text-gray-500">Presupuesto:
            <select value={selectedBudgetId ?? ''} onChange={e => setSelectedBudgetId(e.target.value ? Number(e.target.value) : null)} className="ml-3 border px-2 py-1 rounded">
              <option value="">Selecciona presupuesto</option>
              {budgets.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 bg-gray-100 rounded overflow-hidden">
            <button onClick={() => setViewLine('montblanc')} className={`px-3 py-2 ${viewLine === 'montblanc' ? 'bg-indigo-600 text-white' : 'text-gray-700'}`}>Montblanc</button>
            <button onClick={() => setViewLine('parbel')} className={`px-3 py-2 ${viewLine === 'parbel' ? 'bg-indigo-600 text-white' : 'text-gray-700'}`}>Parbel</button>
          </div>

          <button onClick={() => exportCsvFor(`advisor_${viewLine}`, currentCategories, currentOverrides)} className="px-3 py-2 rounded bg-sky-600 text-white">Export CSV</button>
          <button onClick={() => { (viewLine === 'montblanc' ? setBOverrides(prev => ({ ...prev, ...aOverrides })) : setAOverrides(prev => ({ ...prev, ...bOverrides }))); setMessage({ type: 'ok', text: 'Copiado (recuerda guardar)' }); setTimeout(()=>setMessage(null),1500); }} className="px-3 py-2 rounded bg-amber-500 text-white">Copiar a par</button>
          {onClose && <button onClick={onClose} className="px-3 py-2 rounded bg-gray-200">Cerrar</button>}
        </div>
      </div>

      {message && <div className={`mb-4 p-3 rounded ${message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.text}</div>}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* MAIN: big table (occupies 2 cols on lg) */}
        <div className="lg:col-span-2 bg-white rounded-2xl shadow p-4">
          <div className="flex items-center justify-between mb-4">
            <div>
              <div className="text-sm text-gray-500">Mostrando: <strong>{viewLine === 'montblanc' ? 'Montblanc' : 'Parbel'}</strong></div>
              <div className="text-lg font-semibold">
                {viewLine === 'montblanc'
                  ? (aData?.user?.name ?? '')
                  : (bData?.user?.name ?? '')}
              </div>
            </div>

            <div className="flex items-center gap-2">
              <div className="text-sm text-gray-500 text-right">
                <div>PPTO usuario</div>
                <div className="font-bold">{moneyUSD(currentData?.user_budget_usd ?? 0)}</div>
              </div>
              <div>
                <button onClick={() => currentSaveOverrides(currentOverrides)} disabled={(viewLine === 'montblanc' ? savingA : savingB) || !(viewLine === 'montblanc' ? selectedAId : selectedBId) || !selectedBudgetId} className={`px-3 py-2 rounded ${((viewLine === 'montblanc' ? savingA : savingB) ? 'bg-gray-300 text-gray-600' : 'bg-emerald-600 text-white')}`}>
                  {(viewLine === 'montblanc' ? savingA : savingB) ? 'Guardando...' : 'Guardar comisiones'}
                </button>
              </div>
            </div>
          </div>

          {/* categories table (single table) */}
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
                {currentCategories.map((c, idx) => {
                  const code = String(c.classification_code);
                  const ppto = Number(c.category_budget_usd_for_user ?? 0);
                  const sales = Number(c.sales_sum_usd ?? 0);
                  const pct = Number(c.pct_user_of_category_budget ?? 0);
                  const applied = currentOverrides[code] ?? Number(c.applied_commission_pct ?? 0);
                  const commUsd = Number(c.commission_sum_usd ?? 0);
                  return (
                    <tr key={`cat-${viewLine}-${code}-${idx}`} className="border-t">
                      <td className="p-2">{c.category ?? code}</td>
                      <td className="p-2 text-right">{moneyUSD(ppto)}</td>
                      <td className="p-2 text-right">{moneyUSD(sales)}</td>
                      <td className="p-2 text-right">{pct.toFixed(1)}%</td>
                      <td className="p-2 text-right">
                        {applied.toFixed(2)} %
                      </td>
                      <td className="p-2 text-right font-semibold text-green-600">{moneyUSD(commUsd)}</td>
                    </tr>
                  );
                })}
                {currentCategories.length === 0 && (
                  <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay categorías para la vista actual</td></tr>
                )}
              </tbody>
            </table>
          </div>

          {/* filters & sales list */}
          <div className="mb-3 grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
            <select value={filterProvider} onChange={e => setFilterProvider(e.target.value)} className="border rounded px-2 py-2 text-sm">
              <option value="ALL">Proveedor: Todos</option>
              {providers.map((p, i) => <option key={`prov-${p}-${i}`} value={p}>{p}</option>)}
            </select>
            <select value={filterBrand} onChange={e => setFilterBrand(e.target.value)} className="border rounded px-2 py-2 text-sm">
              <option value="ALL">Marca: Todas</option>
              {brands.map((b, i) => <option key={`brand-${b}-${i}`} value={b}>{b}</option>)}
            </select>
            <select value={filterProduct} onChange={e => setFilterProduct(e.target.value)} className="border rounded px-2 py-2 text-sm">
              <option value="ALL">Producto: Todos</option>
              {products.map((p, i) => <option key={`prod-${p}-${i}`} value={p}>{p}</option>)}
            </select>
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Buscar…" className="border rounded px-2 py-2 text-sm" />
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
                {filteredSales.map((s, idx) => (
                  <tr key={`sale-${viewLine}-${s.id ?? s.rowKey ?? idx}`} className="border-t hover:bg-gray-50">
                    <td className="p-2">{s.sale_date}</td>
                    <td className="p-2">{s.provider ?? '—'}</td>
                    <td className="p-2">{s.brand ?? '—'}</td>
                    <td className="p-2">{s.product ?? s.folio ?? '—'}</td>
                    <td className="p-2 text-right">{moneyUSD(s.value_usd ?? 0)}</td>
                    <td className="p-2 text-right">{moneyCOP(s.commission_amount ?? 0)}</td>
                  </tr>
                ))}
                {filteredSales.length === 0 && (
                  <tr><td colSpan={6} className="p-4 text-center text-gray-500">No hay ventas (según filtros)</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* SIDEBAR (selector especialistas) */}
        <aside className="space-y-4">
          <div className="bg-white shadow rounded p-4">
            <h3 className="font-semibold">Especialistas por línea</h3>
            <div className="mt-3">
              <div className="flex gap-2 mb-3">
                <button onClick={() => setViewLine('montblanc')} className={`px-3 py-1 rounded ${viewLine === 'montblanc' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Montblanc</button>
                <button onClick={() => setViewLine('parbel')} className={`px-3 py-1 rounded ${viewLine === 'parbel' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Parbel</button>
              </div>

              <div className="text-sm text-gray-600 mb-2">Activo: {currentActive ? findUserName(currentActive.user_id) : 'Ninguno'}</div>

              {/* <-- Aquí el cambio importante: usamos optgroups: primero especialistas (history), luego "Todos los vendedores" */}
              <select
                value={currentActive?.user_id ?? ''}
                onChange={e => {
                  const uid = Number(e.target.value || 0);
                  if (!uid) return;
                  if (viewLine === 'montblanc') {
                    setActiveMont(prev => ({ ...(prev ?? { budget_id: selectedBudgetId ?? 0, user_id: 0 }), user_id: uid, business_line: 'montblanc' }));
                    setSelectedAId(uid || null);
                  } else {
                    setActivePar(prev => ({ ...(prev ?? { budget_id: selectedBudgetId ?? 0, user_id: 0 }), user_id: uid, business_line: 'parbel' }));
                    setSelectedBId(uid || null);
                  }
                }}
                className="border rounded px-2 py-2 w-full mb-2"
              >
                <option value="">Selecciona asesor {viewLine === 'montblanc' ? 'Montblanc' : 'Parbel'}</option>

                {/* especialistas / historial (si existen) */}
                {currentOptions.length > 0 && <optgroup label="Especialistas (historial)">
                  {currentOptions.map(s => {
                    const label = findUserName(s.user_id) || `Usuario ${s.user_id}`;
                    return <option key={`spec-${s.user_id}`} value={s.user_id}>{label}</option>;
                  })}
                </optgroup>}

                {/* todos los vendedores (lista completa) */}
                <optgroup label="Todos los vendedores">
                  {advisors.map(u => (
                    <option key={`adv-${u.id}`} value={u.id}>
                      {u.name ?? u.display_name ?? `Usuario ${u.id}`}
                    </option>
                  ))}
                </optgroup>
              </select>

              <button onClick={() => currentActive?.user_id && assignSpecialistForLine(currentActive.user_id, viewLine)} disabled={assigning || !currentActive?.user_id} className={`w-full px-3 py-2 rounded text-white ${assigning ? 'bg-gray-400' : 'bg-emerald-600'}`}>
                {assigning ? 'Asignando...' : `Asignar ${viewLine === 'montblanc' ? 'Montblanc' : 'Parbel'}`}
              </button>

              <div className="mt-3 text-sm text-gray-600">
                <div className="font-medium">Historial</div>
                <div className="max-h-36 overflow-auto mt-2">
                  {currentHistory.length ? currentHistory.map(h => (
                    <div key={`${h.user_id}-${h.valid_from ?? ''}`} className="py-1 border-b last:border-b-0">
                      <div>{findUserName(h.user_id)}</div>
                      <div className="text-xxs text-gray-500">{h.valid_from ?? '-'} → {h.valid_to ?? 'activo'}</div>
                    </div>
                  )) : <div className="text-xs text-gray-400">Sin historial</div>}
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}