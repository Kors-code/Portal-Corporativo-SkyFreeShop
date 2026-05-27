import { useEffect, useState } from "react";
import type React from "react";
import { useNavigate } from "react-router-dom";
import { ClipboardList, FileInput, FilePlus2, Inbox, Settings, UsersRound } from "lucide-react";
import entregasApi from "../services/entregasApi";
import useEmpleadoActual from "../hooks/useEmpleadoActual";
import FirmaPad from "../components/FirmaPad";
import type { DashboardStats, Entrega } from "../types";

export default function EntregasDashboardPage() {
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [recientes, setRecientes] = useState<Entrega[]>([]);
  const [firmaGuardada, setFirmaGuardada] = useState<string | null>(null);
  const [mostrarFirma, setMostrarFirma] = useState(false);

  useEffect(() => {
    if (!empleado?.id) return;
    entregasApi.obtenerDashboard(empleado.id).then((data) => {
      setStats(data.stats);
      setRecientes(data.recientes ?? []);
    });
    entregasApi.obtenerFirmaEmpleado(empleado.id).then((data) => setFirmaGuardada(data.firma));
  }, [empleado?.id]);

  const guardarFirma = async (firma: string) => {
    if (!empleado?.id) return;
    await entregasApi.guardarFirmaEmpleado(empleado.id, firma);
    setFirmaGuardada(firma);
    setMostrarFirma(false);
  };

  if (!empleado) {
    return <div className="rounded-lg bg-white p-5 text-center text-sm text-gray-600 sm:p-8">No se encontro empleado asociado al usuario actual. El usuario debe existir en empleados con el mismo email, cedula/username o nombre.</div>;
  }

  return (
    <div className="space-y-5 sm:space-y-8">
      {mostrarFirma && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-3 sm:p-4">
          <FirmaPad titulo="Mi firma" firmaGuardada={firmaGuardada} permitirGuardada={false} onFirmaCapturada={(firma) => guardarFirma(firma)} onCancelar={() => setMostrarFirma(false)} />
        </div>
      )}
      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-primary">Entrega de lideres</p>
            <h1 className="mt-2 text-2xl font-bold leading-tight text-gray-900 sm:text-3xl">Vas a entregar o a recibir acta?</h1>
            <p className="mt-2 max-w-2xl text-sm text-gray-500">Hola {empleado.colaborador}. Crea, recibe y cierra actas desde la base de datos.</p>
          </div>
          <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap">
            <button onClick={() => setMostrarFirma(true)} className="inline-flex w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 sm:w-auto">
              <Settings size={17} /> Mi firma
            </button>
            <button onClick={() => navigate("/entregas/listado")} className="inline-flex w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 sm:w-auto">
              <ClipboardList size={17} /> Ver todas
            </button>
          </div>
        </div>
      </section>
      <section className="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <ActionCard title="Entregar" description="Crear una nueva acta para el lider que recibe el siguiente turno." button="Crear acta" icon={<FilePlus2 size={28} />} tone="primary" onClick={() => navigate("/entregas/nuevo")} />
        <ActionCard title="Recibir" description="Ver las actas que te dejaron pendientes para leerlas, firmarlas y cerrarlas." button="Actas abiertas" icon={<FileInput size={28} />} tone="green" badge={stats?.recibidas_pendientes ?? 0} onClick={() => navigate("/entregas/recibir")} />
      </section>
      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Metric icon={<FilePlus2 size={18} />} label="Actas creadas" value={stats?.entregas_realizadas ?? 0} />
        <Metric icon={<Inbox size={18} />} label="Por recibir" value={stats?.recibidas_pendientes ?? 0} />
        <Metric icon={<ClipboardList size={18} />} label="Recibidas" value={stats?.recibidas_completadas ?? 0} />
        <Metric icon={<UsersRound size={18} />} label="Actas abiertas" value={stats?.entregas_pendientes_firma ?? 0} />
      </section>
      <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div className="mb-4 flex items-center justify-between gap-3">
          <h2 className="text-lg font-bold text-gray-900">Actas recientes</h2>
          <button onClick={() => navigate("/entregas/listado")} className="shrink-0 text-sm font-semibold text-primary">Abrir listado</button>
        </div>
        <div className="space-y-2">
          {recientes.map((acta) => (
            <button key={acta.id} onClick={() => navigate(`/entregas/${acta.id}`)} className="flex w-full flex-col gap-2 rounded-md border border-gray-200 p-3 text-left hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between">
              <span className="min-w-0"><strong className="block break-words sm:inline">{acta.nombre_acta}</strong><span className="text-sm text-gray-500 sm:ml-2">{acta.estado}</span></span>
              <span className="text-sm font-semibold text-primary">Ver</span>
            </button>
          ))}
          {recientes.length === 0 && <div className="py-8 text-center text-sm text-gray-500">No hay actas recientes.</div>}
        </div>
      </section>
    </div>
  );
}

function ActionCard({ title, description, button, icon, tone, badge, onClick }: { title: string; description: string; button: string; icon: React.ReactNode; tone: "primary" | "green"; badge?: number; onClick: () => void }) {
  const classes = tone === "primary" ? "border-primary/20 bg-primary text-white" : "border-emerald-700/20 bg-emerald-700 text-white";
  return <button onClick={onClick} className={`relative rounded-lg border p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-6 ${classes}`}>{Boolean(badge) && <span className="absolute right-4 top-4 rounded-full bg-white px-2.5 py-1 text-xs font-bold text-primary">{badge} pendiente</span>}<div className="mb-4 flex h-11 w-11 items-center justify-center rounded-md bg-white/15 sm:h-12 sm:w-12">{icon}</div><h2 className="text-xl font-bold sm:text-2xl">{title}</h2><p className="mt-2 max-w-md text-sm text-white/85">{description}</p><span className="mt-5 inline-flex rounded-md bg-white/15 px-4 py-2 text-sm font-bold">{button}</span></button>;
}

function Metric({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><div className="mb-3 flex h-9 w-9 items-center justify-center rounded-md bg-primary/10 text-primary">{icon}</div><div className="text-2xl font-bold text-gray-900">{value}</div><div className="text-sm text-gray-500">{label}</div></div>;
}
