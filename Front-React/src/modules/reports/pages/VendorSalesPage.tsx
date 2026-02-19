import  { useState } from 'react';
import { getSalesByVendor } from '../../../api/sales';

export default function VendorSalesPage() {
    const [vendor, setVendor] = useState('');
    const [date, setDate] = useState('');
    const [rows, setRows] = useState<any[]>([]);
    const [loading, setLoading] = useState(false);

    const load = async () => {
        if (!vendor) return alert('Ingresa código vendedor');
        setLoading(true);
        try {
            const res = await getSalesByVendor(vendor, date || undefined);
            setRows(res.data);
        } catch (err: any) {
            alert('Error');
        } finally { setLoading(false); }
    };

    return (
        <div className="p-6 max-w-4xl mx-auto">
            <h1 className="text-2xl font-bold text-primary mb-4">Ventas por vendedor</h1>

            <div className="flex gap-2 mb-4">
                <input className="border p-2 rounded" placeholder="Código vendedor" value={vendor} onChange={e => setVendor(e.target.value)} />
                <input className="border p-2 rounded" type="date" value={date} onChange={e => setDate(e.target.value)} />
                <button className="bg-primary text-white px-3 rounded" onClick={load}>Buscar</button>
            </div>

            <div>
                {loading ? <p>Cargando...</p> :
                    <table className="w-full">
                        <thead><tr><th>Vendedor</th><th>Total</th></tr></thead>
                        <tbody>
                            {rows.map((r: any) => (
                                <tr key={r.vendor_code}><td>{r.vendor_name} ({r.vendor_code})</td><td>{Number(r.total).toLocaleString()}</td></tr>
                            ))}
                        </tbody>
                    </table>
                }
            </div>
        </div>
    );
}
