import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  BarChart3,
  Building2,
  CheckCircle2,
  ChevronRight,
  CloudUpload,
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
  getStores,
  importInventory,
  runInventoryMetrics,
  type InventoryMetricItem,
  type Store,
} from "../services/inventoryService";

type RunResponse = {
  message?: string;
  executed_at?: string;
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
  const [viewMode, setViewMode] = useState<ViewMode>("all");
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
    void loadMetrics();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedStoreIds, asOfDate]);

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
        asOfDate || undefined
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

  const brandOptions = useMemo(() => uniqueValues(data?.rows ?? [], (row) => row.brand), [data]);
  const providerOptions = useMemo(
    () => uniqueValues(data?.rows ?? [], (row) => row.supplier ?? row.proveedor),
    [data]
  );
  const categoryOptions = useMemo(
    () => uniqueValues(data?.rows ?? [], (row) => row.classification_desc),
    [data]
  );

  const enrichedRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    const planningDays = Math.max(0, targetDays) + Math.max(0, leadTimeDays);
    const rows = data?.rows ?? [];

    return rows
      .filter((row) => {
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
      })
      .map((row) => {
        const monthlyRate = Number(row.rotacion_diaria_mes ?? 0);
        const fallbackRate = Number(row.maximo_mes ?? 0) > 0 ? Number(row.maximo_mes ?? 0) / 30 : 0;
        const stockActual = Number(row.stock_actual ?? 0);
        const rotationBase = monthlyRate > 0 ? monthlyRate : fallbackRate;
        const suggestedPurchase =
          rotationBase > 0 ? Math.max(0, Math.ceil(rotationBase * planningDays - stockActual)) : 0;

        return {
          ...row,
          suggested_purchase: suggestedPurchase,
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
  }, [
    data,
    search,
    selectedBrand,
    selectedProvider,
    selectedCategory,
    targetDays,
    leadTimeDays,
    viewMode,
  ]);

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
                  <NumberStepper value={leadTimeDays} onChange={setLeadTimeDays} suffix="días" />
                </FieldCard>
              </div>

              <div className="mt-5 rounded-[1.5rem] border border-amber-200 bg-white px-5 py-4 shadow-sm">
                <div className="text-sm text-slate-500">Cobertura total planeada</div>
                <div className="mt-2 flex flex-wrap items-center gap-3 text-2xl font-semibold text-slate-900">
                  {targetDays + leadTimeDays} días
                  <ChevronRight className="h-5 w-5 text-slate-300" />
                  <span className="text-base font-medium text-slate-500">
                    {Math.round((((targetDays + leadTimeDays) / 30) * 10)) / 10} meses aprox.
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

              <FieldCard label="Fecha de corte">
                <input
                  type="date"
                  value={asOfDate}
                  onChange={(e) => setAsOfDate(e.target.value)}
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                />
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
            title="Tabla principal de cobertura"
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
  return (
    <FieldCard label={label}>
      <div className="relative">
        <div className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
          {icon}
        </div>
        <select
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3.5 pl-11 pr-4 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
        >
          <option value="">Todos</option>
          {options.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>
    </FieldCard>
  );
}

function NumberStepper({
  value,
  onChange,
  suffix,
}: {
  value: number;
  onChange: (value: number) => void;
  suffix: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3.5">
      <div className="flex items-center justify-between gap-3">
        <button
          type="button"
          onClick={() => onChange(Math.max(0, value - 5))}
          className="rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200"
        >
          -5
        </button>
        <div className="text-center">
          <div className="text-2xl font-semibold text-slate-900">{value}</div>
          <div className="text-xs text-slate-500">{suffix}</div>
        </div>
        <button
          type="button"
          onClick={() => onChange(value + 5)}
          className="rounded-xl bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
        >
          +5
        </button>
      </div>
      <input
        type="range"
        min={0}
        max={180}
        step={5}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="mt-4 w-full accent-slate-900"
      />
    </div>
  );
}

function ModeChip({
  label,
  active,
  onClick,
}: {
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded-full px-4 py-2 text-sm font-medium transition ${
        active
          ? "bg-slate-900 text-white shadow-sm"
          : "bg-white text-slate-700 hover:bg-slate-100"
      }`}
    >
      {label}
    </button>
  );
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

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 }).format(value);
}
