import { useEffect, useMemo, useState } from "react";
import {
  Play,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Clock3,
  BarChart3,
  Search,
  Building2,
} from "lucide-react";

import {
  getStores,
  getInventoryMetrics,
  runInventoryMetrics,
  type Store,
  type InventoryMetricItem,
} from "./services/inventoryService";
import InventoryCoverageTable from "./components/InventoryCoverageTable";

type RunResponse = {
  message?: string;
  executed_at?: string;
  processed_products?: number;
  rows?: InventoryMetricItem[];
};

export default function InventoryMetricsRunner() {
  const [stores, setStores] = useState<Store[]>([]);
  const [selectedStoreId, setSelectedStoreId] = useState<number | "">("");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingStores, setLoadingStores] = useState(false);
  const [message, setMessage] = useState<string>("");
  const [error, setError] = useState<string>("");
  const [data, setData] = useState<RunResponse | null>(null);

  useEffect(() => {
    void loadStores();
  }, []);

  useEffect(() => {
    void loadMetrics();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedStoreId]);

  const loadStores = async () => {
    try {
      setLoadingStores(true);
      const res = await getStores();
      setStores(res);
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
        selectedStoreId ? Number(selectedStoreId) : undefined,
        search
      );

      setData({
        message: "Datos cargados correctamente.",
        executed_at: new Date().toISOString(),
        processed_products: rows.length,
        rows,
      });
    } catch (err: any) {
      console.error(err);
      setError(err?.response?.data?.message || err?.message || "No se pudo cargar el cálculo.");
    } finally {
      setLoading(false);
    }
  };

  const handleRun = async () => {
    setLoading(true);
    setError("");
    setMessage("");

    try {
      const response = await runInventoryMetrics(
        selectedStoreId ? Number(selectedStoreId) : undefined,
        search
      );
      setData(response);
      setMessage(response?.message || "Cálculo ejecutado correctamente.");
    } catch (err: any) {
      console.error(err);
      setError(err?.response?.data?.message || err?.message || "No se pudo ejecutar el cálculo.");
    } finally {
      setLoading(false);
    }
  };

  const monthKeys = useMemo(() => {
    const rows = data?.rows ?? [];
    const set = new Set<string>();

    rows.forEach((row) => {
      Object.keys(row.month_columns ?? {}).forEach((k) => set.add(k));
    });

    return Array.from(set).sort((a, b) => monthSort(a, b));
  }, [data]);

  const summary = useMemo(() => {
    const rows = data?.rows ?? [];
    return {
      rowsCount: rows.length,
      maxSales: rows.reduce((acc, r) => Math.max(acc, Number(r.maximo_mes ?? 0)), 0),
      totalSales: rows.reduce((acc, r) => acc + Number(r.total_general ?? r.total_ventas ?? 0), 0),
      avgRotStock: rows.length
        ? rows.reduce((acc, r) => acc + Number(r.ind_rot_stock ?? 0), 0) / rows.length
        : 0,
    };
  }, [data]);

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    const rows = data?.rows ?? [];

    if (!q) return rows;

    return rows.filter((row) => {
      return (
        (row.product_code ?? "").toLowerCase().includes(q) ||
        (row.description ?? "").toLowerCase().includes(q) ||
        (row.brand ?? "").toLowerCase().includes(q) ||
        (row.supplier ?? "").toLowerCase().includes(q) ||
        (row.store_name ?? "").toLowerCase().includes(q) ||
        (row.store_code ?? "").toLowerCase().includes(q)
      );
    });
  }, [data, search]);

  return (
    <div className="min-h-screen bg-slate-50 p-4 text-slate-900 sm:p-6 lg:p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-6 rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-slate-700 p-6 text-white shadow-xl">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
              <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm text-slate-100 backdrop-blur">
                <BarChart3 className="h-4 w-4" />
                Métricas de inventario
              </div>
              <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                Ejecutar cálculo y ver resultados
              </h1>
              <p className="mt-2 max-w-2xl text-sm text-slate-300 sm:text-base">
                Filtra por tienda y revisa el comportamiento mensual por producto.
              </p>
            </div>

            <button
              onClick={handleRun}
              disabled={loading}
              className="inline-flex items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? (
                <>
                  <RefreshCw className="h-4 w-4 animate-spin" />
                  Calculando...
                </>
              ) : (
                <>
                  <Play className="h-4 w-4" />
                  Ejecutar cálculo
                </>
              )}
            </button>
          </div>
        </div>

        <div className="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <StatCard label="Registros procesados" value={data?.processed_products ?? summary.rowsCount} icon={<Clock3 className="h-4 w-4" />} />
          <StatCard label="Ventas totales" value={formatNumber(summary.totalSales)} icon={<BarChart3 className="h-4 w-4" />} />
          <StatCard label="Máximo mes" value={formatNumber(summary.maxSales)} icon={<BarChart3 className="h-4 w-4" />} />
          <StatCard label="Rotación promedio" value={formatNumber(summary.avgRotStock)} icon={<BarChart3 className="h-4 w-4" />} />
        </div>

        <div className="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="mb-4 grid gap-3 md:grid-cols-[220px_1fr_auto] md:items-end">
            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">
                Tienda
              </label>
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
                      {store.code} - {store.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">
                Buscar
              </label>
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="SKU, descripción, marca, proveedor..."
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                />
              </div>
            </div>

            <button
              onClick={loadMetrics}
              disabled={loading || loadingStores}
              className="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <RefreshCw className="h-4 w-4" />
              Recargar
            </button>
          </div>

          {message && (
            <div className="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-700">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-4 w-4" />
                {message}
              </div>
              {data?.executed_at && (
                <div className="mt-1 text-xs text-emerald-600">
                  Ejecutado: {data.executed_at}
                </div>
              )}
            </div>
          )}

          {error && (
            <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700">
              <div className="flex items-center gap-2">
                <XCircle className="h-4 w-4" />
                {error}
              </div>
            </div>
          )}

          <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
              <div>
                <h2 className="text-lg font-semibold">Resultado del cálculo</h2>
                <p className="text-sm text-slate-500">
                  Máximo mensual por producto y por tienda.
                </p>
              </div>
              <div className="text-sm text-slate-500">
                {loading ? "Procesando..." : `${filteredRows.length} filas visibles`}
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-[1800px] w-full border-collapse text-left text-sm">
                <thead className="bg-slate-50 text-slate-600">
                  <tr>
                    <Th>SKU</Th>
                    {monthKeys.map((m) => (
                      <Th key={m}>{m}</Th>
                    ))}
                    <Th>Total general</Th>
                    <Th>MAXIMO MES</Th>
                    <Th>MAXIMO DIA</Th>
                    <Th>IND rot stock</Th>
                    <Th>IND ROT promedio</Th>
                    <Th>DESCRIPCION</Th>
                  </tr>
                </thead>
                <tbody>
                  {filteredRows.length === 0 ? (
                    <tr>
                      <td
                        colSpan={monthKeys.length + 6}
                        className="px-5 py-10 text-center text-slate-500"
                      >
                        Ejecuta el cálculo para ver resultados aquí.
                      </td>
                    </tr>
                  ) : (
                    filteredRows.map((row) => (
                      <tr key={`${row.store_id}-${row.product_id}`} className="border-t border-slate-100 hover:bg-slate-50/70">
                        <Td strong>{row.product_code}</Td>
                        {monthKeys.map((m) => (
                          <Td key={m}>{formatNumber(row.month_columns?.[m])}</Td>
                        ))}
                        <Td>{formatNumber(row.total_general)}</Td>
                        <Td>{formatNumber(row.maximo_mes)}</Td>
                        <Td>{formatNumber(row.maximo_dia)}</Td>
                        <Td>{formatNumber(row.ind_rot_stock)}</Td>
                        <Td>{formatNumber(row.ind_rot_promedio)}</Td>
                        <Td>{row.description ?? "-"}</Td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <InventoryCoverageTable
          rows={filteredRows}
          loading={loading}
          title="Cobertura y alerta de inventario"
          subtitle="Lectura operativa basada en stock actual y maximo diario."
        />
      </div>
    </div>
  );
}

function monthSort(a: string, b: string) {
  const [am, ay] = a.split(".").map(Number);
  const [bm, by] = b.split(".").map(Number);

  const aYear = 2000 + (ay || 0);
  const bYear = 2000 + (by || 0);

  const da = new Date(aYear, (am || 1) - 1, 1).getTime();
  const db = new Date(bYear, (bm || 1) - 1, 1).getTime();

  return da - db;
}

function StatCard({
  label,
  value,
  icon,
}: {
  label: string;
  value: string | number;
  icon: React.ReactNode;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
        {icon}
      </div>
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-2xl font-semibold text-slate-900">{value}</div>
    </div>
  );
}

function Th({ children }: { children: React.ReactNode }) {
  return <th className="whitespace-nowrap px-4 py-4 font-medium">{children}</th>;
}

function Td({ children, strong }: { children: React.ReactNode; strong?: boolean }) {
  return (
    <td className={`whitespace-nowrap px-4 py-4 ${strong ? "font-semibold text-slate-900" : "text-slate-600"}`}>
      {children}
    </td>
  );
}

const formatNumber = (value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 2 }).format(Number(value));
};
