import axios from 'axios';
import { API } from '../api/api';
// en producción cambias al dominio real

export const importCatalogFile = (file: File) => {
  const fd = new FormData();
  fd.append('file', file);

  return axios.post(`${API}/catalog/import`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};
