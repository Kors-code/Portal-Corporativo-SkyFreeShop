import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import {
  AlertTriangle,
  Boxes,
  Building2,
  CheckCircle2,
  Filter,
  PackageSearch,
  RefreshCw,
  Search,
  ShieldAlert,
  Tags,
  Warehouse,
} from "lucide-react";
import {
  type InventoryMetricItem,
  type Store,
} from "../../inventory/services/inventoryService";
import { getInventoryMonitoring, getInventoryMonitoringStores } from "../services/visualizacionesService";

type SortKey = "risk" | "days" | "stock" | "description";

const numberFmt = new Intl.NumberFormat("es-CO", {
  maximumFractionDigits: 0,
});

const decimalFmt = new Intl.NumberFormat("es-CO", {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
});

const statusOrder: Record<string, number> = {
  sin_stock: 0,
  critico: 1,
  alto: 2,
  medio: 3,
  estable: 4,
  sin_rotacion: 5,
};

const statusLabel: Record<string, string> = {
  sin_stock: "Sin stock",
  critico: "Critico",
  alto: "Alto",
  medio: "Medio",
  estable: "Estable",
  sin_rotacion: "Sin rotacion",
};

const statusClasses: Record<string, string> = {
  sin_stock: "border-slate-300 bg-slate-100 text-slate-700",
  critico: "border-rose-200 bg-rose-50 text-rose-700",
  alto: "border-amber-200 bg-amber-50 text-amber-700",
  medio: "border-yellow-200 bg-yellow-50 text-yellow-700",
  estable: "border-emerald-200 bg-emerald-50 text-emerald-700",
  sin_rotacion: "border-cyan-200 bg-cyan-50 text-cyan-700",
};

const barClasses = ["bg-rose-600", "bg-amber-500", "bg-yellow-500", "bg-emerald-600", "bg-cyan-600", "bg-slate-600"];

