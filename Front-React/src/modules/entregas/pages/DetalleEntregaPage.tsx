import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import entregasApi from '../services/entregasApi';
import useEmpleadoActual from '../hooks/useEmpleadoActual';
import FirmaPad from '../components/FirmaPad';
import type {
  CategoriaInfo,
  CategoriaKey,
  Entrega,
  EstadoEntrega,
  Novedad,
  PrioridadKey,
} from '../types';

type TipoFirma = 'entrega' | 'recepcion';

const ESTADO_INFO: Record<EstadoEntrega, { bg: string; label: string }> = {
  abierta:    { bg: 'bg-yellow-500',  label: 'Abierta - Por firmar' },
  entregada:  { bg: 'bg-blue-500',    label: 'Firmada por quien entrega' },
  recibida:   { bg: 'bg-purple-500',  label: 'Recibida' },
  completada: { bg: 'bg-green-600',   label: '✅ Completada' },
  rechazada:  { bg: 'bg-red-600',     label: '❌ Rechazada' },
};

const PRIORIDAD_COLORS: Record<PrioridadKey, string> = {
  urgente: 'bg-red-700 text-white',
  alta:    'bg-red-500 text-white',
  media:   'bg-yellow-500 text-white',
  baja:    'bg-gray-500 text-white',
};

