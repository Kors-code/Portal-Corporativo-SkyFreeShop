import { Link } from "react-router-dom";

export default function Topbar() {
  return (
    <header className="bg-white border-b border-gray-200">
      <div className="max-w-7xl mx-auto px-8 h-16 flex items-center justify-between">
        <Link
          to="/"
          className="text-lg font-bold tracking-wide text-[var(--primary)]"
        >
          sky free shop
        </Link>

        <nav className="flex gap-8 text-sm font-medium text-gray-700">
          <Link to="/" className="hover:text-[var(--primary)]">Inicio</Link>
          <Link to="/users" className="hover:text-[var(--primary)]">Usuarios</Link>
          <Link to="/budget" className="hover:text-[var(--primary)]">Presupuesto</Link>
          <Link to="/comissions" className="hover:text-[var(--primary)]">Comisiones</Link>
        </nav>
      </div>
    </header>
  );
}
