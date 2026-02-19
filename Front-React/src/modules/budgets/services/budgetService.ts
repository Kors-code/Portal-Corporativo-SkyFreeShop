import api from '../../../api/axios';
import type { Budget } from '../types';

export async function getBudgets(): Promise<Budget[]> {
  const res = await api.get('/budgets');
  return res.data;
}

export async function getActiveBudget() {
  const res = await api.get('/budgets/active');
  return res.data;
}

export async function createBudget(payload: Partial<Budget>) {
  const res = await api.post('/budgets', payload);
  return res.data;
}

export async function updateBudget(id: number, payload: Partial<Budget>) {
  const res = await api.put(`/budgets/${id}`, payload);
  return res.data;
}

export async function deleteBudget(id: number) {
  return api.delete(`/budgets/${id}`);
}
