// src/api/clt.ts
import axiosClient from './axiosClient'
export { ensureCsrfCookie } from './axiosClient'

/** Estados do job no backend (sem pausa e sem agendamento) */
export type CltJobStatus =
  | 'pendente'
  | 'em_progresso'
  | 'concluido'
  | 'falhou'
  | 'cancelado'

export type CltJobStatusFilter = CltJobStatus | 'todos'
export type CltJobVariantFilter = 'online' | 'offline' | 'hybrid' | 'todos'

export type CltJobPhase = 'fase_1' | 'fase_2' | null

/** Estados da PRÉVIA (alinhado ao backend) */
export type PreviewStatus = 'none' | 'queued' | 'running' | 'ready' | 'error'

/** DTO básico (index) */
export interface CltConsultJobListItem {
  id: number
  title: string
  status: CltJobStatus
  phase?: CltJobPhase
  phase2_total?: number
  phase2_attempt?: number
  phase2_aprovado_count?: number
  phase2_nao_aprovado_count?: number
  total_cpfs: number
  elegivel_count: number
  inelegivel_count: number
  fail_count: number
  not_found_count: number

  /** Final */
  has_file?: boolean | null
  file_disk?: string | null
  file_path?: string | null
  file_name?: string | null

  /** PRÉVIA (opcional) */
  preview_disk?: string | null
  preview_path?: string | null
  preview_name?: string | null
  preview_updated_at?: string | null

  /** telemetria opcional */
  spool_bytes?: number | null

  /** modo/variante */
  variant?: 'online' | 'offline' | 'hybrid' | null

  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  cancel_reason?: string | null
  created_at: string
}

/** DTO de show() — espelha FGTS OFF, com campos de prévia completos */
export interface CltConsultJobShow {
  id: number
  title: string
  status: CltJobStatus
  phase?: CltJobPhase
  phase2_total?: number
  phase2_attempt?: number
  phase2_aprovado_count?: number
  phase2_nao_aprovado_count?: number
  total_cpfs: number
  elegivel_count: number
  inelegivel_count: number
  fail_count: number
  not_found_count: number
  has_file: boolean

  /** PRÉVIA */
  has_preview?: boolean
  preview_status?: PreviewStatus
  preview_updated_at?: string | null
  preview_requested_at?: string | null
  preview_started_at?: string | null
  preview_finished_at?: string | null
  preview_size_bytes?: number | null
  preview_rows?: number | null
  preview_error?: string | null

  /** telemetria */
  spool_bytes?: number | null

  /** modo/variante (opcional no show) */
  variant?: 'online' | 'offline' | 'hybrid' | null

  /** datas */
  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  cancel_reason?: string | null
  created_at: string
}

export interface CltJobHttpCounterRow {
  endpoint: string
  request_count: number
  response_count: number
  status_2xx_count: number
  status_4xx_count: number
  status_5xx_count: number
  status_other_count: number
  exception_count: number
  timeout_count: number
  connection_exception_count: number
  no_response_count: number
}

export interface CltJobHttpCountersResponse {
  id: number
  title: string
  variant: 'online' | 'offline' | 'hybrid' | null
  status: CltJobStatus
  available: boolean
  summary: Omit<CltJobHttpCounterRow, 'endpoint'>
  checks: {
    request_balance_ok: boolean
    status_balance_ok: boolean
  }
  endpoints: CltJobHttpCounterRow[]
  updated_at?: string | null
}

/** Payload de criação alinhado ao backend */
export interface CreateCltConsultInput {
  title: string
  cpfs: string | string[]
  /** 'online' | 'offline' | 'hybrid' */
  variant?: 'online' | 'offline' | 'hybrid'
}

/** Resposta de paginação Laravel */
export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const BASE = '/clt/consult-jobs'
const CLT_JOB_STATUSES: CltJobStatus[] = ['pendente', 'em_progresso', 'concluido', 'falhou', 'cancelado']
const CLT_JOB_VARIANTS: Array<'online' | 'offline' | 'hybrid'> = ['online', 'offline', 'hybrid']

