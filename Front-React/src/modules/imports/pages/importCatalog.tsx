import { useState } from "react";
import * as catalogApi from "../../../services/catalog.service";

export default function ImportCatalog() {
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [msg, setMsg] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [progress, setProgress] = useState(0);

  async function handleUpload() {
    if (!file) {
      setError("Selecciona un archivo");
      return;
    }

    setUploading(true);
    setError(null);
    setMsg("");
    setProgress(0);

    try {
      const start = await catalogApi.startCatalogImport(file);
      const path = start.data.path;
      const totalRows = Number(start.data.total_rows ?? 0);
      const chunkSize = Number(start.data.chunk_size ?? 500);
      let nextRow = Number(start.data.next_row ?? 2);
      let done = totalRows === 0;
      const totals = {
        processed: 0,
        created: 0,
        updated: 0,
        skipped: 0,
        duplicates: 0,
        warnings: 0,
      };

      while (!done) {
        const chunk = await catalogApi.processCatalogChunk({
          path,
          next_row: nextRow,
          chunk_size: chunkSize,
        });

        const summary = chunk.data.summary ?? {};
        totals.processed += Number(summary.processed ?? 0);
        totals.created += Number(summary.created ?? 0);
        totals.updated += Number(summary.updated ?? 0);
        totals.skipped += Number(summary.skipped ?? 0);
        totals.duplicates += Number(summary.duplicates ?? 0);
        totals.warnings += Number(summary.warnings ?? 0);

        nextRow = Number(chunk.data.next_row ?? nextRow + chunkSize);
        done = Boolean(chunk.data.done);
        const processedRows = Math.min(totalRows, Math.max(0, nextRow - 2));
        setProgress(totalRows ? Math.round((processedRows / totalRows) * 100) : 100);
      }

      setProgress(100);
      setMsg(
        `Importacion exitosa: ${totals.processed} productos procesados, ${totals.created} creados, ${totals.updated} actualizados, ${totals.skipped} omitidos.`
      );
      setFile(null);
    } catch (e: any) {
      const svcMsg = e?.response?.data;

      if (svcMsg && typeof svcMsg === "object" && svcMsg.message) {
        setError(String(svcMsg.message));
      } else {
        setError(e?.response?.data?.message || e?.message || "Error importando catalogo");
      }
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="p-6 max-w-xl space-y-4">
      <h2 className="text-xl font-semibold">Importar Catalogo</h2>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

      {uploading && (
        <div className="space-y-1">
          <div className="h-2 overflow-hidden rounded bg-gray-200">
            <div
              className="h-full bg-[#840028] transition-all"
              style={{ width: `${progress}%` }}
            />
          </div>
          <div className="text-sm text-gray-600">{progress}% procesado</div>
        </div>
      )}

      <div className="flex items-center gap-3">
        <input
          type="file"
          accept=".xlsx,.xls,.csv"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
        <div className="text-sm text-gray-600">
          {file ? file.name : "No hay archivo seleccionado"}
        </div>
      </div>

      <button
        onClick={handleUpload}
        disabled={!file || uploading}
        className="px-4 py-2 bg-[#840028] text-white rounded disabled:opacity-50"
      >
        {uploading ? "Importando..." : "Subir y procesar"}
      </button>
    </div>
  );
}
