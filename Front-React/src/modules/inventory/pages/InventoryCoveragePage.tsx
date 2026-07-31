import { useEffect, useMemo, useRef, useState } from "react";
import * as XLSX from "xlsx";
import {
  AlertTriangle,
  BarChart3,
  Building2,
  CheckCircle2,
  ChevronRight,
  CloudUpload,
  Download,
  Filter,
  Package,
  RefreshCw,
  Search,
  ShieldAlert,
  Tags,
  TimerReset,
  Truck,
  X,
} from "lucide-react";

import InventoryCoverageTable from "../components/InventoryCoverageTable";
import {
  getInventoryMetrics,
  getLatestInventoryRows,
  getStores,
  importInventory,
  runInventoryMetrics,
  type InventoryItem,
  type InventoryMetricItem,
  type Store,
} from "../services/inventoryService";

type RunResponse = {
  message?: string;
  executed_at?: string;
  max_months?: number;
  processed_products?: number;
  rows?: InventoryMetricItem[];
};

type ViewMode = "all" | "critical" | "attention" | "healthy";

export default function InventoryCoveragePage() {
  const [stores, setStores] = useState<Store[]>([]);
  const [selectedStoreIds, setSelectedStoreIds] = useState<number[]>([]);
  const [importStoreId, setImportStoreId] = useState<number | "">("");
  const [asOfDate, setAsOfDate] = useState("");
  const [search, setSearch] = useState("");
  const [selectedBrand, setSelectedBrand] = useState("");
  const [selectedProvider, setSelectedProvider] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("");
  const [targetDays, setTargetDays] = useState(60);
  const [leadTimeDays, setLeadTimeDays] = useState(15);
  const [maxMonths, setMaxMonths] = useState(6);
  const [viewMode, setViewMode] = useState<ViewMode>("all");
  const [joinSelectedStores, setJoinSelectedStores] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingStores, setLoadingStores] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [data, setData] = useState<RunResponse | null>(null);

  useEffect(() => {
    void loadStores();
  }, []);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      void loadMetrics();
    }, 650);

    return () => window.clearTimeout(timeoutId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedStoreIds, asOfDate, search]);

  const loadStores = async () => {
    try {
      setLoadingStores(true);
      const response = await getStores();
      setStores(response);
    } catch (err) {
      console.error(err);
    } finally {
      setLoadingStores(false);
    }
  };

  const loadMetrics = async () => {
    try {
      setLoading(true);
      setError("");

      const rows = await getInventoryMetrics(
        undefined,
        search,
        selectedStoreIds.length > 0 ? selectedStoreIds : undefined,
        asOfDate || undefined
      );

      setData({
        message: "Cobertura cargada correctamente.",
        executed_at: new Date().toISOString(),
        processed_products: rows.length,
        rows,
      });
    } catch (err: any) {
      console.error(err);
      setError(err?.response?.data?.message || err?.message || "No se pudo cargar la cobertura.");
    } finally {
      setLoading(false);
    }
  };

  const handleRun = async () => {
    try {
      setLoading(true);
      setError("");
      setMessage("");

      const response = await runInventoryMetrics(
        undefined,
        search,
        selectedStoreIds.length > 0 ? selectedStoreIds : undefined,
        asOfDate || undefined,
        maxMonths
      );

      setData(response);
      setMessage(response?.message || "Cobertura recalculada correctamente.");
    } catch (err: any) {
      console.error(err);
      setError(err?.response?.data?.message || err?.message || "No se pudo recalcular la cobertura.");
    } finally {
      setLoading(false);
    }
  };

  const handleImport = async () => {
    if (!file) {
      setError("Selecciona un archivo de inventario primero.");
      return;
    }

    if (!importStoreId) {
      setError("Selecciona una tienda para importar el archivo.");
      return;
    }

    try {
      setUploading(true);
      setError("");
      setMessage("");

      const response = await importInventory(file, Number(importStoreId));
      setMessage(response?.message ?? "Inventario importado correctamente.");
      setSelectedStoreIds((current) =>
        current.includes(Number(importStoreId)) ? current : [...current, Number(importStoreId)]
      );
      setFile(null);

      const nextStoreIds =
        selectedStoreIds.length > 0
          ? Array.from(new Set([...selectedStoreIds, Number(importStoreId)]))
          : [Number(importStoreId)];

      const rows = await getInventoryMetrics(undefined, search, nextStoreIds, asOfDate || undefined);

      setData({
        message: "Cobertura cargada correctamente.",
        executed_at: new Date().toISOString(),
        processed_products: rows.length,
        rows,
      });
    } catch (err: any) {
      console.error(err);
      setError(err?.response?.data?.message || err?.message || "No se pudo importar el inventario.");
    } finally {
      setUploading(false);
    }
  };

  const handleExportAnalysis = async () => {
    if (selectedStoreIds.length === 0) {
      setError("Selecciona al menos una tienda para exportar el analisis.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");
      const exportStoreIds = resolveExportInventoryStoreIds(stores, selectedStoreIds);
      const inventoryRows = await getLatestInventoryRows(
        exportStoreIds,
        search,
        asOfDate || undefined
      );

      const result = exportInventoryAnalysisWorkbook({
        rows: filteredRows.filter((row) => matchesViewMode(row, viewMode)),
        inventoryRows,
        stores,
        selectedStoreIds,
        targetDays,
        leadTimeValue: leadTimeDays,
      });

      if (!result.exported) {
        setError(result.message);
        return;
      }

      setMessage(result.message);
    } catch (err: any) {
      console.error(err);
      setError(err?.message || "No se pudo exportar el analisis de inventario.");
    } finally {
      setLoading(false);
    }
  };

  const brandOptions = useMemo(() => uniqueValues(data?.rows ?? [], (row) => row.brand), [data]);
  const providerOptions = useMemo(
    () => uniqueValues(data?.rows ?? [], (row) => row.supplier ?? row.proveedor),
    [data]
  );
  const categoryOptions = useMemo(
    () => uniqueValues(data?.rows ?? [], (row) => row.classification_desc),
    [data]
  );

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    const rows = data?.rows ?? [];

    return rows.filter((row) => {
      const matchesSearch =
        !q ||
        (row.product_code ?? "").toLowerCase().includes(q) ||
        (row.description ?? "").toLowerCase().includes(q) ||
        (row.brand ?? "").toLowerCase().includes(q) ||
        (row.supplier ?? "").toLowerCase().includes(q) ||
        (row.proveedor ?? "").toLowerCase().includes(q) ||
        (row.classification_desc ?? "").toLowerCase().includes(q);

      const matchesBrand = !selectedBrand || (row.brand ?? "") === selectedBrand;
      const matchesProvider =
        !selectedProvider || (row.supplier ?? row.proveedor ?? "") === selectedProvider;
      const matchesCategory =
        !selectedCategory || (row.classification_desc ?? "") === selectedCategory;

      return matchesSearch && matchesBrand && matchesProvider && matchesCategory;
    });
  }, [data, search, selectedBrand, selectedProvider, selectedCategory]);

  const planningRows = useMemo(() => {
    if (!joinSelectedStores || selectedStoreIds.length < 2) {
      return filteredRows;
    }

    return groupRowsByProduct(filteredRows);
  }, [filteredRows, joinSelectedStores, selectedStoreIds.length]);

  const enrichedRows = useMemo(() => {
    const planningDays = Math.max(0, targetDays) + normalizeLeadTimeMonths(leadTimeDays) * 30;

    return planningRows
      .map((row) => {
        const monthlyRate = Number(row.rotacion_diaria_mes ?? 0);
        const fallbackRate = Number(row.maximo_mes ?? 0) > 0 ? Number(row.maximo_mes ?? 0) / 30 : 0;
        const stockActual = Number(row.stock_actual ?? 0);
        const rotationBase = monthlyRate > 0 ? monthlyRate : fallbackRate;
        const suggestedPurchase =
          rotationBase > 0 ? Math.max(0, Math.ceil(rotationBase * planningDays - stockActual)) : 0;
        const factorCaja = Number(row.factor_caja ?? 0);

        return {
          ...row,
          suggested_purchase: suggestedPurchase,
          suggested_purchase_cases: factorCaja > 0 ? Math.ceil(suggestedPurchase / factorCaja) : null,
          factor_conversion_error: factorCaja <= 0,
        };
      })
      .filter((row) => {
        if (viewMode === "critical") {
          return row.stock_alert_level === "critico" || row.stock_alert_level === "sin_stock";
        }
        if (viewMode === "attention") {
          return row.stock_alert_level === "alto" || row.stock_alert_level === "medio";
        }
        if (viewMode === "healthy") {
          return row.stock_alert_level === "estable" || row.stock_alert_level === "sin_rotacion";
        }
        return true;
      });
  }, [planningRows, targetDays, leadTimeDays, viewMode]);

  const summary = useMemo(() => {
    const rows = enrichedRows;
    return {
      total: rows.length,
      critical: rows.filter((row) => row.stock_alert_level === "critico" || row.stock_alert_level === "sin_stock").length,
      attention: rows.filter((row) => row.stock_alert_level === "alto" || row.stock_alert_level === "medio").length,
      healthy: rows.filter((row) => row.stock_alert_level === "estable" || row.stock_alert_level === "sin_rotacion").length,
      suggested: rows.reduce((acc, row) => acc + Number(row.suggested_purchase ?? 0), 0),
    };
  }, [enrichedRows]);

  const selectedStores = useMemo(
    () => stores.filter((store) => selectedStoreIds.includes(store.id)),
    [stores, selectedStoreIds]
  );

  const activeFilters = [
    search.trim() ? { key: "search", label: `Texto: ${search.trim()}`, clear: () => setSearch("") } : null,
    selectedBrand ? { key: "brand", label: `Marca: ${selectedBrand}`, clear: () => setSelectedBrand("") } : null,
    selectedProvider ? { key: "provider", label: `Proveedor: ${selectedProvider}`, clear: () => setSelectedProvider("") } : null,
    selectedCategory ? { key: "category", label: `Categoría: ${selectedCategory}`, clear: () => setSelectedCategory("") } : null,
    asOfDate ? { key: "date", label: `Fecha: ${asOfDate}`, clear: () => setAsOfDate("") } : null,
  ].filter(Boolean) as Array<{ key: string; label: string; clear: () => void }>;

  return (
    <div className="min-h-screen bg-[linear-gradient(180deg,#f8fafc_0%,#eef4ff_40%,#f8fafc_100%)] px-4 py-6 text-slate-900 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-[1760px]">
        <section className="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-[0_24px_90px_rgba(15,23,42,0.08)]">
          <div className="bg-[linear-gradient(135deg,#0b1324_0%,#12213f_48%,#1e3a5f_100%)] px-7 py-8 text-white sm:px-10">
            <div className="flex flex-col gap-8 xl:flex-row xl:items-end xl:justify-between">
              <div className="max-w-4xl">
                <div className="mb-4 inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-sm text-slate-100">
                  <ShieldAlert className="h-4 w-4" />
                  Planeación de cobertura
                </div>
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">
                  Importar inventario y Sugerido de compra
                </h1>
                <p className="mt-3 max-w-3xl text-sm leading-7 text-slate-300 sm:text-base">
                  
                </p>
              </div>

              <div className="w-full max-w-md space-y-3">
                <div className="rounded-2xl border border-white/15 bg-white/10 p-4">
                  <label className="block text-sm font-semibold text-white">Meses para maximo</label>
                  <div className="mt-2 flex items-center gap-3">
                    <input
                      type="number"
                      min={1}
                      max={20}
                      step={1}
                      value={maxMonths}
                      onChange={(e) => setMaxMonths(clampInteger(e.target.value, 1, 20, 12))}
                      className="h-12 w-28 rounded-xl border border-white/20 bg-white px-4 text-base font-semibold text-slate-900 outline-none transition focus:border-white"
                    />
                    <div className="min-w-0 text-xs leading-5 text-slate-300">
                      Se aplica cuando presionas Recalcular.
                    </div>
                  </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <button
                    onClick={loadMetrics}
                    disabled={loading || loadingStores}
                    className="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/10 px-5 py-3.5 text-sm font-medium text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    <BarChart3 className="h-4 w-4" />
                    Actualizar vista
                  </button>
                  <button
                    onClick={handleRun}
                    disabled={loading}
                    className="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3.5 text-sm font-semibold text-slate-900 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    <RefreshCw className={`h-4 w-4 ${loading ? "animate-spin" : ""}`} />
                    {loading ? "Procesando..." : "Recalcular"}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div className="grid gap-4 bg-slate-50/80 px-6 py-5 sm:grid-cols-2 xl:grid-cols-5 xl:px-8">
            <StatCard label="SKUs visibles" value={summary.total} icon={<Package className="h-4 w-4" />} accent="slate" />
            <StatCard label="Críticos" value={summary.critical} icon={<AlertTriangle className="h-4 w-4" />} accent="rose" />
            <StatCard label="Atención" value={summary.attention} icon={<ShieldAlert className="h-4 w-4" />} accent="amber" />
            <StatCard label="Estables" value={summary.healthy} icon={<CheckCircle2 className="h-4 w-4" />} accent="emerald" />
            <StatCard label="Compra sugerida" value={formatNumber(summary.suggested)} icon={<Truck className="h-4 w-4" />} accent="sky" />
          </div>
        </section>

        <section className="mt-7 space-y-6">
          <div className="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
            <div className="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_16px_60px_rgba(15,23,42,0.06)]">
              <div className="mb-5 flex items-start justify-between gap-4">
                <div>
                  <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-sky-50 px-3 py-1 text-sm text-sky-700">
                    <CloudUpload className="h-4 w-4" />
                    Paso 1
                  </div>
                  <h2 className="text-2xl font-semibold text-slate-900">Importar inventario</h2>
                  <p className="mt-1 text-sm leading-6 text-slate-500">
                    Empieza por aquí. Esta zona va primero para que la tarea principal del día sea lo más evidente.
                  </p>
                </div>
              </div>

              <div className="grid gap-4 lg:grid-cols-[0.9fr_1.1fr_auto] lg:items-end">
                <FieldCard label="Tienda para importación">
                  <select
                    value={importStoreId}
                    onChange={(e) => setImportStoreId(e.target.value ? Number(e.target.value) : "")}
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                  >
                    <option value="">Selecciona una tienda</option>
                    {stores.map((store) => (
                      <option key={store.id} value={store.id}>
                        {store.code} - {store.name}
                      </option>
                    ))}
                  </select>
                </FieldCard>

                <FieldCard label="Archivo de inventario">
                  <label className="block cursor-pointer rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-4 text-sm text-slate-600 transition hover:border-slate-400 hover:bg-slate-100">
                    <input
                      type="file"
                      accept=".xlsx,.xls,.csv"
                      onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                      className="hidden"
                    />
                    <div className="flex items-center gap-4">
                      <div className="rounded-2xl bg-white p-3 shadow-sm">
                        <CloudUpload className="h-5 w-5 text-slate-600" />
                      </div>
                      <div className="min-w-0">
                        <div className="truncate font-medium text-slate-700">
                          {file ? file.name : "Selecciona el archivo de inventario"}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">
                          Formatos: `.xlsx`, `.xls`, `.csv`
                        </div>
                      </div>
                    </div>
                  </label>
                </FieldCard>

                <button
                  onClick={handleImport}
                  disabled={uploading}
                  className="inline-flex h-[54px] items-center justify-center gap-2 rounded-2xl bg-slate-900 px-6 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <CloudUpload className="h-4 w-4" />
                  {uploading ? "Importando..." : "Importar"}
                </button>
              </div>
            </div>

            <div className="rounded-[2rem] border border-slate-200 bg-[linear-gradient(180deg,#fff7ed_0%,#ffffff_100%)] p-6 shadow-[0_16px_60px_rgba(15,23,42,0.06)]">
              <div className="mb-5">
                <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-700">
                  <TimerReset className="h-4 w-4" />
                  Paso 2
                </div>
                <h2 className="text-2xl font-semibold text-slate-900">Definir compra sugerida</h2>
                <p className="mt-1 text-sm leading-6 text-slate-500">
                  Ajusta la ventana total de cobertura. La tabla se recalcula visualmente con estos dos valores.
                </p>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <FieldCard label="Días objetivo de stock">
                  <NumberStepper value={targetDays} onChange={setTargetDays} suffix="días" />
                </FieldCard>
                <FieldCard label="Lead time del proveedor">
                  <NumberStepper value={leadTimeDays} onChange={setLeadTimeDays} suffix="dias o meses" step={0.05} />
                </FieldCard>
              </div>

              <div className="mt-5 rounded-[1.5rem] border border-amber-200 bg-white px-5 py-4 shadow-sm">
                <div className="text-sm text-slate-500">Cobertura total planeada</div>
                <div className="mt-2 flex flex-wrap items-center gap-3 text-2xl font-semibold text-slate-900">
                  <ChevronRight className="h-5 w-5 text-slate-300" />
                  {Math.round((targetDays + normalizeLeadTimeMonths(leadTimeDays) * 30) * 10) / 10} dias
                  <ChevronRight className="h-5 w-5 text-slate-300" />
                  <span className="text-base font-medium text-slate-500">
                    {Math.round(((targetDays / 30 + normalizeLeadTimeMonths(leadTimeDays)) * 10)) / 10} meses aprox.
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div className="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-[0_16px_60px_rgba(15,23,42,0.06)]">
            <div className="mb-5 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
              <div>
                <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">
                  <Filter className="h-4 w-4" />
                  Paso 3
                </div>
                <h2 className="text-2xl font-semibold text-slate-900">Filtros de trabajo</h2>
                <p className="mt-1 text-sm leading-6 text-slate-500">
                  Los agrupé arriba para que la tabla respire y no se sienta ahogada.
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <ModeChip label="Todos" active={viewMode === "all"} onClick={() => setViewMode("all")} />
                <ModeChip label="Críticos" active={viewMode === "critical"} onClick={() => setViewMode("critical")} />
                <ModeChip label="Atención" active={viewMode === "attention"} onClick={() => setViewMode("attention")} />
                <ModeChip label="Estables" active={viewMode === "healthy"} onClick={() => setViewMode("healthy")} />
                <ModeChip
                  label="Juntar tiendas"
                  active={joinSelectedStores && selectedStoreIds.length >= 2}
                  disabled={selectedStoreIds.length < 2}
                  onClick={() => setJoinSelectedStores((current) => !current)}
                />
                <button
                  type="button"
                  onClick={handleExportAnalysis}
                  disabled={loading || loadingStores}
                  className="inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <Download className="h-4 w-4" />
                  Exportar analisis
                </button>
              </div>
            </div>

            <div className="grid gap-5 2xl:grid-cols-[1.2fr_1fr_1fr_1fr_1fr]">
              <FieldCard label="Buscar">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="SKU, descripción, marca o proveedor"
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-11 pr-4 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                  />
                </div>
              </FieldCard>


              

              <FilterSelect
                label="Marca"
                icon={<Tags className="h-4 w-4" />}
                value={selectedBrand}
                options={brandOptions}
                onChange={setSelectedBrand}
              />

              <FilterSelect
                label="Proveedor"
                icon={<Truck className="h-4 w-4" />}
                value={selectedProvider}
                options={providerOptions}
                onChange={setSelectedProvider}
              />

              <FilterSelect
                label="Categoría"
                icon={<BarChart3 className="h-4 w-4" />}
                value={selectedCategory}
                options={categoryOptions}
                onChange={setSelectedCategory}
              />
            </div>

            <div className="mt-4 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Texto filtrando
                </div>
                <div className="mt-1 truncate text-sm font-medium text-slate-800">
                  {search.trim() ? search : "Sin texto"}
                </div>
              </div>
              {search.trim() && (
                <button
                  type="button"
                  onClick={() => setSearch("")}
                  className="inline-flex items-center justify-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-100"
                >
                  <X className="h-4 w-4" />
                  Limpiar texto
                </button>
              )}
            </div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[1.25fr_0.75fr]">
              <div className="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                <div className="mb-4 flex items-center justify-between gap-3">
                  <div>
                    <h3 className="text-lg font-semibold text-slate-900">Tiendas</h3>
                    <p className="mt-1 text-sm text-slate-500">Selecciona varias tiendas sin controles incómodos.</p>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => setSelectedStoreIds(stores.map((store) => store.id))}
                      className="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm transition hover:bg-slate-100"
                    >
                      Todas
                    </button>
                    <button
                      type="button"
                      onClick={() => setSelectedStoreIds([])}
                      className="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm transition hover:bg-slate-100"
                    >
                      Limpiar
                    </button>
                  </div>
                </div>

                <div className="grid gap-2 sm:grid-cols-2 2xl:grid-cols-3">
                  {stores.map((store) => {
                    const checked = selectedStoreIds.includes(store.id);
                    return (
                      <label
                        key={store.id}
                        className={`flex cursor-pointer items-center gap-3 rounded-2xl border px-4 py-3 text-sm transition ${
                          checked
                            ? "border-slate-900 bg-slate-900 text-white shadow-sm"
                            : "border-slate-200 bg-white text-slate-700 hover:border-slate-300"
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() =>
                            setSelectedStoreIds((current) =>
                              checked
                                ? current.filter((id) => id !== store.id)
                                : [...current, store.id]
                            )
                          }
                          className="h-4 w-4 rounded border-slate-300"
                        />
                        <span className="truncate">
                          {store.code} - {store.name}
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                <h3 className="text-lg font-semibold text-slate-900">Contexto activo</h3>
                <p className="mt-1 text-sm text-slate-500">Esto resume lo que estás viendo antes de bajar a la tabla.</p>

                <div className="mt-4 space-y-4">
                  <div>
                    <div className="mb-2 text-sm font-medium text-slate-700">Tiendas activas</div>
                    <div className="flex flex-wrap gap-2">
                      {selectedStores.length === 0 ? (
                        <span className="rounded-full bg-white px-3 py-2 text-sm text-slate-500 shadow-sm">
                          Todas las tiendas
                        </span>
                      ) : (
                        selectedStores.map((store) => (
                          <span
                            key={store.id}
                            className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-2 text-sm text-slate-700 shadow-sm"
                          >
                            <Building2 className="h-3.5 w-3.5 text-slate-400" />
                            {store.code}
                          </span>
                        ))
                      )}
                    </div>
                  </div>

                  <div>
                    <div className="mb-2 text-sm font-medium text-slate-700">Filtros activos</div>
                    <div className="flex flex-wrap gap-2">
                      {activeFilters.length === 0 ? (
                        <span className="rounded-full bg-white px-3 py-2 text-sm text-slate-500 shadow-sm">
                          Sin filtros extra
                        </span>
                      ) : (
                        activeFilters.map((filter) => (
                          <button
                            key={filter.key}
                            type="button"
                            onClick={filter.clear}
                            className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-2 text-sm text-slate-700 shadow-sm transition hover:bg-slate-50"
                          >
                            {filter.label}
                            <X className="h-3.5 w-3.5 text-slate-400" />
                          </button>
                        ))
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        {message && (
          <div className="mt-6 rounded-[1.5rem] border border-emerald-200 bg-emerald-50 px-5 py-4 text-emerald-700 shadow-sm">
            {message}
          </div>
        )}

        {error && (
          <div className="mt-6 rounded-[1.5rem] border border-rose-200 bg-rose-50 px-5 py-4 text-rose-700 shadow-sm">
            {error}
          </div>
        )}

        <div className="mt-7 rounded-[2rem] border border-slate-200 bg-white p-4 shadow-[0_18px_70px_rgba(15,23,42,0.06)] sm:p-5">
          <InventoryCoverageTable
            rows={enrichedRows}
            loading={loading}
            title={joinSelectedStores && selectedStoreIds.length >= 2 ? "Tabla principal de cobertura unificada" : "Tabla principal de cobertura"}
            subtitle="Más espacio, columnas respirando mejor y sugerido de compra visible para navegar por muchos SKUs sin sentir la vista apretada."
          />
        </div>
      </div>
    </div>
  );
}

function StatCard({
  label,
  value,
  icon,
  accent,
}: {
  label: string;
  value: string | number;
  icon: React.ReactNode;
  accent: "slate" | "rose" | "amber" | "emerald" | "sky";
}) {
  const styles = {
    slate: "bg-slate-100 text-slate-700",
    rose: "bg-rose-100 text-rose-700",
    amber: "bg-amber-100 text-amber-700",
    emerald: "bg-emerald-100 text-emerald-700",
    sky: "bg-sky-100 text-sky-700",
  } as const;

  return (
    <div className="rounded-[1.4rem] border border-slate-200 bg-white p-5 shadow-sm">
      <div className={`mb-3 inline-flex h-10 w-10 items-center justify-center rounded-2xl ${styles[accent]}`}>
        {icon}
      </div>
      <div className="text-xs uppercase tracking-[0.18em] text-slate-500">{label}</div>
      <div className="mt-2 text-3xl font-semibold text-slate-900">{value}</div>
    </div>
  );
}

function FieldCard({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label className="mb-2 block text-sm font-medium text-slate-700">{label}</label>
      {children}
    </div>
  );
}

function clampInteger(value: string, min: number, max: number, fallback: number): number {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) return fallback;

  return Math.min(max, Math.max(min, parsed));
}

function FilterSelect({
  label,
  icon,
  value,
  options,
  onChange,
}: {
  label: string;
  icon: React.ReactNode;
  value: string;
  options: string[];
  onChange: (value: string) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  const filteredOptions = useMemo(() => {
    const normalizedQuery = normalizeForSearch(query);

    if (!normalizedQuery) {
      return options;
    }

    return options.filter((option) => normalizeForSearch(option).includes(normalizedQuery));
  }, [options, query]);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const timeoutId = window.setTimeout(() => inputRef.current?.focus(), 0);
    const handlePointerDown = (event: PointerEvent) => {
      if (!wrapperRef.current?.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener("pointerdown", handlePointerDown);

    return () => {
      window.clearTimeout(timeoutId);
      document.removeEventListener("pointerdown", handlePointerDown);
    };
  }, [isOpen]);

  const selectOption = (nextValue: string) => {
    onChange(nextValue);
    setQuery("");
    setIsOpen(false);
  };

  return (
    <FieldCard label={label}>
      <div ref={wrapperRef} className="relative">
        <div className="pointer-events-none absolute left-4 top-[27px] -translate-y-1/2 text-slate-400">
          {icon}
        </div>
        <button
          type="button"
          onClick={() => setIsOpen((current) => !current)}
          className="flex min-h-[52px] w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-11 pr-4 text-left text-sm outline-none transition hover:bg-white focus:border-slate-400 focus:bg-white"
        >
          <span className={`min-w-0 truncate ${value ? "font-medium text-slate-800" : "text-slate-500"}`}>
            {value || "Todos"}
          </span>
          <ChevronRight className={`h-4 w-4 shrink-0 text-slate-400 transition ${isOpen ? "rotate-90" : ""}`} />
        </button>

        {isOpen && (
          <div className="absolute left-0 right-0 top-[calc(100%+0.5rem)] z-30 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_18px_60px_rgba(15,23,42,0.16)]">
            <div className="border-b border-slate-100 p-3">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  ref={inputRef}
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === "Escape") {
                      setIsOpen(false);
                    }
                    if (event.key === "Enter" && filteredOptions.length === 1) {
                      selectOption(filteredOptions[0]);
                    }
                  }}
                  placeholder={`Escribe ${label.toLowerCase()}`}
                  className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                />
              </div>
              <div className="mt-2 text-xs font-medium text-slate-400">
                {query.trim()
                  ? `${filteredOptions.length} resultado${filteredOptions.length === 1 ? "" : "s"}`
                  : `${options.length} opcion${options.length === 1 ? "" : "es"}`}
              </div>
            </div>

            <div className="max-h-64 overflow-y-auto p-2">
              <button
                type="button"
                onClick={() => selectOption("")}
                className={`flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm transition ${
                  !value ? "bg-slate-900 font-semibold text-white" : "text-slate-700 hover:bg-slate-50"
                }`}
              >
                Todos
                {!value && <CheckCircle2 className="h-4 w-4" />}
              </button>

              {filteredOptions.map((option) => {
                const selected = option === value;

                return (
                  <button
                    key={option}
                    type="button"
                    onClick={() => selectOption(option)}
                    className={`mt-1 flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition ${
                      selected ? "bg-slate-900 font-semibold text-white" : "text-slate-700 hover:bg-slate-50"
                    }`}
                  >
                    <span className="min-w-0 truncate">{option}</span>
                    {selected && <CheckCircle2 className="h-4 w-4 shrink-0" />}
                  </button>
                );
              })}

              {filteredOptions.length === 0 && (
                <div className="px-3 py-6 text-center text-sm font-medium text-slate-400">
                  Sin resultados
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </FieldCard>
  );
}

function NumberStepper({
  value,
  onChange,
  suffix,
  step = 1,
}: {
  value: number;
  onChange: (value: number) => void;
  suffix: string;
  step?: number;
}) {
  const sliderMax = Math.max(180, value);
  const updateValue = (nextValue: number) => {
    if (!Number.isFinite(nextValue)) {
      onChange(0);
      return;
    }

    onChange(Math.max(0, Math.round(nextValue * 100) / 100));
  };

  return (
    <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3.5">
      <div className="flex items-center justify-between gap-3">
        <button
          type="button"
          onClick={() => updateValue(value - 1)}
          className="rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200"
        >
          -1
        </button>
        <div className="min-w-0 text-center">
          <input
            type="number"
            min={0}
            step={step}
            value={value}
            onChange={(e) => updateValue(Number.parseFloat(e.target.value))}
            className="h-10 w-24 rounded-xl border border-slate-200 bg-slate-50 text-center text-2xl font-semibold text-slate-900 outline-none transition focus:border-slate-400 focus:bg-white"
          />
          <div className="text-xs text-slate-500">{suffix}</div>
        </div>
        <button
          type="button"
          onClick={() => updateValue(value + 1)}
          className="rounded-xl bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
        >
          +1
        </button>
      </div>
      <input
        type="range"
        min={0}
        max={sliderMax}
        step={step}
        value={value}
        onChange={(e) => updateValue(Number(e.target.value))}
        className="mt-4 w-full accent-slate-900"
      />
    </div>
  );
}

function ModeChip({
  label,
  active,
  disabled = false,
  onClick,
}: {
  label: string;
  active: boolean;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`rounded-full px-4 py-2 text-sm font-medium transition ${
        active
          ? "bg-slate-900 text-white shadow-sm"
          : "bg-white text-slate-700 hover:bg-slate-100"
      } disabled:cursor-not-allowed disabled:opacity-50`}
    >
      {label}
    </button>
  );
}

type AnalysisWorkbookInput = {
  rows: InventoryMetricItem[];
  inventoryRows: InventoryItem[];
  stores: Store[];
  selectedStoreIds: number[];
  targetDays: number;
  leadTimeValue: number;
};

type AnalysisSheetGroup = {
  key: string;
  name: string;
  rows: InventoryMetricItem[];
};

type InventorySheetGroup = {
  key: string;
  name: string;
  rows: InventoryItem[];
};

function exportInventoryAnalysisWorkbook({
  rows,
  inventoryRows,
  stores,
  selectedStoreIds,
  targetDays,
  leadTimeValue,
}: AnalysisWorkbookInput): { exported: boolean; message: string } {
  const groups = buildAnalysisSheetGroups(rows, stores, selectedStoreIds);
  const inventoryGroups = buildInventorySheetGroups(inventoryRows, stores, selectedStoreIds);

  if (groups.length === 0 && inventoryGroups.length === 0) {
    return {
      exported: false,
      message: "No hay tiendas validas para exportar.",
    };
  }

  const workbook = XLSX.utils.book_new();
  workbook.Workbook = { CalcPr: { fullCalcOnLoad: true } } as any;

  const objectiveMonths = round2(Math.max(0, targetDays) / 30);
  const leadTimeMonths = round2(normalizeLeadTimeMonths(leadTimeValue));
  const usedSheetNames = new Set<string>();

  groups.forEach((group) => {
    const sheet = buildAnalysisWorksheet(group.rows, {
      targetDays,
      objectiveMonths,
      leadTimeMonths,
    });
    const safeName = uniqueSheetName(group.name, usedSheetNames);
    XLSX.utils.book_append_sheet(workbook, sheet, safeName);
  });

  inventoryGroups.forEach((group) => {
    const sheet = buildInventoryWorksheet(group.rows);
    const safeName = uniqueSheetName(group.name, usedSheetNames);
    XLSX.utils.book_append_sheet(workbook, sheet, safeName);
  });

  const date = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(workbook, `analisis-inventario-${date}.xlsx`);

  return {
    exported: true,
    message: `Analisis exportado correctamente (${groups.length + inventoryGroups.length} hoja${groups.length + inventoryGroups.length === 1 ? "" : "s"}).`,
  };
}

function buildAnalysisSheetGroups(
  rows: InventoryMetricItem[],
  stores: Store[],
  selectedStoreIds: number[]
): AnalysisSheetGroup[] {
  const storesById = new Map(stores.map((store) => [store.id, store]));
  const storesByCode = new Map(stores.map((store) => [normalizeStoreCode(store.code), store]));
  const selectedAnalysisCodes = new Set(
    selectedStoreIds
      .map((id) => storesById.get(id)?.code)
      .filter((code): code is string => Boolean(code))
      .map(normalizeStoreCode)
      .map(salesStoreCodeFor)
      .filter((code): code is string => code !== null && !isWarehouseStoreCode(code))
  );
  const groups = new Map<string, AnalysisSheetGroup>();

  rows.forEach((row) => {
    const codes = rowStoreCodes(row);
    const code = codes
      .map(salesStoreCodeFor)
      .find((value) => value && selectedAnalysisCodes.has(value) && !isWarehouseStoreCode(value)) ?? "";
    if (!code) {
      return;
    }

    const store = storesByCode.get(code);
    pushAnalysisRow(groups, code, `ANALISIS ${store?.name ?? row.store_name ?? code}`, row);
  });

  return Array.from(groups.values()).map((group) => ({
    ...group,
    rows: combineRowsBySku(group.rows),
  }));
}

function buildInventorySheetGroups(
  rows: InventoryItem[],
  stores: Store[],
  selectedStoreIds: number[]
): InventorySheetGroup[] {
  const storesById = new Map(stores.map((store) => [store.id, store]));
  const selectedPairs = uniqueText(
    selectedStoreIds
      .map((id) => storesById.get(id)?.code)
      .filter((code): code is string => Boolean(code))
      .map(normalizeStoreCode)
      .map((code) => warehouseStoreCodeFor(code) ?? code)
  )
    .filter(isWarehouseStoreCode)
    .map((warehouseCode) => ({
      warehouseCode,
      salesCode: salesStoreCodeFor(warehouseCode),
    }));
  const groups = new Map<string, AnalysisSheetGroup>();

  selectedPairs.forEach(({ warehouseCode, salesCode }) => {
    const pairRows = rows.filter((row) => {
      const code = normalizeStoreCode(row.store_code ?? "");
      return code === warehouseCode || code === salesCode;
    });

    if (pairRows.length === 0) {
      return;
    }

    groups.set(warehouseCode, {
      key: warehouseCode,
      name: `INVENTARIO ${warehouseCode}`,
      rows: combineInventoryRowsBySku(pairRows),
    });
  });

  return Array.from(groups.values());
}

function pushAnalysisRow(
  groups: Map<string, AnalysisSheetGroup>,
  key: string,
  name: string,
  row: InventoryMetricItem
) {
  const current = groups.get(key) ?? { key, name, rows: [] };
  current.rows.push(row);
  groups.set(key, current);
}

function combineRowsBySku(rows: InventoryMetricItem[]): InventoryMetricItem[] {
  const grouped = new Map<string, InventoryMetricItem[]>();

  rows.forEach((row) => {
    const key = productGroupKey(row);
    grouped.set(key, [...(grouped.get(key) ?? []), row]);
  });

  return Array.from(grouped.values()).map((group) => {
    if (group.length === 1) {
      return group[0];
    }

    const first = group[0];
    const monthColumns = mergeMonthColumns(group.map((row) => row.month_columns));
    const monthValues = Object.values(monthColumns);
    const maximoMes = monthValues.length > 0
      ? Math.max(...monthValues)
      : Math.max(...group.map((row) => Number(row.maximo_mes ?? 0)));

    return {
      ...first,
      stock_actual: sumNumbers(group, (row) => row.stock_actual),
      month_columns: monthColumns,
      maximo_mes: maximoMes,
      factor_caja: group.find((row) => Number(row.factor_caja ?? 0) > 0)?.factor_caja ?? first.factor_caja,
    };
  });
}

function combineInventoryRowsBySku(rows: InventoryItem[]): InventoryItem[] {
  const grouped = new Map<string, InventoryItem[]>();

  rows.forEach((row) => {
    const key = productGroupKey(row);
    grouped.set(key, [...(grouped.get(key) ?? []), row]);
  });

  return Array.from(grouped.values()).map((group) => {
    if (group.length === 1) {
      return group[0];
    }

    const first = group[0];
    const storeCodes = uniqueText(group.map((row) => row.store_code ?? row.store_name));

    return {
      ...first,
      store_id: null,
      store_code: storeCodes.join(" + "),
      store_name: storeCodes.join(" + "),
      stock_actual: sumNumbers(group, (row) => row.stock_actual),
      factor_caja: group.find((row) => Number(row.factor_caja ?? 0) > 0)?.factor_caja ?? first.factor_caja,
      last_inventory_date: latestTextDate(group.map((row) => row.last_inventory_date)),
      batch_id: null,
    };
  });
}

function buildAnalysisWorksheet(
  rows: InventoryMetricItem[],
  context: { targetDays: number; objectiveMonths: number; leadTimeMonths: number }
): XLSX.WorkSheet {
  const monthKeys = uniqueText(rows.flatMap((row) => Object.keys(row.month_columns ?? {})))
    .sort((left, right) => monthKeyValue(left) - monthKeyValue(right));
  const monthHeaders = monthKeys.map(formatMonthKey);
  const headers = [
    "SKU",
    "DESCRIPCION",
    ...monthHeaders,
    "Proyectado",
    "INVEN",
    "MESES DISP",
    "LEAD TIME",
    "DISPO REAL",
    "UNIDADES SUGER",
    "FC",
    "CAJAS",
  ];
  const projectedCol = 2 + monthKeys.length;
  const inventoryCol = projectedCol + 1;
  const mesesDispCol = projectedCol + 2;
  const leadTimeCol = projectedCol + 3;
  const dispoRealCol = projectedCol + 4;
  const unitsCol = projectedCol + 5;
  const fcCol = projectedCol + 6;
  const aoa: any[][] = [
    [
      "DIAS OBJETIVO:",
      Math.max(0, context.targetDays),
      "MESES OBJETIVO:",
      context.objectiveMonths,
      "LEAD TIME:",
      context.leadTimeMonths,
    ],
    headers,
  ];

  rows.forEach((row, index) => {
    const rowIndex = index + 2;
    const projectedCell = XLSX.utils.encode_cell({ r: rowIndex, c: projectedCol });
    const inventoryCell = XLSX.utils.encode_cell({ r: rowIndex, c: inventoryCol });
    const mesesDispCell = XLSX.utils.encode_cell({ r: rowIndex, c: mesesDispCol });
    const leadTimeCell = XLSX.utils.encode_cell({ r: rowIndex, c: leadTimeCol });
    const dispoRealCell = XLSX.utils.encode_cell({ r: rowIndex, c: dispoRealCol });
    const unitsCell = XLSX.utils.encode_cell({ r: rowIndex, c: unitsCol });
    const fcCell = XLSX.utils.encode_cell({ r: rowIndex, c: fcCol });

    aoa.push([
      row.product_code ?? "",
      row.description ?? "",
      ...monthKeys.map((key) => {
        const value = Number(row.month_columns?.[key] ?? 0);
        return value > 0 ? value : null;
      }),
      numberOrZero(row.maximo_mes),
      numberOrZero(row.stock_actual),
      { t: "n", f: `IFERROR(${inventoryCell}/${projectedCell},0)`, z: "0.00" },
      context.leadTimeMonths,
      { t: "n", f: `${mesesDispCell}-${leadTimeCell}`, z: "0.00" },
      {
        t: "n",
        f: `IF(${dispoRealCell}<=${context.objectiveMonths},ROUNDUP(${projectedCell}*(${context.objectiveMonths}-${dispoRealCell}),0),0)`,
        z: "0",
      },
      numberOrZero(row.factor_caja),
      { t: "n", f: `IFERROR(ROUNDUP(${unitsCell}/${fcCell},0),0)`, z: "0" },
    ]);

  });

  const sheet = XLSX.utils.aoa_to_sheet(aoa);
  const lastCol = headers.length - 1;
  const lastRow = aoa.length;
  sheet["!autofilter"] = {
    ref: XLSX.utils.encode_range({ s: { r: 1, c: 0 }, e: { r: Math.max(1, lastRow - 1), c: lastCol } }),
  };
  sheet["!cols"] = [
    { wch: 14 },
    { wch: 44 },
    ...monthHeaders.map(() => ({ wch: 11 })),
    { wch: 12 },
    { wch: 10 },
    { wch: 12 },
    { wch: 11 },
    { wch: 11 },
    { wch: 15 },
    { wch: 8 },
    { wch: 9 },
  ];

  styleAnalysisWorksheet(sheet, headers.length, monthHeaders.length, aoa.length);

  return sheet;
}

function buildInventoryWorksheet(rows: InventoryItem[]): XLSX.WorkSheet {
  const headers = [
    "SKU",
    "DESCRIPCION",
    "MARCA",
    "PROVEEDOR",
    "UBICACION STOCK",
    "INVENTARIO",
    "FC",
    "CAJAS",
    "ULTIMO INVENTARIO",
  ];
  const aoa: any[][] = [headers];

  rows.forEach((row, index) => {
    const rowIndex = index + 1;
    const inventoryCell = XLSX.utils.encode_cell({ r: rowIndex, c: 5 });
    const fcCell = XLSX.utils.encode_cell({ r: rowIndex, c: 6 });

    aoa.push([
      row.product_code ?? "",
      row.description ?? "",
      row.brand ?? "",
      row.supplier ?? row.proveedor ?? "",
      row.store_code ?? row.store_name ?? "",
      numberOrZero(row.stock_actual),
      numberOrZero(row.factor_caja),
      { t: "n", f: `IFERROR(ROUNDUP(${inventoryCell}/${fcCell},0),0)`, z: "0" },
      row.last_inventory_date ?? "",
    ]);
  });

  const sheet = XLSX.utils.aoa_to_sheet(aoa);
  sheet["!autofilter"] = {
    ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(0, aoa.length - 1), c: headers.length - 1 } }),
  };
  sheet["!cols"] = [
    { wch: 14 },
    { wch: 44 },
    { wch: 18 },
    { wch: 24 },
    { wch: 18 },
    { wch: 11 },
    { wch: 8 },
    { wch: 9 },
    { wch: 17 },
  ];
  styleInventoryWorksheet(sheet, headers.length, aoa.length);

  return sheet;
}

function styleInventoryWorksheet(sheet: XLSX.WorkSheet, columnCount: number, rowCount: number) {
  const headerStyle = {
    font: { bold: true, color: { rgb: "FFFFFF" } },
    fill: { fgColor: { rgb: "14532D" } },
    alignment: { horizontal: "center", vertical: "center" },
  };
  const inventoryFill = { fill: { fgColor: { rgb: "D9EAD3" } } };

  for (let c = 0; c < columnCount; c += 1) {
    setCellStyle(sheet, 0, c, headerStyle);
  }

  for (let r = 1; r < rowCount; r += 1) {
    [5, 6, 7].forEach((col) => setCellStyle(sheet, r, col, inventoryFill));
  }
}

function styleAnalysisWorksheet(
  sheet: XLSX.WorkSheet,
  columnCount: number,
  monthCount: number,
  rowCount: number
) {
  const headerStyle = {
    font: { bold: true, color: { rgb: "FFFFFF" } },
    fill: { fgColor: { rgb: "156082" } },
    alignment: { horizontal: "center", vertical: "center" },
  };
  const contextStyle = {
    font: { bold: true, color: { rgb: "0F172A" } },
    fill: { fgColor: { rgb: "E2E8F0" } },
  };
  const inventoryFill = { fill: { fgColor: { rgb: "B7E1A1" } } };
  const planningFill = { fill: { fgColor: { rgb: "F4B6CA" } } };
  const unitsFill = { fill: { fgColor: { rgb: "9FE3A5" } } };
  const casesFill = { fill: { fgColor: { rgb: "B7DDF6" } } };

  for (let c = 0; c < columnCount; c += 1) {
    setCellStyle(sheet, 1, c, headerStyle);
  }

  for (let c = 0; c < 6; c += 1) {
    setCellStyle(sheet, 0, c, contextStyle);
  }

  const projectedCol = 2 + monthCount;
  const inventoryCol = projectedCol + 1;
  const mesesDispCol = projectedCol + 2;
  const leadTimeCol = projectedCol + 3;
  const dispoRealCol = projectedCol + 4;
  const unitsCol = projectedCol + 5;
  const fcCol = projectedCol + 6;
  const casesCol = projectedCol + 7;

  for (let r = 2; r < rowCount; r += 1) {
    [projectedCol, inventoryCol, fcCol].forEach((col) => setCellStyle(sheet, r, col, inventoryFill));
    [mesesDispCol, leadTimeCol, dispoRealCol].forEach((col) => setCellStyle(sheet, r, col, planningFill));
    setCellStyle(sheet, r, unitsCol, unitsFill);
    setCellStyle(sheet, r, casesCol, casesFill);
  }
}

function setCellStyle(sheet: XLSX.WorkSheet, row: number, col: number, style: Record<string, unknown>) {
  const ref = XLSX.utils.encode_cell({ r: row, c: col });
  if (!sheet[ref]) {
    return;
  }

  (sheet[ref] as any).s = {
    ...((sheet[ref] as any).s ?? {}),
    ...style,
  };
}

function rowStoreCodes(row: InventoryMetricItem): string[] {
  return uniqueText([
    ...(row.store_code ?? "").split("+"),
    row.sales_store_code,
  ]).map(normalizeStoreCode);
}

function resolveExportInventoryStoreIds(stores: Store[], selectedStoreIds: number[]): number[] {
  const storesById = new Map(stores.map((store) => [store.id, store]));
  const storesByCode = new Map(stores.map((store) => [normalizeStoreCode(store.code), store]));
  const ids = new Set<number>(selectedStoreIds);

  selectedStoreIds.forEach((id) => {
    const selectedCode = storesById.get(id)?.code;
    if (!selectedCode) {
      return;
    }

    [warehouseStoreCodeFor(selectedCode), salesStoreCodeFor(selectedCode)].forEach((code) => {
      if (!code) {
        return;
      }

      const linkedStore = storesByCode.get(code);
      if (linkedStore) {
        ids.add(linkedStore.id);
      }
    });
  });

  return Array.from(ids);
}

function salesStoreCodeFor(value: string): string | null {
  const code = normalizeStoreCode(value);
  const warehouse = code.match(/^COLB(\d+)$/);

  if (warehouse) {
    return `COLS${warehouse[1]}`;
  }

  if (code === "DEPARTURES") return "COLS1";
  if (code === "ARRIVALS") return "COLS2";

  return code || null;
}

function warehouseStoreCodeFor(value: string): string | null {
  const code = normalizeStoreCode(value);
  const sales = code.match(/^COLS(\d+)$/);

  if (sales) {
    return `COLB${sales[1]}`;
  }

  if (code === "DEPARTURES") return "COLB1";
  if (code === "ARRIVALS") return "COLB2";
  if (isWarehouseStoreCode(code)) return code;

  return null;
}

function isWarehouseStoreCode(value: string): boolean {
  const code = normalizeStoreCode(value);
  return /^COLB\d+$/.test(code) || code === "COLZ1";
}

function normalizeStoreCode(value: string): string {
  return value.toUpperCase().replace(/\s+/g, "").trim();
}

function normalizeLeadTimeMonths(value: number): number {
  const safeValue = Math.max(0, Number(value) || 0);
  return safeValue <= 3 ? safeValue : safeValue / 30;
}

function matchesViewMode(row: InventoryMetricItem, viewMode: ViewMode): boolean {
  if (viewMode === "critical") {
    return row.stock_alert_level === "critico" || row.stock_alert_level === "sin_stock";
  }

  if (viewMode === "attention") {
    return row.stock_alert_level === "alto" || row.stock_alert_level === "medio";
  }

  if (viewMode === "healthy") {
    return row.stock_alert_level === "estable" || row.stock_alert_level === "sin_rotacion";
  }

  return true;
}

function formatMonthKey(monthKey: string): string {
  const { month, year } = parseMonthKey(monthKey);
  return `${String(year).padStart(4, "0")}-${String(month).padStart(2, "0")}`;
}

function monthKeyValue(monthKey: string): number {
  const { month, year } = parseMonthKey(monthKey);
  return year * 100 + month;
}

function parseMonthKey(monthKey: string): { month: number; year: number } {
  const [left, right] = monthKey.includes("-")
    ? monthKey.split("-")
    : monthKey.split(".");
  const first = Number(left) || 1;
  const second = Number(right) || 0;

  if (monthKey.includes("-")) {
    return {
      month: Math.max(1, Math.min(12, second || 1)),
      year: first < 100 ? 2000 + first : first,
    };
  }

  return {
    month: Math.max(1, Math.min(12, first)),
    year: second < 100 ? 2000 + second : second,
  };
}

function uniqueSheetName(name: string, used: Set<string>): string {
  const base = (name || "ANALISIS")
    .replace(/[\\/?*[\]:]/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 31) || "ANALISIS";
  let candidate = base;
  let counter = 2;

  while (used.has(candidate)) {
    const suffix = ` ${counter}`;
    candidate = `${base.slice(0, 31 - suffix.length)}${suffix}`;
    counter += 1;
  }

  used.add(candidate);
  return candidate;
}

function numberOrZero(value: number | null | undefined): number {
  const number = Number(value ?? 0);
  return Number.isFinite(number) ? number : 0;
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

function groupRowsByProduct(rows: InventoryMetricItem[]): InventoryMetricItem[] {
  const grouped = new Map<string, InventoryMetricItem[]>();

  rows.forEach((row) => {
    const key = productGroupKey(row);
    const current = grouped.get(key) ?? [];
    current.push(row);
    grouped.set(key, current);
  });

  return Array.from(grouped.values()).map((group) => {
    const first = group[0];
    const monthColumns = mergeMonthColumns(group.map((row) => row.month_columns));
    const monthValues = Object.values(monthColumns);
    const maximoMes = monthValues.length > 0
      ? Math.max(...monthValues)
      : sumNumbers(group, (row) => row.maximo_mes);
    const maximoMesKey = Object.entries(monthColumns).find(([, value]) => value === maximoMes)?.[0] ?? null;
    const stockActual = sumNumbers(group, (row) => row.stock_actual);
    const rotacionDiariaMes = maximoMes > 0 ? maximoMes / 30 : 0;
    const diasDisponibles = rotacionDiariaMes > 0 ? stockActual / rotacionDiariaMes : 0;
    const alert = resolveStockAlert(diasDisponibles, stockActual, rotacionDiariaMes);
    const storeCodes = uniqueText(group.map((row) => row.store_code ?? row.store_name));
    const salesAudit = buildGroupedSalesAudit(group, monthColumns);

    return {
      ...first,
      store_id: null,
      store_code: storeCodes.length > 0 ? storeCodes.join(" + ") : "Tiendas",
      store_name: storeCodes.length > 0 ? storeCodes.join(" + ") : "Tiendas seleccionadas",
      sales_store_id: null,
      sales_store_code: null,
      sales_store_name: null,
      stock_actual: stockActual,
      total_ventas: sumNumbers(group, (row) => row.total_ventas),
      total_general: sumNumbers(group, (row) => row.total_general),
      maximo_mes: maximoMes,
      maximo_mes_key: maximoMesKey,
      maximo_dia: sumNumbers(group, (row) => row.maximo_dia),
      promedio_diario: sumNumbers(group, (row) => row.promedio_diario),
      rotacion_diaria_mes: rotacionDiariaMes,
      ind_rot_stock: 0,
      ind_rot_promedio: 0,
      month_columns: monthColumns,
      missing_month_store_codes: mergeMissingMonthStoreCodes(
        group.map((row) => row.missing_month_store_codes),
        salesAudit.missing_month_store_codes
      ),
      no_sales_store_codes: uniqueText([
        ...group.flatMap((row) => row.no_sales_store_codes ?? []),
        ...(salesAudit.no_sales_store_codes ?? []),
      ]),
      dias_disponibles: diasDisponibles,
      stock_alert_level: alert.level,
      stock_alert_label: alert.label,
      stock_alert_color: alert.color,
      last_inventory_date: latestTextDate(group.map((row) => row.last_inventory_date)),
      batch_id: null,
    };
  });
}

function productGroupKey(row: InventoryItem): string {
  const code = (row.product_code ?? "").trim();
  if (code) return code;

  return String(row.product_id);
}

function buildGroupedSalesAudit(
  rows: InventoryMetricItem[],
  monthColumns: Record<string, number>
): Pick<InventoryMetricItem, "missing_month_store_codes" | "no_sales_store_codes"> {
  const stores = rows
    .map((row) => ({
      code: shortSalesCode(row.sales_store_code ?? row.store_code ?? ""),
      months: row.month_columns ?? {},
    }))
    .filter((row) => /^S\d+$/.test(row.code));

  if (stores.length < 2) {
    return {
      missing_month_store_codes: {},
      no_sales_store_codes: stores
        .filter((store) => sumObjectValues(store.months) <= 0)
        .map((store) => store.code),
    };
  }

  const missing_month_store_codes: Record<string, string[]> = {};
  Object.keys(monthColumns).forEach((monthKey) => {
    stores.forEach((store) => {
      if (Number(store.months[monthKey] ?? 0) <= 0) {
        missing_month_store_codes[monthKey] = [
          ...(missing_month_store_codes[monthKey] ?? []),
          store.code,
        ];
      }
    });
  });

  return {
    missing_month_store_codes,
    no_sales_store_codes: stores
      .filter((store) => sumObjectValues(store.months) <= 0)
      .map((store) => store.code),
  };
}

function mergeMissingMonthStoreCodes(
  sources: Array<Record<string, string[]> | null | undefined>,
  fallback: Record<string, string[]> | null | undefined
): Record<string, string[]> {
  return [...sources, fallback].reduce<Record<string, string[]>>((acc, source) => {
    Object.entries(source ?? {}).forEach(([monthKey, codes]) => {
      acc[monthKey] = uniqueText([...(acc[monthKey] ?? []), ...(codes ?? [])]);
    });
    return acc;
  }, {});
}

function shortSalesCode(value: string): string {
  const normalized = value.toUpperCase().replace(/\s+/g, "").trim();
  const cols = normalized.match(/^COLS(\d+)$/);
  const colb = normalized.match(/^COLB(\d+)$/);

  if (cols) return `S${cols[1]}`;
  if (colb) return `S${colb[1]}`;

  return normalized;
}

function sumObjectValues(values: Record<string, number>): number {
  return Object.values(values).reduce((total, value) => total + Number(value ?? 0), 0);
}

function mergeMonthColumns(monthGroups: Array<Record<string, number> | null | undefined>): Record<string, number> {
  return monthGroups.reduce<Record<string, number>>((acc, months) => {
    Object.entries(months ?? {}).forEach(([key, value]) => {
      acc[key] = (acc[key] ?? 0) + Number(value ?? 0);
    });
    return acc;
  }, {});
}

function sumNumbers<T extends InventoryItem>(rows: T[], picker: (row: T) => number | null | undefined): number {
  return rows.reduce((total, row) => total + Number(picker(row) ?? 0), 0);
}

function uniqueText(values: Array<string | null | undefined>): string[] {
  return Array.from(new Set(values.map((value) => (value ?? "").trim()).filter(Boolean)));
}

function latestTextDate(values: Array<string | null | undefined>): string | null {
  const dates = values.map((value) => (value ?? "").trim()).filter(Boolean).sort();
  return dates.at(-1) ?? null;
}

function resolveStockAlert(diasDisponibles: number, stockActual: number, rotacionDiariaMes: number) {
  if (stockActual <= 0) return { level: "sin_stock", label: "Sin stock", color: "slate" };
  if (rotacionDiariaMes <= 0) return { level: "sin_rotacion", label: "Sin rotacion", color: "sky" };
  if (diasDisponibles < 7) return { level: "critico", label: "Critico", color: "rose" };
  if (diasDisponibles < 15) return { level: "alto", label: "Alto", color: "amber" };
  if (diasDisponibles < 30) return { level: "medio", label: "Medio", color: "yellow" };
  return { level: "estable", label: "Estable", color: "emerald" };
}

function uniqueValues(
  rows: InventoryMetricItem[],
  picker: (row: InventoryMetricItem) => string | null | undefined
): string[] {
  return Array.from(
    new Set(
      rows
        .map((row) => (picker(row) ?? "").trim())
        .filter((value) => value.length > 0)
    )
  ).sort((a, b) => a.localeCompare(b, "es"));
}

function normalizeForSearch(value: string): string {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 }).format(value);
}
