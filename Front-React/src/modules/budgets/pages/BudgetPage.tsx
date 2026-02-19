import  { useState } from 'react';
import useBudgets from '../../../hooks/useBudgets';
import BudgetForm from '../components/BudgetForm';
import BudgetList from '../components/BudgetList';
import type { Budget } from '../types';
import { useNavigate } from 'react-router-dom';

export default function BudgetsPage() {
  const { items: budgets, loading, saving,  activeInfo, create, update, remove } = useBudgets();
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Budget | null>(null);

  const openCreate = () => { setEditing(null); setShowForm(true); };
  const openEdit = (b: Budget) => { setEditing(b); setShowForm(true); };
  const navigate = useNavigate();

  const handleSubmit = async (payload: Partial<Budget>) => {
    try {
      if (editing && editing.id) {
        await update(editing.id, payload);
      } else {
        await create(payload);
      }
      setShowForm(false);
      setEditing(null);
    } catch (err: any) {
      alert(err.message || 'Error guardando');
    }
  };

  const handleDelete = async (id?: number) => {
    if (!id) return;
    if (!confirm('¿Seguro que deseas eliminar este presupuesto?')) return;
    try {
      await remove(id);
    } catch (err: any) {
      console.error(err);
      alert('Error eliminando');
    }
  };

  return (
    <div className="min-h-screen bg-gray-100 p-6">
      <header className="bg-primary text-white px-6 py-6 rounded-lg shadow mb-6">
        <h1 className="text-3xl font-bold">Gestión de Presupuestos</h1>
      </header>

      <div className="max-w-4xl mx-auto">
        <div className="flex items-center justify-between mb-4">
          <button onClick={openCreate} className="bg-primary text-white px-4 py-2 rounded-xl">+ Nuevo Presupuesto</button>
          <button
            onClick={() => navigate('/commissions/categories')}
            className="bg-primary text-white px-4 py-2 rounded-xl"
          >
            Modificar Participación categorias
          </button>          {activeInfo && activeInfo.budget && (
            <div className="text-sm text-gray-700">
              Activo: <strong>{activeInfo.budget.name}</strong> — {activeInfo.sales_total ?? 0} ({activeInfo.compliance_pct ?? 0}%)
            </div>
          )}
        </div>

        <div className="bg-white rounded-2xl shadow p-6 mb-6">
          <h2 className="text-2xl font-bold mb-4">Lista de Presupuestos</h2>
          <BudgetList budgets={budgets} loading={loading} onEdit={openEdit} onDelete={handleDelete} />
        </div>

        {showForm && (
          <div className="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
            <div className="absolute inset-0 bg-black/30" onClick={() => { setShowForm(false); setEditing(null); }} />
            <div className="relative w-full max-w-2xl bg-white rounded-2xl p-6 shadow-lg z-10">
              <BudgetForm budget={editing} onSubmit={handleSubmit} onCancel={() => { setShowForm(false); setEditing(null); }} loading={saving} />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
