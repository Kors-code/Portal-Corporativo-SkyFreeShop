import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  Activity,
  ArrowLeft,
  CalendarDays,
  ChevronRight,
  Gauge,
  Maximize2,
  ReceiptText,
  Search,
  Target,
  TrendingUp,
  Users,
  Wallet,
  X,
} from "lucide-react";
import {
  getAdvisorAnalytics,
  type AdvisorAnalyticsResponse,
} from "../services/visualizacionesService";

const usd = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 0,
});

const usd2 = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 2,
});

const num = new Intl.NumberFormat("es-CO", {
  maximumFractionDigits: 1,
});

const palette = ["#0f766e", "#2563eb", "#dc2626", "#ca8a04", "#7c3aed", "#0891b2", "#16a34a", "#be123c"];

function monthLabel(date: string) {
  const [year, month] = date.split("-");
  const names = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  return `${names[Number(month) - 1] ?? month} ${year}`;
}

function complianceClass(value: number) {
  if (value < 80) return "text-red-700";
  if (value < 100) return "text-blue-700";
  return "text-emerald-700";
}

export default function AdvisorAnalyticsDashboard() {
  const [data, setData] = useState<AdvisorAnalyticsResponse | null>(null);
  const [selectedBudgetIds, setSelectedBudgetIds] = useState<number[]>([]);
  const [selectedUserId, setSelectedUserId] = useState<number | undefined>();
  const [expanded, setExpanded] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = async (next?: { budgetIds?: number[]; userId?: number }) => {
    try {
      setLoading(true);
      setError("");
      const result = await getAdvisorAnalytics({
        budget_ids: next?.budgetIds ?? selectedBudgetIds,
        user_id: next?.userId ?? selectedUserId,
      });
      setData(result);
      setSelectedBudgetIds(result.filters.budget_ids);
      setSelectedUserId(result.filters.user_id ?? undefined);
    } catch (err) {
      console.error(err);
      setError("No se pudo cargar el tablero de asesores.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const advisors = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return data?.advisors ?? [];

    return (data?.advisors ?? []).filter((advisor) => {
      return `${advisor.advisor} ${advisor.seller_code ?? ""}`.toLowerCase().includes(term);
    });
  }, [data?.advisors, query]);

  const selectedAdvisor = data?.selected_advisor;
  const dailyChart = (data?.daily ?? []).map((row) => ({
    ...row,
    target_80_usd: row.target_usd * 0.8,
  }));
  const categoryPie = data?.categories.map((row) => ({ name: row.category, value: row.sales_usd, pct: row.pct })) ?? [];

  const toggleBudget = (budgetId: number) => {
    const next = selectedBudgetIds.includes(budgetId)
      ? selectedBudgetIds.filter((id) => id !== budgetId)
      : [...selectedBudgetIds, budgetId];

    if (next.length === 0) return;
    setSelectedBudgetIds(next);
    void load({ budgetIds: next });
  };

  const selectAdvisor = (userId: number) => {
    setSelectedUserId(userId);
    setExpanded(null);
    void load({ userId });
  };

  const cards = [
    {
      id: "daily",
      title: "Cumplimiento diario",
      subtitle: "Venta vs meta diaria estimada",
      content: <DailyComplianceChart data={dailyChart} />,
    },
    {
      id: "categories",
      title: "Mix por categoria",
      subtitle: "% de venta del rango seleccionado",
      content: <CategoryPie data={categoryPie} />,
    },
    {
      id: "monthly",
      title: "Comparativo mensual",
      subtitle: "Cumplimiento, venta y ticket promedio",
      content: <MonthlyChart data={data?.monthly ?? []} />,
    },
    {
      id: "tickets",
      title: "Tickets por dia",
      subtitle: "Cantidad de tickets en cada dia trabajado",
      content: <DailySingleMetricChart data={dailyChart} dataKey="trx" name="Tickets" color="#2563eb" />,
    },
    {
      id: "avgTicket",
      title: "Ticket promedio",
      subtitle: "Valor promedio de cada ticket por dia trabajado",
      content: <DailySingleMetricChart data={dailyChart} dataKey="tkt_usd" name="Ticket promedio" color="#0f766e" money />,
    },
    {
      id: "unitsTicket",
      title: "Unidades por ticket",
      subtitle: "Promedio de unidades vendidas por ticket",
      content: <DailySingleMetricChart data={dailyChart} dataKey="units_per_ticket" name="Unidad/TKT" color="#ca8a04" decimal />,
    },
  ];

  const expandedCard = cards.find((card) => card.id === expanded);

  return (
    <div className="space-y-5 text-slate-950">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizaciones</p>
            <h1 className="mt-1 text-2xl font-black leading-tight text-slate-950">Analitica visual de asesores</h1>
            <p className="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-500">
              Selecciona un asesor, compara varios meses y abre cada grafico en grande para revisar cumplimiento,
              categorias, ticket promedio, tickets y unidades por ticket.
            </p>
          </div>

          <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
            Cumplimiento estimado solo con dias trabajados
          </div>
        </div>
      </section>

      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
          {error}
        </div>
      )}

      <section className="grid gap-4 xl:grid-cols-[340px_1fr]">
        <aside className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div>
            <div className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
              <Search size={15} />
              Asesores
            </div>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Buscar asesor"
                className="h-11 w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 text-sm font-semibold text-slate-900"
              />
            </div>
          </div>

          <div className="max-h-[620px] space-y-2 overflow-auto pr-1">
            {loading && <div className="py-8 text-center text-sm font-bold text-slate-500">Cargando...</div>}
            {!loading && advisors.map((advisor) => {
              const active = advisor.user_id === selectedUserId;

              return (
                <button
                  key={advisor.user_id}
                  onClick={() => selectAdvisor(advisor.user_id)}
                  className={`w-full rounded-lg border p-3 text-left transition ${
                    active
                      ? "border-primary bg-primary text-white"
                      : "border-slate-200 bg-slate-50 text-slate-900 hover:border-primary/50"
                  }`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="font-black leading-tight">{advisor.advisor}</div>
                      <div className={`mt-1 text-xs font-bold uppercase ${active ? "text-white/75" : "text-slate-400"}`}>
                        {advisor.seller_code ?? "Sin codigo"}
                      </div>
                    </div>
                    <ChevronRight size={17} className={active ? "text-white" : "text-slate-400"} />
                  </div>
                  <div className={`mt-3 text-lg font-black ${active ? "text-white" : "text-slate-950"}`}>
                    {usd.format(advisor.total_usd)}
                  </div>
                  <div className={`text-xs font-bold ${active ? "text-white/75" : "text-slate-500"}`}>
                    {advisor.trx} tickets | TKT {usd2.format(advisor.tkt_usd)}
                  </div>
                </button>
              );
            })}
            {!loading && advisors.length === 0 && (
              <div className="py-8 text-center text-sm font-bold text-slate-500">Sin asesores para estos meses.</div>
            )}
          </div>
        </aside>

        <main className="space-y-4">
          <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="text-xs font-black uppercase tracking-wide text-slate-500">Meses</div>
                <div className="mt-1 text-lg font-black text-slate-950">{selectedAdvisor?.advisor ?? "Selecciona un asesor"}</div>
              </div>
              <div className="flex items-center gap-2 text-sm font-bold text-slate-500">
                <CalendarDays size={16} />
                {selectedBudgetIds.length} mes(es)
              </div>
            </div>
            <div className="flex gap-2 overflow-x-auto pb-1">
              {(data?.budgets ?? []).slice(0, 12).map((budget) => {
                const active = selectedBudgetIds.includes(budget.id);

                return (
                  <button
                    key={budget.id}
                    onClick={() => toggleBudget(budget.id)}
                    className={`shrink-0 rounded-lg border px-3 py-2 text-left text-xs font-bold transition ${
                      active
                        ? "border-slate-950 bg-slate-950 text-white"
                        : "border-slate-200 bg-slate-50 text-slate-700 hover:border-primary/40"
                    }`}
                  >
                    <span className="block">{monthLabel(budget.start_date)}</span>
                    <span className={active ? "text-white/75" : "text-slate-500"}>{usd.format(budget.target_amount)}</span>
                  </button>
                );
              })}
            </div>
          </section>

          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi icon={<Wallet size={18} />} label="Venta" value={usd.format(data?.totals.sales_usd ?? 0)} />
            <Kpi
              icon={<Gauge size={18} />}
              label="Cumplimiento"
              value={`${num.format(data?.totals.compliance_pct ?? 0)}%`}
              valueClass={complianceClass(data?.totals.compliance_pct ?? 0)}
            />
            <Kpi icon={<ReceiptText size={18} />} label="Tickets" value={String(data?.totals.trx ?? 0)} />
            <Kpi icon={<Target size={18} />} label="Ticket prom." value={usd2.format(data?.totals.tkt_usd ?? 0)} />
            <Kpi icon={<Activity size={18} />} label="Unidades" value={num.format(data?.totals.units ?? 0)} />
            <Kpi icon={<TrendingUp size={18} />} label="Und/TKT" value={num.format(data?.totals.units_per_ticket ?? 0)} />
            <Kpi icon={<CalendarDays size={18} />} label="Dias trabajados" value={String(data?.totals.days_with_sales ?? 0)} />
            <Kpi icon={<Users size={18} />} label="Meta estimada" value={usd.format(data?.totals.target_usd ?? 0)} />
          </section>

          <section className="grid gap-4 xl:grid-cols-2">
            {cards.map((card) => (
              <GraphCard
                key={card.id}
                title={card.title}
                subtitle={card.subtitle}
                onExpand={() => setExpanded(card.id)}
              >
                {card.content}
              </GraphCard>
            ))}
          </section>
        </main>
      </section>

      {expandedCard && (
        <div className="fixed inset-0 z-50 bg-slate-950/70 p-3 backdrop-blur-sm sm:p-6">
          <section className="mx-auto flex h-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
            <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
              <button
                onClick={() => setExpanded(null)}
                className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200"
                title="Volver"
              >
                <ArrowLeft size={18} />
              </button>
              <div className="min-w-0 flex-1">
                <h2 className="truncate text-lg font-black text-slate-950">{expandedCard.title}</h2>
                <p className="truncate text-sm font-semibold text-slate-500">{expandedCard.subtitle}</p>
              </div>
              <button
                onClick={() => setExpanded(null)}
                className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-950 text-white"
                title="Cerrar"
              >
                <X size={18} />
              </button>
            </div>
            <div className="min-h-0 flex-1 p-4">
              <div className="h-full min-h-[520px]">{expandedCard.content}</div>
            </div>
          </section>
        </div>
      )}
    </div>
  );
}

