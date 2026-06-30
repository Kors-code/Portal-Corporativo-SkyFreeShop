import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { CalendarDays, Image, ReceiptText, RefreshCw, Send, Target, Users } from "lucide-react";
import {
  getAdvisorSalesSummary,
  sendAdvisorSalesWhatsappReport,
  type AdvisorSalesResponse,
} from "../services/visualizacionesService";

const usd = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 2,
});

const num = new Intl.NumberFormat("es-CO", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

function today() {
  return new Date().toISOString().slice(0, 10);
}

export default function AdvisorSalesDashboard() {
  const [data, setData] = useState<AdvisorSalesResponse | null>(null);
  const [date, setDate] = useState(today());
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const previewUrl = useMemo(() => {
    const params = new URLSearchParams();
    if (date) params.set("date", date);
    return `/api/v1/visualizaciones/ventas-asesores/whatsapp/preview?${params.toString()}`;
  }, [date]);

  const load = async (nextDate = date) => {
    try {
      setLoading(true);
      setError("");
      setMessage("");
      const result = await getAdvisorSalesSummary({ date: nextDate || undefined });
      setData(result);
      setDate(result.date);
    } catch (err) {
      console.error(err);
      setError("No se pudo cargar la visualizacion de ventas por asesor.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const sendWhatsapp = async () => {
    try {
      setSending(true);
      setError("");
      setMessage("");
      const result = await sendAdvisorSalesWhatsappReport({ date });
      setMessage(result.message || "Reporte encolado para WhatsApp.");
    } catch (err) {
      console.error(err);
      setError("No se pudo enviar a WhatsApp. Revisa que el servicio este conectado.");
    } finally {
      setSending(false);
    }
  };

  const total = data?.totals;

  return (
    <div className="space-y-5 text-slate-950">
      <section className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-primary">Visualizaciones</p>
          <h1 className="mt-1 text-2xl font-black leading-tight text-slate-950">Ventas por asesor</h1>
          <p className="mt-2 text-sm font-medium text-slate-500">
            Cierre diario por asesor para revisar desempeno individual y enviar al final del dia.
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <button
            onClick={() => void load(date)}
            disabled={loading}
            className="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white disabled:opacity-50"
          >
            <RefreshCw size={16} />
            Actualizar
          </button>
          <button
            onClick={sendWhatsapp}
            disabled={!data || sending}
            className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-white disabled:opacity-50"
          >
            <Send size={16} />
            {sending ? "Encolando..." : "WhatsApp numero"}
          </button>
        </div>
      </section>

      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
          {error}
        </div>
      )}

      {message && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
          {message}
        </div>
      )}

      <section className="grid gap-3 md:grid-cols-4">
        <Metric label="Venta asesores" value={total ? usd.format(total.total_usd) : "-"} detail={data?.date ?? date} icon={<Users size={17} />} />
        <Metric label="Asesores" value={total ? String(total.advisors_count) : "-"} detail="Con venta" icon={<Users size={17} />} />
        <Metric label="TRX" value={total ? num.format(total.trx) : "-"} detail="Transacciones" icon={<ReceiptText size={17} />} />
        <Metric label="TKT" value={total ? usd.format(total.tkt_usd) : "-"} detail="Ticket promedio" icon={<Target size={17} />} />
      </section>

      <section className="grid gap-4 lg:grid-cols-[320px_1fr]">
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <label>
            <span className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
              <CalendarDays size={15} />
              Fecha
            </span>
            <input
              type="date"
              value={date}
              onChange={(event) => {
                setDate(event.target.value);
                void load(event.target.value);
              }}
              className="h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-900"
            />
          </label>

          <div className="mt-4 rounded-lg bg-slate-50 p-3">
            <div className="text-xs font-black uppercase tracking-wide text-slate-500">Programacion</div>
            <div className="mt-2 text-2xl font-black text-slate-950">23:59</div>
            <div className="mt-1 text-sm font-bold text-slate-500">Envio automatico diario</div>
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-100 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-black text-slate-950">Ranking diario por asesor</h2>
                <p className="text-sm font-semibold text-slate-500">Ventas registradas durante el dia.</p>
              </div>
              <span className="rounded-lg bg-primary/10 px-3 py-2 text-sm font-black text-primary">
                {data?.date ?? date}
              </span>
            </div>
          </div>

          <div className="grid grid-cols-[1.4fr_.75fr_.5fr_.6fr_.6fr] bg-slate-950 px-4 py-3 text-xs font-black uppercase tracking-wide text-white">
            <div>Asesor</div>
            <div className="text-right">Venta</div>
            <div className="text-right">TRX</div>
            <div className="text-right">TKT</div>
            <div className="text-right">Und/TKT</div>
          </div>

          {loading && (
            <div className="px-3 py-10 text-center text-sm font-bold text-slate-500">
              Cargando...
            </div>
          )}

          {!loading && data?.advisors.length === 0 && (
            <div className="px-4 py-10 text-center text-sm font-bold text-slate-500">
              Sin ventas por asesor para esta fecha.
            </div>
          )}

          {!loading && data?.advisors.map((row) => (
            <div
              key={row.user_id}
              className="grid grid-cols-[1.4fr_.75fr_.5fr_.6fr_.6fr] items-center border-b border-slate-100 px-4 py-3 text-base text-slate-950 hover:bg-slate-50"
            >
              <div>
                <div className="font-black">{row.advisor}</div>
                {row.seller_code && (
                  <div className="mt-1 text-xs font-bold uppercase tracking-wide text-slate-400">{row.seller_code}</div>
                )}
              </div>
              <div className="text-right font-black">{num.format(row.total_usd)}</div>
              <div className="text-right font-bold">{num.format(row.trx)}</div>
              <div className="text-right font-bold">{num.format(row.tkt_usd)}</div>
              <div className="text-right font-black">{num.format(row.units_per_ticket)}</div>
            </div>
          ))}

          {!loading && total && (
            <div className="grid grid-cols-[1.4fr_.75fr_.5fr_.6fr_.6fr] items-center bg-primary px-4 py-4 text-base font-black text-white">
              <div>Total asesores</div>
              <div className="text-right">{num.format(total.total_usd)}</div>
              <div className="text-right">{num.format(total.trx)}</div>
              <div className="text-right">{num.format(total.tkt_usd)}</div>
              <div className="text-right">{num.format(total.units_per_ticket)}</div>
            </div>
          )}
        </div>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="mb-3 flex items-center gap-2 text-sm font-black text-slate-700">
          <Image size={17} className="text-primary" />
          Preview WhatsApp
        </div>
        <div className="overflow-auto rounded-lg bg-slate-950 p-3">
          <img src={previewUrl} alt="Preview ventas por asesor" className="mx-auto max-w-full" />
        </div>
      </section>
    </div>
  );
}

function Metric({
  label,
  value,
  detail,
  icon,
}: {
  label: string;
  value: string;
  detail: string;
  icon: ReactNode;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
        {icon}
      </div>
      <div className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-xl font-black text-slate-950">{value}</div>
      <div className="mt-1 text-sm font-semibold text-slate-500">{detail}</div>
    </section>
  );
}
