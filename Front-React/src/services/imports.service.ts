import api from '../api/axios';

export type ImportBatch = {
  id: number;
  filename: string;
  checksum?: string;
  status?: string;
  rows?: number | null;
  created_at?: string | null;
  note?: string | null;
  path?: string | null;
};

export interface StoreOption {
  id: number;
  name: string;
  code: string;
  type?: string | null;
}

export const importSalesFile = (file: File, storeId: number) => {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('store_id', String(storeId));

  return api.post('import-sales', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const startSalesImport = (file: File, storeId: number) => {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('store_id', String(storeId));

  return api.post('import-sales/start', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const processSalesImportChunk = (payload: {
  path: string;
  batch_id: number;
  store_id: number;
  next_row: number;
  total_rows: number;
  chunk_size?: number;
}) => {
  return api.post('import-sales/chunk', payload);
};

export const getImports = async (params?: Record<string, any>) => {
  const res = await api.get('imports', { params });
  return res.data && res.data.data ? res.data : res.data;
};

export const getStores = async (): Promise<StoreOption[]> => {
  const response = await api.get('stores');
  return response.data;
};

export const getImport = async (id: number) => {
  const res = await api.get(`imports/${id}`);
  return res.data;
};

export const deleteImport = async (id: number) => {
  return api.delete(`imports/${id}`);
};

export const deleteImports = async (ids: number[]) => {
  return api.post('imports/bulk-delete', { ids });
};
