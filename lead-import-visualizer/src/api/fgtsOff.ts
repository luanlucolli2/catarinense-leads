// src/api/fgtsOff.ts
import axiosClient from './axiosClient'

/** Estados do job no backend */
export type FgtsOffJobStatus =
  | 'pendente'
  | 'em_progresso'
  | 'concluido'
  | 'falhou'
  | 'cancelado'
  | 'agendado'
  | 'expirado'

/** DTO básico (index) */
export interface FgtsOffConsultJobListItem {
  id: number
  title: string
  status: FgtsOffJobStatus
  total_cpfs: number
  success_count: number              // autorizado
  not_authorized_count: number       // não autorizado
  fail_count: number                 // erro
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
  scheduled_for?: string | null
  scheduled_until?: string | null
  created_at: string
}

/** DTO de show() */
export interface FgtsOffConsultJobShow {
  id: number
  title: string
  status: FgtsOffJobStatus
  total_cpfs: number
  success_count: number
  not_authorized_count: number
  fail_count: number
  has_file: boolean
  has_preview?: boolean
  preview_updated_at?: string | null
  started_at?: string | null
  finished_at?: string | null
  canceled_at?: string | null
  cancel_reason?: string | null
  scheduled_for?: string | null
  scheduled_until?: string | null
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

/** Base de rotas do módulo FGTS OFF — ajuste caso seu backend use outro prefixo */
const BASE = '/fgts-off/consult-jobs'

/** Lista os jobs do usuário autenticado */
export async function listFgtsOffConsultJobs(page = 1): Promise<Paginated<FgtsOffConsultJobListItem>> {
  const { data } = await axiosClient.get<Paginated<FgtsOffConsultJobListItem>>(
    `${BASE}?page=${page}`
  )
  return data
}

/** Cria um novo job (cpfs: string colada do textarea ou array de strings)
 *  Suporta agendamento com run_at, end_at e timezone (opcionais).
 */
export async function createFgtsOffConsultJob(input: {
  title: string
  cpfs: string | string[]
  run_at?: string
  end_at?: string
  timezone?: string
}) {
  const { data } = await axiosClient.post<{ id: number; status: FgtsOffJobStatus }>(
    BASE,
    input
  )
  return data
}

/** Busca um job específico (para checar status) */
export async function getFgtsOffConsultJob(id: number): Promise<FgtsOffConsultJobShow> {
  const { data } = await axiosClient.get<FgtsOffConsultJobShow>(`${BASE}/${id}`)
  return data
}

/** Faz o download do relatório FINAL (stream) — liberado em 'concluido' ou 'expirado' */
export async function downloadFgtsOffReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}.xlsx`

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
export async function downloadFgtsOffPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
  })

  const cd = resp.headers['content-disposition'] || ''
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}-preview.xlsx`

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
export async function cancelFgtsOffConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number
    status: FgtsOffJobStatus
    canceled_at?: string | null
    cancel_reason?: string | null
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {})
  return data
}

/** Exclui definitivamente um job e seus arquivos */
export async function deleteFgtsOffConsultJob(id: number) {
  const { data } = await axiosClient.delete<{ success: boolean; id: number }>(
    `${BASE}/${id}`
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
