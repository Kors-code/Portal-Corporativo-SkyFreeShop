import { Link, NavLink } from "react-router-dom";
import { useState, useEffect } from "react";
/**
 * Navbar:
 * - Desktop: links horizontales + user area
 * - Mobile: hamburger -> panel deslizable
 * - Usa tus clases de color (.text-primary / .bg-primary)
 */

export default function Navbar() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const navItems = [
    { label: "Comisiones Cajeros", to: "CashierAwards" },
    { label: "Comisiones", to: "/CommissionCardsPage" },
    { label: "Presupuesto", to: "/budget" },
    { label: "Importarciones", to: "/ImportsManagerPage" },
    { label: "Pct Categorias", to: "/commissions/categories" },
  ];

  return (
    
    <header
      className={`fixed w-full z-50 transition-shadow bg-white h-16 md:h-20${
        scrolled ? "shadow-md" : "shadow-sm"
      }`}
    >
      

      
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="h-16 flex items-center justify-between">
          {/* Brand */}
          <Link to="/" className="flex items-center gap-3">
            <img
              src="/logo3.png"
              alt="Sky Free Shop"
              className="h-16 w-auto object-contain"
            />
            
          </Link>


          {/* Desktop nav */}
          <nav className="hidden md:flex items-center gap-6">
            {navItems.map((n) => (
              <NavLink
                key={n.to}
                to={n.to}
                className={({ isActive }) =>
                  `text-sm font-medium px-2 py-1 rounded ${
                    isActive ? "bg-primary/10 text-primary" : "text-gray-700 hover:text-primary"
                  } transition`
                }
              >
                {n.label}
              </NavLink>
            ))}

            {/* CTA / user placeholder */}
            <a
              href="https://skyfreeshopdutyfree.com/welcome"
              className="ml-4 inline-flex items-center gap-2 px-3 py-2 rounded-md border border-gray-200 text-sm font-medium hover:bg-primary/5 transition"
            >
              Inicio
            </a>
          </nav>

          {/* Mobile controls */}
          <div className="md:hidden flex items-center">
            <button
              onClick={() => setOpen((s) => !s)}
              aria-label="Abrir menú"
              className="p-2 rounded-md text-gray-700 hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                <path d={open ? "M6 18L18 6M6 6l12 12" : "M3 12h18M3 6h18M3 18h18"} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      {/* Mobile panel */}
      <div
        className={`md:hidden transform transition-all duration-300 origin-top ${
          open ? "max-h-screen opacity-100" : "max-h-0 opacity-0 pointer-events-none"
        }`}
      >
        <div className="px-4 pb-6 pt-2 space-y-3 bg-white border-t border-gray-100">
          {navItems.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              onClick={() => setOpen(false)}
              className={({ isActive }) =>
                `block rounded px-3 py-2 text-base font-medium ${
                  isActive ? "bg-primary/10 text-primary" : "text-gray-700 hover:text-primary"
                }`
              }
            >
              {n.label}
            </NavLink>
          ))}

          
        </div>
      </div>
    </header>
  );
}
