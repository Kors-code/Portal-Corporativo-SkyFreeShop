// SpecialistCommissionsPanel.tsx
import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================ */
type AdvisorBreakdownRow = {
  classification_code: string;
  classification_name?: string;
  sales_usd?: number;
  sales_cop?: number;
  category_budget_usd_for_user?: number;
  pct_user_of_category_budget?: number | null;
  applied_commission_pct?: number | null;
  commission_usd?: number | null;
};

type SpecialistPayload = {
  sales: any[];
  count?: number;
  specialist_user_id?: number;
  specialist?: any;
  specialist_name?: string;
  business_line?: 'montblanc' | 'parbel' | null;
  budget_id?: number | null;
  totals?: { sales_usd?: number; sales_cop?: number; total_commission_usd?: number; avg_trm?: number };
  user_budget_usd?: number;
  assigned_turns_for_user?: number;
  tickets?: any[];
  tickets_summary?: { tickets_count?: number; avg_ticket_usd?: number; avg_units_per_ticket?: number };
  days_worked?: any[];
  breakdown?: Record<string, any> | AdvisorBreakdownRow[]; // controller returns either object or array
};

/* ============ CONSTS ============== */
const MIN_PCT_TO_QUALIFY = 80;
const DEFAULT_MONT_CLASSIFICATIONS = ['19','14','15','16','21']; // Montblanc defaults
const PARBEL_ALLOWED_KEYS = ['skin','fragancias']; // normalized keys used in controller for parbel

