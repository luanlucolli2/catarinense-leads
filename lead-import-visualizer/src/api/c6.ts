import axiosClient from './axiosClient'
export { ensureCsrfCookie } from './axiosClient'

export type C6LinkStatus = 'ativo' | 'expirado'

export interface C6PhoneInput {
  numero: string
  codigoArea: string
}

export interface GenerateC6AuthorizationLinkInput {
  cpf: string
  nomeCliente?: string
  dataNascimento?: string
  telefone?: C6PhoneInput
}

export interface GenerateC6AuthorizationLinkResponse {
  id: number
  link: string
  nome_cliente: string | null
  generated_at: string
  data_expiracao: string
  status: C6LinkStatus
  reused: boolean
  message?: string
}

export interface C6AuthorizationLinkListItem {
  id: number
  cpf: string
  nome_cliente: string | null
  link: string
  generated_at: string | null
  data_expiracao: string | null
  status: C6LinkStatus
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ListC6AuthorizationLinksParams {
  page?: number
  perPage?: number
  nome?: string
  cpf?: string
  status?: C6LinkStatus | ''
}

const BASE = '/c6'

export async function generateC6AuthorizationLink(
  input: GenerateC6AuthorizationLinkInput
): Promise<GenerateC6AuthorizationLinkResponse> {
  const payload: Record<string, unknown> = {
    cpf: input.cpf,
  }

  const nomeCliente = input.nomeCliente?.trim()
  if (nomeCliente) payload.nome_cliente = nomeCliente

  const dataNascimento = input.dataNascimento?.trim()
  if (dataNascimento) payload.data_nascimento = dataNascimento

  if (input.telefone?.numero && input.telefone.codigoArea) {
    payload.telefone = {
      numero: input.telefone.numero,
      codigo_area: input.telefone.codigoArea,
    }
  }

  const { data } = await axiosClient.post<GenerateC6AuthorizationLinkResponse>(
    `${BASE}/authorization-link`,
    payload
  )

  return data
}

export async function listC6AuthorizationLinks(
  params: ListC6AuthorizationLinksParams = {}
): Promise<Paginated<C6AuthorizationLinkListItem>> {
  const query: Record<string, string | number> = {}

  if (params.page) query.page = params.page
  if (params.perPage) query.per_page = params.perPage
  if (params.nome?.trim()) query.nome = params.nome.trim()
  if (params.cpf?.trim()) query.cpf = params.cpf.trim()
  if (params.status) query.status = params.status

  const { data } = await axiosClient.get<Paginated<C6AuthorizationLinkListItem>>(
    `${BASE}/authorization-links`,
    { params: query }
  )

  return data
}
