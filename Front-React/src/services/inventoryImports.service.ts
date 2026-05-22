import axios from "axios";

import { API } from "../api/api";

export async function importInventoryFile(
  file: File,
  storeId: number,
  toDate?: string
) {
  const fd = new FormData();
  fd.append("file", file);
  fd.append("store_id", String(storeId));
  if (toDate) fd.append("to_date", toDate);

  const { data } = await axios.post(`${API}/inventory-imports/import`, fd, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

export async function getImports() {
  const { data } = await axios.get(`${API}/inventory-imports`);
  return data;
}

export async function getImport(id: number) {
  const { data } = await axios.get(`${API}/inventory-imports/${id}`);
  return data;
}

export async function deleteImport(id: number) {
  const { data } = await axios.delete(`${API}/inventory-imports/${id}`);
  return data;
}

export async function deleteImports(ids: number[]) {
  const { data } = await axios.post(`${API}/inventory-imports/bulk-delete`, { ids });
  return data;
}