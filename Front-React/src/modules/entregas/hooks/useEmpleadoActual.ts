import { useEffect, useState } from "react";
import type { Empleado } from "../types";
import { entregasApi } from "../services/entregasApi";

const STORAGE_KEY = "empleado_actual";

type StoredEmpleado = {
  portalUserId?: number | null;
  empleado: Empleado;
};

type EntregasCapabilities = {
  entregas_auditoria_global?: boolean;
  entregas_manage?: boolean;
};

export function useEmpleadoActual() {
  const [empleado, setEmpleado] = useState<Empleado | null>(null);
  const [user, setUser] = useState<any>(null);
  const [capabilities, setCapabilities] = useState<EntregasCapabilities>({});
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    let activo = true;

    const cargarEmpleado = async () => {
      try {
        const metaUser = document.querySelector('meta[name="laravel-user"]');
        const rawUser = metaUser?.getAttribute("content");
        const portalUser = rawUser && rawUser !== "null" ? JSON.parse(rawUser) : null;
        const portalUserId = portalUser?.id ?? null;
        if (activo) {
          setUser(portalUser);
        }
        const meta = document.querySelector('meta[name="laravel-empleado"]');
        const raw = meta?.getAttribute("content");

        if (raw && raw !== "null") {
          const empleadoMeta = JSON.parse(raw);
          if (activo) {
            setEmpleado(empleadoMeta);
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ portalUserId, empleado: empleadoMeta }));
          }
          return;
        }

        const respuesta = await entregasApi.obtenerEmpleadoActual({
          portal_user_id: portalUserId ?? undefined,
          portal_user: portalUser ?? undefined,
        });
        if (activo) {
          setUser(respuesta.user ?? portalUser);
          setCapabilities(respuesta.capabilities ?? {});
        }
        if (activo && respuesta.empleado) {
          setEmpleado(respuesta.empleado);
          localStorage.setItem(STORAGE_KEY, JSON.stringify({ portalUserId: respuesta.user?.id ?? portalUserId, empleado: respuesta.empleado }));
          return;
        }

        const local = localStorage.getItem(STORAGE_KEY);
        if (activo && local) {
          const parsed = JSON.parse(local) as StoredEmpleado | Empleado;
          const stored = "empleado" in parsed ? parsed : { portalUserId: null, empleado: parsed };
          if (!portalUserId || stored.portalUserId === portalUserId) {
            setEmpleado(stored.empleado);
          } else {
            localStorage.removeItem(STORAGE_KEY);
            setEmpleado(null);
          }
        }
      } catch (e) {
        console.error("Error leyendo empleado actual", e);
      } finally {
        if (activo) {
          setCargando(false);
        }
      }
    };

    cargarEmpleado();

    return () => {
      activo = false;
    };
  }, []);

  const setEmpleadoActual = (e: Empleado | null) => {
    setEmpleado(e);
    if (e) {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ portalUserId: e.portal_user_id ?? null, empleado: e }));
    } else {
      localStorage.removeItem(STORAGE_KEY);
    }
  };

  return { empleado, user, capabilities, setEmpleadoActual, cargando };
}

export default useEmpleadoActual;
