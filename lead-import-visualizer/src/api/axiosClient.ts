// Em src/api/axiosClient.ts

import axios from 'axios';

// Cria uma instância do Axios com configurações padrão
const axiosClient = axios.create({
// Lê a URL base da nossa variável de ambiente
baseURL: import.meta.env.VITE_API_BASE_URL,
withCredentials: true,
});

// O resto do arquivo permanece o mesmo...
axiosClient.interceptors.request.use((config) => {
const token = localStorage.getItem('AUTH\_TOKEN');
if (token) {
config.headers.Authorization = `Bearer ${token}`;
}
return config;
});

export default axiosClient;