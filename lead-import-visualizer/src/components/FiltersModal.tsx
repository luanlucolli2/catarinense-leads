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
  higienizacaoFilter: string[];
  vendorsFilter: string[];

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
  onHigienizacaoFilterChange: (values: string[]) => void
  onVendorsFilterChange: (values: string[]) => void

  /* 🎂 meses de aniversário */
  birthMonthFilter: string[]
  onBirthMonthFilterChange: (values: string[]) => void

  /* callbacks utilitários */
  onApplyFilters: () => void
  onClearFilters: () => void

  /* listas */
  availableMotivos: string[]
  availableOrigens: string[]
  availableHigienizacoes: string[]
  availableVendors: { id: number; name: string }[]
}

/* ===== Helpers: Meses (label ⇄ número) ===== */
const MONTH_LABELS: Record<string, string> = {
  "1": "Janeiro (1)",
  "2": "Fevereiro (2)",
  "3": "Março (3)",
  "4": "Abril (4)",
  "5": "Maio (5)",
  "6": "Junho (6)",
  "7": "Julho (7)",
  "8": "Agosto (8)",
  "9": "Setembro (9)",
  "10": "Outubro (10)",
  "11": "Novembro (11)",
  "12": "Dezembro (12)",
}
const monthNumToLabel = (m: string) => MONTH_LABELS[String(parseInt(m, 10))] ?? m
const monthLabelToNum = (label: string) => {
  const m = label.match(/\((\d{1,2})\)\s*$/)
  const num = m?.[1] ?? label.replace(/\D/g, "")
  return String(parseInt(num || "0", 10))
}
const isValidMonth = (m: string) => {
  const n = parseInt(m, 10)
  return !Number.isNaN(n) && n >= 1 && n <= 12
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
  higienizacaoFilter,
  vendorsFilter,

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
  onHigienizacaoFilterChange,
  onVendorsFilterChange,

  /* 🎂 */
  birthMonthFilter,
  onBirthMonthFilterChange,

  onApplyFilters,
  onClearFilters,
  availableMotivos,
  availableOrigens,
  availableHigienizacoes,
  availableVendors,
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
  const [localVendors, setLocalVendors] = useState<string[]>(vendorsFilter)
  // meses armazenados como **labels** no estado local
  const [localBirthMonths, setLocalBirthMonths] = useState<string[]>(
    birthMonthFilter.map(monthNumToLabel)
  )

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
    setLocalVendors(vendorsFilter)
    setLocalBirthMonths(birthMonthFilter.map(monthNumToLabel))
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
    vendorsFilter,
    birthMonthFilter,
  ])

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  /* ------------------------------------------------------------------
   * 3.  Commit – envia tudo para o Dashboard
   * -----------------------------------------------------------------*/
  const commitAndApply = () => {
    const normalizedMonths = localBirthMonths
      .map(monthLabelToNum)
      .filter(isValidMonth)

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
    onVendorsFilterChange(localVendors)
    onBirthMonthFilterChange(normalizedMonths) // 🎂 sobe como números (string)
    onApplyFilters()
    onClose()
  }

  if (!isOpen) return null

  // opções fixas de meses – exibidas como "Nome (nº)"
  const MONTH_OPTIONS = Object.values(MONTH_LABELS)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      {/* CSS local para colorir checkboxes (inclui os do MultiSelect) */}


      <div className="filters-modal max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-xl">
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
                  checkedClasses="bg-blue-600 border-blue-600 text-white"
                  uncheckedClasses="border-blue-600 opacity-50 [&_svg]:invisible"
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

              {/* Vendedores */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Vendedores
                </label>
                <MultiSelect
                  options={availableVendors.map(v => v.name)}
                  selected={localVendors}
                  onChange={setLocalVendors}
                  placeholder="Selecionar vendedores..."
                />
              </div>

              {/* 🎂 Mês(es) de aniversário – labels "Nome (nº)" */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Mês de Aniversário
                </label>
                <MultiSelect
                  options={MONTH_OPTIONS}
                  selected={localBirthMonths}
                  onChange={setLocalBirthMonths}
                  placeholder="Selecione os meses…"
                />
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

              {/* Período de atualização */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">
                  Período de Higienização
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
        <footer className="flex items-center justify-end gap-2 border-t px-4 sm:px-6 py-4">
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
