import axios from "axios";
import { API } from "./api";

const api = axios.create({
        baseURL: API,
  withCredentials: true, 
  headers: {
    "X-Requested-With": "XMLHttpRequest",
    "Content-Type": "application/json",
  },
});


export default api;
