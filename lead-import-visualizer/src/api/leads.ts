/* Camada de acesso aos endpoints /leads */
import axiosClient from "@/api/axiosClient"

const configuredLeads360Timeout = Number(import.meta.env.VITE_LEADS_360_TIMEOUT_MS)
const LEADS_360_TIMEOUT_MS = Number.isFinite(configuredLeads360Timeout) && configuredLeads360Timeout > 0
  ? configuredLeads360Timeout
  : 60_000

/* ---------- Tipagens ---------- */
export type Mode = "base" | "fgts" | "facta" | "mercantil" | "uy3" | "360"
export type LeadBankKey = "fgts" | "facta" | "mercantil" | "uy3"
export type LeadBankCombinationMode = "all" | "any"
export type LeadSort =
  | "lead_updated_at"
  | "lead_created_at"
  | "facta_updated_at"
  | "facta_consulted_at"
  | "mercantil_updated_at"
  | "mercantil_consulted_at"
  | "uy3_consulted_at"

export interface LeadFromApiBase {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  ultima_origem_cadastral: string | null
  ultima_origem_higienizacao: string | null
}

/** FGTS (lista) */
export interface LeadFromApiFGTS {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  /** ⬇️ campos de origem (últimas) */
  ultima_origem_cadastral: string | null
  ultima_origem_higienizacao: string | null
  consulta: string | null
  data_atualizacao: string | null
  saldo: string | null
  libera: string | null
  contracts_count: number
  /** 🆕 último contrato (FGTS) */
  data_contrato_recente: string | null
  vendedor: string | null
  /** ➕ FGTS OFF */
  fgts_off_authorized: boolean | number | "0" | "1" | null
  fgts_off_consultado_em: string | null
}

/** CLT (lista) – subselects no back, join por CPF */
export interface LeadFromApiFacta {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  /** ⬇️ origem cadastral (última), pedido no back para CLT também */
  ultima_origem_cadastral: string | null

  /** Snapshot CLT */
  matricula: string | null
  elegivel: boolean | null
  not_found: boolean | null
  politica_credito_aprovado: boolean | number | "0" | "1" | null
  politica_credito_mensagem: string | null
  politica_credito_valor_maximo_disponivel: string | number | null
  politica_credito_prazo_maximo_disponivel: number | string | null
  politica_credito_data_consulta: string | null
  politica_credito_tabela_aprovada: string | null
  facta_consultado_em: string | null
  facta_dados_atualizados_em: string | null,

  idade: number | null
  sexo: "M" | "F" | string | null
  data_admissao: string | null
  meses_admissao: number | null

  valor_renda: string | number | null
  valor_base_margem: string | number | null
  margem_disponivel: string | number | null
  valor_max_prestacao: string | number | null

  categoria_trabalhador_codigo: string | null
  inicio_atividade_empregador: string | null

  qtd_emprestimos_ativos_suspensos: number | null
  emprestimos_legados: number | null
}

/** CLT Mercantil (lista) – join por CPF */
export interface LeadFromApiMercantil {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  ultima_origem_cadastral: string | null
  ultima_origem_mercantil: string | null

  mercantil_status: string | null
  mercantil_mensagem_erro: string | null
  mercantil_data_hora_origem: string | null
  mercantil_valor_financiado: string | number | null
  mercantil_valor_iof: string | number | null
  mercantil_data_primeiro_vencimento: string | null
  mercantil_valor_emprestimo: string | number | null
  mercantil_quantidade_parcelas: number | string | null
  mercantil_valor_liberado: string | number | null
  mercantil_taxa_juros_mes: string | number | null
  mercantil_valor_parcela: string | number | null
}

export interface LeadFromApiUY3 {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  ultima_origem_cadastral: string | null
  uy3_type_webhook: string | null
  uy3_status: string | null
  uy3_consultado_em: string | null
  uy3_data_admissao: string | null
  uy3_valor_liberado: string | number | null
  uy3_numero_parcelas: number | string | null
  uy3_codigo_requisicao: string | null
  uy3_margem_disponivel: string | number | null
  uy3_elegivel_emprestimo: boolean | number | "0" | "1" | null
  uy3_numero_inscricao_empregador: string | null
  uy3_pessoa_exposta_politicamente_codigo: number | string | null
  uy3_data_hora_validade_solicitacao: string | null
  uy3_is_mei: boolean | number | "0" | "1" | null
  uy3_is_judicial_recovery: boolean | number | "0" | "1" | null
}

