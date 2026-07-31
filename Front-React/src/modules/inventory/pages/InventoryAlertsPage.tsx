import { useEffect, useMemo, useRef, useState } from "react";
import {
  BellRing,
  CheckCircle2,
  ChevronRight,
  Mail,
  PackageSearch,
  Plus,
  RefreshCw,
  Search,
  Send,
  Trash2,
} from "lucide-react";
import { getStores, type Store } from "../services/inventoryService";
import {
  addProductToInventoryAlert,
  addTopToInventoryAlert,
  deleteInventoryAlert,
  getInventoryAlert,
  getInventoryAlertCurrent,
  getInventoryAlertFilterOptions,
  getInventoryAlertTop,
  listInventoryAlerts,
  removeProductFromInventoryAlert,
  saveInventoryAlert,
  searchInventoryAlertProducts,
  sendInventoryAlertNow,
  sendInventoryAlertTest,
  type InventoryAlertList,
  type InventoryAlertCurrentProduct,
  type InventoryAlertProduct,
  type InventoryAlertRecipient,
} from "../services/inventoryAlertsService";

const inputClass = "h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-slate-400";

type FormState = {
  name: string;
  is_active: boolean;
  auto_send: boolean;
  frequency_days: number;
  top_months: number;
  top_limit: number;
  store_ids: number[];
  recipients: InventoryAlertRecipient[];
};

const emptyForm: FormState = {
  name: "Top inventario",
  is_active: true,
  auto_send: true,
  frequency_days: 1,
  top_months: 3,
  top_limit: 50,
  store_ids: [],
  recipients: [],
};

