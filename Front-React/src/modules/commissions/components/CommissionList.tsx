import type { Commission } from "../types/Commission";

export default function CommissionList({
    commissions,
    onEdit,
    onDelete,
}: {
    commissions: Commission[];
    onEdit: (c: Commission) => void;
    onDelete: (id: number) => void;
}) {
    return (
        <div className="bg-white shadow rounded-xl p-6 mt-6">
            <h2 className="text-xl font-semibold mb-4 text-primary">
                Lista de Comisiones
            </h2>

            <table className="w-full table-auto text-left">
                <thead className="border-b">
                    <tr>
                        <th className="py-3 font-medium">Desde</th>
                        <th className="py-3 font-medium">Hasta</th>
                        <th className="py-3 font-medium">Comisión (%)</th>
                        <th className="py-3 text-center font-medium">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    {commissions.length === 0 && (
                        <tr>
                            <td colSpan={4} className="py-4 text-center text-gray-500">
                                No hay categorías registradas
                            </td>
                        </tr>
                    )}

                    {commissions.map((c) => (
                        <tr key={c.id} className="border-b hover:bg-gray-50">
                            <td className="py-3">{c.from}</td>
                            <td className="py-3">{c.to}</td>
                            <td className="py-3">{c.percentage}%</td>

                            <td className="py-3 flex justify-center gap-3">
                                <button
                                    onClick={() => onEdit(c)}
                                    className="text-primary hover:underline"
                                >
                                    Editar
                                </button>

                                <button
                                    onClick={() => onDelete(c.id!)}
                                    className="text-red-600 hover:underline"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
