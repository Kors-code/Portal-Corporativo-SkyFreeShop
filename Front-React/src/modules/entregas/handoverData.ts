import type { Entrega, Novedad, TurnoKey } from "./types";

export type HandoverCategoryKey =
  | "personal"
  | "producto"
  | "insumos"
  | "testing"
  | "cierre_caja"
  | "temas_varios";

export type HandoverSubcategory = {
  key: string;
  label: string;
};

export type HandoverCategory = {
  key: HandoverCategoryKey;
  label: string;
  apiCategory:
    | "personal"
    | "precios_promociones"
    | "logistica"
    | "cajas"
    | "otros_temas"
    | "temas_pendientes";
  subcategories: HandoverSubcategory[];
};

export type ActaLocal = {
  id: number;
  codigo_acta: string;
  nombre_acta: string;
  lider_entrega_id: number;
  lider_recibe_id: number;
  lider_entrega_nombre: string;
  lider_recibe_nombre: string;
  turno: TurnoKey;
  fecha_acta: string;
  sede?: string;
  estado: Entrega["estado"];
  observaciones?: string;
  novedades: Novedad[];
  created_at: string;
};

export const entregaCategories: HandoverCategory[] = [
  {
    key: "personal",
    label: "Personal",
    apiCategory: "personal",
    subcategories: [
      { key: "dp", label: "DP" },
      { key: "cambio_turno", label: "Cambio turno" },
      { key: "incapacidad", label: "Incapacidad" },
      { key: "otro", label: "Otro" },
    ],
  },
  {
    key: "producto",
    label: "Producto",
    apiCategory: "precios_promociones",
    subcategories: [
      { key: "cambio_precios", label: "Cambio precios" },
      { key: "promociones", label: "Promociones" },
      { key: "etiquetado", label: "Etiquetado" },
      { key: "surtido", label: "Surtido" },
    ],
  },
  {
    key: "insumos",
    label: "Insumos",
    apiCategory: "logistica",
    subcategories: [
      { key: "empaque", label: "Empaque" },
      { key: "papeleria", label: "Papeleria" },
      { key: "cafeteria", label: "Cafeteria" },
    ],
  },
  {
    key: "testing",
    label: "Testing",
    apiCategory: "otros_temas",
    subcategories: [
      { key: "licor", label: "Licor" },
      { key: "perfumeria", label: "Perfumeria" },
      { key: "cocteleria", label: "Cocteleria" },
    ],
  },
  {
    key: "cierre_caja",
    label: "Novedades cierre de caja",
    apiCategory: "cajas",
    subcategories: [
      { key: "descuadre", label: "Descuadre" },
      { key: "macnnect", label: "Macnnect" },
      { key: "equipos", label: "Equipos" },
    ],
  },
  {
    key: "temas_varios",
    label: "Temas varios",
    apiCategory: "temas_pendientes",
    subcategories: [
      { key: "precios_promociones", label: "Precios y promociones" },
      { key: "logistica", label: "Logistica" },
      { key: "cajas", label: "Cajas" },
      { key: "otro_tema", label: "Otro tema" },
    ],
  },
];

export const ACTAS_STORAGE_KEY = "entregas_actas_locales";

export function getLocalActas(): ActaLocal[] {
  try {
    const raw = localStorage.getItem(ACTAS_STORAGE_KEY);
    if (!raw) return [];
    return JSON.parse(raw) as ActaLocal[];
  } catch {
    return [];
  }
}

export function saveLocalActas(actas: ActaLocal[]) {
  localStorage.setItem(ACTAS_STORAGE_KEY, JSON.stringify(actas));
}
