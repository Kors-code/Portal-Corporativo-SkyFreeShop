import * as XLSX from "xlsx";
import type { InventoryReportRow, ExcelCell } from "./types";

const CANONICAL = (value: string) =>
  value
    .toUpperCase()
    .trim()
    .replace(/[^A-Z0-9]+/g, "");

const MONTH_HEADER_RE = /^\d{1,2}(\.\d{1,2})?$/;

const isMonthHeader = (header: string) => MONTH_HEADER_RE.test(header.trim());

const normalizeNumberString = (value: string) => {
  const cleaned = value.trim();

  if (cleaned === "" || cleaned === "-") return null;

  if (cleaned.includes(",") && cleaned.includes(".")) {
    // Formato tipo 1.234,56
    const v = cleaned.replace(/\./g, "").replace(",", ".");
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
  }

  if (cleaned.includes(",")) {
    const v = cleaned.replace(",", ".");
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
  }

  const n = Number(cleaned);
  return Number.isNaN(n) ? null : n;
};

export const toNumber = (value: ExcelCell): number | null => {
  if (value === null || value === undefined) return null;
  if (typeof value === "number") return Number.isFinite(value) ? value : null;
  if (typeof value === "boolean") return value ? 1 : 0;

  const text = String(value).trim();
  return normalizeNumberString(text);
};

export const toText = (value: ExcelCell): string => {
  if (value === null || value === undefined) return "";
  return String(value).trim();
};

const getValueByAliases = (
  row: Record<string, ExcelCell>,
  aliases: string[]
): ExcelCell => {
  const map = new Map<string, ExcelCell>();

  Object.entries(row).forEach(([key, value]) => {
    map.set(CANONICAL(key), value);
  });

  for (const alias of aliases) {
    const found = map.get(CANONICAL(alias));
    if (found !== undefined) return found;
  }

  return undefined;
};

const sum = (values: number[]) =>
  values.reduce((acc, cur) => acc + cur, 0);

const max = (values: number[]) =>
  values.length ? Math.max(...values) : 0;

const round2 = (value: number) =>
  Math.round(value * 100) / 100;

const calcPct = (part: number | null, whole: number | null) => {
  if (part === null || whole === null || whole === 0) return null;
  return round2((part / whole) * 100);
};

export const extractWorkbookRows = (
  workbook: XLSX.WorkBook,
  sheetName?: string
): Record<string, ExcelCell>[] => {
  const targetSheet =
    sheetName && workbook.Sheets[sheetName]
      ? workbook.Sheets[sheetName]
      : workbook.Sheets[workbook.SheetNames[0]];

  const aoa = XLSX.utils.sheet_to_json<ExcelCell[]>(targetSheet, {
    header: 1,
    defval: null,
    blankrows: false,
  });

  if (!aoa.length) return [];

  const headerRowIndex = aoa.findIndex((row) =>
    row.some((cell) => {
      const text = CANONICAL(toText(cell));
      return (
        text === "SKU" ||
        text === "SKUCODE" ||
        text === "CODIGO" ||
        text === "DESCRIPCION" ||
        text === "DESCRIPTION"
      );
    })
  );

  if (headerRowIndex === -1) return [];

  const headers = (aoa[headerRowIndex] || []).map((h) => toText(h));
  const dataRows = aoa.slice(headerRowIndex + 1);

  return dataRows
    .filter((row) => row.some((cell) => cell !== null && cell !== ""))
    .map((row) => {
      const obj: Record<string, ExcelCell> = {};
      headers.forEach((header, index) => {
        if (!header) return;
        obj[header] = row[index] ?? null;
      });
      return obj;
    });
};

