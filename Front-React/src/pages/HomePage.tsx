import ModuleCard from "../components/ModuleCard";
import { getCurrentUser } from "../auth/auth";

export default function HomePage() {
  const currentUser = getCurrentUser();
  const canViewVisualizaciones = ["super_admin", "lider"].includes(currentUser?.role);
  const moduleSections = [
    {
      title: "Comercial",
      items: [
        { title: "Configuracion de presupuestos", to: "/budget", description: "Crea, configura y edita presupuestos" },
        { title: "Seguimiento Asesores", to: "/CommissionCardsPage", description: "Resumen de ventas, KPI's y comisiones" },
        { title: "Historial de Importes", to: "/ImportsManagerPage", description: "Importa, consulta y edita" },
        { title: "Seguimiento Cajeros", to: "/CashierAwards", description: "Seguimiento de comisiones por cajero" },
        { title: "Asesores Especializados", to: "/commissions/DualCommissionAdmin", description: "Seguimiento de asesores especializados" },
        { title: "Comisiones de Lideres", to: "/commissions/CommissionLeadersPage", description: "Seguimiento de comisiones de lideres" },
      ],
    },
    {
      title: "Minuta de entrega",
      items: [
        { title: "Entrega de Lideres", to: "/entregas", description: "Recibir y consultar actas de turno" },
      ],
    },
    {
      title: "Personal",
      items: [
        { title: "Gestion de personal", to: "/users", description: "Administra personal y accesos del sistema" },
        { title: "Wish List", to: "/CatalogMatchPage", description: "Solicitudes y catalogo de productos" },
      ],
    },
    {
      title: "Contabilidad",
      items: [
        { title: "Bancos", to: "/BankImportsManagerPage", description: "Historial de importaciones bancarias" },
        { title: "Movimientos bancarios", to: "/BankMovementsPage", description: "UID, filtros por banco, dias y descarga CSV" },
      ],
    },
    {
      title: "Inventario",
      items: [
        { title: "Cobertura de Inventario", to: "/inventarios/cobertura", description: "Alertas, dias disponibles y riesgo por SKU" },
        { title: "Alertas de Inventario", to: "/inventarios/alertas", description: "Listas, top de ventas y notificaciones por correo" },
      ],
    },
  ];

  return (
    <div className="pt-20">
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-12">
        <div className="bg-white rounded-3xl p-8 md:p-12 shadow-sm border border-gray-200">
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
              <h1 className="text-3xl md:text-4xl font-extrabold text-primary leading-tight">
                Portal Corporativo
              </h1>
              <p className="mt-3 text-gray-600 max-w-xl" />
            </div>

            <div className="flex gap-3 items-center">
              <div className="hidden sm:block text-sm text-gray-500">Acciones rapidas:</div>
              <a href="/panel/ImportsManagerPage" className="inline-flex items-center px-4 py-2 bg-primary/10 text-primary rounded-lg text-sm font-medium hover:bg-primary/20 transition">
                Importar ventas
              </a>
              <a href="/panel/CommissionCardsPage" className="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:brightness-95 transition">
                Ver comisiones
              </a>
            </div>
          </div>
        </div>
      </section>

      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 className="text-2xl font-bold text-gray-800 mb-6">Modulos</h2>

        <div className="space-y-10">
          {moduleSections.map((section) => (
            <div key={section.title}>
              <h3 className="mb-4 text-lg font-semibold text-gray-700">{section.title}</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                {section.items.map((item) => (
                  <ModuleCard key={item.to} {...item} />
                ))}
              </div>
            </div>
          ))}

          {canViewVisualizaciones && (
            <div>
              <h3 className="mb-4 text-lg font-semibold text-gray-700">Analitica</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <ModuleCard title="Visualizaciones" to="/visualizaciones" description="Tableros ejecutivos, cierre de caja e indicadores diarios" />
              </div>
            </div>
          )}
        </div>
      </section>

      <div className="h-24" />
    </div>
  );
}
