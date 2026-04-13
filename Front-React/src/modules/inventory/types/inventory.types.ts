export interface InventoryItem {
    product_id: number;
    product_code: string;
    description: string;
    classification_desc?: string | null;
    existencia_anterior?: number | null;
    compras?: number | null;
    ventas: number;
    entrada?: number | null;
    salida?: number | null;
    existencia_final: number;
    factor_caja: number;
    cost_unitario: number;
    total_inv_final: number;
    cost_unitario_usd: number;
    valor_final_usd: number;
    t_cambio?: number | null;
    cogs: number;
    proveedor: string;
    supplier: string;
    brand?: string | null;
    upc1?: string | null;
    upc2?: string | null;
    upc3?: string | null;
    retail: number;
    pct_costo: number;
    pct_margen: number;
    maximo_mes: number;
    maximo_dia: number;
    ind_rot_stock: number;
    ind_rot_promedio: number;
    dias_en_existencia?: number | null;
    fecha_ultima_venta?: string | null;
    dias_sin_ventas?: number | null;
    promedio_diario?: number;
}

export interface ImportResponse {
    message: string;
}