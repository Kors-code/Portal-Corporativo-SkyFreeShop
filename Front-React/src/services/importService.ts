import axios from "axios";

import { API } from "../api/api";

export const importSalesFile = (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return axios.post(`${API}/import-sales`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
    });
};
