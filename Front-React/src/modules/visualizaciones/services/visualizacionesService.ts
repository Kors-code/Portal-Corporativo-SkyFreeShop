import api from "../../../api/axios";

export type CashClosureSummary = {
  total_cop: number;
  total_usd: number;
  tickets: number;
  rows_count: number;
  units: number;
  cashiers_count: number;
  avg_exchange_rate: number;
  avg_ticket_usd: number;
};

export type CashClosureResponse = {
  date: string;
  pdvs: string[];
  budgets: Array<{
    id: number;
    name: string;
    target_amount: number;
    start_date: string;
    end_date: string;
  }>;
  filters: {
    pdvs: string[];
    budget_id: number | null;
  };
  available_period: {
    start: string;
    end: string;
  };
  budget: {
    id: number | null;
    name: string | null;
    monthly_usd: number;
    days_in_month: number;
    days_in_range: number;
    budget_daily_usd: number;
    month_sales_usd: number;
    month_diff_usd: number;
    month_compliance_pct: number;
    range_budget_usd: number;
    period: {
      start: string;
      end: string;
    };
    range: {
      start: string;
      end: string;
    };
  };
  summary: CashClosureSummary;
  hourly: Array<{
    hour: string;
    sales_cop: number;
    sales_usd: number;
    tickets: number;
  }>;
  by_pdv: Array<{
    pdv: string;
    sales_cop: number;
    sales_usd: number;
    tickets: number;
    pct: number;
  }>;
  cashiers: Array<{
    cashier: string;
    sales_cop: number;
    sales_usd: number;
    tickets: number;
    avg_ticket_usd: number;
  }>;
  categories: Array<{
    category: string;
    sales_usd: number;
    pct: number;
  }>;
  daily_performance: Array<{
    date: string;
    year: number;
    month: string;
    day: number;
    weekday: string;
    sales_usd: number;
    sales_cop: number;
    budget_daily_usd: number;
    diff_usd: number;
    compliance_pct: number;
    units: number;
    trx: number;
    tkt_usd: number;
    avg_exchange_rate: number;
    is_selected: boolean;
  }>;
  transactions: Array<{
    id: number;
    time: string | null;
    folio: string | null;
    pdv: string | null;
    cashier: string;
    product: string;
    quantity: number;
    amount_cop: number;
    value_usd: number;
  }>;
};

export type StoreSalesRow = {
  code: string;
  label: string;
  total_usd: number;
  trx: number;
  tkt_usd: number;
  units: number;
  units_per_ticket: number;
};

export type StoreSalesResponse = {
  date: string;
  stores: StoreSalesRow[];
  totals: Omit<StoreSalesRow, "code">;
  meta_usd: number;
  compliance_pct: number;
};

export type AdvisorSalesRow = {
  user_id: number;
  advisor: string;
  seller_code: string | null;
  total_usd: number;
  trx: number;
  tkt_usd: number;
  units: number;
  units_per_ticket: number;
};

export type AdvisorSalesResponse = {
  date: string;
  advisors: AdvisorSalesRow[];
  totals: {
    label: string;
    total_usd: number;
    trx: number;
    tkt_usd: number;
    units: number;
    units_per_ticket: number;
    advisors_count: number;
  };
};

export async function getCashRegisterClosure(params: {
  date?: string;
  start_date?: string;
  end_date?: string;
  budget_id?: number | "";
  pdvs?: string[];
}): Promise<CashClosureResponse> {
  const response = await api.get<CashClosureResponse>(
    "/visualizaciones/cierre-caja",
    { params }
  );

  return response.data;
}

export async function sendDailyWhatsappReport(params: {
  date?: string;
  pdvs?: string[];
}): Promise<{ ok: boolean; message: string }> {
  const response = await api.post<{ ok: boolean; message: string }>(
    "/visualizaciones/daily-whatsapp/queue",
    {},
    { params }
  );

  return response.data;
}

export async function getStoreSalesSummary(params: {
  date?: string;
}): Promise<StoreSalesResponse> {
  const response = await api.get<StoreSalesResponse>(
    "/visualizaciones/ventas-tiendas",
    { params }
  );

  return response.data;
}

export async function sendStoreSalesWhatsappReport(params: {
  date?: string;
}): Promise<{ ok: boolean; message: string }> {
  const response = await api.post<{ ok: boolean; message: string }>(
    "/visualizaciones/ventas-tiendas/whatsapp/queue",
    {},
    { params }
  );

  return response.data;
}

export async function getAdvisorSalesSummary(params: {
  date?: string;
}): Promise<AdvisorSalesResponse> {
  const response = await api.get<AdvisorSalesResponse>(
    "/visualizaciones/ventas-asesores",
    { params }
  );

  return response.data;
}

export async function sendAdvisorSalesWhatsappReport(params: {
  date?: string;
}): Promise<{ ok: boolean; message: string }> {
  const response = await api.post<{ ok: boolean; message: string }>(
    "/visualizaciones/ventas-asesores/whatsapp/queue",
    {},
    { params }
  );

  return response.data;
}
