import axiosClient from "./axiosClient";

export type VendeaiAttemptStatus = "all" | "success" | "failed" | "pending";
export type VendeaiSortDirection = "asc" | "desc";
export type VendeaiLeadSortField = "first_received_at" | "last_received_at" | "id";
export type VendeaiLeadPeriodBasis = "updated" | "started";
export type VendeaiProductFilter = "all" | "clt" | "fgts";
export type VendeaiNewcorbanStatusFilter = "all" | "not_sent" | "sent" | "success" | "failed";
export type VendeaiProductValue = Exclude<VendeaiProductFilter, "all">;
export type VendeaiNewcorbanStatusValue = Exclude<VendeaiNewcorbanStatusFilter, "all">;
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
    started_total: number;
    offered_total: number;
    typed_total: number;
    paid_total: number;
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

export interface VendeaiFilterOptionsResponse {
  banks: string[];
  stages: string[];
  proposal_statuses: string[];
  inbox_phone_numbers: string[];
  tags: string[];
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
    inbox_phone_number: string | null;
    stage: string | null;
    simulation_product: string | null;
    simulation_bank: string | null;
    simulation_liquid_value: string | null;
    simulation_number_of_payments: number | null;
    simulation_installment_value: string | null;
    simulation_monthly_fee: string | null;
    simulation_table_name: string | null;
    simulation_table_id: string | null;
    simulation_best_liquid_value: string | null;
    simulation_best_table_id: string | null;
    simulation_received_at: string | null;
    proposal_id: string | null;
    proposal_number: string | null;
    proposal_bank: string | null;
    proposal_product: string | null;
    proposal_status: string | null;
    previous_proposal_status: string | null;
    proposal_liquid_value: string | null;
    proposal_gross_value: string | null;
    proposal_number_of_payments: number | null;
    proposal_installment_value: string | null;
    proposal_table_name: string | null;
    proposal_table_id: string | null;
    proposal_formalization_link: string | null;
    proposal_created_at: string | null;
    proposal_status_updated_at: string | null;
  };
  proposal: {
    proposal_id: string | null;
    proposal_number: string | null;
    bank: string | null;
    product: string | null;
    status: string | null;
    previous_status: string | null;
    liquid_value: string | null;
    gross_value: string | null;
    number_of_payments: number | null;
    installment_value: string | null;
    table_name: string | null;
    table_id: string | null;
    formalization_link: string | null;
    created_at: string | null;
    status_updated_at: string | null;
  };
}

export interface VendeaiLead {
  id: number;
  account_id: string | null;
  chat_id: string | null;
  product_key: string | null;
  first_received_at: string | null;
  last_received_at: string | null;
  last_event: string | null;
  chat_product: string | null;
  stage: string | null;
  tags: string[] | null;
  campaign: string | null;
  customer_cpf: string | null;
  customer_name: string | null;
  customer_birth_date: string | null;
  customer_phone: string | null;
  inbox_phone_number: string | null;
  simulation_product: string | null;
  simulation_bank: string | null;
  simulation_liquid_value: string | null;
  simulation_number_of_payments: number | null;
  simulation_installment_value: string | null;
  simulation_monthly_fee: string | null;
  simulation_table_name: string | null;
  simulation_table_id: string | null;
  simulation_best_liquid_value: string | null;
  simulation_best_table_id: string | null;
  simulation_received_at: string | null;
  proposal_id: string | null;
  proposal_number: string | null;
  proposal_bank: string | null;
  proposal_product: string | null;
  proposal_status: string | null;
  previous_proposal_status: string | null;
  proposal_liquid_value: string | null;
  proposal_gross_value: string | null;
  proposal_number_of_payments: number | null;
  proposal_installment_value: string | null;
  proposal_table_name: string | null;
  proposal_table_id: string | null;
  proposal_formalization_link: string | null;
  proposal_created_at: string | null;
  proposal_status_updated_at: string | null;
  newcorban_proposta_id: string | null;
  newcorban_error: string | null;
  newcorban_sent_at: string | null;
  newcorban_attempts_out_of_period_count: number;
  newcorban_attempts_out_of_period_received_at: string | null;
  newcorban_attempts: VendeaiLeadAttempt[];
}

