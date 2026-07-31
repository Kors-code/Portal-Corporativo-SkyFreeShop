import { useMemo, useState } from "react";
import type { ChangeEvent, FormEvent } from "react";
import { Download, FileSpreadsheet, Loader2, UploadCloud } from "lucide-react";
import * as bankApi from "../services/bankImports.service";

type ConversionStatus = "idle" | "uploading" | "success" | "error";

const BANK_OPTIONS = [
  { value: "davibank", label: "Davibank", accept: ".csv,text/csv,text/plain" },
  { value: "davivienda", label: "Davivienda", accept: ".xls,.html,text/html,application/vnd.ms-excel" },
  { value: "bancolombia", label: "Bancolombia", accept: ".csv,text/csv,text/plain" },
];

export default function DavibankConverterPage() {
  const [file, setFile] = useState<File | null>(null);
  const [bank, setBank] = useState("davibank");
  const [receiptStart, setReceiptStart] = useState("8695");
  const [status, setStatus] = useState<ConversionStatus>("idle");
  const [message, setMessage] = useState("");
  const selectedBank = BANK_OPTIONS.find((option) => option.value === bank) ?? BANK_OPTIONS[0];

  const fileLabel = useMemo(() => {
    if (!file) return `Selecciona el archivo de ${selectedBank.label}`;
    return `${file.name} (${formatBytes(file.size)})`;
  }, [file, selectedBank.label]);

  const handleFileChange = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = event.target.files?.[0] ?? null;
    setFile(selected);
    setStatus("idle");
    setMessage("");
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!file) {
      setStatus("error");
      setMessage("Selecciona un CSV de Davibank antes de convertir.");
      return;
    }

    const start = Number(receiptStart);
    if (!Number.isInteger(start) || start <= 0) {
      setStatus("error");
      setMessage("Ingresa un numero inicial de recibo valido.");
      return;
    }

    setStatus("uploading");
    setMessage("Procesando, guardando y generando Excel...");

    try {
      const response = await bankApi.importBankFile({ bank, file, receiptStart: start });
      const blob = response.data;
      const filename = getFilename(response) || `${bank}_final.xlsx`;
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);

      const sheets = response.headers["x-bank-sheets"];
      const rows = response.headers["x-bank-rows"];
      const imported = response.headers["x-bank-rows-imported"];
      const skipped = response.headers["x-bank-rows-skipped"];
      const batchId = response.headers["x-bank-batch-id"];
      setStatus("success");
      setMessage(
        `Excel generado${sheets ? ` con ${sheets} hojas` : ""}${rows ? ` y ${rows} filas procesadas` : ""}.` +
          `${imported ? ` Nuevas guardadas: ${imported}.` : ""}${skipped ? ` Omitidas/duplicadas: ${skipped}.` : ""}` +
          `${batchId ? ` Lote: ${batchId}.` : ""}`
      );
    } catch (error) {
      setStatus("error");
      setMessage(await readErrorMessage(error));
    }
  };

  return (
    <main className="min-h-screen bg-slate-50 text-slate-900">
      <div className="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-6 py-10">
        <header className="mb-8 flex items-center justify-between gap-4 border-b border-slate-200 pb-5">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-primary">Sky Free Shop</p>
            <h1 className="mt-2 text-2xl font-semibold text-slate-950">Importador bancario</h1>
          </div>
          <div className="hidden items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 sm:flex">
            <FileSpreadsheet className="h-4 w-4 text-primary" />
            Banco a Excel
          </div>
        </header>

        <section className="grid flex-1 gap-8 lg:grid-cols-[1.1fr_0.9fr]">
          <form onSubmit={handleSubmit} className="self-start rounded-md border border-slate-200 bg-white p-6 shadow-sm">
            <div className="mb-6">
              <h2 className="text-lg font-semibold text-slate-950">Subir archivo bancario</h2>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                El archivo se guarda en la base de datos y se devuelve como Excel final.
              </p>
            </div>

            <label className="mb-5 block">
              <span className="mb-2 block text-sm font-medium text-slate-700">Banco</span>
              <select
                value={bank}
                onChange={(event) => {
                  setBank(event.target.value);
                  setFile(null);
                  setStatus("idle");
                  setMessage("");
                }}
                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/10"
              >
                {BANK_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-2 block text-sm font-medium text-slate-700">Archivo CSV</span>
              <input type="file" accept={selectedBank.accept} onChange={handleFileChange} className="sr-only" id="davibank-file" />
              <span className="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-primary hover:bg-primary/5">
                <UploadCloud className="mb-3 h-8 w-8 text-primary" />
                <span className="max-w-full truncate text-sm font-medium text-slate-800">{fileLabel}</span>
                <span className="mt-1 text-xs text-slate-500">Formato esperado para {selectedBank.label}</span>
              </span>
            </label>

            <label className="mt-5 block">
              <span className="mb-2 block text-sm font-medium text-slate-700">Numero inicial del recibo</span>
              <input
                type="number"
                min="1"
                step="1"
                value={receiptStart}
                onChange={(event) => setReceiptStart(event.target.value)}
                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/10"
              />
            </label>

            <button
              type="submit"
              disabled={status === "uploading"}
              className="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-70"
            >
              {status === "uploading" ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
              {status === "uploading" ? "Procesando" : "Guardar y descargar final"}
            </button>

            {message && (
              <div
                className={`mt-4 rounded-md border px-4 py-3 text-sm ${
                  status === "error"
                    ? "border-red-200 bg-red-50 text-red-700"
                    : status === "success"
                      ? "border-emerald-200 bg-emerald-50 text-emerald-700"
                      : "border-slate-200 bg-slate-50 text-slate-700"
                }`}
              >
                {message}
              </div>
            )}
          </form>

          <aside className="self-start rounded-md border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-base font-semibold text-slate-950">Salida generada</h2>
            <div className="mt-5 space-y-4 text-sm text-slate-700">
              <div className="rounded-md border border-slate-200 p-4">
                <p className="font-medium text-slate-900">Davibank</p>
                <p className="mt-1">Agrupa por fecha de abono y genera resumen Visa/Redeban.</p>
              </div>
              <div className="rounded-md border border-slate-200 p-4">
                <p className="font-medium text-slate-900">Davivienda</p>
                <p className="mt-1">Lee consulta detallada y genera recibos por fecha de abono.</p>
              </div>
              <div className="rounded-md border border-slate-200 p-4">
                <p className="font-medium text-slate-900">Bancolombia</p>
                <p className="mt-1">Toma pagos QR/ventas y arma los recibos de caja.</p>
              </div>
            </div>
          </aside>
        </section>
      </div>
    </main>
  );
}

function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

async function readErrorMessage(error: unknown) {
  if (!isAxiosLikeError(error)) {
    return error instanceof Error ? error.message : "No se pudo convertir el archivo.";
  }

  const data = error.response?.data;
  if (data instanceof Blob) {
    try {
      const text = await data.text();
      const parsed = JSON.parse(text);
      return parsed?.message || "No se pudo convertir el archivo.";
    } catch {
      return "No se pudo convertir el archivo.";
    }
  }

  return data?.message || error.message || "No se pudo convertir el archivo.";
}

function getFilename(response: { headers: { [key: string]: unknown } }) {
  const rawDisposition = response.headers["content-disposition"];
  const disposition = typeof rawDisposition === "string" ? rawDisposition : "";
  const match = disposition.match(/filename="?([^"]+)"?/i);
  return match?.[1];
}

function isAxiosLikeError(error: unknown): error is { message?: string; response?: { data?: Blob | { message?: string } } } {
  return typeof error === "object" && error !== null && "response" in error;
}
