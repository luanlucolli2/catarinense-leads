import { useState, useMemo, useEffect } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"
import { usePersistedState } from "@/hooks/usePersistedState"

import {
  LeadsTableFGTS,
  LeadsTableCLT,
  LeadsTableMercantil,
  LeadsTableUy3,
  ProcessedLeadFGTS,
  ProcessedLeadCLT,
  ProcessedLeadMercantil,
  ProcessedLeadUy3,
} from "@/components/LeadsTable"
import { LeadsTable360, ProcessedLead360 } from "@/components/LeadsTable360"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import {
  fetchLeadsBase,
  fetchLeadsFGTS,
  fetchLeadsCLT,
  fetchLeadsMercantil,
  fetchLeadsUy3,
  fetchLeads360,
  fetchLeadsFilters,
  // export async + poller
  startLeadsExport,
  downloadLeadsExport,
  leadsExportPoller,
  LeadBankKey,
  LeadBankCombinationMode,
  LeadFromApiBase,
  LeadFromApiFGTS,
  LeadFromApiCLT,
  LeadFromApiMercantil,
  LeadFromApiUY3,
  LeadFromApi360,
  LeadFilters,
  LeadSort,
  PaginatedLeadsResponseBase,
  PaginatedLeadsResponseFGTS,
  PaginatedLeadsResponseCLT,
  PaginatedLeadsResponseMercantil,
  PaginatedLeadsResponseUY3,
  PaginatedLeadsResponse360,
  LeadsExportStatusDTO,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatLocalDateTime,
  formatPhone,
  formatDateOnly
} from "@/lib/formatters"

type StatusFilter = "todos" | "elegiveis" | "nao-elegiveis"
type FgtsStatusFilter = "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"
type YesNoAll = "todos" | "sim" | "nao"
type CltSituacaoFilter = "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado"
type Uy3SituacaoFilter = "todos" | "aprovado" | "nao_aprovado"
type MercantilSituacao360Filter = "todos" | "aprovado" | "nao_aprovado"
type ActiveTab = "360" | "BASE" | "FGTS" | "CLT" | "MERCANTIL" | "UY3"

