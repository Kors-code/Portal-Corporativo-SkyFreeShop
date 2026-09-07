import api from "../../../api/axios";

export type CommissionProfileRule = {
  id?: number;
  rule_type: "provider" | "category" | "provider_category";
  provider_name?: string | null;
  category_id?: number | null;
  category_code?: string | null;
  commission_percentage: number;
  commission_percentage100?: number;
  commission_percentage120?: number;
};

export type CommissionProfileUser = {
  assignment_id?: number;
  user_id: number;
  name?: string | null;
  email?: string | null;
  codigo_vendedor?: string | null;
};

export type CommissionProfile = {
  id: number;
  budget_id?: number | null;
  name: string;
  profile_type: string;
  commission_mode: "fixed";
  category_role_id?: number | null;
  commission_percentage: number;
  commission_percentage100?: number;
  commission_percentage120?: number;
  minimum_fulfillment_pct?: number | null;
  target_amount_usd?: number | null;
  is_active: boolean;
  valid_from?: string | null;
  valid_to?: string | null;
  note?: string | null;
  rules: CommissionProfileRule[];
  users: CommissionProfileUser[];
};

export type CommissionProfilePayload = Omit<CommissionProfile, "id" | "users"> & {
  user_ids: number[];
};

export type CommissionProfileOptions = {
  providers: string[];
  categories: Array<{ id: number; code: string; name: string }>;
  users: Array<{ id: number; name: string; email?: string | null; codigo_vendedor?: string | null }>;
  roles: Array<{ id: number; name: string }>;
  profile_types: Array<{ value: string; label: string }>;
  rule_types: Array<{ value: CommissionProfileRule["rule_type"]; label: string }>;
};

export type CommissionProfileSummary = {
  rows: Array<{
    user_id: number;
    user_name?: string | null;
    seller_code?: string | null;
    sales_count: number;
    units: number;
    sales_usd: number;
    sales_cop: number;
    fulfillment_pct?: number | null;
    eligible: boolean;
    applied_commission_pct: number;
    commission_usd: number;
    rules?: Array<{
      rule_id?: number;
      rule_type: CommissionProfileRule["rule_type"];
      provider_name?: string | null;
      category_code?: string | null;
      sales_count: number;
      sales_usd: number;
      fulfillment_pct?: number | null;
      applied_commission_pct: number;
      commission_usd: number;
    }>;
  }>;
  totals: {
    sales_usd: number;
    sales_cop: number;
    commission_usd: number;
    sales_count: number;
  };
};

export async function getCommissionProfiles(params?: { budget_id?: number | null; search?: string }) {
  const { data } = await api.get("/commission-profiles", { params });
  return data.profiles as CommissionProfile[];
}

export async function getCommissionProfileOptions() {
  const { data } = await api.get("/commission-profiles/options");
  return data as CommissionProfileOptions;
}

export async function createCommissionProfile(payload: CommissionProfilePayload) {
  const { data } = await api.post("/commission-profiles", payload);
  return data.profile as CommissionProfile;
}

export async function updateCommissionProfile(id: number, payload: CommissionProfilePayload) {
  const { data } = await api.put(`/commission-profiles/${id}`, payload);
  return data.profile as CommissionProfile;
}

export async function deleteCommissionProfile(id: number) {
  const { data } = await api.delete(`/commission-profiles/${id}`);
  return data;
}

export async function getCommissionProfileSummary(id: number, budgetId?: number | null) {
  const { data } = await api.get(`/commission-profiles/${id}/summary`, {
    params: { budget_id: budgetId || undefined },
  });
  return data as CommissionProfileSummary;
}

export async function getCommissionProfileEarners(params?: { budget_id?: number | null; profile_id?: number | null }) {
  const { data } = await api.get("/commission-profiles/earners", { params });
  return data as CommissionProfileSummary;
}

export function commissionProfileEarnersExportUrl(params?: { budget_id?: number | null; profile_id?: number | null }) {
  const query = new URLSearchParams();
  if (params?.budget_id) query.set("budget_id", String(params.budget_id));
  if (params?.profile_id) query.set("profile_id", String(params.profile_id));
  const suffix = query.toString();
  return `/api/v1/commission-profiles/earners/export${suffix ? `?${suffix}` : ""}`;
}
