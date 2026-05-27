import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { CheckCircle2, Download, Mail, Pencil, Printer, Save, Signature } from "lucide-react";
import FirmaPad from "../components/FirmaPad";
import entregasApi from "../services/entregasApi";
import useEmpleadoActual from "../hooks/useEmpleadoActual";
import { entregaCategories } from "../handoverData";
import type { Entrega } from "../types";

export default function DetalleEntregaPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();
  const [acta, setActa] = useState<Entrega | null>(null);
  const [firmaGuardada, setFirmaGuardada] = useState<string | null>(null);
  const [mostrarFirma, setMostrarFirma] = useState<"entrega" | "recepcion" | null>(null);
  const [ediciones, setEdiciones] = useState<Record<number, { titulo: string; descripcion: string }>>({});

  const load = async () => {
    if (!id || !empleado?.id) return;
    const data = await entregasApi.obtener(id, empleado.id);
    setActa(data);
    if (empleado?.id) {
      const firma = await entregasApi.obtenerFirmaEmpleado(empleado.id).catch(() => null);
      setFirmaGuardada(firma?.firma ?? null);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, empleado?.id]);

  const grouped = useMemo(() => {
    if (!acta) return [];
    return entregaCategories
      .map((category) => ({ category, novedades: (acta.novedades ?? []).filter((n) => n.categoria === category.apiCategory) }))
      .filter((group) => group.novedades.length > 0);
  }, [acta]);

  if (!acta) return <div className="rounded-lg bg-white p-5 text-center text-gray-500 sm:p-8">Cargando acta...</div>;

  const pendientes = (acta.novedades ?? []).filter((n) => !n.resuelto).length;
  const completadas = (acta.novedades ?? []).length - pendientes;
  const esEntrega = empleado?.id === acta.lider_entrega_id;
  const esRecibe = empleado?.id === acta.lider_recibe_id;
  const puedeEntregarActa = esEntrega && acta.estado === "abierta";
  const puedeFirmarRecibido = esRecibe && acta.estado === "entregada";
  const puedeChulear = esRecibe && acta.estado === "recibida";
  const puedeCerrarActa = esRecibe && acta.estado === "recibida";
  const puedeEditar = false;
  const puedeIrAEditar = esEntrega && acta.estado === "abierta";
  const estadoLabel = acta.estado === "completada" ? `Cerrada con ${pendientes} novedades pendientes` : acta.estado;

  const firmar = async (firmaData: string, usandoGuardada: boolean) => {
    if (!empleado?.id || !mostrarFirma) return;
    try {
      const res = await entregasApi.firmar(acta.id, {
        empleado_id: empleado.id,
        tipo_firma: mostrarFirma,
        firma_data: firmaData,
        formato: "base64",
        usar_firma_guardada: usandoGuardada,
      });
      setMostrarFirma(null);
      setActa(res.entrega);
      if (!usandoGuardada) setFirmaGuardada(firmaData);
      if (res.message) alert(res.message);
    } catch (error: any) {
      alert(error?.response?.data?.error || error?.response?.data?.message || "No se pudo firmar el acta");
    }
  };

  const toggleNovedad = async (novedadId: number, current: boolean) => {
    if (!empleado?.id) return;
    const res = await entregasApi.actualizarEstadoNovedad(acta.id, novedadId, {
      empleado_id: empleado.id,
      resuelto: !current,
    });
    setActa(res.entrega);
  };

  const cambiarEdicion = (novedadId: number, field: "titulo" | "descripcion", value: string) => {
    const novedad = acta.novedades?.find((entry) => entry.id === novedadId);
    setEdiciones((prev) => ({
      ...prev,
      [novedadId]: {
        titulo: prev[novedadId]?.titulo ?? novedad?.titulo ?? "",
        descripcion: prev[novedadId]?.descripcion ?? novedad?.descripcion ?? "",
        [field]: value,
      },
    }));
  };

  const guardarNovedad = async (novedadId: number) => {
    if (!empleado?.id) return;
    const novedad = acta.novedades?.find((entry) => entry.id === novedadId);
    const edit = ediciones[novedadId] ?? { titulo: novedad?.titulo ?? "", descripcion: novedad?.descripcion ?? "" };
    if (!edit.descripcion.trim()) {
      alert("La descripcion no puede quedar vacia");
      return;
    }

    try {
      const res = await entregasApi.actualizarNovedad(acta.id, novedadId, {
        empleado_id: empleado.id,
        titulo: edit.titulo.trim() || null,
        descripcion: edit.descripcion.trim(),
      });
      setActa(res.entrega);
      setEdiciones((prev) => {
        const next = { ...prev };
        delete next[novedadId];
        return next;
      });
    } catch (error: any) {
      alert(error?.response?.data?.error || error?.response?.data?.message || "No se pudo actualizar la novedad");
    }
  };

  const cerrarActa = async () => {
    if (!empleado?.id) return;
    const confirmar = window.confirm(`Vas a cerrar el acta con ${pendientes} novedades pendientes. Despues de cerrar no se podra modificar. Continuar?`);
    if (!confirmar) return;
    const res = await entregasApi.cerrar(acta.id, { empleado_id: empleado.id });
    setActa(res.entrega);
    if (res.message) alert(res.message);
  };

  return (
    <div className="space-y-4 sm:space-y-5">
      {mostrarFirma && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-3 sm:p-4">
          <FirmaPad titulo={mostrarFirma === "entrega" ? "Entregar acta con firma" : "Firma de recibido"} firmaGuardada={firmaGuardada} onFirmaCapturada={firmar} onCancelar={() => setMostrarFirma(null)} />
        </div>
      )}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0"><button onClick={() => navigate("/entregas")} className="text-sm font-semibold text-primary">Volver al inicio de actas</button><p className="mt-2 text-xs font-bold uppercase tracking-wide text-gray-500">{acta.codigo_acta}</p><h1 className="break-words text-2xl font-bold leading-tight text-gray-900 sm:text-3xl">{acta.nombre_acta}</h1><p className="mt-2 text-sm font-semibold text-primary">{estadoLabel}</p></div>
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
          <a href={entregasApi.descargarPdfUrl(acta.id, empleado?.id)} target="_blank" className="inline-flex items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 sm:px-4"><Download size={16} /> PDF</a>
          <button onClick={() => window.print()} className="inline-flex items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 sm:px-4"><Printer size={16} /> Imprimir</button>
          {puedeIrAEditar && <button onClick={() => navigate(`/entregas/${acta.id}/editar`)} className="inline-flex items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 sm:px-4"><Pencil size={16} /> Editar</button>}
          {puedeEntregarActa && <button onClick={() => setMostrarFirma("entrega")} className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white sm:px-4"><Signature size={16} /> Entregar</button>}
          {puedeFirmarRecibido && <button onClick={() => setMostrarFirma("recepcion")} className="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white sm:px-4"><Signature size={16} /> Firmar</button>}
          {puedeCerrarActa && <button onClick={cerrarActa} className="col-span-2 inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white sm:col-span-1 sm:px-4"><CheckCircle2 size={16} /> Cerrar acta</button>}
        </div>
      </div>
      <section className="grid grid-cols-1 gap-4 sm:grid-cols-3"><Summary label="Novedades" value={acta.novedades?.length ?? 0} /><Summary label="Completadas" value={completadas} /><Summary label="Pendientes" value={pendientes} /></section>
      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4"><Info label="Fecha" value={String(acta.fecha_acta).slice(0, 10)} /><Info label="Turno" value={acta.turno} /><Info label="Sede" value={acta.sede || "-"} /><Info label="Estado" value={estadoLabel} /></div>
        <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2"><Leader title="Lider entrega" name={acta.lider_entrega?.colaborador ?? ""} signed={Boolean(acta.firma_entrega)} firma={acta.firma_entrega?.firma_data} /><Leader title="Lider recibe" name={acta.lider_recibe?.colaborador ?? ""} signed={Boolean(acta.firma_recepcion)} firma={acta.firma_recepcion?.firma_data} /></div>
      </section>
      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-col gap-2 border-b border-gray-200 pb-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-lg font-bold text-gray-900">Novedades del acta</h2><p className="text-sm text-gray-500">{puedeEditar ? "Revisa y corrige las novedades antes de entregar el acta." : puedeChulear ? "Puedes chulear cada novedad cuando quede completada. Luego cierras el acta." : "El contenido queda bloqueado cuando el acta se cierra."}</p></div><span className="text-sm font-semibold text-gray-500">{pendientes} pendientes</span></div>
        <div className="space-y-5">{grouped.map(({ category, novedades }) => <div key={category.key}><h3 className="mb-2 rounded-md bg-gray-900 px-3 py-2 text-sm font-bold text-white">{category.label}</h3><div className="space-y-2">{novedades.map((novedad) => {
          const novedadId = novedad.id ?? 0;
          const edit = ediciones[novedadId] ?? { titulo: novedad.titulo ?? "", descripcion: novedad.descripcion ?? "" };
          return <div key={novedad.id} className={`rounded-md border p-3 ${novedad.resuelto ? "border-emerald-200 bg-emerald-50" : "border-gray-200"}`}><div className="flex items-start gap-3"><button type="button" onClick={() => novedad.id && toggleNovedad(novedad.id, Boolean(novedad.resuelto))} disabled={!puedeChulear} className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded border ${novedad.resuelto ? "border-emerald-600 bg-emerald-600 text-white" : "border-gray-300 bg-white text-transparent"} disabled:cursor-not-allowed`}><CheckCircle2 size={16} /></button><div className="min-w-0 flex-1">{puedeEditar && novedad.id ? <div className="space-y-2"><input value={edit.titulo} onChange={(event) => cambiarEdicion(novedad.id!, "titulo", event.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-900" /><textarea value={edit.descripcion} onChange={(event) => cambiarEdicion(novedad.id!, "descripcion", event.target.value)} rows={3} className="w-full resize-y rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700" /><button type="button" onClick={() => guardarNovedad(novedad.id!)} className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white"><Save size={14} /> Guardar novedad</button></div> : <><div className={`break-words font-semibold ${novedad.resuelto ? "text-emerald-800" : "text-gray-900"}`}>{novedad.titulo}</div><p className="mt-1 whitespace-pre-wrap break-words text-sm text-gray-600">{novedad.descripcion}</p></>}</div></div></div>;
        })}</div></div>)}</div>
      </section>
      <section className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 sm:p-5"><div className="flex items-start gap-2 text-sm text-gray-600"><Mail className="mt-0.5 shrink-0" size={16} /> Al cerrar el acta se envia la notificacion y ya no se modifica el contenido. Si faltó algo, se crea otra acta.</div></section>
    </div>
  );
}

function Summary({ label, value }: { label: string; value: number }) { return <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><div className="text-2xl font-bold text-gray-900">{value}</div><div className="text-sm text-gray-500">{label}</div></div>; }
function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-lg bg-gray-50 p-3 sm:p-4"><div className="text-xs font-bold uppercase tracking-wide text-gray-500">{label}</div><div className="mt-1 break-words font-semibold text-gray-900">{value}</div></div>; }
function Leader({ title, name, signed, firma }: { title: string; name: string; signed?: boolean; firma?: string | null }) { return <div className="rounded-lg border border-gray-200 p-4"><div className="text-xs font-bold uppercase tracking-wide text-gray-500">{title}</div><div className="mt-2 break-words font-semibold text-gray-900">{name}</div><div className="mt-5 rounded border border-gray-200 bg-gray-50 p-3 text-center text-xs text-gray-500">{signed ? firma ? <img src={firma} alt="Firma" className="mx-auto max-h-20" /> : "Firmado" : "Pendiente de firma"}</div></div>; }
