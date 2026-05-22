import { useState } from 'react';
import type { CategoriaInfo, CategoriaKey, Novedad, PrioridadInfo, PrioridadKey } from '../types';

type NovedadFormItemProps = {
  index: number;
  novedad: Novedad;
  categorias: Record<CategoriaKey, CategoriaInfo>;
  prioridades: Record<PrioridadKey, PrioridadInfo>;
  puedeEliminar: boolean;
  onChange: (campo: keyof Novedad, valor: any) => void;
  onEliminar: () => void;
};

export default function NovedadFormItem({
  index,
  novedad,
  categorias,
  prioridades,
  puedeEliminar,
  onChange,
  onEliminar,
}: NovedadFormItemProps) {
  const [mostrarOpciones, setMostrarOpciones] = useState(false);
  const catInfo = novedad.categoria ? categorias[novedad.categoria] : null;

  return (
    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-3">
      <div className="flex items-center justify-between mb-3">
        <span className="text-sm font-semibold text-gray-700">
          Novedad #{index + 1}
        </span>
        {puedeEliminar && (
          <button
            type="button"
            onClick={onEliminar}
            className="text-xs px-2 py-1 bg-white border border-red-200 text-red-600 hover:bg-red-50 rounded transition"
          >
            🗑️ Eliminar
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">
            Categoría *
          </label>
          <select
            value={novedad.categoria || ''}
            onChange={e => onChange('categoria', e.target.value as CategoriaKey)}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
          >
            <option value="">-- Selecciona --</option>
            {Object.entries(categorias).map(([key, cat]) => (
              <option key={key} value={key}>
                {cat.icon} {cat.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">
            Prioridad
          </label>
          <select
            value={novedad.prioridad}
            onChange={e => onChange('prioridad', e.target.value as PrioridadKey)}
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
          >
            {Object.entries(prioridades).map(([key, p]) => (
              <option key={key} value={key}>{p.label}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="mb-3">
        <label className="block text-xs font-semibold text-gray-600 mb-1">
          Título (opcional)
        </label>
        <input
          type="text"
          value={novedad.titulo || ''}
          onChange={e => onChange('titulo', e.target.value)}
          placeholder="Resumen breve..."
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
        />
      </div>

      <div className="mb-3">
        <div className="flex items-center justify-between mb-1">
          <label className="block text-xs font-semibold text-gray-600">
            Descripción *
          </label>
          {catInfo && (
            <button
              type="button"
              onClick={() => setMostrarOpciones(s => !s)}
              className="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
            >
              {mostrarOpciones ? '▲ Ocultar' : '▼ Ver'} opciones rápidas
            </button>
          )}
        </div>

        {mostrarOpciones && catInfo && (
          <div className="flex flex-wrap gap-1.5 p-2 bg-white border border-gray-200 rounded mb-2">
            {catInfo.opciones.map((opcion, i) => (
              <button
                key={i}
                type="button"
                onClick={() => {
                  onChange('descripcion', opcion);
                  setMostrarOpciones(false);
                }}
                className="text-xs px-2.5 py-1 bg-gray-100 hover:bg-indigo-100 hover:text-indigo-700 text-gray-700 rounded-full transition border border-gray-200"
              >
                {opcion}
              </button>
            ))}
          </div>
        )}

        <textarea
          value={novedad.descripcion}
          onChange={e => onChange('descripcion', e.target.value)}
          placeholder="Describe la novedad en detalle..."
          rows={3}
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-y"
        />
      </div>

      <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
        <input
          type="checkbox"
          checked={novedad.requiere_seguimiento}
          onChange={e => onChange('requiere_seguimiento', e.target.checked)}
          className="w-4 h-4"
        />
        ⚠️ Requiere seguimiento
      </label>
    </div>
  );
}
