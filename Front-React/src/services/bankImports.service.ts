import api from "../api/axios";

export type BankImportBatch = {
  id: number;
  bank_id?: number | null;
  file_format_id?: number | null;
  bank_account_id?: number | null;
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

export type BankMovementFilters = {
  bank?: string;
  batch_id?: number | string;
  movement_date_from?: string;
  movement_date_to?: string;
  movement_month?: string;
  deposit_date_from?: string;
  deposit_date_to?: string;
  search?: string;
  per_page?: number;
  page?: number;
};

export const getBankImports = async (params?: Record<string, any>) => {
  const res = await api.get("bank-imports", { params });
  return res.data;
};

export const getBankMovementsAudit = async () => {
  const res = await api.get("bank-imports/movements/audit");
  return res.data;
};

export const getBankMovements = async (params?: BankMovementFilters) => {
  const res = await api.get("bank-imports/movements", { params });
  return res.data;
};

export const exportBankMovements = async (params?: BankMovementFilters) => {
  return api.get("bank-imports/movements/export", {
    params,
    responseType: "blob",
  });
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

export const importBankFile = async (payload: { bank: string; file: File; receiptStart: number }) => {
  const formData = new FormData();
  formData.append("bank", payload.bank);
  formData.append("file", payload.file);
  formData.append("receipt_start", String(payload.receiptStart));

  return api.post("bank-imports/import", formData, {
    responseType: "blob",
    headers: {
      "Content-Type": "multipart/form-data",
    },
  });
};

export const exportBankImport = async (id: number, receiptStart?: number) => {
  return api.get(`bank-imports/${id}/export`, {
    params: receiptStart ? { receipt_start: receiptStart } : undefined,
    responseType: "blob",
  });
};

export const exportDavibankImport = async (id: number, receiptStart?: number) => {
  return api.get(`bank-imports/${id}/export-davibank`, {
    params: receiptStart ? { receipt_start: receiptStart } : undefined,
    responseType: "blob",
  });
};
