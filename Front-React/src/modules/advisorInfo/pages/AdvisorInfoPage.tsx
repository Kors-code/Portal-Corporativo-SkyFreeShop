import { useEffect, useState } from "react";
import {
  BookOpen,
  Download,
  ExternalLink,
  File,
  FileText,
  Folder,
  Loader2,
  RefreshCcw,
} from "lucide-react";
import {
  getAdvisorInfoContentUrl,
  getAdvisorInfoIndex,
  getAdvisorInfoProvider,
  type AdvisorInfoFile,
  type AdvisorInfoFolder,
} from "../services/advisorInfoService";

type LoadState = "idle" | "loading" | "error";

function formatDate(value?: string | null) {
  if (!value) return "Sin fecha";

  return new Intl.DateTimeFormat("es-CO", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function formatSize(bytes: number) {
  if (!bytes) return "0 KB";

  const units = ["B", "KB", "MB", "GB"];
  let size = bytes;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex += 1;
  }

  return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function fileIcon(file: AdvisorInfoFile) {
  if (file.extension === "pdf") return <FileText className="h-4 w-4" />;
  return <File className="h-4 w-4" />;
}

export default function AdvisorInfoPage() {
  const [status, setStatus] = useState<LoadState>("idle");
  const [detailStatus, setDetailStatus] = useState<LoadState>("idle");
  const [error, setError] = useState("");
  const [rootFolder, setRootFolder] = useState("Info Asesores");
  const [providers, setProviders] = useState<AdvisorInfoFolder[]>([]);
  const [rootFiles, setRootFiles] = useState<AdvisorInfoFile[]>([]);
  const [selectedProvider, setSelectedProvider] = useState<AdvisorInfoFolder | null>(null);
  const [files, setFiles] = useState<AdvisorInfoFile[]>([]);
  const [selectedFile, setSelectedFile] = useState<AdvisorInfoFile | null>(null);
  const [previewObjectUrl, setPreviewObjectUrl] = useState("");
  const [previewStatus, setPreviewStatus] = useState<LoadState>("idle");

  const visibleFiles = selectedProvider ? files : rootFiles;

  async function loadIndex(selectFirst = true) {
    setStatus("loading");
    setError("");

    try {
      const data = await getAdvisorInfoIndex();
      setRootFolder(data.root_folder);
      setProviders(data.providers);
      setRootFiles(data.root_files);

      if (selectFirst && data.providers.length > 0) {
        await loadProvider(data.providers[0]);
      } else if (data.root_files.length > 0) {
        setSelectedFile(data.root_files[0]);
      } else {
        setSelectedFile(null);
      }

      setStatus("idle");
    } catch (err: any) {
      setStatus("error");
      setError(err?.response?.data?.message || "No se pudo cargar la informacion de OneDrive.");
    }
  }

  async function loadProvider(provider: AdvisorInfoFolder) {
    setSelectedProvider(provider);
    setDetailStatus("loading");
    setSelectedFile(null);

    try {
      const data = await getAdvisorInfoProvider(provider.id);
      setFiles(data.files);
      setSelectedFile(data.files[0] ?? null);
      setDetailStatus("idle");
    } catch (err: any) {
      setDetailStatus("error");
      setError(err?.response?.data?.message || "No se pudo abrir la carpeta del proveedor.");
    }
  }

  useEffect(() => {
    loadIndex();
  }, []);

  useEffect(() => {
    let cancelled = false;
    let objectUrl = "";

    setPreviewObjectUrl("");
    setPreviewStatus("idle");

    if (!selectedFile?.previewable) {
      return;
    }

    setPreviewStatus("loading");

    fetch(getAdvisorInfoContentUrl(selectedFile.id), {
      credentials: "include",
      headers: { Accept: selectedFile.mimeType || "application/octet-stream" },
    })
      .then(async (response) => {
        if (!response.ok) {
          throw new Error(`No se pudo cargar el archivo (${response.status})`);
        }

        const blob = await response.blob();
        objectUrl = URL.createObjectURL(blob);

        if (!cancelled) {
          setPreviewObjectUrl(objectUrl);
          setPreviewStatus("idle");
        }
      })
      .catch((err) => {
        console.error(err);
        if (!cancelled) {
          setPreviewStatus("error");
        }
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [selectedFile]);

  async function downloadSelectedFile() {
    if (!selectedFile) return;

    try {
      const response = await fetch(getAdvisorInfoContentUrl(selectedFile.id), {
        credentials: "include",
        headers: { Accept: selectedFile.mimeType || "application/octet-stream" },
      });

      if (!response.ok) {
        throw new Error(`No se pudo descargar el archivo (${response.status})`);
      }

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");

      link.href = objectUrl;
      link.download = selectedFile.name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(objectUrl);
    } catch (err) {
      console.error(err);
      setError("No se pudo descargar el archivo desde el portal.");
    }
  }

  return (
    <div className="space-y-6 pb-12">
      <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div className="mb-2 flex items-center gap-2 text-sm font-semibold uppercase text-primary">
              <BookOpen className="h-4 w-4" />
              Biblioteca interna
            </div>
            <h1 className="text-2xl font-black text-slate-950">Info asesores</h1>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
              Material por proveedor sincronizado desde la carpeta {rootFolder} de OneDrive.
            </p>
          </div>

          <button
            type="button"
            onClick={() => loadIndex(false)}
            className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2 text-sm font-bold text-slate-800 transition hover:bg-slate-100"
          >
            <RefreshCcw className="h-4 w-4" />
            Actualizar
          </button>
        </div>
      </section>

      {error && (
        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
          {error}
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        <aside className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-black uppercase text-slate-700">Proveedores</h2>
            {status === "loading" && <Loader2 className="h-4 w-4 animate-spin text-primary" />}
          </div>

          <div className="space-y-2">
            <button
              type="button"
              onClick={() => {
                setSelectedProvider(null);
                setFiles([]);
                setSelectedFile(rootFiles[0] ?? null);
              }}
              className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold transition ${
                !selectedProvider ? "bg-primary text-white" : "text-slate-700 hover:bg-slate-100"
              }`}
            >
              <Folder className="h-4 w-4" />
              General
            </button>

            {providers.map((provider) => (
              <button
                key={provider.id}
                type="button"
                onClick={() => loadProvider(provider)}
                className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm font-semibold transition ${
                  selectedProvider?.id === provider.id ? "bg-primary text-white" : "text-slate-700 hover:bg-slate-100"
                }`}
              >
                <span className="flex min-w-0 items-center gap-2">
                  <Folder className="h-4 w-4 shrink-0" />
                  <span className="truncate">{provider.name}</span>
                </span>
                <span className="shrink-0 text-xs opacity-75">{provider.childCount ?? 0}</span>
              </button>
            ))}
          </div>
        </aside>

        <section className="grid gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
          <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-sm font-black uppercase text-slate-700">
                {selectedProvider?.name || "General"}
              </h2>
              {detailStatus === "loading" && <Loader2 className="h-4 w-4 animate-spin text-primary" />}
            </div>

            {visibleFiles.length === 0 ? (
              <div className="rounded-lg border border-dashed border-slate-300 p-5 text-sm text-slate-500">
                Esta carpeta todavia no tiene archivos visibles.
              </div>
            ) : (
              <div className="space-y-2">
                {visibleFiles.map((file) => (
                  <button
                    key={file.id}
                    type="button"
                    onClick={() => setSelectedFile(file)}
                    className={`w-full rounded-md border p-3 text-left transition ${
                      selectedFile?.id === file.id
                        ? "border-primary bg-primary/5"
                        : "border-slate-200 hover:border-slate-300 hover:bg-slate-50"
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                        {fileIcon(file)}
                      </span>
                      <span className="min-w-0">
                        <span className="block break-words text-sm font-bold text-slate-900">{file.name}</span>
                        <span className="mt-1 block text-xs text-slate-500">
                          {formatSize(file.size)} · {formatDate(file.updatedAt)}
                        </span>
                      </span>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </div>

          <div className="min-h-[640px] rounded-lg border border-slate-200 bg-white shadow-sm">
            {selectedFile ? (
              <div className="flex h-full min-h-[640px] flex-col">
                <div className="flex flex-col gap-3 border-b border-slate-200 p-4 md:flex-row md:items-center md:justify-between">
                  <div>
                    <h2 className="break-words text-base font-black text-slate-950">{selectedFile.name}</h2>
                    <p className="mt-1 text-xs text-slate-500">{selectedFile.mimeType || selectedFile.extension || "archivo"}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button
                      type="button"
                      onClick={downloadSelectedFile}
                      className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                      <Download className="h-4 w-4" />
                      Descargar
                    </button>
                    {selectedFile.webUrl && (
                      <a
                        href={selectedFile.webUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center justify-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                      >
                        <ExternalLink className="h-4 w-4" />
                        OneDrive
                      </a>
                    )}
                  </div>
                </div>

                {previewStatus === "loading" ? (
                  <div className="grid min-h-[570px] place-items-center p-6 text-center">
                    <div>
                      <Loader2 className="mx-auto h-10 w-10 animate-spin text-primary" />
                      <p className="mt-3 text-sm font-semibold text-slate-600">Cargando vista previa...</p>
                    </div>
                  </div>
                ) : previewObjectUrl ? (
                  <iframe
                    title={selectedFile.name}
                    src={previewObjectUrl}
                    className="h-full min-h-[570px] w-full rounded-b-lg"
                  />
                ) : (
                  <div className="grid min-h-[570px] place-items-center p-6 text-center">
                    <div>
                      <File className="mx-auto h-12 w-12 text-slate-300" />
                      <h3 className="mt-3 text-lg font-black text-slate-900">Vista previa no disponible</h3>
                      <p className="mt-2 max-w-md text-sm leading-6 text-slate-500">
                        Este tipo de archivo se puede abrir directamente en OneDrive.
                      </p>
                    </div>
                  </div>
                )}
              </div>
            ) : (
              <div className="grid min-h-[640px] place-items-center p-6 text-center">
                <div>
                  <BookOpen className="mx-auto h-12 w-12 text-slate-300" />
                  <h3 className="mt-3 text-lg font-black text-slate-900">Selecciona un archivo</h3>
                  <p className="mt-2 max-w-md text-sm leading-6 text-slate-500">
                    El contenido que subas a OneDrive aparecera aqui organizado por proveedor.
                  </p>
                </div>
              </div>
            )}
          </div>
        </section>
      </div>
    </div>
  );
}
