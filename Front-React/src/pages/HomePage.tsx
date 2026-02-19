import ModuleCard from "../components/ModuleCard";

export default function HomePage() {
  return (
    <div className="pt-20"> {/* espacio porque navbar es fixed */}
      {/* HERO */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-12">
        <div className="bg-white rounded-3xl p-8 md:p-12 shadow-sm border border-gray-200">
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
              <h1 className="text-3xl md:text-4xl font-extrabold text-primary leading-tight">
                Portal Corporativo
              </h1>
              <p className="mt-3 text-gray-600 max-w-xl">
                
              </p>
            </div>

            <div className="flex gap-3 items-center">
              <div className="hidden sm:block text-sm text-gray-500">Acciones rápidas:</div>
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

      {/* GRID DE MÓDULOS */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 className="text-2xl font-bold text-gray-800 mb-6">Módulos</h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <ModuleCard title="Configuracion de presupuestos" to="/budget" description="Crea, Configurar y  Editar" />
          <ModuleCard title="Seguimiento Asesores" to="/CommissionCardsPage" description="Resumen de ventas, KPI´s y comisiones" />
          <ModuleCard title="Historial de Importes" to="/ImportsManagerPage" description="Importa, Consulta y edita" />
          <ModuleCard title="Seguimiento Cajeros" to="/CashierAwards" description="Seguimiento de comisiones por cajero" />
          <ModuleCard title="Gestion De Usuarios" to="/users" description="Administra usuarios del sistema" />
        </div>
      </section>

      {/* small spacing */}
      <div className="h-24" />
    </div>
  );
}
