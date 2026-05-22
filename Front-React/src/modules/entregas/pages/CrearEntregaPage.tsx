import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import entregasApi from '../services/entregasApi';
import useEmpleadoActual from '../hooks/useEmpleadoActual';
import NovedadFormItem from '../components/NovedadFormItem';
import type {
  CategoriaInfo,
  CategoriaKey,
  Empleado,
  Novedad,
  PrioridadInfo,
  PrioridadKey,
  TurnoKey,
} from '../types';

const NOVEDAD_INICIAL: Novedad = {
  categoria: '' as CategoriaKey,
  titulo: '',
  descripcion: '',
  prioridad: 'media',
  requiere_seguimiento: false,
};

export default function CrearEntregaPage() {
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();

  const [lideres, setLideres] = useState<Empleado[]>([]);
  const [categorias, setCategorias] = useState<Record<CategoriaKey, CategoriaInfo>>({} as any);
  const [prioridades, setPrioridades] = useState<Record<PrioridadKey, PrioridadInfo>>({} as any);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [liderRecibeId, setLiderRecibeId] = useState<number | ''>('');
  const [turno, setTurno] = useState<TurnoKey>('mañana');
  const [fechaActa, setFechaActa] = useState<string>(new Date().toISOString().split('T')[0]);
  const [sede, setSede] = useState<string>('');
  const [observaciones, setObservaciones] = useState<string>('');
  const [novedades, setNovedades] = useState<Novedad[]>([{ ...NOVEDAD_INICIAL }]);

  useEffect(() => {
    if (empleado?.sede) setSede(empleado.sede);
    cargarDatos();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const cargarDatos = async () => {
    try {
      const [lideresData, catsData] = await Promise.all([
        entregasApi.obtenerLideres(),
        entregasApi.obtenerCategorias(),
      ]);
      setLideres(lideresData.filter(l => l.id !== empleado?.id));
      setCategorias(catsData.categorias);
      setPrioridades(catsData.prioridades);
    } catch (e) {
      console.error(e);
      alert('Error cargando datos iniciales');
    } finally {
      setLoading(false);
    }
  };

  const actualizarNovedad = (index: number, campo: keyof Novedad, valor: any) => {
    const copia = [...novedades];
    copia[index] = { ...copia[index], [campo]: valor };
    setNovedades(copia);
  };

  const agregarNovedad = () => setNovedades(prev => [...prev, { ...NOVEDAD_INICIAL }]);
  const eliminarNovedad = (index: number) => {
    if (novedades.length === 1) {
      alert('Debe haber al menos una novedad');
      return;
    }
    setNovedades(prev => prev.filter((_, i) => i !== index));
  };

  const validar = (): boolean => {
    if (!liderRecibeId) {
      alert('Selecciona el líder que recibe');
      return false;
    }
    if (!fechaActa) {
      alert('Selecciona la fecha del turno');
      return false;
    }
    for (let i = 0; i < novedades.length; i++) {
      if (!novedades[i].categoria) {
        alert(`Selecciona categoría para la novedad ${i + 1}`);
        return false;
      }
      if (!novedades[i].descripcion.trim()) {
        alert(`Agrega descripción a la novedad ${i + 1}`);
        return false;
      }
    }
    return true;
  };

  const enviar = async () => {
    if (!empleado?.id) {
      alert('No se detectó el empleado actual');
      return;
    }
    if (!validar()) return;

    setSaving(true);
    try {
      const payload = {
        lider_entrega_id: empleado.id,
        lider_recibe_id: Number(liderRecibeId),
        turno,
        fecha_acta: fechaActa,
        sede: sede || undefined,
        observaciones: observaciones || undefined,
        novedades: novedades.map((n, i) => ({
          categoria: n.categoria,
          titulo: n.titulo || undefined,
          descripcion: n.descripcion,
          prioridad: n.prioridad,
          requiere_seguimiento: n.requiere_seguimiento,
          orden: i,
        })),
      };

      const res = await entregasApi.crear(payload);
      alert('✅ Acta creada exitosamente. Ahora debes firmarla.');
      navigate(`/entregas/${res.entrega.id}`);
    } catch (e: any) {
      console.error(e);
      alert('Error: ' + (e.response?.data?.message || e.message));
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div className="p-6 text-center text-gray-500">Cargando…</div>;
  }

  return (
    <div className="p-4 sm:p-6 max-w-5xl mx-auto">
      {/* Header */}
      <div className="mb-5">
        <button
          onClick={() => navigate(-1)}
          className="text-sm text-indigo-600 hover:text-indigo-800 mb-2"
        >
          ← Volver
        </button>
        <h1 className="text-2xl font-bold text-gray-800">📝 Nueva Acta de Entrega</h1>
      </div>

      {/* Información del acta */}
      <div className="bg-white rounded-lg shadow p-5 mb-5">
        <h2 className="text-lg font-semibold text-gray-800 mb-4">📋 Información del acta</h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Líder que recibe *</label>
            <select
              value={liderRecibeId}
              onChange={e => setLiderRecibeId(e.target.value ? Number(e.target.value) : '')}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">-- Selecciona un líder --</option>
              {lideres.map(l => (
                <option key={l.id} value={l.id}>
                  {l.colaborador} {l.sede ? `(${l.sede})` : ''}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Fecha del turno *</label>
            <input
              type="date"
              value={fechaActa}
              onChange={e => setFechaActa(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Turno *</label>
            <select
              value={turno}
              onChange={e => setTurno(e.target.value as TurnoKey)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
            >
              <option value="mañana">🌅 Mañana</option>
              <option value="tarde">☀️ Tarde</option>
              <option value="noche">🌙 Noche</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Sede</label>
            <input
              type="text"
              value={sede}
              onChange={e => setSede(e.target.value)}
              placeholder="MDE_ARR, MDE_DEP, CTG, CALI..."
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Observaciones generales</label>
          <textarea
            value={observaciones}
            onChange={e => setObservaciones(e.target.value)}
            rows={3}
            placeholder="Información general sobre el turno..."
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 resize-y"
          />
        </div>
      </div>

      {/* Novedades */}
      <div className="bg-white rounded-lg shadow p-5 mb-5">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-800">📋 Novedades a reportar</h2>
          <button
            type="button"
            onClick={agregarNovedad}
            className="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded transition"
          >
            + Agregar novedad
          </button>
        </div>

        {novedades.map((novedad, i) => (
          <NovedadFormItem
            key={i}
            index={i}
            novedad={novedad}
            categorias={categorias}
            prioridades={prioridades}
            puedeEliminar={novedades.length > 1}
            onChange={(campo, valor) => actualizarNovedad(i, campo, valor)}
            onEliminar={() => eliminarNovedad(i)}
          />
        ))}
      </div>

      {/* Acciones */}
      <div className="flex flex-col sm:flex-row gap-3 justify-end">
        <button
          type="button"
          onClick={() => navigate(-1)}
          disabled={saving}
          className="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md font-semibold transition disabled:opacity-50"
        >
          Cancelar
        </button>
        <button
          type="button"
          onClick={enviar}
          disabled={saving}
          className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-semibold transition disabled:opacity-50"
        >
          {saving ? '⏳ Creando acta...' : '✅ Crear acta y proceder a firmar'}
        </button>
      </div>
    </div>
  );
}
