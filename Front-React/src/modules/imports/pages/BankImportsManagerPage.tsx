import { useEffect, useMemo, useState, type ReactNode } from "react";
import { Activity, Banknote, CalendarDays, Download, Filter, Search, ShieldCheck, TrendingUp, X } from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import * as bankApi from "../../../services/bankImports.service";

type BankImportBatch = bankApi.BankImportBatch;
type BankImportsManagerPageProps = {
  initialView?: "imports" | "movements";
};

const BANK_OPTIONS = [
  { value: "", label: "Todos los bancos" },
  { value: "davibank", label: "Davibank" },
  { value: "colpatria", label: "Colpatria" },
  { value: "davivienda", label: "Davivienda" },
  { value: "bancolombia", label: "Bancolombia" },
  { value: "bancodebogota", label: "Banco de Bogota" },
];

function money(value: unknown) {
  const numberValue = Number(value ?? 0);
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(Number.isFinite(numberValue) ? numberValue : 0);
}

function compactMoney(value: unknown) {
  const numberValue = Number(value ?? 0);
  if (!Number.isFinite(numberValue) || numberValue === 0) return "$0";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(numberValue);
}

function percent(value: number) {
  return `${Number.isFinite(value) ? value.toFixed(1) : "0.0"}%`;
}

function bankLabel(value: unknown) {
  const code = String(value ?? "").toLowerCase();
  return (
    {
      davibank: "Davibank / Colpatria",
      colpatria: "Colpatria",
      davivienda: "Davivienda",
      bancolombia: "Bancolombia",
      bancodebogota: "Banco de Bogota",
    } as Record<string, string>
  )[code] ?? String(value ?? "-");
}

function monthLabel(value: unknown) {
  const raw = String(value ?? "").slice(0, 7);
  if (!/^\d{4}-\d{2}$/.test(raw)) return raw || "-";
  const [year, month] = raw.split("-");
  const date = new Date(Number(year), Number(month) - 1, 1);
  if (Number.isNaN(date.getTime())) return raw;
  return new Intl.DateTimeFormat("es-CO", { month: "long", year: "numeric" }).format(date);
}

function dayParts(value: unknown) {
  const raw = String(value ?? "").slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    return { day: "--", month: "", weekday: "" };
  }
  const [year, month, day] = raw.split("-").map((part) => Number(part));
  const date = new Date(year, month - 1, day);
  if (Number.isNaN(date.getTime()) || year < 2020 || year > 2100) {
    return { day: "--", month: "", weekday: "" };
  }
  return {
    day: String(day || "").padStart(2, "0"),
    month: new Intl.DateTimeFormat("es-CO", { month: "short" }).format(date).replace(".", ""),
    weekday: new Intl.DateTimeFormat("es-CO", { weekday: "short" }).format(date).replace(".", ""),
  };
}

const BANK_COLORS: Record<string, string> = {
  davibank: "#0f766e",
  colpatria: "#7c3aed",
  davivienda: "#dc2626",
  bancolombia: "#ca8a04",
  bancodebogota: "#2563eb",
};

