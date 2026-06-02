// src/pages/imports/ImportsManagerPage.tsx
import { useEffect, useMemo, useState } from "react";
import * as salesApi from "../../../services/imports.service";
import * as turnsApi from "../../../services/importTurnsService";

type ImportBatch = {
  id: number;
  filename: string;
  checksum?: string;
  status?: string;
  rows?: number;
  created_at?: string;
  note?: string;
  errors?: any;
  [k: string]: any;
};

type ImportType = "sales" | "turns";

type StoreOption = {
  id: number;
  name: string;
  code: string;
  type?: string | null;
};

export default function ImportsManagerPage() {
  const [type, setType] = useState<ImportType>("sales");

  const [stores, setStores] = useState<StoreOption[]>([]);
  const [storesLoading, setStoresLoading] = useState(false);
  const [selectedStore, setSelectedStore] = useState<number | "">("");

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
  const [importProgress, setImportProgress] = useState(0);

  const [filterFilename, setFilterFilename] = useState("");
  const [filterFromDate, setFilterFromDate] = useState("");
  const [filterToDate, setFilterToDate] = useState("");

  const api = useMemo(() => {
    if (type === "sales") {
      return {
        importFn: salesApi.importSalesFile,
        getFn: salesApi.getImports,
        getOneFn: salesApi.getImport,
        deleteFn: salesApi.deleteImport,
        deleteManyFn: salesApi.deleteImports,
      };
    }

    return {
      importFn: (turnsApi as any).importTurnsFile,
      getFn: (turnsApi as any).getImports,
      getOneFn: (turnsApi as any).getImport,
      deleteFn: (turnsApi as any).deleteImport,
      deleteManyFn: (turnsApi as any).deleteImports,
    };
  }, [type]);

  useEffect(() => {
    void loadStores();
  }, []);

  useEffect(() => {
    void load();
    setSelectedIds([]);
    setSelectedBatch(null);
    setMsg("");
    setError(null);

    if (type !== "sales") {
      setSelectedStore("");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type]);

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
      const res = await api.getFn();
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

    if (type === "sales" && !selectedStore) {
      setError("Selecciona una tienda para importar ventas.");
      return;
    }

    setUploading(true);
    setError(null);
    setMsg("");
    setImportProgress(0);

    try {
      if (type === "sales") {
        const start = await salesApi.startSalesImport(file, Number(selectedStore));
        const path = start.data.path;
        const batchId = Number(start.data.batch_id ?? 0) || null;
        const storeId = Number(start.data.store_id ?? selectedStore);
        const totalRows = Number(start.data.total_rows ?? 0);
        const chunkSize = Number(start.data.chunk_size ?? 300);
        let nextRow = Number(start.data.next_row ?? 2);
        let done = totalRows === 0;
        let insertedRows = 0;

        while (!done) {
          const chunk = await salesApi.processSalesImportChunk({
            path,
            batch_id: Number(batchId),
            store_id: storeId,
            next_row: nextRow,
            total_rows: totalRows,
            chunk_size: chunkSize,
          });

          insertedRows += Number(chunk.data?.summary?.created?.sales ?? 0);
          nextRow = Number(chunk.data.next_row ?? nextRow + chunkSize);
          done = Boolean(chunk.data.done);
          const processedRows = Math.min(totalRows, Math.max(0, nextRow - 2));
          setImportProgress(totalRows ? Math.round((processedRows / totalRows) * 100) : 100);
        }

        setMsg(
          `Importacion exitosa${insertedRows ? `: ${insertedRows} filas` : ""}${
            batchId ? ` (batch ${batchId})` : ""
          }`
        );
        setFile(null);
        setImportProgress(100);
        await load();
        return;
      }

      const res = await api.importFn(file);

      const rows =
        res?.data?.rows ??
        res?.data?.processed ??
        res?.data?.rows_imported ??
        res?.rows ??
        res?.processed ??
        null;

      const batchId =
        res?.data?.batch_id ?? res?.data?.id ?? res?.batch_id ?? res?.id ?? null;

      setMsg(
        `Importación exitosa${rows ? `: ${rows} filas` : ""}${
          batchId ? ` (batch ${batchId})` : ""
        }`
      );
      setFile(null);
      await load();
    } catch (e: any) {
      const svcMsg = e?.response?.data;
      if (svcMsg && typeof svcMsg === "object" && svcMsg.message) {
        let full = svcMsg.message;
        if (svcMsg.fila) full += ` (fila ${svcMsg.fila})`;
        if (svcMsg.valor_detectado) full += ` — valor: ${svcMsg.valor_detectado}`;
        setError(String(full));
      } else {
        setError(e?.response?.data?.message || e?.message || "Error importando");
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
    if (!confirm("¿Eliminar esta importación? Esta acción no se puede deshacer.")) return;
    setDeletingId(id);
    setError(null);
    try {
      await api.deleteFn(id);
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
      await api.deleteManyFn(selectedIds);
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
      const res = await api.getOneFn(batchId);
      const data = res?.data ?? res;
      setSelectedBatch(data);
    } catch (e: any) {
      setError(e?.response?.data?.message || "Error cargando detalles");
      setSelectedBatch(null);
    }
  }

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
    const candidates = ["rows_data", "rows", "items", "data", "sales", "turns", "errors"];
    for (const k of candidates) {
      if (Array.isArray(batch[k])) return batch[k];
    }
    return [];
  };

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">Centro de Importaciones</h1>

      <div className="grid grid-cols-2 gap-4">
        <button
          onClick={() => setType("sales")}
          className={`p-4 rounded-xl border transition text-left flex flex-col ${
            type === "sales"
              ? "bg-[#840028] text-white border-[#840028]"
              : "bg-white hover:bg-gray-50"
          }`}
        >
          <div className="text-lg font-semibold">🧾 Ventas</div>
          <div className="text-sm opacity-80">Importar ventas y tickets</div>
        </button>

        <button
          onClick={() => setType("turns")}
          className={`p-4 rounded-xl border transition text-left flex flex-col ${
            type === "turns"
              ? "bg-[#840028] text-white border-[#840028]"
              : "bg-white hover:bg-gray-50"
          }`}
        >
          <div className="text-lg font-semibold">🔄 Turnos</div>
          <div className="text-sm opacity-80">Asignación masiva de turnos</div>
        </button>
      </div>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

      <div className="bg-white p-4 rounded shadow flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div className="flex items-center gap-3 flex-wrap">
          {type === "sales" && (
            <div className="min-w-[260px]">
              <label className="text-xs text-gray-600 block mb-1">Tienda para ventas</label>
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
          )}

          <div>
            <input
              type="file"
              accept=".csv,.xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <div className="text-sm text-gray-600 mt-1">
              {file ? file.name : "No hay archivo seleccionado"}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={handleUpload}
            disabled={uploading || !file || (type === "sales" && !selectedStore)}
            style={{
              backgroundColor:
                uploading || !file || (type === "sales" && !selectedStore)
                  ? "#9CA3AF"
                  : "#840028",
              cursor:
                uploading || !file || (type === "sales" && !selectedStore)
                  ? "not-allowed"
                  : "pointer",
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

      {uploading && type === "sales" && (
        <div className="bg-white p-4 rounded shadow max-w-xl space-y-1">
          <div className="h-2 overflow-hidden rounded bg-gray-200">
            <div
              className="h-full bg-[#840028] transition-all"
              style={{ width: `${importProgress}%` }}
            />
          </div>
          <div className="text-sm text-gray-600">{importProgress}% procesado</div>
        </div>
      )}

      <div className="bg-white p-4 rounded shadow space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div>
            <label className="text-xs text-gray-600">Nombre del archivo</label>
            <input
              type="text"
              value={filterFilename}
              onChange={(e) => setFilterFilename(e.target.value)}
              placeholder="ej: ventas_enero"
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
                  <td colSpan={6} className="p-6 text-center text-gray-500">
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
