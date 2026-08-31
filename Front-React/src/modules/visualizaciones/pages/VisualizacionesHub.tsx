import { BarChart3, BookOpen, CalendarDays, ChevronRight, LockKeyhole, PackageSearch, TrendingUp, Users } from "lucide-react";
import { Link } from "react-router-dom";

const dashboards = [
  {
    title: "Daily Sales",
    description: "Budget tracking, store filters, date ranges, daily compliance, and WhatsApp delivery.",
    to: "/visualizaciones/daily-sales",
    icon: BarChart3,
    status: "Available",
    type: "Daily",
  },
  {
    title: "Store Sales",
    description: "Daily Arrivals + Departures summary with sales, transactions, average ticket, and units per ticket.",
    to: "/visualizaciones/ventas-tiendas",
    icon: TrendingUp,
    status: "Available",
    type: "Daily",
  },
  {
    title: "Advisor Sales",
    description: "Daily advisor ranking with sales, transactions, average ticket, and units per ticket.",
    to: "/visualizaciones/ventas-asesores",
    icon: CalendarDays,
    status: "Available",
    type: "Daily",
  },
  {
    title: "Advisor Analytics",
    description: "Visual advisor gallery with multi-month compliance, category mix, average ticket, tickets, and KPIs.",
    to: "/visualizaciones/asesores-analytics",
    icon: Users,
    status: "New",
    type: "Analytics",
  },
  {
    title: "Inventory Monitoring",
    description: "Corporate stock watch with availability days, status, provider, brand, SKU and category filters.",
    to: "/visualizaciones/inventario",
    icon: PackageSearch,
    status: "New",
    type: "Inventory",
  },
  {
    title: "Info asesores",
    description: "Biblioteca informativa por proveedor conectada a OneDrive para consultar PDFs y material comercial.",
    to: "/info-asesores",
    icon: BookOpen,
    status: "Info",
    type: "Informacional",
  },
];

export default function VisualizacionesHub() {
  return (
    <div className="space-y-8">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizations</p>
            <h1 className="mt-2 text-3xl font-black text-slate-950">Dashboard Hub</h1>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
              Executive dashboards for reviewing sales indicators and daily performance without mixing operational setup
              with reporting views.
            </p>
          </div>

          <div className="rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm font-semibold text-primary">
            Access for super admin and leaders
          </div>
        </div>
      </section>

      <section>
        <div className="mb-4 flex items-center gap-2 text-sm font-bold text-slate-700">
          <TrendingUp size={18} className="text-primary" />
          Available Dashboards
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {dashboards.map((dashboard) => {
            const Icon = dashboard.icon;

            return (
              <Link
                key={dashboard.to}
                to={dashboard.to}
                className="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-primary/40 hover:shadow-md"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Icon size={22} />
                  </div>
                  <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                    {dashboard.status}
                  </span>
                </div>

                <h2 className="mt-5 text-xl font-black text-slate-950">{dashboard.title}</h2>
                <p className="mt-2 min-h-[64px] text-sm leading-6 text-slate-600">{dashboard.description}</p>

                <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                  <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-400">
                    <CalendarDays size={14} />
                    {dashboard.type}
                  </div>
                  <div className="inline-flex items-center gap-2 text-sm font-bold text-primary">
                    Open
                    <ChevronRight size={17} className="transition group-hover:translate-x-1" />
                  </div>
                </div>
              </Link>
            );
          })}

          <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-slate-500">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-white text-slate-400 shadow-sm">
              <LockKeyhole size={21} />
            </div>
            <h2 className="mt-5 text-xl font-black text-slate-800">Next Dashboards</h2>
            <p className="mt-2 text-sm leading-6">
              This area is ready for inventory, leader compliance, or cashier dashboards when needed.
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
