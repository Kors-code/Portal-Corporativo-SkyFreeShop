import api from "../../../api/axios";

export type InventoryAlertStore = {
  id: number;
  code: string;
  name: string;
};

export type InventoryAlertProduct = {
  id: number;
  product_code: string;
  description: string;
  brand?: string | null;
  provider_name?: string | null;
  source?: string;
  total_usd?: number;
  total_units?: number;
};

export type InventoryAlertRecipient = {
  id?: number;
  name?: string | null;
  email: string;
  is_active?: boolean;
};

export type InventoryAlertStoreStatus = {
  store_id?: number | null;
  store_code?: string | null;
  store_name?: string | null;
  level?: string | null;
  label?: string | null;
  stock_actual: number;
  maximo_mes: number;
  dias_disponibles: number;
  suggested_units: number;
};

export type InventoryAlertCurrentProduct = {
  product_id: number;
  product_code: string;
  description: string;
  brand?: string | null;
  provider_name?: string | null;
  stores: InventoryAlertStoreStatus[];
};

export type InventoryAlertHistory = {
  id: number;
  list_id?: number | null;
  list_name?: string | null;
  mode: string;
  status: string;
  sent_count: number;
  skipped_count: number;
  failed_count: number;
  message?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
};

export type InventoryAlertList = {
  id: number;
  name: string;
  is_active: boolean;
  auto_send: boolean;
  frequency_days: number;
  top_months: number;
  top_limit: number;
  stores: InventoryAlertStore[];
  products?: InventoryAlertProduct[];
  recipients?: InventoryAlertRecipient[];
  current_alerts?: InventoryAlertCurrentProduct[];
  history?: InventoryAlertHistory[];
  products_count?: number;
  recipients_count?: number;
};

export type SaveInventoryAlertListPayload = {
  name: string;
  is_active: boolean;
  auto_send: boolean;
  frequency_days: number;
  top_months: number;
  top_limit: number;
  store_ids: number[];
  product_ids?: number[];
  recipients?: InventoryAlertRecipient[];
};

export async function listInventoryAlerts(): Promise<InventoryAlertList[]> {
  const { data } = await api.get("inventory-alerts");
  return data;
}

export async function getInventoryAlert(id: number): Promise<InventoryAlertList> {
  const { data } = await api.get(`inventory-alerts/${id}`);
  return data;
}

export async function getInventoryAlertCurrent(id: number): Promise<InventoryAlertCurrentProduct[]> {
  const { data } = await api.get(`inventory-alerts/${id}/current-alerts`);
  return data;
}

export async function saveInventoryAlert(
  payload: SaveInventoryAlertListPayload,
  id?: number
): Promise<InventoryAlertList> {
  const { data } = id
    ? await api.put(`inventory-alerts/${id}`, payload)
    : await api.post("inventory-alerts", payload);
  return data;
}

export async function deleteInventoryAlert(id: number): Promise<void> {
  await api.delete(`inventory-alerts/${id}`);
}

export type InventoryAlertProductFilters = {
  search?: string;
  brand?: string;
  provider?: string;
};

export type InventoryAlertFilterOptions = {
  brands: string[];
  providers: string[];
};

export async function getInventoryAlertFilterOptions(): Promise<InventoryAlertFilterOptions> {
  const { data } = await api.get("inventory-alerts/filter-options");
  return data;
}

export async function searchInventoryAlertProducts(filters: InventoryAlertProductFilters): Promise<InventoryAlertProduct[]> {
  const { data } = await api.get("inventory-alerts/products", { params: cleanParams(filters) });
  return data;
}

export async function getInventoryAlertTop(params: {
  store_ids: number[];
  months: number;
  limit: number;
  search?: string;
  brand?: string;
  provider?: string;
}): Promise<InventoryAlertProduct[]> {
  const { data } = await api.post("inventory-alerts/top", cleanParams(params));
  return data;
}

export async function addTopToInventoryAlert(
  id: number,
  params: { months: number; limit: number; search?: string; brand?: string; provider?: string }
): Promise<InventoryAlertProduct[]> {
  const { data } = await api.post(`inventory-alerts/${id}/top`, cleanParams(params));
  return data;
}

export async function addProductToInventoryAlert(id: number, productId: number): Promise<InventoryAlertProduct[]> {
  const { data } = await api.post(`inventory-alerts/${id}/products`, { product_id: productId });
  return data;
}

export async function removeProductFromInventoryAlert(id: number, productId: number): Promise<InventoryAlertProduct[]> {
  const { data } = await api.delete(`inventory-alerts/${id}/products/${productId}`);
  return data;
}

export async function sendInventoryAlertNow(id: number): Promise<{ status: string; message: string }> {
  const { data } = await api.post(`inventory-alerts/${id}/send`);
  return data;
}

export async function sendInventoryAlertTest(id: number): Promise<{ status: string; message: string }> {
  const { data } = await api.post(`inventory-alerts/${id}/test`);
  return data;
}

export async function getInventoryAlertHistory(listId?: number): Promise<InventoryAlertHistory[]> {
  const { data } = await api.get("inventory-alerts/history", { params: listId ? { list_id: listId } : undefined });
  return data;
}

function cleanParams<T extends Record<string, unknown>>(params: T): Partial<T> {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && String(value).trim() !== "")
  ) as Partial<T>;
}
