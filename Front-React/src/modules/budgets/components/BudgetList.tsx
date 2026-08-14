import type { Budget } from '../types';

type Props = {
  budgets: Budget[];
  loading: boolean;
  onEdit: (budget: Budget) => void;
  onDelete: (id?: number) => void;
  onClose?: (id?: number) => void;
  canManage?: boolean;
};

export default function BudgetList({
  budgets,
  loading,
  onEdit,
  onDelete,
  onClose,
  canManage = false,
}: Props) {
  if (loading) return <div className="text-gray-500">Cargando...</div>;
  if (!Array.isArray(budgets) || budgets.length === 0) {
    return <div className="text-sm text-gray-500">No hay presupuestos.</div>;
  }

  return (
    <div className="space-y-3">
      {budgets.map((budget) => (
        <div key={budget.id} className="bg-white p-3 rounded shadow flex justify-between items-center">
          <div>
            <div className="font-medium">{budget.name ?? budget.month}</div>
            <div className="text-sm text-gray-600">{budget.start_date ?? budget.start} - {budget.end_date ?? budget.end}</div>
          </div>

          <div className="flex items-center gap-3">
            <div className="text-sm text-gray-700 font-semibold">${budget.target_amount ?? budget.amount ?? 0}</div>
            <div className="text-sm text-gray-500">{budget.total_turns ?? ''} turnos</div>

            {canManage && (
              <>
                <button onClick={() => onEdit(budget)} className="flex items-center gap-2 bg-primary text-white px-3 py-1 rounded hover:opacity-90">Editar</button>
                <button onClick={() => onDelete(budget.id)} className="flex items-center gap-2 bg-gray-800 text-white px-3 py-1 rounded hover:opacity-90">Eliminar</button>
              </>
            )}
            {canManage && !budget.is_closed && (
              <button
                onClick={() => onClose?.(budget.id)}
                className="flex items-center gap-2 bg-red-600 text-white px-3 py-1 rounded hover:opacity-90"
              >
                Cerrar
              </button>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
