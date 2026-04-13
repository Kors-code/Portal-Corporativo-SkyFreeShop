import api from "../../../api/axios";

export interface Store {
  id: number;
  name: string;
  code: string;
  type: string;
}

export interface InventoryItem {
  product_id: number;
  product_code: string;
  sku_mia?: string | null;
  description: string;
  classification_desc?: string | null;
  existencia_anterior?: number | null;
  compras?: number | null;
  ventas: number;
  entrada?: number | null;
  salida?: number | null;
  existencia_final?: number | null;
  factor_caja?: number | null;
  cost_unitario?: number | null;
  total_inv_final?: number | null;
  cost_unitario_usd?: number | null;
  valor_final_usd?: number | null;
  t_cambio?: number | null;
  cogs?: number | null;
  proveedor?: string | null;
  supplier?: string | null;
  brand?: string | null;
  upc1?: string | null;
  upc2?: string | null;
  upc3?: string | null;
  retail?: number | null;
  pct_costo?: number | null;
  pct_margen?: number | null;
  maximo_mes?: number | null;
  maximo_dia?: number | null;
  ind_rot_stock?: number | null;
  ind_rot_promedio?: number | null;
  dias_en_existencia?: number | null;
  fecha_ultima_venta?: string | null;
  fecha_ultima_compra?: string | null;
  dias_sin_ventas?: number | null;
  promedio_diario?: number | null;
  mde_zf_bod_1?: number | null;
  mde_zf_bod_2?: number | null;
  mde_bodega_departures?: number | null;
  mde_bodega_arrivals?: number | null;
  mde_tienda_departures?: number | null;
  mde_tienda_arrivals?: number | null;
  mde_danados?: number | null;
  mde_testers?: number | null;
  mde_reparacion?: number | null;
  ctg_bodega?: number | null;
  ctg_tienda?: number | null;
  ctg_danados?: number | null;
  ctg_testers?: number | null;
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

  return response.data;
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