type Props = {
    pct: number;
    minPct: number;
    missingUsd: number;
    isProvisional: boolean;
};

export default function CommissionUnlockProgress({
    pct,
    minPct,
    missingUsd,
    isProvisional
}: Props) {

    const progress = Math.min(pct, 100);

    return (
        <div className="bg-white rounded-lg shadow p-4 mb-6">
            <div className="flex justify-between items-center mb-2">
                <h3 className="font-semibold text-sm text-gray-700">
                    Estado de comisiones
                </h3>
                <span
                    className={`text-xs font-bold px-2 py-1 rounded
            ${isProvisional
                            ? 'bg-yellow-100 text-yellow-700'
                            : 'bg-green-100 text-green-700'}
          `}
                >
                    {isProvisional ? 'PROVISIONAL' : 'LIBERADAS'}
                </span>
            </div>

            {/* Barra */}
            <div className="w-full bg-gray-200 rounded-full h-3 mb-2">
                <div
                    className={`h-3 rounded-full transition-all duration-500
            ${progress >= minPct ? 'bg-green-500' : 'bg-indigo-500'}
          `}
                    style={{ width: `${progress}%` }}
                />
            </div>

            {/* Texto */}
            {isProvisional ? (
                <p className="text-sm text-gray-600">
                    Falta <b>${missingUsd.toLocaleString()} USD</b> para liberar comisiones
                    <span className="ml-1 text-xs text-gray-400">
                        ({minPct}% requerido)
                    </span>
                </p>
            ) : (
                <p className="text-sm text-green-600 font-semibold">
                    🎉 Meta alcanzada. Comisiones liberadas
                </p>
            )}
        </div>
    );
}
