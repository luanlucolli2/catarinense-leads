import axios, { type InternalAxiosRequestConfig } from "axios";

function resolveTimeout(raw: unknown, fallback: number): number {
  const parsed = Number(raw);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

const API_TIMEOUT_MS = resolveTimeout(import.meta.env.VITE_API_TIMEOUT_MS, 15000);
const CSRF_TIMEOUT_MS = resolveTimeout(import.meta.env.VITE_CSRF_TIMEOUT_MS, 10000);

const axiosClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  timeout: API_TIMEOUT_MS,
});

// Cliente sem baseURL para acessar rotas fora de /api (ex.: /sanctum/csrf-cookie).
const csrfClient = axios.create({
  withCredentials: true,
  timeout: CSRF_TIMEOUT_MS,
});

let csrfBootstrapped = false;
let csrfBootstrapPromise: Promise<void> | null = null;

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (csrfBootstrapped && !force) return;

  if (!csrfBootstrapPromise || force) {
    csrfBootstrapPromise = csrfClient
      .get("/sanctum/csrf-cookie")
      .then(() => {
        csrfBootstrapped = true;
      })
      .finally(() => {
        csrfBootstrapPromise = null;
      });
  }

  await csrfBootstrapPromise;
}

function shouldBootstrapCsrf(config: InternalAxiosRequestConfig): boolean {
  const method = (config.method ?? "get").toLowerCase();
  return ["post", "put", "patch", "delete"].includes(method);
}

axiosClient.interceptors.request.use(async (config) => {
  if (shouldBootstrapCsrf(config)) {
    await ensureCsrfCookie();
  }

  return config;
});

axiosClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error?.response?.status;
    const config = error?.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined;

    // Revalida CSRF uma vez e repete a requisição em caso de token expirado/inválido.
    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true;
      await ensureCsrfCookie(true);
      return axiosClient.request(config);
    }

    return Promise.reject(error);
  }
);

export default axiosClient;
