// src/modules/commissions/pages/CategoryCommissionsPage.tsx
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../../api/axios';

import {
  getCategoriesWithCommission,
  upsertCategoryCommission,
  bulkSaveCategoryCommissions,
  deleteCategoryCommission,
  getRoles,
  getBudgets
} from '../services/categoryCommissionService';

import type { CategoryWithCommission, Role } from '../types/comissionscategory';

/**
 * CategoryCommissionsPage
 * - Mantiene el comportamiento anterior.
 * - Si DB sólo trae participation_pct calculamos participation_value al cargar.
 * - participation_pct es sólo visual (readOnly).
 *
 * Ajustes:
 * - UI mejorada para el "Split" (más limpia).
 * - Los porcentajes ingresados para A/B se respetan tal cual (no se normalizan).
 * - Total participación redondeado a 2 decimales (para validación y color).
 * - Valor participación mostrado sin decimales (redondeo integer).
 * - Se agrega "Total presupuesto asignado" (2 decimales).
 */

export default function CategoryCommissionsPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [roleId, setRoleId] = useState<number | null>(null);

  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  const [items, setItems] = useState<CategoryWithCommission[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingIds, setSavingIds] = useState<number[]>([]);
  const [message, setMessage] = useState<{ type: 'ok'|'error', text: string } | null>(null);

  const navigate = useNavigate();

  const [dirtyIds, setDirtyIds] = useState<Set<number>>(new Set());

  const normalizeCategoryName = (name: string) => {
    const n = String(name).toLowerCase();
    if (n.includes('frag')) return 'FRAGANCIA';
    return name;
  };

  // ----- Roles & budgets load -----
  useEffect(() => {
    let mounted = true;
    async function loadMeta() {
      try {
        const [rolesData, budgetsData] = await Promise.all([getRoles(), getBudgets()]);
        if (!mounted) return;
        setRoles(Array.isArray(rolesData) ? rolesData : []);
        setBudgets(Array.isArray(budgetsData) ? budgetsData : []);

        if (Array.isArray(rolesData) && rolesData.length) {
          const vendedor = rolesData.find(r => r.name.toLowerCase().includes('vendedor') && r.id !== 2);
          const fallback = rolesData.find(r => r.id !== 2);
          setRoleId(vendedor ? vendedor.id : (fallback ? fallback.id : rolesData[0].id));
        }
        if (Array.isArray(budgetsData) && budgetsData.length) setBudgetId(prev => prev ?? budgetsData[0].id);
      } catch (err) {
        console.error('Error cargando roles/presupuestos', err);
        setRoles([]); setBudgets([]);
      }
    }
    loadMeta();
    return () => { mounted = false; };
  }, []);

  const sellerRoles = useMemo(() => roles.filter(r => r.id !== 2), [roles]);

  // ---- Helper: obtener total del presupuesto global ----
  const getBudgetTotal = (bId?: number | null) => {
    if (!bId) return 0;
    const b = budgets.find(b => b.id === bId);
    if (!b) return 0;
    const candidate = Number(b.target_amount ?? b.total ?? b.total_usd ?? b.budget_usd ?? b.value ?? 0);
    return isNaN(candidate) ? 0 : candidate;
  };

  // ---------------------------
  // Split Montblanc (A) <-> Parbel (B)
  // ---------------------------
  const [montSpecialists, setMontSpecialists] = useState<any[]>([]);
  const [parbelSpecialists, setParbelSpecialists] = useState<any[]>([]);
  const [activeMont, setActiveMont] = useState<any | null>(null);
  const [activePar, setActivePar] = useState<any | null>(null);
  console.log(montSpecialists)
  console.log(parbelSpecialists)
  console.log(activeMont)
  console.log(activePar)
  const [selectedAId, setSelectedAId] = useState<number | null>(null); // Montblanc user id (A)
  const [selectedBId, setSelectedBId] = useState<number | null>(null); // Parbel user id (B)

  const [aUserBudgetUsd, setAUserBudgetUsd] = useState<number>(0); // Montblanc budget
  const [bUserBudgetUsd, setBUserBudgetUsd] = useState<number>(0); // Parbel budget

  // presupuesto "efectivo" usado en la UI (asesor A/B si aplica, si no -> global)
  const [displayedBudgetAmount, setDisplayedBudgetAmount] = useState<number | null>(null);

  // **Estos son los porcentajes que el usuario escribe. Los mostramos tal cual.**
  const [advisorAPct, setAdvisorAPct] = useState<number>(50);
  const [advisorBPct, setAdvisorBPct] = useState<number>(50);

  const [advisorSplit, setAdvisorSplit] = useState<any>(null);
  const [loadingSplit, setLoadingSplit] = useState(false);
  const [savingSplit, setSavingSplit] = useState(false);

  const [usersMap, setUsersMap] = useState<Record<number, string>>({});

  const findUserName = (id?: number | null) => {
    if (!id) return '';
    return usersMap[id] ?? `User ${id}`;
  };

  // on budget change: fetch specialists + budget-sellers
  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!budgetId) {
        setMontSpecialists([]); setParbelSpecialists([]); setActiveMont(null); setActivePar(null);
        setSelectedAId(null); setSelectedBId(null); setAUserBudgetUsd(0); setBUserBudgetUsd(0);
        setUsersMap({});
        setDisplayedBudgetAmount(null);
        return;
      }
      try {
        const [mRes, pRes, uRes] = await Promise.all([
          api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'montblanc' } }),
          api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'parbel' } }),
          api.get('advisors/budget-sellers', { params: { budget_id: budgetId } })
        ]);

        if (cancelled) return;

        const montList = Array.isArray(mRes.data) ? mRes.data : [];
        const parList = Array.isArray(pRes.data) ? pRes.data : [];
        const usersList = Array.isArray(uRes.data) ? uRes.data : [];

        setMontSpecialists(montList);
        setParbelSpecialists(parList);
        const aActive = montList.find((s: any) => !s.valid_to) ?? montList[0] ?? null;
        const bActive = parList.find((s: any) => !s.valid_to) ?? parList[0] ?? null;
        setActiveMont(aActive);
        setActivePar(bActive);
        setUsersMap(usersList.reduce((acc: Record<number,string>, u: any) => { if (u && u.id) acc[u.id] = u.name; return acc; }, {}));

        const aIdToLoad = selectedAId ?? (aActive?.user_id ?? montList[0]?.user_id ?? null);
        const bIdToLoad = selectedBId ?? (bActive?.user_id ?? parList[0]?.user_id ?? null);

        if (!selectedAId && montList.length) setSelectedAId(aIdToLoad ?? null);
        if (!selectedBId && parList.length) setSelectedBId(bIdToLoad ?? null);

        // load user budgets
        if (aIdToLoad) {
          try {
            const resA = await api.get('advisors/active-sales', { params: { budget_id: budgetId, business_line: 'montblanc', user_id: aIdToLoad } });
            if (!cancelled) setAUserBudgetUsd(Number(resA.data.user_budget_usd ?? 0));
          } catch (e) {
            if (!cancelled) setAUserBudgetUsd(0);
          }
        } else setAUserBudgetUsd(0);

        if (bIdToLoad) {
          try {
            const resB = await api.get('advisors/active-sales', { params: { budget_id: budgetId, business_line: 'parbel', user_id: bIdToLoad } });
            if (!cancelled) setBUserBudgetUsd(Number(resB.data.user_budget_usd ?? 0));
          } catch (e) {
            if (!cancelled) setBUserBudgetUsd(0);
          }
        } else setBUserBudgetUsd(0);

      } catch (e) {
        console.warn('Error cargando especialistas/usuarios', e);
        if (!cancelled) {
          setMontSpecialists([]); setParbelSpecialists([]); setActiveMont(null); setActivePar(null);
          setUsersMap({});
          setAUserBudgetUsd(0); setBUserBudgetUsd(0);
          setDisplayedBudgetAmount(null);
        }
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  // when selectedAId/selectedBId change, refresh their budgets
  useEffect(() => {
    if (!budgetId) return;
    let cancelled = false;
    (async () => {
      if (selectedAId) {
        try {
          const resA = await api.get('advisors/active-sales', { params: { budget_id: budgetId, business_line: 'montblanc', user_id: selectedAId } });
          if (!cancelled) setAUserBudgetUsd(Number(resA.data.user_budget_usd ?? 0));
        } catch {
          if (!cancelled) setAUserBudgetUsd(0);
        }
      } else {
        setAUserBudgetUsd(0);
      }

      if (selectedBId) {
        try {
          const resB = await api.get('advisors/active-sales', { params: { budget_id: budgetId, business_line: 'parbel', user_id: selectedBId } });
          if (!cancelled) setBUserBudgetUsd(Number(resB.data.user_budget_usd ?? 0));
        } catch {
          if (!cancelled) setBUserBudgetUsd(0);
        }
      } else {
        setBUserBudgetUsd(0);
      }
    })();
    return () => { cancelled = true; };
  }, [selectedAId, selectedBId, budgetId]);

  // Effect: decidir qué presupuesto mostrar según role seleccionado.
  // Mapeo claro: Montblanc -> A (aUserBudgetUsd), Parbel or Skin -> B (bUserBudgetUsd)
  useEffect(() => {
    const role = roles.find(r => r.id === roleId);
    const name = (role?.name ?? '').toLowerCase();

    const isMontblanc = /montblanc|mont blanc|mont-blanc|montblanc/i.test(name);
    const isParbel = /parbel|parbel/i.test(name);
    const isSkin = /skin|skincare|skin care/i.test(name);

    // Montblanc => A
    if (isMontblanc) {
      setDisplayedBudgetAmount(aUserBudgetUsd > 0 ? aUserBudgetUsd : null);
      return;
    }

    // Skin or Parbel => B
    if (isSkin || isParbel) {
      setDisplayedBudgetAmount(bUserBudgetUsd > 0 ? bUserBudgetUsd : null);
      return;
    }

    // Si no es un asesor específico -> usar presupuesto global
    setDisplayedBudgetAmount(null);
  }, [roleId, roles, aUserBudgetUsd, bUserBudgetUsd]);

  // --------------------------
  // UTIL: obtener presupuesto "efectivo"
  // --------------------------
  const getEffectiveBudgetTotal = (bId?: number | null) => {
    if (displayedBudgetAmount !== null && displayedBudgetAmount !== undefined && !isNaN(Number(displayedBudgetAmount)) && Number(displayedBudgetAmount) > 0) {
      return Number(displayedBudgetAmount);
    }
    return getBudgetTotal(bId);
  };

  // load categories when roleId or budgetId or displayedBudgetAmount changes
  useEffect(() => {
    if (!roleId) {
      setItems([]);
      return;
    }
    loadCategories(roleId, budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId, budgetId, displayedBudgetAmount]);

  const loadCategories = async (rId: number, bId?: number | null) => {
    try {
      setLoading(true);
      const res = await getCategoriesWithCommission(rId, bId ?? undefined);
      const cats: CategoryWithCommission[] = res?.categories ?? res?.data ?? res ?? [];
      let filtered: CategoryWithCommission[] = Array.isArray(cats) ? cats : [];

      if (rId === 4) {
        const allowedCodes = new Set(['13', '13.0']);
        filtered = filtered.filter(c => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();
          if (nameNormalized.includes('frag')) return true;
          if (allowedCodes.has(codeNormalized)) return true;
          if (!isNaN(Number(codeNormalized)) && allowedCodes.has(String(Number(codeNormalized)))) return true;
          return false;
        });
      } else if (rId === 5) {
        const allowedCodes = new Set(['14','15','16','19','21','14.0','15.0','16.0','19.0','21.0']);
        filtered = filtered.filter(c => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();
          const keywords = ['gift', 'gifts', 'watch', 'watches', 'jewel', 'jewelry', 'sunglass', 'electronics'];
          if (keywords.some(k => nameNormalized.includes(k))) return true;
          if (allowedCodes.has(codeNormalized)) return true;
          if (!isNaN(Number(codeNormalized)) && allowedCodes.has(String(Number(codeNormalized)))) return true;
          return false;
        });
      } else {
        filtered = Array.isArray(cats) ? cats : [];
      }

      // --------------------------
      // Si backend envía participation_pct pero no participation_value,
      // calculamos participation_value = effectiveBudgetTotal * pct / 100 (si hay presupuesto).
      // --------------------------
      const budgetTotal = getEffectiveBudgetTotal(bId);
      const withValues = filtered.map(f => {
        const rawPct = (f as any).participation_pct;
        const rawVal = (f as any).participation_value;

        const pctNum = rawPct !== undefined && rawPct !== null && !isNaN(Number(rawPct)) ? Number(rawPct) : null;
        let valNum = rawVal !== undefined && rawVal !== null && !isNaN(Number(rawVal)) ? Number(rawVal) : null;

        if ((valNum === null || valNum === undefined) && pctNum !== null && budgetTotal) {
          valNum = (pctNum / 100) * budgetTotal;
        }

        let pctComputed = pctNum;
        if (valNum !== null && budgetTotal) {
          pctComputed = (valNum / budgetTotal) * 100;
        } else if (pctNum !== null) {
          pctComputed = pctNum;
        } else {
          pctComputed = null;
        }

        return {
          ...f,
          participation_value: valNum ?? undefined,
          participation_pct: pctComputed ?? undefined
        };
      });

      setItems(withValues);
      setDirtyIds(new Set());
    } catch (err) {
      console.error('Error cargando categorias:', err);
      setItems([]);
    } finally {
      setLoading(false);
    }
  };

  // helpers money and dirty
  const markDirty = (categoryId: number, dirty = true) => {
    setDirtyIds(prev => {
      const clone = new Set(prev);
      if (dirty) clone.add(categoryId); else clone.delete(categoryId);
      return clone;
    });
  };

  const onChangeField = (categoryId: number, field: string, rawVal: string) => {
    if (field === 'participation_pct') return;
    const val = rawVal === '' ? null : Number(rawVal);

    if (field === 'participation_value') {
      const budgetTotal = getEffectiveBudgetTotal(budgetId);
      const valueNum = val ?? 0;
      const pct = budgetTotal ? (valueNum / budgetTotal) * 100 : 0;
      setItems(prev => prev.map(it =>
        it.category_id === categoryId ? { ...it, participation_value: valueNum, participation_pct: Number(pct) } : it
      ));
      markDirty(categoryId, true);
      return;
    }

    setItems(prev => prev.map(it => it.category_id === categoryId ? { ...it, [field]: val } : it));
    markDirty(categoryId, true);
  };

  const saveOne = async (it: CategoryWithCommission) => {
    if (!roleId) return;
    setSavingIds(s => [...s, it.category_id]);
    try {
      const budgetTotal = getEffectiveBudgetTotal(budgetId);
      const valNum = (it as any).participation_value;
      const computedPct = budgetTotal && valNum !== null && valNum !== undefined ? (Number(valNum) / budgetTotal) * 100 : Number((it as any).participation_pct ?? 0);

      const payload = {
        category_id: it.category_id,
        role_id: roleId,
        budget_id: budgetId,
        commission_percentage: Number(it.commission_percentage ?? 0),
        commission_percentage100: Number(it.commission_percentage100 ?? 0),
        commission_percentage120: Number(it.commission_percentage120 ?? 0),
        participation_pct: Number(Number(computedPct).toFixed(6)),
        participation_value: Number((it as any).participation_value ?? 0)
      };
      await upsertCategoryCommission(payload);
      setMessage({ type: 'ok', text: 'Guardado' });
      markDirty(it.category_id, false);
      await loadCategories(roleId, budgetId);
    } catch (e: any) {
      console.error('saveOne error completo:', e.response?.data || e);
      setMessage({ type: 'error', text: 'Error al guardar' + (e?.response?.data?.message ? ': ' + e.response.data.message : '') });
    } finally {
      setSavingIds(s => s.filter(id => id !== it.category_id));
      setTimeout(() => setMessage(null), 2000);
    }
  };

  const saveAll = async () => {
    if (!roleId) return;
    setSaving(true);
    try {
      const budgetTotal = getEffectiveBudgetTotal(budgetId);
      const payload = items.map(i => {
        const valNum = (i as any).participation_value;
        const computedPct = budgetTotal && valNum !== null && valNum !== undefined ? (Number(valNum) / budgetTotal) * 100 : Number((i as any).participation_pct ?? 0);
        return {
          category_id: i.category_id,
          role_id: roleId,
          budget_id: budgetId,
          commission_percentage: Number(i.commission_percentage ?? 0),
          commission_percentage100: Number(i.commission_percentage100 ?? 0),
          commission_percentage120: Number(i.commission_percentage120 ?? 0),
          participation_pct: Number(Number(computedPct).toFixed(6)),
          participation_value: Number((i as any).participation_value ?? 0)
        };
      });
      await bulkSaveCategoryCommissions(roleId, payload);
      setMessage({ type: 'ok', text: 'Guardado masivo exitoso' });
      setDirtyIds(new Set());
      await loadCategories(roleId, budgetId);
    } catch (e) {
      console.error('saveAll error', e);
      setMessage({ type: 'error', text: 'Error al guardar masivo' });
    } finally {
      setSaving(false);
      setTimeout(() => setMessage(null), 2000);
    }
  };

  const onDelete = async (categoryId: number) => {
    if (!confirm('¿Eliminar configuración de comisión para esta categoría?')) return;
    try {
      await deleteCategoryCommission(categoryId);
      setMessage({ type: 'ok', text: 'Configuración eliminada' });
      await loadCategories(roleId as number, budgetId);
    } catch (e) {
      console.error('delete error', e);
      setMessage({ type: 'error', text: 'Error al eliminar' });
    } finally {
      setTimeout(() => setMessage(null), 2000);
    }
  };

  const anyDirty = useMemo(() => dirtyIds.size > 0, [dirtyIds]);

  const normalizedItems = useMemo(() => {
    const map = new Map<number | string, CategoryWithCommission>();
    items.forEach(it => {
      const normalizedName = normalizeCategoryName(it.name);
      const key = it.category_id;
      const currentVal = (it as any).participation_value;
      const currentPct = (it as any).participation_pct;

      if (!map.has(key)) {
        map.set(key, { ...it, name: normalizedName });
      } else {
        const existing = map.get(key)!;
        const existingVal = (existing as any).participation_value;
        const existingPct = (existing as any).participation_pct;

        let mergedVal: number | null = null;
        const na = existingVal === null || existingVal === undefined ? null : Number(existingVal);
        const nb = currentVal === null || currentVal === undefined ? null : Number(currentVal);
        if (na === null && nb === null) mergedVal = null;
        else mergedVal = Math.max(na ?? 0, nb ?? 0);

        let mergedPct: number | null = null;
        const pa = existingPct === null || existingPct === undefined ? null : Number(existingPct);
        const pb = currentPct === null || currentPct === undefined ? null : Number(currentPct);
        if (pa === null && pb === null) mergedPct = null;
        else mergedPct = Math.max(pa ?? 0, pb ?? 0);

        map.set(key, {
          ...existing,
          ...it,
          name: normalizedName,
          commission_percentage: Math.max(existing.commission_percentage ?? 0, it.commission_percentage ?? 0),
          commission_percentage100: Math.max(existing.commission_percentage100 ?? 0, it.commission_percentage100 ?? 0),
          commission_percentage120: Math.max(existing.commission_percentage120 ?? 0, it.commission_percentage120 ?? 0),
          participation_value: mergedVal === null ? undefined : Number(Number(mergedVal).toFixed(2)),
          participation_pct: mergedPct === null ? undefined : Number(Number(mergedPct).toFixed(6)),
        });
      }
    });
    return Array.from(map.values());
  }, [items]);

  // total participación (2 decimales, number)
  const totalParticipation = useMemo(() => {
    const total = normalizedItems.reduce(
      (acc, it) => acc + Number((it as any).participation_pct ?? 0),
      0
    );

    return Number(total.toFixed(2));
  }, [normalizedItems]);

  // total participación valor (suma de participation_value) -> 2 decimales
  const totalParticipationValue = useMemo(() => {
    const total = normalizedItems.reduce(
      (acc, it) => acc + Number((it as any).participation_value ?? 0),
      0
    );

    return Number(total.toFixed(2));
  }, [normalizedItems]);

  // ------------------ Split helpers ------------------
  const calculateAdvisorSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    if (!selectedAId || !selectedBId) { setMessage({ type: 'error', text: 'Selecciona ambos asesores (Montblanc/Parbel)' }); setTimeout(()=>setMessage(null),1500); return; }
    setLoadingSplit(true);
    try {
      // enviamos exactamente lo que ingresó el usuario
      const res = await api.get('advisors/split-pool', {
        params: {
          budget_id: budgetId,
          advisor_a_id: selectedAId,
          advisor_b_id: selectedBId,
          advisor_a_pct: advisorAPct,
          advisor_b_pct: advisorBPct,
        }
      });
      // guardamos la respuesta (pool, assigned_usd, etc.)
      setAdvisorSplit(res.data ?? {});
      if (!res.data) {
        setMessage({ type: 'error', text: 'La API no devolvió datos al calcular.' });
        setTimeout(()=>setMessage(null),1500);
      }
    } catch (e) {
      console.error('calc advisor split error', e);
      setAdvisorSplit(null);
      setMessage({ type: 'error', text: 'Error calculando split asesores' });
      setTimeout(()=>setMessage(null),1500);
    } finally {
      setLoadingSplit(false);
    }
  };

  const saveAdvisorSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    if (!selectedAId || !selectedBId) { setMessage({ type: 'error', text: 'Selecciona ambos asesores (Montblanc/Parbel)' }); setTimeout(()=>setMessage(null),1500); return; }
    setSavingSplit(true);
    try {
      // guardamos exactamente los porcentajes que el usuario ingresó
      const payload = {
        budget_id: budgetId,
        advisor_a_id: selectedAId,
        advisor_a_pct: Number(advisorAPct || 0),
        advisor_b_id: selectedBId,
        advisor_b_pct: Number(advisorBPct || 0),
      };
      await api.post('advisors/save-split', payload);
      const res = await api.get('advisors/get-split', { params: { budget_id: budgetId } });
      setAdvisorSplit(res.data ?? {});
      setMessage({ type: 'ok', text: 'Distribución guardada' });
    } catch (e) {
      console.error('save split error', e);
      setMessage({ type: 'error', text: 'Error guardando distribución' });
    } finally {
      setSavingSplit(false);
      setTimeout(()=>setMessage(null),1800);
    }
  };

  const advisorPoolTotal = Number(advisorSplit?.advisor_pool_usd ?? 0);
  const assignedAUsd = Number(advisorSplit?.advisor_a?.assigned_usd ?? 0);
  const assignedBUsd = Number(advisorSplit?.advisor_b?.assigned_usd ?? 0);

  // **Mostrar los porcentajes EXACTOS que el usuario ingresó**
  const displayedAssignedPctA = Number(advisorAPct ?? 0);
  const displayedAssignedPctB = Number(advisorBPct ?? 0);

  // ------------------ Render ------------------
  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex flex-col gap-4 mb-4">
        <div className="flex items-start justify-between">
          <div className="flex flex-col gap-2">
            <button onClick={() => navigate('/budget')} className="text-sm text-primary hover:underline w-fit">← Volver a Presupuesto</button>
            <div>
              <h1 className="text-2xl font-bold">Configuración de participación por categoría</h1>
              <div className="text-sm text-gray-500">Asignación de participación por categoría</div>
            </div>
          </div>

          {/* Upper-right: sellers + budget select + save */}
          <div className="flex gap-3 items-center">
            <div className="flex flex-col">
              <label className="text-xs text-gray-500 block mb-1">Vendedores</label>
              <div className="flex gap-2 items-center">
                {sellerRoles.length === 0 ? (
                  <div className="text-xs text-gray-400">No hay vendedores</div>
                ) : (
                  sellerRoles.map(r => (
                    <button
                      key={r.id}
                      onClick={() => setRoleId(r.id)}
                      className={`text-sm px-3 py-1 rounded border ${ roleId === r.id ? 'bg-indigo-600 text-white' : 'bg-white hover:bg-gray-50' }`}
                      title={r.name}
                    >
                      {r.name}
                    </button>
                  ))
                )}
              </div>
            </div>

            <div>
              <label className="text-xs text-gray-500 block mb-1">Presupuesto</label>
              <div className="flex items-center gap-2">
                <select value={budgetId ?? ''} onChange={e => setBudgetId(e.target.value ? Number(e.target.value) : null)} className="border rounded px-3 py-2 text-sm">
                  <option value="">(Sin presupuesto)</option>
                  {budgets.map(b => <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>)}
                </select>

                <div className="text-xs text-gray-600">
                  <div>
                    {displayedBudgetAmount !== null ? (
                      <>Presupuesto asesor (override): <strong>{Number(displayedBudgetAmount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</strong></>
                    ) : budgetId ? (
                      <>Total presupuesto: <strong>{getBudgetTotal(budgetId).toLocaleString()}</strong></>
                    ) : (
                      <>Seleccione presupuesto</>
                    )}
                  </div>
                </div>
              </div>
            </div>

            <div className="flex items-end gap-2">
              <button onClick={saveAll} disabled={!roleId || loading || saving || !anyDirty} className={`px-4 py-2 rounded text-white ${(!roleId || loading || saving || !anyDirty) ? 'bg-gray-400 cursor-not-allowed' : 'bg-indigo-600'}`}>
                {saving ? 'Guardando...' : 'Guardar todo'}
              </button>
            </div>
          </div>
        </div>

        {/* ---------------- Horizontal Split card (mejorado) ---------------- */}
        <div className="bg-white rounded-2xl shadow p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
          {/* Left: title + budget */}
          <div className="flex flex-col gap-2">
            <div className="text-sm font-semibold">Split Montblanc (A) ↔ Parbel (B)</div>
            <div className="text-xs text-gray-500">Presupuesto: <strong>{budgetId ?? '-'}</strong></div>
            <div className="text-xxs text-gray-400 mt-1">Los porcentajes ingresados se respetan tal cual (no se normalizan automáticamente a 100%).</div>
          </div>

          {/* Middle: advisors info */}
          <div className="flex flex-col md:flex-row md:items-center md:justify-center gap-4">
            <div className="flex items-start gap-3">
              <div className="text-xxs text-gray-500">Asesor (Parbel)</div>
              <div className="text-sm font-medium">{selectedBId ? findUserName(selectedBId) : <em className="text-red-500">No seleccionado</em>}</div>
              <div className="text-xs text-gray-400 ml-2">PPTO: <strong>{Number(bUserBudgetUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</strong></div>
            </div>

            <div className="hidden md:block border-l h-8 self-stretch" />

            <div className="flex items-start gap-3">
              <div className="text-xxs text-gray-500">Asesor (Montblanc)</div>
              <div className="text-sm font-medium">{selectedAId ? findUserName(selectedAId) : <em className="text-red-500">No seleccionado</em>}</div>
              <div className="text-xs text-gray-400 ml-2">PPTO: <strong>{Number(aUserBudgetUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</strong></div>
            </div>
          </div>

          {/* Right: controls + summary card */}
          <div className="flex flex-col md:items-end gap-3">
            <div className="flex items-center gap-2">
              <input
                type="number"
                min={0}
                step={0.01}
                value={advisorAPct}
                onChange={e => setAdvisorAPct(Number(e.target.value || 0))}
                className="w-20 rounded px-2 py-1 text-sm border"
                title="Porcentaje A (se respeta tal cual)"
              />
              <span className="text-sm">/</span>
              <input
                type="number"
                min={0}
                step={0.01}
                value={advisorBPct}
                onChange={e => setAdvisorBPct(Number(e.target.value || 0))}
                className="w-20 rounded px-2 py-1 text-sm border"
                title="Porcentaje B (se respeta tal cual)"
              />
            </div>

            <div className="flex items-center gap-2">
              <button onClick={calculateAdvisorSplit} className="px-3 py-1 rounded bg-indigo-600 text-white text-sm">
                {loadingSplit ? 'Calculando...' : 'Calcular'}
              </button>
              <button onClick={saveAdvisorSplit} disabled={savingSplit || !selectedAId || !selectedBId} className={`px-3 py-1 rounded text-white text-sm ${savingSplit ? 'bg-gray-400' : (!selectedAId || !selectedBId ? 'bg-gray-300' : 'bg-emerald-600')}`}>
                {savingSplit ? 'Guardando...' : 'Guardar'}
              </button>
            </div>

            {/* Small summary card */}
            <div className="mt-2 w-full md:w-auto bg-gray-50 border rounded p-3 text-right">
              <div className="text-xs text-gray-500">Pool</div>
              <div className="font-semibold">{Number(advisorPoolTotal).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</div>

              <div className="mt-2 text-xs text-gray-500">A asignado</div>
              <div className="flex items-baseline justify-end gap-2">
                <div className="font-semibold">{Number(assignedAUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</div>
                <div className="text-xxs text-gray-500">({displayedAssignedPctA.toFixed(2)}%)</div>
              </div>

              <div className="mt-1 text-xs text-gray-500">B asignado</div>
              <div className="flex items-baseline justify-end gap-2">
                <div className="font-semibold">{Number(assignedBUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</div>
                <div className="text-xxs text-gray-500">({displayedAssignedPctB.toFixed(2)}%)</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {message && (
        <div className={`mb-4 p-3 rounded ${message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
          {message.text}
        </div>
      )}

      {/* Main table */}
      <div className="bg-white shadow rounded overflow-x-auto">
        <table className="w-full min-w-[900px]">
          <thead className="bg-gray-100">
            <tr>
              <th className="p-3 text-left">Categoría</th>
              <th className="p-3 text-left">Código</th>
              <th className="p-3 text-left">Comisión %</th>
              <th className="p-3 text-left">Comisión 100%</th>
              <th className="p-3 text-left">Comisión 120%</th>
              <th className="p-3 text-left">Valor participación</th>
              <th className="p-3 text-left">Participación %</th>
              <th className="p-3 text-left">Acciones</th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <tr><td colSpan={8} className="p-6 text-center text-gray-500">Cargando categorías…</td></tr>
            ) : items.length === 0 ? (
              <tr><td colSpan={8} className="p-6 text-center text-gray-500">No hay categorías.</td></tr>
            ) : normalizedItems.map((it) => {
              const isSaving = savingIds.includes(it.category_id);
              const isDirty = dirtyIds.has(it.category_id);
              return (
                <tr key={it.category_id} className="border-t hover:bg-gray-50">
                  <td className="p-3 align-top">
                    <div className="font-medium">{it.name}</div>
                    <div className="text-xs text-gray-500">{it.description ?? ''}</div>
                  </td>

                  <td className="p-3 text-sm text-gray-500 align-top">{it.code}</td>

                  <td className="p-3 align-top">
                    <input type="number" step="0.01" value={it.commission_percentage ?? ''} onChange={e => onChangeField(it.category_id, 'commission_percentage', e.target.value)} className="border px-2 py-1 rounded w-28" />
                    {isDirty && <div className="text-xxs text-indigo-600 mt-1">modificado</div>}
                  </td>

                  <td className="p-3 align-top">
                    <input type="number" step="0.01" value={it.commission_percentage100 ?? ''} onChange={e => onChangeField(it.category_id, 'commission_percentage100', e.target.value)} className="border px-2 py-1 rounded w-28" />
                  </td>

                  <td className="p-3 align-top">
                    <input type="number" step="0.01" value={it.commission_percentage120 ?? ''} onChange={e => onChangeField(it.category_id, 'commission_percentage120', e.target.value)} className="border px-2 py-1 rounded w-28" />
                  </td>

                  <td className="p-3 align-top">
                    {/* Valor participación: mostrar SIN decimales (rounded integer) */}
                    <input
                      type="number"
                      step="1"
                      min={0}
                      value={
                        (it as any).participation_value !== undefined &&
                        (it as any).participation_value !== null
                          ? Math.round(Number((it as any).participation_value))
                          : ''
                      }
                      onChange={e => onChangeField(it.category_id, 'participation_value', e.target.value)}
                      className="border px-2 py-1 rounded w-36"
                      placeholder={getEffectiveBudgetTotal(budgetId) ? `Presupuesto: ${getEffectiveBudgetTotal(budgetId).toLocaleString()}` : 'Sin presupuesto'}
                    />
                    <div className="text-xxs text-gray-400 mt-1">
                      {displayedBudgetAmount !== null ? `Usando presupuesto asesor: ${displayedBudgetAmount.toLocaleString()}` : (budgetId ? `Total presupuesto: ${getBudgetTotal(budgetId).toLocaleString()}` : 'Seleccione presupuesto para calcular %')}
                    </div>
                  </td>

                  <td className="p-3 align-top">
                    <input
                      type="number"
                      value={Number((it as any).participation_pct ?? 0).toFixed(2)}
                      readOnly
                      className="border px-2 py-1 rounded w-28 bg-gray-50"
                    />
                  </td>

                  <td className="p-3 align-top">
                    <div className="flex gap-2 items-center">
                      <button onClick={() => saveOne(it)} disabled={isSaving} className={`px-3 py-1 rounded border ${isSaving ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}`}>{isSaving ? 'Guardando...' : 'Guardar'}</button>
                      <button onClick={() => onDelete(it.category_id)} className="px-3 py-1 rounded border bg-white hover:bg-gray-50 text-red-600" title="Eliminar configuración">Eliminar</button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        <div className="mt-4 flex flex-col md:flex-row items-end justify-end gap-4 p-4">
          <div className="mt-2 px-4 py-2 rounded text-sm font-semibold bg-blue-50 text-blue-700">
            Total presupuesto asignado: {totalParticipationValue.toLocaleString(undefined,{
              minimumFractionDigits:2,
              maximumFractionDigits:2
            })} USD
          </div>

          <div className={`px-4 py-2 rounded text-sm font-semibold ${ Math.abs(totalParticipation - 100) < 0.01 ? 'bg-green-50 text-green-700' : totalParticipation > 100 ? 'bg-red-50 text-red-700' : 'bg-yellow-50 text-yellow-700' }`}>
            Total participación: {totalParticipation.toFixed(2)}%
          </div>
        </div>
      </div>
    </div>
  );
}