import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../../api/axios';

import {
  getCategoriesWithCommission,
  upsertCategoryCommission,
  bulkSaveCategoryCommissions,
  deleteCategoryCommission,
  getRoles,
  getBudgets,
} from '../services/categoryCommissionService';

import type { CategoryWithCommission, Role } from '../types/comissionscategory';

type MessageState = { type: 'ok' | 'error'; text: string };

/* =========================================================
 * Precisión
 * ========================================================= */
const PARTICIPATION_PCT_DECIMALS = 5;
const PARTICIPATION_PCT_DISPLAY_DECIMALS = 2;

const roundTo = (value: number, decimals: number) => {
  const n = Number(value);
  if (!Number.isFinite(n)) return 0;
  const factor = 10 ** decimals;
  return Math.round((n + Number.EPSILON) * factor) / factor;
};

const computeParticipationPct = (participationValue: number, baseBudget: number) => {
  if (!Number.isFinite(participationValue) || !Number.isFinite(baseBudget) || baseBudget <= 0) {
    return 0;
  }
  return roundTo((participationValue / baseBudget) * 100, PARTICIPATION_PCT_DECIMALS);
};

const formatUSD = (value: number) =>
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
  }).format(Number.isFinite(value) ? value : 0);

const formatCompactUSD = (value: number) =>
  new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 2,
  }).format(Number.isFinite(value) ? value : 0);

