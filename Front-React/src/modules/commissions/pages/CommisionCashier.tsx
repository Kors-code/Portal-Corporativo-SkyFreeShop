import { useEffect, useMemo, useState } from "react";
import api from "../../../api/axios";

/* =========================
   Helpers
========================= */
const moneyUSD = (v: any) =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
  }).format(Number(v || 0));

const moneyCOP = (v: any) =>
  new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(Number(v || 0));

const getField = (obj: any, ...keys: string[]) => {
  if (!obj) return undefined;
  for (const k of keys) {
    if (obj[k] !== undefined && obj[k] !== null) return obj[k];
  }
  return undefined;
};

const formatNumber = (v: any) => {
  if (v === "" || v === null || v === undefined) return "";
  const n = Number(String(v).replace(/,/g, ""));
  if (Number.isNaN(n)) return "";
  return n.toLocaleString("en-US");
};

const parseNumber = (v: any) => {
  const raw = String(v ?? "").replace(/,/g, "").trim();
  if (raw === "") return 0;
  const n = Number(raw);
  return Number.isFinite(n) ? n : 0;
};

const calcPrizeByCompliance = (
  compliance: number,
  prize80: number,
  prize100: number,
  prize120: number
) => {
  if (compliance < 80) return 0;
  if (compliance < 100) return prize80;
  if (compliance < 120) return prize100;
  return prize120;
};

const getCategoryLabel = (value: any) => {
  const raw = String(value ?? "").trim();
  if (!raw) return "Sin categoría";

  const map: Record<string, string> = {
    "13": "Skin care",
    "14": "Relojes",
    "15": "Joyería",
    "16": "Gafas",
    "17": "Tabaco",
    "18": "Licores",
    "19": "Gifts",
    "21": "Electrónicos",
    "22": "Chocolates",
    fragancias: "Fragancias",
  };

  const normalized = raw.toLowerCase();

  if (map[raw]) return map[raw];
  if (map[normalized]) return map[normalized];

  if (/^\d+$/.test(raw)) return `Categoría ${raw}`;

  return raw
    .replace(/_/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/\b\w/g, (c) => c.toUpperCase());
};

/* =========================
   Types
========================= */
interface BudgetItem {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  cashier_prize_80?: number;
  cashier_prize_100?: number;
  cashier_prize_120?: number;
  cashier_prize?: number;
}

interface ReportRow {
  user_id: number;
  nombre: string;
  ventas_usd: number;
  pct: number;
  premiacion: number;
}

interface ReportData {
  metaUsd: number;
  prizeAt120: number;
  prizeApplied: number;
  totalVentas: number;
  cumplimiento: number;
  rows: ReportRow[];
  period: { start: string; end: string } | null;
}

interface SaveMessage {
  type: "error" | "success";
  text: string;
}

interface CategoryMeta {
  cashierName: string;
  totalUsd: number;
  totalCop: number;
  tickets: number;
}