export interface LeadFromApi360 {
  id: number
  cpf: string
  nome: string | null
  created_at: string | null
  updated_at: string | null
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
  saldo: string | null
  libera: string | null
  data_atualizacao: string | null
  contracts_count: number
  data_contrato_recente: string | null
  vendedor: string | null
  fgts_off_authorized: boolean | number | "0" | "1" | null
  fgts_off_consultado_em: string | null
  ultima_origem_cadastral: string | null
  ultima_origem_higienizacao: string | null
  ultima_origem_mercantil: string | null
  elegivel: boolean | null
  not_found: boolean | null
  margem_disponivel: string | number | null
  politica_credito_aprovado: boolean | number | "0" | "1" | null
  politica_credito_valor_maximo_disponivel: string | number | null
  facta_consultado_em: string | null
  facta_dados_atualizados_em: string | null
  mercantil_status: string | null
  mercantil_mensagem_erro: string | null
  mercantil_data_hora_origem: string | null
  mercantil_valor_financiado: string | number | null
  mercantil_valor_iof: string | number | null
  mercantil_data_primeiro_vencimento: string | null
  mercantil_valor_emprestimo: string | number | null
  mercantil_quantidade_parcelas: number | string | null
  mercantil_valor_liberado: string | number | null
  mercantil_taxa_juros_mes: string | number | null
  mercantil_valor_parcela: string | number | null
  uy3_type_webhook: string | null
  uy3_status: string | null
  uy3_consultado_em: string | null
  uy3_data_admissao: string | null
  uy3_valor_liberado: string | number | null
  uy3_numero_parcelas: number | string | null
  uy3_codigo_requisicao: string | null
  uy3_margem_disponivel: string | number | null
  uy3_elegivel_emprestimo: boolean | number | "0" | "1" | null
  uy3_numero_inscricao_empregador: string | null
  uy3_pessoa_exposta_politicamente_codigo: number | string | null
  uy3_data_hora_validade_solicitacao: string | null
  uy3_is_mei: boolean | number | "0" | "1" | null
  uy3_is_judicial_recovery: boolean | number | "0" | "1" | null
}

/** Detalhe (carrega FGTS e CLT) */
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
  importJobs?: { id: number; origin: string; type: string; created_at: string }[]
  import_jobs?: { id: number; origin: string; type: string; created_at: string }[]
  contracts: {
    id: number
    data_contrato: string
    vendor?: { id: number; name: string }
  }[]
  /** ⬇️ origens (últimas) */
  ultima_origem_cadastral: string | null
  ultima_origem_higienizacao: string | null
  /** ➕ FGTS OFF */
  fgts_off_authorized: boolean | number | "0" | "1" | null
  fgts_off_consultado_em: string | null
  facta_dados_atualizados_em: string | null,

  /** ➕ CLT */
  matricula: string | null
  elegivel: boolean | null
  idade: number | null
  sexo: "M" | "F" | string | null
  data_admissao: string | null
  meses_admissao: number | null
  valor_renda: string | number | null
  valor_base_margem: string | number | null
  margem_disponivel: string | number | null
  valor_max_prestacao: string | number | null
  categoria_trabalhador_codigo: string | null
  inicio_atividade_empregador: string | null
  qtd_emprestimos_ativos_suspensos: number | null
  emprestimos_legados: number | null
  not_found: boolean | null
  facta_consultado_em: string | null
  mercantil_status: string | null
  mercantil_mensagem_erro: string | null
  mercantil_data_hora_origem: string | null
  mercantil_valor_financiado: string | number | null
  mercantil_valor_iof: string | number | null
  mercantil_data_primeiro_vencimento: string | null
  mercantil_valor_emprestimo: string | number | null
  mercantil_quantidade_parcelas: number | string | null
  mercantil_valor_liberado: string | number | null
  mercantil_taxa_juros_mes: string | number | null
  mercantil_valor_parcela: string | number | null
  ultima_origem_mercantil: string | null
  uy3_type_webhook: string | null
  uy3_status: string | null
  uy3_consultado_em: string | null
  uy3_data_admissao: string | null
  uy3_valor_liberado: string | number | null
  uy3_numero_parcelas: number | string | null
  uy3_codigo_requisicao: string | null
  uy3_margem_disponivel: string | number | null
  uy3_elegivel_emprestimo: boolean | number | "0" | "1" | null
  uy3_numero_inscricao_empregador: string | null
  uy3_pessoa_exposta_politicamente_codigo: number | string | null
  uy3_data_hora_validade_solicitacao: string | null
  uy3_is_mei: boolean | number | "0" | "1" | null
  uy3_is_judicial_recovery: boolean | number | "0" | "1" | null
}

export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export type PaginatedLeadsResponseFGTS = PaginatedResponse<LeadFromApiFGTS>
export type PaginatedLeadsResponseFacta = PaginatedResponse<LeadFromApiFacta>
export type PaginatedLeadsResponseMercantil = PaginatedResponse<LeadFromApiMercantil>
export type PaginatedLeadsResponseUY3 = PaginatedResponse<LeadFromApiUY3>
export type PaginatedLeadsResponseBase = PaginatedResponse<LeadFromApiBase>
export type PaginatedLeadsResponse360 = PaginatedResponse<LeadFromApi360>

