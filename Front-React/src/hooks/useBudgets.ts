import { useCallback, useEffect, useState } from 'react';
import type { Budget } from '../modules/budgets/types';
import * as svc from '../modules/budgets/services/budgetService';

export default function useBudgets() {
  const [items, setItems] = useState<Budget[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeInfo, setActiveInfo] = useState<any>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await svc.getBudgets();
      setItems(res);
    } catch (err: any) {
      console.error(err);
      setError(err.message || 'Error cargando budgets');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadActive = useCallback(async () => {
    try {
      const res = await svc.getActiveBudget();
      setActiveInfo(res);
    } catch (err) {
      // no crítico: loguear
      console.warn(err);
    }
  }, []);

  useEffect(() => { load(); loadActive(); }, [load, loadActive]);

  const create = useCallback(async (payload: Partial<Budget>) => {
    setSaving(true);
    try {
      await svc.createBudget(payload);
      await load();
      await loadActive();
    } finally {
      setSaving(false);
    }
  }, [load, loadActive]);

  const update = useCallback(async (id: number, payload: Partial<Budget>) => {
    setSaving(true);
    try {
      await svc.updateBudget(id, payload);
      await load();
      await loadActive();
    } finally {
      setSaving(false);
    }
  }, [load, loadActive]);

  const remove = useCallback(async (id: number) => {
    try {
      await svc.deleteBudget(id);
      // recargar lista
      await load();
      await loadActive();
    } catch (err) {
      throw err;
    }
  }, [load, loadActive]);

  return { items, loading, saving, error, activeInfo, load, create, update, remove, setItems };
}
