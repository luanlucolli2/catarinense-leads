import { useState, useMemo } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"

import { LeadsTable, ProcessedLead } from "@/components/LeadsTable"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import {
  fetchLeads,
  fetchLeadsFilters,
  LeadFromApi,
  PaginatedLeadsResponse,
} from "@/api/leads"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatPhone,
} from "@/lib/formatters"

/* ---------- estado de filtros ---------- */
type StatusFilter = "todos" | "elegiveis" | "nao-elegiveis"

const Dashboard = () => {
  const [currentPage, setCurrentPage] = useState(1)

  /* filtros simples */
  const [searchValue, setSearchValue] = useState("")
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("todos")
  const [motivosFilter, setMotivosFilter] = useState<string[]>([])
  const [origemFilter, setOrigemFilter] = useState<string[]>([])
  const [higienizacaoFilter, setHigienizacaoFilter] = useState<string[]>([])
  const [dateFromFilter, setDateFromFilter] = useState("")
  const [dateToFilter, setDateToFilter] = useState("")
  /* período de contratos */
  const [contractDateFromFilter, setContractDateFromFilter] = useState("")
  const [contractDateToFilter, setContractDateToFilter] = useState("")
  /* filtros “massa” ainda não implementados */
  const [cpfMassFilter, setCpfMassFilter] = useState("")
  const [namesMassFilter, setNamesMassFilter] = useState("")
  const [phonesMassFilter, setPhonesMassFilter] = useState("")
  /* modais */
  const [isImportModalOpen, setIsImportModalOpen] = useState(false)
  const [isExportModalOpen, setIsExportModalOpen] = useState(false)

  /* ---------- opções (motivos & origens) ---------- */
  const {
    data: filterOptions,
    isLoading: loadingOptions,
    isError: errorOptions,
  } = useQuery({
    queryKey: ["leadsFilters"],
    queryFn: fetchLeadsFilters,
    staleTime: 1000 * 60 * 5, // 5 min
  })

  /* ---------- fetch dos leads ---------- */
  const {
    data: paginatedData,
    isLoading,
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
      }),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
  })

  /* ---------- transformação p/ tabela ---------- */
  const processedLeads: ProcessedLead[] = useMemo(() => {
    if (!paginatedData?.data) return []

    return paginatedData.data.map((lead: LeadFromApi) => {
      const liberaIsNumeric =
        lead.libera && !isNaN(parseFloat(lead.libera.replace(",", ".")))
      const liberaValue = liberaIsNumeric
        ? parseFloat(lead.libera!.replace(",", "."))
        : 0

      const status: "Elegível" | "Inelegível" =
        lead.consulta === "Saldo FACTA" && liberaValue > 0
          ? "Elegível"
          : "Inelegível"

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
        data_nascimento: formatDate(lead.data_nascimento),
        telefones,
        status,
        contratos: lead.contracts_count,
        saldo: formatCurrency(lead.saldo),
        libera: formatCurrency(lead.libera),
        data_atualizacao: formatDate(lead.data_atualizacao),
        consulta: lead.consulta || "--",
        primeira_origem: lead.primeira_origem,
      }
    })
  }, [paginatedData])

  /* ---------- handlers de filtros ---------- */
  const handleApplyFilters = () => {
    setCurrentPage(1)
    toast.success("Filtros aplicados.")
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
    setCurrentPage(1)
    toast.info("Filtros limpos.")
  }

  /* ---------- flags ---------- */
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
    higienizacaoFilter.length

  /* ---------- render ---------- */
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
        /* busca rápida e elegibilidade */
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        eligibleFilter={statusFilter}
        onEligibleFilterChange={setStatusFilter}
        /* filtros avançados */
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
        /* filtros “massa” (placeholder) */
        cpfMassFilter={cpfMassFilter}
        onCpfMassFilterChange={setCpfMassFilter}
        namesMassFilter={namesMassFilter}
        onNamesMassFilterChange={setNamesMassFilter}
        phonesMassFilter={phonesMassFilter}
        onPhonesMassFilterChange={setPhonesMassFilter}
        /* ações */
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        /* opções dinâmicas */
        availableMotivos={filterOptions?.motivos ?? []}
        availableOrigens={filterOptions?.origens ?? []}
        availableHigienizacoes={filterOptions?.origens_hig ?? []}
        hasActiveFilters={!!hasActiveFilters}
      />

      <LeadsTable
        leads={processedLeads}
        currentPage={paginatedData?.current_page ?? 1}
        totalPages={paginatedData?.last_page ?? 1}
        onPageChange={setCurrentPage}
        isLoading={isLoading || loadingOptions}
      />

      {/* Modais */}
      <ImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        onImportSuccess={() => refetch()}
      />

      <ExportModal
        isOpen={isExportModalOpen}
        onClose={() => setIsExportModalOpen(false)}
        onExport={() => toast.info("Exportação iniciada.")}
      />
    </div>
  )
}

export default Dashboard
