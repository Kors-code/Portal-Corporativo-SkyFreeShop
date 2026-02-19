import { Navigate } from "react-router-dom";
import type { JSX } from "react/jsx-dev-runtime";

export default function ProtectedRoute({ children }: { children: JSX.Element }) {
  const user = (window as any).Laravel?.user;

  if (!user) return <Navigate to="/login" replace />;

  return children;
}