function DailyComplianceChart({ data }: { data: Array<Record<string, number | string>> }) {
  return (
    <div className="h-full min-h-[320px]">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 20, right: 16, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
          <XAxis dataKey="label" tick={{ fontSize: 11 }} />
          <YAxis tick={{ fontSize: 11 }} />
          <Tooltip formatter={(value, name) => [name === "compliance_pct" ? `${num.format(Number(value))}%` : usd2.format(Number(value)), name]} />
          <Bar dataKey="sales_usd" name="Venta" fill="#0f766e" radius={[4, 4, 0, 0]} />
          <Line type="monotone" dataKey="target_80_usd" name="80% meta" stroke="#ca8a04" strokeWidth={3} dot={false} />
          <Line type="monotone" dataKey="target_usd" name="100% meta" stroke="#16a34a" strokeWidth={3} dot={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

function CategoryPie({ data }: { data: Array<{ name: string; value: number; pct: number }> }) {
  return (
    <div className="grid h-full min-h-[320px] gap-3 lg:grid-cols-[1fr_220px]">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" innerRadius="54%" outerRadius="82%" paddingAngle={2}>
            {data.map((_, index) => (
              <Cell key={index} fill={palette[index % palette.length]} />
            ))}
          </Pie>
          <Tooltip formatter={(value) => usd2.format(Number(value))} />
        </PieChart>
      </ResponsiveContainer>
      <div className="space-y-2 overflow-auto">
        {data.map((row, index) => (
          <div key={row.name} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="h-3 w-3 shrink-0 rounded-sm" style={{ backgroundColor: palette[index % palette.length] }} />
                <span className="truncate text-sm font-black text-slate-800">{row.name}</span>
              </div>
              <div className="mt-1 text-xs font-bold text-slate-500">{usd.format(row.value)}</div>
            </div>
            <div className="text-sm font-black text-slate-950">{num.format(row.pct)}%</div>
          </div>
        ))}
      </div>
    </div>
  );
}

function MonthlyChart({ data }: { data: AdvisorAnalyticsResponse["monthly"] }) {
  return (
    <div className="h-full min-h-[320px]">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 20, right: 16, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
          <XAxis dataKey="month" tick={{ fontSize: 11 }} />
          <YAxis yAxisId="money" tick={{ fontSize: 11 }} />
          <YAxis yAxisId="pct" orientation="right" tick={{ fontSize: 11 }} />
          <Tooltip formatter={(value, name) => [name === "Cumplimiento" ? `${num.format(Number(value))}%` : usd2.format(Number(value)), name]} />
          <Bar yAxisId="money" dataKey="sales_usd" name="Venta" fill="#2563eb" radius={[4, 4, 0, 0]} />
          <Line yAxisId="pct" type="monotone" dataKey="compliance_pct" name="Cumplimiento" stroke="#dc2626" strokeWidth={3} />
          <Line yAxisId="money" type="monotone" dataKey="tkt_usd" name="TKT" stroke="#0f766e" strokeWidth={3} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

function DailySingleMetricChart({
  data,
  dataKey,
  name,
  color,
  money = false,
  decimal = false,
}: {
  data: Array<Record<string, number | string>>;
  dataKey: string;
  name: string;
  color: string;
  money?: boolean;
  decimal?: boolean;
}) {
  return (
    <div className="h-full min-h-[320px]">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 20, right: 16, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
          <XAxis dataKey="label" tick={{ fontSize: 11 }} />
          <YAxis tick={{ fontSize: 11 }} />
          <Tooltip
            formatter={(value) => [
              money ? usd2.format(Number(value)) : decimal ? num.format(Number(value)) : Number(value).toFixed(0),
              name,
            ]}
          />
          <Bar dataKey={dataKey} name={name} fill={color} radius={[4, 4, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

function GraphCard({
  title,
  subtitle,
  children,
  onExpand,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
  onExpand: () => void;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-black text-slate-950">{title}</h2>
          <p className="text-sm font-semibold text-slate-500">{subtitle}</p>
        </div>
        <button
          onClick={onExpand}
          className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200"
          title="Ver grande"
        >
          <Maximize2 size={17} />
        </button>
      </div>
      <div className="h-[340px]">{children}</div>
    </section>
  );
}

function Kpi({
  icon,
  label,
  value,
  valueClass = "text-slate-950",
}: {
  icon: ReactNode;
  label: string;
  value: string;
  valueClass?: string;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
        {icon}
      </div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-xl font-black ${valueClass}`}>{value}</div>
    </section>
  );
}
