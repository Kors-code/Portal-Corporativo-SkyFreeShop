import { useEffect, useMemo, useState } from "react";
import {
  ArrowUpDown,
  BadgeDollarSign,
  Building2,
  CloudUpload,
  Search,
  Store,
  TrendingUp,
  Package,
  RefreshCw,
  FileText,
  BarChart3,
  CheckCircle2,
  XCircle,
} from "lucide-react";

import {
  getStores,
  importInventory,
  getInventory,
  getInventoryMetrics,
  runInventoryMetrics,
  type Store as StoreType,
  type InventoryItem,
  type InventoryMetricItem,
} from "./services/inventoryService";
import InventoryCoverageTable from "./components/InventoryCoverageTable";

const currency = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 2,
});

const number = new Intl.NumberFormat("es-CO", {
  maximumFractionDigits: 2,
});

type DashboardSortKey =
  | keyof InventoryItem
  | keyof InventoryMetricItem;

type DashboardRow = InventoryItem & Partial<InventoryMetricItem>;

const InventoryDashboardPro = () => {
  const [stores, setStores] = useState<StoreType[]>([]);
  const [selectedStoreId, setSelectedStoreId] = useState<number | "">("");
  const [search, setSearch] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [data, setData] = useState<InventoryItem[]>([]);
  const [metricsRows, setMetricsRows] = useState<InventoryMetricItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [metricsLoading, setMetricsLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [recalculating, setRecalculating] = useState(false);
  const [message, setMessage] = useState("");
  const [messageType, setMessageType] = useState<"success" | "error" | "info" | "">("");
  const [sortKey, setSortKey] = useState<DashboardSortKey>("product_code");
  const [sortDirection, setSortDirection] = useState<"asc" | "desc">("asc");

  useEffect(() => {
    void loadInitial();
  }, []);

  useEffect(() => {
    void loadInventory();
    void loadMetrics();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedStoreId]);

  const loadInitial = async () => {
    try {
      setLoading(true);
      const storesData = await getStores();
      setStores(storesData);
    } catch (error) {
      console.error(error);
      setMessage("No se pudieron cargar las tiendas.");
      setMessageType("error");
    } finally {
      setLoading(false);
    }
  };

  const loadInventory = async () => {
    try {
      setLoading(true);
      const inventory = await getInventory(
        selectedStoreId ? Number(selectedStoreId) : undefined,
        search
      );
      setData(inventory);
    } catch (error) {
      console.error(error);
      setMessage("No se pudo cargar el inventario.");
      setMessageType("error");
    } finally {
      setLoading(false);
    }
  };

  const loadMetrics = async () => {
    try {
      setMetricsLoading(true);
      const rows = await getInventoryMetrics(
        selectedStoreId ? Number(selectedStoreId) : undefined
      );
      setMetricsRows(rows);
    } catch (error) {
      console.error(error);
      setMessage("No se pudieron cargar las métricas.");
      setMessageType("error");
    } finally {
      setMetricsLoading(false);
    }
  };

  const handleUpload = async () => {
    if (!file) {
      setMessage("Selecciona un archivo primero.");
      setMessageType("error");
      return;
    }

    if (!selectedStoreId) {
      setMessage("Selecciona una tienda primero.");
      setMessageType("error");
      return;
    }

    try {
      setUploading(true);
      setMessage("");

      const response = await importInventory(file, Number(selectedStoreId));
      setMessage(response?.message ?? "Inventario importado correctamente.");
      setMessageType("success");
      setFile(null);

      await loadInventory();
      await loadMetrics();
    } catch (error) {
      console.error(error);
      setMessage("No se pudo importar el archivo.");
      setMessageType("error");
    } finally {
      setUploading(false);
    }
  };

  const handleRecalculateMetrics = async () => {
    try {
      setRecalculating(true);
      setMessage("");

      const response = await runInventoryMetrics(
        selectedStoreId ? Number(selectedStoreId) : undefined
      );

      setMessage(response?.message ?? "Métricas recalculadas correctamente.");
      setMessageType("success");
      await loadMetrics();
    } catch (error) {
      console.error(error);
      setMessage("No se pudieron recalcular las métricas.");
      setMessageType("error");
    } finally {
      setRecalculating(false);
    }
  };

  const metricsMap = useMemo(() => {
    const map: Record<number, InventoryMetricItem> = {};
    for (const row of metricsRows) {
      map[row.product_id] = row;
    }
    return map;
  }, [metricsRows]);

  const mergedRows: DashboardRow[] = useMemo(() => {
    return data.map((item) => ({
      ...item,
      ...metricsMap[item.product_id],
    }));
  }, [data, metricsMap]);

  const filteredAndSorted = useMemo(() => {
    const q = search.trim().toLowerCase();

    const filtered = mergedRows.filter((item) => {
      if (!q) return true;
      return (
        (item.product_code ?? "").toLowerCase().includes(q) ||
        (item.description ?? "").toLowerCase().includes(q) ||
        (item.brand ?? "").toLowerCase().includes(q) ||
        (item.supplier ?? "").toLowerCase().includes(q) ||
        (item.classification_desc ?? "").toLowerCase().includes(q)
      );
    });

    const sorted = [...filtered].sort((a, b) => {
      const aValue = getSortableValue(a, sortKey);
      const bValue = getSortableValue(b, sortKey);

      const aNormalized =
        typeof aValue === "number"
          ? aValue
          : String(aValue ?? "").toLowerCase();

      const bNormalized =
        typeof bValue === "number"
          ? bValue
          : String(bValue ?? "").toLowerCase();

      if (aNormalized < bNormalized) return sortDirection === "asc" ? -1 : 1;
      if (aNormalized > bNormalized) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });

    return sorted;
  }, [mergedRows, search, sortKey, sortDirection]);

  const metrics = useMemo(() => {
    const items = filteredAndSorted;
    const totalProducts = items.length;
    const totalStock = items.reduce((acc, item) => acc + Number(item.existencia_final ?? 0), 0);
    const totalValue = items.reduce((acc, item) => acc + Number(item.total_inv_final ?? 0), 0);
    const totalSales = items.reduce((acc, item) => acc + Number(item.ventas ?? 0), 0);

    return { totalProducts, totalStock, totalValue, totalSales };
  }, [filteredAndSorted]);

  const toggleSort = (key: DashboardSortKey) => {
    if (sortKey === key) {
      setSortDirection((prev) => (prev === "asc" ? "desc" : "asc"));
      return;
    }
    setSortKey(key);
    setSortDirection("asc");
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      <div className="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8">
        <div className="mb-6 rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-slate-700 p-6 text-white shadow-xl">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
              <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm text-slate-100 backdrop-blur">
                <Package className="h-4 w-4" />
                Inventario por tienda
              </div>
              <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                Panel de inventario
              </h1>
              <p className="mt-2 max-w-2xl text-sm text-slate-300 sm:text-base">
                Consulta, filtra e importa inventario por tienda desde una interfaz clara, rápida y profesional.
              </p>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <MetricCard
                label="Productos"
                value={metrics.totalProducts}
                icon={<FileText className="h-4 w-4" />}
              />
              <MetricCard
                label="Stock"
                value={number.format(metrics.totalStock)}
                icon={<Store className="h-4 w-4" />}
              />
              <MetricCard
                label="Valor"
                value={currency.format(metrics.totalValue)}
                icon={<BadgeDollarSign className="h-4 w-4" />}
              />
              <MetricCard
                label="Ventas"
                value={number.format(metrics.totalSales)}
                icon={<TrendingUp className="h-4 w-4" />}
              />
            </div>
          </div>
        </div>

        <div className="mb-6 grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
          <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold">Carga de archivo</h2>
                <p className="text-sm text-slate-500">
                  Selecciona una tienda y sube el Excel correspondiente.
                </p>
              </div>
              <CloudUpload className="h-5 w-5 text-slate-400" />
            </div>

            <div className="grid gap-3 md:grid-cols-[220px_1fr_auto] md:items-center">
              <div className="relative">
                <Building2 className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <select
                  value={selectedStoreId}
                  onChange={(e) =>
                    setSelectedStoreId(e.target.value ? Number(e.target.value) : "")
                  }
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                >
                  <option value="">Todas las tiendas</option>
                  {stores.map((store) => (
                    <option key={store.id} value={store.id}>
                      {store.name}
                    </option>
                  ))}
                </select>
              </div>

              <label className="flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600 transition hover:border-slate-400 hover:bg-slate-100">
                <input
                  type="file"
                  accept=".xlsx,.xls,.csv"
                  onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                  className="hidden"
                />
                <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white shadow-sm">
                  <FileText className="h-4 w-4 text-slate-500" />
                </span>
                <span className="truncate">
                  {file ? file.name : "Selecciona un archivo Excel o CSV"}
                </span>
              </label>

              <button
                onClick={handleUpload}
                disabled={uploading}
                className="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <CloudUpload className="h-4 w-4" />
                {uploading ? "Subiendo..." : "Importar"}
              </button>
            </div>

            {message && (
              <div
                className={`mt-4 rounded-2xl px-4 py-3 text-sm ${
                  messageType === "success"
                    ? "bg-emerald-50 text-emerald-700"
                    : messageType === "error"
                      ? "bg-rose-50 text-rose-700"
                      : "bg-sky-50 text-sky-700"
                }`}
              >
                <div className="flex items-center gap-2">
                  {messageType === "success" ? (
                    <CheckCircle2 className="h-4 w-4" />
                  ) : messageType === "error" ? (
                    <XCircle className="h-4 w-4" />
                  ) : null}
                  {message}
                </div>
              </div>
            )}
          </div>

          <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold">Métricas cacheadas</h2>
                <p className="text-sm text-slate-500">
                  Se leen desde product_metrics, sin recalcular en pantalla.
                </p>
              </div>
              <div className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5 text-slate-400" />
                <button
                  onClick={handleRecalculateMetrics}
                  disabled={recalculating}
                  className="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <RefreshCw className={`h-4 w-4 ${recalculating ? "animate-spin" : ""}`} />
                  {recalculating ? "Calculando..." : "Recalcular métricas"}
                </button>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <MiniMetric label="Filas métricas" value={metricsRows.length} />
              <MiniMetric
                label="Máximo mes"
                value={number.format(
                  Math.max(0, ...metricsRows.map((m) => Number(m.maximo_mes ?? 0)))
                )}
              />
              <MiniMetric
                label="Stock sugerido"
                value={number.format(
                  metricsRows.reduce((acc, m) => acc + Number(m.sugerido_compra ?? 0), 0)
                )}
              />
              <MiniMetric
                label="Lead time prom."
                value={number.format(
                  metricsRows.length
                    ? metricsRows.reduce((acc, m) => acc + Number(m.lead_time ?? 0), 0) /
                        metricsRows.length
                    : 0
                )}
              />
            </div>

            {metricsLoading ? (
              <div className="mt-4 text-sm text-slate-500">Cargando métricas...</div>
            ) : (
              <div className="mt-4 max-h-72 overflow-auto rounded-2xl border border-slate-200">
                <table className="min-w-[900px] w-full border-collapse text-left text-sm">
                  <thead className="sticky top-0 bg-slate-50 text-slate-600">
                    <tr>
                      <Th>SKU</Th>
                      <Th>Descripción</Th>
                      <Th>Stock</Th>
                      <Th>Max. mes</Th>
                      <Th>Max. día</Th>
                      <Th>Lead time</Th>
                      <Th>Reorder point</Th>
                      <Th>Sugerido compra</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {metricsRows.length === 0 ? (
                      <tr>
                        <td className="px-5 py-8 text-slate-500" colSpan={8}>
                          No hay métricas para mostrar.
                        </td>
                      </tr>
                    ) : (
                      metricsRows.map((item) => (
                        <tr
                          key={item.product_id}
                          className="border-t border-slate-100 hover:bg-slate-50/70"
                        >
                          <Td strong>{item.product_code}</Td>
                          <Td>{item.description}</Td>
                          <Td>{formatNumber(item.stock_actual)}</Td>
                          <Td>{formatNumber(item.maximo_mes)}</Td>
                          <Td>{formatNumber(item.maximo_dia)}</Td>
                          <Td>{formatNumber(item.lead_time)}</Td>
                          <Td>{formatNumber(item.reorder_point)}</Td>
                          <Td>{formatNumber(item.sugerido_compra)}</Td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        <div className="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold">Acciones rápidas</h2>
              <p className="text-sm text-slate-500">Controla la vista del inventario.</p>
            </div>
            <RefreshCw className="h-5 w-5 text-slate-400" />
          </div>

          <div className="space-y-3">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Buscar por SKU, descripción, marca o proveedor"
                className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
              />
            </div>

            <button
              onClick={loadInventory}
              className="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
              <RefreshCw className="h-4 w-4" />
              Recargar inventario
            </button>
          </div>
        </div>

        <div className="mb-6">
          <InventoryCoverageTable
            rows={metricsRows}
            loading={metricsLoading}
            title="Tabla de cobertura"
            subtitle="SKU, stock, consumo maximo y severidad de alerta."
          />
        </div>

        <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
              <h2 className="text-lg font-semibold">Detalle del inventario</h2>
              <p className="text-sm text-slate-500">
                {loading ? "Cargando datos..." : `${filteredAndSorted.length} registros encontrados`}
              </p>
            </div>
            <div className="text-sm text-slate-500">
              Ordenado por <span className="font-medium text-slate-700">{String(sortKey)}</span>
              {" · "}
              <span className="font-medium text-slate-700">
                {sortDirection === "asc" ? "Ascendente" : "Descendente"}
              </span>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-[1700px] w-full border-collapse text-left text-sm">
              <thead className="bg-slate-50 text-slate-600">
                <tr>
                  <Th onClick={() => toggleSort("product_code")}>
                    Código <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("description")}>
                    Descripción <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("classification_desc")}>
                    Clasificación <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("existencia_final")}>
                    Stock <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("factor_caja")}>
                    F/C <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("cost_unitario")}>
                    Costo unitario <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("total_inv_final")}>
                    Total inv. final <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("cost_unitario_usd")}>
                    Costo USD <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("valor_final_usd")}>
                    Valor USD <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("cogs")}>
                    COGS <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("maximo_mes")}>
                    Máximo mes <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("maximo_dia")}>
                    Máximo día <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("ind_rot_stock")}>
                    Rot. stock <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("ind_rot_promedio")}>
                    Rot. prom. <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th>Días en existencia</Th>
                  <Th>Últ. venta</Th>
                  <Th>Días sin ventas</Th>
                  <Th onClick={() => toggleSort("supplier")}>
                    Proveedor <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("brand")}>
                    Marca <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th onClick={() => toggleSort("retail")}>
                    Retail <ArrowUpDown className="ml-1 inline h-3.5 w-3.5" />
                  </Th>
                  <Th>% Costo</Th>
                  <Th>% Margen</Th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td className="px-5 py-8 text-slate-500" colSpan={23}>
                      Cargando inventario...
                    </td>
                  </tr>
                ) : filteredAndSorted.length === 0 ? (
                  <tr>
                    <td className="px-5 py-8 text-slate-500" colSpan={23}>
                      No hay resultados para mostrar.
                    </td>
                  </tr>
                ) : (
                  filteredAndSorted.map((item) => (
                    <tr
                      key={item.product_id}
                      className="border-t border-slate-100 transition hover:bg-slate-50/70"
                    >
                      <Td strong>{item.product_code}</Td>
                      <Td>{item.description}</Td>
                      <Td>{item.classification_desc ?? "-"}</Td>
                      <Td>{formatNumber(item.existencia_final)}</Td>
                      <Td>{formatNumber(item.factor_caja)}</Td>
                      <Td>{formatMoney(item.cost_unitario)}</Td>
                      <Td>{formatMoney(item.total_inv_final)}</Td>
                      <Td>{formatMoney(item.cost_unitario_usd)}</Td>
                      <Td>{formatMoney(item.valor_final_usd)}</Td>
                      <Td>{formatMoney(item.cogs)}</Td>
                      <Td>{formatNumber(item.maximo_mes)}</Td>
                      <Td>{formatNumber(item.maximo_dia)}</Td>
                      <Td>{formatNumber(item.ind_rot_stock)}</Td>
                      <Td>{formatNumber(item.ind_rot_promedio)}</Td>
                      <Td>{formatNumber(item.dias_en_existencia)}</Td>
                      <Td>{item.last_sale_date ?? "-"}</Td>
                      <Td>{formatNumber(item.without_sales_days)}</Td>
                      <Td>{item.supplier ?? "-"}</Td>
                      <Td>{item.brand ?? "-"}</Td>
                      <Td>{formatMoney(item.retail)}</Td>
                      <Td>{formatNumber(item.pct_costo)}%</Td>
                      <Td>{formatNumber(item.pct_margen)}%</Td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
};

function getSortableValue(row: DashboardRow, key: DashboardSortKey) {
  return row[key as keyof DashboardRow];
}

function MetricCard({
  label,
  value,
  icon,
}: {
  label: string;
  value: string | number;
  icon: React.ReactNode;
}) {
  return (
    <div className="rounded-2xl border border-white/10 bg-white/10 p-3 shadow-sm backdrop-blur">
      <div className="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-xl bg-white/10 text-white">
        {icon}
      </div>
      <div className="text-xs uppercase tracking-wide text-slate-300">{label}</div>
      <div className="mt-1 text-lg font-semibold text-white">{value}</div>
    </div>
  );
}

function MiniMetric({
  label,
  value,
}: {
  label: string;
  value: string | number;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-xl font-semibold text-slate-900">{value}</div>
    </div>
  );
}

function Th({
  children,
  onClick,
}: {
  children: React.ReactNode;
  onClick?: () => void;
}) {
  return (
    <th
      onClick={onClick}
      className={`whitespace-nowrap px-4 py-4 ${
        onClick ? "cursor-pointer select-none hover:text-slate-900" : ""
      }`}
    >
      <span className="inline-flex items-center">{children}</span>
    </th>
  );
}

function Td({
  children,
  strong,
}: {
  children: React.ReactNode;
  strong?: boolean;
}) {
  return (
    <td
      className={`whitespace-nowrap px-4 py-4 ${
        strong ? "font-medium text-slate-900" : "text-slate-600"
      }`}
    >
      {children}
    </td>
  );
}

const formatNumber = (value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return number.format(Number(value));
};

const formatMoney = (value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return currency.format(Number(value));
};

export default InventoryDashboardPro;
