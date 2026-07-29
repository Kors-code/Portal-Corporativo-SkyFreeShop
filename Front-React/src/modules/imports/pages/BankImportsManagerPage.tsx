import { useEffect, useMemo, useState } from "react";
import * as bankApi from "../../../services/bankImports.service";

type BankImportBatch = bankApi.BankImportBatch;

const BANK_OPTIONS = [
  { value: "", label: "Todos los bancos" },
  { value: "colpatria", label: "Colpatria" },
  { value: "davivienda", label: "Davivienda" },
  { value: "bancolombia", label: "Bancolombia" },
];

function money(value: unknown) {
  const numberValue = Number(value ?? 0);
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(Number.isFinite(numberValue) ? numberValue : 0);
}

export default function BankImportsManagerPage() {
  const [batches, setBatches] = useState<BankImportBatch[]>([]);
  const [loading, setLoading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [selectedBatch, setSelectedBatch] = useState<any | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState("");
  const [filterBank, setFilterBank] = useState("");
  const [filterFilename, setFilterFilename] = useState("");
  const [filterFromDate, setFilterFromDate] = useState("");
  const [filterToDate, setFilterToDate] = useState("");

  useEffect(() => {
    void load();
  }, []);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const data = await bankApi.getBankImports();
      setBatches(Array.isArray(data) ? data : data?.data ?? []);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error cargando importaciones bancarias");
    } finally {
      setLoading(false);
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
    if (!confirm("Eliminar esta importacion bancaria? Esta accion no se puede deshacer.")) return;

    setDeletingId(id);
    setError(null);
    setMsg("");
    try {
      const res = await bankApi.deleteBankImport(id);
      const deleted = Number(res?.data?.deleted ?? 0);
      if (deleted < 1) {
        throw new Error(res?.data?.message || "La importacion no fue eliminada.");
      }
      setSelectedIds((prev) => prev.filter((x) => x !== id));
      if (selectedBatch?.id === id) setSelectedBatch(null);
      setMsg("Importacion bancaria eliminada.");
      await load();
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando");
    } finally {
      setDeletingId(null);
    }
  }

  async function handleBulkDelete() {
    if (!selectedIds.length) return;
    if (!confirm(`Eliminar ${selectedIds.length} importaciones bancarias? Esta accion no se puede deshacer.`)) return;

    setBulkDeleting(true);
    setError(null);
    setMsg("");
    try {
      const res = await bankApi.deleteBankImports(selectedIds);
      const deleted = Number(res?.data?.deleted ?? 0);
      const expected = selectedIds.length;
      setSelectedIds([]);
      setSelectedBatch(null);
      setMsg(`Importaciones eliminadas: ${deleted}`);
      await load();
      if (deleted < expected) {
        setError(`Se eliminaron ${deleted} de ${expected}. La lista fue recargada.`);
      }
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || "Error eliminando en bloque");
    } finally {
      setBulkDeleting(false);
    }
  }

  async function showDetails(batchId: number) {
    setSelectedBatch({ loading: true });
    setError(null);
    try {
      const data = await bankApi.getBankImport(batchId);
      setSelectedBatch(data);
    } catch (e: any) {
      setError(e?.response?.data?.message || "Error cargando detalles");
      setSelectedBatch(null);
    }
  }

  const filteredBatches = useMemo(() => {
    return batches.filter((b) => {
      if (filterBank && String(b.bank).toLowerCase() !== filterBank) return false;
      if (filterFilename && !b.filename?.toLowerCase().includes(filterFilename.toLowerCase())) return false;

      if (b.created_at) {
        const time = new Date(b.created_at).getTime();
        if (filterFromDate) {
          const from = new Date(filterFromDate).setHours(0, 0, 0, 0);
          if (time < from) return false;
        }
        if (filterToDate) {
          const to = new Date(`${filterToDate}T23:59:59`).getTime();
          if (time > to) return false;
        }
      }

      return true;
    });
  }, [batches, filterBank, filterFilename, filterFromDate, filterToDate]);

  const totals = useMemo(() => {
    return filteredBatches.reduce(
      (acc, item) => {
        acc.sale += Number(item.total_sale_amount ?? 0);
        acc.commission += Number(item.total_commission_amount ?? 0);
        acc.withholding += Number(item.total_withholding_amount ?? 0);
        acc.income += Number(item.total_income_amount ?? 0);
        return acc;
      },
      { sale: 0, commission: 0, withholding: 0, income: 0 }
    );
  }, [filteredBatches]);

  const movements = Array.isArray(selectedBatch?.movements_sample)
    ? selectedBatch.movements_sample
    : [];

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Importaciones bancarias</h1>
        <p className="text-sm text-gray-600 mt-1">
          Historial de archivos bancarios, movimientos normalizados y borrado por lote.
        </p>
      </div>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Venta</div>
          <div className="text-lg font-semibold">{money(totals.sale)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Comision</div>
          <div className="text-lg font-semibold">{money(totals.commission)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Retencion</div>
          <div className="text-lg font-semibold">{money(totals.withholding)}</div>
        </div>
        <div className="bg-white rounded shadow p-4">
          <div className="text-xs text-gray-500">Ingreso</div>
          <div className="text-lg font-semibold">{money(totals.income)}</div>
        </div>
      </div>

      <div className="bg-white p-4 rounded shadow space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
          <div>
            <label className="text-xs text-gray-600">Banco</label>
            <select
              value={filterBank}
              onChange={(e) => setFilterBank(e.target.value)}
              className="w-full border rounded px-2 py-1 text-sm"
            >
              {BANK_OPTIONS.map((bank) => (
                <option key={bank.value} value={bank.value}>
                  {bank.label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs text-gray-600">Archivo</label>
            <input
              type="text"
              value={filterFilename}
              onChange={(e) => setFilterFilename(e.target.value)}
              placeholder="nombre del archivo"
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
                setFilterBank("");
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
                checked={filteredBatches.length > 0 && filteredBatches.every((b) => selectedIds.includes(b.id))}
              />
              <span className="text-sm">Seleccionar todo</span>
            </label>
            <button
              onClick={handleBulkDelete}
              disabled={selectedIds.length === 0 || bulkDeleting}
              className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
            >
              {bulkDeleting ? "Eliminando..." : `Eliminar seleccionados (${selectedIds.length})`}
            </button>
          </div>
          <div className="text-sm text-gray-600">{filteredBatches.length} registros</div>
        </div>

        {loading ? (
          <div className="p-6 text-center">Cargando...</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-3 w-12"></th>
                <th className="p-3">Banco</th>
                <th className="p-3">Archivo</th>
                <th className="p-3">Filas</th>
                <th className="p-3">Venta</th>
                <th className="p-3">Comision</th>
                <th className="p-3">Retencion</th>
                <th className="p-3">Ingreso</th>
                <th className="p-3">Estado</th>
                <th className="p-3">Fecha</th>
                <th className="p-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {filteredBatches.map((batch) => (
                <tr key={batch.id} className="border-t">
                  <td className="p-3">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(batch.id)}
                      onChange={() => toggleSelect(batch.id)}
                    />
                  </td>
                  <td className="p-3 capitalize">{batch.bank}</td>
                  <td className="p-3">{batch.filename}</td>
                  <td className="p-3">{batch.rows_imported ?? batch.rows ?? "-"}</td>
                  <td className="p-3">{money(batch.total_sale_amount)}</td>
                  <td className="p-3">{money(batch.total_commission_amount)}</td>
                  <td className="p-3">{money(batch.total_withholding_amount)}</td>
                  <td className="p-3">{money(batch.total_income_amount)}</td>
                  <td className="p-3">
                    <span className="inline-block px-2 py-1 text-xs rounded bg-gray-100">
                      {batch.status ?? "-"}
                    </span>
                  </td>
                  <td className="p-3">
                    {batch.created_at ? new Date(batch.created_at).toLocaleString() : "-"}
                  </td>
                  <td className="p-3 text-right space-x-2">
                    <button onClick={() => showDetails(batch.id)} className="text-sm text-indigo-600">
                      Detalles
                    </button>
                    <button
                      onClick={() => handleDelete(batch.id)}
                      disabled={deletingId === batch.id}
                      className="text-sm text-red-600 disabled:opacity-50"
                    >
                      {deletingId === batch.id ? "Borrando..." : "Eliminar"}
                    </button>
                  </td>
                </tr>
              ))}
              {filteredBatches.length === 0 && (
                <tr>
                  <td colSpan={11} className="p-6 text-center text-gray-500">
                    No hay importaciones bancarias con los filtros aplicados
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {selectedBatch && !selectedBatch.loading && (
        <div className="fixed inset-0 bg-black/40 flex justify-center items-start p-6 z-50">
          <div className="bg-white rounded p-6 max-w-5xl w-full">
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
                      bank: selectedBatch.bank,
                      filename: selectedBatch.filename,
                      status: selectedBatch.status,
                      rows: selectedBatch.rows,
                      rows_imported: selectedBatch.rows_imported,
                      rows_skipped: selectedBatch.rows_skipped,
                      first_movement_date: selectedBatch.first_movement_date,
                      last_movement_date: selectedBatch.last_movement_date,
                      note: selectedBatch.note,
                    },
                    null,
                    2
                  )}
                </pre>
              </div>
              <div>
                <div className="text-xs text-gray-600 mb-1">Muestra de movimientos</div>
                <pre className="bg-gray-50 p-3 rounded text-xs max-h-80 overflow-auto">
                  {JSON.stringify(movements, null, 2)}
                </pre>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