export default function DetalleEntregaPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();

  const [entrega, setEntrega] = useState<Entrega | null>(null);
  const [categorias, setCategorias] = useState<Record<CategoriaKey, CategoriaInfo>>({} as any);
  const [loading, setLoading] = useState(true);
  const [firmaGuardada, setFirmaGuardada] = useState<string | null>(null);

  const [mostrarFirma, setMostrarFirma] = useState<TipoFirma | null>(null);
  const [mostrarRechazo, setMostrarRechazo] = useState(false);
  const [razonRechazo, setRazonRechazo] = useState('');

  // observaciones por novedad
  const [obsEditandoId, setObsEditandoId] = useState<number | null>(null);
  const [obsTexto, setObsTexto] = useState('');

  useEffect(() => {
    if (id) cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const cargar = async () => {
    if (!id) return;
    setLoading(true);
    try {
      const [entregaData, catsData, firmaData] = await Promise.all([
        entregasApi.obtener(id),
        entregasApi.obtenerCategorias(),
        empleado?.id ? entregasApi.obtenerFirmaEmpleado(empleado.id).catch(() => null) : Promise.resolve(null),
      ]);
      setEntrega(entregaData);
      setCategorias(catsData.categorias);
      if (firmaData?.tiene_firma) setFirmaGuardada(firmaData.firma);
    } catch (e) {
      console.error(e);
      alert('Error cargando acta');
    } finally {
      setLoading(false);
    }
  };

  const handleFirma = async (firmaData: string, usandoGuardada: boolean) => {
    if (!id || !empleado?.id || !mostrarFirma) return;
    try {
      await entregasApi.firmar(id, {
        empleado_id: empleado.id,
        tipo_firma: mostrarFirma,
        firma_data: firmaData,
        formato: 'base64',
        usar_firma_guardada: usandoGuardada,
      });
      alert(`✅ Firma de ${mostrarFirma === 'entrega' ? 'entrega' : 'recepción'} registrada`);
      setMostrarFirma(null);
      cargar();
    } catch (e: any) {
      alert('Error: ' + (e.response?.data?.error || e.message));
    }
  };

  const handleRechazar = async () => {
    if (!id || !empleado?.id) return;
    if (!razonRechazo.trim()) {
      alert('Debes escribir la razón');
      return;
    }
    if (!confirm('¿Confirmas rechazar esta acta?')) return;

    try {
      await entregasApi.rechazar(id, {
        empleado_id: empleado.id,
        razon_rechazo: razonRechazo,
      });
      alert('Acta rechazada');
      setMostrarRechazo(false);
      setRazonRechazo('');
      cargar();
    } catch (e: any) {
      alert('Error: ' + (e.response?.data?.error || e.message));
    }
  };

  const guardarObservacion = async (novedadId: number) => {
    if (!id || !obsTexto.trim()) return;
    try {
      await entregasApi.agregarObservacionNovedad(id, novedadId, {
        observaciones_receptor: obsTexto,
      });
      setObsEditandoId(null);
      setObsTexto('');
      cargar();
    } catch (e) {
      alert('Error guardando observación');
    }
  };

  if (loading || !entrega) {
    return <div className="p-6 text-center text-gray-500">Cargando…</div>;
  }

  const esLiderEntrega = empleado?.id === entrega.lider_entrega_id;
  const esLiderRecibe = empleado?.id === entrega.lider_recibe_id;
  const puedeFirmarEntrega = esLiderEntrega && !entrega.firma_entrega && entrega.estado === 'abierta';
  const puedeFirmarRecepcion =
    esLiderRecibe && !!entrega.firma_entrega && !entrega.firma_recepcion && entrega.estado === 'entregada';

  // agrupar novedades por categoría
  const novedadesPorCat: Record<string, Novedad[]> = (entrega.novedades ?? []).reduce((acc, n) => {
    (acc[n.categoria] = acc[n.categoria] || []).push(n);
    return acc;
  }, {} as Record<string, Novedad[]>);

  const estado = ESTADO_INFO[entrega.estado];

  return (
    <div className="p-4 sm:p-6 max-w-6xl mx-auto">
      {/* Modal firma */}
      {mostrarFirma && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <FirmaPad
            titulo={`Firma como quien ${mostrarFirma === 'entrega' ? 'entrega' : 'recibe'}`}
            firmaGuardada={firmaGuardada}
            onFirmaCapturada={handleFirma}
            onCancelar={() => setMostrarFirma(null)}
          />
        </div>
      )}

      {/* Modal rechazo */}
      {mostrarRechazo && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-lg p-5 w-full max-w-lg">
            <h3 className="text-lg font-semibold text-gray-800 mb-3">❌ Rechazar acta</h3>
            <p className="text-sm text-gray-600 mb-3">Indica la razón del rechazo:</p>
            <textarea
              value={razonRechazo}
              onChange={e => setRazonRechazo(e.target.value)}
              rows={5}
              placeholder="Explica por qué rechazas esta acta..."
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-red-500"
            />
            <div className="flex justify-end gap-2 mt-4">
              <button
                onClick={() => setMostrarRechazo(false)}
                className="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded font-medium"
              >
                Cancelar
              </button>
              <button
                onClick={handleRechazar}
                className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded font-semibold"
              >
                Rechazar acta
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="mb-5">
        <button onClick={() => navigate('/entregas')} className="text-sm text-indigo-600 hover:text-indigo-800 mb-2">
          ← Volver al dashboard
        </button>
        <div className="flex flex-col sm:flex-row justify-between items-start gap-3">
          <div>
            <div className="text-xs font-semibold text-gray-500">{entrega.codigo_acta}</div>
            <h1 className="text-xl sm:text-2xl font-bold text-gray-800">{entrega.nombre_acta}</h1>
          </div>
          <div className="flex flex-col items-end gap-2">
            <span className={`px-3 py-1.5 rounded-full text-xs font-bold text-white uppercase ${estado.bg}`}>
              {estado.label}
            </span>
            <a
              href={entregasApi.descargarPdfUrl(entrega.id)}
              target="_blank"
              rel="noopener noreferrer"
              className="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-sm font-semibold rounded"
            >
              📄 Descargar PDF
            </a>
          </div>
        </div>
      </div>

      {/* Info card */}
      <div className="bg-white rounded-lg shadow p-5 mb-5">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5">
          <InfoBox icono="📅" label="Fecha" valor={new Date(entrega.fecha_acta).toLocaleDateString('es')} />
          <InfoBox icono="🕒" label="Turno" valor={entrega.turno.toUpperCase()} />
          <InfoBox icono="🏪" label="Sede" valor={entrega.sede || '-'} />
          <InfoBox icono="📋" label="Novedades" valor={entrega.novedades?.length ?? 0} />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <LiderBox tipo="entrega" lider={entrega.lider_entrega} firma={entrega.firma_entrega} />
          <LiderBox tipo="recepcion" lider={entrega.lider_recibe} firma={entrega.firma_recepcion} />
        </div>
      </div>

      {/* Observaciones generales */}
      {entrega.observaciones && (
        <div className="bg-white rounded-lg shadow p-5 mb-5">
          <h3 className="text-base font-semibold text-gray-800 mb-2">📝 Observaciones generales</h3>
          <p className="text-sm text-gray-600 whitespace-pre-wrap">{entrega.observaciones}</p>
        </div>
      )}

      {/* Razón rechazo */}
      {entrega.razon_rechazo && (
        <div className="bg-red-50 border-l-4 border-red-500 rounded-lg shadow p-5 mb-5">
          <h3 className="text-base font-semibold text-red-700 mb-2">❌ Razón del rechazo</h3>
          <p className="text-sm text-red-800 whitespace-pre-wrap">{entrega.razon_rechazo}</p>
        </div>
      )}

      {/* Novedades */}
      <div className="bg-white rounded-lg shadow p-5 mb-5">
        <h3 className="text-base font-semibold text-gray-800 mb-4">📋 Novedades reportadas</h3>

        {Object.entries(novedadesPorCat).map(([catKey, items]) => {
          const catInfo = categorias[catKey as CategoriaKey];
          return (
            <div key={catKey} className="mb-5">
              <div
                className="text-white rounded-md px-4 py-2 mb-2 flex justify-between items-center font-semibold text-sm"
                style={{ backgroundColor: catInfo?.color || '#6b7280' }}
              >
                <span>{catInfo?.icon} {catInfo?.label || catKey}</span>
                <span className="bg-white/30 px-2 py-0.5 rounded-full text-xs">{items.length}</span>
              </div>

              {items.map(n => (
                <div key={n.id} className="bg-gray-50 border-l-4 border-indigo-400 rounded p-3 mb-2">
                  <div className="flex flex-wrap gap-2 mb-2">
                    <span className={`px-2 py-0.5 rounded text-xs font-bold ${PRIORIDAD_COLORS[n.prioridad]}`}>
                      {n.prioridad.toUpperCase()}
                    </span>
                    {n.requiere_seguimiento && (
                      <span className="text-xs text-red-600 font-semibold">⚠️ Requiere seguimiento</span>
                    )}
                  </div>

                  {n.titulo && <h4 className="font-semibold text-gray-800 mb-1">{n.titulo}</h4>}
                  <p className="text-sm text-gray-700 whitespace-pre-wrap">{n.descripcion}</p>

                  {n.observaciones_receptor && obsEditandoId !== n.id && (
                    <div className="mt-3 p-2 bg-yellow-50 border-l-2 border-yellow-400 rounded text-sm">
                      <strong>💬 Observación de {entrega.lider_recibe?.colaborador}:</strong>
                      <p className="mt-1 whitespace-pre-wrap">{n.observaciones_receptor}</p>
                    </div>
                  )}

                  {obsEditandoId === n.id ? (
                    <div className="mt-3">
                      <textarea
                        value={obsTexto}
                        onChange={e => setObsTexto(e.target.value)}
                        rows={3}
                        placeholder="Tu observación..."
                        className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                      />
                      <div className="flex gap-2 mt-1">
                        <button
                          onClick={() => n.id && guardarObservacion(n.id)}
                          className="text-xs px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded"
                        >
                          Guardar
                        </button>
                        <button
                          onClick={() => { setObsEditandoId(null); setObsTexto(''); }}
                          className="text-xs px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border"
                        >
                          Cancelar
                        </button>
                      </div>
                    </div>
                  ) : (
                    esLiderRecibe && ['entregada', 'recibida'].includes(entrega.estado) && n.id && (
                      <button
                        onClick={() => {
                          setObsEditandoId(n.id!);
                          setObsTexto(n.observaciones_receptor || '');
                        }}
                        className="mt-2 text-xs px-3 py-1 bg-white border border-dashed border-gray-300 hover:bg-gray-50 text-gray-600 rounded"
                      >
                        {n.observaciones_receptor ? '✏️ Editar observación' : '💬 Agregar observación'}
                      </button>
                    )
                  )}
                </div>
              ))}
            </div>
          );
        })}
      </div>

      {/* Acciones disponibles */}
      {(puedeFirmarEntrega || puedeFirmarRecepcion) && (
        <div className="bg-gradient-to-br from-yellow-50 to-amber-100 border border-yellow-200 rounded-lg shadow p-5">
          {puedeFirmarEntrega && (
            <>
              <h3 className="text-lg font-semibold text-amber-900 mb-2">Acciones disponibles</h3>
              <p className="text-sm text-amber-800 mb-3">
                Eres el líder que entrega. Firma la acta para enviarla a <strong>{entrega.lider_recibe?.colaborador}</strong>.
              </p>
              <button
                onClick={() => setMostrarFirma('entrega')}
                className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-md"
              >
                ✍️ Firmar como quien entrega
              </button>
            </>
          )}

          {puedeFirmarRecepcion && (
            <>
              <h3 className="text-lg font-semibold text-amber-900 mb-2">Recibir acta</h3>
              <p className="text-sm text-amber-800 mb-3">
                Revisa las novedades y firma para confirmar la recepción.
              </p>
              <div className="flex flex-col sm:flex-row gap-2">
                <button
                  onClick={() => setMostrarFirma('recepcion')}
                  className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-md"
                >
                  ✅ Firmar y recibir acta
                </button>
                <button
                  onClick={() => setMostrarRechazo(true)}
                  className="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-md"
                >
                  ❌ Rechazar acta
                </button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}

function InfoBox({ icono, label, valor }: { icono: string; label: string; valor: string | number }) {
  return (
    <div className="flex items-center gap-2">
      <div className="text-2xl">{icono}</div>
      <div>
        <div className="text-xs text-gray-500 uppercase">{label}</div>
        <div className="font-semibold text-gray-800">{valor}</div>
      </div>
    </div>
  );
}

function LiderBox({ tipo, lider, firma }: { tipo: 'entrega' | 'recepcion'; lider: any; firma: any }) {
  return (
    <div className="bg-gray-50 rounded-lg p-4">
      <div className="text-xs font-semibold text-gray-500 uppercase mb-1">
        {tipo === 'entrega' ? '📤 Líder que entrega' : '📥 Líder que recibe'}
      </div>
      <div className="font-bold text-gray-800">{lider?.colaborador}</div>
      <div className="text-xs text-gray-500">{lider?.email}</div>

      {firma && (
        <div className="mt-3 p-2 bg-white border border-gray-200 rounded text-center">
          <img src={firma.firma_data} alt="Firma" className="max-h-16 mx-auto" />
          <small className="block mt-1 text-xs text-gray-500">
            ✅ Firmado: {new Date(firma.fecha_firma).toLocaleString('es')}
          </small>
        </div>
      )}
    </div>
  );
}
