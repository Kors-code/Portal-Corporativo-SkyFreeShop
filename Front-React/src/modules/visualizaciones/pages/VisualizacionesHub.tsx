import { BarChart3, CalendarDays, ChevronRight, LockKeyhole, TrendingUp } from "lucide-react";
import { Link } from "react-router-dom";

const dashboards = [
  {
    title: "Daily",
    description: "Indicadores diarios de ventas, clientes, tickets y productos para monitorear el desempeño de cada tienda en tiempo real.",
    to: "/visualizaciones/cierre-caja",
    icon: BarChart3,
    status: "Disponible",
  },
];

export default function VisualizacionesHub() {
  return (
    <div className="space-y-8">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizaciones</p>
            <h1 className="mt-2 text-3xl font-black text-slate-950">Hub de tableros</h1>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
              Accesos ejecutivos para consultar indicadores, cierres y lecturas diarias sin mezclar configuraciones
              operativas con tableros de seguimiento.
            </p>
          </div>

          <div className="rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm font-semibold text-primary">
            Acceso para super admin y lideres
          </div>
        </div>
      </section>

      <section>
        <div className="mb-4 flex items-center gap-2 text-sm font-bold text-slate-700">
          <TrendingUp size={18} className="text-primary" />
          Tableros disponibles
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
                    Diario
                  </div>
                  <div className="inline-flex items-center gap-2 text-sm font-bold text-primary">
                    Abrir
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
            <h2 className="mt-5 text-xl font-black text-slate-800">Proximos tableros</h2>
            <p className="mt-2 text-sm leading-6">
              Este espacio queda listo para agregar inventario, ventas por tienda, cumplimiento por lider o cierres por
              cajero cuando los necesitemos.
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
