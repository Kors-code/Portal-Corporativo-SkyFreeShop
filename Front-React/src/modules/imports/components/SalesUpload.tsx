import  { useState } from 'react';
import { uploadSalesFile } from '../../../api/sales';

export default function SalesUpload() {
    const [file, setFile] = useState<File | null>(null);
    const [msg, setMsg] = useState('');
    const [loading, setLoading] = useState(false);

    const submit = async (e: any) => {
        e.preventDefault();
        if (!file) return alert('Selecciona un archivo');
        const fd = new FormData(); fd.append('file', file);
        setLoading(true);
        try {
            const res = await uploadSalesFile(fd);
            setMsg(res.data.message || 'Import OK');
        } catch (err: any) {
            setMsg(err?.response?.data?.error || 'Error importando');
        } finally { setLoading(false); }
    };

    return (
        <div className="p-6 max-w-lg mx-auto">
            <h2 className="text-xl font-bold text-primary mb-4">Importar ventas (diario)</h2>
            <form onSubmit={submit} className="space-y-4">
                <input type="file" accept=".csv,.xls,.xlsx" onChange={e => setFile(e.target.files?.[0] ?? null)} />
                <div>
                    <button className="bg-primary text-white px-4 py-2 rounded" disabled={loading}>
                        {loading ? 'Importando...' : 'Importar archivo'}
                    </button>
                </div>
            </form>
            {msg && <div className="mt-4 text-sm">{msg}</div>}
        </div>
    );
}
