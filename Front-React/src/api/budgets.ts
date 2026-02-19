// src/api/budgets.ts
import api from './axios';

export type Budget = {
    id?: number;
    month: string;       // YYYY-MM
    amount: number;
    total_turns?: number;
    trm?: number | null;
    created_at?: string;
    updated_at?: string;
};

export const getBudgets = () => api.get<Budget[]>('/budgets');
export const getBudget = (id: number) => api.get<Budget>(`/budgets/${id}`);
export const createBudget = (payload: Partial<Budget>) => api.post('/budgets', payload);
export const updateBudget = (id: number, payload: Partial<Budget>) => api.put(`/budgets/${id}`, payload);
export const deleteBudget = (id: number) => api.delete(`/budgets/${id}`);
