import axios, { type InternalAxiosRequestConfig } from "axios";

const rawBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim();
const apiBaseUrl = rawBaseUrl && rawBaseUrl.length > 0
  ? rawBaseUrl.replace(/\/+$/, "")
  : "/api";

const axiosClient = axios.create({
  baseURL: apiBaseUrl,
  withCredentials: true,
});

// Cliente sem baseURL para acessar rotas fora de /api (ex.: /sanctum/csrf-cookie).
const csrfClient = axios.create({
  withCredentials: true,
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