export const buildInventoryReport = (
  rawRows: Record<string, ExcelCell>[]
): InventoryReportRow[] => {
  return rawRows.map((row) => {
    const headers = Object.keys(row);
    const monthHeaders = headers.filter(isMonthHeader);

    const monthValues = monthHeaders
      .map((header) => toNumber(row[header]))
      .filter((value): value is number => value !== null);

    const codigo = toText(
      getValueByAliases(row, ["SKU", "SKU CODE", "CODIGO", "CODE"])
    );

    const descripcion = toText(
      getValueByAliases(row, [
        "DESCRIPCION",
        "DESCRIPTION",
        "SECUNDARY DESCRIPTION",
      ])
    );

    const clasificacionCompleta = toText(
      getValueByAliases(row, [
        "CLASIFICACION COMPLETA",
        "CLASSIFICATION",
        "CLASSIFICATION DESC",
      ])
    );

    const existenciaAnterior = toNumber(
      getValueByAliases(row, ["EXISTENCIA ANTERIOR"])
    );

    const compras = toNumber(getValueByAliases(row, ["COMPRAS"]));
    const ventas = toNumber(getValueByAliases(row, ["VENTAS"]));
    const entrada = toNumber(getValueByAliases(row, ["ENTRADA"]));
    const salida = toNumber(getValueByAliases(row, ["SALIDA"]));
    const existenciaFinal = toNumber(
      getValueByAliases(row, ["EXISTENCIA FINAL"])
    );

    const factorCaja = toNumber(
      getValueByAliases(row, ["F / C", "F/C", "FC", "FACTOR CAJA"])
    );

    const costoUnitario = toNumber(
      getValueByAliases(row, ["COSTO UNITARIO", "COST UNIT"])
    );

    const totalInvFinal = toNumber(
      getValueByAliases(row, ["TOTAL INV. FINAL", "TOTAL INV FINAL"])
    );

    const costoUnitarioUsd = toNumber(
      getValueByAliases(row, ["COSTO UNITARIO USD", "COST USD"])
    );

    const valorFinalUsd = toNumber(
      getValueByAliases(row, ["VALOR FINAL (USD)", "VALOR FINAL USD"])
    );

    const tCambio = toNumber(getValueByAliases(row, ["T CAMBIO"]));
    const cogs = toNumber(getValueByAliases(row, ["COGS"]));

    const proveedor = toText(getValueByAliases(row, ["PROVEEDOR", "SUPPLIER"]));
    const supplier = toText(getValueByAliases(row, ["SUPPLIER", "PROVEEDOR"]));
    const brand = toText(getValueByAliases(row, ["BRAND", "MARCA"]));
    const upc1 = toText(getValueByAliases(row, ["UPC1", "UPC"]));
    const upc2 = toText(getValueByAliases(row, ["UPC2"]));
    const upc3 = toText(getValueByAliases(row, ["UPC3"]));
    const retail = toNumber(getValueByAliases(row, ["RETAIL", "PRECIO MN"]));

    const totalGeneral =
      toNumber(getValueByAliases(row, ["TOTAL GENERAL"])) ?? sum(monthValues);

    const maximoMes =
      toNumber(getValueByAliases(row, ["MAXIMO MES"])) ??
      (monthValues.length ? max(monthValues) : null);

    const maximoDia = toNumber(getValueByAliases(row, ["MAXIMO DIA"]));

    // Basado en tu salida: Total general / Máximo mes
    const indRotStock =
      totalGeneral !== null && maximoMes && maximoMes > 0
        ? round2(totalGeneral / maximoMes)
        : null;

    // Basado en tu salida de ejemplo: indRotStock - costo unitario
    const indRotPromedio =
      indRotStock !== null && costoUnitario !== null
        ? round2(indRotStock - costoUnitario)
        : null;

    const pctCosto =
      retail !== null && costoUnitario !== null
        ? calcPct(costoUnitario, retail)
        : null;

    const pctMargen =
      retail !== null && costoUnitario !== null
        ? round2(retail - costoUnitario)
        : null;

    return {
      codigo,
      descripcion,
      clasificacionCompleta,
      existenciaAnterior,
      compras,
      ventas,
      entrada,
      salida,
      existenciaFinal,
      factorCaja,
      costoUnitario,
      totalInvFinal,
      costoUnitarioUsd,
      valorFinalUsd,
      tCambio,
      cogs,
      proveedor,
      supplier,
      brand,
      upc1,
      upc2,
      upc3,
      retail,
      pctCosto,
      pctMargen,
      totalGeneral,
      maximoMes,
      maximoDia,
      indRotStock,
      indRotPromedio,
      monthValues,
    };
  });
};

export const formatNumber = (value: number | null | undefined) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "-";
  }

  return new Intl.NumberFormat("es-CO", {
    maximumFractionDigits: 2,
  }).format(value);
};

export const formatMoney = (value: number | null | undefined) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "-";
  }

  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 2,
  }).format(value);
};

export const exportToCSV = (rows: InventoryReportRow[]) => {
  const headers = [
    "CODIGO",
    "DESCRIPCION",
    "CLASIFICACION COMPLETA",
    "EXISTENCIA ANTERIOR",
    "COMPRAS",
    "VENTAS",
    "ENTRADA",
    "SALIDA",
    "EXISTENCIA FINAL",
    "F/C",
    "COSTO UNITARIO",
    "TOTAL INV. FINAL",
    "COSTO UNITARIO USD",
    "VALOR FINAL (USD)",
    "T CAMBIO",
    "COGS",
    "PROVEEDOR",
    "SUPPLIER",
    "BRAND",
    "UPC1",
    "UPC2",
    "UPC3",
    "RETAIL",
    "% COSTO",
    "% MARGEN",
    "TOTAL GENERAL",
    "MAXIMO MES",
    "MAXIMO DIA",
    "IND ROT STOCK",
    "IND ROT PROMEDIO",
  ];

  const csv = [
    headers.join(","),
    ...rows.map((row) =>
      [
        row.codigo,
        row.descripcion,
        row.clasificacionCompleta,
        row.existenciaAnterior ?? "",
        row.compras ?? "",
        row.ventas ?? "",
        row.entrada ?? "",
        row.salida ?? "",
        row.existenciaFinal ?? "",
        row.factorCaja ?? "",
        row.costoUnitario ?? "",
        row.totalInvFinal ?? "",
        row.costoUnitarioUsd ?? "",
        row.valorFinalUsd ?? "",
        row.tCambio ?? "",
        row.cogs ?? "",
        row.proveedor,
        row.supplier,
        row.brand,
        row.upc1,
        row.upc2,
        row.upc3,
        row.retail ?? "",
        row.pctCosto ?? "",
        row.pctMargen ?? "",
        row.totalGeneral ?? "",
        row.maximoMes ?? "",
        row.maximoDia ?? "",
        row.indRotStock ?? "",
        row.indRotPromedio ?? "",
      ]
        .map((value) => `"${String(value).replace(/"/g, '""')}"`)
        .join(",")
    ),
  ].join("\n");

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "inventario-rotacion.csv";
  a.click();
  URL.revokeObjectURL(url);
};