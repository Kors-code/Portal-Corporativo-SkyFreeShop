import api from "../../../api/axios";

export interface Store {
  id: number;
  name: string;
  code: string;
  type?: string | null;
}

export interface InventoryItem {
  product_id: number;
  product_code: string;
  sku_mia?: string | null;
  description: string;
  classification_desc?: string | null;
  brand?: string | null;
  supplier?: string | null;
  proveedor?: string | null;

  existencia_anterior?: number | null;
  compras?: number | null;
  ventas?: number | null;
  entrada?: number | null;
  salida?: number | null;
  existencia_final?: number | null;
  stock_actual?: number | null;
  factor_caja?: number | null;
  cost_unitario?: number | null;
  total_inv_final?: number | null;
  cost_unitario_usd?: number | null;
  valor_final_usd?: number | null;
  t_cambio?: number | null;
  cogs?: number | null;
  retail?: number | null;
  pct_costo?: number | null;
  pct_margen?: number | null;

  total_ventas?: number | null;
  maximo_mes?: number | null;
  maximo_mes_key?: string | null;
  maximo_dia?: number | null;
  rotacion_diaria_mes?: number | null;
  promedio_diario?: number | null;
  ind_rot_stock?: number | null;
  ind_rot_promedio?: number | null;
  lead_time?: number | null;
  stock_seguridad?: number | null;
  reorder_point?: number | null;
  sugerido_compra?: number | null;

  days_in_stock?: number | null;
  dias_en_existencia?: number | null;
  last_purchase_date?: string | null;
  last_sale_date?: string | null;
  fecha_ultima_venta?: string | null;
  without_sales_days?: number | null;
  dias_sin_ventas?: number | null;
  last_inventory_date?: string | null;
  toDate?: string | null;
  batch_id?: number | null;

  upc1?: string | null;
  upc2?: string | null;
  upc3?: string | null;

  store_id?: number | null;
  store_code?: string | null;
  store_name?: string | null;
  sales_store_id?: number | null;
  sales_store_code?: string | null;
  sales_store_name?: string | null;
}

export interface InventoryMetricItem extends InventoryItem {
  store_id?: number | null;
  store_code?: string | null;
  store_name?: string | null;
  total_general?: number | null;
  month_columns?: Record<string, number>;
  dias_disponibles?: number | null;
  stock_alert_level?: string | null;
  stock_alert_label?: string | null;
  stock_alert_color?: string | null;
}
export const getStores = async (): Promise<Store[]> => {
  const response = await api.get("stores");
  return response.data;
};

export const importInventory = async (file: File, storeId: number) => {
  const formData = new FormData();
  formData.append("file", file);
  formData.append("store_id", String(storeId));

  const response = await api.post("inventory/import", formData, {
    headers: {
      "Content-Type": "multipart/form-data",
    },
  });

  return response.data as { message?: string; batch_id?: number };
};

export const deleteInventoryBatch = async (batchId: number) => {
  const response = await api.delete(`inventory/batches/${batchId}`);
  return response.data as { message?: string };
};

export const getInventory = async (
  storeId?: number,
  search?: string
): Promise<InventoryItem[]> => {
  const params: Record<string, string | number> = {};

  if (storeId) params.store_id = storeId;
  if (search && search.trim()) params.search = search.trim();

  const response = await api.get("inventory", { params });
  return response.data;
};

export const getInventoryMetrics = async (
  storeId?: number,
  search?: string,
  storeIds?: number[],
  asOfDate?: string
): Promise<InventoryMetricItem[]> => {
  const params: Record<string, string | number | number[]> = {};

  if (storeId) params.store_id = storeId;
  if (search && search.trim()) params.search = search.trim();
  if (storeIds && storeIds.length > 0) params.store_ids = storeIds;
  if (asOfDate) params.as_of_date = asOfDate;

  const response = await api.get("inventory/metrics", { params });
  return response.data;
};

export const runInventoryMetrics = async (
  storeId?: number,
  search?: string,
  storeIds?: number[],
  asOfDate?: string
) => {
  const params: Record<string, string | number | number[]> = {};

  if (storeId) params.store_id = storeId;
  if (search && search.trim()) params.search = search.trim();
  if (storeIds && storeIds.length > 0) params.store_ids = storeIds;
  if (asOfDate) params.as_of_date = asOfDate;

  const response = await api.post("inventory/metrics/run", null, { params });

  return response.data as {
    message?: string;
    executed_at?: string;
    processed_products?: number;
    rows?: InventoryMetricItem[];
  };
};

export const importCatalog = async (file: File) => {
  const formData = new FormData();
  formData.append("file", file);

  const response = await api.post("catalog/import", formData, {
    headers: {
      "Content-Type": "multipart/form-data",
    },
  });

  return response.data as { message?: string };
};
