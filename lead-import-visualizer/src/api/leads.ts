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
    search?: string
    status?: "todos" | "elegiveis" | "nao-elegiveis"
    motivos?: string[]
    origens?: string[]
    date_from?: string
    date_to?: string
    contract_from?: string
    contract_to?: string
    cpf?: string      // agora pode conter vírgulas, ; ou quebras
    names?: string
    phones?: string
}

// src/api/leads.ts
/* ---------- Helpers ---------- */
const splitAndNormalize = (raw: string, stripNonDigits = true): string[] =>
    raw
        .split(/[\n,;]+/)
        .map(s => stripNonDigits ? s.replace(/\D/g, "") : s.trim())
        .filter(Boolean)

const buildQueryParams = (f: LeadFilters) => {
    const p = new URLSearchParams()

    // filtros básicos
    if (f.page) p.set("page", String(f.page))
    // 1) busca geral: normaliza CPF/telefone
    if (f.search) {
        const raw = f.search.trim()
        const hasLetters = /[A-Za-zÀ-ú]/.test(raw)
        const normalized = hasLetters ? raw : raw.replace(/\D/g, "")
        p.set("search", normalized)
    } if (f.status && f.status !== "todos") p.set("status", f.status)
    if (f.motivos?.length) p.set("motivos", f.motivos.join(","))
    if (f.origens?.length) p.set("origens", f.origens.join(","))
    if (f.date_from) p.set("date_from", f.date_from)
    if (f.date_to) p.set("date_to", f.date_to)
    if (f.contract_from) p.set("contract_from", f.contract_from)
    if (f.contract_to) p.set("contract_to", f.contract_to)

    // filtros em massa
    if (f.cpf) {
        const list = splitAndNormalize(f.cpf, true)
        if (list.length) p.set("cpf", list.join(","))
    }
    if (f.names) {
        const list = splitAndNormalize(f.names, false)
        if (list.length) p.set("names", list.join(","))
    }
    if (f.phones) {
        const list = splitAndNormalize(f.phones, true)
        if (list.length) p.set("phones", list.join(","))
    }

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
