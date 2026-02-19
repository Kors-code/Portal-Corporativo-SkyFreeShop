import axios from "axios";

const api = axios.create({
      // baseURL: "http://127.0.0.1:8000/api/v1",
       baseURL: "https://skyfreeshopdutyfree.com/api/v1",
  withCredentials: true, 
  headers: {
    "X-Requested-With": "XMLHttpRequest",
    "Content-Type": "application/json",
  },
});


export default api;