/** Lista os jobs CLT com filtros opcionais */
export async function listCltConsultJobs(
  page = 1,
  opts?: { status?: CltJobStatusFilter; variant?: CltJobVariantFilter }
): Promise<Paginated<CltConsultJobListItem>> {
  const params: Record<string, string | number> = { page }
  const requestedStatus = opts?.status
  const requestedVariant = opts?.variant
  if (
    typeof requestedStatus === 'string'
    && requestedStatus !== 'todos'
    && CLT_JOB_STATUSES.includes(requestedStatus as CltJobStatus)
  ) {
    params.status = requestedStatus
  }
  if (
    typeof requestedVariant === 'string'
    && requestedVariant !== 'todos'
    && CLT_JOB_VARIANTS.includes(requestedVariant as 'online' | 'offline' | 'hybrid')
  ) {
    params.variant = requestedVariant
  }

  const { data } = await axiosClient.get<Paginated<CltConsultJobListItem>>(
    BASE,
    { params }
  )
  return data
}

/** Cria um novo job (cpfs: string colada do textarea ou array de strings) */
export async function createCltConsultJob(input: CreateCltConsultInput) {
  const { data } = await axiosClient.post<{ id: number; status: CltJobStatus; phase?: CltJobPhase }>(
    BASE,
    input
  )
  return data
}

/** Busca um job específico (para checar status) */
export async function getCltConsultJob(id: number): Promise<CltConsultJobShow> {
  const { data } = await axiosClient.get<CltConsultJobShow>(`${BASE}/${id}`)
  return data
}

export async function getCltJobHttpCounters(id: number): Promise<CltJobHttpCountersResponse> {
  const { data } = await axiosClient.get<CltJobHttpCountersResponse>(`${BASE}/${id}/http-counters`)
  return data
}

/** Solicita geração da PRÉVIA (200=já pronta, 202=aceita/andando, 409=indisponível) */
export async function requestCltPreview(id: number): Promise<200 | 202 | 409> {
  const resp = await axiosClient.post(
    `${BASE}/${id}/preview/generate`,
    null,
    {
      validateStatus: (s) => (s >= 200 && s < 300) || s === 409
    }
  )
  return resp.status as 200 | 202 | 409
}

/** Faz o download do relatório FINAL (stream) — aplica cache-busting defensivo */
export async function downloadCltReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
    params: { t: Date.now() },
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `clt-consulta-${id}.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

/** Faz o download da PRÉVIA já pronta (NÃO força regeneração) */
export async function downloadCltPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
    params: { t: Date.now() },
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `clt-consulta-${id}-preview.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

/** Cancela um job (opcionalmente com motivo) */
export async function cancelCltConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number
    status: CltJobStatus
    phase?: CltJobPhase
    canceled_at?: string | null
    cancel_reason?: string | null
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {})
  return data
}

/** Reprocessa a fase 2 de um job online/hibrido concluído */
export async function rerunCltConsultJobPhase2(id: number) {
  const { data } = await axiosClient.post<{
    id: number
    status: CltJobStatus
    phase?: CltJobPhase
    phase2_total?: number
    phase2_attempt?: number
    phase2_aprovado_count?: number
    phase2_nao_aprovado_count?: number
  }>(`${BASE}/${id}/phase2/rerun`)
  return data
}

/** Exclui definitivamente um job e seus arquivos (204 No Content) */
export async function deleteCltConsultJob(id: number): Promise<void> {
  await axiosClient.delete(`${BASE}/${id}`)
}

function parseContentDispositionFilename(contentDisposition: string): string | null {
  const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(contentDisposition)
  if (!match) return null
  try {
    return decodeURIComponent(match[1].replace(/"/g, ''))
  } catch {
    return match[1].replace(/"/g, '')
  }
}
