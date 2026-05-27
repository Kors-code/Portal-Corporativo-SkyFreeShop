import { useEffect, useRef, useState } from "react";
import SignaturePad from "signature_pad";

type FirmaPadProps = {
  titulo?: string;
  firmaGuardada?: string | null;
  permitirGuardada?: boolean;
  onFirmaCapturada: (firmaData: string, usandoGuardada: boolean) => void;
  onCancelar: () => void;
};

export default function FirmaPad({
  titulo = "Firma aqui",
  firmaGuardada,
  permitirGuardada = true,
  onFirmaCapturada,
  onCancelar,
}: FirmaPadProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const padRef = useRef<SignaturePad | null>(null);
  const [usarGuardada, setUsarGuardada] = useState(false);
  const [vacia, setVacia] = useState(true);
  const [firmaCargada, setFirmaCargada] = useState<string | null>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || usarGuardada || firmaCargada) return;

    const resize = () => {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      canvas.width = canvas.offsetWidth * ratio;
      canvas.height = canvas.offsetHeight * ratio;
      const ctx = canvas.getContext("2d");
      ctx?.scale(ratio, ratio);
      padRef.current?.clear();
    };

    padRef.current = new SignaturePad(canvas, {
      backgroundColor: "rgb(255, 255, 255)",
      penColor: "rgb(15, 23, 42)",
      minWidth: 1,
      maxWidth: 2.5,
    });

    padRef.current.addEventListener("endStroke", () => {
      setVacia(padRef.current?.isEmpty() ?? true);
    });

    resize();
    window.addEventListener("resize", resize);

    return () => {
      window.removeEventListener("resize", resize);
      padRef.current?.off();
    };
  }, [usarGuardada, firmaCargada]);

  const limpiar = () => {
    padRef.current?.clear();
    setFirmaCargada(null);
    setVacia(true);
  };

  const cargarImagen = (file?: File) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      alert("Carga una imagen valida de la firma");
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      setFirmaCargada(String(reader.result));
      setVacia(false);
    };
    reader.readAsDataURL(file);
  };

  const confirmar = () => {
    if (usarGuardada && firmaGuardada) {
      onFirmaCapturada(firmaGuardada, true);
      return;
    }

    if (firmaCargada) {
      onFirmaCapturada(firmaCargada, false);
      return;
    }

    if (!padRef.current || padRef.current.isEmpty()) {
      alert("Por favor firma antes de confirmar");
      return;
    }

    onFirmaCapturada(padRef.current.toDataURL("image/png"), false);
  };

  return (
    <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-4 shadow-lg sm:p-5">
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="text-base font-semibold text-gray-800 sm:text-lg">{titulo}</h3>
      </div>

      {permitirGuardada && firmaGuardada && (
        <div className="mb-4 rounded border border-gray-200 bg-gray-50 p-3">
          <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
            <input
              type="checkbox"
              checked={usarGuardada}
              onChange={(event) => setUsarGuardada(event.target.checked)}
              className="h-4 w-4"
            />
            Usar mi firma guardada
          </label>

          {usarGuardada && (
            <div className="mt-3 rounded border bg-white p-3 text-center">
              <img src={firmaGuardada} alt="Firma guardada" className="mx-auto max-h-24" />
            </div>
          )}
        </div>
      )}

      {!usarGuardada && (
        <>
          <div className="mb-3 flex flex-col gap-2 rounded-md border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div className="text-sm font-semibold text-gray-700">Cargar firma como imagen</div>
              <div className="text-xs text-gray-500">PNG, JPG o captura de firma.</div>
            </div>
            <input
              type="file"
              accept="image/*"
              onChange={(event) => cargarImagen(event.target.files?.[0])}
              className="w-full text-sm sm:w-auto"
            />
          </div>

          {firmaCargada ? (
            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-center">
              <img src={firmaCargada} alt="Firma cargada" className="mx-auto max-h-32" />
            </div>
          ) : (
            <div className="relative mb-4 rounded-lg border-2 border-dashed border-gray-300 bg-gray-50">
              <canvas ref={canvasRef} className="block h-40 w-full cursor-crosshair rounded-lg sm:h-48" />
              {vacia && (
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-gray-400">
                  Dibuja tu firma aqui
                </div>
              )}
            </div>
          )}
        </>
      )}

      <div className="grid grid-cols-1 gap-2 sm:flex sm:justify-end">
        {!usarGuardada && (
          <button
            type="button"
            onClick={limpiar}
            className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200"
          >
            Limpiar
          </button>
        )}
        <button
          type="button"
          onClick={onCancelar}
          className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
        >
          Cancelar
        </button>
        <button
          type="button"
          onClick={confirmar}
          className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:brightness-95"
        >
          Confirmar firma
        </button>
      </div>
    </div>
  );
}
