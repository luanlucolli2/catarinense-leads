import { useState, useMemo } from "react"
import { useQuery, keepPreviousData } from "@tanstack/react-query"
import { toast } from "sonner"

import { LeadsTable, ProcessedLead } from "@/components/LeadsTable"
import { LeadsControls } from "@/components/LeadsControls"
import { ImportModal } from "@/components/ImportModal"
import { ExportModal } from "@/components/ExportModal"
import axiosClient from "@/api/axiosClient"
import {
  formatCPF,
  formatCurrency,
  formatDate,
  formatPhone,
} from "@/lib/formatters"

/* ---------- Tipos vindos da API ---------- */
interface LeadFromApi {
  id: number
  cpf: string
  nome: string | null
  data_nascimento: string | null
  fone1: string | null
  classe_fone1: string | null
  fone2: string | null
  classe_fone2: string | null
  fone3: string | null
  classe_fone3: string | null
  fone4: string | null
  classe_fone4: string | null
  primeira_origem: string | null
  consulta: string | null
  data_atualizacao: string | null
  saldo: string | null
  libera: string | null
  /* 🆕 campo que vem do back-end */
  contracts_count: number
}

interface PaginatedLeadsResponse {
  data: LeadFromApi[]
  current_page: number
  last_page: number
  total: number
}

const Dashboard = () => {
  /* ------------------- filtros/estado UI ------------------- */
  const [searchValue, setSearchValue] = useState("")
  const [eligibleFilter, setEligibleFilter] = useState<
    "todos" | "elegiveis" | "nao-elegiveis"
  >("todos")
  const [motivosFilter, setMotivosFilter] = useState<string[]>([])
  const [origemFilter, setOrigemFilter] = useState<string[]>([])
  const [dateFromFilter, setDateFromFilter] = useState("")
  const [dateToFilter, setDateToFilter] = useState("")
  const [isImportModalOpen, setIsImportModalOpen] = useState(false)
  const [isExportModalOpen, setIsExportModalOpen] = useState(false)
  const [currentPage, setCurrentPage] = useState(1)

  /* ------------------- fetch leads ------------------- */
  const {
    data: paginatedData,
    isLoading,
    isError,
    refetch,
  } = useQuery<PaginatedLeadsResponse>({
    queryKey: ["leads", currentPage, searchValue, eligibleFilter],
    queryFn: async () => {
      const params = new URLSearchParams({ page: currentPage.toString() })
      if (searchValue) params.set("search", searchValue)
      if (eligibleFilter !== "todos") params.set("status", eligibleFilter)
      /* outros filtros podem ser adicionados aqui */
      const { data } = await axiosClient.get("/leads", { params })
      return data
    },
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
  })

  /* ------------------- transformação p/ tabela ------------------- */
  const processedLeads: ProcessedLead[] = useMemo(() => {
    if (!paginatedData?.data) return []

    return paginatedData.data.map((lead) => {
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
        contratos: lead.contracts_count, // 🆕 agora real
        saldo: formatCurrency(lead.saldo),
        libera: formatCurrency(lead.libera),
        data_atualizacao: formatDate(lead.data_atualizacao),
        consulta: lead.consulta || "--",
        primeira_origem: lead.primeira_origem,
      }
    })
  }, [paginatedData])

  /* ------------------- demais handlers (sem alterações) ------------------- */
  const availableMotivos = ["Aprovado", "Não autorizado", "Saldo insuficiente"]
  const availableOrigens = ["Sistema Interno", "Planilha Excel", "API Externa"]

  const handleApplyFilters = () => {
    setCurrentPage(1)
    toast.success("Filtros aplicados.")
  }

  const handleClearFilters = () => {
    setSearchValue("")
    setEligibleFilter("todos")
    setMotivosFilter([])
    setOrigemFilter([])
    setDateFromFilter("")
    setDateToFilter("")
    setCurrentPage(1)
    toast.info("Filtros limpos.")
  }

  /* ------------------- render ------------------- */
  if (isLoading && !paginatedData)
    return <div className="p-6 text-center">Carregando leads...</div>
  if (isError)
    return (
      <div className="p-6 text-center text-red-500">
        Erro ao carregar os dados.
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
        eligibleFilter={eligibleFilter}
        onEligibleFilterChange={setEligibleFilter}
        motivosFilter={motivosFilter}
        onMotivosFilterChange={setMotivosFilter}
        origemFilter={origemFilter}
        onOrigemFilterChange={setOrigemFilter}
        dateFromFilter={dateFromFilter}
        onDateFromFilterChange={setDateFromFilter}
        dateToFilter={dateToFilter}
        onDateToFilterChange={setDateToFilter}
        onApplyFilters={handleApplyFilters}
        onClearFilters={handleClearFilters}
        availableMotivos={availableMotivos}
        availableOrigens={availableOrigens}
        hasActiveFilters={
          searchValue !== "" ||
          eligibleFilter !== "todos" ||
          motivosFilter.length > 0 ||
          origemFilter.length > 0 ||
          dateFromFilter !== "" ||
          dateToFilter !== ""
        }
        /* filtros não implementados ainda: */
        contractDateFromFilter=""
        onContractDateFromFilterChange={() => { }}
        contractDateToFilter=""
        onContractDateToFilterChange={() => { }}
        cpfMassFilter=""
        onCpfMassFilterChange={() => { }}
        namesMassFilter=""
        onNamesMassFilterChange={() => { }}
        phonesMassFilter=""
        onPhonesMassFilterChange={() => { }}
      />

      <LeadsTable
        leads={processedLeads}
        currentPage={paginatedData?.current_page ?? 1}
        totalPages={paginatedData?.last_page ?? 1}
        onPageChange={setCurrentPage}
        isLoading={isLoading}
      />

      <ImportModal
        isOpen={isImportModalOpen}
        onClose={() => setIsImportModalOpen(false)}
        onImportSuccess={() => refetch()} /* atualiza após import */
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
