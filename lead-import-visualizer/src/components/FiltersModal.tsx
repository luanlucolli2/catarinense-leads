import { useState, useEffect } from "react"
import { X, Filter } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { MultiSelect } from "@/components/ui/multi-select"

interface FiltersModalProps {
  isOpen: boolean
  onClose: () => void

  /* valores atuais vindos do Dashboard */
  searchValue: string
  eligibleFilter: "todos" | "elegiveis" | "nao-elegiveis"
  contractDateFromFilter: string
  contractDateToFilter: string
  motivosFilter: string[]
  origemFilter: string[]
  cpfMassFilter: string
  namesMassFilter: string
  phonesMassFilter: string
  dateFromFilter: string
  dateToFilter: string

  /* setters que afetam o Dashboard (só no Apply!) */
  onSearchChange: (v: string) => void
  onEligibleFilterChange: (
    v: "todos" | "elegiveis" | "nao-elegiveis",
  ) => void
  onContractDateFromFilterChange: (v: string) => void
  onContractDateToFilterChange: (v: string) => void
  onMotivosFilterChange: (v: string[]) => void
  onOrigemFilterChange: (v: string[]) => void
  onCpfMassFilterChange: (v: string) => void
  onNamesMassFilterChange: (v: string) => void
  onPhonesMassFilterChange: (v: string) => void
  onDateFromFilterChange: (v: string) => void
  onDateToFilterChange: (v: string) => void

  /* callbacks utilitários */
  onApplyFilters: () => void
  onClearFilters: () => void

  /* listas */
  availableMotivos: string[]
  availableOrigens: string[]
  higienizacaoFilter: string[];
  onHigienizacaoFilterChange: (values: string[]) => void;
  availableHigienizacoes: string[];
}

