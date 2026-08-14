import { NavLink, useLocation } from "react-router-dom";
import { useEffect, useMemo, useState } from "react";
import {
  Banknote,
  BarChart3,
  Boxes,
  ClipboardList,
  FileSpreadsheet,
  Home,
  Menu,
  Settings,
  ShieldCheck,
  Users,
  X,
} from "lucide-react";
import { hasAnyPermission } from "../auth/auth";

type NavItem = {
  label: string;
  to: string;
  permissions?: string[];
};

type NavContext = {
  name: string;
  eyebrow: string;
  Icon: typeof Home;
  items: NavItem[];
};

const budgetNav: NavItem[] = [
  { label: "Presupuestos", to: "/budget", permissions: ["budget.admin.view"] },
  { label: "Ventas asesores", to: "/CommissionCardsPage", permissions: ["budget.commissions.view"] },
  { label: "Cajeros", to: "/CashierAwards", permissions: ["budget.cashier.view"] },
  { label: "Categorias", to: "/commissions/categories", permissions: ["budget.commissions.manage"] },
  { label: "Especializados", to: "/commissions/DualCommissionAdmin", permissions: ["budget.commissions.manage"] },
  { label: "Lideres", to: "/commissions/CommissionLeadersPage", permissions: ["budget.leader.view"] },
];

const inventoryNav: NavItem[] = [
  { label: "Cobertura", to: "/inventarios/cobertura", permissions: ["inventarios.cobertura"] },
  { label: "Alertas", to: "/inventarios/alertas", permissions: ["inventarios.alertas"] },
  { label: "Dashboard", to: "/InventoryDashboard", permissions: ["inventarios.cobertura"] },
  { label: "Dashboard Pro", to: "/InventoryDashboardPro", permissions: ["inventarios.cobertura"] },
  { label: "Importar inventario", to: "/InventoryImportsManagerPage", permissions: ["inventarios.importes"] },
  { label: "Catalogo", to: "/CatalogImportCard", permissions: ["inventarios.importes"] },
  { label: "Metricas", to: "/InventoryMetricsRunner", permissions: ["inventarios.importes"] },
];

const entregaNav: NavItem[] = [
  { label: "Panel entregas", to: "/entregas", permissions: ["entregas.view"] },
  { label: "Nueva acta", to: "/entregas/nuevo", permissions: ["entregas.manage"] },
  { label: "Recibir", to: "/entregas/recibir", permissions: ["entregas.view"] },
  { label: "Activas", to: "/entregas/activas", permissions: ["entregas.view"] },
  { label: "Listado", to: "/entregas/listado", permissions: ["entregas.view"] },
];

const accountingNav: NavItem[] = [
  { label: "Bancos", to: "/BankImportsManagerPage", permissions: ["accounting.bank-imports.view"] },
  { label: "Movimientos", to: "/BankMovementsPage", permissions: ["accounting.bank-imports.view"] },
  { label: "Davibank", to: "/davibank-converter", permissions: ["accounting.bank-imports.create"] },
];

const peopleNav: NavItem[] = [
  { label: "Personal", to: "/users", permissions: ["users.view"] },
  { label: "Permisos", to: "/AdminPermissionsPanel", permissions: ["permissions.view"] },
  { label: "Wish List", to: "/CatalogMatchPage", permissions: ["wishlist.view"] },
  { label: "Admin Wish List", to: "/AdminWishList", permissions: ["wishlist.manage"] },
];

const analyticsNav: NavItem[] = [
  { label: "Hub", to: "/visualizaciones", permissions: ["visualizations.view"] },
  { label: "Cierre caja", to: "/visualizaciones/cierre-caja", permissions: ["visualizations.view"] },
  { label: "Ventas tiendas", to: "/visualizaciones/ventas-tiendas", permissions: ["visualizations.view"] },
  { label: "Ventas asesores", to: "/visualizaciones/ventas-asesores", permissions: ["visualizations.view"] },
];

