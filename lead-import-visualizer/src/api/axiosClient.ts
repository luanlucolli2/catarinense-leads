// Em src/api/axiosClient.ts
import axios from 'axios';

// Cria uma instância do Axios com configurações padrão
const axiosClient = axios.create({
  // Remova a dependência da variável de ambiente.
  // A baseURL da API agora é uma regra fixa do nosso sistema.
  baseURL: '/api', // <-- Mude para esta string fixa
  withCredentials: true,
});

export default axiosClient;