import axiosClient from "@/api/axiosClient";
import type { AxiosError } from "axios";

export type ShortLinkMode = "single" | "rotating";
export type ShortLinkKind = "url" | "whatsapp";
export type ShortLinkStrategy = "sequential" | "random" | "weighted" | "first";
export type ShortLinkStatus = "active" | "inactive" | "deleted";
export type AnalyticsPeriod = "24h" | "7d" | "30d" | "90d" | "365d";

export interface ShortLinkDestinationInput {
  kind: ShortLinkKind;
  url?: string;
  phone?: string;
  message?: string;
  position: number;
  weight?: number;
}

export interface ShortLinkDestination {
  id: string;
  kind: ShortLinkKind;
  url: string;
  normalized_phone?: string;
  whatsapp_message?: string;
  position: number;
  weight: number;
}

export interface ShortLink {
  id: string;
  slug: string;
  short_url: string;
  label: string;
  status: ShortLinkStatus;
  mode: ShortLinkMode;
  strategy: ShortLinkStrategy | null;
  destinations?: ShortLinkDestination[];
  destination_count: number;
  destination_summary: string;
  real_clicks: number;
  created_at: string;
  updated_at: string;
}

export interface ShortLinkListResponse {
  items: ShortLink[];
  pagination: { page: number; per_page: number; total: number; total_pages: number };
}

export interface ShortLinkAnalytics {
  period: AnalyticsPeriod;
  summary: { real_clicks: number; ignored_clicks: number; total_clicks: number; unique_ips: number; countries: number; devices: number };
  by_day: Array<{ key: string; real: number; ignored: number }>;
  by_hour: Array<{ key: string; real: number; ignored: number }>;
  dimensions: Record<string, Array<{ label: string; count: number; percentage: number }>>;
}

export interface ShortLinkClickListResponse {
  items: Array<{ id: string; occurred_at: string; ip_masked: string; country: string; city: string; region: string; device: string; browser: string; operating_system: string; referrer: string; destination: string; event_type: "real" | "ignored"; bot_reason?: string }>;
  pagination: { page: number; per_page: number; total: number; total_pages: number };
}

export type ShortLinkInput = { label: string; mode: ShortLinkMode; strategy: ShortLinkStrategy | null; destinations: ShortLinkDestinationInput[] };
export type ShortLinkUpdate = Partial<ShortLinkInput> & { retain_destination_id?: string };

const query = (filters: Record<string, string | number | undefined>) => {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== "") params.set(key, String(value));
  });
  return params.toString();
};

export const shortLinksApi = {
  list: async (filters: Record<string, string | number | undefined>) => (await axiosClient.get<ShortLinkListResponse>(`/links?${query(filters)}`)).data,
  get: async (id: string) => (await axiosClient.get<ShortLink>(`/links/${id}`)).data,
  create: async (input: ShortLinkInput) => (await axiosClient.post<ShortLink>("/links", input)).data,
  update: async (id: string, input: ShortLinkUpdate) => (await axiosClient.patch<ShortLink>(`/links/${id}`, input)).data,
  remove: (id: string) => axiosClient.delete(`/links/${id}`),
  disable: (id: string) => axiosClient.post(`/links/${id}/disable`),
  enable: (id: string) => axiosClient.post(`/links/${id}/enable`),
  analytics: async (id: string, period: AnalyticsPeriod) => (await axiosClient.get<ShortLinkAnalytics>(`/links/${id}/analytics?period=${period}`)).data,
  clicks: async (id: string, filters: Record<string, string | number | undefined>) => (await axiosClient.get<ShortLinkClickListResponse>(`/links/${id}/clicks?${query(filters)}`)).data,
  exportUrl: (id: string, filters: Record<string, string | number | undefined>) => `${axiosClient.defaults.baseURL ?? "/api"}/links/${id}/export.csv?${query(filters)}`,
};

export const apiErrorMessage = (error: unknown) => {
  const payload = (error as AxiosError<{ message?: string }>).response?.data;
  return payload?.message ?? "Não foi possível concluir a operação.";
};
