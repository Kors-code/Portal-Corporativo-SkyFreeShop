import axios from "axios";

import { API } from "../api/api";


export const getUsers = () =>
    axios.get(`${API}/users`);

export const assignRole = (userId: number, roleId: number) =>
    axios.post(`${API}/users/${userId}/assign-role`, {
        role_id: roleId
    });

export const getRoles = () =>
    axios.get(`${API}/roles`);