export interface VendeaiLeadAttempt {
  id: number;
  original_number: number | null;
  received_at: string | null;
  newcorban_sent_at: string | null;
  newcorban_response_status: number | null;
  newcorban_proposta_id: string | null;
  newcorban_cliente_id: string | null;
  newcorban_error: string | null;
  status: "success" | "failed" | "pending";
  is_in_filtered_period: boolean;
  matches_newcorban_scope: boolean;
  proposal: {
    proposal_id: string | null;
    proposal_number: string | null;
    proposal_bank: string | null;
    proposal_product: string | null;
    proposal_status: string | null;
    previous_proposal_status: string | null;
    proposal_liquid_value: string | null;
    proposal_gross_value: string | null;
    proposal_number_of_payments: number | null;
    proposal_installment_value: string | null;
    proposal_table_name: string | null;
    proposal_table_id: string | null;
    proposal_formalization_link: string | null;
    proposal_created_at: string | null;
    proposal_status_updated_at: string | null;
  };
}

export interface VendeaiAttemptsResponse {
  data: VendeaiAttempt[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface VendeaiLeadsResponse {
  data: VendeaiLead[];
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
  leadPeriodBasis?: VendeaiLeadPeriodBasis;
  status?: VendeaiAttemptStatus;
  direction?: VendeaiSortDirection;
  product?: VendeaiProductValue[];
  search?: string;
  bank?: string[];
  stage?: string[];
  proposalStatus?: string[];
  newcorbanStatus?: VendeaiNewcorbanStatusValue[];
  inboxPhoneNumber?: string[];
  tags?: string[];
}

export interface ListVendeaiAttemptsParams extends VendeaiFilters {
  page?: number;
  perPage?: number;
  sort?: "received_at" | "newcorban_sent_at" | "id";
}

export interface ListVendeaiLeadsParams extends VendeaiFilters {
  page?: number;
  perPage?: number;
  sort?: VendeaiLeadSortField;
}

function buildParams(params: VendeaiFilters): Record<string, string | number | string[]> {
  const query: Record<string, string | number | string[]> = {};

  if (params.from) query.from = params.from;
  if (params.to) query.to = params.to;
  if (params.leadPeriodBasis) query.lead_period_basis = params.leadPeriodBasis;
  if (params.status && params.status !== "all") query.status = params.status;
  if (params.direction) query.direction = params.direction;
  if (params.product?.length) query.product = params.product;
  if (params.search?.trim()) query.search = params.search.trim();
  if (params.bank?.length) query.bank = params.bank;
  if (params.stage?.length) query.stage = params.stage;
  if (params.proposalStatus?.length) query.proposal_status = params.proposalStatus;
  if (params.newcorbanStatus?.length) query.newcorban_status = params.newcorbanStatus;
  if (params.inboxPhoneNumber?.length) query.inbox_phone_number = params.inboxPhoneNumber;
  if (params.tags?.length) query.tags = params.tags;

  return query;
}

export async function getVendeaiMetrics(params: VendeaiFilters, signal?: AbortSignal): Promise<VendeaiMetricsResponse> {
  const { data } = await axiosClient.get<VendeaiMetricsResponse>("/vendeai/metrics", {
    params: buildParams(params),
    signal,
  });

  return data;
}

export async function getVendeaiFilterOptions(
  params: VendeaiFilters,
  signal?: AbortSignal
): Promise<VendeaiFilterOptionsResponse> {
  const { data } = await axiosClient.get<VendeaiFilterOptionsResponse>("/vendeai/filter-options", {
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

export async function listVendeaiLeads(params: ListVendeaiLeadsParams, signal?: AbortSignal): Promise<VendeaiLeadsResponse> {
  const query = buildParams(params);

  if (typeof params.page === "number") query.page = params.page;
  if (typeof params.perPage === "number") query.per_page = params.perPage;
  if (params.sort) query.sort = params.sort;

  const { data } = await axiosClient.get<VendeaiLeadsResponse>("/vendeai/leads", {
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
