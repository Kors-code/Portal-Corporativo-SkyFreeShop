import React, { useEffect } from 'react';
import type { Budget } from '../types';

const SPANISH_MONTHS = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const pad = (n:number) => n < 10 ? `0${n}` : String(n);

type Props = {
  budget?: Budget | null;
  onSubmit: (payload: Partial<Budget>) => Promise<void>;
  onCancel: () => void;
  loading?: boolean;
};

export default function BudgetForm({ budget, onSubmit, onCancel, loading }: Props) {
  const [form, setForm] = React.useState({
    month: '',
    name: '',
    amount: '',
    total_turns: 300,
    start_date: '',
    end_date: ''
  });

  useEffect(() => {
    if (budget) {
      // si el presupuesto existe, poblar form
      setForm({
        month: budget.start_date ? `${budget.start_date.slice(0,7)}` : '',
        name: budget.name ?? '',
        amount: budget.target_amount != null ? String(budget.target_amount) : '',
        total_turns: budget.total_turns ?? 300,
        start_date: budget.start_date ?? '',
        end_date: budget.end_date ?? ''
      });
    } else {
      setForm({
        month: '',
        name: '',
        amount: '',
        total_turns: 300,
        start_date: '',
        end_date: ''
      });
    }
  }, [budget]);

  useEffect(() => {
    const m = form.month;
    if (!m) {
      setForm(f => ({ ...f, name: '', start_date: '', end_date: '' }));
      return;
    }
    const year = Number(m.slice(0,4));
    const monthIndex = Number(m.slice(5,7)) - 1;
    const monthName = SPANISH_MONTHS[monthIndex] ?? m.slice(5);
    const start = `${year}-${pad(monthIndex + 1)}-01`;
    const lastDay = new Date(year, monthIndex + 1, 0).getDate();
    const end = `${year}-${pad(monthIndex + 1)}-${pad(lastDay)}`;
    const generatedName = `Presupuesto ${monthName.charAt(0).toUpperCase() + monthName.slice(1)} ${year}`;
    setForm(f => ({ ...f, name: generatedName, start_date: start, end_date: end }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.month]);

  const submit = async () => {
    if (!form.month) return alert('Selecciona el mes');
    if (!form.amount || Number.isNaN(Number(form.amount))) return alert('Monto inválido');

    const payload: Partial<Budget> = {
      name: form.name,
      target_amount: Number(form.amount),
      total_turns: Number(form.total_turns),
      start_date: form.start_date,
      end_date: form.end_date
    };

    await onSubmit(payload);
  };

  return (
    <div className="bg-white rounded p-4 shadow">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Mes</label>
          <input type="month" value={form.month} onChange={e => setForm({ ...form, month: e.target.value })} className="border p-2 rounded" />
        </div>

        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Meta (USD)</label>
          <input type="number" placeholder="30000" value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} className="border p-2 rounded" />
        </div>

        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Turnos totales</label>
          <input type="number" value={form.total_turns} onChange={e => setForm({ ...form, total_turns: Number(e.target.value) })} className="border p-2 rounded" />
        </div>

        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Nombre (generado)</label>
          <input type="text" value={form.name} readOnly className="border p-2 rounded bg-gray-50" />
        </div>

        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Fecha inicio</label>
          <input type="date" value={form.start_date} readOnly className="border p-2 rounded bg-gray-50" />
        </div>

        <div className="flex flex-col">
          <label className="text-xs text-gray-600 mb-1">Fecha fin</label>
          <input type="date" value={form.end_date} readOnly className="border p-2 rounded bg-gray-50" />
        </div>
      </div>

      <div className="flex items-center gap-3 mt-4">
        <button onClick={submit} disabled={loading} className={`px-4 py-2 rounded text-white ${loading ? 'bg-gray-400' : 'bg-indigo-600 hover:bg-indigo-700'}`}>
          {loading ? 'Guardando…' : (budget ? 'Actualizar' : 'Guardar Presupuesto')}
        </button>

        <button onClick={() => {
          setForm({
            month: '',
            name: '',
            amount: '',
            total_turns: 300,
            start_date: '',
            end_date: ''
          });
        }} className="px-3 py-2 rounded border">Limpiar</button>

        <button onClick={onCancel} className="ml-auto px-3 py-2 rounded border">Cancelar</button>
      </div>
    </div>
  );
}
