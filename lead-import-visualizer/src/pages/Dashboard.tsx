import { useState, useMemo, useEffect } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"
import { usePersistedState } from "@/hooks/usePersistedState"

import {
  LeadsTableFGTS,
  LeadsTableCLT,
  ProcessedLeadFGTS,
  ProcessedLeadCLT,
} from "@/components/LeadsTable"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import {
  fetchLeadsFGTS,
  fetchLeadsCLT,
  fetchLeadsFilters,
  exportLeads,
  LeadFromApiFGTS,
  LeadFromApiCLT,
  PaginatedLeadsResponseFGTS,
  PaginatedLeadsResponseCLT,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatPhone,
} from "@/lib/formatters"
import { cn } from "@/lib/utils"

type StatusFilter = "todos" | "elegiveis" | "nao-elegiveis"
type FgtsStatusFilter = "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"
type YesNoAll = "todos" | "sim" | "nao"
type CltSituacaoFilter = "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel"
type ActiveTab = "FGTS" | "CLT"

const Dashboard = () => {
  const [activeTab, setActiveTab] = usePersistedState<ActiveTab>("dashboard:activeTab", "FGTS")
  const [currentPage, setCurrentPage] = useState(1)

  /* =========================  FGTS (persistido)  ========================= */
  const [searchValue, setSearchValue] = usePersistedState<string>("dashboard:searchValue", "")
  const [statusFilter, setStatusFilter] = usePersistedState<StatusFilter>("dashboard:statusFilter", "todos")
  const [motivosFilter, setMotivosFilter] = usePersistedState<string[]>("dashboard:motivosFilter", [])
  const [origemFilter, setOrigemFilter] = usePersistedState<string[]>("dashboard:origemFilter", [])
  const [higienizacaoFilter, setHigienizacaoFilter] = usePersistedState<string[]>("dashboard:higienizacaoFilter", [])
  const [dateFromFilter, setDateFromFilter] = usePersistedState<string>("dashboard:dateFromFilter", "")
  const [dateToFilter,    setDateToFilter] = usePersistedState<string>("dashboard:dateToFilter", "")
  const [contractDateFromFilter, setContractDateFromFilter] = usePersistedState<string>("dashboard:contractDateFromFilter", "")
  const [contractDateToFilter,   setContractDateToFilter]   = usePersistedState<string>("dashboard:contractDateToFilter", "")
  const [cpfMassFilter,   setCpfMassFilter]   = usePersistedState<string>("dashboard:cpfMassFilter", "")
  const [namesMassFilter, setNamesMassFilter] = usePersistedState<string>("dashboard:namesMassFilter", "")
  const [phonesMassFilter,setPhonesMassFilter]= usePersistedState<string>("dashboard:phonesMassFilter", "")
  const [vendorsFilter, setVendorsFilter] = usePersistedState<string[]>("dashboard:vendorsFilter", [])
  const [birthMonthFilter, setBirthMonthFilter] = usePersistedState<string[]>("dashboard:birthMonthFilter", [])

  /** ➕ FGTS OFF (tri-estado) – só fazem efeito no modo FGTS */
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
  const [cltDateToFilter,   setCltDateToFilter]   = usePersistedState<string>("dashboard-clt:dateToFilter", "")
  const [cltContractFromFilter, setCltContractFromFilter] = usePersistedState<string>("dashboard-clt:contractDateFromFilter", "")
  const [cltContractToFilter,   setCltContractToFilter]   = usePersistedState<string>("dashboard-clt:contractDateToFilter", "")
  const [cltCpfMassFilter,    setCltCpfMassFilter]    = usePersistedState<string>("dashboard-clt:cpfMassFilter", "")
  const [cltNamesMassFilter,  setCltNamesMassFilter]  = usePersistedState<string>("dashboard-clt:namesMassFilter", "")
  const [cltPhonesMassFilter, setCltPhonesMassFilter] = usePersistedState<string>("dashboard-clt:phonesMassFilter", "")
  const [cltVendorsFilter, setCltVendorsFilter] = usePersistedState<string[]>("dashboard-clt:vendorsFilter", [])
  const [cltBirthMonthFilter, setCltBirthMonthFilter] = usePersistedState<string[]>("dashboard-clt:birthMonthFilter", [])

  // —— CLT específicos ——
  const [cltConsultado, setCltConsultado] = usePersistedState<YesNoAll>("dashboard-clt:consultado", "todos")
  const [cltSituacao, setCltSituacao] = usePersistedState<CltSituacaoFilter>("dashboard-clt:situacao", "todos")
  const [cltConsultaFrom, setCltConsultaFrom] = usePersistedState<string>("dashboard-clt:consultaFrom", "")
  const [cltConsultaTo,   setCltConsultaTo]   = usePersistedState<string>("dashboard-clt:consultaTo", "")
  const [cltAdmissaoFrom, setCltAdmissaoFrom] = usePersistedState<string>("dashboard-clt:admissaoFrom", "")
  const [cltAdmissaoTo,   setCltAdmissaoTo]   = usePersistedState<string>("dashboard-clt:admissaoTo", "")
  const [cltMesesMin, setCltMesesMin] = usePersistedState<string>("dashboard-clt:mesesMin", "")
  const [cltMesesMax, setCltMesesMax] = usePersistedState<string>("dashboard-clt:mesesMax", "")
  const [cltInicioEmpregadorFrom, setCltInicioEmpregadorFrom] = usePersistedState<string>("dashboard-clt:inicioEmpFrom", "")
  const [cltInicioEmpregadorTo,   setCltInicioEmpregadorTo]   = usePersistedState<string>("dashboard-clt:inicioEmpTo", "")
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

  /** fetch paginado – chave inclui aba ativa e filtros do modo ativo */
  const {
    data: paginatedData,
    isLoading,
    isFetching,
    isError,
    refetch,
  } = useQuery<PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT>({
    queryKey: [
      "leads",
      activeTab,
      currentPage,

      // —— FGTS comuns
      searchValue, statusFilter, motivosFilter, origemFilter, higienizacaoFilter,
      dateFromFilter, dateToFilter, contractDateFromFilter, contractDateToFilter,
      cpfMassFilter, namesMassFilter, phonesMassFilter, vendorsFilter, birthMonthFilter,
      fgtsAuthorizedFilter, fgtsConsultaFromFilter, fgtsConsultaToFilter,

      // —— CLT comuns
      cltSearchValue, cltStatusFilter, cltMotivosFilter, cltOrigemFilter, cltHigienizacaoFilter,
      cltDateFromFilter, cltDateToFilter, cltContractFromFilter, cltContractToFilter,
      cltCpfMassFilter, cltNamesMassFilter, cltPhonesMassFilter, cltVendorsFilter, cltBirthMonthFilter,
      // CLT específicos
      cltConsultado, cltSituacao,
      cltConsultaFrom, cltConsultaTo, cltAdmissaoFrom, cltAdmissaoTo, cltMesesMin, cltMesesMax,
      cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltCategoriaCodigos, cltIdadeMin, cltIdadeMax,
      cltSexo, cltRendaMin, cltRendaMax, cltBaseMin, cltBaseMax, cltMargemMin, cltMargemMax,
      cltPrestacaoMin, cltPrestacaoMax, cltAtivosMin, cltAtivosMax, cltTemAtivos, cltTemLegados,
    ],
    queryFn: async (): Promise<PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT> => {
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
          vendors: vendorsFilter,
          birth_month: birthMonthFilter,
          fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
          fgts_consulta_from: fgtsConsultaFromFilter || undefined,
          fgts_consulta_to: fgtsConsultaToFilter || undefined,
        })
      }

      // —— CLT
      const catCodes = cltCategoriaCodigos
        ? cltCategoriaCodigos.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean)
        : undefined
      return fetchLeadsCLT({
        page: currentPage,
        search: cltSearchValue,
        status: cltStatusFilter,
        // CLT: apenas filtros válidos
        origens: cltOrigemFilter,
        cpf: cltCpfMassFilter,
        names: cltNamesMassFilter,
        phones: cltPhonesMassFilter,
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

        // somente boolean de legados (sem min/max)
        clt_tem_legados: cltTemLegados !== "todos" ? cltTemLegados : undefined,
      })
    },
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
  })

  /** mapeamentos FGTS / CLT */
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
        data_nascimento: lead.data_nascimento ? formatDate(lead.data_nascimento) : "",
        telefones,
        contratos: lead.contracts_count,
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

      return {
        id: lead.id,
        cpf: formatCPF(lead.cpf),
        nome: lead.nome || "--",
        data_nascimento: lead.data_nascimento ? formatDate(lead.data_nascimento) : "",
        telefones,
        ultima_origem_cadastral: lead.ultima_origem_cadastral || "",
        elegivel,
        not_found: !!lead.not_found,
        clt_consultado_em: lead.clt_consultado_em ? formatDate(lead.clt_consultado_em) : "",
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

  /* ---------- toasts controlados ---------- */
  const [awaitingFetch, setAwaitingFetch] = useState<null | "apply" | "clear">(null)
  const [pendingToastId, setPendingToastId] = useState<string | number | null>(null)

  useEffect(() => {
    if (awaitingFetch && (isFetching || isLoading) && !pendingToastId) {
      const id = toast.loading(
        awaitingFetch === "apply" ? "Aplicando filtros…" : "Limpando filtros…"
      )
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

  /* ---------- handlers ---------- */
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

  const handleClearFilters = () => {
    if (activeTab === "FGTS") clearFgts()
    else clearClt()

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
    higienizacaoFilter.length ||
    vendorsFilter.length ||
    birthMonthFilter.length ||
    fgtsAuthorizedFilter !== "todos" ||
    fgtsConsultaFromFilter ||
    fgtsConsultaToFilter

  // CLT: removemos contagem de filtros que não se aplicam (motivos, higienização, período hig., período de contrato, vendors)
  const hasActiveFiltersCLT =
    cltSearchValue ||
    cltStatusFilter !== "todos" ||
    cltOrigemFilter.length || // cadastral ok
    cltCpfMassFilter ||
    cltNamesMassFilter ||
    cltPhonesMassFilter ||
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
    /* apenas boolean de legados */
    cltTemLegados !== "todos"

  const hasActiveFilters = activeTab === "FGTS" ? hasActiveFiltersFGTS : hasActiveFiltersCLT

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

    return {
      search: cltSearchValue || undefined,
      status: cltStatusFilter !== "todos" ? cltStatusFilter : undefined,
      // CLT válidos
      origens: cltOrigemFilter.length ? cltOrigemFilter : undefined,
      cpf: cltCpfMassFilter || undefined,
      names: cltNamesMassFilter || undefined,
      phones: cltPhonesMassFilter || undefined,
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
      clt_sexo: cltSexo.length ? (cltSexo as ("M"|"F")[]) : undefined,
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

  const handleExport = async (columns: string[]) => {
    // nesta etapa mantemos exportação somente para FGTS (como estava)
    toast.info("Exportação iniciada.")
    try {
      await exportLeads(collectFilters(), columns)
      toast.success("Exportação concluída!")
    } catch (err) {
      console.error(err)
      toast.error("Falha ao exportar. Tente novamente.")
    }
  }

  const total = (paginatedData as any)?.total ?? 0
  const current_page = (paginatedData as any)?.current_page ?? 1
  const last_page = (paginatedData as any)?.last_page ?? 1

  // ======== Projeção de estados para o componente de controles (modo atual) ========
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
        vendorsFilter, setVendorsFilter,
        birthMonthFilter, setBirthMonthFilter,
        fgtsAuthorizedFilter, setFgtsAuthorizedFilter,
        fgtsConsultaFromFilter, setFgtsConsultaFromFilter,
        fgtsConsultaToFilter, setFgtsConsultaToFilter,
      }
    : {
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
        vendorsFilter: cltVendorsFilter, setVendorsFilter: setCltVendorsFilter,
        birthMonthFilter: cltBirthMonthFilter, setBirthMonthFilter: setCltBirthMonthFilter,
        // FGTS OFF não se aplica no CLT (passamos defaults não usados no modal CLT)
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
          {activeTab === "FGTS" ? "FGTS (Facta FGTS Base offline)" : "CLT (Facta Crédito do Trabalhador)"} — {total} registros
        </p>
      </div>

      {/* --------- Abas (acima do LeadsControls) --------- */}
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
          CLT (Consignado)
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
        // ➕ FGTS OFF (só tem UI no modo FGTS)
        fgtsAuthorizedFilter={ui.fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={ui.setFgtsAuthorizedFilter}
        fgtsConsultaFromFilter={ui.fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={ui.setFgtsConsultaFromFilter}
        fgtsConsultaToFilter={ui.fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={ui.setFgtsConsultaToFilter}

        /* CLT – novos props */
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
      />

      {activeTab === "FGTS" ? (
        <LeadsTableFGTS
          leads={processedLeadsFGTS}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || loadingOptions}
        />
      ) : (
        <LeadsTableCLT
          leads={processedLeadsCLT}
          currentPage={current_page}
          totalPages={last_page}
          onPageChange={setCurrentPage}
          isLoading={isLoading || loadingOptions}
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
      />
    </div>
  )
}

export default Dashboard
