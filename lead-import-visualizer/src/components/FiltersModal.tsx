import { useState, useEffect } from "react"
import { X, Filter, Check } from "lucide-react"
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
import { cn } from "@/lib/utils"

interface FiltersModalProps {
  isOpen: boolean
  onClose: () => void
  searchValue: string
  /** mantidos p/ compat com o caller, mas não usados */
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
  higienizacaoFilter: string[]
  vendorsFilter: string[]
  fgtsAuthorizedFilter: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"
  fgtsConsultaFromFilter: string
  fgtsConsultaToFilter: string
  onSearchChange: (v: string) => void
  /** mantidos p/ compat, não usados */
  onEligibleFilterChange: (v: "todos" | "elegiveis" | "nao-elegiveis") => void
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
  onFgtsAuthorizedFilterChange: (v: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado") => void
  onFgtsConsultaFromFilterChange: (v: string) => void
  onFgtsConsultaToFilterChange: (v: string) => void
  birthMonthFilter: string[]
  onBirthMonthFilterChange: (values: string[]) => void
  onApplyFilters: () => void
  onClearFilters: () => void
  availableMotivos: string[]
  availableOrigens: string[]
  availableHigienizacoes: string[]
  availableVendors: { id: number; name: string }[]
}

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

/* util de foco: remove outline/ring nativos */
const NO_FOCUS = "focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0 focus:shadow-none"

function Section({
  title,
  description,
  active = false,
  children,
}: {
  title: string
  description?: string
  active?: boolean
  children: React.ReactNode
}) {
  return (
    <section
      className={cn(
        "rounded-lg border p-4 sm:p-5 bg-white",
        active ? "border-blue-300 ring-1 ring-blue-200" : "border-gray-200"
      )}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <h3 className={cn("text-sm font-semibold", active ? "text-blue-700" : "text-gray-800")}>
            {title}
          </h3>
          {description && <p className="mt-0.5 text-xs text-gray-500">{description}</p>}
        </div>
        {active && (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
            <Check className="h-3 w-3" /> ativo
          </span>
        )}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  )
}

const Label = ({ text, active = false }: { text: string; active?: boolean }) => (
  <label className={cn("text-xs font-medium", active ? "text-blue-700" : "text-gray-700")}>{text}</label>
)

export const FiltersModal = ({
  isOpen,
  onClose,
  searchValue,
  /** elegibilidade: mantido em props mas não usado */
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
  fgtsAuthorizedFilter,
  fgtsConsultaFromFilter,
  fgtsConsultaToFilter,
  onSearchChange,
  /** elegibilidade: mantido em props mas não usado */
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
  onFgtsAuthorizedFilterChange,
  onFgtsConsultaFromFilterChange,
  onFgtsConsultaToFilterChange,
  birthMonthFilter,
  onBirthMonthFilterChange,
  onApplyFilters,
  onClearFilters,
  availableMotivos,
  availableOrigens,
  availableHigienizacoes,
  availableVendors,
}: FiltersModalProps) => {
  const [localSearch, setLocalSearch] = useState(searchValue)
  const [localContractFrom, setLocalContractFrom] = useState(contractDateFromFilter)
  const [localContractTo, setLocalContractTo] = useState(contractDateToFilter)
  const [localMotivos, setLocalMotivos] = useState<string[]>(motivosFilter)
  const [localOrigens, setLocalOrigens] = useState<string[]>(origemFilter)
  const [localCpfMass, setLocalCpfMass] = useState(cpfMassFilter)
  const [localNamesMass, setLocalNamesMass] = useState(namesMassFilter)
  const [localPhonesMass, setLocalPhonesMass] = useState(phonesMassFilter)
  const [localDateFrom, setLocalDateFrom] = useState(dateFromFilter)
  const [localDateTo, setLocalDateTo] = useState(dateToFilter)
  const [localHigienizacao, setLocalHigienizacao] = useState<string[]>(higienizacaoFilter)
  const [localVendors, setLocalVendors] = useState<string[]>(vendorsFilter)
  const [localBirthMonths, setLocalBirthMonths] = useState<string[]>(birthMonthFilter.map(monthNumToLabel))
  const [localFgtsAuthorized, setLocalFgtsAuthorized] =
    useState<"todos" | "autorizado" | "nao_autorizado" | "nao_consultado">(fgtsAuthorizedFilter)
  const [localFgtsFrom, setLocalFgtsFrom] = useState(fgtsConsultaFromFilter)
  const [localFgtsTo, setLocalFgtsTo] = useState(fgtsConsultaToFilter)

  useEffect(() => {
    if (!isOpen) return
    setLocalSearch(searchValue)
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
    setLocalFgtsAuthorized(fgtsAuthorizedFilter)
    setLocalFgtsFrom(fgtsConsultaFromFilter)
    setLocalFgtsTo(fgtsConsultaToFilter)
  }, [
    isOpen,
    searchValue,
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
    fgtsAuthorizedFilter,
    fgtsConsultaFromFilter,
    fgtsConsultaToFilter,
  ])

  useEffect(() => {
    if (isOpen) document.body.style.overflow = "hidden"
    return () => {
      document.body.style.overflow = ""
    }
  }, [isOpen])

  const commitAndApply = () => {
    const normalizedMonths = localBirthMonths.map(monthLabelToNum).filter(isValidMonth)
    onSearchChange(localSearch.trim())
    // elegibilidade não é mais aplicada
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
    onBirthMonthFilterChange(normalizedMonths)
    onFgtsAuthorizedFilterChange(localFgtsAuthorized)
    onFgtsConsultaFromFilterChange(localFgtsFrom)
    onFgtsConsultaToFilterChange(localFgtsTo)
    onApplyFilters()
    onClose()
  }

  if (!isOpen) return null

  const MONTH_OPTIONS = Object.values(MONTH_LABELS)

  const any = (arr: (string | null | undefined)[]) => arr.some((v) => !!(v && String(v).trim()))
  const isSearchActive = !!localSearch.trim()
  const isMotivosActive = localMotivos.length > 0
  const isOrigensActive = localOrigens.length > 0
  const isHigienizacaoActive = localHigienizacao.length > 0
  const isVendorsActive = localVendors.length > 0
  const isBirthActive = localBirthMonths.length > 0
  const isContractPeriodActive = any([localContractFrom, localContractTo])
  const isUpdatedPeriodActive = any([localDateFrom, localDateTo])
  const isFgtsStatusActive = localFgtsAuthorized !== "todos"
  const isFgtsPeriodActive = any([localFgtsFrom, localFgtsTo])
  const isMassActive = any([localCpfMass, localNamesMass, localPhonesMass])

  const chips: string[] = []
  if (isSearchActive) chips.push("Pesquisa")
  if (isMotivosActive) chips.push(`Motivos (${localMotivos.length})`)
  if (isOrigensActive) chips.push(`Origem (${localOrigens.length})`)
  if (isHigienizacaoActive) chips.push(`Higienização (${localHigienizacao.length})`)
  if (isVendorsActive) chips.push(`Vendedores (${localVendors.length})`)
  if (isBirthActive) chips.push(`Aniversário (${localBirthMonths.length})`)
  if (isContractPeriodActive) chips.push("Período de contratos")
  if (isUpdatedPeriodActive) chips.push("Período de higienização")
  if (isFgtsStatusActive) chips.push("FGTS OFF status")
  if (isFgtsPeriodActive) chips.push("FGTS OFF período")
  if (isMassActive) chips.push("Filtros em massa")

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      {/* Wrapper com escopo p/ reset de focus */}
      <div className="filters-modal flex max-h:[90vh] max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
        {/* Cabeçalho */}
        <header className="flex flex-col gap-3 border-b p-4 sm:p-6 flex-shrink-0">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Filter className="h-5 w-5 text-gray-600" />
              <h2 className="text-lg sm:text-xl font-semibold text-gray-900">Filtros avançados</h2>
            </div>
            <button
              onClick={onClose}
              className={cn("rounded p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700", NO_FOCUS)}
              aria-label="Fechar"
            >
              <X className="h-5 w-5" />
            </button>
          </div>

          <div className="flex flex-wrap gap-2">
            {chips.length === 0 ? (
              <span className="text-xs text-gray-500">Nenhum filtro ativo.</span>
            ) : (
              chips.map((c, i) => (
                <span
                  key={i}
                  className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-medium text-blue-700"
                >
                  <span className="inline-block h-1.5 w-1.5 rounded-full bg-blue-600" />
                  {c}
                </span>
              ))
            )}
          </div>
        </header>

        {/* Conteúdo rolável */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6">
          <div className="grid gap-4 sm:gap-5 lg:grid-cols-2">
            <div className="space-y-4 sm:space-y-5">
              <Section title="Pesquisa" description="Busque por nome, CPF ou telefone." active={isSearchActive}>
                <div className={cn(isSearchActive && "rounded-md ring-1 ring-blue-2  00")}>
                  <Label text="Pesquisa geral" active={isSearchActive} />
                  <Input
                    value={localSearch}
                    onChange={(e) => setLocalSearch(e.target.value)}
                    placeholder="Digite termos…"
                    className={NO_FOCUS}
                  />
                </div>
              </Section>

              <Section
                title="Origem e Motivos"
                description="Refine pela origem do lead, origem da higienização e motivo."
                active={isOrigensActive || isHigienizacaoActive || isMotivosActive}
              >
                <div>
                  <Label text="Motivos" active={isMotivosActive} />
                  <MultiSelect
                    options={availableMotivos}
                    selected={localMotivos}
                    onChange={setLocalMotivos}
                    placeholder="Selecionar motivos…"
                  />
                </div>

                <div>
                  <Label text="Origem dos leads" active={isOrigensActive} />
                  <MultiSelect
                    options={availableOrigens}
                    selected={localOrigens}
                    onChange={setLocalOrigens}
                    placeholder="Selecionar origens…"
                  />
                </div>

                <div>
                  <Label text="Origem das higienizações" active={isHigienizacaoActive} />
                  <MultiSelect
                    options={availableHigienizacoes}
                    selected={localHigienizacao}
                    onChange={setLocalHigienizacao}
                    placeholder="Selecionar origens…"
                  />
                </div>
              </Section>

              <Section title="Vendedores" description="Filtre por responsável comercial." active={isVendorsActive}>
                <div>
                  <Label text="Seleção de vendedores" active={isVendorsActive} />
                  <MultiSelect
                    options={availableVendors.map((v) => v.name)}
                    selected={localVendors}
                    onChange={setLocalVendors}
                    placeholder="Selecionar vendedores…"
                  />
                </div>
              </Section>
            </div>

            <div className="space-y-4 sm:space-y-5">
              <Section
                title="Períodos"
                description="Defina intervalos de datas para contratos e higienização."
                active={isContractPeriodActive || isUpdatedPeriodActive}
              >
                <div className="grid grid-cols-1 gap-3">
                  <div>
                    <Label text="Período de contratos" active={isContractPeriodActive} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input
                        type="date"
                        value={localContractFrom}
                        onChange={(e) => setLocalContractFrom(e.target.value)}
                        className={cn(NO_FOCUS, localContractFrom && "ring-1 ring-blue-200")}
                      />
                      <Input
                        type="date"
                        value={localContractTo}
                        onChange={(e) => setLocalContractTo(e.target.value)}
                        className={cn(NO_FOCUS, localContractTo && "ring-1 ring-blue-200")}
                      />
                    </div>
                  </div>

                  <div>
                    <Label text="Período de higienização" active={isUpdatedPeriodActive} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input
                        type="date"
                        value={localDateFrom}
                        onChange={(e) => setLocalDateFrom(e.target.value)}
                        className={cn(NO_FOCUS, localDateFrom && "ring-1 ring-blue-200")}
                      />
                      <Input
                        type="date"
                        value={localDateTo}
                        onChange={(e) => setLocalDateTo(e.target.value)}
                        className={cn(NO_FOCUS, localDateTo && "ring-1 ring-blue-200")}
                      />
                    </div>
                  </div>
                </div>
              </Section>

              <Section
                title="FGTS OFF"
                description="Filtre por status da autorização e período da consulta."
                active={isFgtsStatusActive || isFgtsPeriodActive}
              >
                <div>
                  <Label text="Status" active={isFgtsStatusActive} />
                  <Select
                    value={localFgtsAuthorized}
                    onValueChange={(v) =>
                      setLocalFgtsAuthorized(v as "todos" | "autorizado" | "nao_autorizado" | "nao_consultado")
                    }
                  >
                    <SelectTrigger className={cn(NO_FOCUS, isFgtsStatusActive && "ring-1 ring-blue-200")}>
                      <SelectValue placeholder="Selecionar..." />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="todos">Todos</SelectItem>
                      <SelectItem value="autorizado">Autorizado</SelectItem>
                      <SelectItem value="nao_autorizado">Não autorizado</SelectItem>
                      <SelectItem value="nao_consultado">Não consultado</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label text="Período da consulta" active={isFgtsPeriodActive} />
                  <div className="mt-2 grid grid-cols-2 gap-3">
                    <Input
                      type="date"
                      value={localFgtsFrom}
                      onChange={(e) => setLocalFgtsFrom(e.target.value)}
                      className={cn(NO_FOCUS, localFgtsFrom && "ring-1 ring-blue-200")}
                    />
                    <Input
                      type="date"
                      value={localFgtsTo}
                      onChange={(e) => setLocalFgtsTo(e.target.value)}
                      className={cn(NO_FOCUS, localFgtsTo && "ring-1 ring-blue-200")}
                    />
                  </div>
                </div>
              </Section>

              <Section title="Aniversário" description="Selecione um ou mais meses." active={isBirthActive}>
                <div>
                  <Label text="Mês(es) de aniversário" active={isBirthActive} />
                  <MultiSelect
                    options={MONTH_OPTIONS}
                    selected={localBirthMonths}
                    onChange={setLocalBirthMonths}
                    placeholder="Selecione os meses…"
                  />
                </div>
              </Section>

              <Section title="Filtros em massa" description="Cole uma lista de valores para filtrar rapidamente." active={isMassActive}>
                <div className="grid grid-cols-1 gap-3">
                  <div>
                    <Label text="CPFs" active={!!localCpfMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="CPFs separados por vírgula, ponto e vírgula ou quebra de linha"
                      value={localCpfMass}
                      onChange={(e) => setLocalCpfMass(e.target.value)}
                      className={cn(NO_FOCUS, !!localCpfMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>

                  <div>
                    <Label text="Nomes" active={!!localNamesMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="Nomes separados por quebra de linha"
                      value={localNamesMass}
                      onChange={(e) => setLocalNamesMass(e.target.value)}
                      className={cn(NO_FOCUS, !!localNamesMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>

                  <div>
                    <Label text="Telefones" active={!!localPhonesMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="Telefones separados por quebra de linha"
                      value={localPhonesMass}
                      onChange={(e) => setLocalPhonesMass(e.target.value)}
                      className={cn(NO_FOCUS, !!localPhonesMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>
                </div>
              </Section>
            </div>
          </div>
        </main>

        {/* Rodapé fixo */}
        <footer className="flex flex-col-reverse gap-2 border-t p-4 sm:flex-row sm:items-center sm:justify-end sm:gap-2 flex-shrink-0 bg-white">
          <Button
            variant="outline"
            className={cn("border-gray-300 text-gray-700 hover:bg-gray-50", NO_FOCUS)}
            onClick={() => {
              onClearFilters()
              onClose()
            }}
          >
            Limpar filtros
          </Button>

          <Button
            variant="outline"
            className={cn("border-gray-300 text-gray-700 hover:bg-gray-50", NO_FOCUS)}
            onClick={onClose}
          >
            Cancelar
          </Button>

          <Button className={cn("bg-blue-600 hover:bg-blue-700", NO_FOCUS)} onClick={commitAndApply}>
            Aplicar filtros
          </Button>
        </footer>
      </div>

      {/* reset global de outline dentro do modal */}
      <style>{`
        .filters-modal *:focus { outline: none !important; box-shadow: none !important; }
        .filters-modal *:focus-visible { outline: none !important; box-shadow: none !important; }
        .filters-modal input:focus,
        .filters-modal textarea:focus,
        .filters-modal select:focus,
        .filters-modal button:focus { outline: none !important; box-shadow: none !important; }
      `}</style>
    </div>
  )
}
