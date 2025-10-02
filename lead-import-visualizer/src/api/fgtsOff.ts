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

/** Estados da PRÉVIA (alinhado ao backend) */
export type PreviewStatus = 'none' | 'queued' | 'running' | 'ready' | 'error'

/** DTO básico (index) */
export interface FgtsOffConsultJobListItem {
  id: number
  title: string
  status: FgtsOffJobStatus
  total_cpfs: number
  success_count: number
  not_authorized_count: number
  fail_count: number
  file_disk?: string | null
  file_path?: string | null
  file_name?: string | null
  // PRÉVIA (opcional)
  preview_disk?: string | null
  preview_path?: string | null
  preview_name?: string | null
  preview_updated_at?: string | null
  // telemetria opcional
  spool_bytes?: number | null

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

  // PRÉVIA
  has_preview?: boolean
  preview_status?: PreviewStatus
  preview_updated_at?: string | null
  preview_requested_at?: string | null
  preview_started_at?: string | null
  preview_finished_at?: string | null
  preview_size_bytes?: number | null
  preview_rows?: number | null
  preview_error?: string | null

  // telemetria
  spool_bytes?: number | null

  // datas
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

/** Base de rotas do módulo FGTS OFF */
const BASE = '/fgts-off/consult-jobs'

/** Lista os jobs do usuário autenticado */
export async function listFgtsOffConsultJobs(page = 1): Promise<Paginated<FgtsOffConsultJobListItem>> {
  const { data } = await axiosClient.get<Paginated<FgtsOffConsultJobListItem>>(
    `${BASE}?page=${page}`
  )
  return data
}

/** Cria um novo job */
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

/** Busca um job específico */
export async function getFgtsOffConsultJob(id: number): Promise<FgtsOffConsultJobShow> {
  const { data } = await axiosClient.get<FgtsOffConsultJobShow>(`${BASE}/${id}`)
  return data
}

/** Solicita geração da PRÉVIA (202=aceita/andando, 200=já pronta, 409=indisponível). */
export async function requestFgtsOffPreview(id: number): Promise<200 | 202 | 409> {
  const resp = await axiosClient.post(
    `${BASE}/${id}/preview/generate`,
    null,
    {
      validateStatus: (s) => (s >= 200 && s < 300) || s === 409
    }
  )
  return resp.status as 200 | 202 | 409
}
/** (opcional) também pode aplicar cache-busting no FINAL */
export async function downloadFgtsOffReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
    params: { t: Date.now() }, // seguro aplicar; final não muda, mas evita cache agressivo
  });

  const cd = resp.headers['content-disposition'] || '';
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}.xlsx`;

  const url = window.URL.createObjectURL(resp.data);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}

/** Faz o download da PRÉVIA já pronta (NÃO força regeneração) */
export async function downloadFgtsOffPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
    // 👇 evita baixar versão antiga por cache do navegador/CDN
    params: { t: Date.now() },
  });

  const cd = resp.headers['content-disposition'] || '';
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}-preview.xlsx`;

  const url = window.URL.createObjectURL(resp.data);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}

/** Cancela um job */
export async function cancelFgtsOffConsultJob(id: number, reason?: string) {
  const { data } = await axiosClient.post<{
    id: number
    status: FgtsOffJobStatus
    canceled_at?: string | null
    cancel_reason?: string | null
  }>(`${BASE}/${id}/cancel`, reason ? { reason } : {})
  return data
}

/** Exclui um job */
export async function deleteFgtsOffConsultJob(id: number): Promise<void> {
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
