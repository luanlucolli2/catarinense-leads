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
type ActiveTab = "FGTS" | "CLT"

const Dashboard = () => {
  const [activeTab, setActiveTab] = usePersistedState<ActiveTab>("dashboard:activeTab", "FGTS")
  const [currentPage, setCurrentPage] = useState(1)

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

  /** ➕ novos filtros FGTS OFF (tri-estado) – só fazem efeito no modo FGTS */
  const [fgtsAuthorizedFilter, setFgtsAuthorizedFilter] =
    usePersistedState<FgtsStatusFilter>("dashboard:fgtsAuthorizedFilter", "todos")
  const [fgtsConsultaFromFilter, setFgtsConsultaFromFilter] =
    usePersistedState<string>("dashboard:fgtsConsultaFromFilter", "")
  const [fgtsConsultaToFilter, setFgtsConsultaToFilter] =
    usePersistedState<string>("dashboard:fgtsConsultaToFilter", "")

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

  /** fetch paginado – chave inclui aba ativa */
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
      searchValue,
      statusFilter,
      motivosFilter,
      origemFilter,
      higienizacaoFilter,
      dateFromFilter,
      dateToFilter,
      contractDateFromFilter,
      contractDateToFilter,
      cpfMassFilter,
      namesMassFilter,
      phonesMassFilter,
      vendorsFilter,
      birthMonthFilter,
      // FGTS OFF
      fgtsAuthorizedFilter,
      fgtsConsultaFromFilter,
      fgtsConsultaToFilter,
    ],
    queryFn: async (): Promise<PaginatedLeadsResponseFGTS | PaginatedLeadsResponseCLT> => {
      const common = {
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
      }
      if (activeTab === "FGTS") {
        return fetchLeadsFGTS({
          ...common,
          fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
          fgts_consulta_from: fgtsConsultaFromFilter || undefined,
          fgts_consulta_to: fgtsConsultaToFilter || undefined,
        })
      }
      return fetchLeadsCLT(common)
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

      // Normaliza 0/1/"0"/"1" → boolean | null
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

      // 🔧 Normaliza 0/1/"0"/"1" → boolean | null
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

  const handleClearFilters = () => {
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
    // FGTS OFF
    setFgtsAuthorizedFilter("todos")
    setFgtsConsultaFromFilter("")
    setFgtsConsultaToFilter("")
    setCurrentPage(1)
    setAwaitingFetch("clear")
    if (pendingToastId) toast.dismiss(pendingToastId)
    const id = toast.loading("Limpando filtros…")
    setPendingToastId(id)
  }

  const hasActiveFilters =
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
    // FGTS OFF (conta só quando na aba FGTS)
    (activeTab === "FGTS" &&
      (fgtsAuthorizedFilter !== "todos" || fgtsConsultaFromFilter || fgtsConsultaToFilter))

  const collectFilters = () => ({
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
    // FGTS OFF (apenas no FGTS)
    fgts_status: activeTab === "FGTS" && fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
    fgts_consulta_from: activeTab === "FGTS" && fgtsConsultaFromFilter ? fgtsConsultaFromFilter : undefined,
    fgts_consulta_to: activeTab === "FGTS" && fgtsConsultaToFilter ? fgtsConsultaToFilter : undefined,
  })

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

  return (
    <div className="max-w-full p-4 lg:p-6">
      <div className="mb-4">
        <h1 className="mb-1 text-xl font-bold lg:text-2xl text-gray-900">
          Dashboard
        </h1>
        <p className="text-sm text-gray-600 lg:text-base">
          {activeTab === "FGTS" ? "FGTS (Robô OFF)" : "CLT (Consignado)"} — {total} registros
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
        onImportClick={() => setIsImportModalOpen(true)}
        onExportClick={() => setIsExportModalOpen(true)}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        eligibleFilter={statusFilter} // ainda exibido; sem efeito no back
        onEligibleFilterChange={setStatusFilter}
        motivosFilter={motivosFilter}
        onMotivosFilterChange={setMotivosFilter}
        origemFilter={origemFilter}
        onOrigemFilterChange={setOrigemFilter}
        higienizacaoFilter={higienizacaoFilter}
        onHigienizacaoFilterChange={setHigienizacaoFilter}
        dateFromFilter={dateFromFilter}
        onDateFromFilterChange={setDateFromFilter}
        dateToFilter={dateToFilter}
        onDateToFilterChange={setDateToFilter}
        contractDateFromFilter={contractDateFromFilter}
        onContractDateFromFilterChange={setContractDateFromFilter}
        contractDateToFilter={contractDateToFilter}
        onContractDateToFilterChange={setContractDateToFilter}
        cpfMassFilter={cpfMassFilter}
        onCpfMassFilterChange={setCpfMassFilter}
        namesMassFilter={namesMassFilter}
        onNamesMassFilterChange={setNamesMassFilter}
        phonesMassFilter={phonesMassFilter}
        onPhonesMassFilterChange={setPhonesMassFilter}
        birthMonthFilter={birthMonthFilter}
        onBirthMonthFilterChange={setBirthMonthFilter}
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        availableMotivos={filterOptions?.motivos ?? []}
        availableOrigens={filterOptions?.origens ?? []}
        availableHigienizacoes={filterOptions?.origens_hig ?? []}
        vendorsFilter={vendorsFilter}
        onVendorsFilterChange={setVendorsFilter}
        availableVendors={filterOptions?.vendors ?? []}
        hasActiveFilters={!!hasActiveFilters}
        // ➕ FGTS OFF (exibidos pelo componente; no CLT serão ignorados no back)
        fgtsAuthorizedFilter={fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={setFgtsAuthorizedFilter}
        fgtsConsultaFromFilter={fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={setFgtsConsultaFromFilter}
        fgtsConsultaToFilter={fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={setFgtsConsultaToFilter}
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