export default function InventoryMonitoringDashboard() {
  const [rows, setRows] = useState<InventoryMetricItem[]>([]);
  const [stores, setStores] = useState<Store[]>([]);
  const [selectedStoreIds, setSelectedStoreIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingStores, setLoadingStores] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("ALL");
  const [provider, setProvider] = useState("ALL");
  const [brand, setBrand] = useState("ALL");
  const [status, setStatus] = useState("ALL");
  const [sortBy, setSortBy] = useState<SortKey>("risk");

  const load = async (storeIds = selectedStoreIds) => {
    try {
      setLoading(true);
      setError("");
      const result = await getInventoryMonitoring({
        max_months: 6,
        store_ids: storeIds.length > 0 ? storeIds : undefined,
      });
      setRows(Array.isArray(result.rows) ? result.rows : []);
    } catch (err) {
      console.error(err);
      setError("No se pudo cargar el monitoreo de inventario.");
    } finally {
      setLoading(false);
    }
  };

  const loadStores = async () => {
    try {
      setLoadingStores(true);
      const result = await getInventoryMonitoringStores();
      setStores(Array.isArray(result) ? result : []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoadingStores(false);
    }
  };

  useEffect(() => {
    void loadStores();
  }, []);

  useEffect(() => {
    void load(selectedStoreIds);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedStoreIds]);

  const categories = useMemo(() => uniqueValues(rows, (row) => row.classification_desc), [rows]);
  const providers = useMemo(() => uniqueValues(rows, (row) => row.proveedor ?? row.supplier), [rows]);
  const brands = useMemo(() => uniqueValues(rows, (row) => row.brand), [rows]);
  const statuses = useMemo(
    () => uniqueValues(rows, (row) => normalizeStatus(row.stock_alert_level)),
    [rows]
  );

  const filteredRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    const list = rows.filter((row) => {
      if (category !== "ALL" && normalizeText(row.classification_desc) !== category) return false;
      if (provider !== "ALL" && normalizeText(row.proveedor ?? row.supplier) !== provider) return false;
      if (brand !== "ALL" && normalizeText(row.brand) !== brand) return false;
      if (status !== "ALL" && normalizeStatus(row.stock_alert_level) !== status) return false;
      if (!term) return true;

      return [
        row.description,
        row.product_code,
        row.sku_mia,
        row.classification_desc,
        row.proveedor,
        row.supplier,
        row.brand,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase()
        .includes(term);
    });

    return list.sort((left, right) => {
      if (sortBy === "days") return numberOrZero(left.dias_disponibles) - numberOrZero(right.dias_disponibles);
      if (sortBy === "stock") return numberOrZero(right.stock_actual) - numberOrZero(left.stock_actual);
      if (sortBy === "description") return String(left.description ?? "").localeCompare(String(right.description ?? ""));
      return statusRank(left) - statusRank(right);
    });
  }, [rows, search, category, provider, brand, status, sortBy]);

  const visibleRows = filteredRows.slice(0, 250);
  const totalStock = filteredRows.reduce((sum, row) => sum + numberOrZero(row.stock_actual), 0);
  const criticalCount = filteredRows.filter((row) => ["sin_stock", "critico"].includes(normalizeStatus(row.stock_alert_level))).length;
  const stableCount = filteredRows.filter((row) => normalizeStatus(row.stock_alert_level) === "estable").length;
  const avgDays = filteredRows.length
    ? filteredRows.reduce((sum, row) => sum + numberOrZero(row.dias_disponibles), 0) / filteredRows.length
    : 0;

  const statusChart = useMemo(() => {
    const map = new Map<string, number>();
    filteredRows.forEach((row) => {
      const key = normalizeStatus(row.stock_alert_level);
      map.set(key, (map.get(key) ?? 0) + 1);
    });
    return Array.from(map.entries())
      .map(([key, count]) => ({ key, label: statusLabel[key] ?? key, count }))
      .sort((a, b) => (statusOrder[a.key] ?? 99) - (statusOrder[b.key] ?? 99));
  }, [filteredRows]);

  const providerChart = useMemo(() => {
    const map = new Map<string, number>();
    filteredRows.forEach((row) => {
      const key = String(row.proveedor ?? row.supplier ?? "Sin proveedor");
      map.set(key, (map.get(key) ?? 0) + numberOrZero(row.stock_actual));
    });
    return Array.from(map.entries())
      .map(([label, stock]) => ({ label, stock }))
      .sort((a, b) => b.stock - a.stock)
      .slice(0, 6);
  }, [filteredRows]);

  const maxStatusCount = Math.max(1, ...statusChart.map((item) => item.count));
  const maxProviderStock = Math.max(1, ...providerChart.map((item) => item.stock));
  const selectedStoreNames = selectedStoreIds.length
    ? stores
        .filter((store) => selectedStoreIds.includes(Number(store.id)))
        .map((store) => store.code || store.name)
        .join(", ")
    : "Todas las tiendas";

  const toggleStore = (storeId: number) => {
    setSelectedStoreIds((current) =>
      current.includes(storeId)
        ? current.filter((id) => id !== storeId)
        : [...current, storeId]
    );
  };

  return (
    <div className="space-y-5 text-slate-950">
      <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="grid gap-0 lg:grid-cols-[1fr_360px]">
          <div className="p-5 sm:p-6">
            <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizaciones</p>
            <h1 className="mt-2 text-3xl font-black tracking-tight text-slate-950">
              Monitoreo de inventario
            </h1>
            <p className="mt-3 max-w-3xl text-sm font-medium leading-6 text-slate-600">
              Control ejecutivo de stock, dias disponibles y estado por producto para detectar riesgo operativo rapido.
            </p>
            <div className="mt-4 inline-flex max-w-full items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-600">
              <Building2 size={15} className="text-primary" />
              <span className="truncate">{selectedStoreNames}</span>
            </div>
          </div>

          <div className="border-t border-slate-200 bg-slate-950 p-5 text-white lg:border-l lg:border-t-0 sm:p-6">
            <div className="flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/10">
                <Warehouse size={24} />
              </div>
              <div>
                <div className="text-xs font-bold uppercase tracking-wide text-slate-400">Inventario activo</div>
                <div className="mt-1 text-2xl font-black">{numberFmt.format(filteredRows.length)}</div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => void load()}
              disabled={loading}
              className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white transition hover:brightness-95 disabled:opacity-50"
            >
              <RefreshCw size={16} className={loading ? "animate-spin" : ""} />
              Actualizar monitoreo
            </button>
          </div>
        </div>
      </section>

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
          {error}
        </div>
      )}

      <section className="grid gap-3 md:grid-cols-4">
        <MetricCard icon={<Boxes size={18} />} label="Stock total" value={numberFmt.format(totalStock)} detail="Unidades en vista" />
        <MetricCard icon={<ShieldAlert size={18} />} label="Riesgo critico" value={numberFmt.format(criticalCount)} detail="Sin stock o critico" danger />
        <MetricCard icon={<CheckCircle2 size={18} />} label="Estables" value={numberFmt.format(stableCount)} detail="Productos saludables" />
        <MetricCard icon={<AlertTriangle size={18} />} label="Dias promedio" value={decimalFmt.format(avgDays)} detail="Cobertura estimada" />
      </section>

      <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-black text-slate-950">Filtros de monitoreo</h2>
              <p className="text-sm font-semibold text-slate-500">Tienda, categoria, producto, SKU, proveedor y marca.</p>
            </div>
            <span className="inline-flex items-center gap-2 rounded-lg bg-primary/10 px-3 py-2 text-sm font-black text-primary">
              <Filter size={16} />
              {numberFmt.format(filteredRows.length)} resultados
            </span>
          </div>

          <div className="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                <Building2 size={15} className="text-primary" />
                Tiendas
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setSelectedStoreIds(stores.map((store) => Number(store.id)))}
                  disabled={loadingStores || stores.length === 0}
                  className="rounded-md bg-white px-2.5 py-1.5 text-xs font-black text-slate-700 shadow-sm ring-1 ring-slate-200 disabled:opacity-50"
                >
                  Todas
                </button>
                <button
                  type="button"
                  onClick={() => setSelectedStoreIds([])}
                  disabled={loadingStores}
                  className="rounded-md bg-white px-2.5 py-1.5 text-xs font-black text-slate-700 shadow-sm ring-1 ring-slate-200 disabled:opacity-50"
                >
                  Limpiar
                </button>
              </div>
            </div>

            {loadingStores ? (
              <div className="text-sm font-semibold text-slate-500">Cargando tiendas...</div>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {stores.map((store) => {
                  const checked = selectedStoreIds.includes(Number(store.id));
                  return (
                    <label
                      key={store.id}
                      className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-bold transition ${
                        checked
                          ? "border-primary bg-primary/10 text-primary"
                          : "border-slate-200 bg-white text-slate-700 hover:border-primary/30"
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => toggleStore(Number(store.id))}
                        className="h-4 w-4 accent-[#9C0E0E]"
                      />
                      <span className="min-w-0 truncate">{store.code || store.name}</span>
                    </label>
                  );
                })}
              </div>
            )}
          </div>

          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <label className="xl:col-span-2">
              <span className="mb-1 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                <Search size={14} />
                Producto o SKU
              </span>
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Buscar descripcion, SKU o codigo"
                className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
              />
            </label>

            <SelectField label="Categoria" value={category} onChange={setCategory} options={categories} />
            <SelectField label="Proveedor" value={provider} onChange={setProvider} options={providers} />
            <SelectField label="Marca" value={brand} onChange={setBrand} options={brands} />
            <SelectField
              label="Estado"
              value={status}
              onChange={setStatus}
              options={statuses}
              formatOption={(value) => statusLabel[value] ?? value}
            />

            <label>
              <span className="mb-1 block text-xs font-black uppercase tracking-wide text-slate-500">Orden</span>
              <select
                value={sortBy}
                onChange={(event) => setSortBy(event.target.value as SortKey)}
                className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
              >
                <option value="risk">Riesgo primero</option>
                <option value="days">Menos dias disponibles</option>
                <option value="stock">Mayor stock</option>
                <option value="description">Descripcion A-Z</option>
              </select>
            </label>

            <button
              type="button"
              onClick={() => {
                setSearch("");
                setCategory("ALL");
                setProvider("ALL");
                setBrand("ALL");
                setStatus("ALL");
                setSortBy("risk");
              }}
              className="h-11 self-end rounded-lg border border-slate-300 bg-slate-50 px-3 text-sm font-black text-slate-700 transition hover:bg-slate-100"
            >
              Limpiar
            </button>
          </div>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-2 text-sm font-black text-slate-700">
            <PackageSearch size={17} className="text-primary" />
            Estado del inventario
          </div>
          <div className="space-y-3">
            {statusChart.map((item, index) => (
              <MiniBar
                key={item.key}
                label={item.label}
                value={numberFmt.format(item.count)}
                pct={(item.count / maxStatusCount) * 100}
                color={barClasses[index % barClasses.length]}
              />
            ))}
          </div>
        </div>
      </section>

      <section className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-2 text-sm font-black text-slate-700">
            <Tags size={17} className="text-primary" />
            Stock por proveedor
          </div>
          <div className="space-y-3">
            {providerChart.length === 0 ? (
              <div className="rounded-lg bg-slate-50 p-4 text-sm font-semibold text-slate-500">Sin datos</div>
            ) : (
              providerChart.map((item, index) => (
                <MiniBar
                  key={item.label}
                  label={item.label}
                  value={numberFmt.format(item.stock)}
                  pct={(item.stock / maxProviderStock) * 100}
                  color={barClasses[(index + 2) % barClasses.length]}
                />
              ))
            )}
          </div>
        </div>

        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-100 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-black text-slate-950">Tabla de monitoreo</h2>
                <p className="text-sm font-semibold text-slate-500">
                  {visibleRows.length < filteredRows.length
                    ? `Mostrando ${visibleRows.length} de ${filteredRows.length}. Usa filtros para afinar.`
                    : `${filteredRows.length} productos visibles.`}
                </p>
              </div>
              <span className="rounded-lg bg-slate-950 px-3 py-2 text-sm font-black text-white">
                Corte actual
              </span>
            </div>
          </div>

          <div className="lg:hidden">
            {loading ? (
              <div className="px-4 py-10 text-center text-sm font-bold text-slate-500">Cargando inventario...</div>
            ) : visibleRows.length === 0 ? (
              <div className="px-4 py-10 text-center text-sm font-bold text-slate-500">No hay productos con esos filtros.</div>
            ) : (
              <div className="divide-y divide-slate-100">
                {visibleRows.map((row, index) => {
                  const level = normalizeStatus(row.stock_alert_level);
                  return (
                    <div key={`${row.product_id}-${row.store_id ?? "all"}-${index}-card`} className="p-4">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="font-black text-slate-950">{row.description ?? "Sin descripcion"}</div>
                          <div className="mt-1 text-xs font-bold text-slate-500">
                            SKU {row.sku_mia ?? row.product_code ?? "-"} · {row.store_code ?? "Tienda"}
                          </div>
                        </div>
                        <span className={`shrink-0 rounded-full border px-2.5 py-1 text-xs font-black ${statusClasses[level] ?? statusClasses.estable}`}>
                          {row.stock_alert_label ?? statusLabel[level] ?? "Sin estado"}
                        </span>
                      </div>
                      <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <InfoPill label="Proveedor" value={row.proveedor ?? row.supplier ?? "-"} />
                        <InfoPill label="Marca" value={row.brand ?? "-"} />
                        <InfoPill label="Stock" value={numberFmt.format(numberOrZero(row.stock_actual))} />
                        <InfoPill label="Dias" value={decimalFmt.format(numberOrZero(row.dias_disponibles))} />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <div className="hidden lg:block">
            <table className="w-full table-fixed border-collapse">
              <colgroup>
                <col className="w-[31%]" />
                <col className="w-[10%]" />
                <col className="w-[17%]" />
                <col className="w-[14%]" />
                <col className="w-[8%]" />
                <col className="w-[10%]" />
                <col className="w-[10%]" />
              </colgroup>
              <thead className="bg-slate-950 text-white">
                <tr className="text-left text-xs font-black uppercase tracking-wide">
                  <th className="px-3 py-3">Descripcion</th>
                  <th className="px-3 py-3">Tienda</th>
                  <th className="px-3 py-3">Proveedor</th>
                  <th className="px-3 py-3">Marca</th>
                  <th className="px-3 py-3 text-right">Stock</th>
                  <th className="px-3 py-3 text-right">Dias</th>
                  <th className="px-3 py-3 text-right">Estado</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-10 text-center text-sm font-bold text-slate-500">
                      Cargando inventario...
                    </td>
                  </tr>
                ) : visibleRows.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-10 text-center text-sm font-bold text-slate-500">
                      No hay productos con esos filtros.
                    </td>
                  </tr>
                ) : (
                  visibleRows.map((row, index) => {
                    const level = normalizeStatus(row.stock_alert_level);
                    return (
                      <tr key={`${row.product_id}-${row.store_id ?? "all"}-${index}`} className="border-t border-slate-100 hover:bg-slate-50">
                        <td className="px-3 py-3 align-top">
                          <div className="break-words text-sm font-black leading-5 text-slate-950">{row.description ?? "Sin descripcion"}</div>
                          <div className="mt-1 flex flex-wrap gap-2 text-xs font-bold text-slate-500">
                            <span>SKU {row.sku_mia ?? row.product_code ?? "-"}</span>
                            <span>{row.classification_desc ?? "Sin categoria"}</span>
                          </div>
                        </td>
                        <td className="break-words px-3 py-3 align-top text-sm font-black text-slate-700">
                          {row.store_code ?? row.store_name ?? "-"}
                        </td>
                        <td className="break-words px-3 py-3 align-top text-sm font-semibold text-slate-700">
                          {row.proveedor ?? row.supplier ?? "-"}
                        </td>
                        <td className="break-words px-3 py-3 align-top text-sm font-semibold text-slate-700">{row.brand ?? "-"}</td>
                        <td className="px-3 py-3 text-right align-top text-sm font-black text-slate-950">
                          {numberFmt.format(numberOrZero(row.stock_actual))}
                        </td>
                        <td className="px-3 py-3 text-right align-top text-sm font-black text-slate-950">
                          {decimalFmt.format(numberOrZero(row.dias_disponibles))}
                        </td>
                        <td className="px-3 py-3 text-right align-top">
                          <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-black ${statusClasses[level] ?? statusClasses.estable}`}>
                            {row.stock_alert_label ?? statusLabel[level] ?? "Sin estado"}
                          </span>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  );
}

function MetricCard({
  icon,
  label,
  value,
  detail,
  danger = false,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  detail: string;
  danger?: boolean;
}) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className={`mb-3 flex h-10 w-10 items-center justify-center rounded-lg ${danger ? "bg-rose-50 text-rose-700" : "bg-primary/10 text-primary"}`}>
        {icon}
      </div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-2xl font-black text-slate-950">{value}</div>
      <div className="mt-1 text-sm font-semibold text-slate-500">{detail}</div>
    </div>
  );
}

function InfoPill({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-slate-50 px-3 py-2">
      <div className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-1 break-words text-sm font-black text-slate-800">{value}</div>
    </div>
  );
}

function SelectField({
  label,
  value,
  onChange,
  options,
  formatOption,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: string[];
  formatOption?: (value: string) => string;
}) {
  return (
    <label>
      <span className="mb-1 block text-xs font-black uppercase tracking-wide text-slate-500">{label}</span>
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900 outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
      >
        <option value="ALL">Todos</option>
        {options.map((option) => (
          <option key={option} value={option}>
            {formatOption ? formatOption(option) : option}
          </option>
        ))}
      </select>
    </label>
  );
}

function MiniBar({
  label,
  value,
  pct,
  color,
}: {
  label: string;
  value: string;
  pct: number;
  color: string;
}) {
  return (
    <div>
      <div className="mb-1 flex items-center justify-between gap-3 text-xs font-black text-slate-600">
        <span className="truncate">{label}</span>
        <span>{value}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.max(6, Math.min(100, pct))}%` }} />
      </div>
    </div>
  );
}

function uniqueValues(rows: InventoryMetricItem[], picker: (row: InventoryMetricItem) => string | null | undefined): string[] {
  return Array.from(new Set(rows.map((row) => normalizeText(picker(row))).filter(Boolean))).sort((a, b) => a.localeCompare(b));
}

function normalizeText(value: string | null | undefined): string {
  return String(value ?? "").trim();
}

function normalizeStatus(value: string | null | undefined): string {
  return normalizeText(value) || "estable";
}

function numberOrZero(value: number | null | undefined): number {
  const numeric = Number(value ?? 0);
  return Number.isFinite(numeric) ? numeric : 0;
}

function statusRank(row: InventoryMetricItem): number {
  return statusOrder[normalizeStatus(row.stock_alert_level)] ?? 99;
}
