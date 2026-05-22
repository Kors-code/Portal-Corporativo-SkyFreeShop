import { useEffect, useMemo, useState } from "react";
import * as invApi from "../../../services/inventoryImports.service";
import * as salesApi from "../../../services/imports.service"; // reutilizamos getStores

type ImportBatch = {
  id: number;
  filename: string;
  store_id?: number;
  to_date?: string;
  checksum?: string;
  status?: string;
  rows?: number;
  rows_imported?: number;
  created_at?: string;
  note?: string;
  [k: string]: any;
};

type StoreOption = {
  id: number;
  name: string;
  code: string;
  type?: string | null;
};

export default function InventoryImportsManagerPage() {
  const [stores, setStores] = useState<StoreOption[]>([]);
  const [storesLoading, setStoresLoading] = useState(false);
  const [selectedStore, setSelectedStore] = useState<number | "">("");
  const [toDate, setToDate] = useState<string>("");

  const [batches, setBatches] = useState<ImportBatch[]>([]);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [selectedBatch, setSelectedBatch] = useState<any | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<string>("");

  const [filterFilename, setFilterFilename] = useState("");
  const [filterFromDate, setFilterFromDate] = useState("");
  const [filterToDate, setFilterToDate] = useState("");

  useEffect(() => {
    void loadStores();
    void load();
  }, []);

  async function loadStores() {
    setStoresLoading(true);
    try {
      const res = await salesApi.getStores();
      setStores(res ?? []);
    } catch (e: any) {
      console.error(e);
      setError(e?.response?.data?.message || e?.message || "Error cargando tiendas");
    } finally {
      setStoresLoading(false);
    }
  }

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const res = await invApi.getImports();
      const data = Array.isArray(res) ? res : res?.data ?? res;
      setBatches(data ?? []);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando importaciones");
    } finally {
      setLoading(false);
    }
  }

  async function handleUpload() {
    if (!file) {
      setMsg("Selecciona un archivo");
      return;
    }
    if (!selectedStore) {
      setError("Selecciona una tienda para importar inventario.");
      return;
    }

    setUploading(true);
    setError(null);
    setMsg("");

    try {
      const res = await invApi.importInventoryFile(
        file,
        Number(selectedStore),
        toDate || undefined
      );

      const rows = res?.rows ?? res?.data?.rows ?? null;
      const batchId = res?.batch_id ?? res?.data?.batch_id ?? null;
      const errs = res?.errors ?? [];

      setMsg(
        `Importación exitosa${rows ? `: ${rows} filas` : ""}${
          batchId ? ` (batch ${batchId})` : ""
        }${errs.length ? ` — con ${errs.length} advertencias` : ""}`
      );
      setFile(null);
      await load();
    } catch (e: any) {
      const data = e?.response?.data;
      if (data && typeof data === "object" && data.message) {
        let full = data.message;
        if (data.error) full += ` — ${data.error}`;
        setError(String(full));
      } else {
        setError(e?.message || "Error importando");
      }
    } finally {
      setUploading(false);
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  }

  function toggleSelectAll() {
    const ids = filteredBatches.map((b) => b.id);
    const allSelected = ids.length > 0 && ids.every((id) => selectedIds.includes(id));
    setSelectedIds(allSelected ? [] : ids);
  }

  async function handleDelete(id: number) {
    if (!confirm("¿Eliminar esta importación de inventario? Esta acción no se puede deshacer.")) return;
    setDeletingId(id);
    setError(null);
    try {
      await invApi.deleteImport(id);
      setBatches((prev) => prev.filter((b) => b.id !== id));
      setSelectedIds((prev) => prev.filter((x) => x !== id));
      if (selectedBatch?.id === id) setSelectedBatch(null);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando");
    } finally {
      setDeletingId(null);
    }
  }

  async function handleBulkDelete() {
    if (!selectedIds.length) return;
    if (
      !confirm(
        `¿Eliminar ${selectedIds.length} importaciones? Esta acción no se puede deshacer.`
      )
    )
      return;

    setBulkDeleting(true);
    setError(null);
    try {
      await invApi.deleteImports(selectedIds);
      setBatches((prev) => prev.filter((b) => !selectedIds.includes(b.id)));
      setSelectedIds([]);
      setSelectedBatch(null);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando en bloque");
    } finally {
      setBulkDeleting(false);
    }
  }

  async function showDetails(batchId: number) {
    setSelectedBatch({ loading: true });
    try {
      const res = await invApi.getImport(batchId);
      const data = res?.data ?? res;
      setSelectedBatch(data);
    } catch (e: any) {
      setError(e?.response?.data?.message || "Error cargando detalles");
      setSelectedBatch(null);
    }
  }

  const storeMap = useMemo(() => {
    const m: Record<number, StoreOption> = {};
    stores.forEach((s) => (m[s.id] = s));
    return m;
  }, [stores]);

  const filteredBatches = useMemo(() => {
    return batches.filter((b) => {
      if (
        filterFilename &&
        !b.filename?.toLowerCase().includes(filterFilename.toLowerCase())
      )
        return false;

      if (b.created_at) {
        const time = new Date(b.created_at).getTime();
        if (filterFromDate) {
          const from = new Date(filterFromDate).setHours(0, 0, 0, 0);
          if (time < from) return false;
        }
        if (filterToDate) {
          const to = new Date(filterToDate + "T23:59:59").getTime();
          if (time > to) return false;
        }
      }

      return true;
    });
  }, [batches, filterFilename, filterFromDate, filterToDate]);

  const getRowsArray = (batch: any) => {
    if (!batch) return [];
    const candidates = ["rows_data", "rows", "items", "data", "inventory", "errors"];
    for (const k of candidates) {
      if (Array.isArray(batch[k])) return batch[k];
    }
    return [];
  };

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">📦 Importaciones de Inventario</h1>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

      <div className="bg-white p-4 rounded shadow flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div className="flex items-end gap-3 flex-wrap">
          <div className="min-w-[240px]">
            <label className="text-xs text-gray-600 block mb-1">Tienda</label>
            <select
              value={selectedStore}
              onChange={(e) =>
                setSelectedStore(e.target.value ? Number(e.target.value) : "")
              }
              className="w-full border rounded px-3 py-2 text-sm"
              disabled={storesLoading}
            >
              <option value="">
                {storesLoading ? "Cargando tiendas..." : "Selecciona una tienda"}
              </option>
              {stores.map((store) => (
                <option key={store.id} value={store.id}>
                  {store.code ? `${store.code} - ` : ""}
                  {store.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="text-xs text-gray-600 block mb-1">Fecha de corte</label>
            <input
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="border rounded px-3 py-2 text-sm"
            />
          </div>

          <div>
            <label className="text-xs text-gray-600 block mb-1">Archivo</label>
            <input
              type="file"
              accept=".csv,.xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <div className="text-xs text-gray-600 mt-1">
              {file ? file.name : "No hay archivo seleccionado"}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={handleUpload}
            disabled={uploading || !file || !selectedStore}
            style={{
              backgroundColor:
                uploading || !file || !selectedStore ? "#9CA3AF" : "#840028",
              cursor:
                uploading || !file || !selectedStore ? "not-allowed" : "pointer",
            }}
            className="px-4 py-2 text-white rounded transition-opacity disabled:opacity-70"
          >
            {uploading ? "Importando..." : "Subir y procesar"}
          </button>

          <button
            onClick={() => {
              setFile(null);
              setMsg("");
              setError(null);
            }}
            className="px-3 py-2 border rounded text-sm"
          >
            Limpiar
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded shadow space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div>
            <label className="text-xs text-gray-600">Nombre del archivo</label>
            <input
              type="text"
              value={filterFilename}
              onChange={(e) => setFilterFilename(e.target.value)}
              placeholder="ej: inventario_enero"
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>

          <div>
            <label className="text-xs text-gray-600">Desde</label>
            <input
              type="date"
              value={filterFromDate}
              onChange={(e) => setFilterFromDate(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>

          <div>
            <label className="text-xs text-gray-600">Hasta</label>
            <input
              type="date"
              value={filterToDate}
              onChange={(e) => setFilterToDate(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            />
          </div>

          <div className="flex items-end">
            <button
              onClick={() => {
                setFilterFilename("");
                setFilterFromDate("");
                setFilterToDate("");
              }}
              className="w-full px-3 py-2 border rounded text-sm"
            >
              Limpiar filtros
            </button>
          </div>
        </div>
      </div>

      <div className="bg-white rounded shadow overflow-x-auto">
        <div className="p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                onChange={toggleSelectAll}
                checked={
                  filteredBatches.length > 0 &&
                  filteredBatches.every((d) => selectedIds.includes(d.id))
                }
              />
              <span className="text-sm">Seleccionar todo</span>
            </label>

            <button
              onClick={handleBulkDelete}
              disabled={selectedIds.length === 0 || bulkDeleting}
              className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
            >
              {bulkDeleting
                ? "Eliminando..."
                : `Eliminar seleccionados (${selectedIds.length})`}
            </button>
          </div>

          <div className="text-sm text-gray-600">{filteredBatches.length} registros</div>
        </div>

        {loading ? (
          <div className="p-6 text-center">Cargando…</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-3 w-12"></th>
                <th className="p-3">Archivo</th>
                <th className="p-3">Tienda</th>
                <th className="p-3">Corte</th>
                <th className="p-3">Filas</th>
                <th className="p-3">Estado</th>
                <th className="p-3">Fecha</th>
                <th className="p-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {filteredBatches.map((b) => (
                <tr key={b.id} className="border-t">
                  <td className="p-3">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(b.id)}
                      onChange={() => toggleSelect(b.id)}
                    />
                  </td>
                  <td className="p-3">{b.filename}</td>
                  <td className="p-3">
                    {b.store_id && storeMap[b.store_id]
                      ? `${storeMap[b.store_id].code ?? ""} ${storeMap[b.store_id].name ?? ""}`
                      : b.store_id ?? "-"}
                  </td>
                  <td className="p-3">{b.to_date ?? "-"}</td>
                  <td className="p-3">{b.rows ?? b.rows_imported ?? "-"}</td>
                  <td className="p-3">
                    <span
                      className="inline-block px-2 py-1 text-xs rounded"
                      style={{
                        background:
                          b.status === "processing"
                            ? "#FFF4E5"
                            : b.status === "error"
                              ? "#FEE2E2"
                              : b.status === "completed_with_errors"
                                ? "#FEF3C7"
                                : "#ECFDF5",
                      }}
                    >
                      {b.status ?? "-"}
                    </span>
                  </td>
                  <td className="p-3">
                    {b.created_at ? new Date(b.created_at).toLocaleString() : "-"}
                  </td>
                  <td className="p-3 text-right space-x-2">
                    <button
                      onClick={() => showDetails(b.id)}
                      className="text-sm text-indigo-600"
                    >
                      Detalles
                    </button>
                    <button
                      onClick={() => handleDelete(b.id)}
                      disabled={deletingId === b.id}
                      className="text-sm text-red-600"
                    >
                      {deletingId === b.id ? "Borrando..." : "Eliminar"}
                    </button>
                  </td>
                </tr>
              ))}

              {filteredBatches.length === 0 && (
                <tr>
                  <td colSpan={8} className="p-6 text-center text-gray-500">
                    No hay resultados con los filtros aplicados
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {selectedBatch && !selectedBatch.loading && (
        <div className="fixed inset-0 bg-black/40 flex justify-center items-start p-6 z-50">
          <div className="bg-white rounded p-6 max-w-4xl w-full">
            <div className="flex justify-between mb-4">
              <h3 className="font-semibold">
                Detalles - {selectedBatch.filename ?? `batch ${selectedBatch.id}`}
              </h3>
              <button onClick={() => setSelectedBatch(null)} className="text-gray-600">
                Cerrar
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <div className="text-xs text-gray-600 mb-1">Metadatos</div>
                <pre className="bg-gray-50 p-3 rounded text-xs max-h-80 overflow-auto">
                  {JSON.stringify(
                    {
                      id: selectedBatch.id,
                      filename: selectedBatch.filename,
                      store_id: selectedBatch.store_id,
                      to_date: selectedBatch.to_date,
                      status: selectedBatch.status,
                      rows: selectedBatch.rows ?? selectedBatch.rows_imported,
                      created_at: selectedBatch.created_at,
                      note: selectedBatch.note,
                    },
                    null,
                    2
                  )}
                </pre>
              </div>

              <div>
                <div className="text-xs text-gray-600 mb-1">Contenido / Errores</div>
                <pre className="bg-gray-50 p-3 rounded text-xs max-h-80 overflow-auto">
                  {JSON.stringify(getRowsArray(selectedBatch), null, 2)}
                </pre>
              </div>
            </div>

            <div className="mt-4 text-xs text-gray-500">
              <strong>Objeto completo:</strong>
              <pre className="bg-gray-50 p-3 rounded max-h-72 overflow-auto">
                {JSON.stringify(selectedBatch, null, 2)}
              </pre>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}