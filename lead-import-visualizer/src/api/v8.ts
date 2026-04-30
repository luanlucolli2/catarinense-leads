import axiosClient from './axiosClient'
export { ensureCsrfCookie } from './axiosClient'

export type V8JobStatus =
  | 'agendado'
  | 'pendente'
  | 'em_progresso'
  | 'pausado'
  | 'concluido'
  | 'falhou'
  | 'cancelado'

export type V8JobPhase = 'fase_1' | 'fase_2' | null

export interface V8ConsultJobListItem {
  id: number
  title: string
  status: V8JobStatus
  phase?: V8JobPhase
  total_cpfs: number
  success_count: number
  nao_elegivel_count: number
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

export interface V8ConsultJobShow {
  id: number
  title: string
  status: V8JobStatus
  phase?: V8JobPhase
  reuse_recent_consults?: boolean
  reuse_recent_consults_days?: number
  total_cpfs: number
  success_count: number
  nao_elegivel_count: number
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

const BASE = '/v8/consult-jobs'

export async function listV8ConsultJobs(page = 1): Promise<Paginated<V8ConsultJobListItem>> {
  const { data } = await axiosClient.get<Paginated<V8ConsultJobListItem>>(
    `${BASE}?page=${page}`
  )
  return data
}

export async function createV8ConsultJob(input: {
  title: string
  lines: string
  run_at?: string
  timezone?: string
  reuse_recent_consults?: boolean
  reuse_recent_consults_days?: number
}) {
  const { data } = await axiosClient.post<{ id: number; status: V8JobStatus; phase?: V8JobPhase; scheduled_for?: string | null }>(
    BASE,
    input
  )
  return data
}

export async function getV8ConsultJob(id: number): Promise<V8ConsultJobShow> {
  const { data } = await axiosClient.get<V8ConsultJobShow>(`${BASE}/${id}`)
  return data
}

export async function downloadV8Report(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
    params: { t: Date.now() },
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `v8-consulta-${id}.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

export async function downloadV8Preview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
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
  const name = parseContentDispositionFilename(cd) || `v8-consulta-${id}-preview.csv`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

export async function cancelV8ConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number
    status: V8JobStatus
    phase?: V8JobPhase
    canceled_at?: string | null
    cancel_reason?: string | null
    finished_at?: string | null
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {})
  return data
}

export async function pauseV8ConsultJob(id: number) {
  const { data } = await axiosClient.post<{
    id: number
    status: V8JobStatus
    phase?: V8JobPhase
    paused_at?: string | null
  }>(`${BASE}/${id}/pause`)
  return data
}

export async function resumeV8ConsultJob(id: number) {
  const { data } = await axiosClient.post<{
    id: number
    status: V8JobStatus
    phase?: V8JobPhase
  }>(`${BASE}/${id}/resume`)
  return data
}

export async function deleteV8ConsultJob(id: number): Promise<void> {
  await axiosClient.delete(`${BASE}/${id}`)
}

function parseContentDispositionFilename(contentDisposition: string): string | null {
  const match = /filename\*?=(?:UTF-8''|")?([^\";]+)/i.exec(contentDisposition)
  if (!match) return null
  try {
    return decodeURIComponent(match[1].replace(/\"/g, ''))
  } catch {
    return match[1].replace(/\"/g, '')
  }
}