export interface LeadFilters {
  page?: number
  per_page?: number
  sort?: LeadSort
  search?: string
  selected_banks?: LeadBankKey[]
  bank_combination_mode?: LeadBankCombinationMode
  /** mantido só por compat na UI; ignorado no back FGTS/CLT */
  status?: "todos" | "elegiveis" | "nao-elegiveis"
  motivos?: string[]
  origens?: string[]        // cadastral
  origens_hig?: string[]    // higienização (relevante no FGTS)
  date_from?: string
  date_to?: string
  contract_from?: string
  contract_to?: string
  cpf?: string
  names?: string
  phones?: string
  with_phones?: boolean
  without_phones?: boolean
  vendors?: string[]
  birth_month?: string[]

  /** ➕ FGTS OFF (apenas uso no modo FGTS) */
  fgts_status?: "autorizado" | "nao_autorizado" | "nao_consultado"
  fgts_consulta_from?: string
  fgts_consulta_to?: string

  /** ➕ CLT — filtros específicos */
  /** novo filtro unificado de situação */
  facta_situacao?: "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado"
  facta_consulta_from?: string
  facta_consulta_to?: string
  facta_admissao_from?: string
  facta_admissao_to?: string
  facta_meses_min?: string | number
  facta_meses_max?: string | number
  facta_inicio_empregador_from?: string
  facta_inicio_empregador_to?: string
  facta_categoria_codigos?: string[] // enviaremos como array no POST e CSV no GET
  facta_idade_min?: string | number
  facta_idade_max?: string | number
  facta_sexo?: ("M" | "F")[]
  facta_renda_min?: string
  facta_renda_max?: string
  facta_base_min?: string
  facta_base_max?: string
  facta_margem_min?: string
  facta_margem_max?: string
  facta_numero_parcelas_min?: string | number
  facta_numero_parcelas_max?: string | number
  facta_prestacao_min?: string
  facta_prestacao_max?: string
  facta_ativos_min?: string | number
  facta_ativos_max?: string | number
  facta_tem_ativos?: "sim" | "nao"
  /** 🔁 apenas boolean */
  facta_tem_legados?: "sim" | "nao"

  /** ➕ MERCANTIL — filtros específicos */
  mercantil_situacao?: "aprovado" | "nao_aprovado"
  mercantil_status?: string[]
  mercantil_consulta_from?: string
  mercantil_consulta_to?: string
  mercantil_valor_parcela_min?: string
  mercantil_valor_parcela_max?: string
  mercantil_numero_parcelas_min?: string | number
  mercantil_numero_parcelas_max?: string | number
  mercantil_parcela_min?: string
  mercantil_parcela_max?: string
  mercantil_qtd_parcelas_min?: string | number
  mercantil_qtd_parcelas_max?: string | number
  mercantil_origens?: string[]