export default function CategoryCommissionsPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [roleId, setRoleId] = useState<number | null>(null);

  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  const [advisorBudgetUsd, setAdvisorBudgetUsd] = useState<number>(0);
  const [savingAdvisorBudget, setSavingAdvisorBudget] = useState(false);

  const [items, setItems] = useState<CategoryWithCommission[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingIds, setSavingIds] = useState<number[]>([]);
  const [message, setMessage] = useState<MessageState | null>(null);
  const [dirtyIds, setDirtyIds] = useState<Set<number>>(new Set());

  // 🔑 Para detectar cambios de presupuesto y auto-marcar dirty
  const lastBaseBudgetRef = useRef<number | null>(null);
  const [budgetChangedNotice, setBudgetChangedNotice] = useState(false);

  const navigate = useNavigate();

  const isSpecialistRole = roleId === 4 || roleId === 5;

  const normalizeCategoryName = (name: string) => {
    const n = String(name ?? '').toLowerCase();
    if (n.includes('frag')) return 'FRAGANCIAS';
    if (n.includes('diam')) return 'DIAMANTES';
    return String(name ?? '').toUpperCase();
  };

  /* =========================================================
   * Carga inicial de roles y presupuestos
   * ========================================================= */
  useEffect(() => {
    let mounted = true;

    async function loadMeta() {
      try {
        const [rolesData, budgetsData] = await Promise.all([getRoles(), getBudgets()]);
        if (!mounted) return;

        setRoles(Array.isArray(rolesData) ? rolesData : []);
        setBudgets(Array.isArray(budgetsData) ? budgetsData : []);

        if (Array.isArray(rolesData) && rolesData.length) {
          const vendedor = rolesData.find((r) => r.name.toLowerCase().includes('vendedor') && r.id !== 2);
          const fallback = rolesData.find((r) => r.id !== 2);
          setRoleId(vendedor ? vendedor.id : fallback ? fallback.id : rolesData[0].id);
        }

        if (Array.isArray(budgetsData) && budgetsData.length) {
          setBudgetId((prev) => prev ?? budgetsData[0].id);
        }
      } catch (err) {
        console.error('Error cargando roles/presupuestos', err);
        setRoles([]);
        setBudgets([]);
      }
    }

    loadMeta();
    return () => {
      mounted = false;
    };
  }, []);

  const sellerRoles = useMemo(() => roles.filter((r) => r.id !== 2), [roles]);

  /* =========================================================
   * Helpers de presupuesto
   * ========================================================= */
  const getBudgetTotal = (bId?: number | null) => {
    if (!bId) return 0;
    const b = budgets.find((item) => item.id === bId);
    if (!b) return 0;
    const candidate = Number(b.target_amount ?? b.total ?? b.total_usd ?? b.budget_usd ?? b.value ?? 0);
    return Number.isFinite(candidate) ? candidate : 0;
  };

  const getRowsBaseBudget = (rows: CategoryWithCommission[], fallbackBase = 0) => {
    const current = rows.reduce((acc, item) => acc + Number((item as any).participation_value ?? 0), 0);
    return fallbackBase > 0 ? fallbackBase : Number(current.toFixed(2));
  };

  /* =========================================================
   * Normalización: participation_value es la verdad,
   * participation_pct se deriva.
   * ========================================================= */
  const normalizeRowsWithBase = (rows: CategoryWithCommission[], baseBudget: number) => {
    return rows.map((row) => {
      const rawVal = (row as any).participation_value;
      const rawPct = (row as any).participation_pct;

      let valNum: number | null =
        rawVal !== undefined && rawVal !== null && !isNaN(Number(rawVal))
          ? Number(rawVal)
          : null;

      const pctNum =
        rawPct !== undefined && rawPct !== null && !isNaN(Number(rawPct))
          ? Number(rawPct)
          : 0;

      // Migración suave: si NO hay valor (o es 0) pero sí hay % histórico, derivamos USD
      if ((valNum === null || valNum === 0) && pctNum > 0 && baseBudget > 0) {
        valNum = (pctNum / 100) * baseBudget;
      }

      const pctComputed = computeParticipationPct(valNum ?? 0, baseBudget);

      return {
        ...row,
        participation_value: valNum === null ? undefined : roundTo(valNum, 2),
        participation_pct: pctComputed,
      };
    });
  };

  /* =========================================================
   * Carga de categorías
   * ========================================================= */
  const loadCategories = async (rId: number, bId?: number | null, advisorBudgetOverride?: number) => {
    try {
      setLoading(true);

      const res = await getCategoriesWithCommission(rId, bId ?? undefined);
      const cats: CategoryWithCommission[] = res?.categories ?? res?.data ?? res ?? [];
      let filtered: CategoryWithCommission[] = Array.isArray(cats) ? cats : [];

      if (rId === 4) {
        const allowedCodes = new Set(['13', '13.0']);
        filtered = filtered.filter((c) => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();

          if (nameNormalized.includes('frag')) return true;
          if (allowedCodes.has(codeNormalized)) return true;
          if (!isNaN(Number(codeNormalized)) && allowedCodes.has(String(Number(codeNormalized)))) return true;

          return false;
        });
      } else if (rId === 5) {
        const allowedCodes = new Set([
          '14','15','16','19','21','23',
          '14.0','15.0','16.0','19.0','21.0','23.0',
        ]);

        filtered = filtered.filter((c) => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();

          const keywords = ['gift','gifts','watch','watches','jewel','jewelry','sunglass','electronics','diam'];

          if (nameNormalized.includes('diam')) return true;
          if (keywords.some((k) => nameNormalized.includes(k))) return true;
          if (allowedCodes.has(codeNormalized)) return true;
          if (!isNaN(Number(codeNormalized)) && allowedCodes.has(String(Number(codeNormalized)))) return true;

          return false;
        });
      } else {
        filtered = (Array.isArray(cats) ? cats : []).filter((c) => {
          const nameNormalized = String((c as any).name ?? '').toLowerCase().trim();
          if (nameNormalized.includes('diam')) return false;
          return true;
        });
      }

      const globalBudget = getBudgetTotal(bId);
      const specialistBase = isSpecialistRole
        ? getRowsBaseBudget(filtered, Number(advisorBudgetOverride ?? 0))
        : globalBudget;

      const withValues = normalizeRowsWithBase(filtered, specialistBase);

      setItems(withValues);
      setDirtyIds(new Set());
      setBudgetChangedNotice(false);

      // 🔑 Guardamos la base usada para la próxima comparación
      lastBaseBudgetRef.current = isSpecialistRole
        ? (advisorBudgetOverride && advisorBudgetOverride > 0
            ? advisorBudgetOverride
            : getRowsBaseBudget(withValues, 0))
        : globalBudget;
    } catch (err) {
      console.error('Error cargando categorias:', err);
      setItems([]);
    } finally {
      setLoading(false);
    }
  };

  /* =========================================================
   * Inicialización al cambiar rol/presupuesto
   * ========================================================= */
  useEffect(() => {
    if (!roleId) {
      setItems([]);
      setAdvisorBudgetUsd(0);
      lastBaseBudgetRef.current = null;
      setBudgetChangedNotice(false);
      return;
    }

    let cancelled = false;

    (async () => {
      try {
        let loadedAdvisorBudget = 0;

        if (isSpecialistRole && budgetId) {
          try {
            const res = await api.get('/advisor-budgets', {
              params: { budget_id: budgetId, role_id: roleId },
            });
            loadedAdvisorBudget = Number(res.data?.budget_usd ?? 0);
          } catch {
            loadedAdvisorBudget = 0;
          }
        }

        if (cancelled) return;

        // Reseteamos el ref antes de cargar para que loadCategories lo establezca
        lastBaseBudgetRef.current = null;
        setBudgetChangedNotice(false);

        setAdvisorBudgetUsd(loadedAdvisorBudget);
        await loadCategories(roleId, budgetId, loadedAdvisorBudget);
      } catch (e) {
        console.error('Error inicializando categorías', e);
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId, budgetId]);

  useEffect(() => {
    if (!isSpecialistRole) return;
    if (advisorBudgetUsd > 0) return;
    if (!items.length) return;

    const fallback = getRowsBaseBudget(items, 0);
    if (fallback > 0) {
      setAdvisorBudgetUsd(Number(fallback.toFixed(2)));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items, isSpecialistRole]);

  /* =========================================================
   * 🔑 Reaccionar a cambios de presupuesto base:
   * - Renormaliza los %
   * - Si la base cambió respecto a la última conocida, marca dirty
   *   las filas con valor capturado y muestra el banner.
   * ========================================================= */
  useEffect(() => {
    if (!roleId) return;

    const baseBudget = isSpecialistRole
      ? (advisorBudgetUsd > 0 ? advisorBudgetUsd : getRowsBaseBudget(items, 0))
      : getBudgetTotal(budgetId);

    if (baseBudget <= 0) return;

    setItems((prev) => normalizeRowsWithBase(prev, baseBudget));

    const prevBase = lastBaseBudgetRef.current;
    const baseChanged =
      prevBase !== null && Math.abs(prevBase - baseBudget) > 0.001;

    if (baseChanged && items.length > 0) {
      setDirtyIds((prev) => {
        const merged = new Set(prev);
        items.forEach((it) => {
          const valNum = Number((it as any).participation_value ?? 0);
          if (valNum > 0) merged.add(it.category_id);
        });
        return merged;
      });
      setBudgetChangedNotice(true);
    }

    lastBaseBudgetRef.current = baseBudget;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [advisorBudgetUsd, budgetId]);

  /* =========================================================
   * Edición
   * ========================================================= */
  const markDirty = (categoryId: number, dirty = true) => {
    setDirtyIds((prev) => {
      const clone = new Set(prev);
      if (dirty) clone.add(categoryId);
      else clone.delete(categoryId);
      // Si ya no quedan dirty, ocultamos el banner
      if (clone.size === 0) setBudgetChangedNotice(false);
      return clone;
    });
  };

  const getActiveBaseBudget = () => {
    if (isSpecialistRole) {
      const fallback = advisorBudgetUsd > 0 ? advisorBudgetUsd : getRowsBaseBudget(items, 0);
      return Number(fallback.toFixed(2));
    }
    return getBudgetTotal(budgetId);
  };

  const onChangeField = (categoryId: number, field: string, rawVal: string) => {
    if (field === 'participation_pct') return;

    const val = rawVal === '' ? null : Number(rawVal);

    if (field === 'participation_value') {
      const baseBudget = getActiveBaseBudget();
      const valueNum = val ?? 0;
      const pct = computeParticipationPct(valueNum, baseBudget);

      setItems((prev) =>
        prev.map((it) =>
          it.category_id === categoryId
            ? {
                ...it,
                participation_value: roundTo(valueNum, 2),
                participation_pct: pct,
              }
            : it
        )
      );

      markDirty(categoryId, true);
      return;
    }

    setItems((prev) =>
      prev.map((it) => (it.category_id === categoryId ? { ...it, [field]: val } : it))
    );
    markDirty(categoryId, true);
  };

  /* =========================================================
   * Persistencia
   * ========================================================= */
  const persistAdvisorBudget = async () => {
    if (!roleId || !budgetId || !isSpecialistRole) return;

    setSavingAdvisorBudget(true);
    try {
      await api.post('/advisor-budgets', {
        budget_id: budgetId,
        role_id: roleId,
        budget_usd: Number(advisorBudgetUsd ?? 0),
      });
      setMessage({ type: 'ok', text: 'Presupuesto del asesor guardado correctamente' });
    } catch (e) {
      console.error('save advisor budget error', e);
      setMessage({ type: 'error', text: 'No se pudo guardar el presupuesto del asesor' });
    } finally {
      setSavingAdvisorBudget(false);
      setTimeout(() => setMessage(null), 2000);
    }
  };

  const saveOne = async (it: CategoryWithCommission) => {
    if (!roleId) return;

    setSavingIds((s) => [...s, it.category_id]);

    try {
      const baseBudget = getActiveBaseBudget();
      const valNum = Number((it as any).participation_value ?? 0);
      const computedPct = computeParticipationPct(valNum, baseBudget);

      const payload = {
        category_id: it.category_id,
        role_id: roleId,
        budget_id: budgetId,
        commission_percentage: Number(it.commission_percentage ?? 0),
        commission_percentage100: Number(it.commission_percentage100 ?? 0),
        commission_percentage120: Number(it.commission_percentage120 ?? 0),
        participation_value: roundTo(valNum, 2),
        participation_pct: computedPct,
      };

      await upsertCategoryCommission(payload);
      setMessage({ type: 'ok', text: 'Guardado' });
      markDirty(it.category_id, false);
      await loadCategories(roleId, budgetId, isSpecialistRole ? advisorBudgetUsd : undefined);
    } catch (e: any) {
      console.error('saveOne error completo:', e.response?.data || e);
      setMessage({
        type: 'error',
        text: 'Error al guardar' + (e?.response?.data?.message ? ': ' + e.response.data.message : ''),
      });
    } finally {
      setSavingIds((s) => s.filter((id) => id !== it.category_id));
      setTimeout(() => setMessage(null), 2000);
    }
  };

  const saveAll = async () => {
    if (!roleId) return;

    setSaving(true);
    try {
      if (isSpecialistRole) {
        await api.post('/advisor-budgets', {
          budget_id: budgetId,
          role_id: roleId,
          budget_usd: Number(advisorBudgetUsd ?? 0),
        });
      }

      const baseBudget = getActiveBaseBudget();

      const payload = items.map((i) => {
        const valNum = Number((i as any).participation_value ?? 0);
        const computedPct = computeParticipationPct(valNum, baseBudget);

        return {
          category_id: i.category_id,
          role_id: roleId,
          budget_id: budgetId,
          commission_percentage: Number(i.commission_percentage ?? 0),
          commission_percentage100: Number(i.commission_percentage100 ?? 0),
          commission_percentage120: Number(i.commission_percentage120 ?? 0),
          participation_value: roundTo(valNum, 2),
          participation_pct: computedPct,
        };
      });

      await bulkSaveCategoryCommissions(roleId, payload);
      setMessage({ type: 'ok', text: 'Guardado masivo exitoso' });
      setDirtyIds(new Set());
      setBudgetChangedNotice(false);
      await loadCategories(roleId, budgetId, isSpecialistRole ? advisorBudgetUsd : undefined);
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
      await loadCategories(roleId as number, budgetId, isSpecialistRole ? advisorBudgetUsd : undefined);
    } catch (e) {
      console.error('delete error', e);
      setMessage({ type: 'error', text: 'Error al eliminar' });
    } finally {
      setTimeout(() => setMessage(null), 2000);
    }
  };

  /* =========================================================
   * Derivados de UI
   * ========================================================= */
  const anyDirty = useMemo(() => dirtyIds.size > 0, [dirtyIds]);

  const normalizedItems = useMemo(() => {
    const map = new Map<number | string, CategoryWithCommission>();

    items.forEach((it) => {
      const normalizedName = normalizeCategoryName(it.name);
      const key = it.category_id;
      const currentVal = (it as any).participation_value;
      const currentPct = (it as any).participation_pct;

      if (!map.has(key)) {
        map.set(key, { ...it, name: normalizedName });
        return;
      }

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
        participation_value: mergedVal === null ? undefined : roundTo(mergedVal, 2),
        participation_pct: mergedPct === null ? undefined : roundTo(mergedPct, PARTICIPATION_PCT_DECIMALS),
      });
    });

    return Array.from(map.values());
  }, [items]);

  const totalParticipation = useMemo(() => {
    const total = normalizedItems.reduce((acc, it) => acc + Number((it as any).participation_pct ?? 0), 0);
    return roundTo(total, PARTICIPATION_PCT_DECIMALS);
  }, [normalizedItems]);

  const totalParticipationValue = useMemo(() => {
    const total = normalizedItems.reduce((acc, it) => acc + Number((it as any).participation_value ?? 0), 0);
    return roundTo(total, 2);
  }, [normalizedItems]);

  const visibleBaseBudget = isSpecialistRole ? advisorBudgetUsd : getBudgetTotal(budgetId);

  /* =========================================================
   * Render
   * ========================================================= */
  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 py-6">
        <div className="mb-5 rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-100 px-5 sm:px-6 py-5">
            <div className="flex flex-col gap-4">
              <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="flex flex-col gap-2">
                  <button
                    onClick={() => navigate('/budget')}
                    className="text-sm text-primary hover:underline w-fit"
                  >
                    ← Volver a Presupuesto
                  </button>
                  <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
                      Configuración de participación por categoría
                    </h1>
                    <p className="text-sm text-slate-500">
                      Asigna comisión, valor de participación y presupuesto base por asesor o vendedor.
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={saveAll}
                    disabled={!roleId || loading || saving || !anyDirty}
                    className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
                      !roleId || loading || saving || !anyDirty
                        ? 'cursor-not-allowed bg-slate-300 text-slate-600'
                        : 'bg-slate-900 text-white hover:bg-slate-800'
                    }`}
                  >
                    {saving ? 'Guardando...' : 'Guardar todo'}
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <div className="xl:col-span-4">
                  <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                    Vendedores / Asesores
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {sellerRoles.length === 0 ? (
                      <div className="text-xs text-slate-400">No hay vendedores</div>
                    ) : (
                      sellerRoles.map((r) => (
                        <button
                          key={r.id}
                          onClick={() => setRoleId(r.id)}
                          className={`rounded-xl border px-3 py-2 text-sm transition ${
                            roleId === r.id
                              ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                              : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                          }`}
                          title={r.name}
                        >
                          {r.name}
                        </button>
                      ))
                    )}
                  </div>
                </div>

                <div className="xl:col-span-5">
                  <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                    Presupuesto
                  </label>
                  <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                    <select
                      value={budgetId ?? ''}
                      onChange={(e) => setBudgetId(e.target.value ? Number(e.target.value) : null)}
                      className="min-w-[320px] rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="">(Sin presupuesto)</option>
                      {budgets.map((b) => (
                        <option key={b.id} value={b.id}>
                          {b.name} — {b.start_date} → {b.end_date}
                        </option>
                      ))}
                    </select>

                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                      <span className="text-slate-500">Total presupuesto:</span>{' '}
                      <strong>{formatCompactUSD(getBudgetTotal(budgetId))} USD</strong>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div className="px-5 sm:px-6 py-4">
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

            {/* 🔔 Banner cuando cambia el presupuesto */}
            {budgetChangedNotice && anyDirty && (
              <div className="mb-4 flex items-start justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <div>
                  <strong>El presupuesto base cambió.</strong> Los porcentajes se
                  recalcularon automáticamente con la nueva base. Pulsa{' '}
                  <em>Guardar todo</em> para persistir los nuevos %.
                </div>
                <button
                  onClick={() => setBudgetChangedNotice(false)}
                  className="rounded-md px-2 py-1 text-xs text-amber-700 hover:bg-amber-100"
                >
                  Cerrar
                </button>
              </div>
            )}

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
              <table className="w-full min-w-[980px] border-collapse">
                <thead className="bg-slate-50">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-4">Categoría</th>
                    <th className="px-4 py-4">Código</th>
                    <th className="px-4 py-4">Comisión %</th>
                    <th className="px-4 py-4">Comisión 100%</th>
                    <th className="px-4 py-4">Comisión 120%</th>
                    <th className="px-4 py-4">Valor participación</th>
                    <th className="px-4 py-4">Participación %</th>
                    <th className="px-4 py-4">Acciones</th>
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-500">
                        Cargando categorías…
                      </td>
                    </tr>
                  ) : items.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-500">
                        No hay categorías.
                      </td>
                    </tr>
                  ) : (
                    normalizedItems.map((it) => {
                      const isSaving = savingIds.includes(it.category_id);
                      const isDirty = dirtyIds.has(it.category_id);

                      return (
                        <tr key={it.category_id} className="border-t border-slate-100 hover:bg-slate-50/60">
                          <td className="px-4 py-4 align-top">
                            <div className="font-medium text-slate-900">{it.name}</div>
                            <div className="mt-1 text-xs text-slate-500">{it.description ?? ''}</div>
                          </td>

                          <td className="px-4 py-4 align-top text-sm text-slate-500">
                            {it.code}
                          </td>

                          <td className="px-4 py-4 align-top">
                            <input
                              type="number"
                              step="0.01"
                              value={it.commission_percentage ?? ''}
                              onChange={(e) =>
                                onChangeField(it.category_id, 'commission_percentage', e.target.value)
                              }
                              className="w-28 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            {isDirty && <div className="mt-1 text-[11px] text-indigo-600">modificado</div>}
                          </td>

                          <td className="px-4 py-4 align-top">
                            <input
                              type="number"
                              step="0.01"
                              value={it.commission_percentage100 ?? ''}
                              onChange={(e) =>
                                onChangeField(it.category_id, 'commission_percentage100', e.target.value)
                              }
                              className="w-28 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                          </td>

                          <td className="px-4 py-4 align-top">
                            <input
                              type="number"
                              step="0.01"
                              value={it.commission_percentage120 ?? ''}
                              onChange={(e) =>
                                onChangeField(it.category_id, 'commission_percentage120', e.target.value)
                              }
                              className="w-28 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                          </td>

                          <td className="px-4 py-4 align-top">
                            <input
                              type="number"
                              step="0.01"
                              min={0}
                              value={
                                (it as any).participation_value !== undefined &&
                                (it as any).participation_value !== null
                                  ? Number((it as any).participation_value)
                                  : ''
                              }
                              onChange={(e) =>
                                onChangeField(it.category_id, 'participation_value', e.target.value)
                              }
                              className="w-36 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                              placeholder={
                                visibleBaseBudget
                                  ? `Base: ${formatCompactUSD(visibleBaseBudget)}`
                                  : 'Sin base'
                              }
                            />
                            <div className="mt-1 text-[11px] text-slate-400">
                              {isSpecialistRole
                                ? 'Este valor se guarda en BD. El % se calcula sobre el presupuesto del asesor.'
                                : 'Este valor se guarda en BD. El % se calcula sobre el presupuesto seleccionado.'}
                            </div>
                          </td>

                          <td className="px-4 py-4 align-top">
                            <input
                              type="number"
                              value={Number((it as any).participation_pct ?? 0).toFixed(PARTICIPATION_PCT_DISPLAY_DECIMALS)}
                              readOnly
                              className="w-28 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 outline-none"
                              title={`Valor preciso: ${Number((it as any).participation_pct ?? 0).toFixed(PARTICIPATION_PCT_DECIMALS)}%`}
                            />
                          </td>

                          <td className="px-4 py-4 align-top">
                            <div className="flex gap-2">
                              <button
                                onClick={() => saveOne(it)}
                                disabled={isSaving}
                                className={`rounded-lg border px-3 py-2 text-sm transition ${
                                  isSaving
                                    ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                              >
                                {isSaving ? 'Guardando...' : 'Guardar'}
                              </button>

                              <button
                                onClick={() => onDelete(it.category_id)}
                                className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm text-rose-600 transition hover:bg-rose-50"
                                title="Eliminar configuración"
                              >
                                Eliminar
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
              <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                  Total presupuesto asignado
                </div>
                <div className="mt-2 text-lg font-semibold text-slate-900">
                  {formatUSD(totalParticipationValue)}
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  Suma de los valores de participación.
                </div>
              </div>

              <div className="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                      Presupuesto base
                    </div>
                    <div className="mt-2 text-lg font-semibold text-slate-900">
                      {isSpecialistRole ? 'Asesor editable' : 'Vendedor / global'}
                    </div>
                  </div>
                  {isSpecialistRole && (
                    <button
                      onClick={persistAdvisorBudget}
                      disabled={savingAdvisorBudget}
                      className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                      {savingAdvisorBudget ? 'Guardando...' : 'Guardar base'}
                    </button>
                  )}
                </div>

                {isSpecialistRole ? (
                  <div className="mt-3">
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">
                      Presupuesto asesor
                    </label>
                    <input
                      type="number"
                      step="0.01"
                      min={0}
                      value={advisorBudgetUsd}
                      onChange={(e) => setAdvisorBudgetUsd(Number(e.target.value || 0))}
                      className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <div className="mt-1 text-xs text-slate-500">
                      Este valor define el denominador para calcular la participación %.
                    </div>
                  </div>
                ) : (
                  <div className="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    Base actual: <strong>{formatUSD(getBudgetTotal(budgetId))}</strong>
                  </div>
                )}
              </div>

              <div
                className={`rounded-2xl border px-4 py-4 ${
                  Math.abs(totalParticipation - 100) < 0.01
                    ? 'border-emerald-200 bg-emerald-50'
                    : totalParticipation > 100
                      ? 'border-rose-200 bg-rose-50'
                      : 'border-amber-200 bg-amber-50'
                }`}
              >
                <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                  Total participación
                </div>
                <div className="mt-2 text-lg font-semibold text-slate-900">
                  {totalParticipation.toFixed(PARTICIPATION_PCT_DISPLAY_DECIMALS)}%
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  Se recalcula en vivo con el valor de participación.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}