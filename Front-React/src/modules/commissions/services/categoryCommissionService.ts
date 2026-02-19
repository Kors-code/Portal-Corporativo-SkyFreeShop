import api from '../../../api/axios';

/* =========================
 * ROLES
 * ========================= */
export const getRoles = async () => {
  const res = await api.get('/roles');
  return res.data?.roles ?? res.data ?? [];
};

/* =========================
 * BUDGETS  ✅ (ESTO FALTABA)
 * ========================= */
export const getBudgets = async () => {
  const res = await api.get('/budgets');
  // ajusta si tu backend devuelve otra estructura
  return res.data?.budgets ?? res.data ?? [];
};

/* =========================
 * CATEGORY COMMISSIONS
 * ========================= */
export const getCategoriesWithCommission = async (
  roleId: number,
  budgetId?: number
) => {
  const params = new URLSearchParams();
  params.set('role_id', String(roleId));
  if (budgetId) params.set('budget_id', String(budgetId));

  const res = await api.get(
    `/commissions/categories?${params.toString()}`
  );

  return res.data;
};

export const upsertCategoryCommission = async (payload: {
  category_id: number;
  role_id: number;
  commission_percentage: number;
  commission_percentage100?: number;
  commission_percentage120?: number;
  participation_pct?: number;  
}) => {
  const res = await api.post('/commissions/categories', payload);
  return res.data;
};

export const bulkSaveCategoryCommissions = async (
  roleId: number,
  items: any[]
) => {
  const res = await api.post('/commissions/categories/bulk', {
    role_id: roleId,
    items
  });
  return res.data;
};

export const deleteCategoryCommission = async (id: number) => {
  const res = await api.delete(`/commissions/categories/${id}`);
  return res.data;
};