export default function BankImportsManagerPage({ initialView = "imports" }: BankImportsManagerPageProps) {
  const [activeView, setActiveView] = useState<"imports" | "movements">(initialView);
  const [batches, setBatches] = useState<BankImportBatch[]>([]);
  const [loading, setLoading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [exportingId, setExportingId] = useState<number | null>(null);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [selectedBatch, setSelectedBatch] = useState<any | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState("");
  const [filterBank, setFilterBank] = useState("");
  const [filterFilename, setFilterFilename] = useState("");
  const [filterFromDate, setFilterFromDate] = useState("");
  const [filterToDate, setFilterToDate] = useState("");
  const [audit, setAudit] = useState<any | null>(null);
  const [movements, setMovements] = useState<any[]>([]);
  const [movementMeta, setMovementMeta] = useState<any | null>(null);
  const [movementTotals, setMovementTotals] = useState<any | null>(null);
  const [movementByMonth, setMovementByMonth] = useState<any[]>([]);
  const [movementByDay, setMovementByDay] = useState<any[]>([]);
  const [movementByBank, setMovementByBank] = useState<any[]>([]);
  const [movementsLoading, setMovementsLoading] = useState(false);
  const [movementsExporting, setMovementsExporting] = useState(false);
  const [movementBank, setMovementBank] = useState("");
  const [movementMonth, setMovementMonth] = useState("");
  const [movementDateFrom, setMovementDateFrom] = useState("");
  const [movementDateTo, setMovementDateTo] = useState("");
  const [depositDateFrom, setDepositDateFrom] = useState("");
  const [depositDateTo, setDepositDateTo] = useState("");
  const [movementSearch, setMovementSearch] = useState("");
  const [movementPage, setMovementPage] = useState(1);

  useEffect(() => {
    void load();
    void loadAudit();
    void loadMovements(1);
  }, []);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankImports();
      setBatches(Array.isArray(data) ? data : data?.data ?? []);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando importaciones bancarias");
    } finally {
      setLoading(false);
    }
  }

  async function loadAudit() {
    try {
      setAudit(await bankApi.getBankMovementsAudit());
    } catch {
      setAudit(null);
    }
  }

  function movementParams(page = movementPage) {
    return {
      bank: movementBank || undefined,
      movement_month: !movementDateFrom && !movementDateTo ? movementMonth || undefined : undefined,
      movement_date_from: movementDateFrom || undefined,
      movement_date_to: movementDateTo || undefined,
      deposit_date_from: depositDateFrom || undefined,
      deposit_date_to: depositDateTo || undefined,
      search: movementSearch || undefined,
      per_page: 50,
      page,
    };
  }

  async function loadMovements(page = movementPage) {
    setMovementsLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankMovements(movementParams(page));
      setMovements(Array.isArray(data?.data) ? data.data : []);
      setMovementMeta(data?.meta ?? null);
      setMovementTotals(data?.totals ?? null);
      setMovementByMonth(Array.isArray(data?.by_month) ? data.by_month : []);
      setMovementByDay(Array.isArray(data?.by_day) ? data.by_day : []);
      setMovementByBank(Array.isArray(data?.by_bank) ? data.by_bank : []);
      setMovementPage(Number(data?.meta?.current_page ?? page));
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando movimientos bancarios");
    } finally {
      setMovementsLoading(false);
    }
  }

  async function handleExportMovements() {
    setMovementsExporting(true);
    setError(null);
    setMsg("");
    try {
      const response = await bankApi.exportBankMovements(movementParams(1));
      const blob = response.data;
      const filename = getFilename(response) || "movimientos_bancarios.csv";
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      setMsg("Movimientos exportados.");
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error exportando movimientos");
    } finally {
      setMovementsExporting(false);
    }
  }

  async function loadMovementDay(bank: string, date: string) {
    setMovementBank(bank);
    setMovementDateFrom(date);
    setMovementDateTo(date);
    setMovementMonth(date.slice(0, 7));
    setMovementPage(1);
    setMovementsLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankMovements({
        ...movementParams(1),
        bank,
        movement_date_from: date,
        movement_date_to: date,
      });
      setMovements(Array.isArray(data?.data) ? data.data : []);
      setMovementMeta(data?.meta ?? null);
      setMovementTotals(data?.totals ?? null);
      setMovementByMonth(Array.isArray(data?.by_month) ? data.by_month : []);
      setMovementByDay(Array.isArray(data?.by_day) ? data.by_day : []);
      setMovementByBank(Array.isArray(data?.by_bank) ? data.by_bank : []);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando movimientos bancarios");
    } finally {
      setMovementsLoading(false);
    }
  }

  async function loadMovementDate(date: string, bank = movementBank) {
    setMovementDateFrom(date);
    setMovementDateTo(date);
    setMovementMonth(date.slice(0, 7));
    setMovementPage(1);
    setMovementsLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankMovements({
        ...movementParams(1),
        bank: bank || undefined,
        movement_date_from: date,
        movement_date_to: date,
      });
      setMovements(Array.isArray(data?.data) ? data.data : []);
      setMovementMeta(data?.meta ?? null);
      setMovementTotals(data?.totals ?? null);
      setMovementByMonth(Array.isArray(data?.by_month) ? data.by_month : []);
      setMovementByDay(Array.isArray(data?.by_day) ? data.by_day : []);
      setMovementByBank(Array.isArray(data?.by_bank) ? data.by_bank : []);
      setMovementPage(Number(data?.meta?.current_page ?? 1));
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando movimientos bancarios");
    } finally {
      setMovementsLoading(false);
    }
  }

  function setSingleMovementDay(date: string) {
    setMovementDateFrom(date);
    setMovementDateTo(date);
    setMovementMonth(date.slice(0, 7));
  }

  async function loadMovementMonth(month: string) {
    setMovementMonth(month);
    setMovementDateFrom("");
    setMovementDateTo("");
    setMovementPage(1);
    setMovementsLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankMovements({
        ...movementParams(1),
        movement_month: month,
        movement_date_from: undefined,
        movement_date_to: undefined,
      });
      setMovements(Array.isArray(data?.data) ? data.data : []);
      setMovementMeta(data?.meta ?? null);
      setMovementTotals(data?.totals ?? null);
      setMovementByMonth(Array.isArray(data?.by_month) ? data.by_month : []);
      setMovementByDay(Array.isArray(data?.by_day) ? data.by_day : []);
      setMovementByBank(Array.isArray(data?.by_bank) ? data.by_bank : []);
      setMovementPage(Number(data?.meta?.current_page ?? 1));
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando movimientos bancarios");
    } finally {
      setMovementsLoading(false);
    }
  }

  async function clearMovementFilters() {
    setMovementBank("");
    setMovementMonth("");
    setMovementDateFrom("");
    setMovementDateTo("");
    setDepositDateFrom("");
    setDepositDateTo("");
    setMovementSearch("");
    setMovementPage(1);
    setMovementsLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankMovements({ per_page: 50, page: 1 });
      setMovements(Array.isArray(data?.data) ? data.data : []);
      setMovementMeta(data?.meta ?? null);
      setMovementTotals(data?.totals ?? null);
      setMovementByMonth(Array.isArray(data?.by_month) ? data.by_month : []);
      setMovementByDay(Array.isArray(data?.by_day) ? data.by_day : []);
      setMovementByBank(Array.isArray(data?.by_bank) ? data.by_bank : []);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando movimientos bancarios");
    } finally {
      setMovementsLoading(false);
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  }

  function toggleSelectAll() {
    const ids = filteredBatches.map((b) => b.id);
    const allSelected = ids.length > 0 && ids.every((id) => selectedIds.includes(id));
    setSelectedIds(allSelected ? [] : ids);
  }

  async function handleDelete(id: number) {
    if (!confirm("Eliminar esta importacion bancaria? Esta accion no se puede deshacer.")) return;

    setDeletingId(id);
    setError(null);
    setMsg("");
    try {
      const res = await bankApi.deleteBankImport(id);
      const deleted = Number(res?.data?.deleted ?? 0);
      if (deleted < 1) {
        throw new Error(res?.data?.message || "La importacion no fue eliminada.");
      }
      setSelectedIds((prev) => prev.filter((x) => x !== id));
      if (selectedBatch?.id === id) setSelectedBatch(null);
      setMsg("Importacion bancaria eliminada.");
      await load();
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando");
    } finally {
      setDeletingId(null);
    }
  }

  async function handleBulkDelete() {
    if (!selectedIds.length) return;
    if (!confirm(`Eliminar ${selectedIds.length} importaciones bancarias? Esta accion no se puede deshacer.`)) return;

    setBulkDeleting(true);
    setError(null);
    setMsg("");
    try {
      const res = await bankApi.deleteBankImports(selectedIds);
      const deleted = Number(res?.data?.deleted ?? 0);
      const expected = selectedIds.length;
      setSelectedIds([]);
      setSelectedBatch(null);
      setMsg(`Importaciones eliminadas: ${deleted}`);
      await load();
      if (deleted < expected) {
        setError(`Se eliminaron ${deleted} de ${expected}. La lista fue recargada.`);
      }
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando en bloque");
    } finally {
      setBulkDeleting(false);
    }
  }

  async function showDetails(batchId: number) {
    setSelectedBatch({ loading: true });
    setError(null);
    try {
      const data = await bankApi.getBankImport(batchId);
      setSelectedBatch(data);
    } catch (e: any) {
      setError(e?.response?.data?.message || "Error cargando detalles");
      setSelectedBatch(null);
    }
  }

  async function handleExportDavibank(batch: BankImportBatch) {
    setExportingId(batch.id);
    setError(null);
    setMsg("");

    try {
      const response = await bankApi.exportBankImport(batch.id);
      const blob = response.data;
      const filename = getFilename(response) || `${batch.bank}_final_${batch.id}.xlsx`;
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      setMsg("Excel generado desde la base de datos.");
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error exportando archivo");
    } finally {
      setExportingId(null);
    }
  }

  const filteredBatches = useMemo(() => {
    return batches.filter((b) => {
      if (filterBank && String(b.bank).toLowerCase() !== filterBank) return false;
      if (filterFilename && !b.filename?.toLowerCase().includes(filterFilename.toLowerCase())) return false;

      if (b.created_at) {
        const time = new Date(b.created_at).getTime();
        if (filterFromDate) {
          const from = new Date(filterFromDate).setHours(0, 0, 0, 0);
          if (time < from) return false;
        }
        if (filterToDate) {
          const to = new Date(`${filterToDate}T23:59:59`).getTime();
          if (time > to) return false;
        }
      }

      return true;
    });
  }, [batches, filterBank, filterFilename, filterFromDate, filterToDate]);

  const totals = useMemo(() => {
    return filteredBatches.reduce(
      (acc, item) => {
        acc.sale += Number(item.total_sale_amount ?? 0);
        acc.commission += Number(item.total_commission_amount ?? 0);
        acc.withholding += Number(item.total_withholding_amount ?? 0);
        acc.income += Number(item.total_income_amount ?? 0);
        return acc;
      },
      { sale: 0, commission: 0, withholding: 0, income: 0 }
    );
  }, [filteredBatches]);

  const selectedBatchMovements = Array.isArray(selectedBatch?.movements_sample)
    ? selectedBatch.movements_sample
    : [];
  const movementRowsCount = Number(movementTotals?.rows_count ?? 0);
  const movementSaleTotal = Number(movementTotals?.sale_amount ?? 0);
  const movementCommissionTotal = Number(movementTotals?.commission_amount ?? 0);
  const movementWithholdingTotal = Number(movementTotals?.withholding_amount ?? 0);
  const movementIncomeTotal = Number(movementTotals?.income_amount ?? 0);
  const commissionRate = movementSaleTotal > 0 ? (movementCommissionTotal / movementSaleTotal) * 100 : 0;
  const withholdingRate = movementSaleTotal > 0 ? (movementWithholdingTotal / movementSaleTotal) * 100 : 0;
  const movementDifference = movementSaleTotal - movementIncomeTotal;
  const monthSummary = movementByMonth
    .map((item) => ({
      month: String(item.movement_month ?? "").slice(0, 7),
      rows: Number(item.rows_count ?? 0),
      days: Number(item.days_count ?? 0),
      banks: Number(item.banks_count ?? 0),
      sale: Number(item.sale_amount ?? 0),
      commission: Number(item.commission_amount ?? 0),
      withholding: Number(item.withholding_amount ?? 0),
      income: Number(item.income_amount ?? 0),
      refunds: Number(item.refund_count ?? 0),
      acquisitions: Number(item.acquisition_count ?? 0),
      missingUid: Number(item.missing_uid ?? 0),
    }))
    .filter((item) => /^\d{4}-\d{2}$/.test(item.month));
  const dailyChart = [...movementByDay]
    .reverse()
    .map((item) => ({
      date: item.movement_date ?? "-",
      bank: bankLabel(item.bank),
      venta: Number(item.sale_amount ?? 0),
      ingreso: Number(item.income_amount ?? 0),
      movimientos: Number(item.rows_count ?? 0),
    }));
  const daySummary = Object.values(
    movementByDay.reduce((acc: Record<string, any>, item) => {
      const date = String(item.movement_date ?? "").slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return acc;
      if (!acc[date]) {
        acc[date] = {
          date,
          rows: 0,
          sale: 0,
          income: 0,
          commission: 0,
          withholding: 0,
          refunds: 0,
          acquisitions: 0,
          missingUid: 0,
          banks: new Set<string>(),
        };
      }
      acc[date].rows += Number(item.rows_count ?? 0);
      acc[date].sale += Number(item.sale_amount ?? 0);
      acc[date].income += Number(item.income_amount ?? 0);
      acc[date].commission += Number(item.commission_amount ?? 0);
      acc[date].withholding += Number(item.withholding_amount ?? 0);
      acc[date].refunds += Number(item.refund_count ?? 0);
      acc[date].acquisitions += Number(item.acquisition_count ?? 0);
      acc[date].missingUid += Number(item.missing_uid ?? 0);
      acc[date].banks.add(String(item.bank ?? ""));
      return acc;
    }, {})
  )
    .map((item: any) => ({
      ...item,
      banksCount: item.banks.size,
      difference: item.sale - item.income,
      parts: dayParts(item.date),
    }))
    .sort((a: any, b: any) => String(b.date).localeCompare(String(a.date)));
  const selectedMovementDay = movementDateFrom && movementDateFrom === movementDateTo ? movementDateFrom : "";
  const activeFiltersCount = [
    movementBank,
    movementMonth,
    movementDateFrom,
    movementDateTo,
    depositDateFrom,
    depositDateTo,
    movementSearch,
  ].filter(Boolean).length;
  const bankChart = movementByBank.map((item) => ({
    bank: bankLabel(item.bank),
    code: String(item.bank ?? ""),
    venta: Number(item.sale_amount ?? 0),
    ingreso: Number(item.income_amount ?? 0),
    movimientos: Number(item.rows_count ?? 0),
    unique_uids: Number(item.unique_uids ?? 0),
    missing_uid: Number(item.missing_uid ?? 0),
  }));

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Importaciones bancarias</h1>
        <p className="text-sm text-gray-600 mt-1">
          Historial de archivos bancarios, movimientos normalizados y borrado por lote.
        </p>
      </div>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

      <div className="bg-white rounded shadow p-2 flex gap-2">
        <button
          onClick={() => setActiveView("imports")}
          className={`px-4 py-2 rounded text-sm ${activeView === "imports" ? "bg-indigo-600 text-white" : "text-gray-700 hover:bg-gray-100"}`}
        >
          Importaciones
        </button>
        <button
          onClick={() => setActiveView("movements")}
          className={`px-4 py-2 rounded text-sm ${activeView === "movements" ? "bg-indigo-600 text-white" : "text-gray-700 hover:bg-gray-100"}`}
        >
          Movimientos guardados
        </button>
      </div>

      {activeView === "imports" && (
        <>
      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Venta</div>
          <div className="text-lg font-semibold">{money(totals.sale)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Comision</div>
          <div className="text-lg font-semibold">{money(totals.commission)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Retencion</div>
          <div className="text-lg font-semibold">{money(totals.withholding)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Ingreso</div>
          <div className="text-lg font-semibold">{money(totals.income)}</div>
        </div>
      </div>

      <div className="bg-white p-4 rounded shadow space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
          <div>
            <label className="text-xs text-gray-600">Banco</label>
            <select
              value={filterBank}
              onChange={(e) => setFilterBank(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            >
              {BANK_OPTIONS.map((bank) => (
                <option key={bank.value} value={bank.value}>
                  {bank.label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs text-gray-600">Archivo</label>
            <input
              type="text"
              value={filterFilename}
              onChange={(e) => setFilterFilename(e.target.value)}
              placeholder="nombre del archivo"
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>
          <div>
            <label className="text-xs text-gray-600">Desde</label>
            <input
              type="date"
              value={filterFromDate}
              onChange={(e) => setFilterFromDate(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>
          <div>
            <label className="text-xs text-gray-600">Hasta</label>
            <input
              type="date"
              value={filterToDate}
              onChange={(e) => setFilterToDate(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>
          <div className="flex items-end">
            <button
              onClick={() => {
                setFilterBank("");
                setFilterFilename("");
                setFilterFromDate("");
                setFilterToDate("");
              }}
              className="w-full px-3 py-2 border rounded text-sm"
            >
              Limpiar filtros
            </button>
          </div>
        </div>
      </div>

      <div className="bg-white rounded shadow overflow-x-auto">
        <div className="p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                onChange={toggleSelectAll}
                checked={filteredBatches.length > 0 && filteredBatches.every((b) => selectedIds.includes(b.id))}
              />
              <span className="text-sm">Seleccionar todo</span>
            </label>
            <button
              onClick={handleBulkDelete}
              disabled={selectedIds.length === 0 || bulkDeleting}
              className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
            >
              {bulkDeleting ? "Eliminando..." : `Eliminar seleccionados (${selectedIds.length})`}
            </button>
          </div>
          <div className="text-sm text-gray-600">{filteredBatches.length} registros</div>
        </div>

        {loading ? (
          <div className="p-6 text-center">Cargando...</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-3 w-12"></th>
                <th className="p-3">Banco</th>
                <th className="p-3">Archivo</th>
                <th className="p-3">Filas</th>
                <th className="p-3">Venta</th>
                <th className="p-3">Comision</th>
                <th className="p-3">Retencion</th>
                <th className="p-3">Ingreso</th>
                <th className="p-3">Estado</th>
                <th className="p-3">Fecha</th>
                <th className="p-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {filteredBatches.map((batch) => (
                <tr key={batch.id} className="border-t">
                  <td className="p-3">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(batch.id)}
                      onChange={() => toggleSelect(batch.id)}
                    />
                  </td>
                  <td className="p-3 capitalize">{batch.bank}</td>
                  <td className="p-3">{batch.filename}</td>
                  <td className="p-3">{batch.rows_imported ?? batch.rows ?? "-"}</td>
                  <td className="p-3">{money(batch.total_sale_amount)}</td>
                  <td className="p-3">{money(batch.total_commission_amount)}</td>
                  <td className="p-3">{money(batch.total_withholding_amount)}</td>
                  <td className="p-3">{money(batch.total_income_amount)}</td>
                  <td className="p-3">
                    <span className="inline-block px-2 py-1 text-xs rounded bg-gray-100">
                      {batch.status ?? "-"}
                    </span>
                  </td>
                  <td className="p-3">
                    {batch.created_at ? new Date(batch.created_at).toLocaleString() : "-"}
                  </td>
                  <td className="p-3 text-right space-x-2">
                    {canExportBank(batch) && (
                      <button
                        onClick={() => handleExportDavibank(batch)}
                        disabled={exportingId === batch.id}
                        className="text-sm text-emerald-700 disabled:opacity-50"
                      >
                        {exportingId === batch.id ? "Exportando..." : "Exportar"}
                      </button>
                    )}
                    <button onClick={() => showDetails(batch.id)} className="text-sm text-indigo-600">
                      Detalles
                    </button>
                    <button
                      onClick={() => handleDelete(batch.id)}
                      disabled={deletingId === batch.id}
                      className="text-sm text-red-600 disabled:opacity-50"
                    >
                      {deletingId === batch.id ? "Borrando..." : "Eliminar"}
                    </button>
                  </td>
                </tr>
              ))}
              {filteredBatches.length === 0 && (
                <tr>
                  <td colSpan={11} className="p-6 text-center text-gray-500">
                    No hay importaciones bancarias con los filtros aplicados
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>
        </>
      )}

      {activeView === "movements" && (
      <div className="space-y-5">
        <div className="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <CalendarDays className="h-5 w-5 text-indigo-600" />
              <h2 className="text-xl font-semibold text-gray-900">Calendario contable bancario</h2>
            </div>
            <p className="text-sm text-gray-600">
              Elige el mes de entrada al banco, revisa solo los dias con movimiento y descarga mes o dia.
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <div className="inline-flex items-center gap-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
              <ShieldCheck className="h-4 w-4" />
              UID {audit?.unique_index_exists ? "protegido" : "por confirmar"}
            </div>
            <button
              onClick={handleExportMovements}
              disabled={movementsExporting}
              className="inline-flex items-center gap-2 rounded border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50"
            >
              <Download className="h-4 w-4" />
              {movementsExporting ? "Exportando" : selectedMovementDay ? "Descargar dia" : "Descargar mes"}
            </button>
          </div>
        </div>

        {monthSummary.length > 0 && (
          <div className="space-y-2">
            <div className="text-xs font-semibold uppercase tracking-wide text-gray-500">Mes de entrada</div>
            <div className="flex gap-3 overflow-x-auto pb-1">
              {monthSummary.map((month) => (
                <button
                  key={month.month}
                  onClick={() => void loadMovementMonth(month.month)}
                  className={`min-w-44 rounded-lg border p-3 text-left transition ${
                    movementMonth === month.month && !selectedMovementDay ? "border-indigo-500 bg-indigo-50" : "border-gray-200 bg-white hover:border-indigo-300"
                  }`}
                >
                  <div className="text-sm font-semibold capitalize text-gray-900">{monthLabel(month.month)}</div>
                  <div className="mt-2 text-lg font-semibold text-gray-900">{compactMoney(month.income)}</div>
                  <div className="text-xs text-gray-500">{month.days} dias · {month.rows.toLocaleString("es-CO")} mov.</div>
                </button>
              ))}
            </div>
          </div>
        )}

        {Array.isArray(audit?.summary_by_bank) && (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            {audit.summary_by_bank.map((item: any) => (
              <div key={item.bank} className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="text-xs font-medium text-gray-500">{bankLabel(item.bank)}</div>
                  <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: BANK_COLORS[String(item.bank ?? "")] ?? "#64748b" }} />
                </div>
                <div className="mt-1 text-lg font-semibold text-gray-900">{Number(item.rows_count ?? 0).toLocaleString("es-CO")}</div>
                <div className="text-xs text-gray-500">
                  UID unicos: {Number(item.unique_uids ?? 0).toLocaleString("es-CO")} · sin UID: {Number(item.missing_uid ?? 0)}
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="rounded-lg bg-gray-50 p-4 space-y-4">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
            <div className="lg:col-span-3">
              <label className="text-xs font-medium text-gray-600">Dia a revisar</label>
              <div className="mt-1 flex gap-2">
                <input
                  type="date"
                  value={selectedMovementDay}
                  onChange={(e) => setSingleMovementDay(e.target.value)}
                  className="w-full border rounded px-3 py-2 text-sm"
                />
                <button
                  onClick={() => selectedMovementDay && void loadMovementDate(selectedMovementDay)}
                  disabled={!selectedMovementDay || movementsLoading}
                  className="inline-flex items-center justify-center rounded bg-indigo-600 px-3 text-white disabled:opacity-50"
                  title="Ver este dia"
                >
                  <CalendarDays className="h-4 w-4" />
                </button>
              </div>
            </div>
            <div className="lg:col-span-3">
              <label className="text-xs font-medium text-gray-600">Banco</label>
              <select
                value={movementBank}
                onChange={(e) => setMovementBank(e.target.value)}
                className="mt-1 w-full border rounded px-3 py-2 text-sm"
              >
                <option value="">Todos los bancos</option>
                <option value="davibank">Davibank / Colpatria</option>
                <option value="colpatria">Colpatria</option>
                <option value="davivienda">Davivienda</option>
                <option value="bancolombia">Bancolombia</option>
                <option value="bancodebogota">Banco de Bogota</option>
              </select>
            </div>
            <div className="lg:col-span-4">
              <label className="text-xs font-medium text-gray-600">Buscar movimiento</label>
              <div className="mt-1 flex items-center gap-2 rounded border bg-white px-3 py-2">
                <Search className="h-4 w-4 text-gray-400" />
                <input
                  type="text"
                  value={movementSearch}
                  onChange={(e) => setMovementSearch(e.target.value)}
                  placeholder="UID, autorizacion, tarjeta, referencia..."
                  className="w-full text-sm outline-none"
                />
              </div>
            </div>
            <div className="lg:col-span-2 flex items-end gap-2">
              <button onClick={() => void loadMovements(1)} disabled={movementsLoading} className="inline-flex flex-1 items-center justify-center gap-2 px-3 py-2 bg-indigo-600 text-white rounded text-sm disabled:opacity-50">
                <Filter className="h-4 w-4" />
                {movementsLoading ? "Buscando" : "Aplicar"}
              </button>
              <button onClick={() => void clearMovementFilters()} disabled={movementsLoading || activeFiltersCount === 0} className="inline-flex items-center justify-center rounded border border-gray-300 px-3 py-2 text-gray-600 disabled:opacity-50" title="Limpiar filtros">
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 border-t border-gray-200 pt-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-xs font-medium text-gray-600">Movimiento desde</label>
                <input type="date" value={movementDateFrom} onChange={(e) => setMovementDateFrom(e.target.value)} className="mt-1 w-full border rounded px-3 py-2 text-sm" />
              </div>
              <div>
                <label className="text-xs font-medium text-gray-600">Movimiento hasta</label>
                <input type="date" value={movementDateTo} onChange={(e) => setMovementDateTo(e.target.value)} className="mt-1 w-full border rounded px-3 py-2 text-sm" />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-xs font-medium text-gray-600">Deposito desde</label>
                <input type="date" value={depositDateFrom} onChange={(e) => setDepositDateFrom(e.target.value)} className="mt-1 w-full border rounded px-3 py-2 text-sm" />
              </div>
              <div>
                <label className="text-xs font-medium text-gray-600">Deposito hasta</label>
                <input type="date" value={depositDateTo} onChange={(e) => setDepositDateTo(e.target.value)} className="mt-1 w-full border rounded px-3 py-2 text-sm" />
              </div>
            </div>
          </div>
        </div>
        </div>

        {daySummary.length > 0 && (
          <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
              <div>
                <h3 className="text-sm font-semibold text-gray-900 capitalize">
                  Calendario de {movementMonth ? monthLabel(movementMonth) : "movimientos"}
                </h3>
                <p className="text-xs text-gray-500">Solo aparecen dias con ingreso bancario registrado.</p>
              </div>
              {selectedMovementDay && (
                <div className="rounded bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700">
                  Revisando {selectedMovementDay}
                </div>
              )}
            </div>
            <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
              {daySummary.map((day: any) => (
                <button
                  key={day.date}
                  onClick={() => void loadMovementDate(day.date)}
                  className={`text-left rounded-lg border p-3 transition hover:border-indigo-300 hover:bg-indigo-50 ${
                    selectedMovementDay === day.date ? "border-indigo-500 bg-indigo-50" : "border-gray-200 bg-white"
                  }`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-xs font-semibold uppercase text-gray-500">{day.parts.weekday}</div>
                      <div className="flex items-baseline gap-1">
                        <span className="text-3xl font-semibold text-gray-900">{day.parts.day}</span>
                        <span className="text-sm font-medium uppercase text-gray-500">{day.parts.month}</span>
                      </div>
                    </div>
                    <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{day.rows} mov.</span>
                  </div>
                  <div className="mt-3 text-lg font-semibold text-emerald-700">{compactMoney(day.income)}</div>
                  <div className="text-xs text-gray-500">{day.banksCount} bancos · venta {compactMoney(day.sale)}</div>
                  <div className="mt-3 flex flex-wrap gap-1">
                    {day.difference !== 0 && <span className="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-700">Dif. {compactMoney(day.difference)}</span>}
                    {day.refunds > 0 && <span className="rounded bg-red-50 px-2 py-0.5 text-xs text-red-700">{day.refunds} devol.</span>}
                    {day.acquisitions > 0 && <span className="rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-700">{day.acquisitions} adquir.</span>}
                    {day.missingUid > 0 && <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{day.missingUid} sin UID</span>}
                  </div>
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          <MetricCard
            title="Movimientos"
            value={movementRowsCount.toLocaleString("es-CO")}
            detail={`${Number(movementMeta?.total ?? 0).toLocaleString("es-CO")} encontrados`}
            icon={<Activity className="h-5 w-5" />}
          />
          <MetricCard
            title="Venta total"
            value={compactMoney(movementSaleTotal)}
            detail={money(movementSaleTotal)}
            icon={<TrendingUp className="h-5 w-5" />}
          />
          <MetricCard
            title="Retencion"
            value={compactMoney(movementWithholdingTotal)}
            detail={`${percent(withholdingRate)} sobre ventas`}
            icon={<CalendarDays className="h-5 w-5" />}
          />
          <MetricCard
            title="Ingreso banco"
            value={compactMoney(movementIncomeTotal)}
            detail={`Dif. venta-ingreso ${compactMoney(movementDifference)} · comision ${percent(commissionRate)}`}
            icon={<Banknote className="h-5 w-5" />}
          />
        </div>

        {bankChart.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            {bankChart.map((item) => {
              const share = movementSaleTotal > 0 ? (item.venta / movementSaleTotal) * 100 : 0;
              return (
                <div key={item.code} className="rounded-lg border border-gray-200 bg-white p-4">
                  <div className="flex items-center justify-between gap-3">
                    <div className="text-sm font-semibold text-gray-900">{item.bank}</div>
                    <span className="h-3 w-3 rounded-full" style={{ backgroundColor: BANK_COLORS[item.code] ?? "#64748b" }} />
                  </div>
                  <div className="mt-3 text-2xl font-semibold text-gray-900">{compactMoney(item.venta)}</div>
                  <div className="mt-1 text-xs text-gray-500">{Number(item.movimientos).toLocaleString("es-CO")} movimientos · {percent(share)}</div>
                  <div className="mt-3 h-2 rounded-full bg-gray-100">
                    <div className="h-2 rounded-full" style={{ width: `${Math.min(100, share)}%`, backgroundColor: BANK_COLORS[item.code] ?? "#64748b" }} />
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {(dailyChart.length > 0 || bankChart.length > 0) && (
          <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div className="xl:col-span-2 rounded-lg border border-gray-200 bg-white p-4">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-gray-900">Venta e ingreso por dia</h3>
                <span className="text-xs text-gray-500">Segun filtros activos</span>
              </div>
              <div className="mt-4 h-72">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={dailyChart} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                    <YAxis tickFormatter={(value) => compactMoney(value)} tick={{ fontSize: 11 }} width={72} />
                    <Tooltip formatter={(value: any) => money(value)} />
                    <Bar dataKey="venta" name="Venta" fill="#2563eb" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="ingreso" name="Ingreso" fill="#0f766e" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
            <div className="rounded-lg border border-gray-200 bg-white p-4">
              <h3 className="text-sm font-semibold text-gray-900">Distribucion por banco</h3>
              <div className="mt-4 h-72">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={bankChart} dataKey="venta" nameKey="bank" innerRadius={58} outerRadius={92} paddingAngle={3}>
                      {bankChart.map((item) => (
                        <Cell key={item.code} fill={BANK_COLORS[item.code] ?? "#64748b"} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(value: any) => money(value)} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <div className="space-y-2">
                {bankChart.map((item) => (
                  <div key={item.code} className="flex items-center justify-between gap-3 text-sm">
                    <span className="flex items-center gap-2 text-gray-600">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: BANK_COLORS[item.code] ?? "#64748b" }} />
                      {item.bank}
                    </span>
                    <span className="font-medium text-gray-900">{compactMoney(item.venta)}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {movementByDay.length > 0 && (
          <div className="overflow-x-auto border rounded">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left">
                <tr>
                  <th className="p-2">Banco</th>
                  <th className="p-2">Dia movimiento</th>
                  <th className="p-2">Filas</th>
                  <th className="p-2">Venta</th>
                  <th className="p-2">Ingreso</th>
                  <th className="p-2 text-right">Accion</th>
                </tr>
              </thead>
              <tbody>
                {movementByDay.slice(0, 8).map((item: any) => (
                  <tr key={`${item.bank}-${item.movement_date}`} className="border-t">
                    <td className="p-2 capitalize">{item.bank}</td>
                    <td className="p-2">{item.movement_date ?? "-"}</td>
                    <td className="p-2">{item.rows_count}</td>
                    <td className="p-2">{money(item.sale_amount)}</td>
                    <td className="p-2">{money(item.income_amount)}</td>
                    <td className="p-2 text-right">
                      <button
                        onClick={() => void loadMovementDay(item.bank ?? "", item.movement_date ?? "")}
                        className="text-indigo-600"
                      >
                        Ver dia
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="overflow-x-auto border rounded">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-2">Banco</th>
                <th className="p-2">Movimiento</th>
                <th className="p-2">Deposito</th>
                <th className="p-2">UID</th>
                <th className="p-2">Referencia</th>
                <th className="p-2">Autorizacion</th>
                <th className="p-2">Tarjeta</th>
                <th className="p-2">Venta</th>
                <th className="p-2">Comision</th>
                <th className="p-2">Retencion</th>
                <th className="p-2">Ingreso</th>
              </tr>
            </thead>
            <tbody>
              {movements.map((item) => (
                <tr key={item.id} className="border-t align-top">
                  <td className="p-2 capitalize">{item.bank}</td>
                  <td className="p-2">{item.movement_date ?? "-"}</td>
                  <td className="p-2">{item.deposit_date ?? "-"}</td>
                  <td className="p-2 font-mono text-xs max-w-52 truncate" title={item.movement_uid}>{item.movement_uid}</td>
                  <td className="p-2">{item.reference ?? item.receipt_number ?? "-"}</td>
                  <td className="p-2">{item.authorization_number ?? "-"}</td>
                  <td className="p-2">{item.card_last_digits ? `****${item.card_last_digits}` : item.card_type ?? "-"}</td>
                  <td className="p-2">{money(item.sale_amount)}</td>
                  <td className="p-2">{money(item.commission_amount)}</td>
                  <td className="p-2">{money(item.withholding_amount)}</td>
                  <td className="p-2">{money(item.income_amount)}</td>
                </tr>
              ))}
              {!movementsLoading && movements.length === 0 && (
                <tr>
                  <td colSpan={11} className="p-6 text-center text-gray-500">
                    No hay movimientos con los filtros aplicados
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between text-sm text-gray-600">
          <span>
            Pagina {movementMeta?.current_page ?? 1} de {movementMeta?.last_page ?? 1} · {Number(movementMeta?.total ?? 0).toLocaleString("es-CO")} movimientos
          </span>
          <div className="flex gap-2">
            <button onClick={() => void loadMovements(Math.max(1, movementPage - 1))} disabled={movementPage <= 1 || movementsLoading} className="px-3 py-1 border rounded disabled:opacity-50">
              Anterior
            </button>
            <button onClick={() => void loadMovements(movementPage + 1)} disabled={movementPage >= Number(movementMeta?.last_page ?? 1) || movementsLoading} className="px-3 py-1 border rounded disabled:opacity-50">
              Siguiente
            </button>
          </div>
        </div>
      </div>
      )}

      {selectedBatch && !selectedBatch.loading && (
        <div className="fixed inset-0 bg-black/40 flex justify-center items-start p-6 z-50">
          <div className="bg-white rounded p-6 max-w-5xl w-full">
            <div className="flex justify-between mb-4">
              <h3 className="font-semibold">
                Detalles - {selectedBatch.filename ?? `batch ${selectedBatch.id}`}
              </h3>
              <button onClick={() => setSelectedBatch(null)} className="text-gray-600">
                Cerrar
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <div className="text-xs text-gray-600 mb-1">Metadatos</div>
                <pre className="bg-gray-50 p-3 rounded text-xs max-h-80 overflow-auto">
                  {JSON.stringify(
                    {
                      id: selectedBatch.id,
                      bank: selectedBatch.bank,
                      filename: selectedBatch.filename,
                      status: selectedBatch.status,
                      rows: selectedBatch.rows,
                      rows_imported: selectedBatch.rows_imported,
                      rows_skipped: selectedBatch.rows_skipped,
                      first_movement_date: selectedBatch.first_movement_date,
                      last_movement_date: selectedBatch.last_movement_date,
                      note: selectedBatch.note,
                    },
                    null,
                    2
                  )}
                </pre>
              </div>
              <div>
                <div className="text-xs text-gray-600 mb-1">Muestra de movimientos</div>
                <pre className="bg-gray-50 p-3 rounded text-xs max-h-80 overflow-auto">
                  {JSON.stringify(selectedBatchMovements, null, 2)}
                </pre>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function MetricCard({ title, value, detail, icon }: { title: string; value: string; detail: string; icon: ReactNode }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <div className="flex items-center justify-between gap-3">
        <div className="text-sm font-medium text-gray-500">{title}</div>
        <div className="rounded bg-indigo-50 p-2 text-indigo-600">{icon}</div>
      </div>
      <div className="mt-3 text-2xl font-semibold text-gray-900">{value}</div>
      <div className="mt-1 text-xs text-gray-500">{detail}</div>
    </div>
  );
}

function canExportBank(batch: BankImportBatch) {
  return ["davibank", "davivienda", "bancolombia", "bancodebogota"].includes(String(batch.bank).toLowerCase()) && Number(batch.rows_imported ?? 0) > 0;
}

function getFilename(response: { headers: { [key: string]: unknown } }) {
  const rawDisposition = response.headers["content-disposition"];
  const disposition = typeof rawDisposition === "string" ? rawDisposition : "";
  const match = disposition.match(/filename="?([^"]+)"?/i);
  return match?.[1];
}
