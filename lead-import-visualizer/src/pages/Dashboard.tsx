import { useState, useMemo, useEffect } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"
import { usePersistedState } from "@/hooks/usePersistedState"

import {
  LeadsTableFGTS,
  LeadsTableCLT,
  LeadsTableMercantil,
  ProcessedLeadFGTS,
  ProcessedLeadCLT,
  ProcessedLeadMercantil,
} from "@/components/LeadsTable"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import {
  fetchLeadsFGTS,
  fetchLeadsCLT,
  fetchLeadsMercantil,
  fetchLeadsFilters,
  // export async + poller
  startLeadsExport,
  downloadLeadsExport,
  leadsExportPoller,
  LeadFromApiFGTS,
  LeadFromApiCLT,
  LeadFromApiMercantil,
  PaginatedLeadsResponseFGTS,
  PaginatedLeadsResponseCLT,
  PaginatedLeadsResponseMercantil,
  LeadsExportStatusDTO,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatPhone,
  formatDateOnly
} from "@/lib/formatters"
import { cn } from "@/lib/utils"

type StatusFilter = "todos" | "elegiveis" | "nao-elegiveis"
type FgtsStatusFilter = "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"
type YesNoAll = "todos" | "sim" | "nao"
type CltSituacaoFilter = "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel"
type ActiveTab = "FGTS" | "CLT" | "MERCANTIL"

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
  "data_admissao", "meses_admissao", "categoria_trabalhador_codigo",
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
  "mercantil_dados_atualizados_em",
  "ultima_origem_cadastral",
  "ultima_origem_mercantil",
]

