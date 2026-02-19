import { useState, useEffect } from "react";
import type { Commission } from "../types/Commission";

export default function CommissionForm({
    initialData,
    onSubmit,
    onCancel,
}: {
    initialData?: Commission | null;
    onSubmit: (data: Partial<Commission>) => void;
    onCancel: () => void;
}) {
    const [from, setFrom] = useState(0);
    const [to, setTo] = useState(0);
    const [percentage, setPercentage] = useState(0);

    useEffect(() => {
        if (initialData) {
            setFrom(initialData.from);
            setTo(initialData.to);
            setPercentage(initialData.percentage);
        }
    }, [initialData]);

    const handleSubmit = (e: any) => {
        e.preventDefault();
        onSubmit({ from, to, percentage });
    };

    return (
        <div className="bg-white shadow-xl rounded-xl p-6 max-w-lg mx-auto mt-6">
            <h2 className="text-xl font-bold mb-4 text-primary">
                {initialData ? "Editar Categoría" : "Nueva Categoría"}
            </h2>

            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="font-medium">Desde</label>
                    <input
                        type="number"
                        value={from}
                        onChange={(e) => setFrom(Number(e.target.value))}
                        className="w-full border rounded-lg p-2 bg-gray-100"
                        required
                    />
                </div>

                <div>
                    <label className="font-medium">Hasta</label>
                    <input
                        type="number"
                        value={to}
                        onChange={(e) => setTo(Number(e.target.value))}
                        className="w-full border rounded-lg p-2 bg-gray-100"
                        required
                    />
                </div>

                <div>
                    <label className="font-medium">Comisión (%)</label>
                    <input
                        type="number"
                        value={percentage}
                        onChange={(e) => setPercentage(Number(e.target.value))}
                        className="w-full border rounded-lg p-2 bg-gray-100"
                        required
                    />
                </div>

                <div className="flex gap-3 justify-end">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="px-4 py-2 bg-gray-300 rounded-lg"
                    >
                        Cancelar
                    </button>

                    <button type="submit" className="px-4 py-2 bg-primary text-white rounded-lg">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    );
}
