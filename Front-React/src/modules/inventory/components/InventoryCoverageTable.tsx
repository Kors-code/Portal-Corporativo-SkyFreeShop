import type { InventoryMetricItem } from "../services/inventoryService";

type CoverageRow = InventoryMetricItem & {
  suggested_purchase?: number | null;
  suggested_purchase_cases?: number | null;
  factor_conversion_error?: boolean | null;
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

      <div className="px-3 pb-3">
        <div className="hidden rounded-2xl bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid lg:grid-cols-[minmax(260px,2fr)_minmax(230px,1.35fr)_minmax(110px,.7fr)_minmax(110px,.7fr)_minmax(110px,.7fr)_minmax(140px,.8fr)] lg:gap-3">
          <div>Producto</div>
          <div>Auditoria mensual</div>
          <div>Maximo</div>
          <div>Stock</div>
          <div>Sugerido</div>
          <div>Estado</div>
        </div>

        <div className="mt-2 space-y-2">
          {loading ? (
            <div className="rounded-2xl border border-slate-100 px-5 py-8 text-slate-500">
              Cargando informacion...
            </div>
          ) : rows.length === 0 ? (
            <div className="rounded-2xl border border-slate-100 px-5 py-8 text-slate-500">
              No hay datos para mostrar.
            </div>
          ) : (
            rows.map((item) => {
              const monthEntries = getMonthEntries(item.month_columns);
              const noSalesCodes = item.no_sales_store_codes ?? [];

              return (
                <div
                  key={`${item.store_id ?? "all"}-${item.product_id}`}
                  className="grid gap-3 rounded-2xl border border-slate-100 bg-white px-4 py-4 shadow-sm transition hover:border-sky-200 hover:bg-sky-50/40 lg:grid-cols-[minmax(260px,2fr)_minmax(230px,1.35fr)_minmax(110px,.7fr)_minmax(110px,.7fr)_minmax(110px,.7fr)_minmax(140px,.8fr)] lg:items-start"
                >
                  <div className="min-w-0">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                      <span className="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">
                        {item.store_code ?? item.store_name ?? "-"}
                      </span>
                      <span className="font-semibold text-slate-900">{item.product_code}</span>
                    </div>
                    <div className="max-h-12 overflow-hidden break-words text-sm leading-6 text-slate-700">
                      {item.description ?? "-"}
                    </div>
                    <div className="mt-2 flex max-h-14 flex-wrap gap-1.5 overflow-hidden text-xs text-slate-500">
                      {item.brand && <InfoPill>{item.brand}</InfoPill>}
                      {(item.supplier ?? item.proveedor) && <InfoPill>{item.supplier ?? item.proveedor}</InfoPill>}
                      {item.classification_desc && <InfoPill>{item.classification_desc}</InfoPill>}
                    </div>
                  </div>

                  <MetricBlock label="Auditoria mensual" className="lg:hidden">
                    <MonthAudit
                      entries={monthEntries}
                      maxKey={item.maximo_mes_key}
                      missingMonthStoreCodes={item.missing_month_store_codes}
                    />
                    <NoSalesNotice codes={noSalesCodes} />
                  </MetricBlock>
                  <div className="hidden min-w-0 lg:block">
                    <MonthAudit
                      entries={monthEntries}
                      maxKey={item.maximo_mes_key}
                      missingMonthStoreCodes={item.missing_month_store_codes}
                    />
                    <NoSalesNotice codes={noSalesCodes} />
                  </div>

                  <MetricBlock label="Max mes">
                    <div className="text-base font-semibold text-slate-900">{formatNumber(item.maximo_mes)}</div>
                    <div className="text-xs text-slate-400">{item.maximo_mes_key ?? "-"}</div>
                    <div className="text-xs text-slate-400">{formatNumber(item.rotacion_diaria_mes)} / dia</div>
                  </MetricBlock>

                  <MetricBlock label="Stock">
                    <div className="text-base font-semibold text-slate-900">{formatNumber(item.stock_actual)}</div>
                    <div className="text-xs text-slate-400">{formatNumber(item.dias_disponibles)} dias</div>
                  </MetricBlock>

                  <MetricBlock label="Sugerido">
                    <div className="inline-flex rounded-xl bg-sky-100 px-3 py-2 text-lg font-bold text-sky-900">
                      {formatNumber(item.suggested_purchase)}
                    </div>
                    {item.factor_conversion_error ? (
                      <div className="mt-1 text-xs font-semibold text-rose-600">Sin factor</div>
                    ) : (
                      <div className="mt-1 text-xs text-slate-500">
                        {formatNumber(item.suggested_purchase_cases)} cajas
                      </div>
                    )}
                  </MetricBlock>

                  <MetricBlock label="Estado">
                    <div className="text-xs text-slate-400">{item.last_inventory_date ?? "-"}</div>
                    <span
                      className={`mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                        badgeStyles[item.stock_alert_level ?? "sin_rotacion"] ??
                        "bg-slate-100 text-slate-700"
                      }`}
                    >
                      {item.stock_alert_label ?? "Sin rotacion"}
                    </span>
                  </MetricBlock>
                </div>
              );
            })
          )}
        </div>
      </div>
    </div>
  );
}

function InfoPill({ children }: { children: React.ReactNode }) {
  return (
    <span className="rounded-full bg-slate-100 px-2 py-1">
      {children}
    </span>
  );
}

function MetricBlock({
  label,
  children,
  className,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={`min-w-0 rounded-xl bg-slate-50 px-3 py-3 lg:bg-transparent lg:px-0 lg:py-0 ${className ?? ""}`}>
      <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400 lg:hidden">
        {label}
      </div>
      {children}
    </div>
  );
}

function MonthAudit({
  entries,
  maxKey,
  missingMonthStoreCodes,
}: {
  entries: Array<[string, number]>;
  maxKey?: string | null;
  missingMonthStoreCodes?: Record<string, string[]> | null;
}) {
  if (entries.length === 0) {
    return <span className="text-slate-400">Sin ventas mensuales</span>;
  }

  return (
    <div className="max-h-20 overflow-y-auto pr-1">
      <div className="flex flex-wrap gap-1.5">
      {entries.map(([monthKey, value]) => {
        const active = monthKey === maxKey;
        const missingCodes = missingMonthStoreCodes?.[monthKey] ?? [];
        return (
          <span
            key={monthKey}
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs ${
              active
                ? "bg-slate-900 font-semibold text-white"
                : "bg-slate-100 text-slate-600"
            }`}
            title={`${monthKey}: ${formatNumber(value)}${
              missingCodes.length > 0 ? ` | Sin ventas ${missingCodes.join(", ")}` : ""
            }`}
          >
            <span>{monthKey}</span>
            <span>{formatNumber(value)}</span>
            {missingCodes.map((code) => (
              <span
                key={code}
                className={`rounded-full px-1.5 text-[10px] font-bold ${
                  active ? "bg-rose-100 text-rose-700" : "bg-rose-50 text-rose-600"
                }`}
              >
                {code}
              </span>
            ))}
          </span>
        );
      })}
      </div>
    </div>
  );
}

function NoSalesNotice({ codes }: { codes: string[] }) {
  if (codes.length === 0) return null;

  return (
    <div className="mt-1 text-xs font-medium text-rose-600">
      Sin ventas {codes.join(", ")}
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
