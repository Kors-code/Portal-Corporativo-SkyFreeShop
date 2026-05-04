import type { InventoryMetricItem } from "../services/inventoryService";

type CoverageRow = InventoryMetricItem & {
  suggested_purchase?: number | null;
};

type Props = {
  rows: CoverageRow[];
  loading?: boolean;
  title?: string;
  subtitle?: string;
};

const badgeStyles: Record<string, string> = {
  sin_stock: "bg-slate-100 text-slate-700",
  critico: "bg-rose-100 text-rose-700",
  alto: "bg-amber-100 text-amber-700",
  medio: "bg-yellow-100 text-yellow-700",
  estable: "bg-emerald-100 text-emerald-700",
  sin_rotacion: "bg-sky-100 text-sky-700",
};

export default function InventoryCoverageTable({
  rows,
  loading = false,
  title = "Cobertura de inventario",
  subtitle = "Tabla operativa de stock, cobertura y alertas.",
}: Props) {
  return (
    <div className="rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 className="text-xl font-semibold text-slate-900">{title}</h2>
          <p className="mt-1 text-sm leading-6 text-slate-500">{subtitle}</p>
        </div>
        <div className="rounded-full bg-slate-100 px-4 py-2 text-sm text-slate-600">
          {loading ? "Cargando..." : `${rows.length} filas`}
        </div>
      </div>

      <div className="overflow-x-auto px-2 pb-2">
        <table className="min-w-[1780px] w-full border-separate border-spacing-0 text-left text-[15px]">
          <thead className="sticky top-0 z-10 bg-slate-50 text-slate-600">
            <tr>
              <Th sticky>Tienda</Th>
              <Th stickyOffset="left-[180px]">SKU</Th>
              <Th>Descripcion</Th>
              <Th>Marca</Th>
              <Th>Proveedor</Th>
              <Th>Categoria</Th>
              <Th>Max mes</Th>
              <Th>Inventario</Th>
              <Th>Dias disponibles</Th>
              <Th>Sugerido compra</Th>
              <Th>Fecha inventario</Th>
              <Th>Alerta</Th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td className="px-5 py-8 text-slate-500" colSpan={12}>
                  Cargando informacion...
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td className="px-5 py-8 text-slate-500" colSpan={12}>
                  No hay datos para mostrar.
                </td>
              </tr>
            ) : (
              rows.map((item) => (
                <tr
                  key={`${item.store_id ?? "all"}-${item.product_id}`}
                  className="group"
                >
                  <Td sticky>{item.store_name ?? item.store_code ?? "-"}</Td>
                  <Td strong stickyOffset="left-[180px]">{item.product_code}</Td>
                  <Td className="min-w-[360px] whitespace-normal leading-6">{item.description ?? "-"}</Td>
                  <Td className="min-w-[170px] whitespace-normal leading-6">{item.brand ?? "-"}</Td>
                  <Td className="min-w-[190px] whitespace-normal leading-6">{item.supplier ?? item.proveedor ?? "-"}</Td>
                  <Td className="min-w-[180px] whitespace-normal leading-6">{item.classification_desc ?? "-"}</Td>
                  <Td>{formatNumber(item.maximo_mes)}</Td>
                  <Td>{formatNumber(item.stock_actual)}</Td>
                  <Td>{formatNumber(item.dias_disponibles)}</Td>
                  <Td strong>{formatNumber(item.suggested_purchase)}</Td>
                  <Td>{item.last_inventory_date ?? "-"}</Td>
                  <Td>
                    <span
                      className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                        badgeStyles[item.stock_alert_level ?? "sin_rotacion"] ??
                        "bg-slate-100 text-slate-700"
                      }`}
                    >
                      {item.stock_alert_label ?? "Sin rotacion"}
                    </span>
                  </Td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Th({
  children,
  sticky,
  stickyOffset,
}: {
  children: React.ReactNode;
  sticky?: boolean;
  stickyOffset?: string;
}) {
  return (
    <th
      className={`whitespace-nowrap border-b border-slate-200 px-4 py-4 font-medium ${
      sticky ? `sticky ${stickyOffset ?? "left-0"} z-20 bg-slate-50` : ""
      }`}
    >
      {children}
    </th>
  );
}

function Td({
  children,
  strong,
  className,
  sticky,
  stickyOffset,
}: {
  children: React.ReactNode;
  strong?: boolean;
  className?: string;
  sticky?: boolean;
  stickyOffset?: string;
}) {
  return (
    <td
      className={`whitespace-nowrap border-b border-slate-100 px-4 py-4 align-top ${
        strong ? "font-semibold text-slate-900" : "text-slate-600"
      } ${sticky ? `sticky ${stickyOffset ?? "left-0"} z-[5] bg-white group-hover:bg-sky-50/50` : "group-hover:bg-sky-50/50"} ${className ?? ""}`}
    >
      {children}
    </td>
  );
}

function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 }).format(Number(value));
}
