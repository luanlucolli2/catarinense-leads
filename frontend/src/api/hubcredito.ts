import axiosClient, { DOWNLOAD_TIMEOUT_MS } from "./axiosClient";

export type HubCreditoJobStatus =
  | "agendado"
  | "pendente"
  | "em_progresso"
  | "pausado"
  | "concluido"
  | "falhou"
  | "cancelado";

export type HubCreditoJobStatusFilter = HubCreditoJobStatus | "todos";
export type HubCreditoJobPhase = "fase_1" | "fase_2" | null | string;

export interface HubCreditoConsultJobListItem {
  id: number;
  title: string;
  executor?: "local" | "api";
  status: HubCreditoJobStatus;
  phase?: HubCreditoJobPhase;
  total_cpfs: number;
  aprovado_count: number;
  nao_aprovado_count: number;
  fail_count: number;
  phase1_submitted_count?: number;
  phase1_not_approved_count?: number;
  phase1_fail_count?: number;
  phase2_approved_count?: number;
  phase2_not_approved_count?: number;
  phase2_fail_count?: number;
  has_file?: boolean | null;
  file_disk?: string | null;
  file_path?: string | null;
  file_name?: string | null;
  spool_bytes?: number | null;
  spool_path?: string | null;
  spool_inputs_path?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  canceled_at?: string | null;
  cancel_reason?: string | null;
  scheduled_for?: string | null;
  paused_at?: string | null;
  created_at: string;
}

export interface HubCreditoConsultJobShow {
  id: number;
  title: string;
  executor?: "local" | "api";
  status: HubCreditoJobStatus;
  phase?: HubCreditoJobPhase;
  total_cpfs: number;
  aprovado_count: number;
  nao_aprovado_count: number;
  fail_count: number;
  phase1_submitted_count?: number;
  phase1_not_approved_count?: number;
  phase1_fail_count?: number;
  phase2_approved_count?: number;
  phase2_not_approved_count?: number;
  phase2_fail_count?: number;
  has_file: boolean;
  preview_running?: boolean;
  spool_bytes?: number | null;
  spool_path?: string | null;
  spool_inputs_path?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  canceled_at?: string | null;
  cancel_reason?: string | null;
  scheduled_for?: string | null;
  paused_at?: string | null;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const BASE = "/hubcredito-clt/consult-jobs";
const HUBCREDITO_JOB_STATUSES: HubCreditoJobStatus[] = [
  "pendente",
  "em_progresso",
  "agendado",
  "pausado",
  "concluido",
  "falhou",
  "cancelado",
];

export async function listHubCreditoConsultJobs(
  page = 1,
  opts?: { status?: HubCreditoJobStatusFilter }
): Promise<Paginated<HubCreditoConsultJobListItem>> {
  const params: Record<string, string | number> = { page };
  const requestedStatus = opts?.status;
  if (
    typeof requestedStatus === "string" &&
    requestedStatus !== "todos" &&
    HUBCREDITO_JOB_STATUSES.includes(requestedStatus as HubCreditoJobStatus)
  ) {
    params.status = requestedStatus;
  }

  const { data } = await axiosClient.get<Paginated<HubCreditoConsultJobListItem>>(BASE, {
    params,
  });
  return data;
}

export async function createHubCreditoConsultJob(input: { title: string; lines: string; run_at?: string; timezone?: string }) {
  const { data } = await axiosClient.post<{ id: number; status: HubCreditoJobStatus; phase?: HubCreditoJobPhase }>(
    BASE,
    input
  );
  return data;
}

export async function getHubCreditoConsultJob(id: number): Promise<HubCreditoConsultJobShow> {
  const { data } = await axiosClient.get<HubCreditoConsultJobShow>(`${BASE}/${id}`);
  return data;
}

export async function downloadHubCreditoReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: "blob",
    timeout: DOWNLOAD_TIMEOUT_MS,
    params: { t: Date.now() },
  });

  const cd = resp.headers["content-disposition"] || "";
  const name = parseContentDispositionFilename(cd) || `hubcredito-consulta-${id}.csv`;

  const url = window.URL.createObjectURL(resp.data);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}

export async function downloadHubCreditoPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: "blob",
    timeout: DOWNLOAD_TIMEOUT_MS,
    params: { t: Date.now() },
    validateStatus: (s) => (s >= 200 && s < 300) || s === 409,
  });

  if (resp.status === 409) {
    const err = new Error("Prévia indisponível ainda (spool ausente).");
    // @ts-expect-error attach status
    err.status = 409;
    throw err;
  }

  const cd = resp.headers["content-disposition"] || "";
  const name = parseContentDispositionFilename(cd) || `hubcredito-consulta-${id}-preview.csv`;

  const url = window.URL.createObjectURL(resp.data);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}

export async function cancelHubCreditoConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number;
    status: HubCreditoJobStatus;
    phase?: HubCreditoJobPhase;
    canceled_at?: string | null;
    cancel_reason?: string | null;
    finished_at?: string | null;
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {});
  return data;
}

export async function pauseHubCreditoConsultJob(id: number) {
  const { data } = await axiosClient.post(`${BASE}/${id}/pause`);
  return data;
}

export async function resumeHubCreditoConsultJob(id: number) {
  const { data } = await axiosClient.post(`${BASE}/${id}/resume`);
  return data;
}

export async function deleteHubCreditoConsultJob(id: number): Promise<void> {
  await axiosClient.delete(`${BASE}/${id}`);
}

function parseContentDispositionFilename(contentDisposition: string): string | null {
  const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(contentDisposition);
  if (!match) return null;
  try {
    return decodeURIComponent(match[1].replace(/"/g, ""));
  } catch {
    return match[1].replace(/"/g, "");
  }
}
