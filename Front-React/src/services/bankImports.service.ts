import api from "../api/axios";

export type BankImportBatch = {
  id: number;
  bank: string;
  source_type?: string;
  filename: string;
  checksum?: string | null;
  status?: string | null;
  rows?: number | null;
  rows_imported?: number | null;
  rows_skipped?: number | null;
  from_date?: string | null;
  to_date?: string | null;
  total_sale_amount?: number | string | null;
  total_commission_amount?: number | string | null;
  total_withholding_amount?: number | string | null;
  total_income_amount?: number | string | null;
  total_debit_amount?: number | string | null;
  total_credit_amount?: number | string | null;
  created_at?: string | null;
  note?: string | null;
  [key: string]: any;
};

export const getBankImports = async (params?: Record<string, any>) => {
  const res = await api.get("bank-imports", { params });
  return res.data;
};

export const getBankImport = async (id: number) => {
  const res = await api.get(`bank-imports/${id}`);
  return res.data;
};

export const deleteBankImport = async (id: number) => {
  return api.delete(`bank-imports/${id}`);
};

export const deleteBankImports = async (ids: number[]) => {
  return api.post("bank-imports/bulk-delete", { ids });
};
