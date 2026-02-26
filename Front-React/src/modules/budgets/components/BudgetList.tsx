import type { Budget } from '../types';

type Props = {
  budgets: Budget[];
  loading: boolean;
  onEdit: (b: Budget) => void;
  onDelete: (id?: number) => void;
  onClose?: (id?: number) => void;
};

export default function BudgetList({ budgets, loading, onEdit, onDelete , onClose  }: Props) {
  if (loading) return <div className="text-gray-500">Cargando…</div>;
  if (!Array.isArray(budgets) || budgets.length === 0) return <div className="text-sm text-gray-500">No hay presupuestos.</div>;

  return (
    <div className="space-y-3">
      {budgets.map(b => (
        <div key={b.id} className="bg-white p-3 rounded shadow flex justify-between items-center">
          <div>
            <div className="font-medium">{b.name ?? b.month}</div>
            <div className="text-sm text-gray-600">{b.start_date ?? b.start} → {b.end_date ?? b.end}</div>
          </div>

          <div className="flex items-center gap-3">
            <div className="text-sm text-gray-700 font-semibold">${b.target_amount ?? b.amount ?? 0}</div>
            <div className="text-sm text-gray-500">{b.total_turns ?? ''} turnos</div>

            <button onClick={() => onEdit(b)} className="flex items-center gap-2 bg-primary text-white px-3 py-1 rounded hover:opacity-90">✏️ Editar</button>
            <button onClick={() => onDelete(b.id)} className="flex items-center gap-2 bg-gray-800 text-white px-3 py-1 rounded hover:opacity-90">🗑️ Eliminar</button>
            {!b.is_closed && (
                <button
                  onClick={() => onClose?.(b.id)}
                  className="flex items-center gap-2 bg-red-600 text-white px-3 py-1 rounded hover:opacity-90"
                >
                  🔒 Cerrar
                </button>
              )}
          </div>
        </div>
      ))}
    </div>
  );
}
