export type LemitPrototypeBankKey = "fgts" | "clt" | "mercantil" | "uy3"

export type LemitPrototypeCombinationMode = "all" | "any"

export type LemitPrototypePhoneClass = "Carteira" | "Atendimento IA" | "Lemit" | "Manual"

export type LemitPrototypeFgtsStatus = "autorizado" | "nao_autorizado" | "nao_consultado"
export type LemitPrototypeCltSituacao = "elegivel" | "nao_elegivel" | "nao_encontrado"
export type LemitPrototypeMercantilSituacao = "consultado" | "sem_consulta"
export type LemitPrototypeYesNo = "sim" | "nao"

export type LemitPrototypeResultStatus =
  | "telefone_encontrado"
  | "sem_telefone"
  | "erro_simulado"

export type LemitPrototypeLotStatus = "em_andamento" | "concluido"

export interface LemitPrototypePhoneCandidate {
  ddd: string
  numero: string
  plus: boolean
  ranking: number
  whatsapp: boolean
}

export interface LemitPrototypeFgtsSnapshot {
  motivo: string
  origem_hig: string
  status: Exclude<LemitPrototypeFgtsStatus, "nao_consultado">
}

export interface LemitPrototypeCltSnapshot {
  consultado: boolean
  situacao: LemitPrototypeCltSituacao
  margem_disponivel: number | null
}

export interface LemitPrototypeMercantilSnapshot {
  status: string
  origem: string
}

export interface LemitPrototypeUy3Snapshot {
  type_webhook: string
  status: string
  elegivel_emprestimo: boolean | null
}

export interface LemitPrototypeLead {
  id: number
  cpf: string
  nome: string
  origem_cadastral: string
  data_nascimento: string
  created_at: string
  updated_at: string
  fone1: string | null
  classe_fone1: LemitPrototypePhoneClass | null
  fone2: string | null
  classe_fone2: LemitPrototypePhoneClass | null
  fone3: string | null
  classe_fone3: LemitPrototypePhoneClass | null
  fone4: string | null
  classe_fone4: LemitPrototypePhoneClass | null
  fgts: LemitPrototypeFgtsSnapshot | null
  clt: LemitPrototypeCltSnapshot | null
  mercantil: LemitPrototypeMercantilSnapshot | null
  uy3: LemitPrototypeUy3Snapshot | null
}

export interface LemitPrototypeGeneralFilters {
  search: string
  origens: string[]
  cpf: string
  names: string
  phones: string
  with_phones: boolean
  without_phones: boolean
  birth_month: string[]
}

export interface LemitPrototypeFgtsFilters {
  fgts_status: LemitPrototypeFgtsStatus | ""
  motivos: string[]
  origens_hig: string[]
}

export interface LemitPrototypeCltFilters {
  clt_consultado: LemitPrototypeYesNo | ""
  clt_situacao: LemitPrototypeCltSituacao | ""
  clt_margem_min: string
  clt_margem_max: string
}

export interface LemitPrototypeMercantilFilters {
  mercantil_situacao: LemitPrototypeMercantilSituacao | ""
  mercantil_status: string[]
  mercantil_origens: string[]
}

export interface LemitPrototypeUy3Filters {
  uy3_type_webhook: string[]
  uy3_status: string[]
  uy3_elegivel_emprestimo: LemitPrototypeYesNo | ""
}

export interface LemitPrototypeFilters {
  general: LemitPrototypeGeneralFilters
  selected_banks: LemitPrototypeBankKey[]
  bank_combination_mode: LemitPrototypeCombinationMode
  bank_filters: {
    fgts: LemitPrototypeFgtsFilters
    clt: LemitPrototypeCltFilters
    mercantil: LemitPrototypeMercantilFilters
    uy3: LemitPrototypeUy3Filters
  }
}

export interface LemitPrototypeLotItem {
  cpf: string
  nome: string
  telefone_atual_antes: string
  celulares: LemitPrototypePhoneCandidate[]
  fixos: LemitPrototypePhoneCandidate[]
  telefone_preferido: string | null
  telefone_lemit: string | null
  tipo_telefone: "celular" | "fixo" | null
  whatsapp: boolean | null
  ranking: number | null
  resultado: LemitPrototypeResultStatus
  atualizaria_lead: boolean
}

export interface LemitPrototypeLot {
  id: number
  title: string
  created_at: string
  banks: LemitPrototypeBankKey[]
  bank_combination_mode: LemitPrototypeCombinationMode
  pool_size: number
  requested_quantity: number
  sampled_quantity: number
  status: LemitPrototypeLotStatus
  phones_found_count: number
  leads_updated_count: number
  filters_snapshot: LemitPrototypeFilters
  items: LemitPrototypeLotItem[]
}

export interface LemitPrototypeOptionCatalog {
  origens: string[]
  fgtsMotivos: string[]
  fgtsOrigensHig: string[]
  mercantilStatus: string[]
  mercantilOrigens: string[]
  uy3Types: string[]
  uy3Statuses: string[]
}
