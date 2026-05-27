import { useEffect, useMemo, useState } from "react";
import type React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Check, Mail, Plus, Save, Trash2, UserRound } from "lucide-react";
import entregasApi from "../services/entregasApi";
import useEmpleadoActual from "../hooks/useEmpleadoActual";
import type { Empleado, Novedad, TurnoKey } from "../types";
import { entregaCategories, type HandoverCategoryKey } from "../handoverData";

type DraftItem = {
  id: string;
  categoryKey: HandoverCategoryKey;
  subcategoryKey: string;
  persona?: string;
  descripcion: string;
};

function today() {
  return new Date().toISOString().slice(0, 10);
}

export default function CrearEntregaPage() {
  const navigate = useNavigate();
  const { id: editId } = useParams<{ id: string }>();
  const { empleado } = useEmpleadoActual();
  const [lideres, setLideres] = useState<Empleado[]>([]);
  const [personas, setPersonas] = useState<Empleado[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [liderRecibeId, setLiderRecibeId] = useState<number | "">("");
  const [fechaActa, setFechaActa] = useState(today());
  const [turno, setTurno] = useState<TurnoKey>("tarde");
  const [sede, setSede] = useState("");
  const [observaciones, setObservaciones] = useState("");
  const [activeCategory, setActiveCategory] = useState<HandoverCategoryKey>("personal");
  const [items, setItems] = useState<DraftItem[]>([]);
  const [empleadosNoLideres, setEmpleadosNoLideres] = useState(0);
  const editando = Boolean(editId);

  useEffect(() => {
    if (empleado?.sede) setSede(empleado.sede);
  }, [empleado?.sede]);

  useEffect(() => {
    Promise.all([
      entregasApi.obtenerLideres(),
      entregasApi.obtenerEmpleados(),
      editId && empleado?.id ? entregasApi.obtener(editId, empleado.id) : Promise.resolve(null),
    ])
      .then(([leaders, employees, acta]) => {
        const disponibles = leaders.filter((leader) => leader.id !== empleado?.id);
        setLideres(disponibles);
        setPersonas(employees);
        setEmpleadosNoLideres(employees.filter((employee) => employee.portal_user_role !== "lider").length);
        if (acta) {
          setFechaActa(String(acta.fecha_acta).slice(0, 10));
          setTurno(acta.turno);
          setSede(acta.sede ?? "");
          setObservaciones(acta.observaciones ?? "");
          setLiderRecibeId(acta.lider_recibe_id);
          setItems((acta.novedades ?? []).map((novedad) => novedadToDraft(novedad)));
          setActiveCategory(novedadToDraft((acta.novedades ?? [])[0]).categoryKey);
          return;
        }
        setLiderRecibeId((current) => disponibles.some((employee) => employee.id === current) ? current : disponibles[0]?.id ?? "");
        const personal = entregaCategories.find((category) => category.key === "personal")!;
        setItems((current) => current.length > 0 ? current : [
            {
              id: crypto.randomUUID(),
              categoryKey: "personal",
              subcategoryKey: personal.subcategories[0].key,
              persona: employees[0]?.colaborador ?? "",
              descripcion: "",
            },
          ]);
      })
      .catch(() => alert("No se pudieron cargar los lideres/empleados"))
      .finally(() => setLoading(false));
  }, [editId, empleado?.id]);

  const nombreActa = `Acta de entrega ${fechaActa} ${turno}`;
  const groupedItems = useMemo(
    () => entregaCategories.map((category) => ({ category, items: items.filter((item) => item.categoryKey === category.key) })),
    [items],
  );
  const resumenNovedades = useMemo(
    () =>
      groupedItems
        .map(({ category, items: categoryItems }) => ({
          category,
          items: categoryItems
            .filter((item) => item.descripcion.trim())
            .map((item) => ({
              ...item,
              subcategoryLabel: category.subcategories.find((entry) => entry.key === item.subcategoryKey)?.label ?? "Novedad",
            })),
        }))
        .filter((group) => group.items.length > 0),
    [groupedItems],
  );
  const totalNovedades = resumenNovedades.reduce((total, group) => total + group.items.length, 0);

  const addItem = (categoryKey: HandoverCategoryKey) => {
    const category = entregaCategories.find((entry) => entry.key === categoryKey)!;
    setActiveCategory(categoryKey);
    setItems((prev) => [
      ...prev,
      {
        id: crypto.randomUUID(),
        categoryKey,
        subcategoryKey: category.subcategories[0].key,
        persona: categoryKey === "personal" ? personas[0]?.colaborador ?? "" : undefined,
        descripcion: "",
      },
    ]);
  };

  const updateItem = (id: string, changes: Partial<DraftItem>) => {
    setItems((prev) => prev.map((item) => (item.id === id ? { ...item, ...changes } : item)));
  };

  const buildNovedades = (): Novedad[] =>
    items
      .filter((item) => item.descripcion.trim())
      .map((item) => {
        const category = entregaCategories.find((entry) => entry.key === item.categoryKey)!;
        const subcategory = category.subcategories.find((entry) => entry.key === item.subcategoryKey);
        const prefix = item.categoryKey === "personal" && item.persona ? `${item.persona}: ` : "";
        return {
          categoria: category.apiCategory,
          titulo: `${category.label} - ${subcategory?.label ?? "Novedad"}`,
          descripcion: `${prefix}${item.descripcion.trim()}`,
          prioridad: "media",
          requiere_seguimiento: item.categoryKey === "personal",
          resuelto: false,
        };
      });

  const saveActa = async () => {
    if (!empleado?.id) {
      alert("No se detecto el empleado actual. Revisa que el usuario del portal tenga email/cedula en empleados.");
      return;
    }
    if (!liderRecibeId) {
      alert("Selecciona el lider que recibe.");
      return;
    }
    const novedades = buildNovedades();
    if (novedades.length === 0) {
      alert("Agrega al menos una novedad.");
      return;
    }

    setSaving(true);
    try {
      const payload = {
        lider_entrega_id: empleado.id,
        lider_recibe_id: Number(liderRecibeId),
        turno,
        fecha_acta: fechaActa,
        sede,
        observaciones,
        novedades: novedades.map((novedad, orden) => ({
          categoria: novedad.categoria,
          titulo: novedad.titulo ?? undefined,
          descripcion: novedad.descripcion,
          prioridad: novedad.prioridad,
          requiere_seguimiento: novedad.requiere_seguimiento,
          orden,
        })),
      };
      const res = editId ? await entregasApi.actualizar(editId, payload) : await entregasApi.crear(payload);
      navigate(`/entregas/${res.entrega.id}`);
    } catch (error: any) {
      alert(error?.response?.data?.message || error?.response?.data?.error || "No se pudo guardar el acta");
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="rounded-lg bg-white p-5 text-center text-gray-500 sm:p-8">Cargando...</div>;

  return (
    <div className="space-y-5 sm:space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <button onClick={() => navigate("/entregas")} className="text-sm font-semibold text-primary">Volver al inicio de actas</button>
          <h1 className="mt-2 text-2xl font-bold leading-tight text-gray-900 sm:text-3xl">{editando ? "Editar acta de entrega" : "Crear acta de entrega"}</h1>
          <p className="mt-1 text-sm text-gray-500">Mientras este abierta puedes ajustarla. Al entregar el acta ya no se modifica.</p>
        </div>
        <button disabled={saving} onClick={saveActa} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60 sm:w-auto">
          <Save size={16} /> {editando ? "Guardar cambios" : "Guardar acta"}
        </button>
      </div>

      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          <Field label="Fecha"><input value={fechaActa} onChange={(e) => setFechaActa(e.target.value)} type="date" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field>
          <Field label="Nombre acta"><input value={nombreActa} readOnly className="w-full rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm" /></Field>
          <Field label="Turno"><select value={turno} onChange={(e) => setTurno(e.target.value as TurnoKey)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"><option value="mañana">Mañana</option><option value="tarde">Tarde</option><option value="noche">Noche</option></select></Field>
          <Field label="Sede"><input value={sede} onChange={(e) => setSede(e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field>
        </div>
        <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
          <LeaderBox title="Lider entrega" name={empleado?.colaborador ?? "Sin empleado relacionado"} email={empleado?.email ?? ""} />
          <label className="rounded-lg border border-gray-200 p-4">
            <span className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500">Lider que recibe</span>
            <select value={liderRecibeId} onChange={(e) => setLiderRecibeId(Number(e.target.value))} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
              {lideres.length === 0 && <option value="">No hay usuarios con rol lider</option>}
              {lideres.map((leader) => <option key={leader.id} value={leader.id}>{leader.colaborador} - {leader.sede || leader.portal_user_email || leader.email || "Sin sede"}</option>)}
            </select>
            <span className="mt-2 inline-flex items-center gap-1 text-xs text-gray-500"><Mail size={13} /> Se notificara por correo al cerrar acta</span>
            {empleadosNoLideres > 0 && (
              <span className="mt-2 block text-xs font-medium text-amber-700">
                {empleadosNoLideres} empleados activos no aparecen aqui porque no tienen usuario con rol lider en el portal.
              </span>
            )}
          </label>
        </div>
      </section>

      <section className="grid grid-cols-1 gap-4 lg:grid-cols-[280px_1fr] lg:gap-5">
        <aside className="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
          <div className="mb-3 px-2 text-xs font-bold uppercase tracking-wide text-gray-500">Items</div>
          <div className="flex gap-2 overflow-x-auto pb-1 lg:block lg:space-y-2 lg:overflow-visible lg:pb-0">
            {entregaCategories.map((category, index) => (
              <button key={category.key} onClick={() => setActiveCategory(category.key)} className={`flex min-w-[190px] items-center justify-between rounded-md border px-3 py-3 text-left text-sm lg:w-full lg:min-w-0 ${activeCategory === category.key ? "border-primary bg-primary/10 text-primary" : "border-gray-200 hover:bg-gray-50"}`}>
                <span className="font-semibold">{index + 1}. {category.label}</span>
                <span className="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-gray-500">{items.filter((item) => item.categoryKey === category.key).length}</span>
              </button>
            ))}
          </div>
        </aside>
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
          {groupedItems.map(({ category, items: categoryItems }) => category.key === activeCategory && (
            <div key={category.key}>
              <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><h2 className="text-xl font-bold text-gray-900">{category.label}</h2><p className="text-sm text-gray-500">Selecciona la subcategoria y escribe la novedad.</p></div>
                <button onClick={() => addItem(category.key)} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white sm:w-auto"><Plus size={16} /> Agregar</button>
              </div>
              <div className="space-y-3">
                {categoryItems.length === 0 && <button onClick={() => addItem(category.key)} className="flex min-h-32 w-full items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-sm font-semibold text-gray-500">Agregar primera novedad</button>}
                {categoryItems.map((item) => (
                  <div key={item.id} className="rounded-lg border border-gray-200 p-4">
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-[180px_1fr_auto]">
                      <Field label="Subcategoria"><select value={item.subcategoryKey} onChange={(e) => updateItem(item.id, { subcategoryKey: e.target.value })} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{category.subcategories.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}</select></Field>
                      {category.key === "personal" && <Field label="Persona"><select value={item.persona ?? ""} onChange={(e) => updateItem(item.id, { persona: e.target.value })} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{personas.map((p) => <option key={p.id}>{p.colaborador}</option>)}</select></Field>}
                      <button onClick={() => setItems((prev) => prev.filter((entry) => entry.id !== item.id))} className="inline-flex h-10 w-full items-center justify-center rounded-md border border-red-200 px-3 text-red-600 hover:bg-red-50 lg:mt-5 lg:w-auto" title="Eliminar"><Trash2 size={16} /></button>
                    </div>
                    <Field label="Novedad"><textarea value={item.descripcion} onChange={(e) => updateItem(item.id, { descripcion: e.target.value })} rows={3} className="w-full resize-y rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-lg font-bold text-gray-900">Revisión de novedades</h2>
            <p className="text-sm text-gray-500">Aquí ves lo que se guardará en el acta antes de continuar.</p>
          </div>
          <span className="inline-flex w-fit rounded-full bg-primary/10 px-3 py-1 text-sm font-bold text-primary">
            {totalNovedades} {totalNovedades === 1 ? "novedad" : "novedades"}
          </span>
        </div>

        {totalNovedades === 0 ? (
          <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm font-semibold text-gray-500">
            Aún no hay novedades con descripción.
          </div>
        ) : (
          <div className="space-y-4">
            {resumenNovedades.map(({ category, items: categoryItems }) => (
              <div key={category.key} className="rounded-md border border-gray-200">
                <div className="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-4 py-2">
                  <span className="text-sm font-bold text-gray-800">{category.label}</span>
                  <span className="text-xs font-semibold text-gray-500">{categoryItems.length}</span>
                </div>
                <div className="divide-y divide-gray-100">
                  {categoryItems.map((item) => (
                    <div key={item.id} className="px-4 py-3">
                      <div className="text-sm font-semibold text-gray-900">
                        {item.subcategoryLabel}{item.persona ? ` - ${item.persona}` : ""}
                      </div>
                      <p className="mt-1 whitespace-pre-wrap text-sm text-gray-600">{item.descripcion.trim()}</p>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5"><Field label="Observaciones generales"><textarea value={observaciones} onChange={(e) => setObservaciones(e.target.value)} rows={4} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" /></Field></section>
      <div className="flex justify-end"><button onClick={saveActa} disabled={saving} className="inline-flex w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60 sm:w-auto"><Check size={16} /> Guardar y continuar</button></div>
    </div>
  );
}

function novedadToDraft(novedad?: Novedad): DraftItem {
  if (!novedad) {
    const personal = entregaCategories.find((category) => category.key === "personal")!;
    return {
      id: crypto.randomUUID(),
      categoryKey: "personal",
      subcategoryKey: personal.subcategories[0].key,
      descripcion: "",
    };
  }

  const category = entregaCategories.find((entry) => entry.apiCategory === novedad.categoria) ?? entregaCategories[0];
  const titleParts = (novedad.titulo ?? "").split(" - ");
  const subLabel = titleParts[1] ?? "";
  const subcategory = category.subcategories.find((entry) => entry.label === subLabel) ?? category.subcategories[0];
  let descripcion = novedad.descripcion ?? "";
  let persona = "";

  if (category.key === "personal" && descripcion.includes(": ")) {
    const [possiblePersona, ...rest] = descripcion.split(": ");
    persona = possiblePersona;
    descripcion = rest.join(": ");
  }

  return {
    id: String(novedad.id ?? crypto.randomUUID()),
    categoryKey: category.key,
    subcategoryKey: subcategory.key,
    persona,
    descripcion,
  };
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="space-y-1"><span className="block text-xs font-bold uppercase tracking-wide text-gray-500">{label}</span>{children}</label>;
}

function LeaderBox({ title, name, email }: { title: string; name: string; email: string }) {
  return <div className="rounded-lg border border-gray-200 p-4"><span className="mb-2 block text-xs font-bold uppercase tracking-wide text-gray-500">{title}</span><div className="flex items-center gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><UserRound size={18} /></div><div className="min-w-0"><div className="break-words font-semibold text-gray-900">{name}</div><div className="break-all text-xs text-gray-500">{email}</div></div></div></div>;
}
