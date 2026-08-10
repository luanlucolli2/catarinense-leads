import axiosClient, { DOWNLOAD_TIMEOUT_MS } from './axiosClient'

export type SomaCltJobStatus = 'agendado' | 'pendente' | 'em_progresso' | 'pausado' | 'concluido' | 'falhou' | 'cancelado'
export type SomaCltJobStatusFilter = SomaCltJobStatus | 'todos'

export interface SomaCltConsultJobListItem {
  id: number
  title: string
  mode: 'uy3' | 'celcoin'
  executor?: 'api'
  status: SomaCltJobStatus
  phase?: string | null
  total_cpfs: number
  success_count: number
  policy_declined_count: number
  fail_count: number
  phase1_pending_count: number
  phase1_success_count: number
  phase1_declined_count: number
  phase1_errors_count: number
  phase2_success_count: number
  phase2_declined_count: number
  phase2_errors_count: number
  has_file?: boolean | null
  file_path?: string | null
  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  paused_at?: string | null
  cancel_reason?: string | null
  scheduled_for?: string | null
  created_at: string
}

export interface SomaCltConsultJobShow extends SomaCltConsultJobListItem {
  has_file: boolean
  preview_running?: boolean
}

interface Paginated<T> { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const BASE = '/soma-clt/consult-jobs'

export async function listSomaCltConsultJobs(page = 1, status: SomaCltJobStatusFilter = 'todos') {
  const { data } = await axiosClient.get<Paginated<SomaCltConsultJobListItem>>(BASE, { params: status === 'todos' ? { page } : { page, status } })
  return data
}

export async function createSomaCltConsultJob(input: { title: string; mode: 'uy3' | 'celcoin'; lines: string; run_at?: string; timezone?: string }) {
  const { data } = await axiosClient.post<SomaCltConsultJobShow>(BASE, input)
  return data
}

export async function getSomaCltConsultJob(id: number) {
  const { data } = await axiosClient.get<SomaCltConsultJobShow>(`${BASE}/${id}`)
  return data
}

export async function pauseSomaCltConsultJob(id: number) { return (await axiosClient.post(`${BASE}/${id}/pause`)).data }
export async function resumeSomaCltConsultJob(id: number) { return (await axiosClient.post(`${BASE}/${id}/resume`)).data }
export async function cancelSomaCltConsultJob(id: number, reason?: string) { return (await axiosClient.post(`${BASE}/${id}/cancel`, reason ? { reason } : {})).data }
export async function deleteSomaCltConsultJob(id: number) { await axiosClient.delete(`${BASE}/${id}`) }

async function download(id: number, suffix: string) {
  const response = await axiosClient.get(`${BASE}/${id}${suffix}`, { responseType: 'blob', timeout: DOWNLOAD_TIMEOUT_MS, params: { t: Date.now() } })
  const header = response.headers['content-disposition'] || ''
  const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(header)
  const filename = match ? decodeURIComponent(match[1].replace(/"/g, '')) : `soma-clt-consulta-${id}${suffix ? '-preview' : ''}.csv`
  const url = window.URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const downloadSomaCltReport = (id: number) => download(id, '/download')
export const downloadSomaCltPreview = (id: number) => download(id, '/preview')
