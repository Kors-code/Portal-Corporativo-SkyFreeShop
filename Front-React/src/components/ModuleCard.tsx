import type { ComponentType } from "react";
import { Link } from "react-router-dom";
import { ArrowRight } from "lucide-react";

type ModuleCardProps = {
  title: string;
  to: string;
  description?: string;
  eyebrow?: string;
  accent?: string;
  Icon?: ComponentType<{ className?: string }>;
};

export default function ModuleCard({
  title,
  to,
  description,
  eyebrow,
  accent = "from-rose-700 to-slate-900",
  Icon,
}: ModuleCardProps) {
  return (
    <Link
      to={to}
      className="group relative block h-full min-h-48 overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-slate-300 hover:shadow-xl"
    >
      <div className={`absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r ${accent}`} />

      <div className="relative z-10 flex h-full flex-col justify-between gap-6">
        <div>
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              {eyebrow && <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{eyebrow}</p>}
              <h3 className="text-lg font-bold leading-snug text-slate-900">{title}</h3>
            </div>
            {Icon && (
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 transition group-hover:bg-primary group-hover:text-white">
                <Icon className="h-5 w-5" />
              </span>
            )}
          </div>
          {description && <p className="text-sm leading-6 text-slate-600">{description}</p>}
        </div>

        <div className="flex items-center justify-between border-t border-slate-100 pt-4">
          <span className="text-sm font-semibold text-primary">Abrir modulo</span>
          <ArrowRight className="h-4 w-4 text-primary transition group-hover:translate-x-1" />
        </div>
      </div>
    </Link>
  );
}
