import { useState } from "react";
import { Upload, FileSpreadsheet, Loader2, CheckCircle2, XCircle } from "lucide-react";
import { importCatalog } from "./services/inventoryService";

export default function CatalogImportCard() {
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [messageType, setMessageType] = useState<"success" | "error" | "info" | "">("");

  const handleImport = async () => {
    if (!file) {
      setMessage("Selecciona un archivo primero.");
      setMessageType("error");
      return;
    }

    try {
      setLoading(true);
      setMessage("");

      const response = await importCatalog(file);

      setMessage(response?.message || "Catálogo importado correctamente.");
      setMessageType("success");
      setFile(null);
    } catch (error) {
      console.error(error);
      setMessage("No se pudo importar el catálogo.");
      setMessageType("error");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold text-slate-900">Importar catálogo</h2>
          <p className="text-sm text-slate-500">
            Carga el Excel del catálogo para actualizar products.
          </p>
        </div>
        <FileSpreadsheet className="h-5 w-5 text-slate-400" />
      </div>

      <div className="grid gap-3 md:grid-cols-[1fr_auto] md:items-center">
        <label className="flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600 transition hover:border-slate-400 hover:bg-slate-100">
          <input
            type="file"
            accept=".xlsx,.xls,.csv"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="hidden"
          />
          <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white shadow-sm">
            <FileSpreadsheet className="h-4 w-4 text-slate-500" />
          </span>
          <span className="truncate">
            {file ? file.name : "Selecciona archivo de catálogo"}
          </span>
        </label>

        <button
          onClick={handleImport}
          disabled={loading}
          className="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {loading ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              Importando...
            </>
          ) : (
            <>
              <Upload className="h-4 w-4" />
              Importar catálogo
            </>
          )}
        </button>
      </div>

      {message && (
        <div
          className={`mt-4 rounded-2xl px-4 py-3 text-sm ${
            messageType === "success"
              ? "bg-emerald-50 text-emerald-700"
              : messageType === "error"
                ? "bg-rose-50 text-rose-700"
                : "bg-sky-50 text-sky-700"
          }`}
        >
          <div className="flex items-center gap-2">
            {messageType === "success" ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : messageType === "error" ? (
              <XCircle className="h-4 w-4" />
            ) : null}
            {message}
          </div>
        </div>
      )}
    </div>
  );
}