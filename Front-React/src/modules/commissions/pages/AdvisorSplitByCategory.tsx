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
 * - Mantiene TODO tu comportamiento existente.
 * - Corrección: si DB sólo trae participation_pct calculamos participation_value al cargar.
 * - participation_pct es sólo visual (readOnly).
 * - Split Montblanc <-> Parbel: usa especialistas activos + commissions/by-seller/:userId para PPTO.
 */

/* ---------- Helper types locales ---------- */
type Specialist = {
  id?: number;
  budget_id?: number;
  user_id?: number;
  business_line?: string | null;
  valid_from?: string | null;
  valid_to?: string | null;
  note?: string | null;
};

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

  // filas marcadas como modificadas (dirty)
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
          const vendedor = rolesData.find(r => String(r.name ?? '').toLowerCase().includes('vendedor') && r.id !== 2);
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

  // lista de roles que consideramos "vendedores" para mostrar arriba
  // omitimos explícitamente el role con id === 2 (no se muestra ni se puede seleccionar)
  const sellerRoles = useMemo(() => roles.filter(r => r.id !== 2), [roles]);

  // ---- Helper: intentar obtener el total del presupuesto seleccionado ----
  // Intenta varias propiedades comunes (amount, total, total_usd, budget_usd, value)
  const getBudgetTotal = (bId?: number | null) => {
    if (!bId) return 0;
    const b = budgets.find(b => b.id === bId);
    if (!b) return 0;
    const candidate = Number(b.target_amount ?? b.total ?? b.total_usd ?? b.budget_usd ?? b.value ?? 0);
    return isNaN(candidate) ? 0 : candidate;
  };

  // load categories when roleId or budgetId changes
  useEffect(() => {
    if (!roleId) {
      setItems([]);
      return;
    }
    loadCategories(roleId, budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId, budgetId]);

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
      // CORRECCIÓN: si backend envía participation_pct pero no participation_value,
      // calculamos participation_value = budgetTotal * pct / 100 (si hay presupuesto).
      // Normalizamos tipos a Number / undefined (compatible con interfaces TS).
      // --------------------------
      const budgetTotal = getBudgetTotal(bId);
      const withValues = filtered.map(f => {
        const rawPct = (f as any).participation_pct;
        const rawVal = (f as any).participation_value;

        const pctNum = rawPct !== undefined && rawPct !== null && !isNaN(Number(rawPct)) ? Number(rawPct) : undefined;
        let valNum = rawVal !== undefined && rawVal !== null && !isNaN(Number(rawVal)) ? Number(rawVal) : undefined;

        // Si no hay value pero hay pct y hay presupuesto -> calcular value
        if ((valNum === undefined) && (pctNum !== undefined) && budgetTotal) {
          valNum = (pctNum / 100) * budgetTotal;
        }

        // Si hay value y hay presupuesto -> recalcular pct para consistencia
        let pctComputed = pctNum;
        if (valNum !== undefined && budgetTotal) {
          pctComputed = (valNum / budgetTotal) * 100;
        } else if (pctNum !== undefined) {
          pctComputed = pctNum;
        } else {
          pctComputed = undefined;
        }

        return {
          ...f,
          participation_value: valNum,
          participation_pct: pctComputed
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

  // input handlers
  const onChangeField = (categoryId: number, field: string, rawVal: string) => {
    // si se intenta cambiar participation_pct desde UI (no debería poder), ignoramos
    if (field === 'participation_pct') return;

    const val = rawVal === '' ? undefined : Number(rawVal);

    // Si cambian el valor en "participation_value", recalculamos participation_pct automáticamente
    if (field === 'participation_value') {
      const budgetTotal = getBudgetTotal(budgetId);
      const valueNum = val ?? 0;
      const pct = budgetTotal ? (valueNum / budgetTotal) * 100 : 0;
      setItems(prev => prev.map(it =>
        it.category_id === categoryId ? { ...it, participation_value: valueNum, participation_pct: Number(pct) } : it
      ));
      markDirty(categoryId, true);
      return;
    }

    // comportamiento por defecto (ediciones manuales)
    setItems(prev => prev.map(it => it.category_id === categoryId ? { ...it, [field]: val } : it));
    markDirty(categoryId, true);
  };

  const saveOne = async (it: CategoryWithCommission) => {
    if (!roleId) return;
    setSavingIds(s => [...s, it.category_id]);
    try {
      const budgetTotal = getBudgetTotal(budgetId);
      const valNum = (it as any).participation_value;
      const computedPct = (budgetTotal && valNum !== undefined && valNum !== null)
        ? (Number(valNum) / budgetTotal) * 100
        : Number((it as any).participation_pct ?? 0);

      const payload = {
        category_id: it.category_id,
        role_id: roleId,
        budget_id: budgetId,
        commission_percentage: Number(it.commission_percentage ?? 0),
        commission_percentage100: Number(it.commission_percentage100 ?? 0),
        commission_percentage120: Number(it.commission_percentage120 ?? 0),
        participation_pct: Number(Number(computedPct).toFixed(6)),
        // valor numérico absoluto (ej: COP / USD según tu backend)
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
      const budgetTotal = getBudgetTotal(budgetId);
      const payload = items.map(i => {
        const valNum = (i as any).participation_value;
        const computedPct = (budgetTotal && valNum !== undefined && valNum !== null)
          ? (Number(valNum) / budgetTotal) * 100
          : Number((i as any).participation_pct ?? 0);
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

  // Normalización: unimos duplicados y preferimos participation_value si existe;
  // usamos merge seguro que respeta undefineds.
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

        // merge participation_value: si ambos undefined -> undefined, si alguno number -> max
        let mergedVal: number | undefined;
        const na = existingVal === null || existingVal === undefined ? undefined : Number(existingVal);
        const nb = currentVal === null || currentVal === undefined ? undefined : Number(currentVal);
        if (na === undefined && nb === undefined) mergedVal = undefined;
        else mergedVal = Math.max(na ?? 0, nb ?? 0);

        // merge pct similar
        let mergedPct: number | undefined;
        const pa = existingPct === null || existingPct === undefined ? undefined : Number(existingPct);
        const pb = currentPct === null || currentPct === undefined ? undefined : Number(currentPct);
        if (pa === undefined && pb === undefined) mergedPct = undefined;
        else mergedPct = Math.max(pa ?? 0, pb ?? 0);

        map.set(key, {
          ...existing,
          ...it,
          name: normalizedName,
          commission_percentage: Math.max(existing.commission_percentage ?? 0, it.commission_percentage ?? 0),
          commission_percentage100: Math.max(existing.commission_percentage100 ?? 0, it.commission_percentage100 ?? 0),
          commission_percentage120: Math.max(existing.commission_percentage120 ?? 0, it.commission_percentage120 ?? 0),
          participation_value: mergedVal === undefined ? undefined : Number(Number(mergedVal).toFixed(2)),
          participation_pct: mergedPct === undefined ? undefined : Number(Number(mergedPct).toFixed(6)),
        });
      }
    });
    return Array.from(map.values());
  }, [items]);

  const totalParticipation = useMemo(() => normalizedItems.reduce((acc, it) => acc + Number((it as any).participation_pct ?? 0), 0), [normalizedItems]);

  // ------------------ Split: Montblanc <-> Parbel (horizontal, top) ------------------
  const [montSpecialists, setMontSpecialists] = useState<Specialist[]>([]);
  const [parbelSpecialists, setParbelSpecialists] = useState<Specialist[]>([]);
  const [specialistMont, setSpecialistMont] = useState<Specialist | null>(null);
  const [specialistParbel, setSpecialistParbel] = useState<Specialist | null>(null);

  const [aUserBudgetUsd, setAUserBudgetUsd] = useState<number>(0);
  const [bUserBudgetUsd, setBUserBudgetUsd] = useState<number>(0);

  const [advisorAPct, setAdvisorAPct] = useState<number>(50);
  const [advisorBPct, setAdvisorBPct] = useState<number>(50);
  const [advisorSplit, setAdvisorSplit] = useState<any>(null);
  const [loadingSplit, setLoadingSplit] = useState(false);
  const [savingSplit, setSavingSplit] = useState(false);

  const [usersMap, setUsersMap] = useState<Record<number, string>>({}); // map user id -> name


  console.log(montSpecialists)
  console.log(parbelSpecialists)
  // helper to get username (tolerante a claves distintas)
  const findUserName = (id?: number | null) => {
    if (!id) return '';
    return usersMap[id] ?? `User ${id}`;
  };

  // on budget change: fetch specialists (mont/parbel) + budget-sellers map
  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!budgetId) {
        setMontSpecialists([]); setParbelSpecialists([]); setSpecialistMont(null); setSpecialistParbel(null);
        setAUserBudgetUsd(0); setBUserBudgetUsd(0); setUsersMap({});
        setAdvisorSplit(null);
        return;
      }
      try {
        // fetch specialists by line and budget-sellers (names)
        const [mRes, pRes, uRes] = await Promise.all([
          api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'montblanc' } }),
          api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'parbel' } }),
          api.get('advisors/budget-sellers', { params: { budget_id: budgetId } })
        ]);

        if (cancelled) return;

        const montList: Specialist[] = Array.isArray(mRes.data) ? mRes.data : [];
        const parList: Specialist[] = Array.isArray(pRes.data) ? pRes.data : [];
        const usersList = Array.isArray(uRes.data) ? uRes.data : [];

        setMontSpecialists(montList);
        setParbelSpecialists(parList);

        // pick active specialists (valid_to null) or first
        const activeMont = montList.find(s => !s.valid_to) ?? montList[0] ?? null;
        const activePar = parList.find(s => !s.valid_to) ?? parList[0] ?? null;
        setSpecialistMont(activeMont);
        setSpecialistParbel(activePar);

        // usersMap: soporta u.id o u.user_id, y name o full_name
        const map = usersList.reduce((acc: Record<number,string>, u: any) => {
          const key = u?.id ?? u?.user_id;
          const name = u?.name ?? u?.full_name ?? u?.username;
          if (key != null) acc[Number(key)] = name ?? `User ${key}`;
          return acc;
        }, {});
        setUsersMap(map);

        // load advisor budgets for display (use commissions/by-seller/:userId endpoint)
        const loadBudgetForUser = async (userId?: number | null) => {
          if (!userId) return 0;
          try {
            const res = await api.get(`commissions/by-seller/${userId}`, { params: { budget_ids: [budgetId] } });
            return Number(res.data?.user_budget_usd ?? 0);
          } catch (e) {
            return 0;
          }
        };

        if (activeMont?.user_id) {
          const v = await loadBudgetForUser(activeMont.user_id);
          if (!cancelled) setAUserBudgetUsd(v);
        } else setAUserBudgetUsd(0);

        if (activePar?.user_id) {
          const v = await loadBudgetForUser(activePar.user_id);
          if (!cancelled) setBUserBudgetUsd(v);
        } else setBUserBudgetUsd(0);

        // load saved advisor split (if any)
        try {
          const splitRes = await api.get('advisors/get-split', { params: { budget_id: budgetId } });
          if (splitRes?.data?.budget_id) {
            setAdvisorAPct(Number(splitRes.data.advisor_a_pct ?? 50));
            setAdvisorBPct(Number(splitRes.data.advisor_b_pct ?? 50));
            setAdvisorSplit(splitRes.data);
          } else {
            setAdvisorAPct(50);
            setAdvisorBPct(50);
            setAdvisorSplit(null);
          }
        } catch {
          setAdvisorSplit(null);
        }

      } catch (e) {
        console.warn('Error cargando especialistas/usuarios', e);
        if (!cancelled) {
          setMontSpecialists([]); setParbelSpecialists([]); setSpecialistMont(null); setSpecialistParbel(null);
          setUsersMap({}); setAUserBudgetUsd(0); setBUserBudgetUsd(0);
        }
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  // when specialistMont/specialistParbel change, refresh their budgets
  useEffect(() => {
    if (!budgetId) return;
    let cancelled = false;
    (async () => {
      const loadBudgetForUser = async (userId?: number | null) => {
        if (!userId || !budgetId) return 0;
        try {
          const res = await api.get(`commissions/by-seller/${userId}`, { params: { budget_ids: [budgetId] } });
          return Number(res.data?.user_budget_usd ?? 0);
        } catch (e) {
          return 0;
        }
      };

      if (specialistMont?.user_id) {
        const v = await loadBudgetForUser(specialistMont.user_id);
        if (!cancelled) setAUserBudgetUsd(v);
      } else {
        setAUserBudgetUsd(0);
      }

      if (specialistParbel?.user_id) {
        const v = await loadBudgetForUser(specialistParbel.user_id);
        if (!cancelled) setBUserBudgetUsd(v);
      } else {
        setBUserBudgetUsd(0);
      }
    })();
    return () => { cancelled = true; };
  }, [specialistMont?.user_id, specialistParbel?.user_id, budgetId]);

  // Split helpers
  const calculateAdvisorSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    const aId = specialistMont?.user_id ?? null;
    const bId = specialistParbel?.user_id ?? null;
    if (!aId || !bId) { setMessage({ type: 'error', text: 'Falta especialista activo en Montblanc o Parbel' }); setTimeout(()=>setMessage(null),1500); return; }
    setLoadingSplit(true);
    try {
      const res = await api.get('advisors/split-pool', {
        params: {
          budget_id: budgetId,
          advisor_a_id: aId,
          advisor_b_id: bId,
          advisor_a_pct: advisorAPct,
          advisor_b_pct: advisorBPct,
        }
      });
      setAdvisorSplit(res.data);
    } catch (e) {
      console.error('calc advisor split error', e);
      setMessage({ type: 'error', text: 'Error calculando split asesores' });
      setTimeout(()=>setMessage(null),1500);
    } finally {
      setLoadingSplit(false);
    }
  };

  const saveAdvisorSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    const aId = specialistMont?.user_id ?? null;
    const bId = specialistParbel?.user_id ?? null;
    if (!aId || !bId) { setMessage({ type: 'error', text: 'Falta especialista activo en Montblanc o Parbel' }); setTimeout(()=>setMessage(null),1500); return; }
    setSavingSplit(true);
    try {
      const payload = {
        budget_id: budgetId,
        advisor_a_id: aId,
        advisor_a_pct: Number(advisorAPct || 0),
        advisor_b_id: bId,
        advisor_b_pct: Number(advisorBPct || 0),
      };
      await api.post('advisors/save-split', payload);
      const res = await api.get('advisors/get-split', { params: { budget_id: budgetId } });
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

  const advisorPoolTotal = Number(advisorSplit?.advisor_pool_usd ?? 0);
  const assignedAUsd = Number(advisorSplit?.advisor_a?.assigned_usd ?? 0);
  const assignedBUsd = Number(advisorSplit?.advisor_b?.assigned_usd ?? 0);
  const assignedPctA = advisorPoolTotal ? (assignedAUsd / advisorPoolTotal) * 100 : Number(advisorAPct ?? 0);
  const assignedPctB = advisorPoolTotal ? (assignedBUsd / advisorPoolTotal) * 100 : Number(advisorBPct ?? 0);
console.log(assignedPctA)
console.log(assignedPctB)
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
              <select value={budgetId ?? ''} onChange={e => setBudgetId(e.target.value ? Number(e.target.value) : null)} className="border rounded px-3 py-2 text-sm">
                <option value="">(Sin presupuesto)</option>
                {budgets.map(b => <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>)}
              </select>
            </div>

            <div className="flex items-end gap-2">
              <button onClick={saveAll} disabled={!roleId || loading || saving || !anyDirty} className={`px-4 py-2 rounded text-white ${(!roleId || loading || saving || !anyDirty) ? 'bg-gray-400 cursor-not-allowed' : 'bg-indigo-600'}`}>
                {saving ? 'Guardando...' : 'Guardar todo'}
              </button>
            </div>
          </div>
        </div>

        {/* ---------------- Horizontal Split card (compact) ---------------- */}
        <div className="bg-white rounded-2xl shadow p-3 flex items-center justify-between gap-4">
          {/* Left: labels */}
          <div className="flex items-center gap-4">
            <div className="text-sm font-semibold">Split Montblanc ↔ Parbel</div>
            <div className="text-xs text-gray-500">Presupuesto: {budgetId ?? '-'}</div>
          </div>

          {/* Middle: active advisors info */}
          <div className="flex items-center gap-6">
            <div className="flex items-center gap-3">
              <div className="text-xxs text-gray-500">Asesor A</div>
              <div className="text-sm font-medium">{specialistMont?.user_id ? findUserName(specialistMont.user_id) : <em className="text-red-500">No seleccionado</em>}</div>
              <div className="text-xs text-gray-400">PPTO: <strong>{Number(aUserBudgetUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</strong></div>
            </div>

            <div className="flex items-center gap-3">
              <div className="text-xxs text-gray-500">Asesor B</div>
              <div className="text-sm font-medium">{specialistParbel?.user_id ? findUserName(specialistParbel.user_id) : <em className="text-red-500">No seleccionado</em>}</div>
              <div className="text-xs text-gray-400">PPTO: <strong>{Number(bUserBudgetUsd).toLocaleString(undefined, { minimumFractionDigits:2, maximumFractionDigits:2 })} USD</strong></div>
            </div>
          </div>

          {/* Right: compact controls */}
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2">
              <input type="number" min={0} max={100} value={advisorAPct} onChange={e => setAdvisorAPct(Number(e.target.value || 0))} className="w-20 rounded px-2 py-1 text-sm border" />
              <span className="text-sm">/</span>
              <input type="number" min={0} max={100} value={advisorBPct} onChange={e => setAdvisorBPct(Number(e.target.value || 0))} className="w-20 rounded px-2 py-1 text-sm border" />
            </div>

            <button onClick={calculateAdvisorSplit} className="px-3 py-1 rounded bg-indigo-600 text-white text-sm">{loadingSplit ? 'Calculando...' : 'Calcular'}</button>
            <button onClick={saveAdvisorSplit} disabled={savingSplit || !(specialistMont && specialistParbel)} className={`px-3 py-1 rounded text-white text-sm ${savingSplit ? 'bg-gray-400' : (!(specialistMont && specialistParbel) ? 'bg-gray-300' : 'bg-emerald-600')}`}>{savingSplit ? 'Guardando...' : 'Guardar'}</button>
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
              {/* Nueva columna: valor absoluto de participación */}
              <th className="p-3 text-left">Valor participación</th>
              {/* Antes era este: <th>Participación %</th> */}
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

                  {/* Nueva celda: Valor participación (editable) */}
                  <td className="p-3 align-top">
                    <input
                      type="number"
                      step="0.01"
                      min={0}
                      value={(it as any).participation_value ?? ''}
                      onChange={e => onChangeField(it.category_id, 'participation_value', e.target.value)}
                      className="border px-2 py-1 rounded w-36"
                      placeholder={getBudgetTotal(budgetId) ? `Presupuesto: ${getBudgetTotal(budgetId).toLocaleString()}` : 'Sin presupuesto'}
                    />
                    <div className="text-xxs text-gray-400 mt-1">
                      {/* Mostrar presupuesto actual y ayuda */}
                      {budgetId ? `Total presupuesto: ${getBudgetTotal(budgetId).toLocaleString()}` : 'Seleccione presupuesto para calcular %'}
                    </div>
                  </td>

                  {/* Participación % (DERIVADO del valor anterior) — readonly para evitar desincronía */}
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

        <div className="mt-4 flex justify-end p-4">
          <div className={`px-4 py-2 rounded text-sm font-semibold ${ totalParticipation === 100 ? 'bg-green-50 text-green-700' : totalParticipation > 100 ? 'bg-red-50 text-red-700' : 'bg-yellow-50 text-yellow-700' }`}>
            Total participación: {totalParticipation.toFixed(2)}%
          </div>
        </div>
      </div>
    </div>
  );
}