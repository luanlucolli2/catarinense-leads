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
  /** ➕ FGTS OFF */
  fgts_off_authorized: boolean | null
  fgts_off_consultado_em: string | null
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
  created_at: string | null
  updated_at: string | null
  contracts: {
    id: number
    data_contrato: string
    vendor?: { id: number; name: string }
  }[]
  importJobs: { id: number; origin: string; type: string; created_at: string }[]
  /** ➕ FGTS OFF */
  fgts_off_authorized: boolean | null
  fgts_off_consultado_em: string | null
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
  cpf?: string
  names?: string
  phones?: string
  origens_hig?: string[]
  vendors?: string[]
  /** 🎂 meses de aniversário (1..12 como strings) */
  birth_month?: string[]
  /** ➕ FGTS OFF (tri-estado) */
  fgts_status?: "autorizado" | "nao_autorizado" | "nao_consultado" // enviamos só quando setado
  fgts_consulta_from?: string      // YYYY-MM-DD
  fgts_consulta_to?: string        // YYYY-MM-DD
}

/* ---------- Helpers ---------- */
const splitAndNormalize = (raw: string, stripNonDigits = true): string[] =>
  raw
    .split(/[\n,;]+/)
    .map((s) => (stripNonDigits ? s.replace(/\D/g, "") : s.trim()))
    .filter(Boolean)

const normalizeMonths = (arr?: string[]) =>
  (arr ?? [])
    .map((m) => String(parseInt(m, 10)))
    .filter((m) => /^\d+$/.test(m) && +m >= 1 && +m <= 12)

const buildQueryParams = (f: LeadFilters) => {
  const p = new URLSearchParams()

  // filtros básicos
  if (f.page) p.set("page", String(f.page))
  if (f.search) {
    const raw = f.search.trim()
    const hasLetters = /[A-Za-zÀ-ú]/.test(raw)
    const normalized = hasLetters ? raw : raw.replace(/\D/g, "")
    p.set("search", normalized)
  }
  if (f.status && f.status !== "todos") p.set("status", f.status)
  if (f.motivos?.length) p.set("motivos", f.motivos.join(","))
  if (f.origens?.length) p.set("origens", f.origens.join(","))
  if (f.origens_hig?.length) p.set("origens_hig", f.origens_hig.join(","))
  if (f.date_from) p.set("date_from", f.date_from)
  if (f.date_to) p.set("date_to", f.date_to)
  if (f.contract_from) p.set("contract_from", f.contract_from)
  if (f.contract_to) p.set("contract_to", f.contract_to)

  // ➕ FGTS OFF (tri-estado)
  if (f.fgts_status) p.set("fgts_status", f.fgts_status)
  if (f.fgts_consulta_from) p.set("fgts_consulta_from", f.fgts_consulta_from)
  if (f.fgts_consulta_to) p.set("fgts_consulta_to", f.fgts_consulta_to)

  // filtros em massa (GET -> CSV)
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
  if (f.vendors?.length) p.set("vendors", f.vendors.join(","))

  // 🎂 mês(es) de aniversário
  const months = normalizeMonths(f.birth_month)
  if (months.length) p.set("birth_month", months.join(","))

  return p
}

const shouldUsePost = (filters: LeadFilters) => {
  const params = buildQueryParams(filters)
  const urlPreview = `/leads?${params.toString()}`
  if (urlPreview.length > 1500) return true

  const cpfCount = filters.cpf ? splitAndNormalize(filters.cpf, true).length : 0
  const namesCount = filters.names ? splitAndNormalize(filters.names, false).length : 0
  const phonesCount = filters.phones ? splitAndNormalize(filters.phones, true).length : 0
  const totalMass = cpfCount + namesCount + phonesCount
  return totalMass > 200
}

/* ---------- Endpoints ---------- */
export async function fetchLeads(filters: LeadFilters) {
  if (shouldUsePost(filters)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      search: filters.search?.trim() || undefined,
      status: filters.status && filters.status !== "todos" ? filters.status : undefined,
      motivos: filters.motivos?.length ? filters.motivos : undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      origens_hig: filters.origens_hig?.length ? filters.origens_hig : undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      contract_from: filters.contract_from || undefined,
      contract_to: filters.contract_to || undefined,
      vendors: filters.vendors?.length ? filters.vendors : undefined,
      birth_month: months.length ? months : undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,
      // ➕ FGTS OFF (tri-estado)
      fgts_status: filters.fgts_status || undefined,
      fgts_consulta_from: filters.fgts_consulta_from || undefined,
      fgts_consulta_to: filters.fgts_consulta_to || undefined,
    }

    const { data } = await axiosClient.post<PaginatedLeadsResponse>(
      "/leads/search",
      payload,
      { params: filters.page ? { page: filters.page } : undefined }
    )
    return data
  }

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
  origens_hig: string[]
  vendors: { id: number; name: string }[]
}

export async function fetchLeadsFilters() {
  const { data } = await axiosClient.get<FiltersOptionsDTO>("/leads/filters")
  return data
}

/** Normaliza filtros críticos para o POST do export (mantém compatibilidade com o backend) */
function normalizeFiltersForExport(filters: LeadFilters): LeadFilters {
  const normalized: LeadFilters = { ...filters }
  normalized.birth_month = normalizeMonths(filters.birth_month)
  return normalized
}

export async function exportLeads(
  filters: LeadFilters,
  columns: string[]
): Promise<void> {
  const payload = { ...normalizeFiltersForExport(filters), columns }

  const response = await axiosClient.post("/leads/export", payload, {
    responseType: "blob",
  })

  const blob = new Blob([response.data], { type: response.headers["content-type"] })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement("a")

  const cd = response.headers["content-disposition"] as string | undefined
  let filename = "leads_export.xlsx"
  if (cd) {
    const star = cd.match(/filename\*\=([^;]+)/i)
    if (star?.[1]) {
      try {
        const v = star[1].trim()
        const parts = v.split("''")
        filename = decodeURIComponent(parts.pop() || filename)
      } catch {}
    } else {
      const simple = cd.match(/filename="?([^"]+)"?/i)
      if (simple?.[1]) filename = simple[1]
    }
  }

  link.href = url
  link.setAttribute("download", filename)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