export const FiltersModal = ({
  isOpen,
  onClose,

  /* props (valores atuais) */
  searchValue,
  eligibleFilter,
  contractDateFromFilter,
  contractDateToFilter,
  motivosFilter,
  origemFilter,
  cpfMassFilter,
  namesMassFilter,
  phonesMassFilter,
  dateFromFilter,
  dateToFilter,

  /* setters */
  onSearchChange,
  onEligibleFilterChange,
  onContractDateFromFilterChange,
  onContractDateToFilterChange,
  onMotivosFilterChange,
  onOrigemFilterChange,
  onCpfMassFilterChange,
  onNamesMassFilterChange,
  onPhonesMassFilterChange,
  onDateFromFilterChange,
  onDateToFilterChange,

  onApplyFilters,
  onClearFilters,
  availableMotivos,
  availableOrigens,
  higienizacaoFilter,
  onHigienizacaoFilterChange,
  availableHigienizacoes,
}: FiltersModalProps) => {
  /* ------------------------------------------------------------------
   * 1.  Estado LOCAL – edita aqui, mas só “sobe” no Apply
   * -----------------------------------------------------------------*/
  const [localSearch, setLocalSearch] = useState(searchValue)
  const [localEligible, setLocalEligible] = useState(eligibleFilter)
  const [localContractFrom, setLocalContractFrom] = useState(
    contractDateFromFilter,
  )
  const [localContractTo, setLocalContractTo] = useState(
    contractDateToFilter,
  )
  const [localMotivos, setLocalMotivos] = useState<string[]>(motivosFilter)
  const [localOrigens, setLocalOrigens] = useState<string[]>(origemFilter)
  const [localCpfMass, setLocalCpfMass] = useState(cpfMassFilter)
  const [localNamesMass, setLocalNamesMass] = useState(namesMassFilter)
  const [localPhonesMass, setLocalPhonesMass] = useState(phonesMassFilter)
  const [localDateFrom, setLocalDateFrom] = useState(dateFromFilter)
  const [localDateTo, setLocalDateTo] = useState(dateToFilter)
  const [localHigienizacao, setLocalHigienizacao] = useState<string[]>(higienizacaoFilter)

  /* ------------------------------------------------------------------
   * 2.  Sincroniza quando modal abre
   * -----------------------------------------------------------------*/
  useEffect(() => {
    if (!isOpen) return
    setLocalSearch(searchValue)
    setLocalEligible(eligibleFilter)
    setLocalContractFrom(contractDateFromFilter)
    setLocalContractTo(contractDateToFilter)
    setLocalMotivos(motivosFilter)
    setLocalOrigens(origemFilter)
    setLocalCpfMass(cpfMassFilter)
    setLocalNamesMass(namesMassFilter)
    setLocalPhonesMass(phonesMassFilter)
    setLocalDateFrom(dateFromFilter)
    setLocalDateTo(dateToFilter)
    setLocalHigienizacao(higienizacaoFilter)
  }, [
    isOpen,
    searchValue,
    eligibleFilter,
    contractDateFromFilter,
    contractDateToFilter,
    motivosFilter,
    origemFilter,
    cpfMassFilter,
    namesMassFilter,
    phonesMassFilter,
    dateFromFilter,
    dateToFilter,
    higienizacaoFilter,
  ])

  /* ------------------------------------------------------------------
   * 3.  Commit – envia tudo para o Dashboard
   * -----------------------------------------------------------------*/
  const commitAndApply = () => {
    onSearchChange(localSearch.trim())
    onEligibleFilterChange(localEligible)
    onContractDateFromFilterChange(localContractFrom)
    onContractDateToFilterChange(localContractTo)
    onMotivosFilterChange(localMotivos)
    onOrigemFilterChange(localOrigens)
    onCpfMassFilterChange(localCpfMass.trim())
    onNamesMassFilterChange(localNamesMass.trim())
    onPhonesMassFilterChange(localPhonesMass.trim())
    onDateFromFilterChange(localDateFrom)
    onDateToFilterChange(localDateTo)
    onHigienizacaoFilterChange(localHigienizacao)
    onApplyFilters()
    onClose()
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-xl">
        {/* ---------- Cabeçalho ---------- */}
        <header className="flex items-center justify-between border-b p-6">
          <div className="flex items-center space-x-2">
            <Filter className="h-5 w-5 text-gray-600" />
            <h2 className="text-xl font-semibold text-gray-900">
              Filtros Avançados
            </h2>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 transition-colors duration-200 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </header>

        {/* ---------- Conteúdo ---------- */}
        <main className="max-h-[calc(90vh-140px)] overflow-y-auto p-6">
          <div className="grid gap-6 lg:grid-cols-2">
            {/* Coluna esquerda */}
            <div className="space-y-6">
              {/* Pesquisa */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Pesquisa Geral
                </label>
                <Input
                  value={localSearch}
                  onChange={(e) => setLocalSearch(e.target.value)}
                  placeholder="Nome, CPF ou telefone..."
                />
              </div>

              {/* Elegibilidade */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Status de Elegibilidade
                </label>
                <Select
                  value={localEligible}
                  onValueChange={(v) =>
                    setLocalEligible(
                      v as "todos" | "elegiveis" | "nao-elegiveis",
                    )
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos</SelectItem>
                    <SelectItem value="elegiveis">Elegíveis</SelectItem>
                    <SelectItem value="nao-elegiveis">Inelegíveis</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Período de contrato */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Período de Contratos
                </label>
                <div className="grid grid-cols-2 gap-3">
                  <Input
                    type="date"
                    value={localContractFrom}
                    onChange={(e) => setLocalContractFrom(e.target.value)}
                  />
                  <Input
                    type="date"
                    value={localContractTo}
                    onChange={(e) => setLocalContractTo(e.target.value)}
                  />
                </div>
              </div>

              {/* Motivos */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Motivos de Consulta
                </label>
                <MultiSelect
                  options={availableMotivos}
                  selected={localMotivos}
                  onChange={setLocalMotivos}
                  placeholder="Selecionar motivos..."
                />
              </div>

              {/* Origens */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Origem dos Leads
                </label>
                <MultiSelect
                  options={availableOrigens}
                  selected={localOrigens}
                  onChange={setLocalOrigens}
                  placeholder="Selecionar origens..."
                />
              </div>

              {/* Origens de Higienização */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Origem das Higienizações
                </label>
                <MultiSelect
                  options={availableHigienizacoes}
                  selected={localHigienizacao}
                  onChange={setLocalHigienizacao}
                  placeholder="Selecionar origens..."
                />
              </div>

              {/* Período de atualização */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Período de Atualização
                </label>
                <div className="grid grid-cols-2 gap-3">
                  <Input
                    type="date"
                    value={localDateFrom}
                    onChange={(e) => setLocalDateFrom(e.target.value)}
                  />
                  <Input
                    type="date"
                    value={localDateTo}
                    onChange={(e) => setLocalDateTo(e.target.value)}
                  />
                </div>
              </div>
            </div>

            {/* Coluna direita */}
            <div className="space-y-6">
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  CPFs em Massa
                </label>
                <Textarea
                  rows={4}
                  placeholder="Cole CPFs separados por , ; ou quebra de linha"
                  value={localCpfMass}
                  onChange={(e) => setLocalCpfMass(e.target.value)}
                />
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Nomes em Massa
                </label>
                <Textarea
                  rows={4}
                  placeholder="Cole nomes…"
                  value={localNamesMass}
                  onChange={(e) => setLocalNamesMass(e.target.value)}
                />
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Telefones em Massa
                </label>
                <Textarea
                  rows={4}
                  placeholder="Cole telefones…"
                  value={localPhonesMass}
                  onChange={(e) => setLocalPhonesMass(e.target.value)}
                />
              </div>
            </div>
          </div>
        </main>

        {/* ---------- Rodapé ---------- */}
        <footer className="flex items-center justify-end space-x-3 border-t p-6">
          <Button
            variant="outline"
            className="border-gray-300 text-gray-700 hover:bg-gray-50"
            onClick={() => {
              onClearFilters()
              onClose()
            }}
          >
            Limpar Filtros
          </Button>

          <Button
            variant="outline"
            className="border-gray-300 text-gray-700 hover:bg-gray-50"
            onClick={onClose}
          >
            Cancelar
          </Button>

          <Button
            className="bg-blue-600 hover:bg-blue-700"
            onClick={commitAndApply}
          >
            Aplicar Filtros
          </Button>
        </footer>
      </div>
    </div>
  )
}
