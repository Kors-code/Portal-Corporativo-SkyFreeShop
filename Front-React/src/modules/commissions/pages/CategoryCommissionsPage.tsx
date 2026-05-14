import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';


import {
  getCategoriesWithCommission,
  upsertCategoryCommission,
  bulkSaveCategoryCommissions,
  deleteCategoryCommission,
  getRoles,
  getBudgets,
} from '../services/categoryCommissionService';

import type { CategoryWithCommission, Role } from '../types/comissionscategory';

export default function CategoryCommissionsPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [roleId, setRoleId] = useState<number | null>(null);

  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  const [items, setItems] = useState<CategoryWithCommission[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingIds, setSavingIds] = useState<number[]>([]);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);
  const [dirtyIds, setDirtyIds] = useState<Set<number>>(new Set());

  const navigate = useNavigate();

  const normalizeCategoryName = (name: string) => {
    const n = String(name ?? '').toLowerCase();
    if (n.includes('frag')) return 'FRAGANCIA';
    if (n.includes('diam')) return 'DIAMANTES';
    return String(name ?? '').toUpperCase();
  };

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

  const getBudgetTotal = (bId?: number | null) => {
    if (!bId) return 0;
    const b = budgets.find((item) => item.id === bId);
    if (!b) return 0;
    const candidate = Number(b.target_amount ?? b.total ?? b.total_usd ?? b.budget_usd ?? b.value ?? 0);
    return Number.isFinite(candidate) ? candidate : 0;
  };

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
          '14',
          '15',
          '16',
          '19',
          '21',
          '23',
          '14.0',
          '15.0',
          '16.0',
          '19.0',
          '21.0',
          '23.0',
        ]);

        filtered = filtered.filter((c) => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();

          const keywords = [
            'gift',
            'gifts',
            'watch',
            'watches',
            'jewel',
            'jewelry',
            'sunglass',
            'electronics',
          ];

          if (keywords.some((k) => nameNormalized.includes(k))) return true;
          if (allowedCodes.has(codeNormalized)) return true;
          if (!isNaN(Number(codeNormalized)) && allowedCodes.has(String(Number(codeNormalized)))) return true;

          return false;
        });
      } else {
        filtered = Array.isArray(cats) ? cats : [];
      }

      const budgetTotal = getBudgetTotal(bId);

      const withValues = filtered.map((f) => {
        const rawPct = (f as any).participation_pct;
        const rawVal = (f as any).participation_value;

        const pctNum =
          rawPct !== undefined && rawPct !== null && !isNaN(Number(rawPct)) ? Number(rawPct) : null;

        let valNum =
          rawVal !== undefined && rawVal !== null && !isNaN(Number(rawVal)) ? Number(rawVal) : null;

        if ((valNum === null || valNum === undefined) && pctNum !== null && budgetTotal) {
          valNum = (pctNum / 100) * budgetTotal;
        }

        let pctComputed = pctNum;
        if (valNum !== null && budgetTotal) {
          pctComputed = (valNum / budgetTotal) * 100;
        } else if (pctNum === null) {
          pctComputed = null;
        }

        return {
          ...f,
          participation_value: valNum ?? undefined,
          participation_pct: pctComputed ?? undefined,
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

  const markDirty = (categoryId: number, dirty = true) => {
    setDirtyIds((prev) => {
      const clone = new Set(prev);
      if (dirty) clone.add(categoryId);
      else clone.delete(categoryId);
      return clone;
    });
  };

  const onChangeField = (categoryId: number, field: string, rawVal: string) => {
    if (field === 'participation_pct') return;

    const val = rawVal === '' ? null : Number(rawVal);

    if (field === 'participation_value') {
      const budgetTotal = getBudgetTotal(budgetId);
      const valueNum = val ?? 0;
      const pct = budgetTotal ? (valueNum / budgetTotal) * 100 : 0;

      setItems((prev) =>
        prev.map((it) =>
          it.category_id === categoryId
            ? { ...it, participation_value: valueNum, participation_pct: Number(pct) }
            : it
        )
      );

      markDirty(categoryId, true);
      return;
    }

    setItems((prev) => prev.map((it) => (it.category_id === categoryId ? { ...it, [field]: val } : it)));
    markDirty(categoryId, true);
  };

  const saveOne = async (it: CategoryWithCommission) => {
    if (!roleId) return;

    setSavingIds((s) => [...s, it.category_id]);

    try {
      const budgetTotal = getBudgetTotal(budgetId);
      const valNum = (it as any).participation_value;

      const computedPct =
        budgetTotal && valNum !== null && valNum !== undefined
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
        participation_value: Number((it as any).participation_value ?? 0),
      };

      await upsertCategoryCommission(payload);
      setMessage({ type: 'ok', text: 'Guardado' });
      markDirty(it.category_id, false);
      await loadCategories(roleId, budgetId);
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
      const budgetTotal = getBudgetTotal(budgetId);

      const payload = items.map((i) => {
        const valNum = (i as any).participation_value;
        const computedPct =
          budgetTotal && valNum !== null && valNum !== undefined
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
          participation_value: Number((i as any).participation_value ?? 0),
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
        participation_value: mergedVal === null ? undefined : Number(Number(mergedVal).toFixed(2)),
        participation_pct: mergedPct === null ? undefined : Number(Number(mergedPct).toFixed(6)),
      });
    });

    return Array.from(map.values());
  }, [items]);

  const totalParticipation = useMemo(() => {
    const total = normalizedItems.reduce((acc, it) => acc + Number((it as any).participation_pct ?? 0), 0);
    return Number(total.toFixed(2));
  }, [normalizedItems]);

  const totalParticipationValue = useMemo(() => {
    const total = normalizedItems.reduce((acc, it) => acc + Number((it as any).participation_value ?? 0), 0);
    return Number(total.toFixed(2));
  }, [normalizedItems]);

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex flex-col gap-4 mb-4">
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div className="flex flex-col gap-2">
            <button
              onClick={() => navigate('/budget')}
              className="text-sm text-primary hover:underline w-fit"
            >
              ← Volver a Presupuesto
            </button>
            <div>
              <h1 className="text-2xl font-bold">Configuración de participación por categoría</h1>
              <div className="text-sm text-gray-500">Asignación de participación por categoría</div>
            </div>
          </div>

          <div className="flex gap-3 items-center flex-wrap">
            <div className="flex flex-col">
              <label className="text-xs text-gray-500 block mb-1">Vendedores</label>
              <div className="flex gap-2 items-center flex-wrap">
                {sellerRoles.length === 0 ? (
                  <div className="text-xs text-gray-400">No hay vendedores</div>
                ) : (
                  sellerRoles.map((r) => (
                    <button
                      key={r.id}
                      onClick={() => setRoleId(r.id)}
                      className={`text-sm px-3 py-1 rounded border ${
                        roleId === r.id ? 'bg-indigo-600 text-white' : 'bg-white hover:bg-gray-50'
                      }`}
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
                <select
                  value={budgetId ?? ''}
                  onChange={(e) => setBudgetId(e.target.value ? Number(e.target.value) : null)}
                  className="border rounded px-3 py-2 text-sm"
                >
                  <option value="">(Sin presupuesto)</option>
                  {budgets.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} — {b.start_date} → {b.end_date}
                    </option>
                  ))}
                </select>

                <div className="text-xs text-gray-600">
                  {budgetId ? (
                    <>
                      Total presupuesto:{' '}
                      <strong>{getBudgetTotal(budgetId).toLocaleString(undefined, { maximumFractionDigits: 2 })} USD</strong>
                    </>
                  ) : (
                    <>Seleccione presupuesto</>
                  )}
                </div>
              </div>
            </div>

            <div className="flex items-end gap-2">
              <button
                onClick={saveAll}
                disabled={!roleId || loading || saving || !anyDirty}
                className={`px-4 py-2 rounded text-white ${
                  !roleId || loading || saving || !anyDirty
                    ? 'bg-gray-400 cursor-not-allowed'
                    : 'bg-indigo-600'
                }`}
              >
                {saving ? 'Guardando...' : 'Guardar todo'}
              </button>
            </div>
          </div>
        </div>
      </div>

      {message && (
        <div
          className={`mb-4 p-3 rounded ${
            message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
          }`}
        >
          {message.text}
        </div>
      )}

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
              <tr>
                <td colSpan={8} className="p-6 text-center text-gray-500">
                  Cargando categorías…
                </td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={8} className="p-6 text-center text-gray-500">
                  No hay categorías.
                </td>
              </tr>
            ) : (
              normalizedItems.map((it) => {
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
                      <input
                        type="number"
                        step="0.01"
                        value={it.commission_percentage ?? ''}
                        onChange={(e) => onChangeField(it.category_id, 'commission_percentage', e.target.value)}
                        className="border px-2 py-1 rounded w-28"
                      />
                      {isDirty && <div className="text-xxs text-indigo-600 mt-1">modificado</div>}
                    </td>

                    <td className="p-3 align-top">
                      <input
                        type="number"
                        step="0.01"
                        value={it.commission_percentage100 ?? ''}
                        onChange={(e) => onChangeField(it.category_id, 'commission_percentage100', e.target.value)}
                        className="border px-2 py-1 rounded w-28"
                      />
                    </td>

                    <td className="p-3 align-top">
                      <input
                        type="number"
                        step="0.01"
                        value={it.commission_percentage120 ?? ''}
                        onChange={(e) => onChangeField(it.category_id, 'commission_percentage120', e.target.value)}
                        className="border px-2 py-1 rounded w-28"
                      />
                    </td>

                    <td className="p-3 align-top">
                      <input
                        type="number"
                        step="1"
                        min={0}
                        value={
                          (it as any).participation_value !== undefined && (it as any).participation_value !== null
                            ? Math.round(Number((it as any).participation_value))
                            : ''
                        }
                        onChange={(e) => onChangeField(it.category_id, 'participation_value', e.target.value)}
                        className="border px-2 py-1 rounded w-36"
                        placeholder={
                          budgetId
                            ? `Presupuesto: ${getBudgetTotal(budgetId).toLocaleString()}`
                            : 'Sin presupuesto'
                        }
                      />
                      <div className="text-xxs text-gray-400 mt-1">
                        {budgetId
                          ? `Total presupuesto: ${getBudgetTotal(budgetId).toLocaleString()}`
                          : 'Seleccione presupuesto para calcular %'}
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
                        <button
                          onClick={() => saveOne(it)}
                          disabled={isSaving}
                          className={`px-3 py-1 rounded border ${
                            isSaving ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50'
                          }`}
                        >
                          {isSaving ? 'Guardando...' : 'Guardar'}
                        </button>
                        <button
                          onClick={() => onDelete(it.category_id)}
                          className="px-3 py-1 rounded border bg-white hover:bg-gray-50 text-red-600"
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

        <div className="mt-4 flex flex-col md:flex-row items-end justify-end gap-4 p-4">
          <div className="mt-2 px-4 py-2 rounded text-sm font-semibold bg-blue-50 text-blue-700">
            Total presupuesto asignado:{' '}
            {totalParticipationValue.toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })}{' '}
            USD
          </div>

          <div
            className={`px-4 py-2 rounded text-sm font-semibold ${
              Math.abs(totalParticipation - 100) < 0.01
                ? 'bg-green-50 text-green-700'
                : totalParticipation > 100
                  ? 'bg-red-50 text-red-700'
                  : 'bg-yellow-50 text-yellow-700'
            }`}
          >
            Total participación: {totalParticipation.toFixed(2)}%
          </div>
        </div>
      </div>
    </div>
  );
}