type Dashboard360Filters = {
  search: string
  origens: string[]
  cpf: string
  names: string
  phones: string
  with_phones: boolean
  without_phones: boolean
  birth_month: string[]
  selected_banks: LeadBankKey[]
  bank_combination_mode: LeadBankCombinationMode
  motivos: string[]
  origens_hig: string[]
  date_from: string
  date_to: string
  contract_from: string
  contract_to: string
  vendors: string[]
  fgts_status: FgtsStatusFilter
  fgts_consulta_from: string
  fgts_consulta_to: string
  clt_situacao: CltSituacaoFilter
  clt_consulta_from: string
  clt_consulta_to: string
  clt_admissao_from: string
  clt_admissao_to: string
  clt_meses_min: string
  clt_meses_max: string
  clt_inicio_empregador_from: string
  clt_inicio_empregador_to: string
  clt_categoria_codigos: string
  clt_idade_min: string
  clt_idade_max: string
  clt_sexo: string[]
  clt_renda_min: string
  clt_renda_max: string
  clt_base_min: string
  clt_base_max: string
  clt_margem_min: string
  clt_margem_max: string
  clt_prestacao_min: string
  clt_prestacao_max: string
  clt_ativos_min: string
  clt_ativos_max: string
  clt_tem_ativos: YesNoAll
  clt_tem_legados: YesNoAll
  mercantil_situacao: MercantilSituacao360Filter
  mercantil_status: string[]
  mercantil_consulta_from: string
  mercantil_consulta_to: string
  mercantil_parcela_min: string
  mercantil_parcela_max: string
  mercantil_qtd_parcelas_min: string
  mercantil_qtd_parcelas_max: string
  mercantil_origens: string[]
  uy3_situacao: Uy3SituacaoFilter
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

const BASE_SORT_DEFAULT: LeadSort = "lead_updated_at"
const DASHBOARD_360_SORT_DEFAULT: LeadSort = "lead_updated_at"
const CLT_SORT_DEFAULT: LeadSort = "clt_consulted_at"
const MERCANTIL_SORT_DEFAULT: LeadSort = "mercantil_consulted_at"
const UY3_SORT_DEFAULT: LeadSort = "uy3_consulted_at"

export const BASE_COLUMNS_DEFAULT: string[] = [
  "cpf",
  "nome",
  "data_nascimento",
];

// SUBSTITUA a constante FGTS_COLUMNS_DEFAULT por:
export const FGTS_COLUMNS_DEFAULT: string[] = [
  "cpf",
  "nome",
  "data_nascimento",
  "telefone_1",
  "classe_1",
  "consulta",
  "saldo",
  "libera",
  "data_atualizacao",
  // 🆕 último contrato
  "data_contrato_recente",
  "vendedor",
  "fgts_off_authorized",
  "fgts_off_consultado_em",
  "contratos",
  "ultima_origem_cadastral",
  "ultima_origem_higienizacao",
];

export const CLT_COLUMNS_DEFAULT: string[] = [
  "cpf", "nome", "data_nascimento", "telefone_1", "classe_1",
  "idade", "sexo",
  "elegivel",
  "clt_consultado_em",
  "clt_dados_atualizados_em", // 🆕
  "data_admissao", "meses_admissao", "categoria_trabalhador_codigo", "matricula",
  "valor_renda", "valor_base_margem", "margem_disponivel", "valor_max_prestacao",
  "politica_credito_aprovado", "politica_credito_mensagem",
  "politica_credito_valor_maximo_disponivel", "politica_credito_prazo_maximo_disponivel",
  "politica_credito_data_consulta", "politica_credito_tabela_aprovada",
  "qtd_emprestimos_ativos_suspensos", "emprestimos_legados", "ultima_origem_cadastral",
];

export const MERCANTIL_COLUMNS_DEFAULT: string[] = [
  "cpf",
  "nome",
  "data_nascimento",
  "telefone_1",
  "classe_1",
  "mercantil_status",
  "mercantil_data_hora_origem",
  "mercantil_mensagem_erro",
  "mercantil_valor_emprestimo",
  "mercantil_valor_iof",
  "mercantil_valor_financiado",
  "mercantil_valor_liberado",
  "mercantil_data_primeiro_vencimento",
  "mercantil_quantidade_parcelas",
  "mercantil_valor_parcela",
  "mercantil_taxa_juros_mes",
  "ultima_origem_cadastral",
  "ultima_origem_mercantil",
]

export const UY3_COLUMNS_DEFAULT: string[] = [
  "cpf",
  "nome",
  "data_nascimento",
  "telefone_1",
  "classe_1",
  "uy3_type_webhook",
  "uy3_status",
  "uy3_consultado_em",
  "uy3_data_admissao",
  "uy3_elegivel_emprestimo",
  "uy3_margem_disponivel",
  "uy3_valor_liberado",
  "uy3_numero_parcelas",
  "uy3_is_mei",
  "uy3_is_judicial_recovery",
  "ultima_origem_cadastral",
]

export const DASHBOARD_360_COLUMNS_DEFAULT: string[] = [
  "cpf",
  "nome",
  "telefone_1",
  "politica_credito_aprovado",
  "mercantil_status",
  "uy3_elegivel_emprestimo",
  "margem_disponivel",
  "mercantil_valor_liberado",
  "uy3_valor_liberado",
  "clt_consultado_em",
  "mercantil_data_hora_origem",
  "uy3_consultado_em",
]

const DASHBOARD_360_COLUMNS_STORAGE_KEY = "leadstable:360:visibleColumns:v6"

const DASHBOARD_360_COLUMNS_LEGACY_STORAGE_KEYS = [
  "leadstable:360:visibleColumns:v5",
  "leadstable:360:visibleColumns:v4",
  "leadstable:360:visibleColumns:v3",
  "leadstable:360:visibleColumns:v2",
  "leadstable:360:visibleColumns:v1",
]

const resolveDashboard360DefaultColumns = (): string[] => {
  if (typeof window === "undefined") return DASHBOARD_360_COLUMNS_DEFAULT

  const current = window.localStorage.getItem(DASHBOARD_360_COLUMNS_STORAGE_KEY)

  if (current) {
    try {
      const parsed = JSON.parse(current)
      if (Array.isArray(parsed) && parsed.every((value) => typeof value === "string")) {
        return parsed
      }
    } catch {
      // Ignore invalid persisted value and fall back to the new default.
    }
  }

  DASHBOARD_360_COLUMNS_LEGACY_STORAGE_KEYS.forEach((key) => window.localStorage.removeItem(key))

  return DASHBOARD_360_COLUMNS_DEFAULT
}

const DASHBOARD_360_FILTERS_DEFAULT: Dashboard360Filters = {
  search: "",
  origens: [],
  cpf: "",
  names: "",
  phones: "",
  with_phones: false,
  without_phones: false,
  birth_month: [],
  selected_banks: [],
  bank_combination_mode: "any",
  motivos: [],
  origens_hig: [],
  date_from: "",
  date_to: "",
  contract_from: "",
  contract_to: "",
  vendors: [],
  fgts_status: "todos",
  fgts_consulta_from: "",
  fgts_consulta_to: "",
  clt_situacao: "todos",
  clt_consulta_from: "",
  clt_consulta_to: "",
  clt_admissao_from: "",
  clt_admissao_to: "",
  clt_meses_min: "",
  clt_meses_max: "",
  clt_inicio_empregador_from: "",
  clt_inicio_empregador_to: "",
  clt_categoria_codigos: "",
  clt_idade_min: "",
  clt_idade_max: "",
  clt_sexo: [],
  clt_renda_min: "",
  clt_renda_max: "",
  clt_base_min: "",
  clt_base_max: "",
  clt_margem_min: "",
  clt_margem_max: "",
  clt_prestacao_min: "",
  clt_prestacao_max: "",
  clt_ativos_min: "",
  clt_ativos_max: "",
  clt_tem_ativos: "todos",
  clt_tem_legados: "todos",
  mercantil_situacao: "todos",
  mercantil_status: [],
  mercantil_consulta_from: "",
  mercantil_consulta_to: "",
  mercantil_parcela_min: "",
  mercantil_parcela_max: "",
  mercantil_qtd_parcelas_min: "",
  mercantil_qtd_parcelas_max: "",
  mercantil_origens: [],
  uy3_situacao: "todos",
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
}

const DASHBOARD_360_EXPORT_COLUMN_MAP: Record<string, string> = {
  cpf: "cpf",
  nome: "nome",
  created_at: "created_at",
  updated_at: "updated_at",
  data_nascimento: "data_nascimento",
  telefone_1: "fone1",
  classe_1: "classe_fone1",
  telefone_2: "fone2",
  classe_2: "classe_fone2",
  telefone_3: "fone3",
  classe_3: "classe_fone3",
  telefone_4: "fone4",
  classe_4: "classe_fone4",
  consulta: "consulta",
  saldo: "saldo",
  libera: "libera",
  data_atualizacao: "data_atualizacao",
  contratos: "contracts_count",
  data_contrato_recente: "data_contrato_recente",
  vendedor: "vendedor",
  fgts_off_authorized: "fgts_off_authorized",
  fgts_off_consultado_em: "fgts_off_consultado_em",
  ultima_origem_cadastral: "ultima_origem_cadastral",
  ultima_origem_higienizacao: "ultima_origem_higienizacao",
  elegivel: "elegivel",
  not_found: "not_found",
  margem_disponivel: "margem_disponivel",
  politica_credito_aprovado: "politica_credito_aprovado",
  clt_consultado_em: "clt_consultado_em",
  clt_dados_atualizados_em: "clt_dados_atualizados_em",
  mercantil_status: "mercantil_status",
  mercantil_mensagem_erro: "mercantil_mensagem_erro",
  mercantil_data_hora_origem: "mercantil_data_hora_origem",
  mercantil_valor_financiado: "mercantil_valor_financiado",
  mercantil_valor_iof: "mercantil_valor_iof",
  mercantil_data_primeiro_vencimento: "mercantil_data_primeiro_vencimento",
  mercantil_valor_emprestimo: "mercantil_valor_emprestimo",
  mercantil_quantidade_parcelas: "mercantil_quantidade_parcelas",
  mercantil_valor_liberado: "mercantil_valor_liberado",
  mercantil_taxa_juros_mes: "mercantil_taxa_juros_mes",
  mercantil_valor_parcela: "mercantil_valor_parcela",
  ultima_origem_mercantil: "ultima_origem_mercantil",
  uy3_type_webhook: "uy3_type_webhook",
  uy3_status: "uy3_status",
  uy3_consultado_em: "uy3_consultado_em",
  uy3_data_admissao: "uy3_data_admissao",
  uy3_valor_liberado: "uy3_valor_liberado",
  uy3_numero_parcelas: "uy3_numero_parcelas",
  uy3_codigo_requisicao: "uy3_codigo_requisicao",
  uy3_margem_disponivel: "uy3_margem_disponivel",
  uy3_elegivel_emprestimo: "uy3_elegivel_emprestimo",
  uy3_numero_inscricao_empregador: "uy3_numero_inscricao_empregador",
  uy3_pessoa_exposta_politicamente_codigo: "uy3_pessoa_exposta_politicamente_codigo",
  uy3_data_hora_validade_solicitacao: "uy3_data_hora_validade_solicitacao",
  uy3_is_mei: "uy3_is_mei",
  uy3_is_judicial_recovery: "uy3_is_judicial_recovery",
}

const Dashboard = () => {
  const [activeTab, setActiveTab] = usePersistedState<ActiveTab>("dashboard:activeTab", "360")
  const [currentPage, setCurrentPage] = useState(1)

  const [dashboard360VisibleColumns, setDashboard360VisibleColumns] = usePersistedState<string[]>(
    DASHBOARD_360_COLUMNS_STORAGE_KEY,
    resolveDashboard360DefaultColumns()
  )
  const [dashboard360SearchValue, setDashboard360SearchValue] = usePersistedState<string>("dashboard-360:searchValue", "")
  const [dashboard360SortBy, setDashboard360SortBy] = usePersistedState<LeadSort>("dashboard-360:sortBy:v1", DASHBOARD_360_SORT_DEFAULT)
  const [dashboard360StickyIdentityColumns, setDashboard360StickyIdentityColumns] = usePersistedState<boolean>(
    "dashboard-360:stickyIdentityColumns:v1",
    true
  )
  const [dashboard360Filters, setDashboard360Filters] = usePersistedState<Dashboard360Filters>(
    "dashboard-360:filters:v1",
    DASHBOARD_360_FILTERS_DEFAULT
  )

  const [baseVisibleColumns, setBaseVisibleColumns] = usePersistedState<string[]>(
    "leadstable:base:visibleColumns:v1",
    BASE_COLUMNS_DEFAULT
  )
  const [fgtsVisibleColumns, setFgtsVisibleColumns] = usePersistedState<string[]>(
    "leadstable:fgts:visibleColumns:v1",
    FGTS_COLUMNS_DEFAULT
  )
  const [cltVisibleColumns, setCltVisibleColumns] = usePersistedState<string[]>(
    "leadstable:clt:visibleColumns:v2",
    CLT_COLUMNS_DEFAULT
  )
  const [mercantilVisibleColumns, setMercantilVisibleColumns] = usePersistedState<string[]>(
    "leadstable:mercantil:visibleColumns:v1",
    MERCANTIL_COLUMNS_DEFAULT
  )
  const [uy3VisibleColumns, setUy3VisibleColumns] = usePersistedState<string[]>(
    "leadstable:uy3:visibleColumns:v1",
    UY3_COLUMNS_DEFAULT
  )

  const update360Filter = <K extends keyof Dashboard360Filters>(
    key: K,
    value: Dashboard360Filters[K]
  ) => {
    setDashboard360Filters((current) => ({ ...current, [key]: value }))
  }
  const dashboard360SelectedBanks = dashboard360Filters.selected_banks.filter((bank) => bank !== "fgts")

  const [baseSearchValue, setBaseSearchValue] = usePersistedState<string>("dashboard-base:searchValue", "")
  const [baseOrigemFilter, setBaseOrigemFilter] = usePersistedState<string[]>("dashboard-base:origemFilter", [])
  const [baseCpfMassFilter, setBaseCpfMassFilter] = usePersistedState<string>("dashboard-base:cpfMassFilter", "")
  const [baseNamesMassFilter, setBaseNamesMassFilter] = usePersistedState<string>("dashboard-base:namesMassFilter", "")
  const [basePhonesMassFilter, setBasePhonesMassFilter] = usePersistedState<string>("dashboard-base:phonesMassFilter", "")
  const [baseWithPhonesFilter, setBaseWithPhonesFilter] = usePersistedState<boolean>("dashboard-base:withPhonesFilter", false)
  const [baseNoPhonesFilter, setBaseNoPhonesFilter] = usePersistedState<boolean>("dashboard-base:noPhonesFilter", false)
  const [baseBirthMonthFilter, setBaseBirthMonthFilter] = usePersistedState<string[]>("dashboard-base:birthMonthFilter", [])
  const [baseSortBy, setBaseSortBy] = usePersistedState<LeadSort>("dashboard-base:sortBy", BASE_SORT_DEFAULT)

  /* =========================  FGTS (persistido)  ========================= */
  const [searchValue, setSearchValue] = usePersistedState<string>("dashboard:searchValue", "")
  const [statusFilter, setStatusFilter] = usePersistedState<StatusFilter>("dashboard:statusFilter", "todos")
  const [motivosFilter, setMotivosFilter] = usePersistedState<string[]>("dashboard:motivosFilter", [])
  const [origemFilter, setOrigemFilter] = usePersistedState<string[]>("dashboard:origemFilter", [])
  const [higienizacaoFilter, setHigienizacaoFilter] = usePersistedState<string[]>("dashboard:higienizacaoFilter", [])
  const [dateFromFilter, setDateFromFilter] = usePersistedState<string>("dashboard:dateFromFilter", "")
  const [dateToFilter, setDateToFilter] = usePersistedState<string>("dashboard:dateToFilter", "")
  const [contractDateFromFilter, setContractDateFromFilter] = usePersistedState<string>("dashboard:contractDateFromFilter", "")
  const [contractDateToFilter, setContractDateToFilter] = usePersistedState<string>("dashboard:contractDateToFilter", "")
  const [cpfMassFilter, setCpfMassFilter] = usePersistedState<string>("dashboard:cpfMassFilter", "")
  const [namesMassFilter, setNamesMassFilter] = usePersistedState<string>("dashboard:namesMassFilter", "")
  const [phonesMassFilter, setPhonesMassFilter] = usePersistedState<string>("dashboard:phonesMassFilter", "")
  const [withPhonesFilter, setWithPhonesFilter] = usePersistedState<boolean>("dashboard:withPhonesFilter", false)
  const [noPhonesFilter, setNoPhonesFilter] = usePersistedState<boolean>("dashboard:noPhonesFilter", false)
  const [vendorsFilter, setVendorsFilter] = usePersistedState<string[]>("dashboard:vendorsFilter", [])
  const [birthMonthFilter, setBirthMonthFilter] = usePersistedState<string[]>("dashboard:birthMonthFilter", [])

  /** ➕ FGTS OFF */
  const [fgtsAuthorizedFilter, setFgtsAuthorizedFilter] =
    usePersistedState<FgtsStatusFilter>("dashboard:fgtsAuthorizedFilter", "todos")
  const [fgtsConsultaFromFilter, setFgtsConsultaFromFilter] =
    usePersistedState<string>("dashboard:fgtsConsultaFromFilter", "")
  const [fgtsConsultaToFilter, setFgtsConsultaToFilter] =
    usePersistedState<string>("dashboard:fgtsConsultaToFilter", "")

  /* =========================  CLT (persistido)  ========================= */
  const [cltSearchValue, setCltSearchValue] = usePersistedState<string>("dashboard-clt:searchValue", "")
  const [cltStatusFilter, setCltStatusFilter] = usePersistedState<StatusFilter>("dashboard-clt:statusFilter", "todos")
  const [cltMotivosFilter, setCltMotivosFilter] = usePersistedState<string[]>("dashboard-clt:motivosFilter", [])
  const [cltOrigemFilter, setCltOrigemFilter] = usePersistedState<string[]>("dashboard-clt:origemFilter", [])
  const [cltHigienizacaoFilter, setCltHigienizacaoFilter] = usePersistedState<string[]>("dashboard-clt:higienizacaoFilter", [])
  const [cltDateFromFilter, setCltDateFromFilter] = usePersistedState<string>("dashboard-clt:dateFromFilter", "")
  const [cltDateToFilter, setCltDateToFilter] = usePersistedState<string>("dashboard-clt:dateToFilter", "")
  const [cltContractFromFilter, setCltContractFromFilter] = usePersistedState<string>("dashboard-clt:contractDateFromFilter", "")
  const [cltContractToFilter, setCltContractToFilter] = usePersistedState<string>("dashboard-clt:contractDateToFilter", "")
  const [cltCpfMassFilter, setCltCpfMassFilter] = usePersistedState<string>("dashboard-clt:cpfMassFilter", "")
  const [cltNamesMassFilter, setCltNamesMassFilter] = usePersistedState<string>("dashboard-clt:namesMassFilter", "")
  const [cltPhonesMassFilter, setCltPhonesMassFilter] = usePersistedState<string>("dashboard-clt:phonesMassFilter", "")
  const [cltWithPhonesFilter, setCltWithPhonesFilter] = usePersistedState<boolean>("dashboard-clt:withPhonesFilter", false)
  const [cltNoPhonesFilter, setCltNoPhonesFilter] = usePersistedState<boolean>("dashboard-clt:noPhonesFilter", false)
  const [cltVendorsFilter, setCltVendorsFilter] = usePersistedState<string[]>("dashboard-clt:vendorsFilter", [])
  const [cltBirthMonthFilter, setCltBirthMonthFilter] = usePersistedState<string[]>("dashboard-clt:birthMonthFilter", [])

  const [cltSituacao, setCltSituacao] = usePersistedState<CltSituacaoFilter>("dashboard-clt:situacao", "todos")
  const [cltConsultaFrom, setCltConsultaFrom] = usePersistedState<string>("dashboard-clt:consultaFrom", "")
  const [cltConsultaTo, setCltConsultaTo] = usePersistedState<string>("dashboard-clt:consultaTo", "")
  const [cltAdmissaoFrom, setCltAdmissaoFrom] = usePersistedState<string>("dashboard-clt:admissaoFrom", "")
  const [cltAdmissaoTo, setCltAdmissaoTo] = usePersistedState<string>("dashboard-clt:admissaoTo", "")
  const [cltMesesMin, setCltMesesMin] = usePersistedState<string>("dashboard-clt:mesesMin", "")
  const [cltMesesMax, setCltMesesMax] = usePersistedState<string>("dashboard-clt:mesesMax", "")
  const [cltInicioEmpregadorFrom, setCltInicioEmpregadorFrom] = usePersistedState<string>("dashboard-clt:inicioEmpFrom", "")
  const [cltInicioEmpregadorTo, setCltInicioEmpregadorTo] = usePersistedState<string>("dashboard-clt:inicioEmpTo", "")
  const [cltCategoriaCodigos, setCltCategoriaCodigos] = usePersistedState<string>("dashboard-clt:categoriaCodigos", "")
  const [cltIdadeMin, setCltIdadeMin] = usePersistedState<string>("dashboard-clt:idadeMin", "")
  const [cltIdadeMax, setCltIdadeMax] = usePersistedState<string>("dashboard-clt:idadeMax", "")
  const [cltSexo, setCltSexo] = usePersistedState<string[]>("dashboard-clt:sexo", [])
  const [cltRendaMin, setCltRendaMin] = usePersistedState<string>("dashboard-clt:rendaMin", "")
  const [cltRendaMax, setCltRendaMax] = usePersistedState<string>("dashboard-clt:rendaMax", "")
  const [cltBaseMin, setCltBaseMin] = usePersistedState<string>("dashboard-clt:baseMin", "")
  const [cltBaseMax, setCltBaseMax] = usePersistedState<string>("dashboard-clt:baseMax", "")
  const [cltMargemMin, setCltMargemMin] = usePersistedState<string>("dashboard-clt:margemMin", "")
  const [cltMargemMax, setCltMargemMax] = usePersistedState<string>("dashboard-clt:margemMax", "")
  const [cltPrestacaoMin, setCltPrestacaoMin] = usePersistedState<string>("dashboard-clt:prestacaoMin", "")
  const [cltPrestacaoMax, setCltPrestacaoMax] = usePersistedState<string>("dashboard-clt:prestacaoMax", "")
  const [cltAtivosMin, setCltAtivosMin] = usePersistedState<string>("dashboard-clt:ativosMin", "")
  const [cltAtivosMax, setCltAtivosMax] = usePersistedState<string>("dashboard-clt:ativosMax", "")
  const [cltTemAtivos, setCltTemAtivos] = usePersistedState<YesNoAll>("dashboard-clt:temAtivos", "todos")
  const [cltTemLegados, setCltTemLegados] = usePersistedState<YesNoAll>("dashboard-clt:temLegados", "todos")
  const [cltSortBy, setCltSortBy] = usePersistedState<LeadSort>("dashboard-clt:sortBy:v2", CLT_SORT_DEFAULT)

  const [mercantilSearchValue, setMercantilSearchValue] = usePersistedState<string>("dashboard-mercantil:searchValue", "")
  const [mercantilOrigemFilter, setMercantilOrigemFilter] = usePersistedState<string[]>("dashboard-mercantil:origemFilter", [])
  const [mercantilCpfMassFilter, setMercantilCpfMassFilter] = usePersistedState<string>("dashboard-mercantil:cpfMassFilter", "")
  const [mercantilNamesMassFilter, setMercantilNamesMassFilter] = usePersistedState<string>("dashboard-mercantil:namesMassFilter", "")
  const [mercantilPhonesMassFilter, setMercantilPhonesMassFilter] = usePersistedState<string>("dashboard-mercantil:phonesMassFilter", "")
  const [mercantilWithPhonesFilter, setMercantilWithPhonesFilter] = usePersistedState<boolean>("dashboard-mercantil:withPhonesFilter", false)
  const [mercantilNoPhonesFilter, setMercantilNoPhonesFilter] = usePersistedState<boolean>("dashboard-mercantil:noPhonesFilter", false)
  const [mercantilBirthMonthFilter, setMercantilBirthMonthFilter] = usePersistedState<string[]>("dashboard-mercantil:birthMonthFilter", [])
  const [mercantilStatusFilter, setMercantilStatusFilter] = usePersistedState<string[]>("dashboard-mercantil:statusFilter", [])
  const [mercantilConsultaFrom, setMercantilConsultaFrom] = usePersistedState<string>("dashboard-mercantil:consultaFrom", "")
  const [mercantilConsultaTo, setMercantilConsultaTo] = usePersistedState<string>("dashboard-mercantil:consultaTo", "")
  const [mercantilParcelaMin, setMercantilParcelaMin] = usePersistedState<string>("dashboard-mercantil:parcelaMin", "")
  const [mercantilParcelaMax, setMercantilParcelaMax] = usePersistedState<string>("dashboard-mercantil:parcelaMax", "")
  const [mercantilQtdParcelasMin, setMercantilQtdParcelasMin] = usePersistedState<string>("dashboard-mercantil:qtdParcelasMin", "")
  const [mercantilQtdParcelasMax, setMercantilQtdParcelasMax] = usePersistedState<string>("dashboard-mercantil:qtdParcelasMax", "")
  const [mercantilOrigensMercantilFilter, setMercantilOrigensMercantilFilter] = usePersistedState<string[]>(
    "dashboard-mercantil:origensMercantilFilter",
    []
  )
  const [mercantilSortBy, setMercantilSortBy] = usePersistedState<LeadSort>("dashboard-mercantil:sortBy", MERCANTIL_SORT_DEFAULT)
  const mercantilSortEffective: LeadSort = mercantilSortBy === "mercantil_updated_at" ? MERCANTIL_SORT_DEFAULT : mercantilSortBy

  const [uy3SearchValue, setUy3SearchValue] = usePersistedState<string>("dashboard-uy3:searchValue", "")
  const [uy3OrigemFilter, setUy3OrigemFilter] = usePersistedState<string[]>("dashboard-uy3:origemFilter", [])
  const [uy3CpfMassFilter, setUy3CpfMassFilter] = usePersistedState<string>("dashboard-uy3:cpfMassFilter", "")
  const [uy3NamesMassFilter, setUy3NamesMassFilter] = usePersistedState<string>("dashboard-uy3:namesMassFilter", "")
  const [uy3PhonesMassFilter, setUy3PhonesMassFilter] = usePersistedState<string>("dashboard-uy3:phonesMassFilter", "")
  const [uy3WithPhonesFilter, setUy3WithPhonesFilter] = usePersistedState<boolean>("dashboard-uy3:withPhonesFilter", false)
  const [uy3NoPhonesFilter, setUy3NoPhonesFilter] = usePersistedState<boolean>("dashboard-uy3:noPhonesFilter", false)
  const [uy3BirthMonthFilter, setUy3BirthMonthFilter] = usePersistedState<string[]>("dashboard-uy3:birthMonthFilter", [])
  const [uy3SortBy, setUy3SortBy] = usePersistedState<LeadSort>("dashboard-uy3:sortBy", UY3_SORT_DEFAULT)

  const [isImportModalOpen, setIsImportModalOpen] = useState(false)
  const [isExportModalOpen, setIsExportModalOpen] = useState(false)

  const {
    data: filterOptions,
    isLoading: loadingOptions,
  } = useQuery({
    queryKey: ["leadsFilters"],
    queryFn: fetchLeadsFilters,
    staleTime: 1000 * 60 * 5,
  })

  const {
    data: totalLeadsData,
    refetch: refetchTotalLeads,
  } = useQuery<PaginatedLeadsResponseBase>({
    queryKey: ["leadsBaseTotal"],
    queryFn: () => fetchLeadsBase({ page: 1 }),
    staleTime: 1000 * 60 * 5,
    refetchOnWindowFocus: false,
  })

  const {
    data: paginatedData,
    isLoading,
    isFetching,
    isError,
    refetch,
  } = useQuery<PaginatedLeadsResponseBase | PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT | PaginatedLeadsResponseMercantil | PaginatedLeadsResponseUY3 | PaginatedLeadsResponse360>({
    queryKey: [
      "leads",
      activeTab,
      currentPage,
      // BASE
      baseSearchValue,
      baseOrigemFilter,
      baseCpfMassFilter,
      baseNamesMassFilter,
      basePhonesMassFilter,
      baseWithPhonesFilter,
      baseNoPhonesFilter,
      baseBirthMonthFilter,
      baseSortBy,
      // FGTS
      searchValue, statusFilter, motivosFilter, origemFilter, higienizacaoFilter,
      dateFromFilter, dateToFilter, contractDateFromFilter, contractDateToFilter,
      cpfMassFilter, namesMassFilter, phonesMassFilter, withPhonesFilter, noPhonesFilter, vendorsFilter, birthMonthFilter,
      fgtsAuthorizedFilter, fgtsConsultaFromFilter, fgtsConsultaToFilter,
      // CLT
      cltSearchValue, cltStatusFilter, cltMotivosFilter, cltOrigemFilter, cltHigienizacaoFilter,
      cltDateFromFilter, cltDateToFilter, cltContractFromFilter, cltContractToFilter,
      cltCpfMassFilter, cltNamesMassFilter, cltPhonesMassFilter, cltWithPhonesFilter, cltNoPhonesFilter, cltVendorsFilter, cltBirthMonthFilter,
      cltSituacao,
      cltConsultaFrom, cltConsultaTo, cltAdmissaoFrom, cltAdmissaoTo, cltMesesMin, cltMesesMax,
      cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltCategoriaCodigos, cltIdadeMin, cltIdadeMax,
      cltSexo, cltRendaMin, cltRendaMax, cltBaseMin, cltBaseMax, cltMargemMin, cltMargemMax,
      cltPrestacaoMin, cltPrestacaoMax, cltAtivosMin, cltAtivosMax, cltTemAtivos, cltTemLegados,
      cltSortBy,
      // MERCANTIL
      mercantilSearchValue,
      mercantilOrigemFilter,
      mercantilCpfMassFilter, mercantilNamesMassFilter, mercantilPhonesMassFilter, mercantilWithPhonesFilter, mercantilNoPhonesFilter,
      mercantilBirthMonthFilter,
      mercantilStatusFilter,
      mercantilConsultaFrom, mercantilConsultaTo,
      mercantilParcelaMin, mercantilParcelaMax,
      mercantilQtdParcelasMin, mercantilQtdParcelasMax,
      mercantilOrigensMercantilFilter,
      mercantilSortEffective,
      // UY3
      uy3SearchValue,
      uy3OrigemFilter,
      uy3CpfMassFilter, uy3NamesMassFilter, uy3PhonesMassFilter, uy3WithPhonesFilter, uy3NoPhonesFilter,
      uy3BirthMonthFilter,
      uy3SortBy,
      dashboard360SearchValue,
      dashboard360SortBy,
      dashboard360Filters,
    ],
    queryFn: async (): Promise<PaginatedLeadsResponseBase | PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT | PaginatedLeadsResponseMercantil | PaginatedLeadsResponseUY3 | PaginatedLeadsResponse360> => {
      if (activeTab === "360") {
        return fetchLeads360({
          page: currentPage,
          search: dashboard360SearchValue,
          sort: dashboard360SortBy,
          with_phones: dashboard360Filters.with_phones || undefined,
          without_phones: dashboard360Filters.without_phones || undefined,
          selected_banks: dashboard360SelectedBanks.length ? dashboard360SelectedBanks : undefined,
          bank_combination_mode: dashboard360Filters.bank_combination_mode,
          clt_situacao: dashboard360Filters.clt_situacao !== "todos" ? dashboard360Filters.clt_situacao : undefined,
          clt_consulta_from: dashboard360Filters.clt_consulta_from || undefined,
          clt_consulta_to: dashboard360Filters.clt_consulta_to || undefined,
          clt_meses_min: dashboard360Filters.clt_meses_min || undefined,
          clt_meses_max: dashboard360Filters.clt_meses_max || undefined,
          clt_margem_min: dashboard360Filters.clt_margem_min || undefined,
          clt_margem_max: dashboard360Filters.clt_margem_max || undefined,
          clt_numero_parcelas_min: dashboard360Filters.clt_prestacao_min || undefined,
          clt_numero_parcelas_max: dashboard360Filters.clt_prestacao_max || undefined,
          mercantil_situacao: dashboard360Filters.mercantil_situacao !== "todos" ? dashboard360Filters.mercantil_situacao : undefined,
          mercantil_consulta_from: dashboard360Filters.mercantil_consulta_from || undefined,
          mercantil_consulta_to: dashboard360Filters.mercantil_consulta_to || undefined,
          mercantil_valor_parcela_min: dashboard360Filters.mercantil_parcela_min || undefined,
          mercantil_valor_parcela_max: dashboard360Filters.mercantil_parcela_max || undefined,
          mercantil_numero_parcelas_min: dashboard360Filters.mercantil_qtd_parcelas_min || undefined,
          mercantil_numero_parcelas_max: dashboard360Filters.mercantil_qtd_parcelas_max || undefined,
          uy3_situacao: dashboard360Filters.uy3_situacao !== "todos" ? dashboard360Filters.uy3_situacao : undefined,
          uy3_consulta_from: dashboard360Filters.uy3_consulta_from || undefined,
          uy3_consulta_to: dashboard360Filters.uy3_consulta_to || undefined,
          uy3_meses_admissao_min: dashboard360Filters.uy3_meses_admissao_min || undefined,
          uy3_meses_admissao_max: dashboard360Filters.uy3_meses_admissao_max || undefined,
          uy3_margem_min: dashboard360Filters.uy3_margem_min || undefined,
          uy3_margem_max: dashboard360Filters.uy3_margem_max || undefined,
          uy3_valor_liberado_min: dashboard360Filters.uy3_valor_liberado_min || undefined,
          uy3_valor_liberado_max: dashboard360Filters.uy3_valor_liberado_max || undefined,
          uy3_numero_parcelas_min: dashboard360Filters.uy3_numero_parcelas_min || undefined,
          uy3_numero_parcelas_max: dashboard360Filters.uy3_numero_parcelas_max || undefined,
        })
      }

      if (activeTab === "BASE") {
        return fetchLeadsBase({
          page: currentPage,
          search: baseSearchValue,
          origens: baseOrigemFilter,
          cpf: baseCpfMassFilter,
          names: baseNamesMassFilter,
          phones: basePhonesMassFilter,
          with_phones: baseWithPhonesFilter || undefined,
          without_phones: baseNoPhonesFilter || undefined,
          birth_month: baseBirthMonthFilter,
          sort: baseSortBy,
        })
      }

      if (activeTab === "UY3") {
        return fetchLeadsUy3({
          page: currentPage,
          search: uy3SearchValue,
          origens: uy3OrigemFilter,
          cpf: uy3CpfMassFilter,
          names: uy3NamesMassFilter,
          phones: uy3PhonesMassFilter,
          with_phones: uy3WithPhonesFilter || undefined,
          without_phones: uy3NoPhonesFilter || undefined,
          birth_month: uy3BirthMonthFilter,
          sort: uy3SortBy,
        })
      }

      if (activeTab === "FGTS") {
        return fetchLeadsFGTS({
          page: currentPage,
          search: searchValue,
          status: statusFilter,
          motivos: motivosFilter,
          origens: origemFilter,
          origens_hig: higienizacaoFilter,
          date_from: dateFromFilter,
          date_to: dateToFilter,
          contract_from: contractDateFromFilter,
          contract_to: contractDateToFilter,
          cpf: cpfMassFilter,
          names: namesMassFilter,
          phones: phonesMassFilter,
          with_phones: withPhonesFilter || undefined,
          without_phones: noPhonesFilter || undefined,
          vendors: vendorsFilter,
          birth_month: birthMonthFilter,
          fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
          fgts_consulta_from: fgtsConsultaFromFilter || undefined,
          fgts_consulta_to: fgtsConsultaToFilter || undefined,
        })
      }

      if (activeTab === "CLT") {
        const catCodes = cltCategoriaCodigos
          ? cltCategoriaCodigos.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean)
          : undefined
        return fetchLeadsCLT({
          page: currentPage,
          search: cltSearchValue,
          status: cltStatusFilter,
          origens: cltOrigemFilter,
          cpf: cltCpfMassFilter,
          names: cltNamesMassFilter,
          phones: cltPhonesMassFilter,
          with_phones: cltWithPhonesFilter || undefined,
          without_phones: cltNoPhonesFilter || undefined,
          birth_month: cltBirthMonthFilter,
          clt_situacao: cltSituacao !== "todos" ? cltSituacao : undefined,
          clt_consulta_from: cltConsultaFrom || undefined,
          clt_consulta_to: cltConsultaTo || undefined,
          clt_admissao_from: cltAdmissaoFrom || undefined,
          clt_admissao_to: cltAdmissaoTo || undefined,
          clt_meses_min: cltMesesMin || undefined,
          clt_meses_max: cltMesesMax || undefined,
          clt_inicio_empregador_from: cltInicioEmpregadorFrom || undefined,
          clt_inicio_empregador_to: cltInicioEmpregadorTo || undefined,
          clt_categoria_codigos: catCodes,
          clt_idade_min: cltIdadeMin || undefined,
          clt_idade_max: cltIdadeMax || undefined,
          clt_sexo: cltSexo.length ? (cltSexo as ("M" | "F")[]) : undefined,
          clt_renda_min: cltRendaMin || undefined,
          clt_renda_max: cltRendaMax || undefined,
          clt_base_min: cltBaseMin || undefined,
          clt_base_max: cltBaseMax || undefined,
          clt_margem_min: cltMargemMin || undefined,
          clt_margem_max: cltMargemMax || undefined,
          clt_prestacao_min: cltPrestacaoMin || undefined,
          clt_prestacao_max: cltPrestacaoMax || undefined,
          clt_ativos_min: cltAtivosMin || undefined,
          clt_ativos_max: cltAtivosMax || undefined,
          clt_tem_ativos: cltTemAtivos !== "todos" ? cltTemAtivos : undefined,
          clt_tem_legados: cltTemLegados !== "todos" ? cltTemLegados : undefined,
          sort: cltSortBy,
        })
      }

      return fetchLeadsMercantil({
        page: currentPage,
        search: mercantilSearchValue,
        origens: mercantilOrigemFilter,
        cpf: mercantilCpfMassFilter,
        names: mercantilNamesMassFilter,
        phones: mercantilPhonesMassFilter,
        with_phones: mercantilWithPhonesFilter || undefined,
        without_phones: mercantilNoPhonesFilter || undefined,
        birth_month: mercantilBirthMonthFilter,
        mercantil_status: mercantilStatusFilter.length ? mercantilStatusFilter : undefined,
        mercantil_consulta_from: mercantilConsultaFrom || undefined,
        mercantil_consulta_to: mercantilConsultaTo || undefined,
        mercantil_parcela_min: mercantilParcelaMin || undefined,
        mercantil_parcela_max: mercantilParcelaMax || undefined,
        mercantil_qtd_parcelas_min: mercantilQtdParcelasMin || undefined,
        mercantil_qtd_parcelas_max: mercantilQtdParcelasMax || undefined,
        mercantil_origens: mercantilOrigensMercantilFilter.length ? mercantilOrigensMercantilFilter : undefined,
        sort: mercantilSortEffective,
      })
    },
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: false,
  })

  const processedLeadsBase: ProcessedLeadFGTS[] = useMemo(() => {
    if (activeTab !== "BASE") return []
    const resp = paginatedData as PaginatedLeadsResponseBase | undefined
    if (!resp?.data) return []
    return resp.data.map((lead: LeadFromApiBase) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        contratos: 0,
        data_contrato_recente: "",
        vendedor: "",
        saldo: "",
        libera: "",
        data_atualizacao: "",
        consulta: "",
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        ultima_origem_higienizacao: lead.ultima_origem_higienizacao || "",
        fgts_off_authorized: null,
        fgts_off_consultado_em: "",
      }
    })
  }, [paginatedData, activeTab])

  const processedLeads360: ProcessedLead360[] = useMemo(() => {
    if (activeTab !== "360") return []
    const resp = paginatedData as PaginatedLeadsResponse360 | undefined
    if (!resp?.data) return []

    const toBool = (value: boolean | number | "0" | "1" | null | undefined) =>
      value === true || value === 1 || value === "1"
        ? true
        : value === false || value === 0 || value === "0"
          ? false
          : null

    return resp.data.map((lead: LeadFromApi360) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      const taxaJuros = lead.mercantil_taxa_juros_mes
      const taxaFmt = taxaJuros === null || taxaJuros === undefined || taxaJuros === ""
        ? ""
        : `${String(taxaJuros)}%`

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        consulta: lead.consulta || "",
        saldo: formatCurrency(lead.saldo as any),
        libera: formatCurrency(lead.libera as any),
        data_atualizacao: lead.data_atualizacao ? formatDate(lead.data_atualizacao) : "",
        contratos: lead.contracts_count,
        data_contrato_recente: lead.data_contrato_recente ? formatDateOnly(lead.data_contrato_recente) : "",
        vendedor: lead.vendedor || "",
        fgts_off_authorized: toBool(lead.fgts_off_authorized),
        fgts_off_consultado_em: lead.fgts_off_consultado_em ? formatDate(lead.fgts_off_consultado_em) : "",
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        ultima_origem_higienizacao: lead.ultima_origem_higienizacao || "",
        elegivel: toBool(lead.elegivel as any),
        not_found: !!lead.not_found,
        margem_disponivel: formatCurrency(lead.margem_disponivel as any),
        politica_credito_aprovado: toBool(lead.politica_credito_aprovado),
        clt_consultado_em: lead.clt_consultado_em ? formatDate(lead.clt_consultado_em) : "",
        clt_dados_atualizados_em: lead.clt_dados_atualizados_em ? formatDate(lead.clt_dados_atualizados_em) : "",
        mercantil_status: lead.mercantil_status || "",
        mercantil_mensagem_erro: lead.mercantil_mensagem_erro || "",
        mercantil_data_hora_origem: lead.mercantil_data_hora_origem ? formatLocalDateTime(lead.mercantil_data_hora_origem) : "",
        mercantil_valor_financiado: formatCurrency(lead.mercantil_valor_financiado as any),
        mercantil_valor_iof: formatCurrency(lead.mercantil_valor_iof as any),
        mercantil_data_primeiro_vencimento: lead.mercantil_data_primeiro_vencimento ? formatDateOnly(lead.mercantil_data_primeiro_vencimento) : "",
        mercantil_valor_emprestimo: formatCurrency(lead.mercantil_valor_emprestimo as any),
        mercantil_quantidade_parcelas: lead.mercantil_quantidade_parcelas ?? "",
        mercantil_valor_liberado: formatCurrency(lead.mercantil_valor_liberado as any),
        mercantil_taxa_juros_mes: taxaFmt,
        mercantil_valor_parcela: formatCurrency(lead.mercantil_valor_parcela as any),
        ultima_origem_mercantil: lead.ultima_origem_mercantil || "",
        uy3_type_webhook: lead.uy3_type_webhook || "",
        uy3_status: lead.uy3_status || "",
        uy3_consultado_em: lead.uy3_consultado_em ? formatLocalDateTime(lead.uy3_consultado_em) : "",
        uy3_data_admissao: lead.uy3_data_admissao ? formatDateOnly(lead.uy3_data_admissao) : "",
        uy3_valor_liberado: formatCurrency(lead.uy3_valor_liberado as any),
        uy3_numero_parcelas: lead.uy3_numero_parcelas ?? "",
        uy3_codigo_requisicao: lead.uy3_codigo_requisicao || "",
        uy3_margem_disponivel: formatCurrency(lead.uy3_margem_disponivel as any),
        uy3_elegivel_emprestimo: toBool(lead.uy3_elegivel_emprestimo),
        uy3_numero_inscricao_empregador: lead.uy3_numero_inscricao_empregador || "",
        uy3_pessoa_exposta_politicamente_codigo: lead.uy3_pessoa_exposta_politicamente_codigo ?? "",
        uy3_data_hora_validade_solicitacao: lead.uy3_data_hora_validade_solicitacao ? formatLocalDateTime(lead.uy3_data_hora_validade_solicitacao) : "",
        uy3_is_mei: toBool(lead.uy3_is_mei),
        uy3_is_judicial_recovery: toBool(lead.uy3_is_judicial_recovery),
      }
    })
  }, [paginatedData, activeTab])

  const processedLeadsUy3: ProcessedLeadUy3[] = useMemo(() => {
    if (activeTab !== "UY3") return []
    const resp = paginatedData as PaginatedLeadsResponseUY3 | undefined
    if (!resp?.data) return []
    return resp.data.map((lead: LeadFromApiUY3) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      const toBool = (value: boolean | number | "0" | "1" | null | undefined) =>
        value === true || value === 1 || value === "1"
          ? true
          : value === false || value === 0 || value === "0"
            ? false
            : null

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        uy3_type_webhook: lead.uy3_type_webhook || "",
        uy3_status: lead.uy3_status || "",
        uy3_consultado_em: lead.uy3_consultado_em ? formatLocalDateTime(lead.uy3_consultado_em) : "",
        uy3_data_admissao: lead.uy3_data_admissao ? formatDateOnly(lead.uy3_data_admissao) : "",
        uy3_valor_liberado: formatCurrency(lead.uy3_valor_liberado as any),
        uy3_numero_parcelas: lead.uy3_numero_parcelas ?? "",
        uy3_codigo_requisicao: lead.uy3_codigo_requisicao || "",
        uy3_margem_disponivel: formatCurrency(lead.uy3_margem_disponivel as any),
        uy3_elegivel_emprestimo: toBool(lead.uy3_elegivel_emprestimo),
        uy3_numero_inscricao_empregador: lead.uy3_numero_inscricao_empregador || "",
        uy3_pessoa_exposta_politicamente_codigo: lead.uy3_pessoa_exposta_politicamente_codigo ?? "",
        uy3_data_hora_validade_solicitacao: lead.uy3_data_hora_validade_solicitacao ? formatLocalDateTime(lead.uy3_data_hora_validade_solicitacao) : "",
        uy3_is_mei: toBool(lead.uy3_is_mei),
        uy3_is_judicial_recovery: toBool(lead.uy3_is_judicial_recovery),
      }
    })
  }, [paginatedData, activeTab])

  const processedLeadsFGTS: ProcessedLeadFGTS[] = useMemo(() => {
    if (activeTab !== "FGTS") return []
    const resp = paginatedData as PaginatedLeadsResponseFGTS | undefined
    if (!resp?.data) return []
    return resp.data.map((lead: LeadFromApiFGTS) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      const rawAuth: any = lead.fgts_off_authorized
      const fgtsOffAuthorized: boolean | null =
        rawAuth === true || rawAuth === 1 || rawAuth === "1"
          ? true
          : rawAuth === false || rawAuth === 0 || rawAuth === "0"
            ? false
            : null

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        contratos: lead.contracts_count,
        data_contrato_recente: lead.data_contrato_recente ? formatDateOnly(lead.data_contrato_recente) : "",
        vendedor: lead.vendedor || "",
        saldo: formatCurrency(lead.saldo),
        libera: formatCurrency(lead.libera),
        data_atualizacao: formatDate(lead.data_atualizacao),
        consulta: lead.consulta || "--",
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        ultima_origem_higienizacao: lead.ultima_origem_higienizacao || "",
        fgts_off_authorized: fgtsOffAuthorized,
        fgts_off_consultado_em: lead.fgts_off_consultado_em
          ? formatDate(lead.fgts_off_consultado_em)
          : "",
      }
    })
  }, [paginatedData, activeTab])

  const processedLeadsCLT: ProcessedLeadCLT[] = useMemo(() => {
    if (activeTab !== "CLT") return []
    const resp = paginatedData as PaginatedLeadsResponseCLT | undefined
    if (!resp?.data) return []
    return resp.data.map((lead: LeadFromApiCLT) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      const rawElegivel: any = lead.elegivel
      const elegivel: boolean | null =
        rawElegivel === true || rawElegivel === 1 || rawElegivel === "1"
          ? true
          : rawElegivel === false || rawElegivel === 0 || rawElegivel === "0"
            ? false
            : null
      const rawPoliticaAprovado: any = lead.politica_credito_aprovado
      const politicaCreditoAprovado: boolean | null =
        rawPoliticaAprovado === true || rawPoliticaAprovado === 1 || rawPoliticaAprovado === "1"
          ? true
          : rawPoliticaAprovado === false || rawPoliticaAprovado === 0 || rawPoliticaAprovado === "0"
            ? false
            : null

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        matricula: lead.matricula || "",
        elegivel,
        not_found: !!lead.not_found,
        politica_credito_aprovado: politicaCreditoAprovado,
        politica_credito_mensagem: lead.politica_credito_mensagem || "",
        politica_credito_valor_maximo_disponivel: formatCurrency(lead.politica_credito_valor_maximo_disponivel as any),
        politica_credito_prazo_maximo_disponivel:
          lead.politica_credito_prazo_maximo_disponivel === null ||
          lead.politica_credito_prazo_maximo_disponivel === undefined ||
          lead.politica_credito_prazo_maximo_disponivel === ""
            ? null
            : Number(lead.politica_credito_prazo_maximo_disponivel),
        politica_credito_data_consulta: lead.politica_credito_data_consulta ? formatLocalDateTime(lead.politica_credito_data_consulta) : "",
        politica_credito_tabela_aprovada: lead.politica_credito_tabela_aprovada || "",
        clt_consultado_em: lead.clt_consultado_em ? formatDate(lead.clt_consultado_em) : "",
        // 🆕
        clt_dados_atualizados_em: lead.clt_dados_atualizados_em ? formatDate(lead.clt_dados_atualizados_em) : "",
        idade: lead.idade ?? null,
        sexo: lead.sexo ?? null,
        data_admissao: lead.data_admissao ? formatDate(lead.data_admissao) : "",
        meses_admissao: lead.meses_admissao ?? null,
        valor_renda: formatCurrency(lead.valor_renda as any),
        valor_base_margem: formatCurrency(lead.valor_base_margem as any),
        margem_disponivel: formatCurrency(lead.margem_disponivel as any),
        valor_max_prestacao: formatCurrency(lead.valor_max_prestacao as any),
        categoria_trabalhador_codigo: lead.categoria_trabalhador_codigo ?? "",
        inicio_atividade_empregador: lead.inicio_atividade_empregador
          ? formatDate(lead.inicio_atividade_empregador)
          : "",
        qtd_emprestimos_ativos_suspensos: lead.qtd_emprestimos_ativos_suspensos ?? null,
        emprestimos_legados: lead.emprestimos_legados ?? null,
      }
    })
  }, [paginatedData, activeTab])

  const processedLeadsMercantil: ProcessedLeadMercantil[] = useMemo(() => {
    if (activeTab !== "MERCANTIL") return []
    const resp = paginatedData as PaginatedLeadsResponseMercantil | undefined
    if (!resp?.data) return []
    return resp.data.map((lead: LeadFromApiMercantil) => {
      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      const taxaJuros = lead.mercantil_taxa_juros_mes
      const taxaFmt = taxaJuros === null || taxaJuros === undefined || taxaJuros === ""
        ? ""
        : `${String(taxaJuros)}%`

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        created_at: lead.created_at ? formatDate(lead.created_at) : "",
        updated_at: lead.updated_at ? formatDate(lead.updated_at) : "",
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        ultima_origem_mercantil: lead.ultima_origem_mercantil || "",
        mercantil_status: lead.mercantil_status || "",
        mercantil_mensagem_erro: lead.mercantil_mensagem_erro || "",
        mercantil_data_hora_origem: lead.mercantil_data_hora_origem ? formatLocalDateTime(lead.mercantil_data_hora_origem) : "",
        mercantil_valor_financiado: formatCurrency(lead.mercantil_valor_financiado as any),
        mercantil_valor_iof: formatCurrency(lead.mercantil_valor_iof as any),
        mercantil_data_primeiro_vencimento: lead.mercantil_data_primeiro_vencimento ? formatDateOnly(lead.mercantil_data_primeiro_vencimento) : "",
        mercantil_valor_emprestimo: formatCurrency(lead.mercantil_valor_emprestimo as any),
        mercantil_quantidade_parcelas: lead.mercantil_quantidade_parcelas ?? "",
        mercantil_valor_liberado: formatCurrency(lead.mercantil_valor_liberado as any),
        mercantil_taxa_juros_mes: taxaFmt,
        mercantil_valor_parcela: formatCurrency(lead.mercantil_valor_parcela as any),
      }
    })
  }, [paginatedData, activeTab])

  const [awaitingFetch, setAwaitingFetch] = useState<null | "apply" | "clear">(null)
  const [pendingToastId, setPendingToastId] = useState<string | number | null>(null)

  useEffect(() => {
    if (awaitingFetch && (isFetching || isLoading) && !pendingToastId) {
      const id = toast.loading("Aplicando filtros…")
      setPendingToastId(id)
    }
    if (!isFetching && !isLoading && pendingToastId) {
      toast.dismiss(pendingToastId)
      setPendingToastId(null)
      if (awaitingFetch) {
        if (isError) {
          toast.error("Falha ao aplicar filtros. Tente novamente.")
        } else {
          if (awaitingFetch === "apply") toast.success("Filtros aplicados.")
          if (awaitingFetch === "clear") toast.info("Filtros limpos.")
        }
        setAwaitingFetch(null)
      }
    }
  }, [isFetching, isLoading, isError, awaitingFetch, pendingToastId])

  useEffect(() => {
    return () => {
      if (pendingToastId) toast.dismiss(pendingToastId)
    }
  }, [pendingToastId])

  const handleApplyFilters = () => {
    setCurrentPage(1)
    setAwaitingFetch("apply")
    if (pendingToastId) toast.dismiss(pendingToastId)
    const id = toast.loading("Aplicando filtros…")
    setPendingToastId(id)
  }

  const clear360 = () => {
    setDashboard360Filters(DASHBOARD_360_FILTERS_DEFAULT)
    setDashboard360SearchValue("")
    setDashboard360SortBy(DASHBOARD_360_SORT_DEFAULT)
  }

  const clearBase = () => {
    setBaseSearchValue("")
    setBaseOrigemFilter([])
    setBaseCpfMassFilter("")
    setBaseNamesMassFilter("")
    setBasePhonesMassFilter("")
    setBaseWithPhonesFilter(false)
    setBaseNoPhonesFilter(false)
    setBaseBirthMonthFilter([])
    setBaseSortBy(BASE_SORT_DEFAULT)
  }

  const clearUy3 = () => {
    setUy3SearchValue("")
    setUy3OrigemFilter([])
    setUy3CpfMassFilter("")
    setUy3NamesMassFilter("")
    setUy3PhonesMassFilter("")
    setUy3WithPhonesFilter(false)
    setUy3NoPhonesFilter(false)
    setUy3BirthMonthFilter([])
    setUy3SortBy(UY3_SORT_DEFAULT)
  }

  const clearFgts = () => {
    setSearchValue("")
    setStatusFilter("todos")
    setMotivosFilter([])
    setOrigemFilter([])
    setHigienizacaoFilter([])
    setDateFromFilter("")
    setDateToFilter("")
    setContractDateFromFilter("")
    setContractDateToFilter("")
    setCpfMassFilter("")
    setNamesMassFilter("")
    setPhonesMassFilter("")
    setWithPhonesFilter(false)
    setNoPhonesFilter(false)
    setVendorsFilter([])
    setBirthMonthFilter([])
    setFgtsAuthorizedFilter("todos")
    setFgtsConsultaFromFilter("")
    setFgtsConsultaToFilter("")
  }

  const clearClt = () => {
    setCltSearchValue("")
    setCltStatusFilter("todos")
    setCltMotivosFilter([])
    setCltOrigemFilter([])
    setCltHigienizacaoFilter([])
    setCltDateFromFilter("")
    setCltDateToFilter("")
    setCltContractFromFilter("")
    setCltContractToFilter("")
    setCltCpfMassFilter("")
    setCltNamesMassFilter("")
    setCltPhonesMassFilter("")
    setCltWithPhonesFilter(false)
    setCltNoPhonesFilter(false)
    setCltVendorsFilter([])
    setCltBirthMonthFilter([])
    setCltSituacao("todos")
    setCltConsultaFrom("")
    setCltConsultaTo("")
    setCltAdmissaoFrom("")
    setCltAdmissaoTo("")
    setCltMesesMin("")
    setCltMesesMax("")
    setCltInicioEmpregadorFrom("")
    setCltInicioEmpregadorTo("")
    setCltCategoriaCodigos("")
    setCltIdadeMin("")
    setCltIdadeMax("")
    setCltSexo([])
    setCltRendaMin("")
    setCltRendaMax("")
    setCltBaseMin("")
    setCltBaseMax("")
    setCltMargemMin("")
    setCltMargemMax("")
    setCltPrestacaoMin("")
    setCltPrestacaoMax("")
    setCltAtivosMin("")
    setCltAtivosMax("")
    setCltTemAtivos("todos")
    setCltTemLegados("todos")
    setCltSortBy(CLT_SORT_DEFAULT)
  }

  const clearMercantil = () => {
    setMercantilSearchValue("")
    setMercantilOrigemFilter([])
    setMercantilCpfMassFilter("")
    setMercantilNamesMassFilter("")
    setMercantilPhonesMassFilter("")
    setMercantilWithPhonesFilter(false)
    setMercantilNoPhonesFilter(false)
    setMercantilBirthMonthFilter([])
    setMercantilStatusFilter([])
    setMercantilConsultaFrom("")
    setMercantilConsultaTo("")
    setMercantilParcelaMin("")
    setMercantilParcelaMax("")
    setMercantilQtdParcelasMin("")
    setMercantilQtdParcelasMax("")
    setMercantilOrigensMercantilFilter([])
    setMercantilSortBy(MERCANTIL_SORT_DEFAULT)
  }

  const handleClearFilters = () => {
    if (activeTab === "360") clear360()
    else if (activeTab === "BASE") clearBase()
    else if (activeTab === "UY3") clearUy3()
    else if (activeTab === "FGTS") clearFgts()
    else if (activeTab === "CLT") clearClt()
    else clearMercantil()
    setCurrentPage(1)
    setAwaitingFetch("clear")
    if (pendingToastId) toast.dismiss(pendingToastId)
    const id = toast.loading("Limpando filtros…")
    setPendingToastId(id)
  }

  const hasActiveFilters360 =
    dashboard360Filters.with_phones ||
    dashboard360Filters.without_phones ||
    dashboard360SelectedBanks.length ||
    dashboard360Filters.clt_situacao !== "todos" ||
    dashboard360Filters.clt_consulta_from ||
    dashboard360Filters.clt_consulta_to ||
    dashboard360Filters.clt_meses_min ||
    dashboard360Filters.clt_meses_max ||
    dashboard360Filters.clt_margem_min ||
    dashboard360Filters.clt_margem_max ||
    dashboard360Filters.clt_prestacao_min ||
    dashboard360Filters.clt_prestacao_max ||
    dashboard360Filters.mercantil_situacao !== "todos" ||
    dashboard360Filters.mercantil_consulta_from ||
    dashboard360Filters.mercantil_consulta_to ||
    dashboard360Filters.mercantil_parcela_min ||
    dashboard360Filters.mercantil_parcela_max ||
    dashboard360Filters.mercantil_qtd_parcelas_min ||
    dashboard360Filters.mercantil_qtd_parcelas_max ||
    dashboard360Filters.uy3_situacao !== "todos" ||
    dashboard360Filters.uy3_consulta_from ||
    dashboard360Filters.uy3_consulta_to ||
    dashboard360Filters.uy3_meses_admissao_min ||
    dashboard360Filters.uy3_meses_admissao_max ||
    dashboard360Filters.uy3_margem_min ||
    dashboard360Filters.uy3_margem_max ||
    dashboard360Filters.uy3_valor_liberado_min ||
    dashboard360Filters.uy3_valor_liberado_max ||
    dashboard360Filters.uy3_numero_parcelas_min ||
    dashboard360Filters.uy3_numero_parcelas_max

  const hasActiveFiltersBASE =
    baseSearchValue ||
    baseOrigemFilter.length ||
    baseCpfMassFilter ||
    baseNamesMassFilter ||
    basePhonesMassFilter ||
    baseWithPhonesFilter ||
    baseNoPhonesFilter ||
    baseBirthMonthFilter.length

  const hasActiveFiltersFGTS =
    searchValue ||
    statusFilter !== "todos" ||
    motivosFilter.length ||
    origemFilter.length ||
    dateFromFilter ||
    dateToFilter ||
    contractDateFromFilter ||
    contractDateToFilter ||
    cpfMassFilter ||
    namesMassFilter ||
    phonesMassFilter ||
    withPhonesFilter ||
    noPhonesFilter ||
    higienizacaoFilter.length ||
    vendorsFilter.length ||
    birthMonthFilter.length ||
    fgtsAuthorizedFilter !== "todos" ||
    fgtsConsultaFromFilter ||
    fgtsConsultaToFilter

  const hasActiveFiltersUY3 =
    uy3SearchValue ||
    uy3OrigemFilter.length ||
    uy3CpfMassFilter ||
    uy3NamesMassFilter ||
    uy3PhonesMassFilter ||
    uy3WithPhonesFilter ||
    uy3NoPhonesFilter ||
    uy3BirthMonthFilter.length

  const hasActiveFiltersCLT =
    cltSearchValue ||
    cltStatusFilter !== "todos" ||
    cltOrigemFilter.length ||
    cltCpfMassFilter ||
    cltNamesMassFilter ||
    cltPhonesMassFilter ||
    cltWithPhonesFilter ||
    cltNoPhonesFilter ||
    cltBirthMonthFilter.length ||
    cltSituacao !== "todos" ||
    cltConsultaFrom ||
    cltConsultaTo ||
    cltAdmissaoFrom ||
    cltAdmissaoTo ||
    cltMesesMin ||
    cltMesesMax ||
    cltInicioEmpregadorFrom ||
    cltInicioEmpregadorTo ||
    !!cltCategoriaCodigos.trim() ||
    cltIdadeMin ||
    cltIdadeMax ||
    (cltSexo && cltSexo.length > 0) ||
    cltRendaMin || cltRendaMax ||
    cltBaseMin || cltBaseMax ||
    cltMargemMin || cltMargemMax ||
    cltPrestacaoMin || cltPrestacaoMax ||
    cltAtivosMin || cltAtivosMax || cltTemAtivos !== "todos" ||
    cltTemLegados !== "todos"

  const hasActiveFiltersMercantil =
    mercantilSearchValue ||
    mercantilOrigemFilter.length ||
    mercantilCpfMassFilter ||
    mercantilNamesMassFilter ||
    mercantilPhonesMassFilter ||
    mercantilWithPhonesFilter ||
    mercantilNoPhonesFilter ||
    mercantilBirthMonthFilter.length ||
    mercantilStatusFilter.length ||
    mercantilConsultaFrom ||
    mercantilConsultaTo ||
    mercantilParcelaMin ||
    mercantilParcelaMax ||
    mercantilQtdParcelasMin ||
    mercantilQtdParcelasMax ||
    mercantilOrigensMercantilFilter.length

  const hasActiveFilters =
    activeTab === "360"
      ? hasActiveFilters360
      : activeTab === "BASE"
      ? hasActiveFiltersBASE
      : activeTab === "UY3"
      ? hasActiveFiltersUY3
      : activeTab === "FGTS"
      ? hasActiveFiltersFGTS
      : activeTab === "CLT"
        ? hasActiveFiltersCLT
        : hasActiveFiltersMercantil

  const collectFilters = () => {
    if (activeTab === "360") {
      return {
        with_phones: dashboard360Filters.with_phones || undefined,
        without_phones: dashboard360Filters.without_phones || undefined,
        selected_banks: dashboard360SelectedBanks.length ? dashboard360SelectedBanks : undefined,
        bank_combination_mode: dashboard360SelectedBanks.length ? dashboard360Filters.bank_combination_mode : undefined,
        clt_situacao: dashboard360Filters.clt_situacao !== "todos" ? dashboard360Filters.clt_situacao : undefined,
        clt_consulta_from: dashboard360Filters.clt_consulta_from || undefined,
        clt_consulta_to: dashboard360Filters.clt_consulta_to || undefined,
        clt_meses_min: dashboard360Filters.clt_meses_min || undefined,
        clt_meses_max: dashboard360Filters.clt_meses_max || undefined,
        clt_margem_min: dashboard360Filters.clt_margem_min || undefined,
        clt_margem_max: dashboard360Filters.clt_margem_max || undefined,
        clt_numero_parcelas_min: dashboard360Filters.clt_prestacao_min || undefined,
        clt_numero_parcelas_max: dashboard360Filters.clt_prestacao_max || undefined,
        mercantil_situacao: dashboard360Filters.mercantil_situacao !== "todos" ? dashboard360Filters.mercantil_situacao : undefined,
        mercantil_consulta_from: dashboard360Filters.mercantil_consulta_from || undefined,
        mercantil_consulta_to: dashboard360Filters.mercantil_consulta_to || undefined,
        mercantil_valor_parcela_min: dashboard360Filters.mercantil_parcela_min || undefined,
        mercantil_valor_parcela_max: dashboard360Filters.mercantil_parcela_max || undefined,
        mercantil_numero_parcelas_min: dashboard360Filters.mercantil_qtd_parcelas_min || undefined,
        mercantil_numero_parcelas_max: dashboard360Filters.mercantil_qtd_parcelas_max || undefined,
        uy3_situacao: dashboard360Filters.uy3_situacao !== "todos" ? dashboard360Filters.uy3_situacao : undefined,
        uy3_consulta_from: dashboard360Filters.uy3_consulta_from || undefined,
        uy3_consulta_to: dashboard360Filters.uy3_consulta_to || undefined,
        uy3_meses_admissao_min: dashboard360Filters.uy3_meses_admissao_min || undefined,
        uy3_meses_admissao_max: dashboard360Filters.uy3_meses_admissao_max || undefined,
        uy3_margem_min: dashboard360Filters.uy3_margem_min || undefined,
        uy3_margem_max: dashboard360Filters.uy3_margem_max || undefined,
        uy3_valor_liberado_min: dashboard360Filters.uy3_valor_liberado_min || undefined,
        uy3_valor_liberado_max: dashboard360Filters.uy3_valor_liberado_max || undefined,
        uy3_numero_parcelas_min: dashboard360Filters.uy3_numero_parcelas_min || undefined,
        uy3_numero_parcelas_max: dashboard360Filters.uy3_numero_parcelas_max || undefined,
      }
    }

    if (activeTab === "BASE") {
      return {
        search: baseSearchValue || undefined,
        origens: baseOrigemFilter.length ? baseOrigemFilter : undefined,
        cpf: baseCpfMassFilter || undefined,
        names: baseNamesMassFilter || undefined,
        phones: basePhonesMassFilter || undefined,
        with_phones: baseWithPhonesFilter || undefined,
        without_phones: baseNoPhonesFilter || undefined,
        birth_month: baseBirthMonthFilter.length ? baseBirthMonthFilter : undefined,
      }
    }

    if (activeTab === "FGTS") {
      return {
        search: searchValue || undefined,
        status: statusFilter !== "todos" ? statusFilter : undefined,
        motivos: motivosFilter.length ? motivosFilter : undefined,
        origens: origemFilter.length ? origemFilter : undefined,
        origens_hig: higienizacaoFilter.length ? higienizacaoFilter : undefined,
        date_from: dateFromFilter || undefined,
        date_to: dateToFilter || undefined,
        contract_from: contractDateFromFilter || undefined,
        contract_to: contractDateToFilter || undefined,
        cpf: cpfMassFilter || undefined,
        names: namesMassFilter || undefined,
        phones: phonesMassFilter || undefined,
        with_phones: withPhonesFilter || undefined,
        without_phones: noPhonesFilter || undefined,
        vendors: vendorsFilter.length ? vendorsFilter : undefined,
        birth_month: birthMonthFilter.length ? birthMonthFilter : undefined,
        fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
        fgts_consulta_from: fgtsConsultaFromFilter || undefined,
        fgts_consulta_to: fgtsConsultaToFilter || undefined,
      }
    }

    if (activeTab === "UY3") {
      return {
        search: uy3SearchValue || undefined,
        origens: uy3OrigemFilter.length ? uy3OrigemFilter : undefined,
        cpf: uy3CpfMassFilter || undefined,
        names: uy3NamesMassFilter || undefined,
        phones: uy3PhonesMassFilter || undefined,
        with_phones: uy3WithPhonesFilter || undefined,
        without_phones: uy3NoPhonesFilter || undefined,
        birth_month: uy3BirthMonthFilter.length ? uy3BirthMonthFilter : undefined,
        sort: uy3SortBy,
      }
    }

    const catCodes = cltCategoriaCodigos
      ? cltCategoriaCodigos.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean)
      : undefined

    if (activeTab === "CLT") {
      return {
        search: cltSearchValue || undefined,
        status: cltStatusFilter !== "todos" ? cltStatusFilter : undefined,
        origens: cltOrigemFilter.length ? cltOrigemFilter : undefined,
        cpf: cltCpfMassFilter || undefined,
        names: cltNamesMassFilter || undefined,
        phones: cltPhonesMassFilter || undefined,
        with_phones: cltWithPhonesFilter || undefined,
        without_phones: cltNoPhonesFilter || undefined,
        birth_month: cltBirthMonthFilter.length ? cltBirthMonthFilter : undefined,
        clt_situacao: cltSituacao !== "todos" ? cltSituacao : undefined,
        clt_consulta_from: cltConsultaFrom || undefined,
        clt_consulta_to: cltConsultaTo || undefined,
        clt_admissao_from: cltAdmissaoFrom || undefined,
        clt_admissao_to: cltAdmissaoTo || undefined,
        clt_meses_min: cltMesesMin || undefined,
        clt_meses_max: cltMesesMax || undefined,
        clt_inicio_empregador_from: cltInicioEmpregadorFrom || undefined,
        clt_inicio_empregador_to: cltInicioEmpregadorTo || undefined,
        clt_categoria_codigos: catCodes,
        clt_idade_min: cltIdadeMin || undefined,
        clt_idade_max: cltIdadeMax || undefined,
        clt_sexo: cltSexo.length ? (cltSexo as ("M" | "F")[]) : undefined,
        clt_renda_min: cltRendaMin || undefined,
        clt_renda_max: cltRendaMax || undefined,
        clt_base_min: cltBaseMin || undefined,
        clt_base_max: cltBaseMax || undefined,
        clt_margem_min: cltMargemMin || undefined,
        clt_margem_max: cltMargemMax || undefined,
        clt_prestacao_min: cltPrestacaoMin || undefined,
        clt_prestacao_max: cltPrestacaoMax || undefined,
        clt_ativos_min: cltAtivosMin || undefined,
        clt_ativos_max: cltAtivosMax || undefined,
        clt_tem_ativos: cltTemAtivos !== "todos" ? cltTemAtivos : undefined,
        clt_tem_legados: cltTemLegados !== "todos" ? cltTemLegados : undefined,
      }
    }

    return {
      search: mercantilSearchValue || undefined,
      origens: mercantilOrigemFilter.length ? mercantilOrigemFilter : undefined,
      cpf: mercantilCpfMassFilter || undefined,
      names: mercantilNamesMassFilter || undefined,
      phones: mercantilPhonesMassFilter || undefined,
      with_phones: mercantilWithPhonesFilter || undefined,
      without_phones: mercantilNoPhonesFilter || undefined,
      birth_month: mercantilBirthMonthFilter.length ? mercantilBirthMonthFilter : undefined,
      mercantil_status: mercantilStatusFilter.length ? mercantilStatusFilter : undefined,
      mercantil_consulta_from: mercantilConsultaFrom || undefined,
      mercantil_consulta_to: mercantilConsultaTo || undefined,
      mercantil_parcela_min: mercantilParcelaMin || undefined,
      mercantil_parcela_max: mercantilParcelaMax || undefined,
      mercantil_qtd_parcelas_min: mercantilQtdParcelasMin || undefined,
      mercantil_qtd_parcelas_max: mercantilQtdParcelasMax || undefined,
      mercantil_origens: mercantilOrigensMercantilFilter.length ? mercantilOrigensMercantilFilter : undefined,
    }
  }

  /* ================= EXPORT: UI + Polling persistente ================= */

  // Assinar eventos do poller com ID fixo por token
  useEffect(() => {
    const listener = async ({ token, status }: { token: string; status: LeadsExportStatusDTO }) => {
      const tid = `export:${token}`

      // idempotente: cria ou mantém um único toast por token
      toast.loading("Exportando leads", { id: tid, duration: Infinity })

      if (status.status === "ready") {
        toast.success("Export pronto. Baixando…", { id: tid })
        try { await downloadLeadsExport(token) } catch { }
        toast.dismiss(tid)
      } else if (status.status === "error") {
        const msg = status.message || "Falha ao gerar export."
        toast.error(msg, { id: tid })
        toast.dismiss(tid)
      } else if (status.status === "deleted") {
        toast.info("Export finalizado.", { id: tid })
        toast.dismiss(tid)
      } // queued/running: mantém
    }

    leadsExportPoller.on(listener)
    leadsExportPoller.resumeAll()

    const onVisibility = () => {
      if (document.visibilityState === "visible") {
        leadsExportPoller.resumeAll()
      }
    }
    document.addEventListener("visibilitychange", onVisibility)

    return () => {
      leadsExportPoller.off(listener)
      document.removeEventListener("visibilitychange", onVisibility)
    }
  }, [])

  // Inicia export e delega o polling ao singleton
  const handleExport = async (columns: string[]) => {
    const mode: "base" | "fgts" | "clt" | "mercantil" | "uy3" | "360" =
      activeTab === "360"
        ? "360"
        : activeTab === "BASE"
        ? "base"
        : activeTab === "UY3"
          ? "uy3"
          : activeTab === "FGTS"
            ? "fgts"
            : activeTab === "CLT"
              ? "clt"
              : "mercantil"
    const preId = toast.loading("Exportando leads", { duration: Infinity })
    try {
      const { token } = await startLeadsExport(collectFilters(), columns, mode)
      toast.dismiss(preId)
      const tid = `export:${token}`
      toast.loading("Exportando leads", { id: tid, duration: Infinity })
      leadsExportPoller.start(token)
    } catch (err: any) {
      const msg = err?.response?.data?.message || err?.message || "Falha ao iniciar export."
      toast.error(msg, { id: preId })
    }
  }

  const handleExport360 = () => {
    const mappedColumns = Array.from(
      new Set(
        dashboard360VisibleColumns
          .map((column) => DASHBOARD_360_EXPORT_COLUMN_MAP[column])
          .filter(Boolean)
      )
    )
    handleExport(mappedColumns)
  }

  const total = (paginatedData as any)?.total ?? 0
  const totalLeads = totalLeadsData?.total ?? total
  const current_page = (paginatedData as any)?.current_page ?? 1
  const last_page = (paginatedData as any)?.last_page ?? 1

  const ui = activeTab === "360"
    ? {
      mode: "360" as const,
      searchValue: dashboard360SearchValue,
      setSearchValue: setDashboard360SearchValue,
      statusFilter: "todos" as const,
      setStatusFilter: (_: StatusFilter) => {},
      motivosFilter: [] as string[],
      setMotivosFilter: (_: string[]) => {},
      origemFilter: [] as string[],
      setOrigemFilter: (_: string[]) => {},
      higienizacaoFilter: [] as string[],
      setHigienizacaoFilter: (_: string[]) => {},
      dateFromFilter: "",
      setDateFromFilter: (_: string) => {},
      dateToFilter: "",
      setDateToFilter: (_: string) => {},
      contractDateFromFilter: "",
      setContractDateFromFilter: (_: string) => {},
      contractDateToFilter: "",
      setContractDateToFilter: (_: string) => {},
      cpfMassFilter: "",
      setCpfMassFilter: (_: string) => {},
      namesMassFilter: "",
      setNamesMassFilter: (_: string) => {},
      phonesMassFilter: "",
      setPhonesMassFilter: (_: string) => {},
      withPhonesFilter: dashboard360Filters.with_phones,
      setWithPhonesFilter: (value: boolean) => update360Filter("with_phones", value),
      noPhonesFilter: dashboard360Filters.without_phones,
      setNoPhonesFilter: (value: boolean) => update360Filter("without_phones", value),
      vendorsFilter: [] as string[],
      setVendorsFilter: (_: string[]) => {},
      birthMonthFilter: [] as string[],
      setBirthMonthFilter: (_: string[]) => {},
      sortBy: dashboard360SortBy,
      setSortBy: (value: LeadSort | "") => value && setDashboard360SortBy(value),
      fgtsAuthorizedFilter: "todos" as const,
      setFgtsAuthorizedFilter: (_: FgtsStatusFilter) => {},
      fgtsConsultaFromFilter: "",
      setFgtsConsultaFromFilter: (_: string) => {},
      fgtsConsultaToFilter: "",
      setFgtsConsultaToFilter: (_: string) => {},
    }
    : activeTab === "BASE"
    ? {
      mode: "BASE" as const,
      searchValue: baseSearchValue, setSearchValue: setBaseSearchValue,
      statusFilter: "todos" as const, setStatusFilter: (_: StatusFilter) => {},
      motivosFilter: [] as string[], setMotivosFilter: (_: string[]) => {},
      origemFilter: baseOrigemFilter, setOrigemFilter: setBaseOrigemFilter,
      higienizacaoFilter: [] as string[], setHigienizacaoFilter: (_: string[]) => {},
      dateFromFilter: "", setDateFromFilter: (_: string) => {},
      dateToFilter: "", setDateToFilter: (_: string) => {},
      contractDateFromFilter: "", setContractDateFromFilter: (_: string) => {},
      contractDateToFilter: "", setContractDateToFilter: (_: string) => {},
      cpfMassFilter: baseCpfMassFilter, setCpfMassFilter: setBaseCpfMassFilter,
      namesMassFilter: baseNamesMassFilter, setNamesMassFilter: setBaseNamesMassFilter,
      phonesMassFilter: basePhonesMassFilter, setPhonesMassFilter: setBasePhonesMassFilter,
      withPhonesFilter: baseWithPhonesFilter, setWithPhonesFilter: setBaseWithPhonesFilter,
      noPhonesFilter: baseNoPhonesFilter, setNoPhonesFilter: setBaseNoPhonesFilter,
      vendorsFilter: [] as string[], setVendorsFilter: (_: string[]) => {},
      birthMonthFilter: baseBirthMonthFilter, setBirthMonthFilter: setBaseBirthMonthFilter,
      sortBy: baseSortBy,
      setSortBy: (value: LeadSort | "") => {
        if (value) setBaseSortBy(value)
      },
      fgtsAuthorizedFilter: "todos" as const, setFgtsAuthorizedFilter: (_: FgtsStatusFilter) => {},
      fgtsConsultaFromFilter: "", setFgtsConsultaFromFilter: (_: string) => {},
      fgtsConsultaToFilter: "", setFgtsConsultaToFilter: (_: string) => {},
    }
    : activeTab === "UY3"
    ? {
      mode: "UY3" as const,
      searchValue: uy3SearchValue, setSearchValue: setUy3SearchValue,
      statusFilter: "todos" as const, setStatusFilter: (_: StatusFilter) => {},
      motivosFilter: [] as string[], setMotivosFilter: (_: string[]) => {},
      origemFilter: uy3OrigemFilter, setOrigemFilter: setUy3OrigemFilter,
      higienizacaoFilter: [] as string[], setHigienizacaoFilter: (_: string[]) => {},
      dateFromFilter: "", setDateFromFilter: (_: string) => {},
      dateToFilter: "", setDateToFilter: (_: string) => {},
      contractDateFromFilter: "", setContractDateFromFilter: (_: string) => {},
      contractDateToFilter: "", setContractDateToFilter: (_: string) => {},
      cpfMassFilter: uy3CpfMassFilter, setCpfMassFilter: setUy3CpfMassFilter,
      namesMassFilter: uy3NamesMassFilter, setNamesMassFilter: setUy3NamesMassFilter,
      phonesMassFilter: uy3PhonesMassFilter, setPhonesMassFilter: setUy3PhonesMassFilter,
      withPhonesFilter: uy3WithPhonesFilter, setWithPhonesFilter: setUy3WithPhonesFilter,
      noPhonesFilter: uy3NoPhonesFilter, setNoPhonesFilter: setUy3NoPhonesFilter,
      vendorsFilter: [] as string[], setVendorsFilter: (_: string[]) => {},
      birthMonthFilter: uy3BirthMonthFilter, setBirthMonthFilter: setUy3BirthMonthFilter,
      sortBy: uy3SortBy,
      setSortBy: (value: LeadSort | "") => {
        if (value) setUy3SortBy(value)
      },
      fgtsAuthorizedFilter: "todos" as const, setFgtsAuthorizedFilter: (_: FgtsStatusFilter) => {},
      fgtsConsultaFromFilter: "", setFgtsConsultaFromFilter: (_: string) => {},
      fgtsConsultaToFilter: "", setFgtsConsultaToFilter: (_: string) => {},
    }
    : activeTab === "FGTS"
    ? {
      mode: "FGTS" as const,
      searchValue, setSearchValue,
      statusFilter, setStatusFilter,
      motivosFilter, setMotivosFilter,
      origemFilter, setOrigemFilter,
      higienizacaoFilter, setHigienizacaoFilter,
      dateFromFilter, setDateFromFilter,
      dateToFilter, setDateToFilter,
      contractDateFromFilter, setContractDateFromFilter,
      contractDateToFilter, setContractDateToFilter,
      cpfMassFilter, setCpfMassFilter,
      namesMassFilter, setNamesMassFilter,
      phonesMassFilter, setPhonesMassFilter,
      withPhonesFilter, setWithPhonesFilter,
      noPhonesFilter, setNoPhonesFilter,
      vendorsFilter, setVendorsFilter,
      birthMonthFilter, setBirthMonthFilter,
      sortBy: "" as LeadSort | "",
      setSortBy: (_: LeadSort | "") => {},
      fgtsAuthorizedFilter, setFgtsAuthorizedFilter,
      fgtsConsultaFromFilter, setFgtsConsultaFromFilter,
      fgtsConsultaToFilter, setFgtsConsultaToFilter,
    }
    : activeTab === "CLT"
      ? {
        mode: "CLT" as const,
        searchValue: cltSearchValue, setSearchValue: setCltSearchValue,
        statusFilter: cltStatusFilter, setStatusFilter: setCltStatusFilter,
        motivosFilter: cltMotivosFilter, setMotivosFilter: setCltMotivosFilter,
        origemFilter: cltOrigemFilter, setOrigemFilter: setCltOrigemFilter,
        higienizacaoFilter: cltHigienizacaoFilter, setHigienizacaoFilter: setCltHigienizacaoFilter,
        dateFromFilter: cltDateFromFilter, setDateFromFilter: setCltDateFromFilter,
        dateToFilter: cltDateToFilter, setDateToFilter: setCltDateToFilter,
        contractDateFromFilter: cltContractFromFilter, setContractDateFromFilter: setCltContractFromFilter,
        contractDateToFilter: cltContractToFilter, setContractDateToFilter: setCltContractToFilter,
        cpfMassFilter: cltCpfMassFilter, setCpfMassFilter: setCltCpfMassFilter,
        namesMassFilter: cltNamesMassFilter, setNamesMassFilter: setCltNamesMassFilter,
        phonesMassFilter: cltPhonesMassFilter, setPhonesMassFilter: setCltPhonesMassFilter,
        withPhonesFilter: cltWithPhonesFilter, setWithPhonesFilter: setCltWithPhonesFilter,
        noPhonesFilter: cltNoPhonesFilter, setNoPhonesFilter: setCltNoPhonesFilter,
        vendorsFilter: cltVendorsFilter, setVendorsFilter: setCltVendorsFilter,
        birthMonthFilter: cltBirthMonthFilter, setBirthMonthFilter: setCltBirthMonthFilter,
        sortBy: cltSortBy,
        setSortBy: (value: LeadSort | "") => {
          if (value) setCltSortBy(value)
        },
        fgtsAuthorizedFilter, setFgtsAuthorizedFilter,
        fgtsConsultaFromFilter, setFgtsConsultaFromFilter,
        fgtsConsultaToFilter, setFgtsConsultaToFilter,
      }
      : {
        mode: "MERCANTIL" as const,
        searchValue: mercantilSearchValue, setSearchValue: setMercantilSearchValue,
        statusFilter: cltStatusFilter, setStatusFilter: setCltStatusFilter,
        motivosFilter: cltMotivosFilter, setMotivosFilter: setCltMotivosFilter,
        origemFilter: mercantilOrigemFilter, setOrigemFilter: setMercantilOrigemFilter,
        higienizacaoFilter: cltHigienizacaoFilter, setHigienizacaoFilter: setCltHigienizacaoFilter,
        dateFromFilter: cltDateFromFilter, setDateFromFilter: setCltDateFromFilter,
        dateToFilter: cltDateToFilter, setDateToFilter: setCltDateToFilter,
        contractDateFromFilter: cltContractFromFilter, setContractDateFromFilter: setCltContractFromFilter,
        contractDateToFilter: cltContractToFilter, setContractDateToFilter: setCltContractToFilter,
        cpfMassFilter: mercantilCpfMassFilter, setCpfMassFilter: setMercantilCpfMassFilter,
        namesMassFilter: mercantilNamesMassFilter, setNamesMassFilter: setMercantilNamesMassFilter,
        phonesMassFilter: mercantilPhonesMassFilter, setPhonesMassFilter: setMercantilPhonesMassFilter,
        withPhonesFilter: mercantilWithPhonesFilter, setWithPhonesFilter: setMercantilWithPhonesFilter,
        noPhonesFilter: mercantilNoPhonesFilter, setNoPhonesFilter: setMercantilNoPhonesFilter,
        vendorsFilter: cltVendorsFilter, setVendorsFilter: setCltVendorsFilter,
        birthMonthFilter: mercantilBirthMonthFilter, setBirthMonthFilter: setMercantilBirthMonthFilter,
        sortBy: mercantilSortEffective,
        setSortBy: (value: LeadSort | "") => {
          if (value) setMercantilSortBy(value)
        },
        fgtsAuthorizedFilter, setFgtsAuthorizedFilter,
        fgtsConsultaFromFilter, setFgtsConsultaFromFilter,
        fgtsConsultaToFilter, setFgtsConsultaToFilter,
      }

  return (
    <div className="max-w-full p-4 lg:p-6">
      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 className="mb-1 text-xl font-bold lg:text-2xl text-gray-900">
            Base de Leads
          </h1>
          <p className="text-sm text-gray-600 lg:text-base">
           Consulte a carteira de leads e escolha quais informações adicionais deseja visualizar.
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            <span className="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700">
              Leads cadastrados: {totalLeads}
            </span>
          </div>
        </div>

        <label className="flex w-full max-w-xs flex-col gap-1 text-sm font-medium text-gray-700">
          Visão
          <select
            value={activeTab}
            onChange={(event) => {
              setActiveTab(event.target.value as ActiveTab)
              setCurrentPage(1)
            }}
            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="360">360</option>
            <option value="BASE">Somente dados cadastrais</option>
            <option value="FGTS">Cadastrais + FGTS</option>
            <option value="CLT">Cadastrais + Facta</option>
            <option value="MERCANTIL">Cadastrais + Mercantil</option>
            <option value="UY3">Cadastrais + UY3</option>
          </select>
        </label>
      </div>

      <LeadsControls
        mode={ui.mode}
        onImportClick={() => setIsImportModalOpen(true)}
        onExportClick={() => {
          if (activeTab === "360") {
            handleExport360()
            return
          }
          setIsExportModalOpen(true)
        }}
        searchValue={ui.searchValue}
        onSearchChange={ui.setSearchValue}
        eligibleFilter={ui.statusFilter}
        onEligibleFilterChange={ui.setStatusFilter}
        motivosFilter={ui.motivosFilter}
        onMotivosFilterChange={ui.setMotivosFilter}
        origemFilter={ui.origemFilter}
        onOrigemFilterChange={ui.setOrigemFilter}
        higienizacaoFilter={ui.higienizacaoFilter}
        onHigienizacaoFilterChange={ui.setHigienizacaoFilter}
        dateFromFilter={ui.dateFromFilter}
        onDateFromFilterChange={ui.setDateFromFilter}
        dateToFilter={ui.dateToFilter}
        onDateToFilterChange={ui.setDateToFilter}
        contractDateFromFilter={ui.contractDateFromFilter}
        onContractDateFromFilterChange={ui.setContractDateFromFilter}
        contractDateToFilter={ui.contractDateToFilter}
        onContractDateToFilterChange={ui.setContractDateToFilter}
        cpfMassFilter={ui.cpfMassFilter}
        onCpfMassFilterChange={ui.setCpfMassFilter}
        namesMassFilter={ui.namesMassFilter}
        onNamesMassFilterChange={ui.setNamesMassFilter}
        phonesMassFilter={ui.phonesMassFilter}
        onPhonesMassFilterChange={ui.setPhonesMassFilter}
        withPhonesFilter={ui.withPhonesFilter}
        onWithPhonesFilterChange={ui.setWithPhonesFilter}
        noPhonesFilter={ui.noPhonesFilter}
        onNoPhonesFilterChange={ui.setNoPhonesFilter}
        birthMonthFilter={ui.birthMonthFilter}
        onBirthMonthFilterChange={ui.setBirthMonthFilter}
        sortBy={ui.sortBy}
        onSortByChange={ui.setSortBy}
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        availableMotivos={filterOptions?.motivos ?? []}
        availableOrigens={filterOptions?.origens ?? []}
        availableHigienizacoes={filterOptions?.origens_hig ?? []}
        vendorsFilter={ui.vendorsFilter}
        onVendorsFilterChange={ui.setVendorsFilter}
        availableVendors={filterOptions?.vendors ?? []}
        hasActiveFilters={!!hasActiveFilters}
        filteredCount={total}
        fgtsAuthorizedFilter={ui.fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={ui.setFgtsAuthorizedFilter}
        fgtsConsultaFromFilter={ui.fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={ui.setFgtsConsultaFromFilter}
        fgtsConsultaToFilter={ui.fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={ui.setFgtsConsultaToFilter}
        /* CLT */
        cltSituacao={activeTab === "360" ? dashboard360Filters.clt_situacao : cltSituacao}
        onCltSituacaoChange={(value) => activeTab === "360" ? update360Filter("clt_situacao", value) : setCltSituacao(value)}
        cltConsultaFrom={activeTab === "360" ? dashboard360Filters.clt_consulta_from : cltConsultaFrom}
        onCltConsultaFromChange={(value) => activeTab === "360" ? update360Filter("clt_consulta_from", value) : setCltConsultaFrom(value)}
        cltConsultaTo={activeTab === "360" ? dashboard360Filters.clt_consulta_to : cltConsultaTo}
        onCltConsultaToChange={(value) => activeTab === "360" ? update360Filter("clt_consulta_to", value) : setCltConsultaTo(value)}
        cltAdmissaoFrom={activeTab === "360" ? dashboard360Filters.clt_admissao_from : cltAdmissaoFrom}
        onCltAdmissaoFromChange={(value) => activeTab === "360" ? update360Filter("clt_admissao_from", value) : setCltAdmissaoFrom(value)}
        cltAdmissaoTo={activeTab === "360" ? dashboard360Filters.clt_admissao_to : cltAdmissaoTo}
        onCltAdmissaoToChange={(value) => activeTab === "360" ? update360Filter("clt_admissao_to", value) : setCltAdmissaoTo(value)}
        cltMesesMin={activeTab === "360" ? dashboard360Filters.clt_meses_min : cltMesesMin}
        onCltMesesMinChange={(value) => activeTab === "360" ? update360Filter("clt_meses_min", value) : setCltMesesMin(value)}
        cltMesesMax={activeTab === "360" ? dashboard360Filters.clt_meses_max : cltMesesMax}
        onCltMesesMaxChange={(value) => activeTab === "360" ? update360Filter("clt_meses_max", value) : setCltMesesMax(value)}
        cltInicioEmpregadorFrom={activeTab === "360" ? dashboard360Filters.clt_inicio_empregador_from : cltInicioEmpregadorFrom}
        onCltInicioEmpregadorFromChange={(value) => activeTab === "360" ? update360Filter("clt_inicio_empregador_from", value) : setCltInicioEmpregadorFrom(value)}
        cltInicioEmpregadorTo={activeTab === "360" ? dashboard360Filters.clt_inicio_empregador_to : cltInicioEmpregadorTo}
        onCltInicioEmpregadorToChange={(value) => activeTab === "360" ? update360Filter("clt_inicio_empregador_to", value) : setCltInicioEmpregadorTo(value)}
        cltCategoriaCodigos={activeTab === "360" ? dashboard360Filters.clt_categoria_codigos : cltCategoriaCodigos}
        onCltCategoriaCodigosChange={(value) => activeTab === "360" ? update360Filter("clt_categoria_codigos", value) : setCltCategoriaCodigos(value)}
        cltIdadeMin={activeTab === "360" ? dashboard360Filters.clt_idade_min : cltIdadeMin}
        onCltIdadeMinChange={(value) => activeTab === "360" ? update360Filter("clt_idade_min", value) : setCltIdadeMin(value)}
        cltIdadeMax={activeTab === "360" ? dashboard360Filters.clt_idade_max : cltIdadeMax}
        onCltIdadeMaxChange={(value) => activeTab === "360" ? update360Filter("clt_idade_max", value) : setCltIdadeMax(value)}
        cltSexo={activeTab === "360" ? dashboard360Filters.clt_sexo : cltSexo}
        onCltSexoChange={(value) => activeTab === "360" ? update360Filter("clt_sexo", value) : setCltSexo(value)}
        cltRendaMin={activeTab === "360" ? dashboard360Filters.clt_renda_min : cltRendaMin}
        onCltRendaMinChange={(value) => activeTab === "360" ? update360Filter("clt_renda_min", value) : setCltRendaMin(value)}
        cltRendaMax={activeTab === "360" ? dashboard360Filters.clt_renda_max : cltRendaMax}
        onCltRendaMaxChange={(value) => activeTab === "360" ? update360Filter("clt_renda_max", value) : setCltRendaMax(value)}
        cltBaseMin={activeTab === "360" ? dashboard360Filters.clt_base_min : cltBaseMin}
        onCltBaseMinChange={(value) => activeTab === "360" ? update360Filter("clt_base_min", value) : setCltBaseMin(value)}
        cltBaseMax={activeTab === "360" ? dashboard360Filters.clt_base_max : cltBaseMax}
        onCltBaseMaxChange={(value) => activeTab === "360" ? update360Filter("clt_base_max", value) : setCltBaseMax(value)}
        cltMargemMin={activeTab === "360" ? dashboard360Filters.clt_margem_min : cltMargemMin}
        onCltMargemMinChange={(value) => activeTab === "360" ? update360Filter("clt_margem_min", value) : setCltMargemMin(value)}
        cltMargemMax={activeTab === "360" ? dashboard360Filters.clt_margem_max : cltMargemMax}
        onCltMargemMaxChange={(value) => activeTab === "360" ? update360Filter("clt_margem_max", value) : setCltMargemMax(value)}
        cltPrestacaoMin={activeTab === "360" ? dashboard360Filters.clt_prestacao_min : cltPrestacaoMin}
        onCltPrestacaoMinChange={(value) => activeTab === "360" ? update360Filter("clt_prestacao_min", value) : setCltPrestacaoMin(value)}
        cltPrestacaoMax={activeTab === "360" ? dashboard360Filters.clt_prestacao_max : cltPrestacaoMax}
        onCltPrestacaoMaxChange={(value) => activeTab === "360" ? update360Filter("clt_prestacao_max", value) : setCltPrestacaoMax(value)}
        cltAtivosMin={activeTab === "360" ? dashboard360Filters.clt_ativos_min : cltAtivosMin}
        onCltAtivosMinChange={(value) => activeTab === "360" ? update360Filter("clt_ativos_min", value) : setCltAtivosMin(value)}
        cltAtivosMax={activeTab === "360" ? dashboard360Filters.clt_ativos_max : cltAtivosMax}
        onCltAtivosMaxChange={(value) => activeTab === "360" ? update360Filter("clt_ativos_max", value) : setCltAtivosMax(value)}
        cltTemAtivos={activeTab === "360" ? dashboard360Filters.clt_tem_ativos : cltTemAtivos}
        onCltTemAtivosChange={(value) => activeTab === "360" ? update360Filter("clt_tem_ativos", value) : setCltTemAtivos(value)}
        cltTemLegados={activeTab === "360" ? dashboard360Filters.clt_tem_legados : cltTemLegados}
        onCltTemLegadosChange={(value) => activeTab === "360" ? update360Filter("clt_tem_legados", value) : setCltTemLegados(value)}
        mercantilSituacao={activeTab === "360" ? dashboard360Filters.mercantil_situacao : "todos"}
        onMercantilSituacaoChange={(value) => {
          if (activeTab === "360") update360Filter("mercantil_situacao", value as MercantilSituacao360Filter)
        }}
        mercantilStatusFilter={activeTab === "360" ? dashboard360Filters.mercantil_status : mercantilStatusFilter}
        onMercantilStatusFilterChange={(value) => activeTab === "360" ? update360Filter("mercantil_status", value) : setMercantilStatusFilter(value)}
        mercantilConsultaFrom={activeTab === "360" ? dashboard360Filters.mercantil_consulta_from : mercantilConsultaFrom}
        onMercantilConsultaFromChange={(value) => activeTab === "360" ? update360Filter("mercantil_consulta_from", value) : setMercantilConsultaFrom(value)}
        mercantilConsultaTo={activeTab === "360" ? dashboard360Filters.mercantil_consulta_to : mercantilConsultaTo}
        onMercantilConsultaToChange={(value) => activeTab === "360" ? update360Filter("mercantil_consulta_to", value) : setMercantilConsultaTo(value)}
        mercantilParcelaMin={activeTab === "360" ? dashboard360Filters.mercantil_parcela_min : mercantilParcelaMin}
        onMercantilParcelaMinChange={(value) => activeTab === "360" ? update360Filter("mercantil_parcela_min", value) : setMercantilParcelaMin(value)}
        mercantilParcelaMax={activeTab === "360" ? dashboard360Filters.mercantil_parcela_max : mercantilParcelaMax}
        onMercantilParcelaMaxChange={(value) => activeTab === "360" ? update360Filter("mercantil_parcela_max", value) : setMercantilParcelaMax(value)}
        mercantilQtdParcelasMin={activeTab === "360" ? dashboard360Filters.mercantil_qtd_parcelas_min : mercantilQtdParcelasMin}
        onMercantilQtdParcelasMinChange={(value) => activeTab === "360" ? update360Filter("mercantil_qtd_parcelas_min", value) : setMercantilQtdParcelasMin(value)}
        mercantilQtdParcelasMax={activeTab === "360" ? dashboard360Filters.mercantil_qtd_parcelas_max : mercantilQtdParcelasMax}
        onMercantilQtdParcelasMaxChange={(value) => activeTab === "360" ? update360Filter("mercantil_qtd_parcelas_max", value) : setMercantilQtdParcelasMax(value)}
        mercantilOrigensFilter={activeTab === "360" ? dashboard360Filters.mercantil_origens : mercantilOrigensMercantilFilter}
        onMercantilOrigensFilterChange={(value) => activeTab === "360" ? update360Filter("mercantil_origens", value) : setMercantilOrigensMercantilFilter(value)}
        availableMercantilOrigens={filterOptions?.origens_mercantil ?? []}
        availableMercantilStatuses={filterOptions?.mercantil_status ?? []}
        selectedBanks={activeTab === "360" ? dashboard360SelectedBanks : []}
        onSelectedBanksChange={(value) => activeTab === "360" && update360Filter("selected_banks", value.filter((bank) => bank !== "fgts"))}
        bankCombinationMode={activeTab === "360" ? dashboard360Filters.bank_combination_mode : "any"}
        onBankCombinationModeChange={(value) => activeTab === "360" && update360Filter("bank_combination_mode", value)}
        uy3Situacao={activeTab === "360" ? dashboard360Filters.uy3_situacao : "todos"}
        onUy3SituacaoChange={(value) => activeTab === "360" && update360Filter("uy3_situacao", value)}
        uy3ConsultaFrom={activeTab === "360" ? dashboard360Filters.uy3_consulta_from : ""}
        onUy3ConsultaFromChange={(value) => activeTab === "360" && update360Filter("uy3_consulta_from", value)}
        uy3ConsultaTo={activeTab === "360" ? dashboard360Filters.uy3_consulta_to : ""}
        onUy3ConsultaToChange={(value) => activeTab === "360" && update360Filter("uy3_consulta_to", value)}
        uy3MesesAdmissaoMin={activeTab === "360" ? dashboard360Filters.uy3_meses_admissao_min : ""}
        onUy3MesesAdmissaoMinChange={(value) => activeTab === "360" && update360Filter("uy3_meses_admissao_min", value)}
        uy3MesesAdmissaoMax={activeTab === "360" ? dashboard360Filters.uy3_meses_admissao_max : ""}
        onUy3MesesAdmissaoMaxChange={(value) => activeTab === "360" && update360Filter("uy3_meses_admissao_max", value)}
        uy3MargemMin={activeTab === "360" ? dashboard360Filters.uy3_margem_min : ""}
        onUy3MargemMinChange={(value) => activeTab === "360" && update360Filter("uy3_margem_min", value)}
        uy3MargemMax={activeTab === "360" ? dashboard360Filters.uy3_margem_max : ""}
        onUy3MargemMaxChange={(value) => activeTab === "360" && update360Filter("uy3_margem_max", value)}
        uy3ValorLiberadoMin={activeTab === "360" ? dashboard360Filters.uy3_valor_liberado_min : ""}
        onUy3ValorLiberadoMinChange={(value) => activeTab === "360" && update360Filter("uy3_valor_liberado_min", value)}
        uy3ValorLiberadoMax={activeTab === "360" ? dashboard360Filters.uy3_valor_liberado_max : ""}
        onUy3ValorLiberadoMaxChange={(value) => activeTab === "360" && update360Filter("uy3_valor_liberado_max", value)}
        uy3NumeroParcelasMin={activeTab === "360" ? dashboard360Filters.uy3_numero_parcelas_min : ""}
        onUy3NumeroParcelasMinChange={(value) => activeTab === "360" && update360Filter("uy3_numero_parcelas_min", value)}
        uy3NumeroParcelasMax={activeTab === "360" ? dashboard360Filters.uy3_numero_parcelas_max : ""}
        onUy3NumeroParcelasMaxChange={(value) => activeTab === "360" && update360Filter("uy3_numero_parcelas_max", value)}
        visibleColumns360={dashboard360VisibleColumns}
        onVisibleColumns360Change={setDashboard360VisibleColumns}
        visibleColumnsBASE={baseVisibleColumns}
        onVisibleColumnsBASEChange={setBaseVisibleColumns}
        visibleColumnsFGTS={fgtsVisibleColumns}
        onVisibleColumnsFGTSChange={setFgtsVisibleColumns}
        visibleColumnsCLT={cltVisibleColumns}
        onVisibleColumnsCLTChange={setCltVisibleColumns}
        visibleColumnsMERCANTIL={mercantilVisibleColumns}
        onVisibleColumnsMERCANTILChange={setMercantilVisibleColumns}
        visibleColumnsUY3={uy3VisibleColumns}
        onVisibleColumnsUY3Change={setUy3VisibleColumns}
        defaultVisibleColumns360={DASHBOARD_360_COLUMNS_DEFAULT}
        defaultVisibleColumnsBASE={BASE_COLUMNS_DEFAULT}
        defaultVisibleColumnsFGTS={FGTS_COLUMNS_DEFAULT}
        defaultVisibleColumnsCLT={CLT_COLUMNS_DEFAULT}
        defaultVisibleColumnsMERCANTIL={MERCANTIL_COLUMNS_DEFAULT}
        defaultVisibleColumnsUY3={UY3_COLUMNS_DEFAULT}
        stickyIdentityColumns360={dashboard360StickyIdentityColumns}
        onStickyIdentityColumns360Change={setDashboard360StickyIdentityColumns}
      />

      {activeTab === "360" ? (
        <LeadsTable360
          leads={processedLeads360}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={dashboard360VisibleColumns}
          stickyIdentityColumns={dashboard360StickyIdentityColumns}
        />
      ) : activeTab === "BASE" ? (
        <LeadsTableFGTS
          leads={processedLeadsBase}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={baseVisibleColumns}
        />
      ) : activeTab === "UY3" ? (
        <LeadsTableUy3
          leads={processedLeadsUy3}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={uy3VisibleColumns}
        />
      ) : activeTab === "FGTS" ? (
        <LeadsTableFGTS
          leads={processedLeadsFGTS}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={fgtsVisibleColumns}
        />
      ) : activeTab === "CLT" ? (
        <LeadsTableCLT
          leads={processedLeadsCLT}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={cltVisibleColumns}
        />
      ) : (
        <LeadsTableMercantil
          leads={processedLeadsMercantil}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || isFetching || loadingOptions}
          visibleColumns={mercantilVisibleColumns}
        />
      )}

      <ImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        onImportSuccess={() => {
          refetch()
          refetchTotalLeads()
        }}
      />

      <ExportModal
        isOpen={isExportModalOpen}
        onClose={() => setIsExportModalOpen(false)}
        onExport={handleExport}
        mode={activeTab}
      />
    </div>
  )
}

export default Dashboard
