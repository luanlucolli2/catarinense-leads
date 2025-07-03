// Em src/api/axiosClient.ts

import axios from 'axios';

// Cria uma instância do Axios com configurações padrão
const axiosClient = axios.create({
    baseURL: 'http://localhost/api', // A URL base da nossa API Laravel
    withCredentials: true, // Essencial para o Sanctum funcionar
});

// Interceptor de Requisição: Adiciona o token de autenticação a cada requisição
axiosClient.interceptors.request.use((config) => {
    const token = localStorage.getItem('AUTH_TOKEN'); // Vamos buscar o token do localStorage
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

export default axiosClient;