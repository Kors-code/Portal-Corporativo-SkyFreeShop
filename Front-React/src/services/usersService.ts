import axios from "axios";

// const API = "http://127.0.0.1:8000/api/v1";
const API = "https://skyfreeshopdutyfree.com/api/v1";

export const getUsers = () =>
    axios.get(`${API}/users`);

export const assignRole = (userId: number, roleId: number) =>
    axios.post(`${API}/users/${userId}/assign-role`, {
        role_id: roleId
    });

export const getRoles = () =>
    axios.get(`${API}/roles`);
