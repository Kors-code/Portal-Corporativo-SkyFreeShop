import { useState } from "react";

export default function CategoryForm({ onSubmit, editing }: any) {
    const [name, setName] = useState(editing?.name ?? "");

    const handleSubmit = (e: any) => {
        e.preventDefault();
        onSubmit({ name });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <input
                className="w-full border p-2 rounded"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Nombre de categoría"
            />

            <button className="bg-primary text-white w-full py-2 rounded">
                Guardar
            </button>
        </form>
    );
}