/* =========================
   Component
========================= */
export default function CommisionCashier() {
  const [loading, setLoading] = useState(true);
  const [report, setReport] = useState<ReportData | null>(null);
  const [budgets, setBudgets] = useState<BudgetItem[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [selectedRow, setSelectedRow] = useState<ReportRow | null>(null);

  const [view, setView] = useState<"table" | "cards">("table");
  const [sortBy, setSortBy] = useState<"ventas" | "premio" | "pct">("ventas");
  const [sortDir, setSortDir] = useState<"desc" | "asc">("desc");

  const [search, setSearch] = useState("");
  const [saveMessage, setSaveMessage] = useState<SaveMessage | null>(null);
  const [saving, setSaving] = useState(false);

  const [prize80, setPrize80] = useState("");
  const [prize100, setPrize100] = useState("");
  const [prize120, setPrize120] = useState("");

  const [editMode, setEditMode] = useState(false);

  /* =========================
     Load budgets
  ========================= */
  useEffect(() => {
    let mounted = true;

    async function loadBudgets() {
      try {
        const res = await api.get("/budgets");
        if (!mounted) return;

        const list = Array.isArray(res.data) ? res.data : [];
        setBudgets(list);

        if (list.length > 0) {
          const first = list[0];
          setBudgetId(first.id);

          const p80 = getField(first, "cashier_prize_80", "cashierPrize80", "prize_80");
          const p100 = getField(first, "cashier_prize_100", "cashierPrize100", "prize_100");
          const p120 = getField(
            first,
            "cashier_prize_120",
            "cashierPrize120",
            "prize_120",
            "cashier_prize"
          );

          setPrize80(String(p80 ?? ""));
          setPrize100(String(p100 ?? ""));
          setPrize120(String(p120 ?? ""));
        }
      } catch (err) {
        console.error("Error loading budgets", err);
      }
    }

    loadBudgets();

    return () => {
      mounted = false;
    };
  }, []);

  /* =========================
     When budget changes
  ========================= */
  useEffect(() => {
    if (!budgetId) return;

    const b = budgets.find((x) => Number(x.id) === Number(budgetId));
    if (b) {
      const p80 = getField(b, "cashier_prize_80", "cashierPrize80", "prize_80");
      const p100 = getField(b, "cashier_prize_100", "cashierPrize100", "prize_100");
      const p120 = getField(
        b,
        "cashier_prize_120",
        "cashierPrize120",
        "prize_120",
        "cashier_prize"
      );

      setPrize80(String(p80 ?? ""));
      setPrize100(String(p100 ?? ""));
      setPrize120(String(p120 ?? ""));
    }

    loadReport(budgetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetId]);

  /* =========================
     Load report
  ========================= */
  async function loadReport(bid: number) {
    setLoading(true);
    setReport(null);

    try {
      const res = await api.get("/reports/cashier-awards", {
        params: { budget_id: bid },
      });

      const d = res.data || {};
      const rows = Array.isArray(d.rows) ? d.rows : [];

      const totalVentas = Number(
        getField(d, "total_ventas", "totalVentas", "total_ventas_usd", "totalSalesUsd") ?? 0
      );

      const prizeAt120 = Number(
        getField(d, "prize_at_120", "prizeAt120", "premio_base", "cashier_prize") ?? 0
      );

      const prizeApplied = Number(
        getField(d, "prize_applied", "prizeApplied", "premio_aplicado") ?? 0
      );

      const cumplimiento = Number(getField(d, "cumplimiento", "compliance") ?? 0);

      const metaUsd = Number(d.meta_usd || d.metaUSD || d.meta || 0);

      setReport({
        metaUsd,
        prizeAt120,
        prizeApplied,
        totalVentas,
        cumplimiento,
        rows,
        period: d.period || null,
      });
    } catch (err) {
      console.error("Error loading report", err);
      setReport(null);
    } finally {
      setLoading(false);
    }
  }

  /* =========================
     Derived data
  ========================= */
  const currentBudget = useMemo(
    () => budgets.find((b) => Number(b.id) === Number(budgetId)) || null,
    [budgets, budgetId]
  );

  const currentPrizePreview = useMemo(() => {
    const c = report?.cumplimiento ?? 0;
    return calcPrizeByCompliance(
      c,
      parseNumber(prize80),
      parseNumber(prize100),
      parseNumber(prize120)
    );
  }, [report?.cumplimiento, prize80, prize100, prize120]);

  const totalGoal = useMemo(() => report?.metaUsd || 0, [report]);
  const totalSales = useMemo(() => report?.totalVentas || 0, [report]);
  const rows = report?.rows ?? [];

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();

    let list = rows.filter((r) => {
      if (!q) return true;
      return String(r.nombre || "").toLowerCase().includes(q);
    });

    list = [...list].sort((a, b) => {
      const va =
        sortBy === "ventas"
          ? Number(a.ventas_usd || 0)
          : sortBy === "premio"
          ? Number(a.premiacion || 0)
          : Number(a.pct || 0);

      const vb =
        sortBy === "ventas"
          ? Number(b.ventas_usd || 0)
          : sortBy === "premio"
          ? Number(b.premiacion || 0)
          : Number(b.pct || 0);

      const dir = sortDir === "desc" ? -1 : 1;
      return (va - vb) * dir;
    });

    return list;
  }, [rows, search, sortBy, sortDir]);

  const summaryStats = useMemo(() => {
    const prizeMax = parseNumber(prize120);
    return {
      compliance: report?.cumplimiento || 0,
      prizeApplied: currentPrizePreview,
      prizeMax,
      totalSales,
      totalGoal,
    };
  }, [report, currentPrizePreview, prize120, totalSales, totalGoal]);

  /* =========================
     Save prize config
  ========================= */
  async function handleSavePrizeConfig() {
    if (!budgetId) {
      setSaveMessage({ type: "error", text: "Selecciona un presupuesto primero." });
      return;
    }

    const p80 = parseNumber(prize80);
    const p100 = parseNumber(prize100);
    const p120 = parseNumber(prize120);

    setSaving(true);
    setSaveMessage(null);

    try {
      await api.patch(`/budgets/${budgetId}/cashier-prizes`, {
        cashier_prize_80: Math.round(p80),
        cashier_prize_100: Math.round(p100),
        cashier_prize_120: Math.round(p120),
      });

      setBudgets((prev) =>
        prev.map((b) =>
          Number(b.id) === Number(budgetId)
            ? {
                ...b,
                cashier_prize_80: Math.round(p80),
                cashier_prize_100: Math.round(p100),
                cashier_prize_120: Math.round(p120),
                cashier_prize: Math.round(p120),
              }
            : b
        )
      );

      await loadReport(budgetId);
      setSaveMessage({ type: "success", text: "Configuración guardada correctamente." });
    } catch (err) {
      console.error("Error saving prize config", err);
      setSaveMessage({ type: "error", text: "No se pudo guardar la configuración." });
    } finally {
      setSaving(false);
      setTimeout(() => setSaveMessage(null), 3500);
    }
  }

  /* =========================
     Export
  ========================= */
  async function downloadExcel() {
    if (!budgetId) {
      alert("Selecciona un presupuesto");
      return;
    }

    try {
      const res = await api.get("/reports/cashier-awards/export", {
        params: { budget_id: budgetId },
        responseType: "blob",
      });

      const blob = new Blob([res.data], {
        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      });

      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `cashier_awards_budget_${budgetId}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error(err);
      alert("Error descargando Excel");
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center">
        <div className="rounded-2xl bg-white shadow-lg px-6 py-5 text-slate-700">
          Cargando reporte de cajeros...
        </div>
      </div>
    );
  }

  if (!report) {
    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div className="rounded-2xl bg-white shadow-lg px-6 py-5 text-red-600 font-medium">
          No se pudieron cargar los datos.
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 via-white to-rose-50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        {/* Header */}
        <div className="mb-6 rounded-3xl bg-white shadow-sm border border-slate-100 overflow-hidden">
          <div className="bg-gradient-to-r from-[#5C0013] via-[#7A0019] to-[#990F2B] px-5 sm:px-6 py-6 text-white">
            <div className="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
              <div>
                <div className="text-sm/6 uppercase tracking-[0.22em] text-white/80">
                  Reporte de cajeros
                </div>
                <h2 className="text-2xl sm:text-3xl font-bold mt-1">
                  Comisiones para cajeros
                </h2>
                <p className="text-white/80 mt-2 max-w-2xl">
                  
                </p>
              </div>

              <div className="flex flex-wrap gap-3">
                <button
                  onClick={downloadExcel}
                  className="px-4 py-2 rounded-xl bg-white text-[#7A0019] font-medium shadow-sm hover:bg-rose-50 transition"
                >
                  Exportar Excel
                </button>
                <button
                  onClick={() => setEditMode((v) => !v)}
                  className="px-4 py-2 rounded-xl bg-black/10 text-white font-medium hover:bg-black/20 transition"
                >
                  {editMode ? "Ocultar edición" : "Editar premio"}
                </button>
              </div>
            </div>
          </div>

          <div className="p-5 sm:p-6">
            <div className="grid grid-cols-1 xl:grid-cols-12 gap-4">
              <div className="xl:col-span-4">
                <label className="block text-sm font-medium text-slate-600 mb-2">
                  Presupuesto
                </label>
                <select
                  value={budgetId ?? ""}
                  onChange={(e) => setBudgetId(Number(e.target.value))}
                  className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:ring-2 focus:ring-red-500"
                >
                  {budgets.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} — {b.start_date} → {b.end_date}
                    </option>
                  ))}
                </select>

                <div className="mt-3 text-xs text-slate-500">
                  Presupuesto actual:{" "}
                  <span className="font-semibold text-slate-700">
                    {currentBudget?.name || "—"}
                  </span>
                </div>
              </div>

              <div className="xl:col-span-8 grid grid-cols-2 lg:grid-cols-4 gap-3">
                <StatCard
                  label="Ventas"
                  value={moneyUSD(summaryStats.totalSales)}
                  sub={`Meta: ${moneyUSD(summaryStats.totalGoal)}`}
                />
                <StatCard
                  label="Cumplimiento"
                  value={`${Number(summaryStats.compliance || 0).toFixed(0)}%`}
                  sub="Según el presupuesto"
                />
                <StatCard
                  label="Premio aplicado"
                  value={moneyUSD(summaryStats.prizeApplied)}
                  sub="Calculado automáticamente"
                />
                <StatCard
                  label="Premio máximo"
                  value={moneyUSD(summaryStats.prizeMax)}
                  sub="Valor del 120%"
                />
              </div>
            </div>

            <div className="mt-5">
              <div className="flex items-center justify-between text-sm mb-2">
                <span className="text-slate-600">Progreso de cumplimiento</span>
                <span className="font-semibold text-slate-800">
                  {Number(report.cumplimiento || 0).toFixed(0)}%
                </span>
              </div>
              <div className="h-3 rounded-full bg-slate-100 overflow-hidden">
                <div
                  className="h-full rounded-full bg-gradient-to-r from-[#5C0013] via-[#7A0019] to-[#990F2B] transition-all"
                  style={{ width: `${Math.min(report.cumplimiento || 0, 100)}%` }}
                />
              </div>
              <div className="mt-2 text-xs text-slate-500">
                Premio calculado con tus valores manuales: 80% → {moneyUSD(parseNumber(prize80))}, 100% →{" "}
                {moneyUSD(parseNumber(prize100))}, 120% → {moneyUSD(parseNumber(prize120))}
              </div>
            </div>

            {editMode && (
              <div className="mt-6 rounded-3xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
                  <div>
                    <h3 className="text-lg font-bold text-slate-900">
                      Configuración manual de premios
                    </h3>
                    <p className="text-sm text-slate-500">
                      Define el valor que se paga cuando el cumplimiento llega al 80%, 100% y 120%.
                    </p>
                  </div>

                  <button
                    onClick={handleSavePrizeConfig}
                    disabled={saving}
                    className={`px-4 py-2 rounded-xl font-medium transition ${
                      saving
                        ? "bg-slate-300 text-slate-700 cursor-not-allowed"
                        : "bg-[#7A0019] text-white hover:bg-[#5C0013]"
                    }`}
                  >
                    {saving ? "Guardando..." : "Guardar configuración"}
                  </button>
                </div>

                {saveMessage && (
                  <div
                    className={`mb-4 rounded-xl px-4 py-3 text-sm ${
                      saveMessage.type === "error"
                        ? "bg-[#FAF5F6] text-[#7A0019] border border-[#E7D4D8]"
                        : "bg-green-50 text-green-700 border border-green-200"
                    }`}
                  >
                    {saveMessage.text}
                  </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <PrizeField
                    label="Premio al 80%"
                    value={prize80}
                    onChange={setPrize80}
                    hint="Primer escalón"
                  />
                  <PrizeField
                    label="Premio al 100%"
                    value={prize100}
                    onChange={setPrize100}
                    hint="Segundo escalón"
                  />
                  <PrizeField
                    label="Premio al 120%"
                    value={prize120}
                    onChange={setPrize120}
                    hint="Máximo"
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Toolbar */}
        <div className="mb-5 rounded-3xl bg-white shadow-sm border border-slate-100 p-4 sm:p-5">
          <div className="flex flex-col lg:flex-row lg:items-center gap-4">
            <div className="flex-1">
              <label className="block text-sm font-medium text-slate-600 mb-2">
                Buscar cajero
              </label>
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Escribe el nombre..."
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none focus:ring-2 focus:ring-red-500"
              />
            </div>

            <div className="flex flex-wrap items-center gap-3">
              <div className="rounded-2xl border border-slate-200 px-3 py-2 bg-white">
                <select
                  value={sortBy}
                  onChange={(e) => setSortBy(e.target.value as any)}
                  className="bg-transparent outline-none text-sm"
                >
                  <option value="ventas">Ordenar por ventas</option>
                  <option value="premio">Ordenar por premio</option>
                  <option value="pct">Ordenar por % participación</option>
                </select>
              </div>

              <button
                onClick={() => setSortDir((d) => (d === "desc" ? "asc" : "desc"))}
                className="px-4 py-2 rounded-2xl border border-slate-200 bg-white text-sm hover:bg-slate-50 transition"
              >
                {sortDir === "desc" ? "Descendente ▼" : "Ascendente ▲"}
              </button>

              <div className="rounded-2xl border border-slate-200 bg-white p-1 flex">
                <button
                  onClick={() => setView("table")}
                  className={`px-4 py-2 rounded-xl text-sm font-medium transition ${
                    view === "table" ? "bg-[#7A0019] text-white" : "text-slate-700"
                  }`}
                >
                  Tabla
                </button>
                <button
                  onClick={() => setView("cards")}
                  className={`px-4 py-2 rounded-xl text-sm font-medium transition ${
                    view === "cards" ? "bg-[#7A0019] text-white" : "text-slate-700"
                  }`}
                >
                  Tarjetas
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Content */}
        {view === "table" ? (
          <div className="overflow-hidden rounded-3xl bg-white shadow-sm border border-slate-100">
            <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
              <div>
                <h3 className="font-bold text-slate-900">Listado de cajeros</h3>
                <p className="text-sm text-slate-500">Haz clic en una fila para ver categorías.</p>
              </div>
              <div className="text-sm text-slate-500">
                {filteredRows.length} resultado(s)
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-slate-600">
                  <tr>
                    <th className="p-4 text-left">Cajero</th>
                    <th className="p-4 text-right">Ventas USD</th>
                    <th className="p-4 text-right">% Participación</th>
                    <th className="p-4 text-right">Premiación</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredRows.map((r, i) => (
                    <tr
                      key={r.user_id ?? i}
                      className="border-t border-slate-100 hover:bg-rose-50/40 cursor-pointer transition"
                      onClick={() => setSelectedRow(r)}
                    >
                      <td className="p-4">
                        <div className="font-medium text-slate-900">{r.nombre}</div>
                        <div className="text-xs text-slate-500">Cajero #{r.user_id}</div>
                      </td>
                      <td className="p-4 text-right font-medium text-emerald-700">
                        {moneyUSD(r.ventas_usd)}
                      </td>
                      <td className="p-4 text-right text-slate-700">
                        {Number(r.pct || 0).toFixed(2)}%
                      </td>
                      <td className="p-4 text-right font-semibold text-rose-700">
                        {moneyUSD(r.premiacion)}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="bg-slate-50">
                  <tr>
                    <td className="p-4 font-semibold text-slate-900">Total</td>
                    <td className="p-4 text-right font-semibold">{moneyUSD(totalSales)}</td>
                    <td className="p-4 text-right font-semibold">100%</td>
                    <td className="p-4 text-right font-semibold">
                      {moneyUSD(currentPrizePreview)}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            {filteredRows.map((r, i) => (
              <button
                key={r.user_id ?? i}
                onClick={() => setSelectedRow(r)}
                className="text-left rounded-3xl bg-white shadow-sm border border-slate-100 p-5 hover:shadow-lg hover:-translate-y-1 transition"
              >
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <div className="text-xs uppercase tracking-[0.18em] text-slate-400">
                      Cajero
                    </div>
                    <div className="mt-1 text-lg font-bold text-slate-900">{r.nombre}</div>
                    <div className="mt-1 text-sm text-slate-500">ID {r.user_id}</div>
                  </div>

                  <div className="text-right">
                    <div className="text-xs text-slate-400">Premiación</div>
                    <div className="text-xl font-bold text-rose-700">{moneyUSD(r.premiacion)}</div>
                  </div>
                </div>

                <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
                  <InfoLine label="Ventas" value={moneyUSD(r.ventas_usd)} />
                  <InfoLine
                    label="% participación"
                    value={`${Number(r.pct || 0).toFixed(2)}%`}
                  />
                </div>
              </button>
            ))}
          </div>
        )}

        {/* Modal */}
        {selectedRow && (
          <CashierCategoryModal
            selectedRow={selectedRow}
            budgetId={budgetId}
            onClose={() => setSelectedRow(null)}
          />
        )}
      </div>
    </div>
  );
}

/* =========================
   Small UI pieces
========================= */
function StatCard({
  label,
  value,
  sub,
}: {
  label: string;
  value: string;
  sub?: string;
}) {
  return (
    <div className="rounded-3xl bg-slate-50 border border-slate-100 p-4 shadow-sm">
      <div className="text-xs uppercase tracking-[0.18em] text-slate-400">{label}</div>
      <div className="mt-2 text-xl font-bold text-slate-900">{value}</div>
      {sub && <div className="mt-1 text-sm text-slate-500">{sub}</div>}
    </div>
  );
}

function PrizeField({
  label,
  value,
  onChange,
  hint,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  hint?: string;
}) {
  return (
    <div className="rounded-3xl bg-white border border-slate-200 p-4 shadow-sm">
      <label className="block text-sm font-medium text-slate-700 mb-2">{label}</label>
      <input
        type="text"
        inputMode="numeric"
        value={formatNumber(value)}
        onChange={(e) => onChange(e.target.value.replace(/[^\d.,]/g, ""))}
        placeholder="0"
        className="w-full rounded-2xl border border-slate-200 px-4 py-3 text-right outline-none focus:ring-2 focus:ring-red-500"
      />
      {hint && <div className="mt-2 text-xs text-slate-500">{hint}</div>}
    </div>
  );
}

function InfoLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl bg-slate-50 p-3">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="mt-1 font-semibold text-slate-900">{value}</div>
    </div>
  );
}

/* =========================
   Modal
========================= */
interface CashierCategoryModalProps {
  selectedRow: any;
  budgetId: number | null;
  onClose: () => void;
}

function CashierCategoryModal({
  selectedRow,
  budgetId,
  onClose,
}: CashierCategoryModalProps) {
  const [loading, setLoading] = useState(true);
  const [cats, setCats] = useState<any[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [meta, setMeta] = useState<CategoryMeta>({
    cashierName: selectedRow?.nombre || "—",
    totalUsd: 0,
    totalCop: 0,
    tickets: 0,
  });

  const cashierId = selectedRow?.user_id ?? selectedRow?.id ?? null;

  useEffect(() => {
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };

    window.addEventListener("keydown", onKey);

    async function load() {
      if (!cashierId) {
        setError("No se encontró identificador del cajero.");
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const res = await api.get(`/reports/cashier/${cashierId}/categories`, {
          params: { budget_id: budgetId },
        });

        const d = res.data || {};
        setCats(Array.isArray(d.categories) ? d.categories : []);
        setMeta({
          cashierName: d.cashier?.name ?? selectedRow.nombre ?? "—",
          totalUsd: Number(d.summary?.total_sales_usd ?? 0),
          totalCop: Number(d.summary?.total_sales_cop ?? 0),
          tickets: Number(d.summary?.tickets_count ?? 0),
        });
      } catch (e) {
        console.error("Error loading cashier categories", e);
        setError("Error cargando categorías.");
        setCats([]);
      } finally {
        setLoading(false);
      }
    }

    load();

    return () => {
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = prev;
    };
  }, [selectedRow, budgetId, cashierId, onClose]);

  const avgTicket = meta.tickets > 0 ? meta.totalUsd / meta.tickets : 0;
const sortedCats = useMemo(() => {

  const grouped = new Map<string, any>();

  cats.forEach((c) => {

    const raw = String(
      c.classification || c.category || "Sin categoría"
    ).trim();

    let groupKey = raw;

    // =========================
    // UNIFICAR FRAGANCIAS
    // =========================
    if (["10", "11", "12"].includes(raw)) {
      groupKey = "Fragancias";
    }

    const current = grouped.get(groupKey);

    if (current) {

      current.sales_usd += Number(c.sales_usd || 0);
      current.sales_cop += Number(c.sales_cop || 0);
      current.tickets += Number(c.tickets || 0);

    } else {

      grouped.set(groupKey, {
        classification: groupKey,
        sales_usd: Number(c.sales_usd || 0),
        sales_cop: Number(c.sales_cop || 0),
        tickets: Number(c.tickets || 0),
      });

    }
  });

  const totalUsd = Array.from(grouped.values()).reduce(
    (acc, item) => acc + Number(item.sales_usd || 0),
    0
  );

  return Array.from(grouped.values())
    .map((item) => ({
      ...item,
      pct_of_total:
        totalUsd > 0
          ? (Number(item.sales_usd || 0) / totalUsd) * 100
          : 0,
    }))
    .sort(
      (a, b) => Number(b.sales_usd || 0) - Number(a.sales_usd || 0)
    );

}, [cats]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/45" onClick={onClose} />

      <div className="relative w-full max-w-5xl rounded-3xl bg-white shadow-2xl overflow-hidden">
        <div className="bg-gradient-to-r from-[#5C0013] via-[#7A0019] to-[#990F2B] px-6 py-5 text-white">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h3 className="text-xl font-bold">{meta.cashierName}</h3>
              <p className="text-white/70 text-sm mt-1">
                Ventas por categoría del presupuesto seleccionado
              </p>
            </div>

            <div className="text-right">
              <div className="text-sm text-white/70">Ventas USD</div>
              <div className="text-lg font-bold">{moneyUSD(meta.totalUsd)}</div>
              <div className="text-sm text-white/70 mt-1">Tickets: {meta.tickets}</div>
            </div>
          </div>
        </div>

        <div className="p-6">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
            <StatCard
              label="Ventas USD"
              value={moneyUSD(meta.totalUsd)}
              sub="Total del cajero"
            />
            <StatCard
              label="Tickets"
              value={String(meta.tickets)}
              sub="Tickets detectados"
            />
            <StatCard
              label="Ticket promedio"
              value={moneyUSD(avgTicket)}
              sub="Ventas / tickets"
            />
          </div>

          {loading ? (
            <div className="py-16 text-center text-slate-500">Cargando categorías...</div>
          ) : error ? (
            <div className="py-10 text-center text-red-600">{error}</div>
          ) : sortedCats.length === 0 ? (
            <div className="py-10 text-center text-slate-500">
              No hay ventas por categoría.
            </div>
          ) : (
            <div className="overflow-x-auto rounded-2xl border border-slate-100">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-slate-600">
                  <tr>
                    <th className="p-3 text-left">Categoría</th>
                    <th className="p-3 text-right">Ventas USD</th>
                    <th className="p-3 text-right">Ventas COP</th>
                    <th className="p-3 text-right">% del total</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedCats.map((c: any, i: number) => {
                    const rawCategory = c.classification || c.category || "Sin categoría";
                    const label = getCategoryLabel(rawCategory);

                    return (
                      <tr key={i} className="border-t border-slate-100 hover:bg-slate-50">
                        <td className="p-3">
                          <div className="font-medium text-slate-900">{label}</div>
                          {rawCategory !== label && (
                            <div className="text-xs text-slate-500">
                              {rawCategory}
                            </div>
                          )}
                        </td>
                        <td className="p-3 text-right">{moneyUSD(c.sales_usd)}</td>
                        <td className="p-3 text-right">{moneyCOP(c.sales_cop)}</td>
                        <td className="p-3 text-right">
                          {(Number(c.pct_of_total || c.pct || 0)).toFixed(2)}%
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot className="bg-slate-50 font-semibold">
                  <tr>
                    <td className="p-3">Total</td>
                    <td className="p-3 text-right">{moneyUSD(meta.totalUsd)}</td>
                    <td className="p-3 text-right">{moneyCOP(meta.totalCop)}</td>
                    <td className="p-3 text-right">100%</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </div>

        <div className="p-5 border-t bg-slate-50 flex justify-end">
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-2xl bg-white border border-slate-200 hover:bg-slate-100 transition"
          >
            Cerrar
          </button>
        </div>
      </div>
    </div>
  );
}