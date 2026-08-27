// Em src/api/http.ts
import axios from 'axios';

// Este é um cliente HTTP genérico, sem prefixo de API.
// Perfeito para chamadas ao root do domínio, como o handshake do Sanctum.
const http = axios.create({
  baseURL: '/', // Aponta para o root do domínio
  withCredentials: true,
});

export default http;