function resolveContext(pathname: string): NavContext {
  if (
    pathname.startsWith("/inventarios") ||
    pathname.startsWith("/Inventory") ||
    pathname === "/CatalogImportCard"
  ) {
    return { name: "Inventario", eyebrow: "Operaciones", Icon: Boxes, items: inventoryNav };
  }

  if (pathname.startsWith("/entregas") || pathname.includes("Entrega")) {
    return { name: "Minutas de entrega", eyebrow: "Turnos", Icon: ClipboardList, items: entregaNav };
  }

  if (pathname.startsWith("/Bank") || pathname === "/davibank-converter") {
    return { name: "Contabilidad", eyebrow: "Bancos", Icon: Banknote, items: accountingNav };
  }

  if (
    pathname.startsWith("/users") ||
    pathname === "/AdminPermissionsPanel" ||
    pathname.includes("WishList") ||
    pathname.includes("CatalogMatch")
  ) {
    return { name: "Personal", eyebrow: "Equipo", Icon: Users, items: peopleNav };
  }

  if (pathname.startsWith("/visualizaciones")) {
    return { name: "Analitica", eyebrow: "Tableros", Icon: BarChart3, items: analyticsNav };
  }

  if (
    pathname.startsWith("/commissions") ||
    pathname.startsWith("/Commission") ||
    pathname.startsWith("/Cashier") ||
    pathname === "/budget" ||
    pathname === "/importCatalog"
  ) {
    return { name: "Presupuesto", eyebrow: "Comercial", Icon: FileSpreadsheet, items: budgetNav };
  }

  return {
    name: "Portal",
    eyebrow: "Sky Free Shop",
    Icon: ShieldCheck,
    items: [
      { label: "Presupuesto", to: "/budget", permissions: ["budget.admin.view"] },
      { label: "Inventario", to: "/inventarios/cobertura", permissions: ["inventarios.cobertura"] },
      { label: "Entregas", to: "/entregas", permissions: ["entregas.view"] },
      { label: "Bancos", to: "/BankImportsManagerPage", permissions: ["accounting.bank-imports.view"] },
      { label: "Personal", to: "/users", permissions: ["users.view"] },
    ],
  };
}

export default function Navbar() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const location = useLocation();
  const context = useMemo(() => resolveContext(location.pathname), [location.pathname]);
  const visibleItems = useMemo(
    () => context.items.filter((item) => hasAnyPermission(item.permissions)),
    [context.items]
  );
  const ContextIcon = context.Icon;

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", onScroll);
    onScroll();
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => setOpen(false), [location.pathname]);

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `inline-flex h-9 items-center rounded-md px-3 text-sm font-semibold transition ${
      isActive ? "bg-primary text-white shadow-sm" : "text-slate-700 hover:bg-slate-100 hover:text-primary"
    }`;

  return (
    <header className={`fixed inset-x-0 top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur transition-shadow ${scrolled ? "shadow-md" : "shadow-sm"}`}>
      <div className="mx-auto flex h-[72px] max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <a href="/welcome" className="flex min-w-0 items-center gap-3" aria-label="Ir a bienvenida">
          <img src="/logo3.png" alt="Sky Free Shop" className="h-12 w-auto shrink-0 object-contain" />
          <span className="hidden min-w-0 border-l border-slate-200 pl-3 sm:block">
            <span className="block text-xs font-semibold uppercase tracking-wide text-slate-500">{context.eyebrow}</span>
            <span className="flex items-center gap-2 text-base font-bold text-slate-900">
              <ContextIcon className="h-4 w-4 text-primary" />
              {context.name}
            </span>
          </span>
        </a>

        <nav className="hidden max-w-4xl flex-1 items-center justify-end gap-1 overflow-x-auto lg:flex">
          <NavLink to="/" end className={navLinkClass}>
            <Home className="mr-2 h-4 w-4" />
            Panel
          </NavLink>
          {visibleItems.map((item) => (
            <NavLink key={item.to} to={item.to} className={navLinkClass}>
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="flex items-center gap-2 lg:hidden">
          <NavLink to="/" end className="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-700 hover:bg-slate-100" aria-label="Panel">
            <Home className="h-5 w-5" />
          </NavLink>
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-700 hover:bg-slate-100"
            aria-label={open ? "Cerrar menu" : "Abrir menu"}
          >
            {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </div>

      <div className={`lg:hidden overflow-hidden border-t border-slate-100 bg-white transition-all ${open ? "max-h-96 opacity-100" : "max-h-0 opacity-0"}`}>
        <div className="space-y-1 px-4 py-4">
          <div className="mb-3 flex items-center gap-2 px-2 text-sm font-bold text-slate-900">
            <Settings className="h-4 w-4 text-primary" />
            {context.name}
          </div>
          {visibleItems.map((item) => (
            <NavLink key={item.to} to={item.to} className={({ isActive }) => `block rounded-md px-3 py-2 text-sm font-semibold ${isActive ? "bg-primary text-white" : "text-slate-700 hover:bg-slate-100"}`}>
              {item.label}
            </NavLink>
          ))}
        </div>
      </div>
    </header>
  );
}