/* ============ COMPONENT ============ */
export default function SpecialistCommissionsPanel({ initialBudgetId }: { initialBudgetId?: number }) {
  const [loading, setLoading] = useState<boolean>(true);
  const [budgetId, setBudgetId] = useState<number | null>(initialBudgetId ?? null);
  const [budgets, setBudgets] = useState<any[]>([]);
  const [isSpecialist, setIsSpecialist] = useState<boolean | null>(null);
  const [specRow, setSpecRow] = useState<any | null>(null);
  const [payload, setPayload] = useState<SpecialistPayload | null>(null);
  const [categories, setCategories] = useState<AdvisorBreakdownRow[]>([]);
  const [sales, setSales] = useState<any[]>([]);
  const [tickets, setTickets] = useState<any[]>([]);
  const [ticketsSummary, setTicketsSummary] = useState<any | null>(null);
  const [daysWorked, setDaysWorked] = useState<any[]>([]);
  const [turnos, setTurnos] = useState<number>(0);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // load budgets list (same as other page)
    (async () => {
      try {
        const res = await api.get('/budgets');
        const data = res.data || [];
        setBudgets(data);
        if (!budgetId && data.length) setBudgetId(data[0].id);
      } catch (e) {
        setBudgets([]);
      }
    })();
    // eslint-disable-next-line
  }, []);

  useEffect(() => {
    if (budgetId) loadForBudget(budgetId);
    // eslint-disable-next-line
  }, [budgetId]);

  async function loadForBudget(bId: number) {
    setLoading(true);
    setError(null);
    setIsSpecialist(null);
    setSpecRow(null);
    setPayload(null);
    setCategories([]);
    setSales([]);
    setTickets([]);
    setTicketsSummary(null);
    setDaysWorked([]);
    setTurnos(0);

    try {
      // 1) specialistCheck -> devuelve is_specialist + specialist_row
      const specRes = await api.get('/advisors/specialistCheck', { params: { budget_id: bId } });
      const specData = specRes.data ?? {};
      const specialist = specData.is_specialist === true;
      setIsSpecialist(specialist);
      setSpecRow(specData.specialist_row ?? specData);

      if (!specialist) {
        // no es especialista: mostramos aviso (no fallback aquí, este componente SOLO consume advisor)
        setLoading(false);
        return;
      }

      // 2) si es especialista -> pedir activeSpecialistsSales (prefer server-side business_line)
      const businessLine = specData.business_line ?? specData.specialist_row?.business_line ?? undefined;
      const forcedUserId = specData.specialist_row?.user_id ?? undefined;
      const activeRes = await api.get('/advisors/active-sales', {
        params: {
          budget_id: bId,
          business_line: businessLine,
          user_id: forcedUserId
        }
      });
      const adv = activeRes.data as SpecialistPayload || null;
      setPayload(adv);

      // 2b) -> ADICIONAL: pedir detalle por vendedor (sales/tickets/days) usando by-seller (esto requiere userId)
      const userId = forcedUserId ?? adv?.specialist_user_id ?? adv?.specialist?.id ?? null;
      if (userId) {
        try {
          // usamos budget_id individual porque active-sales usa un solo budget en este flujo
          const detailRes = await api.get(`/commissions/by-seller/${userId}`, { params: { budget_id: bId } });
          const detail = detailRes.data || {};

          // compute provisional commission per sale (igual que en CommissionDetailModal)
          const avgTrm = Number(detail.totals?.avg_trm || detail.totals?.avg_trm || 0) || Number(adv?.totals?.avg_trm || 1) || 1;
          const computedSales = (detail.sales || []).map((s: any, i: number) => {
            const amountCop = Number(s.amount_cop || 0);
            const valueUsd = Number(s.value_usd || 0);

            // buscar categoria en detail.categories
            const cat = (detail.categories || []).find((c: any) =>
              String(c.classification_code) === String(s.category_code)
            );
            const pct = Number(cat?.applied_commission_pct || 0);

            const commission =
              amountCop > 0
                ? amountCop * (pct / 100)
                : valueUsd * avgTrm * (pct / 100);

            const rowKey = s.id ? String(s.id) : `${s.folio ?? 'nofolio'}-${s.sale_date ?? 'nodate'}-${String(s.product ?? '').slice(0,30)}-${i}`;

            return {
              ...s,
              id: s.id,
              commission_amount: Math.round(commission),
              is_provisional: true,
              rowKey,
            };
          });

          setSales(computedSales);
          setTickets(detail.tickets || adv?.tickets || []);
          setTicketsSummary(detail.tickets_summary || adv?.tickets_summary || null);
          setDaysWorked(detail.days_worked || adv?.days_worked || []);
          setTurnos(detail.assigned_turns_for_user ?? adv?.assigned_turns_for_user ?? 0);
        } catch (detailErr) {
          // si falla detail -> fallback parcial (usa adv.tickets si viene)
          console.warn('No se pudo cargar detail by-seller:', detailErr);
          setSales(adv?.sales ?? []);
          setTickets(adv?.tickets ?? []);
          setTicketsSummary(adv?.tickets_summary ?? null);
          setDaysWorked(adv?.days_worked ?? []);
          setTurnos(adv?.assigned_turns_for_user ?? 0);
        }
      } else {
        // sin userId -> al menos usar lo que trae active-sales
        setSales(adv?.sales ?? []);
        setTickets(adv?.tickets ?? []);
        setTicketsSummary(adv?.tickets_summary ?? null);
        setDaysWorked(adv?.days_worked ?? []);
        setTurnos(adv?.assigned_turns_for_user ?? 0);
      }

      setPayload(adv);

      // 3) normalize breakdown into array of rows
      const breakdownRows: AdvisorBreakdownRow[] = normalizeBreakdown(adv?.breakdown);

      // 4) Filter categories to only those that belong to the specialist's line:
      let filtered: AdvisorBreakdownRow[] = breakdownRows;
      if (businessLine === 'parbel') {
        filtered = breakdownRows.filter(r => {
          const code = String(r.classification_code ?? '').toLowerCase();
          if (PARBEL_ALLOWED_KEYS.includes(code)) return true;
          if (code === '13' || code === 'fragancias' || code === 'frag') return true;
          return false;
        });
      } else { // montblanc
        filtered = breakdownRows.filter(r => {
          const code = String(r.classification_code ?? '');
          return DEFAULT_MONT_CLASSIFICATIONS.includes(code) || /(gifts|watch|jewel|sunglass|electro)/i.test(String(r.classification_name ?? ''));
        });
        // ensure defaults present
        const present = new Set(filtered.map(x => String(x.classification_code)));
        for (const def of DEFAULT_MONT_CLASSIFICATIONS) {
          if (!present.has(def)) filtered.push({
            classification_code: def,
            classification_name: defaultMontName(def),
            sales_usd: 0,
            commission_usd: 0,
            category_budget_usd_for_user: 0,
            pct_user_of_category_budget: 0,
            applied_commission_pct: 0
          });
        }
      }

      filtered.sort((a,b) => (b.sales_usd||0) - (a.sales_usd||0));
      setCategories(filtered);

    } catch (e: any) {
      console.error('Error cargando datos especialista:', e);
      setError((e?.response?.data?.message) ?? (e?.message) ?? 'Error desconocido');
    } finally {
      setLoading(false);
    }
  }

  /* =========== HELPERS ============ */
  function normalizeBreakdown(input: any): AdvisorBreakdownRow[] {
    if (!input) return [];
    if (Array.isArray(input)) return input.map(normalizeRow);
    if (typeof input === 'object') {
      return Object.keys(input).map(k => {
        const it = input[k];
        return normalizeRow({
          classification_code: it.classification_code ?? k,
          classification_name: it.classification_name ?? it.name ?? k,
          sales_usd: it.sales_usd ?? it.sales_sum_usd ?? 0,
          commission_usd: it.commission_usd ?? it.commission_sum_usd ?? 0,
          category_budget_usd_for_user: it.category_budget_usd_for_user ?? it.budget_usd ?? 0,
          pct_user_of_category_budget: it.pct_user_of_category_budget ?? null,
          applied_commission_pct: it.applied_commission_pct ?? null
        });
      });
    }
    return [];
  }

  function normalizeRow(r: any): AdvisorBreakdownRow {
    return {
      classification_code: String(r.classification_code ?? r.classification ?? r.classification_key ?? ''),
      classification_name: r.classification_name ?? r.name ?? String(r.classification_code ?? ''),
      sales_usd: Number(r.sales_usd ?? r.sales_sum_usd ?? 0),
      sales_cop: Number(r.sales_cop ?? 0),
      category_budget_usd_for_user: Number(r.category_budget_usd_for_user ?? r.budget_usd ?? 0),
      pct_user_of_category_budget: r.pct_user_of_category_budget ?? null,
      applied_commission_pct: r.applied_commission_pct ?? null,
      commission_usd: Number(r.commission_usd ?? r.commission_sum_usd ?? 0)
    };
  }

  function defaultMontName(code: string) {
    const map: Record<string,string> = { '19':'Gifts & Accessories','14':'Watches','15':'Jewerly','16':'Sunglasses','21':'Electronics' };
    return map[code] ?? code;
  }

  /* ========== KPI DERIVED =========== */
  const totalSalesUsd = useMemo(() => categories.reduce((s,c)=>s + (c.sales_usd || 0), 0), [categories]);
  const userBudgetUsd = payload?.user_budget_usd ?? 0;
  const totalCommissionUsd = useMemo(() => {
    if (payload?.totals?.total_commission_usd) return payload.totals.total_commission_usd;
    return categories.reduce((s,c)=>s + (c.commission_usd || 0), 0);
  }, [payload, categories]);

  const ticketsCount = Number(ticketsSummary?.tickets_count ?? (tickets.length || 0));
  const avgTicketUsd = Number(ticketsSummary?.avg_ticket_usd ?? 0);
  const avgUnitsPerTicket = Number(ticketsSummary?.avg_units_per_ticket ?? 0);
  const userPct = userBudgetUsd > 0 ? (totalSalesUsd / userBudgetUsd) * 100 : 0;
  const userPctRounded = Math.round((userPct + Number.EPSILON) * 100) / 100;
  const meetsBudget = userPct >= MIN_PCT_TO_QUALIFY;

  /* =========== FORMATTERS =========== */
  const moneyUSD = (v:number) => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v||0);
  const moneyCOP = (v:number) => new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(v||0);

  /* =========== RENDER =============== */
  return (
    <div className="min-h-[420px] bg-gradient-to-br from-gray-50 to-gray-100 p-6 rounded-lg shadow-md">
      <div className="max-w-6xl mx-auto">
        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-6">
          <div>
                      <button
            onClick={() => window.location.href = 'https://skyfreeshopdutyfree.com/welcome'}
            className="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-sm font-medium"
          >
            ← Volver
          </button>
            <div className="text-sm text-gray-500">Panel especialistas</div>
            <h2 className="text-2xl md:text-3xl font-extrabold">Asesor especializado</h2>
            <div className="text-sm text-gray-400 mt-1">Vista profesional — datos desde <span className="font-medium text-indigo-600">Advisor</span></div>
          </div>

          <div className="flex items-center gap-3">
            <select value={budgetId ?? ''} onChange={(e)=>setBudgetId(Number(e.target.value) || null)} className="border rounded px-3 py-2 bg-white">
              <option value="">Seleccionar presupuesto</option>
              {budgets.map(b => <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>)}
            </select>
            <button onClick={() => budgetId && loadForBudget(budgetId)} className="px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold">Actualizar</button>
          </div>
        </div>

        {/* body */}
        <div className="bg-white rounded-2xl shadow p-5">
          {loading ? (
            <div className="p-8 text-center text-gray-500">Cargando datos del especialista…</div>
          ) : error ? (
            <div className="p-6 text-red-600">{error}</div>
          ) : isSpecialist === false ? (
            <div className="p-6 text-center">
              <div className="text-lg font-semibold">Usuario no es asesor especializado</div>
              <div className="text-sm text-gray-500 mt-2">Este panel muestra datos sólo para asesores especializados. Usa la vista normal de comisiones para ver tu información.</div>
              <div className="mt-4">
                <button onClick={() => window.location.href = '/mis-comisiones'} className="px-4 py-2 rounded-md bg-gray-200">Ir a mis comisiones</button>
              </div>
            </div>
          ) : (
            <>
              {/* top KPIs - pro look */}
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="p-4 bg-gradient-to-br from-indigo-50 to-white rounded-lg border">
                  <div className="text-sm text-gray-500">Ventas (USD)</div>
                  <div className="text-2xl font-bold">{moneyUSD(totalSalesUsd)}</div>
                  <div className="text-xs text-gray-400 mt-1">Totales en periodo</div>
                </div>

                <div className="p-4 bg-gradient-to-br from-emerald-50 to-white rounded-lg border">
                  <div className="text-sm text-gray-500">PPTO usuario</div>
                  <div className="text-2xl font-bold">{moneyUSD(userBudgetUsd)}</div>
                  <div className="text-xs text-gray-400 mt-1">Presupuesto asignado</div>
                </div>

                <div className="p-4 bg-gradient-to-br from-yellow-50 to-white rounded-lg border">
                  <div className="text-sm text-gray-500">Cumplimiento</div>
                  <div className="text-2xl font-bold">{userPctRounded.toFixed(2)}%</div>
                  <div className="w-full bg-gray-200 h-2 rounded mt-2 overflow-hidden"><div style={{width: `${Math.min(100, userPctRounded)}%`}} className={`h-2 ${userPctRounded>=100 ? 'bg-green-500' : userPctRounded>=MIN_PCT_TO_QUALIFY ? 'bg-yellow-500' : 'bg-red-500'}`} /></div>
                </div>

                <div className="p-4 bg-gradient-to-br from-pink-50 to-white rounded-lg border">
                  <div className="text-sm text-gray-500">Comisión (USD)</div>
                  <div className="text-2xl font-bold">{meetsBudget ? moneyUSD(totalCommissionUsd) : '—'}</div>
                  <div className="text-xs text-gray-400 mt-1">{meetsBudget ? 'Disponible' : `No comisiona (< ${MIN_PCT_TO_QUALIFY}%)`}</div>
                </div>
              </div>

              {/* secondary KPIs */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div className="p-4 border rounded-lg">
                  <div className="text-sm text-gray-500">Tickets</div>
                  <div className="text-xl font-semibold">{ticketsCount}</div>
                  <div className="text-xs text-gray-400 mt-1">Tickets en periodo</div>
                </div>
                <div className="p-4 border rounded-lg">
                  <div className="text-sm text-gray-500">Ticket promedio (USD)</div>
                  <div className="text-xl font-semibold">{moneyUSD(avgTicketUsd)}</div>
                  <div className="text-xs text-gray-400 mt-1">Promedio por ticket</div>
                </div>
                <div className="p-4 border rounded-lg">
                  <div className="text-sm text-gray-500">Unidades / Ticket</div>
                  <div className="text-xl font-semibold">{Number(avgUnitsPerTicket || 0).toFixed(2)}</div>
                  <div className="text-xs text-gray-400 mt-1">Promedio unidades por ticket</div>
                </div>
              </div>

              {/* Categories table (only specialist categories) */}
              <div className="mb-6">
                <div className="flex items-center justify-between mb-3">
                  <h3 className="text-lg font-semibold">Categorías ({categories.length})</h3>
                  <div className="text-sm text-gray-500">Línea: <span className="font-medium">{payload?.business_line ?? specRow?.business_line ?? '—'}</span></div>
                </div>

                <div className="bg-gray-50 rounded-lg border overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-white">
                      <tr>
                        <th className="p-3 text-left">Categoría</th>
                        <th className="p-3 text-right">PPTO</th>
                        <th className="p-3 text-right">Ventas</th>
                        <th className="p-3 text-right">% Cumpl.</th>
                        <th className="p-3 text-right">% Comisión</th>
                        <th className="p-3 text-right">Comisión (USD)</th>
                      </tr>
                    </thead>
                    <tbody>
                      {categories.map((c) => (
                        <tr key={String(c.classification_code)} className="border-t">
                          <td className="p-3">{c.classification_name ?? String(c.classification_code)}</td>
                          <td className="p-3 text-right">{moneyUSD(Number(c.category_budget_usd_for_user || 0))}</td>
                          <td className="p-3 text-right font-semibold">{moneyUSD(Number(c.sales_usd || 0))}</td>
                          <td className="p-3 text-right">{(Number(c.pct_user_of_category_budget ?? 0)).toFixed(1)}%</td>
                          <td className="p-3 text-right">{(Number(c.applied_commission_pct ?? 0)).toFixed(2)}%</td>
                          <td className="p-3 text-right font-semibold">{moneyUSD(Number(c.commission_usd || 0))}</td>
                        </tr>
                      ))}
                      {categories.length === 0 && (
                        <tr><td colSpan={6} className="p-6 text-center text-gray-500">No hay categorías para mostrar</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Sales sample & metadata (ahora usando el detalle por vendedor que ya funciona) */}
              <div className="flex flex-col md:flex-row gap-4">
                <div className="flex-1 bg-white border rounded-lg p-4">
                  <div className="flex justify-between items-center mb-3">
                    <div className="text-sm font-semibold">Detalle de ventas (muestra)</div>
                    <div className="text-xs text-gray-400">{(sales?.length || 0)} registros</div>
                  </div>

                  <div className="space-y-2 max-h-64 overflow-auto">
                    {(sales || []).slice(0,50).map((s, i) => (
                      <div key={s.id ?? s.rowKey ?? i} className="flex justify-between text-sm text-gray-700 border-b pb-2 pt-2">
                        <div className="min-w-0">
                          <div className="truncate font-medium">{s.product ?? s.folio ?? '—'}</div>
                          <div className="text-xs text-gray-400">{s.sale_date} · {s.provider ?? '—'}</div>
                          <div className="text-xs text-gray-400">Folio: {s.folio ?? '—'} · Cat: {s.category_code ?? s.classification ?? '—'}</div>
                        </div>
                        <div className="text-right">
                          <div className="font-medium">{moneyUSD(Number(s.value_usd ?? 0))}</div>
                          <div className="text-xs text-gray-400">{s.commission_amount != null ? moneyCOP(Number(s.commission_amount)) : 'Calculada'}</div>
                        </div>
                      </div>
                    ))}
                    {(!sales || sales.length === 0) && <div className="p-4 text-center text-gray-400">No hay ventas detalladas</div>}
                  </div>
                </div>

                <div className="w-80 bg-white border rounded-lg p-4">
                  <div className="text-sm font-semibold mb-2">Resumen</div>
                  <div className="text-sm text-gray-600 mb-1">PPTO usuario</div>
                  <div className="font-bold text-lg">{moneyUSD(userBudgetUsd)}</div>

                  <div className="mt-3 text-sm text-gray-600 mb-1">Ventas totales</div>
                  <div className="font-bold">{moneyUSD(totalSalesUsd)}</div>

                  <div className="mt-3 text-sm text-gray-600 mb-1">Comisión total</div>
                  <div className="font-bold">{meetsBudget ? moneyUSD(totalCommissionUsd) : '—'}</div>

                  <div className="mt-3 text-xs text-gray-400">Turnos: <span className="font-medium text-gray-700">{turnos}</span></div>
                  <div className="mt-1 text-xs text-gray-400">Días trabajados: <span className="font-medium text-gray-700">{(daysWorked?.length || 0)}</span></div>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}