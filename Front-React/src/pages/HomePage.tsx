import {
  Banknote,
  BarChart3,
  BookOpen,
  Boxes,
  ClipboardList,
  FileSpreadsheet,
  HeartHandshake,
  Import,
  PackageSearch,
  ShieldCheck,
  Target,
  Users,
  WalletCards,
} from "lucide-react";
import ModuleCard from "../components/ModuleCard";
import { getCurrentUser, hasAnyPermission } from "../auth/auth";

const accents = {
  commercial: "from-rose-700 to-slate-900",
  inventory: "from-emerald-600 to-cyan-700",
  people: "from-indigo-600 to-slate-800",
  accounting: "from-amber-500 to-teal-700",
  delivery: "from-sky-600 to-rose-700",
  analytics: "from-violet-600 to-emerald-600",
};

export default function HomePage() {
  const currentUser = getCurrentUser();
  const canViewVisualizaciones = ["super_admin", "lider"].includes(currentUser?.role);
  const moduleSections = [
    {
      title: "Comercial y presupuesto",
      description: "Metas, ventas, comisiones e importacion comercial.",
      accent: accents.commercial,
      items: [
        { title: "Presupuesto", to: "/budget", description: "Crea, configura y edita presupuestos por periodo.", Icon: FileSpreadsheet, permissions: ["budget.admin.view"] },
        { title: "Seguimiento asesores", to: "/CommissionCardsPage", description: "Resumen de ventas, KPI y comisiones.", Icon: Target, permissions: ["budget.commissions.view"] },
        { title: "Seguimiento cajeros", to: "/CashierAwards", description: "Premios y comisiones por cajero.", Icon: WalletCards, permissions: ["budget.cashier.view"] },
        { title: "Comisiones lideres", to: "/commissions/CommissionLeadersPage", description: "Cumplimiento y comisiones de liderazgo.", Icon: HeartHandshake, permissions: ["budget.leader.view"] },
        { title: "Asesores especializados", to: "/commissions/DualCommissionAdmin", description: "Distribucion y seguimiento por categoria.", Icon: Users, permissions: ["budget.commissions.manage"] },
        { title: "Importaciones de ventas", to: "/ImportsManagerPage", description: "Carga, consulta y correccion de archivos.", Icon: Import, permissions: ["imports.create"] },
      ],
    },
    {
      title: "Inventario",
      description: "Cobertura, alertas, catalogo y procesos de stock.",
      accent: accents.inventory,
      items: [
        { title: "Cobertura de inventario", to: "/inventarios/cobertura", description: "Dias disponibles, riesgo y sugeridos por SKU.", Icon: Boxes, permissions: ["inventarios.cobertura"] },
        { title: "Alertas de inventario", to: "/inventarios/alertas", description: "Listas de seguimiento y notificaciones.", Icon: PackageSearch, permissions: ["inventarios.alertas"] },
        { title: "Importar inventario", to: "/InventoryImportsManagerPage", description: "Historico y carga de movimientos de inventario.", Icon: Import, permissions: ["inventarios.importes"] },
      ],
    },
    {
      title: "Operacion interna",
      description: "Actas, equipo, permisos y solicitudes.",
      accent: accents.delivery,
      items: [
        { title: "Minutas de entrega", to: "/entregas", description: "Recibe, crea y consulta actas de turno.", Icon: ClipboardList, permissions: ["entregas.view"] },
        { title: "Gestion de personal", to: "/users", description: "Administra personal y accesos del sistema.", Icon: Users, permissions: ["users.view"] },
        { title: "Permisos", to: "/AdminPermissionsPanel", description: "Roles, modulos y autorizaciones del portal.", Icon: ShieldCheck, permissions: ["permissions.view"] },
        { title: "Wish List", to: "/CatalogMatchPage", description: "Solicitudes y catalogo de productos.", Icon: HeartHandshake, permissions: ["wishlist.view"] },
        { title: "Info asesores", to: "/info-asesores", description: "Material informativo por proveedor desde OneDrive.", Icon: BookOpen, permissions: ["advisor-info.view"] },
      ],
    },
    {
      title: "Contabilidad",
      description: "Bancos, conciliacion y archivos contables.",
      accent: accents.accounting,
      items: [
        { title: "Bancos", to: "/BankImportsManagerPage", description: "Historial de importaciones bancarias.", Icon: Banknote, permissions: ["accounting.bank-imports.view"] },
        { title: "Movimientos bancarios", to: "/BankMovementsPage", description: "Filtros por banco, dias y descarga CSV.", Icon: WalletCards, permissions: ["accounting.bank-imports.view"] },
        { title: "Conversor Davibank", to: "/davibank-converter", description: "Convierte extractos al formato operativo.", Icon: FileSpreadsheet, permissions: ["accounting.bank-imports.create"] },
      ],
    },
  ];
  const visibleSections = moduleSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => hasAnyPermission(item.permissions)),
    }))
    .filter((section) => section.items.length > 0);
  const visibleItemCount = visibleSections.reduce((total, section) => total + section.items.length, 0);

  return (
    <div className="space-y-10 pb-12">
      <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div className="grid gap-0 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="p-6 sm:p-8 lg:p-10">
            <p className="mb-3 text-sm font-semibold uppercase tracking-wide text-primary">Panel corporativo</p>
            <h1 className="max-w-3xl text-3xl font-black leading-tight text-slate-950 sm:text-4xl">
              Modulos de Sky Free Shop
            </h1>
            <p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">
              Accede a cada area desde un solo lugar. La navegacion de cada modulo se adapta para mostrar solo las opciones que corresponden.
            </p>

            <div className="mt-7 flex flex-wrap gap-3">
              {hasAnyPermission(["budget.admin.view"]) && (
                <a href="/panel/budget" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:brightness-95">
                  <FileSpreadsheet className="h-4 w-4" />
                  Presupuesto
                </a>
              )}
              {hasAnyPermission(["inventarios.cobertura"]) && (
                <a href="/panel/inventarios/cobertura" className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-800 transition hover:bg-slate-100">
                  <Boxes className="h-4 w-4" />
                  Inventario
                </a>
              )}
              {hasAnyPermission(["entregas.view"]) && (
                <a href="/panel/entregas" className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-800 transition hover:bg-slate-100">
                  <ClipboardList className="h-4 w-4" />
                  Entregas
                </a>
              )}
            </div>
          </div>

          <div className="border-t border-slate-200 bg-slate-950 p-6 text-white lg:border-l lg:border-t-0 sm:p-8 lg:p-10">
            <div className="grid h-full content-between gap-8">
              <div>
                <p className="text-sm font-semibold text-slate-300">Sesion activa</p>
                <p className="mt-2 text-2xl font-black">{currentUser?.name || "Usuario Sky"}</p>
                <p className="mt-1 text-sm text-slate-400">{currentUser?.role || "Portal operativo"}</p>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-lg bg-white/10 p-4">
                  <p className="text-2xl font-black">{visibleSections.length + (canViewVisualizaciones ? 1 : 0)}</p>
                  <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-300">Areas</p>
                </div>
                <div className="rounded-lg bg-white/10 p-4">
                  <p className="text-2xl font-black">
                    {visibleItemCount + (canViewVisualizaciones ? 1 : 0)}
                  </p>
                  <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-300">Accesos</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div className="space-y-9">
        {visibleSections.map((section) => (
          <section key={section.title}>
            <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <h2 className="text-xl font-black text-slate-900">{section.title}</h2>
                <p className="mt-1 text-sm text-slate-600">{section.description}</p>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {section.items.map((item) => (
                <ModuleCard key={item.to} {...item} eyebrow={section.title} accent={section.accent} />
              ))}
            </div>
          </section>
        ))}

        {canViewVisualizaciones && (
          <section>
            <div className="mb-4">
              <h2 className="text-xl font-black text-slate-900">Analitica</h2>
              <p className="mt-1 text-sm text-slate-600">Tableros ejecutivos y reportes visuales.</p>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <ModuleCard
                title="Visualizaciones"
                to="/visualizaciones"
                description="Cierre de caja, ventas por tienda e indicadores diarios."
                eyebrow="Analitica"
                accent={accents.analytics}
                Icon={BarChart3}
              />
            </div>
          </section>
        )}
      </div>
    </div>
  );
}
