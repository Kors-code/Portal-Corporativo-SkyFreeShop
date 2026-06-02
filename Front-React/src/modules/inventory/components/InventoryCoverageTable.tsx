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

      <div className="overflow-x-auto px-2 pb-2 xl:overflow-visible">
        <table className="min-w-[980px] w-full border-separate border-spacing-0 text-left text-sm xl:min-w-0">
          <thead className="sticky top-0 z-10 bg-slate-50 text-slate-600">
            <tr>
              <Th>Producto</Th>
              <Th>Auditoria mensual</Th>
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
                <td className="px-5 py-8 text-slate-500" colSpan={8}>
                  Cargando informacion...
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td className="px-5 py-8 text-slate-500" colSpan={8}>
                  No hay datos para mostrar.
                </td>
              </tr>
            ) : (
              rows.map((item) => {
                const monthEntries = getMonthEntries(item.month_columns);

                return (
                  <tr
                    key={`${item.store_id ?? "all"}-${item.product_id}`}
                    className="group"
                  >
                    <Td className="w-[30%] min-w-[280px] whitespace-normal">
                      <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">
                            {item.store_code ?? item.store_name ?? "-"}
                          </span>
                          <span className="font-semibold text-slate-900">{item.product_code}</span>
                        </div>
                        <div className="leading-5 text-slate-700">{item.description ?? "-"}</div>
                        <div className="flex flex-wrap gap-1.5 text-xs text-slate-500">
                          {item.brand && <InfoPill>{item.brand}</InfoPill>}
                          {(item.supplier ?? item.proveedor) && <InfoPill>{item.supplier ?? item.proveedor}</InfoPill>}
                          {item.classification_desc && <InfoPill>{item.classification_desc}</InfoPill>}
                        </div>
                      </div>
                    </Td>
                    <Td className="w-[26%] min-w-[240px] whitespace-normal">
                      <MonthAudit entries={monthEntries} maxKey={item.maximo_mes_key} />
                    </Td>
                    <Td strong>
                      <div>{formatNumber(item.maximo_mes)}</div>
                      <div className="mt-1 text-xs font-normal text-slate-400">
                        {item.maximo_mes_key ?? "-"}
                      </div>
                    </Td>
                    <Td>{formatNumber(item.stock_actual)}</Td>
                    <Td>
                      <div>{formatNumber(item.dias_disponibles)}</div>
                      <div className="mt-1 text-xs text-slate-400">
                        {formatNumber(item.rotacion_diaria_mes)} / dia
                      </div>
                    </Td>
                    <Td strong className="bg-sky-50/70 text-sky-900 group-hover:bg-sky-100/80">
                      {formatNumber(item.suggested_purchase)}
                    </Td>
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
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Th({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <th
      className="whitespace-nowrap border-b border-slate-200 px-4 py-4 font-medium"
    >
      {children}
    </th>
  );
}

function Td({
  children,
  strong,
  className,
}: {
  children: React.ReactNode;
  strong?: boolean;
  className?: string;
}) {
  return (
    <td
      className={`whitespace-nowrap border-b border-slate-100 px-4 py-4 align-top ${
        strong ? "font-semibold text-slate-900" : "text-slate-600"
      } group-hover:bg-sky-50/50 ${className ?? ""}`}
    >
      {children}
    </td>
  );
}

function InfoPill({ children }: { children: React.ReactNode }) {
  return (
    <span className="rounded-full bg-slate-100 px-2 py-1">
      {children}
    </span>
  );
}

function MonthAudit({
  entries,
  maxKey,
}: {
  entries: Array<[string, number]>;
  maxKey?: string | null;
}) {
  if (entries.length === 0) {
    return <span className="text-slate-400">Sin ventas mensuales</span>;
  }

  return (
    <div className="flex flex-wrap gap-1.5">
      {entries.map(([monthKey, value]) => {
        const active = monthKey === maxKey;
        return (
          <span
            key={monthKey}
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs ${
              active
                ? "bg-slate-900 font-semibold text-white"
                : "bg-slate-100 text-slate-600"
            }`}
            title={`${monthKey}: ${formatNumber(value)}`}
          >
            <span>{monthKey}</span>
            <span>{formatNumber(value)}</span>
          </span>
        );
      })}
    </div>
  );
}

function getMonthEntries(months: Record<string, number> | null | undefined): Array<[string, number]> {
  return Object.entries(months ?? {})
    .map(([key, value]) => [key, Number(value)] as [string, number])
    .sort(([left], [right]) => monthKeyValue(left) - monthKeyValue(right));
}

function monthKeyValue(monthKey: string): number {
  const [monthText, yearText] = monthKey.split(".");
  const month = Math.max(1, Math.min(12, Number(monthText) || 1));
  const year = Number(yearText) < 100 ? 2000 + (Number(yearText) || 0) : Number(yearText) || 0;

  return year * 100 + month;
}

function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 }).format(Number(value));
}
