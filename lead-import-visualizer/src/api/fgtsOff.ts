import axiosClient from './axiosClient'
export { ensureCsrfCookie } from './axiosClient'

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
  success_count: number
  not_authorized_count: number
  fail_count: number
  file_disk?: string | null
  file_path?: string | null
  file_name?: string | null
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

/** DTO de show() – enxuto conforme novo backend */
export interface FgtsOffConsultJobShow {
  id: number
  title: string
  status: FgtsOffJobStatus
  total_cpfs: number
  success_count: number
  not_authorized_count: number
  fail_count: number
  has_file: boolean

  // novo: prévia é “espelho” do spool (boolean calculado)
  preview_running?: boolean
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

/** Download do RELATÓRIO FINAL (CSV) */
export async function downloadFgtsOffReport(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/download`, {
    responseType: 'blob',
    params: { t: Date.now() },
  });

  const cd = resp.headers['content-disposition'] || '';
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}.csv`;

  const url = window.URL.createObjectURL(resp.data);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
}

/** Download da PRÉVIA (espelho do spool CSV). Backend retorna 409 se ainda não há spool. */
export async function downloadFgtsOffPreview(id: number) {
  const resp = await axiosClient.get(`${BASE}/${id}/preview`, {
    responseType: 'blob',
    params: { t: Date.now() },
    validateStatus: (s) => (s >= 200 && s < 300) || s === 409,
  });

  if (resp.status === 409) {
    const err = new Error('Prévia indisponível ainda (spool ausente).');
    // @ts-expect-error attach status
    err.status = 409;
    throw err;
  }

  const cd = resp.headers['content-disposition'] || '';
  const name = parseContentDispositionFilename(cd) || `fgts-offline-${id}-preview.csv`;

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
