import { useEffect, useState } from 'react';
import type { Empleado } from '../types';

/**
 * Hook para obtener el empleado actual.
 *
 * ⚠️ ADAPTAR a tu sistema de auth.
 * Por defecto lee de localStorage `empleado_actual` con shape Empleado.
 *
 * Si usas Context, reemplaza el cuerpo por:
 *   const { user } = useContext(AuthContext);
 *   return { empleado: user };
 */
export function useEmpleadoActual() {
  const [empleado, setEmpleado] = useState<Empleado | null>(null);

  useEffect(() => {
    try {
      const raw = localStorage.getItem('empleado_actual');
      if (raw) {
        setEmpleado(JSON.parse(raw));
      }
    } catch (e) {
      console.error('Error parseando empleado_actual', e);
    }
  }, []);

  const setEmpleadoActual = (e: Empleado | null) => {
    setEmpleado(e);
    if (e) {
      localStorage.setItem('empleado_actual', JSON.stringify(e));
    } else {
      localStorage.removeItem('empleado_actual');
    }
  };

  return { empleado, setEmpleadoActual };
}

export default useEmpleadoActual;
