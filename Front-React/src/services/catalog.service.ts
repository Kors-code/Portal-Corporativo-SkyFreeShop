import axios from 'axios';

// const API = 'http://localhost:8000/api/v1';
const API = 'https://skyfreeshopdutyfree.com/api/v1';

// en producción cambias al dominio real

export const importCatalogFile = (file: File) => {
  const fd = new FormData();
  fd.append('file', file);

  return axios.post(`${API}/catalog/import`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};
