// src/modules/commissions/pages/CategoryCommissionsPage.tsx
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  getCategoriesWithCommission,
  upsertCategoryCommission,
  bulkSaveCategoryCommissions,
  deleteCategoryCommission,
  getRoles,
  getBudgets
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
  const [message, setMessage] = useState<{ type: 'ok'|'error', text: string } | null>(null);
  const navigate = useNavigate();

  // filas marcadas como modificadas (dirty)
  const [dirtyIds, setDirtyIds] = useState<Set<number>>(new Set());

  const normalizeCategoryName = (name: string) => {
    const n = String(name).toLowerCase();

    if (n.includes('frag')) {
      return 'FRAGANCIA';
    }

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
          // intentar seleccionar un "vendedor" por defecto
          const vendedor = rolesData.find(r =>
            r.name.toLowerCase().includes('vendedor')
          );
          setRoleId(vendedor ? vendedor.id : rolesData[0].id);
        }

        if (Array.isArray(budgetsData) && budgetsData.length) setBudgetId(prev => prev ?? budgetsData[0].id);
      } catch (err) {
        console.error('Error cargando roles/presupuestos', err);
        setRoles([]);
        setBudgets([]);
      }
    }
    loadMeta();
    return () => { mounted = false; };
  }, []);

  // lista de roles que consideramos "vendedores" para mostrar arriba
  const sellerRoles = roles;

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

      // backend expected to return { categories: [...] } or array directly
      const cats: CategoryWithCommission[] = res?.categories ?? res?.data ?? res ?? [];

      // Si el role seleccionado es el id 2, filtramos solo las categorías indicadas
      let filtered: CategoryWithCommission[] = Array.isArray(cats) ? cats : [];

      if (rId === 2) {
        const allowedCodes = new Set(['19','14','15','16','21','13','19.0','14.0','15.0','16.0','21.0','13.0']);
        filtered = filtered.filter(c => {
          const codeNormalized = String((c as any).code ?? '').toLowerCase().trim();
          const nameNormalized = String((c as any).name ?? '').toLowerCase();

          // permitir por nombre que incluya 'frag' (fragancias)
          if (nameNormalized.includes('frag')) return true;

          // permitir si el code coincide con alguno de los permitidos
          if (allowedCodes.has(codeNormalized)) return true;

          // en caso que la API retorne code numérico
          if (Number(codeNormalized) && allowedCodes.has(String(Number(codeNormalized)))) return true;

          return false;
        });
      }

      setItems(filtered);
      setDirtyIds(new Set()); // reset dirty flags on fresh load
    } catch (err) {
      console.error('Error cargando categorias:', err);
      setItems([]);
    } finally {
      setLoading(false);
    }
  };

  // helpers money
  const markDirty = (categoryId: number, dirty = true) => {
    setDirtyIds(prev => {
      const clone = new Set(prev);
      if (dirty) clone.add(categoryId);
      else clone.delete(categoryId);
      return clone;
    });
  };

  // input handlers
  // cambiamos el tipo del field a string para poder aceptar campos nuevos como 'participation_pct'
  const onChangeField = (categoryId: number, field: string, rawVal: string) => {
    const val = rawVal === '' ? null : Number(rawVal);

    setItems(prev =>
      prev.map(it =>
        it.category_id === categoryId
          ? { ...it, [field]: val }
          : it
      )
    );

    markDirty(categoryId, true);
  };

  const saveOne = async (it: CategoryWithCommission) => {
    if (!roleId) return;
    setSavingIds(s => [...s, it.category_id]);
    try {
      const payload = {
        category_id: it.category_id,
        role_id: roleId,
        budget_id: budgetId,
        commission_percentage: Number(it.commission_percentage ?? 0),
        commission_percentage100: Number(it.commission_percentage100 ?? 0),
        commission_percentage120: Number(it.commission_percentage120 ?? 0),
        participation_pct: Number(it.participation_pct ?? 0)
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
      const payload = items.map(i => ({
        category_id: i.category_id,
        role_id: roleId,
        budget_id: budgetId,
        commission_percentage: Number(i.commission_percentage ?? 0),
        commission_percentage100: Number(i.commission_percentage100 ?? 0),
        commission_percentage120: Number(i.commission_percentage120 ?? 0),
        participation_pct: Number(i.participation_pct ?? 0)
      }));
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

      // clave única: category_id
      const key = it.category_id;

      if (!map.has(key)) {
        map.set(key, {
          ...it,
          name: normalizedName
        });
      } else {
        // si hay duplicados (p.ej. frag), combinamos valores (por si existen duplicados)
        const existing = map.get(key)!;
        map.set(key, {
          ...existing,
          commission_percentage:
            Math.max(
              existing.commission_percentage ?? 0,
              it.commission_percentage ?? 0
            ),
          commission_percentage100:
            Math.max(
              existing.commission_percentage100 ?? 0,
              it.commission_percentage100 ?? 0
            ),
          commission_percentage120:
            Math.max(
              existing.commission_percentage120 ?? 0,
              it.commission_percentage120 ?? 0
            ),
          participation_pct:
            Math.max(
              (existing as any).participation_pct ?? 0,
              (it as any).participation_pct ?? 0
            ),
        });
      }
    });

    return Array.from(map.values());
  }, [items]);

  const totalParticipation = useMemo(() => {
    return normalizedItems.reduce((acc, it) => {
      return acc + Number((it as any).participation_pct ?? 0);
    }, 0);
  }, [normalizedItems]);

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between mb-6 gap-4">
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

        <div className="flex gap-3 items-center">
          {/* ---------- Lista de vendedores arriba (botones) ---------- */}
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

          {/* ---------- Selector de presupuesto ---------- */}
          <div>
            <label className="text-xs text-gray-500 block mb-1">Presupuesto</label>
            <select
              value={budgetId ?? ''}
              onChange={e => setBudgetId(e.target.value ? Number(e.target.value) : null)}
              className="border rounded px-3 py-2 text-sm"
            >
              <option value="">(Sin presupuesto)</option>
              {budgets.map(b => (
                <option key={b.id} value={b.id}>
                  {b.name} — {b.start_date} → {b.end_date}
                </option>
              ))}
            </select>
          </div>

          <div className="flex items-end gap-2">
            <button
              onClick={saveAll}
              disabled={!roleId || loading || saving || !anyDirty}
              className={`px-4 py-2 rounded text-white ${(!roleId || loading || saving || !anyDirty) ? 'bg-gray-400 cursor-not-allowed' : 'bg-indigo-600'}`}
            >
              {saving ? 'Guardando...' : 'Guardar todo'}
            </button>
          </div>
        </div>
      </div>

      {message && (
        <div className={`mb-4 p-3 rounded ${message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
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
              <th className="p-3 text-left">Participación %</th>
              <th className="p-3 text-left">Acciones</th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="p-6 text-center text-gray-500">Cargando categorías…</td></tr>
            ) : items.length === 0 ? (
              <tr><td colSpan={7} className="p-6 text-center text-gray-500">No hay categorías.</td></tr>
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
                    <input
                      type="number"
                      step="0.01"
                      value={it.commission_percentage ?? ''}
                      onChange={e => onChangeField(it.category_id, 'commission_percentage', e.target.value)}
                      className="border px-2 py-1 rounded w-28"
                    />
                    {isDirty && <div className="text-xxs text-indigo-600 mt-1">modificado</div>}
                  </td>

                  <td className="p-3 align-top">
                    <input
                      type="number"
                      step="0.01"
                      value={it.commission_percentage100 ?? ''}
                      onChange={e => onChangeField(it.category_id, 'commission_percentage100', e.target.value)}
                      className="border px-2 py-1 rounded w-28"
                    />
                  </td>

                  <td className="p-3 align-top">
                    <input
                      type="number"
                      step="0.01"
                      value={it.commission_percentage120 ?? ''}
                      onChange={e => onChangeField(it.category_id, 'commission_percentage120', e.target.value)}
                      className="border px-2 py-1 rounded w-28"
                    />
                  </td>

                  <td className="p-3 align-top">
                    <input
                      type="number"
                      step="0.01"
                      value={(it as any).participation_pct ?? ''}
                      onChange={e => onChangeField(it.category_id, 'participation_pct', e.target.value)}
                      className="border px-2 py-1 rounded w-28"
                    />
                  </td>

                  <td className="p-3 align-top">
                    <div className="flex gap-2 items-center">
                      <button
                        onClick={() => saveOne(it)}
                        disabled={isSaving}
                        className={`px-3 py-1 rounded border ${isSaving ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50'}`}
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
            })}
          </tbody>
        </table>

        <div className="mt-4 flex justify-end">
          <div className={`px-4 py-2 rounded text-sm font-semibold ${
            totalParticipation === 100
              ? 'bg-green-50 text-green-700'
              : totalParticipation > 100
                ? 'bg-red-50 text-red-700'
                : 'bg-yellow-50 text-yellow-700'
          }`}>
            Total participación: {totalParticipation.toFixed(2)}%
          </div>
        </div>
      </div>
    </div>
  );
}