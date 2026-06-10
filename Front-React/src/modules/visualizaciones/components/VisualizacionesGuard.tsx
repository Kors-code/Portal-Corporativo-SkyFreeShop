import type { ReactNode } from "react";
import { Navigate } from "react-router-dom";
import { getCurrentUser } from "../../../auth/auth";

const allowedRoles = ["super_admin", "lider"];

export default function VisualizacionesGuard({ children }: { children: ReactNode }) {
  const user = getCurrentUser();

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (!allowedRoles.includes(user.role)) {
    return (
      <div className="rounded-2xl border border-rose-200 bg-white p-6 shadow-sm">
        <p className="text-xs font-bold uppercase tracking-wide text-rose-600">Acceso restringido</p>
        <h1 className="mt-2 text-2xl font-black text-slate-950">No tienes permiso para Visualizaciones</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">
          Este modulo esta habilitado por el momento solo para usuarios super admin y lideres.
        </p>
      </div>
    );
  }

  return <>{children}</>;
}
