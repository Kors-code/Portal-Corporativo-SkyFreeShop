import { useEffect, useState } from "react";
import type React from "react";
import { useNavigate } from "react-router-dom";
import { Search } from "lucide-react";
import entregasApi from "../services/entregasApi";
import useEmpleadoActual from "../hooks/useEmpleadoActual";
import type { Entrega } from "../types";

type Tipo = "" | "entrega" | "recepcion" | "activas";
type Props = { tipoInicial?: Tipo; titulo?: string };

export default function ListadoEntregasPage({ tipoInicial = "", titulo }: Props) {
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();
  const [tipo, setTipo] = useState<Tipo>(tipoInicial);
  const [estado, setEstado] = useState("");
  const [search, setSearch] = useState("");
  const [entregas, setEntregas] = useState<Entrega[]>([]);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    if (!empleado?.id) return;
    const params: Record<string, any> = {
      lider_id: empleado.id,
      search: search || undefined,
      estado: tipo === "activas" ? undefined : estado || undefined,
      tipo: tipo === "activas" ? undefined : tipo || undefined,
      per_page: 50,
    };
    entregasApi.listar(params).then((data) => {
      const rows = data.data ?? [];
      const filtered = tipo === "activas" ? rows.filter((acta) => !["completada", "rechazada"].includes(acta.estado)) : rows;
      setEntregas(filtered);
      setTotal(tipo === "activas" ? filtered.length : data.total);
    });
  }, [empleado?.id, estado, search, tipo]);

  const title = titulo ?? (tipoInicial === "recepcion" ? "Actas que me han enviado" : "Listado de actas");

  return (
    <div className="space-y-5">
      <div>
        <button onClick={() => navigate("/entregas")} className="text-sm font-semibold text-primary">Volver al inicio de actas</button>
        <h1 className="mt-2 text-3xl font-bold text-gray-900">{title}</h1>
        <p className="mt-1 text-sm text-gray-500">{total} actas encontradas</p>
      </div>
      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-[220px_220px_1fr]">
          <Field label="Tipo"><select value={tipo} onChange={(e) => setTipo(e.target.value as Tipo)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="">Todas</option><option value="entrega">Creadas por mi</option><option value="recepcion">Enviadas para mi</option><option value="activas">Activas y responsables</option></select></Field>
          <Field label="Estado"><select value={estado} onChange={(e) => setEstado(e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="">Todos</option><option value="abierta">Abierta</option><option value="entregada">Entregada</option><option value="completada">Completada</option><option value="rechazada">Rechazada</option></select></Field>
          <Field label="Buscar"><div className="relative"><Search className="absolute left-3 top-2.5 text-gray-400" size={16} /><input value={search} onChange={(e) => setSearch(e.target.value)} className="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm" placeholder="Nombre, lider o codigo" /></div></Field>
        </div>
      </section>
      <section className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
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
