import { useEffect, useRef, useState } from 'react';
import SignaturePad from 'signature_pad';

type FirmaPadProps = {
  titulo?: string;
  firmaGuardada?: string | null;
  permitirGuardada?: boolean;
  onFirmaCapturada: (firmaData: string, usandoGuardada: boolean) => void;
  onCancelar: () => void;
};

export default function FirmaPad({
  titulo = 'Firma aquí',
  firmaGuardada,
  permitirGuardada = true,
  onFirmaCapturada,
  onCancelar,
}: FirmaPadProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const padRef = useRef<SignaturePad | null>(null);

  const [usarGuardada, setUsarGuardada] = useState(false);
  const [vacia, setVacia] = useState(true);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const resize = () => {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      canvas.width = canvas.offsetWidth * ratio;
      canvas.height = canvas.offsetHeight * ratio;
      const ctx = canvas.getContext('2d');
      ctx?.scale(ratio, ratio);
      padRef.current?.clear();
    };

    padRef.current = new SignaturePad(canvas, {
      backgroundColor: 'rgb(255, 255, 255)',
      penColor: 'rgb(15, 23, 42)',
      minWidth: 1,
      maxWidth: 2.5,
    });

    padRef.current.addEventListener('endStroke', () => {
      setVacia(padRef.current?.isEmpty() ?? true);
    });

    resize();
    window.addEventListener('resize', resize);

    return () => {
      window.removeEventListener('resize', resize);
      padRef.current?.off();
    };
  }, [usarGuardada]);

  const limpiar = () => {
    padRef.current?.clear();
    setVacia(true);
  };

  const confirmar = () => {
    if (usarGuardada && firmaGuardada) {
      onFirmaCapturada(firmaGuardada, true);
      return;
    }

    if (!padRef.current || padRef.current.isEmpty()) {
      alert('Por favor firma antes de confirmar');
      return;
    }

    const dataUrl = padRef.current.toDataURL('image/png');
    onFirmaCapturada(dataUrl, false);
  };

  return (
    <div className="bg-white rounded-lg shadow-lg p-5 w-full max-w-2xl">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-800">✍️ {titulo}</h3>
      </div>

      {permitirGuardada && firmaGuardada && (
        <div className="bg-gray-50 rounded p-3 mb-4 border border-gray-200">
          <label className="flex items-center gap-2 cursor-pointer text-sm font-medium text-gray-700">
            <input
              type="checkbox"
              checked={usarGuardada}
              onChange={e => setUsarGuardada(e.target.checked)}
              className="w-4 h-4"
            />
            Usar mi firma guardada
          </label>

          {usarGuardada && (
            <div className="mt-3 p-3 bg-white border rounded text-center">
              <img src={firmaGuardada} alt="Firma guardada" className="max-h-24 mx-auto" />
            </div>
          )}
        </div>
      )}

      {!usarGuardada && (
        <div className="relative border-2 border-dashed border-gray-300 rounded-lg bg-gray-50 mb-4">
          <canvas
            ref={canvasRef}
            className="w-full h-48 cursor-crosshair block rounded-lg"
          />
          {vacia && (
            <div className="absolute inset-0 flex items-center justify-center pointer-events-none text-gray-400 text-sm">
              Dibuja tu firma aquí ↓
            </div>
          )}
        </div>
      )}

      <div className="flex gap-2 justify-end">
        {!usarGuardada && (
          <button
            type="button"
            onClick={limpiar}
            className="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition"
          >
            🗑️ Limpiar
          </button>
        )}
        <button
          type="button"
          onClick={onCancelar}
          className="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-sm font-medium transition"
        >
          Cancelar
        </button>
        <button
          type="button"
          onClick={confirmar}
          className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold transition"
        >
          ✅ Confirmar Firma
        </button>
      </div>
    </div>
  );
}
