import axiosClient from "./axiosClient";

export type VendeaiAttemptStatus = "all" | "success" | "failed" | "pending";
export type VendeaiSortDirection = "asc" | "desc";
export type VendeaiExportStatus = "queued" | "running" | "ready" | "error" | "none" | "deleted";
export type VendeaiExportType = "leads" | "newcorban-proposal-attempts";

export interface VendeaiMetricBucket {
  label: string;
  total: number;
}

export interface VendeaiMetricsResponse {
  filters: {
    from: string | null;
    to: string | null;
  };
  leads: {
    total: number;
    by_product: VendeaiMetricBucket[];
  };
  attempts: {
    conversations_total: number;
    total: number;
    success: number;
    failed: number;
    pending: number;
    success_rate: number;
    by_product: VendeaiMetricBucket[];
  };
}

export interface VendeaiAttempt {
  id: number;
  vendeai_lead_id: number | null;
  received_at: string | null;
  newcorban_sent_at: string | null;
  status: Exclude<VendeaiAttemptStatus, "all">;
  newcorban_response_status: number | null;
  newcorban_proposta_id: string | null;
  newcorban_cliente_id: string | null;
  newcorban_error: string | null;
  lead: {
    account_id: string | null;
    chat_id: string | null;
    customer_cpf: string | null;
    customer_name: string | null;
    customer_birth_date: string | null;
    customer_phone: string | null;
    stage: string | null;
  };
  proposal: {
    proposal_id: string | null;
    bank: string | null;
    product: string | null;
    status: string | null;
    liquid_value: string | null;
  };
}

export interface VendeaiAttemptsResponse {
  data: VendeaiAttempt[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface VendeaiExportStatusDTO {
  status: VendeaiExportStatus;
  message?: string;
  error?: string | null;
  filename?: string | null;
  size_bytes?: number;
  updated_at?: string;
}

export interface VendeaiFilters {
  from?: string;
  to?: string;
  status?: VendeaiAttemptStatus;
  direction?: VendeaiSortDirection;
}

export interface ListVendeaiAttemptsParams extends VendeaiFilters {
  page?: number;
  perPage?: number;
  sort?: "received_at" | "newcorban_sent_at" | "id";
}

function buildParams(params: VendeaiFilters): Record<string, string | number> {
  const query: Record<string, string | number> = {};

  if (params.from) query.from = params.from;
  if (params.to) query.to = params.to;
  if (params.status && params.status !== "all") query.status = params.status;
  if (params.direction) query.direction = params.direction;

  return query;
}

export async function getVendeaiMetrics(params: VendeaiFilters, signal?: AbortSignal): Promise<VendeaiMetricsResponse> {
  const { data } = await axiosClient.get<VendeaiMetricsResponse>("/vendeai/metrics", {
    params: buildParams(params),
    signal,
  });

  return data;
}

export async function listVendeaiAttempts(
  params: ListVendeaiAttemptsParams,
  signal?: AbortSignal
): Promise<VendeaiAttemptsResponse> {
  const query = buildParams(params);

  if (typeof params.page === "number") query.page = params.page;
  if (typeof params.perPage === "number") query.per_page = params.perPage;
  if (params.sort) query.sort = params.sort;

  const { data } = await axiosClient.get<VendeaiAttemptsResponse>("/vendeai/newcorban-proposal-attempts", {
    params: query,
    signal,
  });

  return data;
}

export async function startVendeaiExport(type: VendeaiExportType, filters: VendeaiFilters): Promise<{ token: string }> {
  const { data } = await axiosClient.post<{ token: string; status: VendeaiExportStatus }>(
    `/vendeai/exports/${type}`,
    buildParams(filters)
  );

  return { token: data.token };
}

export async function getVendeaiExportStatus(token: string): Promise<VendeaiExportStatusDTO> {
  const { data } = await axiosClient.get<VendeaiExportStatusDTO>(`/vendeai/exports/${token}`);
  return data;
}

export async function downloadVendeaiExport(token: string): Promise<void> {
  const response = await axiosClient.get(`/vendeai/exports/${token}/download`, {
    responseType: "blob",
  });

  const contentType = (response.headers["content-type"] as string | undefined) || "text/csv;charset=utf-8";
  const blob = new Blob([response.data], { type: contentType });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  const disposition = response.headers["content-disposition"] as string | undefined;
  let filename = "vendeai_export.csv";

  if (disposition) {
    const star = disposition.match(/filename\*=([^;]+)/i);
    const simple = disposition.match(/filename="?([^"]+)"?/i);

    if (star?.[1]) {
      try {
        filename = decodeURIComponent(star[1].trim().split("''").pop() || filename);
      } catch {
        filename = "vendeai_export.csv";
      }
    } else if (simple?.[1]) {
      filename = simple[1];
    }
  }

  link.href = url;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
