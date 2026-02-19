import { useState } from "react";
import * as catalogApi from "../../../services/catalog.service";

export default function ImportCatalog() {
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [msg, setMsg] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function handleUpload() {
    if (!file) {
      setError("Selecciona un archivo");
      return;
    }

    setUploading(true);
    setError(null);
    setMsg("");

    try {
      const res = await catalogApi.importCatalogFile(file);

      const rows =
        res?.data?.rows ??
        res?.data?.processed ??
        res?.data?.rows_imported ??
        null;

      setMsg(
        `Importación exitosa${rows ? `: ${rows} filas procesadas` : ""}`
      );

      setFile(null);
    } catch (e: any) {
      const svcMsg = e?.response?.data;

      if (svcMsg && typeof svcMsg === "object" && svcMsg.message) {
        setError(String(svcMsg.message));
      } else {
        setError(
          e?.response?.data?.message ||
          e?.message ||
          "Error importando catálogo"
        );
      }
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="p-6 max-w-xl space-y-4">
      <h2 className="text-xl font-semibold">Importar Catálogo</h2>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="bg-green-100 p-2 rounded text-sm">{msg}</div>}

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
