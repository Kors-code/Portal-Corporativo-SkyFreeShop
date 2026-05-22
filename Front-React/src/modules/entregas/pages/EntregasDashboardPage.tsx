import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import entregasApi from '../services/entregasApi';
import useEmpleadoActual from '../hooks/useEmpleadoActual';
import StatBox from '../components/StatBox';
import EntregaListItem from '../components/EntregaListItem';
import type { DashboardStats, Entrega } from '../types';

export default function EntregasDashboardPage() {
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();

  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [recientes, setRecientes] = useState<Entrega[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (empleado?.id) {
      load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [empleado?.id]);

  const load = async () => {
    if (!empleado?.id) return;
    setLoading(true);
    try {
      const data = await entregasApi.obtenerDashboard(empleado.id);
      setStats(data.stats);
      setRecientes(data.recientes ?? []);
    } catch (e) {
      console.error('Error cargando dashboard', e);
    } finally {
      setLoading(false);
    }
  };

  if (!empleado) {
    return (
      <div className="p-6 text-center text-gray-600">
        Debes iniciar sesión para ver el panel de entregas.
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl sm:text-3xl font-bold text-gray-800">📋 Sistema de Entregas</h1>
        <p className="text-sm text-gray-500 mt-1">
          Hola <strong>{empleado.colaborador}</strong>, ¿qué deseas hacer hoy?
        </p>
      </div>

      {/* Acciones principales */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div
          onClick={() => navigate('/entregas/nuevo')}
          className="relative cursor-pointer p-6 rounded-xl shadow-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-white hover:shadow-2xl hover:-translate-y-1 transition-all"
        >
          <div className="text-4xl mb-2">📤</div>
          <h2 className="text-xl font-bold mb-1">Entregar Novedades</h2>
          <p className="text-sm opacity-90 mb-3">
            Crea una nueva acta para entregar tu turno con todas las novedades.
          </p>
          <button className="px-4 py-2 bg-white/20 hover:bg-white/30 border-2 border-white/40 rounded-md text-sm font-semibold">
            + Nueva Acta
          </button>
        </div>

        <div
          onClick={() => navigate('/entregas/recibir')}
          className="relative cursor-pointer p-6 rounded-xl shadow-lg bg-gradient-to-br from-green-500 to-green-700 text-white hover:shadow-2xl hover:-translate-y-1 transition-all"
        >
          <div className="text-4xl mb-2">📥</div>
          <h2 className="text-xl font-bold mb-1">Recibir Novedades</h2>
          <p className="text-sm opacity-90 mb-3">
            Revisa y firma las actas que otros líderes te han enviado.
          </p>
          {stats && stats.recibidas_pendientes > 0 && (
            <div className="absolute top-4 right-4 bg-white text-red-600 px-2 py-1 rounded-full text-xs font-bold">
              {stats.recibidas_pendientes} pendiente{stats.recibidas_pendientes !== 1 ? 's' : ''}
            </div>
          )}
          <button className="px-4 py-2 bg-white/20 hover:bg-white/30 border-2 border-white/40 rounded-md text-sm font-semibold">
            Ver Pendientes
          </button>
        </div>
      </div>

      {/* Estadísticas */}
      <div className="flex gap-3 overflow-x-auto pb-2 mb-6">
        <StatBox label="Entregas realizadas" value={stats?.entregas_realizadas ?? 0} variant="dark" />
        <StatBox label="Completadas" value={stats?.entregas_completadas ?? 0} variant="success" />
        <StatBox label="Pendientes firma" value={stats?.entregas_pendientes_firma ?? 0} variant="warning" />
        <StatBox label="Por recibir" value={stats?.recibidas_pendientes ?? 0} variant="danger" />
        <StatBox label="Recibidas" value={stats?.recibidas_completadas ?? 0} variant="dark" />
      </div>

      {/* Recientes */}
      <div className="bg-white rounded-lg shadow p-5">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-800">🕐 Actas Recientes</h2>
          <button
            onClick={() => navigate('/entregas/historial')}
            className="text-sm text-indigo-600 hover:text-indigo-800 font-medium"
          >
            Ver historial →
          </button>
        </div>

        {loading ? (
          <div className="text-center py-10 text-gray-500">Cargando…</div>
        ) : recientes.length === 0 ? (
          <div className="text-center py-10 text-gray-400">
            <div className="text-4xl mb-2">📭</div>
            <p>No hay actas registradas aún</p>
          </div>
        ) : (
          <div className="space-y-3">
            {recientes.map(e => (
              <EntregaListItem
                key={e.id}
                entrega={e}
                empleadoId={empleado.id}
                onClick={() => navigate(`/entregas/${e.id}`)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