const Dashboard = () => {
  const [activeTab, setActiveTab] = usePersistedState<ActiveTab>("dashboard:activeTab", "FGTS")
  const [currentPage, setCurrentPage] = useState(1)

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
  const [cltNoPhonesFilter, setCltNoPhonesFilter] = usePersistedState<boolean>("dashboard-clt:noPhonesFilter", false)
  const [cltVendorsFilter, setCltVendorsFilter] = usePersistedState<string[]>("dashboard-clt:vendorsFilter", [])
  const [cltBirthMonthFilter, setCltBirthMonthFilter] = usePersistedState<string[]>("dashboard-clt:birthMonthFilter", [])

  const [cltConsultado, setCltConsultado] = usePersistedState<YesNoAll>("dashboard-clt:consultado", "todos")
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

  const [mercantilSearchValue, setMercantilSearchValue] = usePersistedState<string>("dashboard-mercantil:searchValue", "")
  const [mercantilOrigemFilter, setMercantilOrigemFilter] = usePersistedState<string[]>("dashboard-mercantil:origemFilter", [])
  const [mercantilCpfMassFilter, setMercantilCpfMassFilter] = usePersistedState<string>("dashboard-mercantil:cpfMassFilter", "")
  const [mercantilNamesMassFilter, setMercantilNamesMassFilter] = usePersistedState<string>("dashboard-mercantil:namesMassFilter", "")
  const [mercantilPhonesMassFilter, setMercantilPhonesMassFilter] = usePersistedState<string>("dashboard-mercantil:phonesMassFilter", "")
  const [mercantilNoPhonesFilter, setMercantilNoPhonesFilter] = usePersistedState<boolean>("dashboard-mercantil:noPhonesFilter", false)
  const [mercantilBirthMonthFilter, setMercantilBirthMonthFilter] = usePersistedState<string[]>("dashboard-mercantil:birthMonthFilter", [])
  const [mercantilSituacao, setMercantilSituacao] = usePersistedState<"todos" | "consultado" | "sem_consulta">(
    "dashboard-mercantil:situacao",
    "todos"
  )
  const [mercantilStatusFilter, setMercantilStatusFilter] = usePersistedState<string[]>("dashboard-mercantil:statusFilter", [])
  const [mercantilConsultaFrom, setMercantilConsultaFrom] = usePersistedState<string>("dashboard-mercantil:consultaFrom", "")
  const [mercantilConsultaTo, setMercantilConsultaTo] = usePersistedState<string>("dashboard-mercantil:consultaTo", "")
  const [mercantilImportFrom, setMercantilImportFrom] = usePersistedState<string>("dashboard-mercantil:importFrom", "")
  const [mercantilImportTo, setMercantilImportTo] = usePersistedState<string>("dashboard-mercantil:importTo", "")
  const [mercantilParcelaMin, setMercantilParcelaMin] = usePersistedState<string>("dashboard-mercantil:parcelaMin", "")
  const [mercantilParcelaMax, setMercantilParcelaMax] = usePersistedState<string>("dashboard-mercantil:parcelaMax", "")
  const [mercantilQtdParcelasMin, setMercantilQtdParcelasMin] = usePersistedState<string>("dashboard-mercantil:qtdParcelasMin", "")
  const [mercantilQtdParcelasMax, setMercantilQtdParcelasMax] = usePersistedState<string>("dashboard-mercantil:qtdParcelasMax", "")
  const [mercantilOrigensMercantilFilter, setMercantilOrigensMercantilFilter] = usePersistedState<string[]>(
    "dashboard-mercantil:origensMercantilFilter",
    []
  )
  const mercantilStatusEffective = mercantilSituacao === "sem_consulta" ? [] : mercantilStatusFilter

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
    data: paginatedData,
    isLoading,
    isFetching,
    isError,
    refetch,
  } = useQuery<PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT | PaginatedLeadsResponseMercantil>({
    queryKey: [
      "leads",
      activeTab,
      currentPage,
      // FGTS
      searchValue, statusFilter, motivosFilter, origemFilter, higienizacaoFilter,
      dateFromFilter, dateToFilter, contractDateFromFilter, contractDateToFilter,
      cpfMassFilter, namesMassFilter, phonesMassFilter, noPhonesFilter, vendorsFilter, birthMonthFilter,
      fgtsAuthorizedFilter, fgtsConsultaFromFilter, fgtsConsultaToFilter,
      // CLT
      cltSearchValue, cltStatusFilter, cltMotivosFilter, cltOrigemFilter, cltHigienizacaoFilter,
      cltDateFromFilter, cltDateToFilter, cltContractFromFilter, cltContractToFilter,
      cltCpfMassFilter, cltNamesMassFilter, cltPhonesMassFilter, cltNoPhonesFilter, cltVendorsFilter, cltBirthMonthFilter,
      cltConsultado, cltSituacao,
      cltConsultaFrom, cltConsultaTo, cltAdmissaoFrom, cltAdmissaoTo, cltMesesMin, cltMesesMax,
      cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltCategoriaCodigos, cltIdadeMin, cltIdadeMax,
      cltSexo, cltRendaMin, cltRendaMax, cltBaseMin, cltBaseMax, cltMargemMin, cltMargemMax,
      cltPrestacaoMin, cltPrestacaoMax, cltAtivosMin, cltAtivosMax, cltTemAtivos, cltTemLegados,
      // MERCANTIL
      mercantilSearchValue,
      mercantilOrigemFilter,
      mercantilCpfMassFilter, mercantilNamesMassFilter, mercantilPhonesMassFilter, mercantilNoPhonesFilter,
      mercantilBirthMonthFilter,
      mercantilSituacao, mercantilStatusEffective,
      mercantilConsultaFrom, mercantilConsultaTo,
      mercantilImportFrom, mercantilImportTo,
      mercantilParcelaMin, mercantilParcelaMax,
      mercantilQtdParcelasMin, mercantilQtdParcelasMax,
      mercantilOrigensMercantilFilter,
    ],
    queryFn: async (): Promise<PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT | PaginatedLeadsResponseMercantil> => {
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
          without_phones: cltNoPhonesFilter || undefined,
          birth_month: cltBirthMonthFilter,
          clt_consultado: cltConsultado !== "todos" ? cltConsultado : undefined,
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
        })
      }

      return fetchLeadsMercantil({
        page: currentPage,
        search: mercantilSearchValue,
        origens: mercantilOrigemFilter,
        cpf: mercantilCpfMassFilter,
        names: mercantilNamesMassFilter,
        phones: mercantilPhonesMassFilter,
        without_phones: mercantilNoPhonesFilter || undefined,
        birth_month: mercantilBirthMonthFilter,
        mercantil_situacao:
          mercantilSituacao === "consultado" || mercantilSituacao === "sem_consulta"
            ? mercantilSituacao
            : undefined,
        mercantil_status: mercantilStatusEffective.length ? mercantilStatusEffective : undefined,
        mercantil_consulta_from: mercantilConsultaFrom || undefined,
        mercantil_consulta_to: mercantilConsultaTo || undefined,
        mercantil_import_from: mercantilImportFrom || undefined,
        mercantil_import_to: mercantilImportTo || undefined,
        mercantil_parcela_min: mercantilParcelaMin || undefined,
        mercantil_parcela_max: mercantilParcelaMax || undefined,
        mercantil_qtd_parcelas_min: mercantilQtdParcelasMin || undefined,
        mercantil_qtd_parcelas_max: mercantilQtdParcelasMax || undefined,
        mercantil_origens: mercantilOrigensMercantilFilter.length ? mercantilOrigensMercantilFilter : undefined,
      })
    },
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
  })

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
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
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
        politica_credito_data_consulta: lead.politica_credito_data_consulta ? formatDate(lead.politica_credito_data_consulta) : "",
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
        data_nascimento: lead.data_nascimento ? formatDateOnly(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        ultima_origem_mercantil: lead.ultima_origem_mercantil || "",
        mercantil_status: lead.mercantil_status || "",
        mercantil_mensagem_erro: lead.mercantil_mensagem_erro || "",
        mercantil_data_hora_origem: lead.mercantil_data_hora_origem ? formatDate(lead.mercantil_data_hora_origem) : "",
        mercantil_valor_financiado: formatCurrency(lead.mercantil_valor_financiado as any),
        mercantil_valor_iof: formatCurrency(lead.mercantil_valor_iof as any),
        mercantil_data_primeiro_vencimento: lead.mercantil_data_primeiro_vencimento ? formatDateOnly(lead.mercantil_data_primeiro_vencimento) : "",
        mercantil_valor_emprestimo: formatCurrency(lead.mercantil_valor_emprestimo as any),
        mercantil_quantidade_parcelas: lead.mercantil_quantidade_parcelas ?? "",
        mercantil_valor_liberado: formatCurrency(lead.mercantil_valor_liberado as any),
        mercantil_taxa_juros_mes: taxaFmt,
        mercantil_valor_parcela: formatCurrency(lead.mercantil_valor_parcela as any),
        mercantil_dados_atualizados_em: lead.mercantil_dados_atualizados_em ? formatDate(lead.mercantil_dados_atualizados_em) : "",
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
    setCltNoPhonesFilter(false)
    setCltVendorsFilter([])
    setCltBirthMonthFilter([])
    setCltConsultado("todos")
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
  }

  const clearMercantil = () => {
    setMercantilSearchValue("")
    setMercantilOrigemFilter([])
    setMercantilCpfMassFilter("")
    setMercantilNamesMassFilter("")
    setMercantilPhonesMassFilter("")
    setMercantilNoPhonesFilter(false)
    setMercantilBirthMonthFilter([])
    setMercantilSituacao("todos")
    setMercantilStatusFilter([])
    setMercantilConsultaFrom("")
    setMercantilConsultaTo("")
    setMercantilImportFrom("")
    setMercantilImportTo("")
    setMercantilParcelaMin("")
    setMercantilParcelaMax("")
    setMercantilQtdParcelasMin("")
    setMercantilQtdParcelasMax("")
    setMercantilOrigensMercantilFilter([])
  }

  const handleClearFilters = () => {
    if (activeTab === "FGTS") clearFgts()
    else if (activeTab === "CLT") clearClt()
    else clearMercantil()
    setCurrentPage(1)
    setAwaitingFetch("clear")
    if (pendingToastId) toast.dismiss(pendingToastId)
    const id = toast.loading("Limpando filtros…")
    setPendingToastId(id)
  }

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
    noPhonesFilter ||
    higienizacaoFilter.length ||
    vendorsFilter.length ||
    birthMonthFilter.length ||
    fgtsAuthorizedFilter !== "todos" ||
    fgtsConsultaFromFilter ||
    fgtsConsultaToFilter

  const hasActiveFiltersCLT =
    cltSearchValue ||
    cltStatusFilter !== "todos" ||
    cltOrigemFilter.length ||
    cltCpfMassFilter ||
    cltNamesMassFilter ||
    cltPhonesMassFilter ||
    cltNoPhonesFilter ||
    cltBirthMonthFilter.length ||
    cltConsultado !== "todos" ||
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
    mercantilNoPhonesFilter ||
    mercantilBirthMonthFilter.length ||
    mercantilSituacao !== "todos" ||
    mercantilStatusEffective.length ||
    mercantilConsultaFrom ||
    mercantilConsultaTo ||
    mercantilImportFrom ||
    mercantilImportTo ||
    mercantilParcelaMin ||
    mercantilParcelaMax ||
    mercantilQtdParcelasMin ||
    mercantilQtdParcelasMax ||
    mercantilOrigensMercantilFilter.length

  const hasActiveFilters =
    activeTab === "FGTS"
      ? hasActiveFiltersFGTS
      : activeTab === "CLT"
        ? hasActiveFiltersCLT
        : hasActiveFiltersMercantil

  const collectFilters = () => {
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
        without_phones: noPhonesFilter || undefined,
        vendors: vendorsFilter.length ? vendorsFilter : undefined,
        birth_month: birthMonthFilter.length ? birthMonthFilter : undefined,
        fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
        fgts_consulta_from: fgtsConsultaFromFilter || undefined,
        fgts_consulta_to: fgtsConsultaToFilter || undefined,
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
        without_phones: cltNoPhonesFilter || undefined,
        birth_month: cltBirthMonthFilter.length ? cltBirthMonthFilter : undefined,
        clt_consultado: cltConsultado !== "todos" ? cltConsultado : undefined,
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
      without_phones: mercantilNoPhonesFilter || undefined,
      birth_month: mercantilBirthMonthFilter.length ? mercantilBirthMonthFilter : undefined,
      mercantil_situacao:
        mercantilSituacao === "consultado" || mercantilSituacao === "sem_consulta"
          ? mercantilSituacao
          : undefined,
      mercantil_status: mercantilStatusEffective.length ? mercantilStatusEffective : undefined,
      mercantil_consulta_from: mercantilConsultaFrom || undefined,
      mercantil_consulta_to: mercantilConsultaTo || undefined,
      mercantil_import_from: mercantilImportFrom || undefined,
      mercantil_import_to: mercantilImportTo || undefined,
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
    const mode: "fgts" | "clt" | "mercantil" =
      activeTab === "FGTS" ? "fgts" : activeTab === "CLT" ? "clt" : "mercantil"
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

  const total = (paginatedData as any)?.total ?? 0
  const current_page = (paginatedData as any)?.current_page ?? 1
  const last_page = (paginatedData as any)?.last_page ?? 1

  const ui = activeTab === "FGTS"
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
      noPhonesFilter, setNoPhonesFilter,
      vendorsFilter, setVendorsFilter,
      birthMonthFilter, setBirthMonthFilter,
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
        noPhonesFilter: cltNoPhonesFilter, setNoPhonesFilter: setCltNoPhonesFilter,
        vendorsFilter: cltVendorsFilter, setVendorsFilter: setCltVendorsFilter,
        birthMonthFilter: cltBirthMonthFilter, setBirthMonthFilter: setCltBirthMonthFilter,
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
        noPhonesFilter: mercantilNoPhonesFilter, setNoPhonesFilter: setMercantilNoPhonesFilter,
        vendorsFilter: cltVendorsFilter, setVendorsFilter: setCltVendorsFilter,
        birthMonthFilter: mercantilBirthMonthFilter, setBirthMonthFilter: setMercantilBirthMonthFilter,
        fgtsAuthorizedFilter, setFgtsAuthorizedFilter,
        fgtsConsultaFromFilter, setFgtsConsultaFromFilter,
        fgtsConsultaToFilter, setFgtsConsultaToFilter,
      }

  return (
    <div className="max-w-full p-4 lg:p-6">
      <div className="mb-4">
        <h1 className="mb-1 text-xl font-bold lg:text-2xl text-gray-900">
          Dashboard
        </h1>
        <p className="text-sm text-gray-600 lg:text-base">
          {
            activeTab === "FGTS"
              ? "FGTS (Facta FGTS Base offline)"
              : activeTab === "CLT"
                ? "CLT (Facta Crédito do Trabalhador)"
                : "CLT (Mercantil)"
          } — {total} registros
        </p>
      </div>

      <div className="mb-4 flex gap-2">
        <button
          onClick={() => {
            setActiveTab("FGTS")
            setCurrentPage(1)
          }}
          className={cn(
            "px-6 py-2 rounded-md text-sm font-medium transition-all duration-200",
            activeTab === "FGTS"
              ? "bg-blue-600 text-white shadow-sm"
              : "text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          )}
        >
          FGTS
        </button>

        <button
          onClick={() => {
            setActiveTab("CLT")
            setCurrentPage(1)
          }}
          className={cn(
            "px-6 py-2 rounded-md text-sm font-medium transition-all duration-200",
            activeTab === "CLT"
              ? "bg-blue-600 text-white shadow-sm"
              : "text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          )}
        >
          CLT (Facta)
        </button>

        <button
          onClick={() => {
            setActiveTab("MERCANTIL")
            setCurrentPage(1)
          }}
          className={cn(
            "px-6 py-2 rounded-md text-sm font-medium transition-all duration-200",
            activeTab === "MERCANTIL"
              ? "bg-blue-600 text-white shadow-sm"
              : "text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          )}
        >
          CLT (Mercantil)
        </button>
      </div>

      <LeadsControls
        mode={ui.mode}
        onImportClick={() => setIsImportModalOpen(true)}
        onExportClick={() => setIsExportModalOpen(true)}
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
        noPhonesFilter={ui.noPhonesFilter}
        onNoPhonesFilterChange={ui.setNoPhonesFilter}
        birthMonthFilter={ui.birthMonthFilter}
        onBirthMonthFilterChange={ui.setBirthMonthFilter}
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        availableMotivos={filterOptions?.motivos ?? []}
        availableOrigens={filterOptions?.origens ?? []}
        availableHigienizacoes={filterOptions?.origens_hig ?? []}
        vendorsFilter={ui.vendorsFilter}
        onVendorsFilterChange={ui.setVendorsFilter}
        availableVendors={filterOptions?.vendors ?? []}
        hasActiveFilters={!!hasActiveFilters}
        fgtsAuthorizedFilter={ui.fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={ui.setFgtsAuthorizedFilter}
        fgtsConsultaFromFilter={ui.fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={ui.setFgtsConsultaFromFilter}
        fgtsConsultaToFilter={ui.fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={ui.setFgtsConsultaToFilter}
        /* CLT */
        cltConsultado={cltConsultado}
        onCltConsultadoChange={setCltConsultado}
        cltSituacao={cltSituacao}
        onCltSituacaoChange={setCltSituacao}
        cltConsultaFrom={cltConsultaFrom}
        onCltConsultaFromChange={setCltConsultaFrom}
        cltConsultaTo={cltConsultaTo}
        onCltConsultaToChange={setCltConsultaTo}
        cltAdmissaoFrom={cltAdmissaoFrom}
        onCltAdmissaoFromChange={setCltAdmissaoFrom}
        cltAdmissaoTo={cltAdmissaoTo}
        onCltAdmissaoToChange={setCltAdmissaoTo}
        cltMesesMin={cltMesesMin}
        onCltMesesMinChange={setCltMesesMin}
        cltMesesMax={cltMesesMax}
        onCltMesesMaxChange={setCltMesesMax}
        cltInicioEmpregadorFrom={cltInicioEmpregadorFrom}
        onCltInicioEmpregadorFromChange={setCltInicioEmpregadorFrom}
        cltInicioEmpregadorTo={cltInicioEmpregadorTo}
        onCltInicioEmpregadorToChange={setCltInicioEmpregadorTo}
        cltCategoriaCodigos={cltCategoriaCodigos}
        onCltCategoriaCodigosChange={setCltCategoriaCodigos}
        cltIdadeMin={cltIdadeMin}
        onCltIdadeMinChange={setCltIdadeMin}
        cltIdadeMax={cltIdadeMax}
        onCltIdadeMaxChange={setCltIdadeMax}
        cltSexo={cltSexo}
        onCltSexoChange={setCltSexo}
        cltRendaMin={cltRendaMin}
        onCltRendaMinChange={setCltRendaMin}
        cltRendaMax={cltRendaMax}
        onCltRendaMaxChange={setCltRendaMax}
        cltBaseMin={cltBaseMin}
        onCltBaseMinChange={setCltBaseMin}
        cltBaseMax={cltBaseMax}
        onCltBaseMaxChange={setCltBaseMax}
        cltMargemMin={cltMargemMin}
        onCltMargemMinChange={setCltMargemMin}
        cltMargemMax={cltMargemMax}
        onCltMargemMaxChange={setCltMargemMax}
        cltPrestacaoMin={cltPrestacaoMin}
        onCltPrestacaoMinChange={setCltPrestacaoMin}
        cltPrestacaoMax={cltPrestacaoMax}
        onCltPrestacaoMaxChange={setCltPrestacaoMax}
        cltAtivosMin={cltAtivosMin}
        onCltAtivosMinChange={setCltAtivosMin}
        cltAtivosMax={cltAtivosMax}
        onCltAtivosMaxChange={setCltAtivosMax}
        cltTemAtivos={cltTemAtivos}
        onCltTemAtivosChange={setCltTemAtivos}
        cltTemLegados={cltTemLegados}
        onCltTemLegadosChange={setCltTemLegados}
        mercantilSituacao={mercantilSituacao}
        onMercantilSituacaoChange={setMercantilSituacao}
        mercantilStatusFilter={mercantilStatusFilter}
        onMercantilStatusFilterChange={setMercantilStatusFilter}
        mercantilConsultaFrom={mercantilConsultaFrom}
        onMercantilConsultaFromChange={setMercantilConsultaFrom}
        mercantilConsultaTo={mercantilConsultaTo}
        onMercantilConsultaToChange={setMercantilConsultaTo}
        mercantilImportFrom={mercantilImportFrom}
        onMercantilImportFromChange={setMercantilImportFrom}
        mercantilImportTo={mercantilImportTo}
        onMercantilImportToChange={setMercantilImportTo}
        mercantilParcelaMin={mercantilParcelaMin}
        onMercantilParcelaMinChange={setMercantilParcelaMin}
        mercantilParcelaMax={mercantilParcelaMax}
        onMercantilParcelaMaxChange={setMercantilParcelaMax}
        mercantilQtdParcelasMin={mercantilQtdParcelasMin}
        onMercantilQtdParcelasMinChange={setMercantilQtdParcelasMin}
        mercantilQtdParcelasMax={mercantilQtdParcelasMax}
        onMercantilQtdParcelasMaxChange={setMercantilQtdParcelasMax}
        mercantilOrigensFilter={mercantilOrigensMercantilFilter}
        onMercantilOrigensFilterChange={setMercantilOrigensMercantilFilter}
        availableMercantilOrigens={filterOptions?.origens_mercantil ?? []}
        availableMercantilStatuses={filterOptions?.mercantil_status ?? []}
        visibleColumnsFGTS={fgtsVisibleColumns}
        onVisibleColumnsFGTSChange={setFgtsVisibleColumns}
        visibleColumnsCLT={cltVisibleColumns}
        onVisibleColumnsCLTChange={setCltVisibleColumns}
        visibleColumnsMERCANTIL={mercantilVisibleColumns}
        onVisibleColumnsMERCANTILChange={setMercantilVisibleColumns}
        defaultVisibleColumnsFGTS={FGTS_COLUMNS_DEFAULT}
        defaultVisibleColumnsCLT={CLT_COLUMNS_DEFAULT}
        defaultVisibleColumnsMERCANTIL={MERCANTIL_COLUMNS_DEFAULT}
      />

      {activeTab === "FGTS" ? (
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
        onImportSuccess={() => refetch()}
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
