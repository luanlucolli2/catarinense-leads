/* Camada de acesso aos endpoints /leads */
import axiosClient from "@/api/axiosClient"

/* ---------- Tipagens ---------- */
export interface LeadFromApi {
    id: number
    cpf: string
    nome: string | null
    data_nascimento: string | null
    fone1: string | null
    classe_fone1: string | null
    fone2: string | null
    classe_fone2: string | null
    fone3: string | null
    classe_fone3: string | null
    fone4: string | null
    classe_fone4: string | null
    primeira_origem: string | null
    consulta: string | null
    data_atualizacao: string | null
    saldo: string | null
    libera: string | null
    contracts_count: number
}

export interface LeadDetailFromApi {
  id: number
  cpf: string
  nome: string
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  consulta: string | null
  data_atualizacao: string | null
  saldo: string | null
  libera: string | null
  contracts: { id: number; data_contrato: string }[]
  importJobs: { id: number; origin: string; type: string; created_at: string }[]
}
export interface PaginatedLeadsResponse {
    data: LeadFromApi[]
    current_page: number
    last_page: number
    total: number
}

export interface LeadFilters {
    page?: number
    /* filtros simples */
    search?: string
    status?: "todos" | "elegiveis" | "nao-elegiveis"
    motivos?: string[]
    origens?: string[]
    date_from?: string
    date_to?: string
    /* período de contrato */
    contract_from?: string      // <- nomes EXATOS que o back-end espera
    contract_to?: string
    /* filtros em massa */
    cpf?: string      // lista já como “111,222,333”
    names?: string
    phones?: string
}

/* ---------- Helpers ---------- */
const buildQueryParams = (f: LeadFilters) => {
    const p = new URLSearchParams()

    if (f.page) p.set("page", String(f.page))
    if (f.search) p.set("search", f.search)
    if (f.status && f.status !== "todos") p.set("status", f.status)

    if (f.motivos?.length) p.set("motivos", f.motivos.join(","))
    if (f.origens?.length) p.set("origens", f.origens.join(","))

    if (f.date_from) p.set("date_from", f.date_from)
    if (f.date_to) p.set("date_to", f.date_to)

    // ❌ Antes você mandava cpf “com pontuação” e o back só armazena dígitos
    if (f.contract_from) p.set("contract_from", f.contract_from)
    if (f.contract_to) p.set("contract_to", f.contract_to)

    // aqui stripamos tudo que não for dígito
    if (f.cpf) p.set("cpf", f.cpf.replace(/\D/g, ""))

    // nomes: envia exatamente como o usuário digitou (só trim)
    if (f.names) p.set("names", f.names.trim())

    // telefones idem cpf
    if (f.phones) p.set("phones", f.phones.replace(/\D/g, ""))

    return p
}

/* ---------- Endpoints ---------- */
export async function fetchLeads(filters: LeadFilters) {
    const params = buildQueryParams(filters)
    const { data } = await axiosClient.get<PaginatedLeadsResponse>("/leads", {
        params,
    })
    return data
}
export async function fetchLeadDetail(id: number) {
  const { data } = await axiosClient.get<LeadDetailFromApi>(`/leads/${id}`)
  return data
}
export interface FiltersOptionsDTO {
    motivos: string[]
    origens: string[]
}
export async function fetchLeadsFilters() {
    const { data } = await axiosClient.get<FiltersOptionsDTO>("/leads/filters")
    return data
}
