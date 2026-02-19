import axios from "axios";
// const API = "http://127.0.0.1:8000/api/v1";
const API = "https://skyfreeshopdutyfree.com/api/v1";

export const importSalesFile = (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return axios.post(`${API}/import-sales`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
    });
};
