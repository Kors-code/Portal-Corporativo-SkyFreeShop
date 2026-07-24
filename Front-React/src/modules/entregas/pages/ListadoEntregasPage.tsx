import { useEffect, useState } from "react";
import type React from "react";
import { useNavigate } from "react-router-dom";
import { Download, Search } from "lucide-react";
import entregasApi from "../services/entregasApi";
import useEmpleadoActual from "../hooks/useEmpleadoActual";
import type { Entrega } from "../types";

type Tipo = "" | "entrega" | "recepcion" | "activas";
type Props = { tipoInicial?: Tipo; titulo?: string };

export default function ListadoEntregasPage({ tipoInicial = "", titulo }: Props) {
  const navigate = useNavigate();
  const { empleado, capabilities } = useEmpleadoActual();
  const esAuditorGlobal = Boolean(capabilities.entregas_auditoria_global);
  const esListadoGlobal = !tipoInicial && !titulo;
  const [tipo, setTipo] = useState<Tipo>(tipoInicial);
  const [estado, setEstado] = useState("");
  const [search, setSearch] = useState("");
  const [fechaDesde, setFechaDesde] = useState("");
  const [fechaHasta, setFechaHasta] = useState("");
  const [descargando, setDescargando] = useState(false);
  const [entregas, setEntregas] = useState<Entrega[]>([]);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    if (!empleado?.id && !esAuditorGlobal) return;
    const modoGlobal = (esListadoGlobal || esAuditorGlobal) && (tipo === "" || tipo === "activas");
    const params: Record<string, any> = {
      empleado_id: empleado?.id,
      lider_id: modoGlobal ? undefined : empleado?.id,
      global: modoGlobal ? 1 : undefined,
      search: search || undefined,
      estado: tipo === "activas" ? undefined : estado || undefined,
      tipo: tipo || undefined,
      fecha_desde: fechaDesde || undefined,
      fecha_hasta: fechaHasta || undefined,
      per_page: 50,
    };
    entregasApi.listar(params).then((data) => {
      const rows = data.data ?? [];
      setEntregas(rows);
      setTotal(data.total);
    });
  }, [empleado?.id, estado, search, tipo, fechaDesde, fechaHasta, esListadoGlobal, esAuditorGlobal]);

  const descargarExcel = async () => {
    if (!empleado?.id && !esAuditorGlobal) return;
    setDescargando(true);
    try {
      const modoGlobal = (esListadoGlobal || esAuditorGlobal) && (tipo === "" || tipo === "activas");
      const blob = await entregasApi.descargarResumenExcel({
        empleado_id: empleado?.id,
        lider_id: modoGlobal ? undefined : empleado?.id,
        global: modoGlobal ? 1 : undefined,
        search: search || undefined,
        estado: tipo === "activas" ? undefined : estado || undefined,
        tipo: tipo || undefined,
        fecha_desde: fechaDesde || undefined,
        fecha_hasta: fechaHasta || undefined,
      });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      const desde = fechaDesde || "inicio";
      const hasta = fechaHasta || new Date().toISOString().slice(0, 10);
      a.href = url;
      a.download = `resumen-actas-entrega-${desde}-${hasta}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error("Error descargando resumen de actas", error);
      alert("No se pudo descargar el Excel de actas.");
    } finally {
      setDescargando(false);
    }
  };

  const title = titulo ?? (tipoInicial === "recepcion" ? "Actas que me han enviado" : "Listado de actas");

  return (
    <div className="space-y-4 sm:space-y-5">
      <div>
        <button onClick={() => navigate("/entregas")} className="text-sm font-semibold text-primary">Volver al inicio de actas</button>
        <h1 className="mt-2 text-2xl font-bold leading-tight text-gray-900 sm:text-3xl">{title}</h1>
        <div className="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-gray-500">{total} actas encontradas</p>
          <button onClick={descargarExcel} disabled={descargando} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-gray-400 sm:w-auto">
            <Download size={16} /> {descargando ? "Descargando..." : "Descargar Excel"}
          </button>
        </div>
      </div>
      <section className="rounded-lg border border-gray-200 bg-white p-3 shadow-sm sm:p-4">
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-[180px_180px_170px_170px_1fr]">
          <Field label="Tipo"><select value={tipo} onChange={(e) => setTipo(e.target.value as Tipo)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="">Todas</option><option value="entrega">Creadas por mi</option><option value="recepcion">Enviadas para mi</option><option value="activas">Activas y responsables</option></select></Field>
          <Field label="Estado"><select value={estado} onChange={(e) => setEstado(e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="">Todos</option><option value="abierta">Abierta</option><option value="entregada">Entregada</option><option value="completada">Completada</option><option value="rechazada">Rechazada</option></select></Field>
          <Field label="Desde"><input type="date" value={fechaDesde} onChange={(e) => setFechaDesde(e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field>
          <Field label="Hasta"><input type="date" value={fechaHasta} onChange={(e) => setFechaHasta(e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field>
          <Field label="Buscar"><div className="relative"><Search className="absolute left-3 top-2.5 text-gray-400" size={16} /><input value={search} onChange={(e) => setSearch(e.target.value)} className="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm" placeholder="Nombre, lider o codigo" /></div></Field>
        </div>
      </section>
      <section className="space-y-3 sm:hidden">
        {entregas.map((acta) => (
          <button
            key={acta.id}
            onClick={() => navigate(`/entregas/${acta.id}`)}
            className="w-full rounded-lg border border-gray-200 bg-white p-4 text-left shadow-sm"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="text-xs font-bold uppercase tracking-wide text-primary">{acta.codigo_acta}</div>
                <div className="mt-1 break-words text-base font-bold leading-snug text-gray-900">{acta.nombre_acta}</div>
              </div>
              <span className="shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-bold uppercase text-primary">{acta.estado}</span>
            </div>
            <div className="mt-3 grid grid-cols-1 gap-2 text-sm text-gray-600">
              <div><span className="font-semibold text-gray-800">Entrega:</span> {acta.lider_entrega?.colaborador || "-"}</div>
              <div><span className="font-semibold text-gray-800">Recibe:</span> {acta.lider_recibe?.colaborador || "-"}</div>
              <div className="flex items-center justify-between gap-3">
                <span>{String(acta.fecha_acta).slice(0, 10)}</span>
                <span>{acta.novedades?.length ?? 0} novedades</span>
              </div>
            </div>
          </button>
        ))}
        {entregas.length === 0 && <div className="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500">No hay actas con esos filtros.</div>}
      </section>
      <section className="hidden overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm sm:block">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-sm">
            <thead className="bg-gray-900 text-left text-xs uppercase tracking-wide text-white"><tr><th className="px-4 py-3">Codigo</th><th className="px-4 py-3">Nombre acta</th><th className="px-4 py-3">Entrega</th><th className="px-4 py-3">Recibe</th><th className="px-4 py-3">Fecha</th><th className="px-4 py-3">Novedades</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3"></th></tr></thead>
            <tbody>
              {entregas.map((acta) => <tr key={acta.id} className="border-t border-gray-100 hover:bg-gray-50"><td className="px-4 py-3 font-bold text-primary">{acta.codigo_acta}</td><td className="px-4 py-3 font-semibold text-gray-900">{acta.nombre_acta}</td><td className="px-4 py-3 text-gray-600">{acta.lider_entrega?.colaborador}</td><td className="px-4 py-3 text-gray-600">{acta.lider_recibe?.colaborador}</td><td className="px-4 py-3 text-gray-600">{String(acta.fecha_acta).slice(0, 10)}</td><td className="px-4 py-3 text-gray-600">{acta.novedades?.length ?? 0}</td><td className="px-4 py-3"><span className="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-bold uppercase text-primary">{acta.estado}</span></td><td className="px-4 py-3 text-right"><button onClick={() => navigate(`/entregas/${acta.id}`)} className="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white">Ver acta</button></td></tr>)}
              {entregas.length === 0 && <tr><td colSpan={8} className="px-4 py-12 text-center text-gray-500">No hay actas con esos filtros.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label><span className="mb-1 block text-xs font-bold uppercase tracking-wide text-gray-500">{label}</span>{children}</label>;
}
