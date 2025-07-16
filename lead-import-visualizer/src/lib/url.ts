// src/lib/url.ts
export interface FilterParams {
  page?: number;
  search?: string;
  status?: string;
  motivos?: string[];
  origens?: string[];
  origens_hig?: string[];
  date_from?: string;
  date_to?: string;
  contract_from?: string;
  contract_to?: string;
  cpf?: string;
  names?: string;
  phones?: string;
  vendors?: string[];
}

export function parseFiltersFromUrl(): FilterParams {
  const params = new URLSearchParams(window.location.search)
  const get = (key: string) => params.get(key) ?? undefined

  const arr = (key: string): string[] | undefined => {
    const v = params.get(key)
    return v ? v.split(",").filter(Boolean) : undefined
  }

  return {
    page: params.get("page") ? Number(params.get("page")) : undefined,
    search: get("search"),
    status: get("status"),
    motivos: arr("motivos"),
    origens: arr("origens"),
    origens_hig: arr("origens_hig"),
    date_from: get("date_from"),
    date_to: get("date_to"),
    contract_from: get("contract_from"),
    contract_to: get("contract_to"),
    cpf: get("cpf"),
    names: get("names"),
    phones: get("phones"),
    vendors: arr("vendors"),
  }
}

export function buildUrlWithFilters(filters: FilterParams): string {
  const params = new URLSearchParams()
  if (filters.page) params.set("page", String(filters.page))
  if (filters.search) params.set("search", filters.search)
  if (filters.status) params.set("status", filters.status)
  if (filters.motivos?.length) params.set("motivos", filters.motivos.join(","))
  if (filters.origens?.length) params.set("origens", filters.origens.join(","))
  if (filters.origens_hig?.length) params.set("origens_hig", filters.origens_hig.join(","))
  if (filters.date_from) params.set("date_from", filters.date_from)
  if (filters.date_to) params.set("date_to", filters.date_to)
  if (filters.contract_from) params.set("contract_from", filters.contract_from)
  if (filters.contract_to) params.set("contract_to", filters.contract_to)
  if (filters.cpf) params.set("cpf", filters.cpf)
  if (filters.names) params.set("names", filters.names)
  if (filters.phones) params.set("phones", filters.phones)
  if (filters.vendors?.length) params.set("vendors", filters.vendors.join(","))
  const qs = params.toString()
  return qs ? `?${qs}` : ""
}
