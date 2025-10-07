import { useState, useMemo, useEffect } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"
import { usePersistedState } from "@/hooks/usePersistedState";

import { LeadsTable, ProcessedLead } from "@/components/LeadsTable"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import {
  fetchLeads,
  fetchLeadsFilters,
  exportLeads,
  LeadFromApi,
  PaginatedLeadsResponse,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatPhone,
} from "@/lib/formatters"

type StatusFilter = "todos" | "elegiveis" | "nao-elegiveis"
type FgtsStatusFilter = "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"

const Dashboard = () => {
  const [currentPage, setCurrentPage] = useState(1)

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
  const [vendorsFilter, setVendorsFilter] = usePersistedState<string[]>("dashboard:vendorsFilter", [])
  const [birthMonthFilter, setBirthMonthFilter] = usePersistedState<string[]>("dashboard:birthMonthFilter", [])

  /** ➕ novos filtros FGTS OFF (tri-estado) */
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

  const {
    data: paginatedData,
    isLoading,
    isFetching,
    isError,
    refetch,
  } = useQuery<PaginatedLeadsResponse>({
    queryKey: [
      "leads",
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
      // ➕ FGTS OFF no cache key
      fgtsAuthorizedFilter,
      fgtsConsultaFromFilter,
      fgtsConsultaToFilter,
    ],
    queryFn: () =>
      fetchLeads({
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
        // ➕ FGTS OFF (tri-estado)
        fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
        fgts_consulta_from: fgtsConsultaFromFilter || undefined,
        fgts_consulta_to: fgtsConsultaToFilter || undefined,
      }),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
  })

  const processedLeads: ProcessedLead[] = useMemo(() => {
    if (!paginatedData?.data) return []
    return paginatedData.data.map((lead: LeadFromApi) => {
      const liberaIsNumeric =
        lead.libera && !isNaN(parseFloat(lead.libera.replace(",", ".")))
      const liberaValue = liberaIsNumeric ? parseFloat(lead.libera!.replace(",", ".")) : 0

      const status: "Elegível" | "Inelegível" =
        lead.consulta === "Saldo FACTA" && liberaValue > 0 ? "Elegível" : "Inelegível"

      const telefones = [
        { fone: formatPhone(lead.fone1), classe: lead.classe_fone1 },
        { fone: formatPhone(lead.fone2), classe: lead.classe_fone2 },
        { fone: formatPhone(lead.fone3), classe: lead.classe_fone3 },
        { fone: formatPhone(lead.fone4), classe: lead.classe_fone4 },
      ].filter((f) => f.fone && f.fone !== "--")

      // 🔧 Normaliza 0/1/"0"/"1" → boolean | null
      const rawAuth: any = (lead as any).fgts_off_authorized
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
        data_nascimento: formatDate(lead.data_nascimento),
        telefones,
        status,
        contratos: lead.contracts_count,
        saldo: formatCurrency(lead.saldo),
        libera: formatCurrency(lead.libera),
        data_atualizacao: formatDate(lead.data_atualizacao),
        consulta: lead.consulta || "--",
        primeira_origem: lead.primeira_origem || "",
        fgts_off_authorized: fgtsOffAuthorized,
        fgts_off_consultado_em: lead.fgts_off_consultado_em
          ? formatDate(lead.fgts_off_consultado_em)
          : "",
      }
    })
  }, [paginatedData])

  /* ---------- toasts controlados ---------- */
  const [awaitingFetch, setAwaitingFetch] = useState<null | "apply" | "clear">(null)
  const [pendingToastId, setPendingToastId] = useState<string | number | null>(null)

  useEffect(() => {
    if (!awaitingFetch) return
    if ((isFetching || isLoading) && !pendingToastId) {
      const id = toast.loading(
        awaitingFetch === "apply" ? "Aplicando filtros…" : "Limpando filtros…"
      )
      setPendingToastId(id)
      return
    }

    if (isFetching || isLoading) return

    if (pendingToastId) {
      toast.dismiss(pendingToastId)
      setPendingToastId(null)
    }

    if (isError) {
      toast.error("Falha ao aplicar filtros. Tente novamente.")
    } else {
      if (awaitingFetch === "apply") toast.success("Filtros aplicados.")
      if (awaitingFetch === "clear") toast.info("Filtros limpos.")
    }
    setAwaitingFetch(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isFetching, isLoading, isError, awaitingFetch, pendingToastId])

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

    // ➕ FGTS OFF
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
    // ➕ FGTS OFF
    fgtsAuthorizedFilter !== "todos" ||
    fgtsConsultaFromFilter ||
    fgtsConsultaToFilter

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
    // ➕ FGTS OFF (tri-estado)
    fgts_status: fgtsAuthorizedFilter !== "todos" ? fgtsAuthorizedFilter : undefined,
    fgts_consulta_from: fgtsConsultaFromFilter || undefined,
    fgts_consulta_to: fgtsConsultaToFilter || undefined,
  })

  const handleExport = async (columns: string[]) => {
    toast.info("Exportação iniciada.")
    try {
      await exportLeads(collectFilters(), columns)
      toast.success("Exportação concluída!")
    } catch (err) {
      console.error(err)
      toast.error("Falha ao exportar. Tente novamente.")
    }
  }

  if (isError)
    return (
      <div className="p-6 text-center text-red-500">
        Erro ao carregar os leads.
      </div>
    )

  return (
    <div className="max-w-full p-4 lg:p-6">
      <div className="mb-6">
        <h1 className="mb-2 text-xl font-bold lg:text-2xl text-gray-900">
          Dashboard
        </h1>
        <p className="text-sm text-gray-600 lg:text-base">
          Leads importados ({paginatedData?.total ?? 0} registros)
        </p>
      </div>

      <LeadsControls
        onImportClick={() => setIsImportModalOpen(true)}
        onExportClick={() => setIsExportModalOpen(true)}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        eligibleFilter={statusFilter}
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
        // ➕ FGTS OFF (tri-estado)
        fgtsAuthorizedFilter={fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={setFgtsAuthorizedFilter}
        fgtsConsultaFromFilter={fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={setFgtsConsultaFromFilter}
        fgtsConsultaToFilter={fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={setFgtsConsultaToFilter}
      />

      <LeadsTable
        leads={processedLeads}
        currentPage={paginatedData?.current_page ?? 1}
        totalPages={paginatedData?.last_page ?? 1}
        onPageChange={setCurrentPage}
        isLoading={isLoading || loadingOptions}
      />

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