export default function InventoryAlertsPage() {
  const [stores, setStores] = useState<Store[]>([]);
  const [lists, setLists] = useState<InventoryAlertList[]>([]);
  const [filterOptions, setFilterOptions] = useState({ brands: [] as string[], providers: [] as string[] });
  const [selected, setSelected] = useState<InventoryAlertList | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [topRows, setTopRows] = useState<InventoryAlertProduct[]>([]);
  const [currentAlerts, setCurrentAlerts] = useState<InventoryAlertCurrentProduct[]>([]);
  const [currentAlertsLoading, setCurrentAlertsLoading] = useState(false);
  const [selectionFilters, setSelectionFilters] = useState({ search: "", provider: "", brand: "" });
  const [searchRows, setSearchRows] = useState<InventoryAlertProduct[]>([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    void bootstrap();
  }, []);

  const bootstrap = async () => {
    try {
      setLoading(true);
      const [storeRows, listRows, optionRows] = await Promise.all([getStores(), listInventoryAlerts(), getInventoryAlertFilterOptions()]);
      setStores(storeRows);
      setLists(listRows);
      setFilterOptions({ brands: optionRows.brands ?? [], providers: optionRows.providers ?? [] });
      if (listRows[0]) {
        await selectList(listRows[0].id);
      }
    } catch (err: any) {
      setError(err?.response?.data?.message || "No se pudo cargar alertas de inventario.");
    } finally {
      setLoading(false);
    }
  };

  const selectList = async (id: number) => {
    const detail = await getInventoryAlert(id);
    setSelected(detail);
    setCurrentAlerts(detail.current_alerts ?? []);
    setForm({
      name: detail.name,
      is_active: detail.is_active,
      auto_send: detail.auto_send,
      frequency_days: detail.frequency_days ?? 1,
      top_months: detail.top_months,
      top_limit: detail.top_limit,
      store_ids: detail.stores.map((store) => store.id),
      recipients: detail.recipients ?? [],
    });
    setTopRows([]);
    setSearchRows([]);
    setSelectionFilters({ search: "", provider: "", brand: "" });
  };

  const refreshLists = async (nextSelectedId?: number) => {
    const listRows = await listInventoryAlerts();
    setLists(listRows);
    if (nextSelectedId) {
      await selectList(nextSelectedId);
    }
  };

  const save = async () => {
    if (!form.name.trim()) {
      setError("Escribe un nombre para la lista.");
      return;
    }
    if (form.store_ids.length === 0) {
      setError("Selecciona al menos una tienda.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");
      const saved = await saveInventoryAlert(
        {
          ...form,
          frequency_days: clampNumber(form.frequency_days, 1, 30),
          top_months: clampNumber(form.top_months, 1, 12),
          top_limit: clampNumber(form.top_limit, 1, 200),
          product_ids: selected?.products?.map((product) => product.id) ?? [],
        },
        selected?.id
      );
      setMessage("Lista guardada correctamente.");
      await refreshLists(saved.id);
    } catch (err: any) {
      setError(err?.response?.data?.message || "No se pudo guardar la lista.");
    } finally {
      setLoading(false);
    }
  };

  const createNew = () => {
    setSelected(null);
    setForm(emptyForm);
    setTopRows([]);
    setCurrentAlerts([]);
    setSelectionFilters({ search: "", provider: "", brand: "" });
    setSearchRows([]);
    setMessage("");
    setError("");
  };

  const removeList = async () => {
    if (!selected || !confirm("¿Eliminar esta lista de alertas?")) return;
    await deleteInventoryAlert(selected.id);
    setSelected(null);
    setForm(emptyForm);
    setCurrentAlerts([]);
    setSelectionFilters({ search: "", provider: "", brand: "" });
    await refreshLists();
  };

  const previewTop = async () => {
    try {
      setError("");
      const rows = await getInventoryAlertTop({
        store_ids: form.store_ids,
        months: form.top_months,
        limit: form.top_limit,
        search: selectionFilters.search,
        brand: selectionFilters.brand,
        provider: selectionFilters.provider,
      });
      setTopRows(rows);
    } catch (err: any) {
      setError(err?.response?.data?.message || "No se pudo calcular el top.");
    }
  };

  const addTop = async () => {
    if (!selected) {
      setError("Guarda la lista antes de agregar top.");
      return;
    }
    const products = await addTopToInventoryAlert(selected.id, {
      months: form.top_months,
      limit: form.top_limit,
      search: selectionFilters.search,
      brand: selectionFilters.brand,
      provider: selectionFilters.provider,
    });
    updateSelectedProducts(products);
    setCurrentAlerts([]);
    setMessage("Top filtrado agregado a la lista.");
  };

  const doSearch = async () => {
    if (!selectionFilters.search.trim() && !selectionFilters.brand.trim() && !selectionFilters.provider.trim()) return;
    setSearchRows(await searchInventoryAlertProducts(selectionFilters));
  };

  const addProduct = async (productId: number) => {
    if (!selected) {
      setError("Guarda la lista antes de agregar productos.");
      return;
    }
    updateSelectedProducts(await addProductToInventoryAlert(selected.id, productId));
    setCurrentAlerts([]);
  };

  const removeProduct = async (productId: number) => {
    if (!selected) return;
    updateSelectedProducts(await removeProductFromInventoryAlert(selected.id, productId));
    setCurrentAlerts([]);
  };

  const addRecipient = () => {
    setForm((current) => ({
      ...current,
      recipients: [...current.recipients, { name: "", email: "", is_active: true }],
    }));
  };

  const sendNow = async () => {
    if (!selected) return;
    const result = await sendInventoryAlertNow(selected.id);
    setMessage(result.message);
    await selectList(selected.id);
  };

  const sendTest = async () => {
    if (!selected) return;
    const result = await sendInventoryAlertTest(selected.id);
    setMessage(result.message);
  };

  const loadCurrentAlerts = async () => {
    if (!selected) return;
    try {
      setCurrentAlertsLoading(true);
      setError("");
      setCurrentAlerts(await getInventoryAlertCurrent(selected.id));
    } catch (err: any) {
      setError(err?.response?.data?.message || "No se pudieron calcular las alertas actuales.");
    } finally {
      setCurrentAlertsLoading(false);
    }
  };

  const selectedStoreLabels = useMemo(
    () => stores.filter((store) => form.store_ids.includes(store.id)).map((store) => store.code).join(" + "),
    [stores, form.store_ids]
  );

  const updateSelectedProducts = (products: InventoryAlertProduct[]) => {
    setSelected((current) => current ? { ...current, products, products_count: products.length } : current);
    setLists((current) => current.map((list) => (
      selected && list.id === selected.id ? { ...list, products_count: products.length } : list
    )));
  };

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-6 text-slate-950 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-[1760px] space-y-6">
        <section className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div className="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-sm font-semibold text-rose-700">
              <BellRing className="h-4 w-4" />
              Alertas de inventario
            </div>
            <h1 className="mt-3 text-3xl font-black tracking-tight">Listas de vigilancia y correo diario</h1>
            <p className="mt-2 max-w-3xl text-sm font-medium text-slate-500">
              Controla productos clave, top por ventas USD, destinatarios y alertas criticas por tienda.
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button onClick={bootstrap} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700">
              <RefreshCw className="h-4 w-4" />
              Actualizar
            </button>
            <button onClick={createNew} className="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white">
              <Plus className="h-4 w-4" />
              Nueva lista
            </button>
          </div>
        </section>

        {message && <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">{message}</div>}
        {error && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{error}</div>}

        <section className="grid gap-5 xl:grid-cols-[330px_1fr]">
          <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 text-sm font-black uppercase text-slate-400">Listas globales</div>
            <div className="space-y-2">
              {lists.map((list) => (
                <button
                  key={list.id}
                  onClick={() => void selectList(list.id)}
                  className={`w-full rounded-xl border px-3 py-3 text-left transition ${
                    selected?.id === list.id ? "border-slate-950 bg-slate-950 text-white" : "border-slate-100 bg-white hover:bg-slate-50"
                  }`}
                >
                  <div className="font-black">{list.name}</div>
                  <div className={`mt-1 text-xs ${selected?.id === list.id ? "text-slate-300" : "text-slate-500"}`}>
                    {list.products_count ?? 0} productos | {list.recipients_count ?? 0} correos
                  </div>
                </button>
              ))}
              {lists.length === 0 && <div className="rounded-xl bg-slate-50 p-4 text-sm font-semibold text-slate-500">Sin listas creadas.</div>}
            </div>
          </aside>

          <main className="space-y-5">
            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="grid gap-4 lg:grid-cols-[1.2fr_.45fr_.45fr_.45fr]">
                <Field label="Nombre">
                  <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} />
                </Field>
                <Field label="Enviar cada (dias)">
                  <input type="number" min={1} max={30} value={form.frequency_days} onChange={(e) => setForm({ ...form, frequency_days: clampNumber(e.target.value, 1, 30) })} className={inputClass} />
                </Field>
                <Field label="Meses top">
                  <input type="number" min={1} max={12} value={form.top_months} onChange={(e) => setForm({ ...form, top_months: clampNumber(e.target.value, 1, 12) })} className={inputClass} />
                </Field>
                <Field label="Limite top">
                  <input type="number" min={1} max={200} value={form.top_limit} onChange={(e) => setForm({ ...form, top_limit: clampNumber(e.target.value, 1, 200) })} className={inputClass} />
                </Field>
              </div>

              <div className="mt-4 flex flex-wrap gap-3">
                <Toggle label="Activa" checked={form.is_active} onChange={(value) => setForm({ ...form, is_active: value })} />
                <Toggle label="Enviar automatico" checked={form.auto_send} onChange={(value) => setForm({ ...form, auto_send: value })} />
                <button onClick={save} disabled={loading} className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-black text-white disabled:opacity-50">
                  Guardar
                </button>
                {selected && (
                  <>
                    <button onClick={sendNow} className="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-black text-white">
                      <Send className="h-4 w-4" />
                      Enviar resumen ahora
                    </button>
                    <button onClick={sendTest} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-4 py-2 text-sm font-black text-slate-700">
                      <Mail className="h-4 w-4" />
                      Enviar prueba
                    </button>
                    <button onClick={removeList} className="inline-flex items-center gap-2 rounded-lg border border-rose-200 px-4 py-2 text-sm font-black text-rose-700">
                      <Trash2 className="h-4 w-4" />
                      Eliminar
                    </button>
                  </>
                )}
              </div>
            </section>

            <section className="grid gap-5 xl:grid-cols-2">
              <Panel title="Tiendas vigiladas" subtitle={selectedStoreLabels || "Selecciona al menos una tienda"}>
                <div className="grid gap-2 sm:grid-cols-2">
                  {stores.map((store) => {
                    const checked = form.store_ids.includes(store.id);
                    return (
                      <label key={store.id} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold ${checked ? "border-slate-950 bg-slate-950 text-white" : "border-slate-200 bg-white"}`}>
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => setForm((current) => ({
                            ...current,
                            store_ids: checked ? current.store_ids.filter((id) => id !== store.id) : [...current.store_ids, store.id],
                          }))}
                        />
                        {store.code} - {store.name}
                      </label>
                    );
                  })}
                </div>
              </Panel>

              <Panel title="Destinatarios" subtitle="Nombre opcional y correo configurable">
                <div className="space-y-2">
                  {form.recipients.map((recipient, index) => (
                    <div key={index} className="grid gap-2 sm:grid-cols-[1fr_1.3fr_auto]">
                      <input value={recipient.name ?? ""} onChange={(e) => updateRecipient(index, { name: e.target.value })} placeholder="Nombre" className={inputClass} />
                      <input value={recipient.email} onChange={(e) => updateRecipient(index, { email: e.target.value })} placeholder="correo@dominio.com" className={inputClass} />
                      <button onClick={() => setForm((current) => ({ ...current, recipients: current.recipients.filter((_, i) => i !== index) }))} className="rounded-lg border border-rose-200 px-3 text-rose-700">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  ))}
                  <button onClick={addRecipient} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-black text-slate-700">
                    <Plus className="h-4 w-4" />
                    Agregar destinatario
                  </button>
                </div>
              </Panel>
            </section>

            <Panel title="Seleccionar SKU" subtitle="Filtra por marca, proveedor o SKU y agrega productos a la vigilancia">
              <div className="mb-4 grid gap-2 rounded-xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-[1fr_.9fr_.9fr_auto]">
                <label className="relative block">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <input
                    value={selectionFilters.search}
                    onChange={(e) => setSelectionFilters((current) => ({ ...current, search: e.target.value }))}
                    onKeyDown={(e) => e.key === "Enter" && void doSearch()}
                    placeholder="SKU o descripcion"
                    className="h-10 w-full rounded-lg border border-slate-200 bg-white pl-9 pr-3 text-sm font-semibold text-slate-900 outline-none focus:border-slate-400"
                  />
                </label>
                <FilterCombobox
                  label="Proveedor"
                  value={selectionFilters.provider}
                  options={filterOptions.providers}
                  onChange={(provider) => setSelectionFilters((current) => ({ ...current, provider }))}
                />
                <FilterCombobox
                  label="Marca"
                  value={selectionFilters.brand}
                  options={filterOptions.brands}
                  onChange={(brand) => setSelectionFilters((current) => ({ ...current, brand }))}
                />
                <button onClick={doSearch} className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-slate-950 px-3 text-sm font-black text-white">
                  <Search className="h-4 w-4" />
                  Buscar
                </button>
              </div>

              <div className="grid gap-5 xl:grid-cols-2">
                <section>
                  <div className="mb-3 flex flex-wrap items-center gap-2">
                    <h3 className="mr-auto text-sm font-black uppercase text-slate-500">Top vendidos filtrado</h3>
                    <button onClick={previewTop} className="rounded-lg bg-slate-950 px-3 py-2 text-sm font-black text-white">Ver top</button>
                    <button onClick={addTop} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-black text-slate-700">Agregar top filtrado</button>
                  </div>
                  <ProductList products={topRows} actionLabel="Agregar" onAction={(product) => addProduct(product.id)} />
                </section>

                <section>
                  <div className="mb-3 flex h-10 items-center">
                    <h3 className="text-sm font-black uppercase text-slate-500">Resultados del catalogo</h3>
                  </div>
                  <ProductList products={searchRows} actionLabel="Agregar" onAction={(product) => addProduct(product.id)} />
                </section>
              </div>
            </Panel>

            <Panel title="Productos vigilados" subtitle={`${selected?.products?.length ?? 0} productos en esta lista`}>
              <ProductList products={selected?.products ?? []} actionLabel="Quitar" onAction={(product) => removeProduct(product.id)} danger />
            </Panel>

            <Panel title="Alertas actuales" subtitle="Critico, sin stock y riesgo alto, con contexto por tienda">
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <button
                  onClick={loadCurrentAlerts}
                  disabled={!selected || currentAlertsLoading}
                  className="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-black text-white disabled:opacity-50"
                >
                  <RefreshCw className={`h-4 w-4 ${currentAlertsLoading ? "animate-spin" : ""}`} />
                  {currentAlertsLoading ? "Calculando..." : "Calcular alertas actuales"}
                </button>
                <span className="text-xs font-semibold text-slate-500">Este calculo consulta inventario y ventas solo cuando lo pides.</span>
              </div>
              <div className="space-y-3">
                {currentAlerts.map((product) => (
                  <div key={product.product_id} className="rounded-xl border border-slate-100 p-3">
                    <div className="font-black">{product.product_code} - {product.description}</div>
                    {(product.brand || product.provider_name) && (
                      <div className="mt-1 text-xs font-bold text-slate-400">
                        {[product.brand && `Marca ${product.brand}`, product.provider_name && `Proveedor ${product.provider_name}`].filter(Boolean).join(" | ")}
                      </div>
                    )}
                    <div className="mt-2 grid gap-2 lg:grid-cols-2">
                      {product.stores.map((store, index) => (
                        <div key={index} className="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                          <div className="flex justify-between gap-2">
                            <span className="font-black">{store.store_code ?? store.store_name}</span>
                            <span className={levelClass(store.level)}>{store.label ?? store.level}</span>
                          </div>
                          <div className="mt-1 text-slate-500">Inventario {fmt(store.stock_actual)} | Dias disponible {fmt(store.dias_disponibles)} | Estado {store.label ?? store.level ?? "-"}</div>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
                {currentAlerts.length === 0 && <Empty text="Presiona calcular para ver las alertas actuales de esta lista." />}
              </div>
            </Panel>

            <Panel title="Historial" subtitle="Enviados, fallidos y saltados">
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead className="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th className="p-2">Fecha</th><th className="p-2">Modo</th><th className="p-2">Estado</th><th className="p-2">Enviados</th><th className="p-2">Saltados</th><th className="p-2">Mensaje</th></tr>
                  </thead>
                  <tbody>
                    {(selected?.history ?? []).map((run) => (
                      <tr key={run.id} className="border-b border-slate-100">
                        <td className="p-2">{run.started_at ?? "-"}</td>
                        <td className="p-2">{run.mode}</td>
                        <td className="p-2">{run.status}</td>
                        <td className="p-2">{run.sent_count}</td>
                        <td className="p-2">{run.skipped_count}</td>
                        <td className="p-2">{run.message ?? "-"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          </main>
        </section>
      </div>
    </div>
  );

  function updateRecipient(index: number, patch: Partial<InventoryAlertRecipient>) {
    setForm((current) => ({
      ...current,
      recipients: current.recipients.map((recipient, i) => i === index ? { ...recipient, ...patch } : recipient),
    }));
  }
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block"><span className="mb-2 block text-sm font-black text-slate-600">{label}</span>{children}</label>;
}

function Panel({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="mb-4">
        <h2 className="text-lg font-black text-slate-950">{title}</h2>
        {subtitle && <p className="mt-1 text-sm font-medium text-slate-500">{subtitle}</p>}
      </div>
      {children}
    </section>
  );
}

function FilterCombobox({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: string[];
  onChange: (value: string) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  const filteredOptions = useMemo(() => {
    const normalizedQuery = normalizeForSearch(query);
    if (!normalizedQuery) return options;

    return options.filter((option) => normalizeForSearch(option).includes(normalizedQuery));
  }, [options, query]);

  useEffect(() => {
    if (!isOpen) return;

    const timeoutId = window.setTimeout(() => inputRef.current?.focus(), 0);
    const handlePointerDown = (event: PointerEvent) => {
      if (!wrapperRef.current?.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener("pointerdown", handlePointerDown);

    return () => {
      window.clearTimeout(timeoutId);
      document.removeEventListener("pointerdown", handlePointerDown);
    };
  }, [isOpen]);

  const selectOption = (nextValue: string) => {
    onChange(nextValue);
    setQuery("");
    setIsOpen(false);
  };

  return (
    <div ref={wrapperRef} className="relative">
      <button
        type="button"
        onClick={() => setIsOpen((current) => !current)}
        className="flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 text-left text-sm font-semibold outline-none transition hover:bg-slate-50 focus:border-slate-400"
      >
        <span className={`min-w-0 truncate ${value ? "text-slate-900" : "text-slate-500"}`}>
          {value || label}
        </span>
        <ChevronRight className={`h-4 w-4 shrink-0 text-slate-400 transition ${isOpen ? "rotate-90" : ""}`} />
      </button>

      {isOpen && (
        <div className="absolute left-0 right-0 top-[calc(100%+0.5rem)] z-30 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_18px_50px_rgba(15,23,42,0.16)]">
          <div className="border-b border-slate-100 p-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                ref={inputRef}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Escape") setIsOpen(false);
                  if (event.key === "Enter" && filteredOptions.length === 1) selectOption(filteredOptions[0]);
                }}
                placeholder={`Buscar ${label.toLowerCase()}`}
                className="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm font-semibold outline-none focus:border-slate-400 focus:bg-white"
              />
            </div>
            <div className="mt-2 text-xs font-bold text-slate-400">
              {query.trim() ? `${filteredOptions.length} resultados` : `${options.length} opciones`}
            </div>
          </div>

          <div className="max-h-64 overflow-y-auto p-2">
            <button
              type="button"
              onClick={() => selectOption("")}
              className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition ${
                !value ? "bg-slate-950 font-black text-white" : "font-semibold text-slate-700 hover:bg-slate-50"
              }`}
            >
              Todos
              {!value && <CheckCircle2 className="h-4 w-4" />}
            </button>

            {filteredOptions.map((option) => {
              const selected = option === value;

              return (
                <button
                  key={option}
                  type="button"
                  onClick={() => selectOption(option)}
                  className={`mt-1 flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm transition ${
                    selected ? "bg-slate-950 font-black text-white" : "font-semibold text-slate-700 hover:bg-slate-50"
                  }`}
                >
                  <span className="min-w-0 truncate">{option}</span>
                  {selected && <CheckCircle2 className="h-4 w-4 shrink-0" />}
                </button>
              );
            })}

            {filteredOptions.length === 0 && (
              <div className="px-3 py-6 text-center text-sm font-bold text-slate-400">Sin resultados</div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <button type="button" onClick={() => onChange(!checked)} className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-black ${checked ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-500"}`}>
      <CheckCircle2 className="h-4 w-4" />
      {label}
    </button>
  );
}

function ProductList({ products, actionLabel, onAction, danger = false }: { products: InventoryAlertProduct[]; actionLabel: string; onAction: (product: InventoryAlertProduct) => void; danger?: boolean }) {
  if (products.length === 0) return <Empty text="Sin productos para mostrar." />;
  return (
    <div className="max-h-[420px] space-y-2 overflow-y-auto pr-1">
      {products.map((product) => (
        <div key={product.id} className="grid gap-3 rounded-xl border border-slate-100 px-3 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
          <div className="min-w-0">
            <div className="flex items-center gap-2 font-black">
              <PackageSearch className="h-4 w-4 text-slate-400" />
              {product.product_code}
            </div>
            <div className="mt-1 line-clamp-2 text-sm font-medium text-slate-600">{product.description}</div>
            {(product.brand || product.provider_name) && (
              <div className="mt-1 text-xs font-bold text-slate-400">
                {[product.brand && `Marca ${product.brand}`, product.provider_name && `Proveedor ${product.provider_name}`].filter(Boolean).join(" | ")}
              </div>
            )}
            {product.total_usd !== undefined && <div className="mt-1 text-xs font-bold text-emerald-700">USD {fmt(product.total_usd)} | Und {fmt(product.total_units)}</div>}
          </div>
          <button onClick={() => onAction(product)} className={`rounded-lg px-3 py-2 text-sm font-black ${danger ? "bg-rose-50 text-rose-700" : "bg-slate-950 text-white"}`}>
            {actionLabel}
          </button>
        </div>
      ))}
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <div className="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm font-bold text-slate-400">{text}</div>;
}

function clampNumber(value: string | number, min: number, max: number): number {
  const parsed = typeof value === "number" ? value : Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) return min;
  return Math.max(min, Math.min(max, parsed));
}

function fmt(value: number | null | undefined): string {
  return new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 }).format(Number(value ?? 0));
}

function normalizeForSearch(value: string): string {
  return value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
}

function levelClass(level?: string | null): string {
  if (level === "critico" || level === "sin_stock") return "font-black text-rose-700";
  if (level === "alto") return "font-black text-amber-700";
  if (level === "estable") return "font-black text-emerald-700";
  return "font-black text-slate-500";
}
