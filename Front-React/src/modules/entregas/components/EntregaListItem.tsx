import type { Entrega, EstadoEntrega } from '../types';

type EntregaListItemProps = {
  entrega: Entrega;
  empleadoId: number;
  onClick: () => void;
  variant?: 'card' | 'compact';
};

const ESTADO_COLORS: Record<EstadoEntrega, string> = {
  abierta: 'bg-yellow-500',
  entregada: 'bg-blue-500',
  recibida: 'bg-purple-500',
  completada: 'bg-green-600',
  rechazada: 'bg-red-600',
};

const ESTADO_LABELS: Record<EstadoEntrega, string> = {
  abierta: 'Abierta',
  entregada: 'Firmada',
  recibida: 'Recibida',
  completada: 'Completada',
  rechazada: 'Rechazada',
};

export default function EntregaListItem({
  entrega,
  empleadoId,
  onClick,
  variant = 'card',
}: EntregaListItemProps) {
  const esEntrega = entrega.lider_entrega_id === empleadoId;
  const otroLider = esEntrega ? entrega.lider_recibe : entrega.lider_entrega;

  if (variant === 'compact') {
    return (
      <div
        onClick={onClick}
        className="bg-white rounded-md p-3 shadow-sm cursor-pointer hover:shadow-md transition border border-gray-100"
      >
        <div className="flex justify-between items-start">
          <div>
            <div className="font-medium text-gray-900">{entrega.codigo_acta}</div>
            <div className="text-xs text-gray-500">
              {esEntrega ? '📤 Para: ' : '📥 De: '}
              <strong>{otroLider?.colaborador}</strong>
            </div>
          </div>
          <span className={`px-2 py-1 rounded-full text-xs font-bold text-white ${ESTADO_COLORS[entrega.estado]}`}>
            {ESTADO_LABELS[entrega.estado]}
          </span>
        </div>
      </div>
    );
  }

  return (
    <div
      onClick={onClick}
      className="bg-white shadow-md rounded-lg p-4 hover:shadow-xl transform hover:-translate-y-1 transition-all duration-200 cursor-pointer flex items-center gap-4"
    >
      <div className="text-3xl flex-shrink-0">
        {esEntrega ? '📤' : '📥'}
      </div>

      <div className="flex-1 min-w-0">
        <div className="text-sm font-bold text-indigo-600">{entrega.codigo_acta}</div>
        <div className="text-sm text-gray-700 truncate">
          {esEntrega ? 'Para: ' : 'De: '}
          <strong>{otroLider?.colaborador}</strong>
        </div>
        <div className="text-xs text-gray-400 mt-1">
          {new Date(entrega.created_at).toLocaleDateString('es', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
          })}
          {' · '}
          {entrega.novedades?.length ?? 0} novedades
        </div>
      </div>

      <span className={`px-3 py-1.5 rounded-full text-xs font-bold text-white uppercase ${ESTADO_COLORS[entrega.estado]}`}>
        {ESTADO_LABELS[entrega.estado]}
      </span>
    </div>
  );
}
