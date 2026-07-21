import { useMemo, useState } from "react";
import type { ChangeEvent, FormEvent } from "react";
import { Download, FileSpreadsheet, Loader2, UploadCloud } from "lucide-react";
import axios from "axios";
import { API } from "../api/api";

type ConversionStatus = "idle" | "uploading" | "success" | "error";

export default function DavibankConverterPage() {
  const [file, setFile] = useState<File | null>(null);
  const [receiptStart, setReceiptStart] = useState("8695");
  const [status, setStatus] = useState<ConversionStatus>("idle");
  const [message, setMessage] = useState("");

  const fileLabel = useMemo(() => {
    if (!file) return "Selecciona el archivo CSV de Davibank";
    return `${file.name} (${formatBytes(file.size)})`;
  }, [file]);

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

    const formData = new FormData();
    formData.append("file", file);
    formData.append("receipt_start", String(start));

    setStatus("uploading");
    setMessage("Procesando archivo...");

    try {
      const response = await axios.post(`${API}/davibank/convert`, formData, {
        withCredentials: true,
        responseType: "blob",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": getCsrfToken(),
        },
      });

      const blob = response.data;
      const filename = getFilename(response) || "davibank_convertido.xlsx";
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);

      const sheets = response.headers["x-davibank-sheets"];
      const rows = response.headers["x-davibank-rows"];
      const excluded = response.headers["x-davibank-excluded-zero-commission"];
      setStatus("success");
      setMessage(
        `Excel generado${sheets ? ` con ${sheets} hojas` : ""}${rows ? ` y ${rows} filas` : ""}.` +
          `${excluded ? ` Filas excluidas por comision cero: ${excluded}.` : ""}`
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
            <h1 className="mt-2 text-2xl font-semibold text-slate-950">Conversor Davibank</h1>
          </div>
          <div className="hidden items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 sm:flex">
            <FileSpreadsheet className="h-4 w-4 text-primary" />
            CSV a Excel
          </div>
        </header>

        <section className="grid flex-1 gap-8 lg:grid-cols-[1.1fr_0.9fr]">
          <form onSubmit={handleSubmit} className="self-start rounded-md border border-slate-200 bg-white p-6 shadow-sm">
            <div className="mb-6">
              <h2 className="text-lg font-semibold text-slate-950">Subir ventas Davibank</h2>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                El archivo se procesa y se devuelve como Excel con hojas por fecha de abono.
              </p>
            </div>

            <label className="block">
              <span className="mb-2 block text-sm font-medium text-slate-700">Archivo CSV</span>
              <input type="file" accept=".csv,text/csv,text/plain" onChange={handleFileChange} className="sr-only" id="davibank-file" />
              <span className="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-primary hover:bg-primary/5">
                <UploadCloud className="mb-3 h-8 w-8 text-primary" />
                <span className="max-w-full truncate text-sm font-medium text-slate-800">{fileLabel}</span>
                <span className="mt-1 text-xs text-slate-500">Formato esperado: ventas Davibank en CSV</span>
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
              {status === "uploading" ? "Convirtiendo" : "Convertir y descargar"}
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
                <p className="font-medium text-slate-900">Agrupacion</p>
                <p className="mt-1">Una hoja por `FECHA_ABONO`, nombrada por dia y mes.</p>
              </div>
              <div className="rounded-md border border-slate-200 p-4">
                <p className="font-medium text-slate-900">Filtro</p>
                <p className="mt-1">Se omiten ventas `VD` y `RD` con `VALOR_COMISION` igual a cero.</p>
              </div>
              <div className="rounded-md border border-slate-200 p-4">
                <p className="font-medium text-slate-900">Contenido</p>
                <p className="mt-1">Cada hoja incluye resumen Visa/Redeban, ventas crudas y recibo de caja.</p>
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

function getCsrfToken() {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";
}
