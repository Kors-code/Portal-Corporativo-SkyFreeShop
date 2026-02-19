import { Link } from "react-router-dom";

export default function ModuleCard({ title, to, description }: { title: string; to: string; description?: string }) {
  return (
    <Link
      to={to}
      className="relative group block bg-white border border-gray-200 rounded-2xl p-6 h-44 md:h-52 overflow-hidden shadow-sm hover:shadow-2xl transition transform hover:-translate-y-1"
    >
      {/* Accent bar */}
      <div className="absolute left-0 top-0 h-full w-2 bg-primary rounded-l-xl opacity-90"></div>

      <div className="relative z-10 h-full flex flex-col justify-between">
        <div>
          <h3 className="text-lg font-semibold text-gray-800">{title}</h3>
          {description && <p className="mt-2 text-sm text-gray-500">{description}</p>}
        </div>

        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-primary">Abrir módulo →</span>

          {/* small decorative circle that animates */}
          <div className="w-9 h-9 rounded-lg bg-primary/10 flex items-center justify-center group-hover:scale-105 transition-transform">
            <div className="w-3 h-3 rounded-full bg-primary" />
          </div>
        </div>
      </div>
    </Link>
  );
}
