import axiosClient, { DOWNLOAD_TIMEOUT_MS } from './axiosClient'
export { ensureCsrfCookie } from './axiosClient'

export type PresencaJobStatus =
  | 'agendado'
  | 'pendente'
  | 'em_progresso'
  | 'pausado'
  | 'concluido'
  | 'falhou'
  | 'cancelado'

export type PresencaJobStatusFilter = PresencaJobStatus | 'todos'

export type PresencaJobPhase = string | null

export interface PresencaConsultJobListItem {
  id: number
  title: string
  executor?: 'local' | 'api'
  status: PresencaJobStatus
  phase?: PresencaJobPhase
  total_cpfs: number
  success_count: number
  policy_declined_count: number
  fail_count: number

  has_file?: boolean | null
  file_disk?: string | null
  file_path?: string | null
  file_name?: string | null

  spool_bytes?: number | null
  spool_path?: string | null
  spool_inputs_path?: string | null

  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  paused_at?: string | null
  cancel_reason?: string | null
  scheduled_for?: string | null
  created_at: string
}

export interface PresencaConsultJobShow {
  id: number
  title: string
  executor?: 'local' | 'api'
  status: PresencaJobStatus
  phase?: PresencaJobPhase
  total_cpfs: number
  success_count: number
  policy_declined_count: number
  fail_count: number
  has_file: boolean

  preview_running?: boolean
  spool_bytes?: number | null
  spool_path?: string | null
  spool_inputs_path?: string | null

  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  paused_at?: string | null
  cancel_reason?: string | null
  scheduled_for?: string | null
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const BASE = '/presenca/consult-jobs'
const PRESENCA_JOB_STATUSES: PresencaJobStatus[] = ['agendado', 'pendente', 'em_progresso', 'pausado', 'concluido', 'falhou', 'cancelado']

export async function listPresencaConsultJobs(
  page = 1,
  opts?: { status?: PresencaJobStatusFilter }
): Promise<Paginated<PresencaConsultJobListItem>> {
  const params: Record<string, string | number> = { page }
  const requestedStatus = opts?.status
  if (
    typeof requestedStatus === 'string'
    && requestedStatus !== 'todos'
    && PRESENCA_JOB_STATUSES.includes(requestedStatus as PresencaJobStatus)
  ) {
    params.status = requestedStatus
  }

  const { data } = await axiosClient.get<Paginated<PresencaConsultJobListItem>>(
    BASE,
    { params }
  )
  return data
}

export async function createPresencaConsultJob(input: {
  title: string
  lines: string
  run_at?: string
  timezone?: string
}) {
  const { data } = await axiosClient.post<{ id: number; status: PresencaJobStatus; phase?: PresencaJobPhase; scheduled_for?: string | null }>(
    BASE,
    input
  )
  return data
}

export async function getPresencaConsultJob(id: number): Promise<PresencaConsultJobShow> {
  const { data } = await axiosClient.get<PresencaConsultJobShow>(`${BASE}/${id}`)
  return data
}

export async function downloadPresencaReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
    timeout: DOWNLOAD_TIMEOUT_MS,
    params: { t: Date.now() },
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `presenca-consulta-${id}.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

export async function downloadPresencaPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
    timeout: DOWNLOAD_TIMEOUT_MS,
    params: { t: Date.now() },
    validateStatus: (s) => (s >= 200 && s < 300) || s === 409,
  })

  if (resp.status === 409) {
    const err = new Error('Prévia indisponível ainda (spool ausente).')
    // @ts-expect-error attach status
    err.status = 409
    throw err
  }

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `presenca-consulta-${id}-preview.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

export async function cancelPresencaConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number
    status: PresencaJobStatus
    phase?: PresencaJobPhase
    canceled_at?: string | null
    cancel_reason?: string | null
    finished_at?: string | null
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {})
  return data
}

export async function pausePresencaConsultJob(id: number) {
  const { data } = await axiosClient.post<{
    id: number
    status: PresencaJobStatus
    phase?: PresencaJobPhase
    paused_at?: string | null
  }>(`${BASE}/${id}/pause`)
  return data
}

export async function resumePresencaConsultJob(id: number) {
  const { data } = await axiosClient.post<{
    id: number
    status: PresencaJobStatus
    phase?: PresencaJobPhase
  }>(`${BASE}/${id}/resume`)
  return data
}

export async function deletePresencaConsultJob(id: number): Promise<void> {
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
