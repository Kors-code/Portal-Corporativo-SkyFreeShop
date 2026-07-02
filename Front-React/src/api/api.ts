
const configuredApiUrl = import.meta.env.VITE_API_URL?.trim();

const API = (configuredApiUrl || `${window.location.origin}/api/v1`).replace(/\/$/, "");

export { API };
