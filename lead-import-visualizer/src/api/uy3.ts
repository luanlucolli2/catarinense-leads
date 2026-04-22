import axiosClient from "./axiosClient";

export interface Uy3PostItem {
  id: string;
  received_at: string | null;
  dados: unknown;
}

export interface Uy3PostsResponse {
  data: Uy3PostItem[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ListUy3PostsParams {
  page?: number;
  perPage?: number;
  period?: "all" | "24h" | "7d" | "30d" | "90d";
  from?: string;
  to?: string;
  sort?: "received_at" | "id";
  direction?: "asc" | "desc";
}

export type Uy3ExportStatus = "queued" | "running" | "ready" | "error" | "none" | "deleted";

export interface Uy3ExportStatusDTO {
  status: Uy3ExportStatus;
  message?: string;
  error?: string | null;
  filename?: string | null;
  size_bytes?: number;
  updated_at?: string;
}

export type Uy3ExportFilters = Omit<ListUy3PostsParams, "page" | "perPage">;

function buildUy3FiltersParams(params: Uy3ExportFilters): Record<string, string | number> {
  const query: Record<string, string | number> = {};

  if (params.period) query.period = params.period;
  if (params.from) query.from = params.from;
  if (params.to) query.to = params.to;
  if (params.sort) query.sort = params.sort;
  if (params.direction) query.direction = params.direction;

  return query;
}

export async function listUy3Posts(
  params: ListUy3PostsParams = {},
  signal?: AbortSignal
): Promise<Uy3PostsResponse> {
  const query = buildUy3FiltersParams(params);

  if (typeof params.page === "number" && Number.isFinite(params.page)) query.page = params.page;
  if (typeof params.perPage === "number" && Number.isFinite(params.perPage)) query.per_page = params.perPage;

  const { data } = await axiosClient.get<Uy3PostsResponse>("/uy3/posts", {
    params: query,
    signal,
  });

  return data;
}

export async function startUy3Export(filters: Uy3ExportFilters): Promise<{ token: string }> {
  const payload = buildUy3FiltersParams(filters);
  const { data } = await axiosClient.post<{ token: string; status: Uy3ExportStatus }>("/uy3/posts/export", payload);
  return { token: data.token };
}

export async function getUy3ExportStatus(token: string): Promise<Uy3ExportStatusDTO> {
  const { data } = await axiosClient.get<Uy3ExportStatusDTO>(`/uy3/posts/export/${token}`);
  return data;
}

export async function downloadUy3Export(token: string): Promise<void> {
  const response = await axiosClient.get(`/uy3/posts/export/${token}/download`, {
    responseType: "blob",
  });

  const contentType = (response.headers["content-type"] as string | undefined) || "text/csv;charset=utf-8";
  const blob = new Blob([response.data], { type: contentType });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");

  const disposition = response.headers["content-disposition"] as string | undefined;
  let filename = "uy3_leads_clt_export.csv";

  if (disposition) {
    const star = disposition.match(/filename\*\=([^;]+)/i);
    if (star?.[1]) {
      try {
        const value = star[1].trim();
        const parts = value.split("''");
        filename = decodeURIComponent(parts.pop() || filename);
      } catch {
      }
    } else {
      const simple = disposition.match(/filename="?([^"]+)"?/i);
      if (simple?.[1]) filename = simple[1];
    }
  }

  link.href = url;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
