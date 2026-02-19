import type { CategoryRow } from '../types';

export default function CommissionTable({ rows }: { rows: CategoryRow[] }) {
    if (!rows?.length) return <div className="p-4 text-gray-500">No hay datos</div>;

    return (
        <div className="overflow-x-auto">
            <table className="w-full table-auto border-collapse">
                <thead>
                    <tr className="bg-gray-100">
                        <th className="p-2 border">Categoría</th>
                        <th className="p-2 border">Ventas ($)</th>
                        <th className="p-2 border"># ventas</th>
                        <th className="p-2 border">% del total</th>
                        <th className="p-2 border">Comisión (USD)</th>
                        <th className="p-2 border">% Comisión</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.category} className="hover:bg-gray-50">
                            <td className="p-2 border">{r.category}</td>
                            <td className="p-2 border">{r.ventas?.toLocaleString() ?? 0}</td>
                            <td className="p-2 border">{r.sales_count}</td>
                            <td className="p-2 border">{r.pct_of_total}%</td>
                            <td className="p-2 border">{r.commission_usd?.toLocaleString() ?? 0}</td>
                            <td className="p-2 border">{r.pct_commission}%</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
