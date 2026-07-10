import axiosClient from "@/api/axiosClient"

export type LemitBankKey = "facta" | "mercantil" | "uy3"
export type LemitCombinationMode = "all" | "any"
export type LemitLoanSituation = "aprovado" | "nao_aprovado"

export interface LemitFactaFilters {
  facta_situacao: LemitLoanSituation | ""
  facta_consulta_from: string
  facta_consulta_to: string
  facta_meses_admissao_min: string
  facta_meses_admissao_max: string
  facta_margem_min: string
  facta_margem_max: string
  facta_numero_parcelas_min: string
  facta_numero_parcelas_max: string
}

export interface LemitMercantilFilters {
  mercantil_situacao: LemitLoanSituation | ""
  mercantil_consulta_from: string
  mercantil_consulta_to: string
  mercantil_valor_parcela_min: string
  mercantil_valor_parcela_max: string
  mercantil_numero_parcelas_min: string
  mercantil_numero_parcelas_max: string
}

export interface LemitUy3Filters {
  uy3_situacao: LemitLoanSituation | ""
  uy3_consulta_from: string
  uy3_consulta_to: string
  uy3_meses_admissao_min: string
  uy3_meses_admissao_max: string
  uy3_margem_min: string
  uy3_margem_max: string
  uy3_valor_liberado_min: string
  uy3_valor_liberado_max: string
  uy3_numero_parcelas_min: string
  uy3_numero_parcelas_max: string
}

export interface LemitPoolFiltersDraft {
  selected_banks: LemitBankKey[]
  bank_combination_mode: LemitCombinationMode
  with_phones: boolean
  without_phones: boolean
  facta: LemitFactaFilters
  mercantil: LemitMercantilFilters
  uy3: LemitUy3Filters
}

export interface LemitPoolPreviewResponse {
  pool_size: number
  pool_with_phones: number
  pool_without_phones: number
}

export interface LemitPoolSampleItem {
  lead_id: number
  cpf: string
  nome: string | null
  telefone_atual_antes: string | null
}

export interface LemitPoolSampleResponse {
  pool_size: number
  sampled_quantity: number
  selected_banks: LemitBankKey[]
  bank_combination_mode: LemitCombinationMode
  items: LemitPoolSampleItem[]
}

export function createDefaultLemitPoolFilters(): LemitPoolFiltersDraft {
  return {
    selected_banks: [],
    bank_combination_mode: "any",
    with_phones: false,
    without_phones: false,
    facta: {
      facta_situacao: "",
      facta_consulta_from: "",
      facta_consulta_to: "",
      facta_meses_admissao_min: "",
      facta_meses_admissao_max: "",
      facta_margem_min: "",
      facta_margem_max: "",
      facta_numero_parcelas_min: "",
      facta_numero_parcelas_max: "",
    },
    mercantil: {
      mercantil_situacao: "",
      mercantil_consulta_from: "",
      mercantil_consulta_to: "",
      mercantil_valor_parcela_min: "",
      mercantil_valor_parcela_max: "",
      mercantil_numero_parcelas_min: "",
      mercantil_numero_parcelas_max: "",
    },
    uy3: {
      uy3_situacao: "",
      uy3_consulta_from: "",
      uy3_consulta_to: "",
      uy3_meses_admissao_min: "",
      uy3_meses_admissao_max: "",
      uy3_margem_min: "",
      uy3_margem_max: "",
      uy3_valor_liberado_min: "",
      uy3_valor_liberado_max: "",
      uy3_numero_parcelas_min: "",
      uy3_numero_parcelas_max: "",
    },
  }
}

export async function previewLemitPool(filters: LemitPoolFiltersDraft) {
  const { data } = await axiosClient.post<LemitPoolPreviewResponse>("/lemit/pool/preview", filters)
  return data
}

export async function sampleLemitPool(filters: LemitPoolFiltersDraft, quantity: number) {
  const { data } = await axiosClient.post<LemitPoolSampleResponse>("/lemit/pool/sample", {
    ...filters,
    quantity,
  })
  return data
}