  /** ➕ UY3 — filtros específicos */
  uy3_situacao?: "aprovado" | "nao_aprovado"
  uy3_consulta_from?: string
  uy3_consulta_to?: string
  uy3_meses_admissao_min?: string | number
  uy3_meses_admissao_max?: string | number
  uy3_margem_min?: string
  uy3_margem_max?: string
  uy3_valor_liberado_min?: string
  uy3_valor_liberado_max?: string
  uy3_numero_parcelas_min?: string | number
  uy3_numero_parcelas_max?: string | number
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

const buildQueryParams = (f: LeadFilters, mode: Mode) => {
  const p = new URLSearchParams()
  const supportsFgtsFilters = mode === "fgts" || mode === "360"
  const supportsCltFilters = mode === "facta" || mode === "360"
  const supportsMercantilFilters = mode === "mercantil" || mode === "360"
  const supportsUy3Filters = mode === "uy3" || mode === "360"

  // modo
  p.set("mode", mode)

  // filtros básicos
  if (f.page) p.set("page", String(f.page))
  if (f.per_page) p.set("per_page", String(f.per_page))
  if (f.sort) p.set("sort", f.sort)
  if (f.search) {
    const raw = f.search.trim()
    const hasLetters = /[A-Za-zÀ-ú]/.test(raw)
    const normalized = hasLetters ? raw : raw.replace(/\D/g, "")
    p.set("search", normalized)
  }
  if (mode === "360" && f.selected_banks?.length) {
    p.set("selected_banks", f.selected_banks.join(","))
    p.set("bank_combination_mode", f.bank_combination_mode || "any")
  }

  // FGTS-only
  if (supportsFgtsFilters) {
    if (f.motivos?.length) p.set("motivos", f.motivos.join(","))
    if (f.origens_hig?.length) p.set("origens_hig", f.origens_hig.join(","))
    if (f.date_from) p.set("date_from", f.date_from)
    if (f.date_to) p.set("date_to", f.date_to)
    if (f.contract_from) p.set("contract_from", f.contract_from)
    if (f.contract_to) p.set("contract_to", f.contract_to)
  }
  // comum aos dois modos
  if (f.origens?.length) p.set("origens", f.origens.join(","))

  // ➕ FGTS OFF (só no FGTS; no CLT o back ignora)
  if (supportsFgtsFilters) {
    if (f.fgts_status) p.set("fgts_status", f.fgts_status)
    if (f.fgts_consulta_from) p.set("fgts_consulta_from", f.fgts_consulta_from)
    if (f.fgts_consulta_to) p.set("fgts_consulta_to", f.fgts_consulta_to)
  }

  // ➕ CLT – somente quando mode = "facta"
  if (supportsCltFilters) {
    if (f.facta_situacao) p.set("facta_situacao", f.facta_situacao)
    if (f.facta_consulta_from) p.set("facta_consulta_from", f.facta_consulta_from)
    if (f.facta_consulta_to) p.set("facta_consulta_to", f.facta_consulta_to)

    if (f.facta_admissao_from) p.set("facta_admissao_from", f.facta_admissao_from)
    if (f.facta_admissao_to) p.set("facta_admissao_to", f.facta_admissao_to)
    if (f.facta_meses_min !== undefined && f.facta_meses_min !== "") p.set("facta_meses_min", String(f.facta_meses_min))
    if (f.facta_meses_max !== undefined && f.facta_meses_max !== "") p.set("facta_meses_max", String(f.facta_meses_max))
    if (f.facta_inicio_empregador_from) p.set("facta_inicio_empregador_from", f.facta_inicio_empregador_from)
    if (f.facta_inicio_empregador_to) p.set("facta_inicio_empregador_to", f.facta_inicio_empregador_to)
    if (f.facta_categoria_codigos?.length) p.set("facta_categoria_codigos", f.facta_categoria_codigos.join(","))

    if (f.facta_idade_min !== undefined && f.facta_idade_min !== "") p.set("facta_idade_min", String(f.facta_idade_min))
    if (f.facta_idade_max !== undefined && f.facta_idade_max !== "") p.set("facta_idade_max", String(f.facta_idade_max))
    if (f.facta_sexo?.length) p.set("facta_sexo", f.facta_sexo.join(","))

    if (f.facta_renda_min) p.set("facta_renda_min", f.facta_renda_min)
    if (f.facta_renda_max) p.set("facta_renda_max", f.facta_renda_max)
    if (f.facta_base_min) p.set("facta_base_min", f.facta_base_min)
    if (f.facta_base_max) p.set("facta_base_max", f.facta_base_max)
    if (f.facta_margem_min) p.set("facta_margem_min", f.facta_margem_min)
    if (f.facta_margem_max) p.set("facta_margem_max", f.facta_margem_max)
    if (f.facta_numero_parcelas_min !== undefined && f.facta_numero_parcelas_min !== "") p.set("facta_numero_parcelas_min", String(f.facta_numero_parcelas_min))
    if (f.facta_numero_parcelas_max !== undefined && f.facta_numero_parcelas_max !== "") p.set("facta_numero_parcelas_max", String(f.facta_numero_parcelas_max))
    if (f.facta_prestacao_min) p.set("facta_prestacao_min", f.facta_prestacao_min)
    if (f.facta_prestacao_max) p.set("facta_prestacao_max", f.facta_prestacao_max)

    if (f.facta_ativos_min !== undefined && f.facta_ativos_min !== "") p.set("facta_ativos_min", String(f.facta_ativos_min))
    if (f.facta_ativos_max !== undefined && f.facta_ativos_max !== "") p.set("facta_ativos_max", String(f.facta_ativos_max))
    if (f.facta_tem_ativos) p.set("facta_tem_ativos", f.facta_tem_ativos)

    if (f.facta_tem_legados) p.set("facta_tem_legados", f.facta_tem_legados)
  }

  // ➕ MERCANTIL – somente quando mode = "mercantil"
  if (supportsMercantilFilters) {
    if (f.mercantil_situacao) p.set("mercantil_situacao", f.mercantil_situacao)
    if (f.mercantil_status?.length) p.set("mercantil_status", f.mercantil_status.join(","))
    if (f.mercantil_consulta_from) p.set("mercantil_consulta_from", f.mercantil_consulta_from)
    if (f.mercantil_consulta_to) p.set("mercantil_consulta_to", f.mercantil_consulta_to)
    if (f.mercantil_valor_parcela_min || f.mercantil_parcela_min) p.set("mercantil_parcela_min", f.mercantil_valor_parcela_min || f.mercantil_parcela_min || "")
    if (f.mercantil_valor_parcela_max || f.mercantil_parcela_max) p.set("mercantil_parcela_max", f.mercantil_valor_parcela_max || f.mercantil_parcela_max || "")
    if (f.mercantil_numero_parcelas_min !== undefined && f.mercantil_numero_parcelas_min !== "")
      p.set("mercantil_qtd_parcelas_min", String(f.mercantil_numero_parcelas_min))
    else if (f.mercantil_qtd_parcelas_min !== undefined && f.mercantil_qtd_parcelas_min !== "")
      p.set("mercantil_qtd_parcelas_min", String(f.mercantil_qtd_parcelas_min))
    if (f.mercantil_numero_parcelas_max !== undefined && f.mercantil_numero_parcelas_max !== "")
      p.set("mercantil_qtd_parcelas_max", String(f.mercantil_numero_parcelas_max))
    else if (f.mercantil_qtd_parcelas_max !== undefined && f.mercantil_qtd_parcelas_max !== "")
      p.set("mercantil_qtd_parcelas_max", String(f.mercantil_qtd_parcelas_max))
    if (f.mercantil_origens?.length) p.set("mercantil_origens", f.mercantil_origens.join(","))
  }

  if (supportsUy3Filters) {
    if (f.uy3_situacao) p.set("uy3_situacao", f.uy3_situacao)
    if (f.uy3_consulta_from) p.set("uy3_consulta_from", f.uy3_consulta_from)
    if (f.uy3_consulta_to) p.set("uy3_consulta_to", f.uy3_consulta_to)
    if (f.uy3_meses_admissao_min !== undefined && f.uy3_meses_admissao_min !== "")
      p.set("uy3_meses_admissao_min", String(f.uy3_meses_admissao_min))
    if (f.uy3_meses_admissao_max !== undefined && f.uy3_meses_admissao_max !== "")
      p.set("uy3_meses_admissao_max", String(f.uy3_meses_admissao_max))
    if (f.uy3_margem_min) p.set("uy3_margem_min", f.uy3_margem_min)
    if (f.uy3_margem_max) p.set("uy3_margem_max", f.uy3_margem_max)
    if (f.uy3_valor_liberado_min) p.set("uy3_valor_liberado_min", f.uy3_valor_liberado_min)
    if (f.uy3_valor_liberado_max) p.set("uy3_valor_liberado_max", f.uy3_valor_liberado_max)
    if (f.uy3_numero_parcelas_min !== undefined && f.uy3_numero_parcelas_min !== "")
      p.set("uy3_numero_parcelas_min", String(f.uy3_numero_parcelas_min))
    if (f.uy3_numero_parcelas_max !== undefined && f.uy3_numero_parcelas_max !== "")
      p.set("uy3_numero_parcelas_max", String(f.uy3_numero_parcelas_max))
  }

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
  if (f.with_phones) p.set("with_phones", "1")
  if (f.without_phones) p.set("without_phones", "1")
  // Vendors só é relevante no FGTS
  if (mode === "fgts" && f.vendors?.length) p.set("vendors", f.vendors.join(","))

  // 🎂 mês(es) de aniversário
  const months = normalizeMonths(f.birth_month)
  if (months.length) p.set("birth_month", months.join(","))

  return p
}

const shouldUsePost = (filters: LeadFilters, mode: Mode) => {
  const params = buildQueryParams(filters, mode)
  const urlPreview = `/leads?${params.toString()}`
  if (urlPreview.length > 1500) return true

  const cpfCount = filters.cpf ? splitAndNormalize(filters.cpf, true).length : 0
  const namesCount = filters.names ? splitAndNormalize(filters.names, false).length : 0
  const phonesCount = filters.phones ? splitAndNormalize(filters.phones, true).length : 0
  const totalMass = cpfCount + namesCount + phonesCount
  return totalMass > 200
}

/* ---------- Endpoints: lista ---------- */
export async function fetchLeadsBase(filters: LeadFilters) {
  const mode: Mode = "base"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      sort: filters.sort || undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponseBase>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponseBase>("/leads", {
    params,
  })
  return data
}

export async function fetchLeadsFGTS(filters: LeadFilters) {
  const mode: Mode = "fgts"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      sort: filters.sort || undefined,
      motivos: filters.motivos?.length ? filters.motivos : undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      origens_hig: filters.origens_hig?.length ? filters.origens_hig : undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      contract_from: filters.contract_from || undefined,
      contract_to: filters.contract_to || undefined,
      vendors: filters.vendors?.length ? filters.vendors : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,
      fgts_status: filters.fgts_status || undefined,
      fgts_consulta_from: filters.fgts_consulta_from || undefined,
      fgts_consulta_to: filters.fgts_consulta_to || undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponseFGTS>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponseFGTS>("/leads", {
    params,
  })
  return data
}

export async function fetchLeadsCLT(filters: LeadFilters) {
  const mode: Mode = "facta"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      sort: filters.sort || undefined,
      // CLT: apenas o que faz sentido
      origens: filters.origens?.length ? filters.origens : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,

      // ➕ CLT
      facta_situacao: filters.facta_situacao || undefined,
      facta_consulta_from: filters.facta_consulta_from || undefined,
      facta_consulta_to: filters.facta_consulta_to || undefined,
      facta_admissao_from: filters.facta_admissao_from || undefined,
      facta_admissao_to: filters.facta_admissao_to || undefined,
      facta_meses_min: filters.facta_meses_min ?? undefined,
      facta_meses_max: filters.facta_meses_max ?? undefined,
      facta_inicio_empregador_from: filters.facta_inicio_empregador_from || undefined,
      facta_inicio_empregador_to: filters.facta_inicio_empregador_to || undefined,
      facta_categoria_codigos: filters.facta_categoria_codigos?.length ? filters.facta_categoria_codigos : undefined,
      facta_idade_min: filters.facta_idade_min ?? undefined,
      facta_idade_max: filters.facta_idade_max ?? undefined,
      facta_sexo: filters.facta_sexo?.length ? filters.facta_sexo : undefined,
      facta_renda_min: filters.facta_renda_min || undefined,
      facta_renda_max: filters.facta_renda_max || undefined,
      facta_base_min: filters.facta_base_min || undefined,
      facta_base_max: filters.facta_base_max || undefined,
      facta_margem_min: filters.facta_margem_min || undefined,
      facta_margem_max: filters.facta_margem_max || undefined,
      facta_prestacao_min: filters.facta_prestacao_min || undefined,
      facta_prestacao_max: filters.facta_prestacao_max || undefined,
      facta_ativos_min: filters.facta_ativos_min ?? undefined,
      facta_ativos_max: filters.facta_ativos_max ?? undefined,
      facta_tem_ativos: filters.facta_tem_ativos || undefined,
      facta_tem_legados: filters.facta_tem_legados || undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponseFacta>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponseFacta>("/leads", {
    params,
  })
  return data
}

export async function fetchLeadsMercantil(filters: LeadFilters) {
  const mode: Mode = "mercantil"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      sort: filters.sort || undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,

      mercantil_status: filters.mercantil_status?.length ? filters.mercantil_status : undefined,
      mercantil_consulta_from: filters.mercantil_consulta_from || undefined,
      mercantil_consulta_to: filters.mercantil_consulta_to || undefined,
      mercantil_parcela_min: filters.mercantil_parcela_min || undefined,
      mercantil_parcela_max: filters.mercantil_parcela_max || undefined,
      mercantil_qtd_parcelas_min: filters.mercantil_qtd_parcelas_min ?? undefined,
      mercantil_qtd_parcelas_max: filters.mercantil_qtd_parcelas_max ?? undefined,
      mercantil_origens: filters.mercantil_origens?.length ? filters.mercantil_origens : undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponseMercantil>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponseMercantil>("/leads", {
    params,
  })
  return data
}

export async function fetchLeadsUy3(filters: LeadFilters) {
  const mode: Mode = "uy3"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      sort: filters.sort || undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponseUY3>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponseUY3>("/leads", {
    params,
  })
  return data
}

export async function fetchLeads360(filters: LeadFilters, signal?: AbortSignal) {
  const mode: Mode = "360"
  const pageParams = {
    ...(filters.page ? { page: filters.page } : {}),
    ...(filters.per_page ? { per_page: filters.per_page } : {}),
  }
  if (shouldUsePost(filters, mode)) {
    const months = normalizeMonths(filters.birth_month)
    const payload: any = {
      mode,
      search: filters.search?.trim() || undefined,
      selected_banks: filters.selected_banks?.length ? filters.selected_banks : undefined,
      bank_combination_mode: filters.selected_banks?.length ? (filters.bank_combination_mode || "any") : undefined,
      sort: filters.sort || undefined,
      origens: filters.origens?.length ? filters.origens : undefined,
      birth_month: months.length ? months : undefined,
      with_phones: filters.with_phones || undefined,
      without_phones: filters.without_phones || undefined,
      cpf: filters.cpf ? splitAndNormalize(filters.cpf, true) : undefined,
      names: filters.names ? splitAndNormalize(filters.names, false) : undefined,
      phones: filters.phones ? splitAndNormalize(filters.phones, true) : undefined,
      motivos: filters.motivos?.length ? filters.motivos : undefined,
      origens_hig: filters.origens_hig?.length ? filters.origens_hig : undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      contract_from: filters.contract_from || undefined,
      contract_to: filters.contract_to || undefined,
      vendors: filters.vendors?.length ? filters.vendors : undefined,
      fgts_status: filters.fgts_status || undefined,
      fgts_consulta_from: filters.fgts_consulta_from || undefined,
      fgts_consulta_to: filters.fgts_consulta_to || undefined,
      facta_situacao: filters.facta_situacao || undefined,
      facta_consulta_from: filters.facta_consulta_from || undefined,
      facta_consulta_to: filters.facta_consulta_to || undefined,
      facta_admissao_from: filters.facta_admissao_from || undefined,
      facta_admissao_to: filters.facta_admissao_to || undefined,
      facta_meses_min: filters.facta_meses_min ?? undefined,
      facta_meses_max: filters.facta_meses_max ?? undefined,
      facta_inicio_empregador_from: filters.facta_inicio_empregador_from || undefined,
      facta_inicio_empregador_to: filters.facta_inicio_empregador_to || undefined,
      facta_categoria_codigos: filters.facta_categoria_codigos?.length ? filters.facta_categoria_codigos : undefined,
      facta_idade_min: filters.facta_idade_min ?? undefined,
      facta_idade_max: filters.facta_idade_max ?? undefined,
      facta_sexo: filters.facta_sexo?.length ? filters.facta_sexo : undefined,
      facta_renda_min: filters.facta_renda_min || undefined,
      facta_renda_max: filters.facta_renda_max || undefined,
      facta_base_min: filters.facta_base_min || undefined,
      facta_base_max: filters.facta_base_max || undefined,
      facta_margem_min: filters.facta_margem_min || undefined,
      facta_margem_max: filters.facta_margem_max || undefined,
      facta_prestacao_min: filters.facta_prestacao_min || undefined,
      facta_prestacao_max: filters.facta_prestacao_max || undefined,
      facta_ativos_min: filters.facta_ativos_min ?? undefined,
      facta_ativos_max: filters.facta_ativos_max ?? undefined,
      facta_tem_ativos: filters.facta_tem_ativos || undefined,
      facta_tem_legados: filters.facta_tem_legados || undefined,
      mercantil_situacao: filters.mercantil_situacao || undefined,
      mercantil_status: filters.mercantil_status?.length ? filters.mercantil_status : undefined,
      mercantil_consulta_from: filters.mercantil_consulta_from || undefined,
      mercantil_consulta_to: filters.mercantil_consulta_to || undefined,
      mercantil_parcela_min: filters.mercantil_parcela_min || undefined,
      mercantil_parcela_max: filters.mercantil_parcela_max || undefined,
      mercantil_qtd_parcelas_min: filters.mercantil_qtd_parcelas_min ?? undefined,
      mercantil_qtd_parcelas_max: filters.mercantil_qtd_parcelas_max ?? undefined,
      mercantil_origens: filters.mercantil_origens?.length ? filters.mercantil_origens : undefined,
      uy3_situacao: filters.uy3_situacao || undefined,
      uy3_consulta_from: filters.uy3_consulta_from || undefined,
      uy3_consulta_to: filters.uy3_consulta_to || undefined,
      uy3_meses_admissao_min: filters.uy3_meses_admissao_min ?? undefined,
      uy3_meses_admissao_max: filters.uy3_meses_admissao_max ?? undefined,
      uy3_margem_min: filters.uy3_margem_min || undefined,
      uy3_margem_max: filters.uy3_margem_max || undefined,
      uy3_valor_liberado_min: filters.uy3_valor_liberado_min || undefined,
      uy3_valor_liberado_max: filters.uy3_valor_liberado_max || undefined,
      uy3_numero_parcelas_min: filters.uy3_numero_parcelas_min ?? undefined,
      uy3_numero_parcelas_max: filters.uy3_numero_parcelas_max ?? undefined,
    }
    const { data } = await axiosClient.post<PaginatedLeadsResponse360>(
      "/leads/search",
      payload,
      { params: Object.keys(pageParams).length ? pageParams : undefined, signal, timeout: LEADS_360_TIMEOUT_MS }
    )
    return data
  }

  const params = buildQueryParams(filters, mode)
  const { data } = await axiosClient.get<PaginatedLeadsResponse360>("/leads", {
    params,
    signal,
    timeout: LEADS_360_TIMEOUT_MS,
  })
  return data
}

export async function fetchLeadDetail(id: number) {
  const { data } = await axiosClient.get<LeadDetailFromApi>(`/leads/${id}`)
  return data
}

export interface FiltersOptionsDTO {
  motivos: string[]
  origens: string[]       // últimas cadastrais
  origens_hig: string[]   // últimas higienização
  origens_mercantil: string[]
  mercantil_status: string[]
  vendors: { id: number; name: string }[]
}

export async function fetchLeadsFilters() {
  const { data } = await axiosClient.get<FiltersOptionsDTO>("/leads/filters")
  return data
}

/** Normaliza filtros críticos para o POST do export */
function normalizeFiltersForExport(filters: LeadFilters): LeadFilters {
  const normalized: LeadFilters = { ...filters }
  normalized.birth_month = normalizeMonths(filters.birth_month)
  return normalized
}

/* ======================= EXPORT ASSÍNCRONO ======================= */

export type LeadsExportStatus =
  | "queued"
  | "running"
  | "ready"
  | "error"
  | "none"
  | "deleted"

export interface LeadsExportStatusDTO {
  status: LeadsExportStatus
  message?: string
  filename?: string | null
  size_bytes?: number
  updated_at?: string
}

/** Inicia o export e retorna o token. */
export async function startLeadsExport(
  filters: LeadFilters,
  columns: string[],
  mode: "base" | "fgts" | "facta" | "mercantil" | "uy3" | "360"
): Promise<{ token: string }> {
  const payload = { ...normalizeFiltersForExport(filters), columns, mode }
  const { data } = await axiosClient.post<{ token: string; status: LeadsExportStatus }>("/leads/export", payload)
  return { token: data.token }
}

/** Consulta status pelo token. */
export async function getLeadsExportStatus(token: string): Promise<LeadsExportStatusDTO> {
  const { data } = await axiosClient.get<LeadsExportStatusDTO>(`/leads/export/${token}`)
  return data
}

export async function downloadLeadsExport(token: string): Promise<void> {
  const response = await axiosClient.get(`/leads/export/${token}/download`, {
    responseType: "blob",
  })

  const ct = (response.headers["content-type"] as string | undefined) || "text/csv;charset=utf-8"
  const blob = new Blob([response.data], { type: ct })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement("a")

  const cd = response.headers["content-disposition"] as string | undefined
  let filename = "leads_export.csv" // <- fallback agora CSV
  if (cd) {
    const star = cd.match(/filename\*\=([^;]+)/i)
    if (star?.[1]) {
      try {
        const v = star[1].trim()
        const parts = v.split("''")
        filename = decodeURIComponent(parts.pop() || filename)
      } catch { }
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

/* ============ Poller Singleton: persistência e retomada ============ */

type ExportRecord = {
  token: string
  startedAt: number
}

const LS_KEY = "leads:export:active:v1"

function loadActive(): ExportRecord[] {
  try {
    const raw = localStorage.getItem(LS_KEY)
    if (!raw) return []
    const arr = JSON.parse(raw)
    if (!Array.isArray(arr)) return []
    return arr.filter(x => x && typeof x.token === "string")
  } catch { return [] }
}

function saveActive(list: ExportRecord[]) {
  try { localStorage.setItem(LS_KEY, JSON.stringify(list)) } catch { }
}

function addActive(token: string) {
  const list = loadActive()
  if (!list.find(x => x.token === token)) {
    list.push({ token, startedAt: Date.now() })
    saveActive(list)
  }
}

function removeActive(token: string) {
  const list = loadActive().filter(x => x.token !== token)
  saveActive(list)
}

type Listener = (e: { token: string; status: LeadsExportStatusDTO }) => void

class LeadsExportPoller {
  private timers = new Map<string, number>()
  private listeners = new Set<Listener>()
  private backoff = new Map<string, { attempts: number; interval: number }>()

  on(l: Listener) { this.listeners.add(l) }
  off(l: Listener) { this.listeners.delete(l) }

  private emit(token: string, status: LeadsExportStatusDTO) {
    for (const l of this.listeners) l({ token, status })
  }

  start(token: string) {
    if (this.timers.has(token)) return
    addActive(token)
    this.backoff.set(token, { attempts: 0, interval: 2000 })

    const tick = async () => {
      try {
        const st = await getLeadsExportStatus(token)
        this.emit(token, st)

        if (st.status === "ready" || st.status === "error" || st.status === "deleted") {
          this.stop(token)
          return
        }
      } catch (err: any) {
        // Se o status endpoint 404, o export expirou/foi limpo no back. Remover do LS e avisar UI.
        const http = err?.response?.status
        if (http === 404) {
          this.emit(token, { status: "deleted", message: "Token expirado ou não encontrado (404)." })
          this.stop(token) // stop() também remove do localStorage
          return
        }
        // Falha transitória: mantém polling
      }

      const cfg = this.backoff.get(token)!
      cfg.attempts += 1
      if (cfg.attempts % 10 === 0 && cfg.interval < 5000) cfg.interval += 500
      this.backoff.set(token, cfg)
      const id = window.setTimeout(tick, cfg.interval)
      this.timers.set(token, id)
    }

    const id = window.setTimeout(tick, 0)
    this.timers.set(token, id)
  }

  stop(token: string) {
    const t = this.timers.get(token)
    if (t) window.clearTimeout(t)
    this.timers.delete(token)
    this.backoff.delete(token)
    removeActive(token) // <- garante remoção do localStorage
  }

  resumeAll() {
    const list = loadActive()
    for (const { token } of list) this.start(token)
  }

  clearAll() {
    for (const token of Array.from(this.timers.keys())) this.stop(token)
    saveActive([])
  }
}

export const leadsExportPoller = new LeadsExportPoller()
