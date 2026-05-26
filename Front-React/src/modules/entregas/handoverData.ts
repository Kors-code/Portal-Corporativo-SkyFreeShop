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

export const lideresDemo = [
  { id: 1, colaborador: "Lider actual", email: "lider.actual@skyfreeshop.com", sede: "MDE" },
  { id: 2, colaborador: "Ana Martinez", email: "ana.martinez@skyfreeshop.com", sede: "MDE" },
  { id: 3, colaborador: "Carlos Restrepo", email: "carlos.restrepo@skyfreeshop.com", sede: "CTG" },
  { id: 4, colaborador: "Laura Gomez", email: "laura.gomez@skyfreeshop.com", sede: "CLO" },
];

export const personasDemo = [
  "Andrea Salazar",
  "Juan David Moreno",
  "Valentina Rios",
  "Sebastian Cano",
  "Marcela Torres",
];

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
    label: "Temas Varios",
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
    if (!raw) return seedActas();
    return JSON.parse(raw) as ActaLocal[];
  } catch {
    return seedActas();
  }
}

export function saveLocalActas(actas: ActaLocal[]) {
  localStorage.setItem(ACTAS_STORAGE_KEY, JSON.stringify(actas));
}

export function seedActas(): ActaLocal[] {
  const today = new Date().toISOString().slice(0, 10);
  return [
    {
      id: 1001,
      codigo_acta: "ENT-20260525-001",
      nombre_acta: `Acta de entrega ${today} tarde`,
      lider_entrega_id: 2,
      lider_recibe_id: 1,
      lider_entrega_nombre: "Ana Martinez",
      lider_recibe_nombre: "Lider actual",
      turno: "tarde",
      fecha_acta: today,
      sede: "MDE",
      estado: "entregada",
      observaciones: "Queda pendiente validar una novedad de precios antes del cierre.",
      created_at: new Date().toISOString(),
      novedades: [
        {
          categoria: "precios_promociones",
          titulo: "Producto - Promociones",
          descripcion: "Validar promocion de fragancias con etiqueta anterior.",
          prioridad: "media",
          requiere_seguimiento: true,
          resuelto: false,
        },
      ],
    },
    {
      id: 1002,
      codigo_acta: "ENT-20260525-002",
      nombre_acta: `Acta de entrega ${today} manana`,
      lider_entrega_id: 1,
      lider_recibe_id: 3,
      lider_entrega_nombre: "Lider actual",
      lider_recibe_nombre: "Carlos Restrepo",
      turno: "mañana",
      fecha_acta: today,
      sede: "MDE",
      estado: "abierta",
      observaciones: "Acta en preparacion.",
      created_at: new Date().toISOString(),
      novedades: [
        {
          categoria: "personal",
          titulo: "Personal - Cambio turno",
          descripcion: "Juan David Moreno cambia turno con Valentina Rios.",
          prioridad: "media",
          requiere_seguimiento: false,
          resuelto: true,
        },
      ],
    },
  ];
}
