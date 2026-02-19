
export default function CommissionCard({ title, value }: { title: string; value: string | number; }) {
    return (
        <div className="p-4 rounded-lg shadow-sm bg-gradient-to-br from-slate-800 to-slate-700 text-white">
            <div className="text-xs uppercase opacity-80">{title}</div>
            <div className="mt-2 font-bold text-lg">{value}</div>
        </div>
    );
}
