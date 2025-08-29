// src/api/clt.ts
import axiosClient from './axiosClient'

/** Estados do job no backend */
export type CltJobStatus = 'pendente' | 'em_progresso' | 'concluido' | 'falhou' | 'cancelado'

/** DTO básico (index) */
export interface CltConsultJobListItem {
  id: number
  title: string
  status: CltJobStatus
  total_cpfs: number
  success_count: number
  fail_count: number
  not_found_count: number       // 👈 novo
  file_disk?: string | null
  file_path?: string | null
  file_name?: string | null
  // campos opcionais de PRÉVIA
  preview_disk?: string | null
  preview_path?: string | null
  preview_name?: string | null
  preview_updated_at?: string | null

  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  cancel_reason?: string | null
  created_at: string
}

/** DTO de show() */
export interface CltConsultJobShow {
  id: number
  title: string
  status: CltJobStatus
  total_cpfs: number
  success_count: number
  fail_count: number
  not_found_count: number       // 👈 novo
  has_file: boolean
  has_preview?: boolean
  preview_updated_at?: string | null

  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  cancel_reason?: string | null
  created_at: string
}

/** Resposta de paginação Laravel */
export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/** (Opcional) garantir CSRF da sessão Sanctum antes de POST */
export async function ensureCsrfCookie() {
  await axiosClient.get('/sanctum/csrf-cookie')
}

/** Lista os jobs do usuário autenticado */
export async function listCltConsultJobs(page = 1): Promise<Paginated<CltConsultJobListItem>> {
  const { data } = await axiosClient.get<Paginated<CltConsultJobListItem>>(
    `/clt/consult-jobs?page=${page}`
  )
  return data
}

/** Cria um novo job (cpfs: string colada do textarea ou array de strings) */
export async function createCltConsultJob(input: { title: string; cpfs: string | string[] }) {
  const { data } = await axiosClient.post<{ id: number; status: CltJobStatus }>(
    '/clt/consult-jobs',
    input
  )
  return data
}

/** Busca um job específico (para checar status) */
export async function getCltConsultJob(id: number): Promise<CltConsultJobShow> {
  const { data } = await axiosClient.get<CltConsultJobShow>(`/clt/consult-jobs/${id}`)
  return data
}

/** Faz o download do relatório FINAL (stream) */
export async function downloadCltReport(id: number) {
  const resp = await axiosClient.get(`/clt/consult-jobs/${id}/download`, {
    responseType: 'blob',
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `clt-consulta-${id}.xlsx`

  const url = window.URL.createObjectURL(resp.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

/** Faz o download da PRÉVIA (enquanto em andamento) */
export async function downloadCltPreview(id: number) {
  const resp = await axiosClient.get(`/clt/consult-jobs/${id}/preview`, {
    responseType: 'blob',
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `clt-consulta-${id}-preview.xlsx`

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
    canceled_at?: string | null
    cancel_reason?: string | null
  }>(`/clt/consult-jobs/${id}/cancel`, reason ? { reason } : {})
  return data
}

/** Exclui definitivamente um job e seus arquivos */
export async function deleteCltConsultJob(id: number) {
  const { data } = await axiosClient.delete<{ success: boolean; id: number }>(
    `/clt/consult-jobs/${id}`
  )
  return data
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
