export type ExcelCell = string | number | boolean | Date | null | undefined;

export interface InventoryReportRow {
  codigo: string;
  descripcion: string;
  clasificacionCompleta: string;
  existenciaAnterior: number | null;
  compras: number | null;
  ventas: number | null;
  entrada: number | null;
  salida: number | null;
  existenciaFinal: number | null;
  factorCaja: number | null;
  costoUnitario: number | null;
  totalInvFinal: number | null;
  costoUnitarioUsd: number | null;
  valorFinalUsd: number | null;
  tCambio: number | null;
  cogs: number | null;
  proveedor: string;
  supplier: string;
  brand: string;
  upc1: string;
  upc2: string;
  upc3: string;
  retail: number | null;
  pctCosto: number | null;
  pctMargen: number | null;
  totalGeneral: number | null;
  maximoMes: number | null;
  maximoDia: number | null;
  indRotStock: number | null;
  indRotPromedio: number | null;
  monthValues: number[];
}

export interface ParsedWorkbookData {
  sheetNames: string[];
  rows: InventoryReportRow[];
  selectedSheet: string;
}