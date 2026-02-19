import { useState } from "react";

export default function RangeForm({ onSubmit, editing }: any) {
    const [min, setMin] = useState(editing?.min ?? "");
    const [max, setMax] = useState(editing?.max ?? "");
    const [commission, setCommission] = useState(editing?.commission ?? "");

    const handleSubmit = (e: any) => {
        e.preventDefault();
        onSubmit({ min, max, commission });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <input
                className="w-full border p-2 rounded"
                type="number"
                placeholder="Mínimo"
                value={min}
                onChange={(e) => setMin(e.target.value)}
            />

            <input
                className="w-full border p-2 rounded"
                type="number"
                placeholder="Máximo"
                value={max}
                onChange={(e) => setMax(e.target.value)}
            />

            <input
                className="w-full border p-2 rounded"
                type="number"
                placeholder="% Comisión"
                value={commission}
                onChange={(e) => setCommission(e.target.value)}
            />

            <button className="bg-primary text-white w-full py-2 rounded">
                Guardar
            </button>
        </form>
    );
}
