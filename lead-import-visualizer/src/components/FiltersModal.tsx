import { useState, useEffect } from "react"
import { X, Filter, Check, Info } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
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
import { FiltersModal360 } from "./FiltersModal360"
interface FiltersModalProps {
  mode: "360" | "BASE" | "FGTS" | "CLT" | "MERCANTIL" | "UY3"

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
  withPhonesFilter: boolean
  noPhonesFilter: boolean
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
  onWithPhonesFilterChange: (v: boolean) => void
  onNoPhonesFilterChange: (v: boolean) => void
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

  /** ➕ CLT (props) */
  cltConsultado: "todos" | "sim" | "nao"
  onCltConsultadoChange: (v: "todos" | "sim" | "nao") => void

  /** novo filtro unificado de situação (3 estados) */
  cltSituacao: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado"
  onCltSituacaoChange: (v: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado") => void

  cltConsultaFrom: string
  onCltConsultaFromChange: (v: string) => void
  cltConsultaTo: string
  onCltConsultaToChange: (v: string) => void

  cltAdmissaoFrom: string
  onCltAdmissaoFromChange: (v: string) => void
  cltAdmissaoTo: string
  onCltAdmissaoToChange: (v: string) => void

  cltMesesMin: string
  onCltMesesMinChange: (v: string) => void
  cltMesesMax: string
  onCltMesesMaxChange: (v: string) => void

  cltInicioEmpregadorFrom: string
  onCltInicioEmpregadorFromChange: (v: string) => void
  cltInicioEmpregadorTo: string
  onCltInicioEmpregadorToChange: (v: string) => void

  cltCategoriaCodigos: string
  onCltCategoriaCodigosChange: (v: string) => void

  cltIdadeMin: string
  onCltIdadeMinChange: (v: string) => void
  cltIdadeMax: string
  onCltIdadeMaxChange: (v: string) => void

  cltSexo: string[]
  onCltSexoChange: (values: string[]) => void

  cltRendaMin: string
  onCltRendaMinChange: (v: string) => void
  cltRendaMax: string
  onCltRendaMaxChange: (v: string) => void

  cltBaseMin: string
  onCltBaseMinChange: (v: string) => void
  cltBaseMax: string
  onCltBaseMaxChange: (v: string) => void

  cltMargemMin: string
  onCltMargemMinChange: (v: string) => void
  cltMargemMax: string
  onCltMargemMaxChange: (v: string) => void

  cltPrestacaoMin: string
  onCltPrestacaoMinChange: (v: string) => void
  cltPrestacaoMax: string
  onCltPrestacaoMaxChange: (v: string) => void

  cltAtivosMin: string
  onCltAtivosMinChange: (v: string) => void
  cltAtivosMax: string
  onCltAtivosMaxChange: (v: string) => void
  cltTemAtivos: "todos" | "sim" | "nao"
  onCltTemAtivosChange: (v: "todos" | "sim" | "nao") => void

  /** Somente booleano de legados */
  cltTemLegados: "todos" | "sim" | "nao"
  onCltTemLegadosChange: (v: "todos" | "sim" | "nao") => void

  /** ➕ MERCANTIL */
  mercantilSituacao: "todos" | "consultado" | "sem_consulta" | "aprovado" | "nao_aprovado"
  onMercantilSituacaoChange: (v: "todos" | "consultado" | "sem_consulta" | "aprovado" | "nao_aprovado") => void
  mercantilStatusFilter: string[]
  onMercantilStatusFilterChange: (values: string[]) => void
  mercantilConsultaFrom: string
  onMercantilConsultaFromChange: (v: string) => void
  mercantilConsultaTo: string
  onMercantilConsultaToChange: (v: string) => void
  mercantilParcelaMin: string
  onMercantilParcelaMinChange: (v: string) => void
  mercantilParcelaMax: string
  onMercantilParcelaMaxChange: (v: string) => void
  mercantilQtdParcelasMin: string
  onMercantilQtdParcelasMinChange: (v: string) => void
  mercantilQtdParcelasMax: string
  onMercantilQtdParcelasMaxChange: (v: string) => void
  mercantilOrigensFilter: string[]
  onMercantilOrigensFilterChange: (values: string[]) => void
  availableMercantilOrigens: string[]
  availableMercantilStatuses: string[]
  selectedBanks: ("fgts" | "clt" | "mercantil" | "uy3")[]
  onSelectedBanksChange: (values: ("fgts" | "clt" | "mercantil" | "uy3")[]) => void
  bankCombinationMode: "all" | "any"
  onBankCombinationModeChange: (value: "all" | "any") => void
  uy3Situacao: "todos" | "aprovado" | "nao_aprovado"
  onUy3SituacaoChange: (value: "todos" | "aprovado" | "nao_aprovado") => void
  uy3ConsultaFrom: string
  onUy3ConsultaFromChange: (value: string) => void
  uy3ConsultaTo: string
  onUy3ConsultaToChange: (value: string) => void
  uy3MesesAdmissaoMin: string
  onUy3MesesAdmissaoMinChange: (value: string) => void
  uy3MesesAdmissaoMax: string
  onUy3MesesAdmissaoMaxChange: (value: string) => void
  uy3MargemMin: string
  onUy3MargemMinChange: (value: string) => void
  uy3MargemMax: string
  onUy3MargemMaxChange: (value: string) => void
  uy3ValorLiberadoMin: string
  onUy3ValorLiberadoMinChange: (value: string) => void
  uy3ValorLiberadoMax: string
  onUy3ValorLiberadoMaxChange: (value: string) => void
  uy3NumeroParcelasMin: string
  onUy3NumeroParcelasMinChange: (value: string) => void
  uy3NumeroParcelasMax: string
  onUy3NumeroParcelasMaxChange: (value: string) => void
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

/** Section com profundidade sutil (sombras/hover) */
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
        "rounded-lg border p-4 sm:p-5 bg-white transition-all duration-200",
        "shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:shadow-md",
        active
          ? "border-blue-300 ring-1 ring-blue-200 shadow-md"
          : "border-gray-200"
      )}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <h3 className={cn("text-sm font-semibold tracking-tight", active ? "text-blue-700" : "text-gray-800")}>
            {title}
          </h3>
          {description && <p className="mt-0.5 text-xs text-gray-500">{description}</p>}
        </div>
        {active && (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50/80 px-2 py-0.5 text-[11px] font-medium text-blue-700 shadow-sm">
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

/** Agrupador com grid “bento” (auto-fill) e densidade */
function Group({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  return (
    <div className="mb-6">
      <div className="mb-3 border-b pb-2 bg-gradient-to-r from-white to-transparent">
        <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
      </div>

      {/* Grid responsivo que sempre ocupa toda a largura disponível.
          - auto-fill com minmax garante quebra bonita em 2/3/4 colunas dependendo da largura
          - grid-flow-dense “encaixa” os cards para evitar vãos */}
      <div
        className={cn(
          "grid grid-flow-dense gap-4 sm:gap-5",
          "[grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]",
          "lg:[grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]",
          "xl:[grid-template-columns:repeat(auto-fill,minmax(360px,1fr))]"
        )}
      >
        {children}
      </div>
    </div>
  )
}

export const FiltersModal = ({
  mode,
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
  withPhonesFilter,
  noPhonesFilter,
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
  onWithPhonesFilterChange,
  onNoPhonesFilterChange,
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

  // —— CLT
  cltConsultado,
  onCltConsultadoChange,
  cltSituacao,
  onCltSituacaoChange,
  cltConsultaFrom,
  onCltConsultaFromChange,
  cltConsultaTo,
  onCltConsultaToChange,
  cltAdmissaoFrom,
  onCltAdmissaoFromChange,
  cltAdmissaoTo,
  onCltAdmissaoToChange,
  cltMesesMin,
  onCltMesesMinChange,
  cltMesesMax,
  onCltMesesMaxChange,
  cltInicioEmpregadorFrom,
  onCltInicioEmpregadorFromChange,
  cltInicioEmpregadorTo,
  onCltInicioEmpregadorToChange,
  cltCategoriaCodigos,
  onCltCategoriaCodigosChange,
  cltIdadeMin,
  onCltIdadeMinChange,
  cltIdadeMax,
  onCltIdadeMaxChange,
  cltSexo,
  onCltSexoChange,
  cltRendaMin,
  onCltRendaMinChange,
  cltRendaMax,
  onCltRendaMaxChange,
  cltBaseMin,
  onCltBaseMinChange,
  cltBaseMax,
  onCltBaseMaxChange,
  cltMargemMin,
  onCltMargemMinChange,
  cltMargemMax,
  onCltMargemMaxChange,
  cltPrestacaoMin,
  onCltPrestacaoMinChange,
  cltPrestacaoMax,
  onCltPrestacaoMaxChange,
  cltAtivosMin,
  onCltAtivosMinChange,
  cltAtivosMax,
  onCltAtivosMaxChange,
  cltTemAtivos,
  onCltTemAtivosChange,
  cltTemLegados,
  onCltTemLegadosChange,
  mercantilSituacao,
  onMercantilSituacaoChange,
  mercantilStatusFilter,
  onMercantilStatusFilterChange,
  mercantilConsultaFrom,
  onMercantilConsultaFromChange,
  mercantilConsultaTo,
  onMercantilConsultaToChange,
  mercantilParcelaMin,
  onMercantilParcelaMinChange,
  mercantilParcelaMax,
  onMercantilParcelaMaxChange,
  mercantilQtdParcelasMin,
  onMercantilQtdParcelasMinChange,
  mercantilQtdParcelasMax,
  onMercantilQtdParcelasMaxChange,
  mercantilOrigensFilter,
  onMercantilOrigensFilterChange,
  availableMercantilOrigens,
  availableMercantilStatuses,
  selectedBanks,
  onSelectedBanksChange,
  bankCombinationMode,
  onBankCombinationModeChange,
  uy3Situacao,
  onUy3SituacaoChange,
  uy3ConsultaFrom,
  onUy3ConsultaFromChange,
  uy3ConsultaTo,
  onUy3ConsultaToChange,
  uy3MesesAdmissaoMin,
  onUy3MesesAdmissaoMinChange,
  uy3MesesAdmissaoMax,
  onUy3MesesAdmissaoMaxChange,
  uy3MargemMin,
  onUy3MargemMinChange,
  uy3MargemMax,
  onUy3MargemMaxChange,
  uy3ValorLiberadoMin,
  onUy3ValorLiberadoMinChange,
  uy3ValorLiberadoMax,
  onUy3ValorLiberadoMaxChange,
  uy3NumeroParcelasMin,
  onUy3NumeroParcelasMinChange,
  uy3NumeroParcelasMax,
  onUy3NumeroParcelasMaxChange,
}: FiltersModalProps) => {
  if (mode === "360") {
    return (
      <FiltersModal360
        isOpen={isOpen}
        onClose={onClose}
        onApplyFilters={onApplyFilters}
        onClearFilters={onClearFilters}
        withPhonesFilter={withPhonesFilter}
        onWithPhonesFilterChange={onWithPhonesFilterChange}
        noPhonesFilter={noPhonesFilter}
        onNoPhonesFilterChange={onNoPhonesFilterChange}
        selectedBanks={selectedBanks.filter((bank) => bank !== "fgts")}
        onSelectedBanksChange={onSelectedBanksChange}
        bankCombinationMode={bankCombinationMode}
        onBankCombinationModeChange={onBankCombinationModeChange}
        cltSituacao={cltSituacao as "todos" | "aprovado" | "nao_aprovado"}
        onCltSituacaoChange={onCltSituacaoChange}
        cltConsultaFrom={cltConsultaFrom}
        onCltConsultaFromChange={onCltConsultaFromChange}
        cltConsultaTo={cltConsultaTo}
        onCltConsultaToChange={onCltConsultaToChange}
        cltMesesAdmissaoMin={cltMesesMin}
        onCltMesesAdmissaoMinChange={onCltMesesMinChange}
        cltMesesAdmissaoMax={cltMesesMax}
        onCltMesesAdmissaoMaxChange={onCltMesesMaxChange}
        cltMargemMin={cltMargemMin}
        onCltMargemMinChange={onCltMargemMinChange}
        cltMargemMax={cltMargemMax}
        onCltMargemMaxChange={onCltMargemMaxChange}
        cltNumeroParcelasMin={cltPrestacaoMin}
        onCltNumeroParcelasMinChange={onCltPrestacaoMinChange}
        cltNumeroParcelasMax={cltPrestacaoMax}
        onCltNumeroParcelasMaxChange={onCltPrestacaoMaxChange}
        mercantilSituacao={mercantilSituacao as "todos" | "aprovado" | "nao_aprovado"}
        onMercantilSituacaoChange={onMercantilSituacaoChange}
        mercantilConsultaFrom={mercantilConsultaFrom}
        onMercantilConsultaFromChange={onMercantilConsultaFromChange}
        mercantilConsultaTo={mercantilConsultaTo}
        onMercantilConsultaToChange={onMercantilConsultaToChange}
        mercantilValorParcelaMin={mercantilParcelaMin}
        onMercantilValorParcelaMinChange={onMercantilParcelaMinChange}
        mercantilValorParcelaMax={mercantilParcelaMax}
        onMercantilValorParcelaMaxChange={onMercantilParcelaMaxChange}
        mercantilNumeroParcelasMin={mercantilQtdParcelasMin}
        onMercantilNumeroParcelasMinChange={onMercantilQtdParcelasMinChange}
        mercantilNumeroParcelasMax={mercantilQtdParcelasMax}
        onMercantilNumeroParcelasMaxChange={onMercantilQtdParcelasMaxChange}
        uy3Situacao={uy3Situacao}
        onUy3SituacaoChange={onUy3SituacaoChange}
        uy3ConsultaFrom={uy3ConsultaFrom}
        onUy3ConsultaFromChange={onUy3ConsultaFromChange}
        uy3ConsultaTo={uy3ConsultaTo}
        onUy3ConsultaToChange={onUy3ConsultaToChange}
        uy3MesesAdmissaoMin={uy3MesesAdmissaoMin}
        onUy3MesesAdmissaoMinChange={onUy3MesesAdmissaoMinChange}
        uy3MesesAdmissaoMax={uy3MesesAdmissaoMax}
        onUy3MesesAdmissaoMaxChange={onUy3MesesAdmissaoMaxChange}
        uy3MargemMin={uy3MargemMin}
        onUy3MargemMinChange={onUy3MargemMinChange}
        uy3MargemMax={uy3MargemMax}
        onUy3MargemMaxChange={onUy3MargemMaxChange}
        uy3ValorLiberadoMin={uy3ValorLiberadoMin}
        onUy3ValorLiberadoMinChange={onUy3ValorLiberadoMinChange}
        uy3ValorLiberadoMax={uy3ValorLiberadoMax}
        onUy3ValorLiberadoMaxChange={onUy3ValorLiberadoMaxChange}
        uy3NumeroParcelasMin={uy3NumeroParcelasMin}
        onUy3NumeroParcelasMinChange={onUy3NumeroParcelasMinChange}
        uy3NumeroParcelasMax={uy3NumeroParcelasMax}
        onUy3NumeroParcelasMaxChange={onUy3NumeroParcelasMaxChange}
      />
    )
  }

  const [localSearch, setLocalSearch] = useState(searchValue)
  const [localContractFrom, setLocalContractFrom] = useState(contractDateFromFilter)
  const [localContractTo, setLocalContractTo] = useState(contractDateToFilter)
  const [localMotivos, setLocalMotivos] = useState<string[]>(motivosFilter)
  const [localOrigens, setLocalOrigens] = useState<string[]>(origemFilter)
  const [localCpfMass, setLocalCpfMass] = useState(cpfMassFilter)
  const [localNamesMass, setLocalNamesMass] = useState(namesMassFilter)
  const [localPhonesMass, setLocalPhonesMass] = useState(phonesMassFilter)
  const [localWithPhones, setLocalWithPhones] = useState(withPhonesFilter)
  const [localNoPhones, setLocalNoPhones] = useState(noPhonesFilter)
  const [localDateFrom, setLocalDateFrom] = useState(dateFromFilter)
  const [localDateTo, setLocalDateTo] = useState(dateToFilter)
  const [localHigienizacao, setLocalHigienizacao] = useState<string[]>(higienizacaoFilter)
  const [localVendors, setLocalVendors] = useState<string[]>(vendorsFilter)
  const [localBirthMonths, setLocalBirthMonths] = useState<string[]>(birthMonthFilter.map(monthNumToLabel))
  const [localFgtsAuthorized, setLocalFgtsAuthorized] =
    useState<"todos" | "autorizado" | "nao_autorizado" | "nao_consultado">(fgtsAuthorizedFilter)
  const [localFgtsFrom, setLocalFgtsFrom] = useState(fgtsConsultaFromFilter)
  const [localFgtsTo, setLocalFgtsTo] = useState(fgtsConsultaToFilter)

  // —— CLT locals ——
  const [lCltConsultado, setLCltConsultado] = useState<"todos" | "sim" | "nao">(cltConsultado)
  const [lCltSituacao, setLCltSituacao] = useState<"todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado">(cltSituacao)
  const [lCltConsultaFrom, setLCltConsultaFrom] = useState(cltConsultaFrom)
  const [lCltConsultaTo, setLCltConsultaTo] = useState(cltConsultaTo)
  const [lCltAdmissaoFrom, setLCltAdmissaoFrom] = useState(cltAdmissaoFrom)
  const [lCltAdmissaoTo, setLCltAdmissaoTo] = useState(cltAdmissaoTo)
  const [lCltMesesMin, setLCltMesesMin] = useState(cltMesesMin)
  const [lCltMesesMax, setLCltMesesMax] = useState(cltMesesMax)
  const [lCltInicioEmpFrom, setLCltInicioEmpFrom] = useState(cltInicioEmpregadorFrom)
  const [lCltInicioEmpTo, setLCltInicioEmpTo] = useState(cltInicioEmpregadorTo)
  const [lCltCategoria, setLCltCategoria] = useState(cltCategoriaCodigos)
  const [lCltIdadeMin, setLCltIdadeMin] = useState(cltIdadeMin)
  const [lCltIdadeMax, setLCltIdadeMax] = useState(cltIdadeMax)
  const [lCltSexo, setLCltSexo] = useState<string[]>(cltSexo)
  const [lCltRendaMin, setLCltRendaMin] = useState(cltRendaMin)
  const [lCltRendaMax, setLCltRendaMax] = useState(cltRendaMax)
  const [lCltBaseMin, setLCltBaseMin] = useState(cltBaseMin)
  const [lCltBaseMax, setLCltBaseMax] = useState(cltBaseMax)
  const [lCltMargemMin, setLCltMargemMin] = useState(cltMargemMin)
  const [lCltMargemMax, setLCltMargemMax] = useState(cltMargemMax)
  const [lCltPrestacaoMin, setLCltPrestacaoMin] = useState(cltPrestacaoMin)
  const [lCltPrestacaoMax, setLCltPrestacaoMax] = useState(cltPrestacaoMax)
  const [lCltAtivosMin, setLCltAtivosMin] = useState(cltAtivosMin)
  const [lCltAtivosMax, setLCltAtivosMax] = useState(cltAtivosMax)
  const [lCltTemAtivos, setLCltTemAtivos] = useState<"todos" | "sim" | "nao">(cltTemAtivos)
  const [lCltTemLegados, setLCltTemLegados] = useState<"todos" | "sim" | "nao">(cltTemLegados)

  // —— MERCANTIL locals ——
  const [lMercantilSituacao, setLMercantilSituacao] = useState<"todos" | "consultado" | "sem_consulta" | "aprovado" | "nao_aprovado">(mercantilSituacao)
  const [lMercantilStatus, setLMercantilStatus] = useState<string[]>(mercantilStatusFilter)
  const [lMercantilConsultaFrom, setLMercantilConsultaFrom] = useState(mercantilConsultaFrom)
  const [lMercantilConsultaTo, setLMercantilConsultaTo] = useState(mercantilConsultaTo)
  const [lMercantilParcelaMin, setLMercantilParcelaMin] = useState(mercantilParcelaMin)
  const [lMercantilParcelaMax, setLMercantilParcelaMax] = useState(mercantilParcelaMax)
  const [lMercantilQtdParcelasMin, setLMercantilQtdParcelasMin] = useState(mercantilQtdParcelasMin)
  const [lMercantilQtdParcelasMax, setLMercantilQtdParcelasMax] = useState(mercantilQtdParcelasMax)
  const [lMercantilOrigens, setLMercantilOrigens] = useState<string[]>(mercantilOrigensFilter)

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
    setLocalWithPhones(withPhonesFilter)
    setLocalNoPhones(noPhonesFilter)
    setLocalDateFrom(dateFromFilter)
    setLocalDateTo(dateToFilter)
    setLocalHigienizacao(higienizacaoFilter)
    setLocalVendors(vendorsFilter)
    setLocalBirthMonths(birthMonthFilter.map(monthNumToLabel))
    setLocalFgtsAuthorized(fgtsAuthorizedFilter)
    setLocalFgtsFrom(fgtsConsultaFromFilter)
    setLocalFgtsTo(fgtsConsultaToFilter)

    // CLT locals
    setLCltConsultado(cltConsultado)
    setLCltSituacao(cltSituacao)
    setLCltConsultaFrom(cltConsultaFrom)
    setLCltConsultaTo(cltConsultaTo)
    setLCltAdmissaoFrom(cltAdmissaoFrom)
    setLCltAdmissaoTo(cltAdmissaoTo)
    setLCltMesesMin(cltMesesMin)
    setLCltMesesMax(cltMesesMax)
    setLCltInicioEmpFrom(cltInicioEmpregadorFrom)
    setLCltInicioEmpTo(cltInicioEmpregadorTo)
    setLCltCategoria(cltCategoriaCodigos)
    setLCltIdadeMin(cltIdadeMin)
    setLCltIdadeMax(cltIdadeMax)
    setLCltSexo(cltSexo)
    setLCltRendaMin(cltRendaMin)
    setLCltRendaMax(cltRendaMax)
    setLCltBaseMin(cltBaseMin)
    setLCltBaseMax(cltBaseMax)
    setLCltMargemMin(cltMargemMin)
    setLCltMargemMax(cltMargemMax)
    setLCltPrestacaoMin(cltPrestacaoMin)
    setLCltPrestacaoMax(cltPrestacaoMax)
    setLCltAtivosMin(cltAtivosMin)
    setLCltAtivosMax(cltAtivosMax)
    setLCltTemAtivos(cltTemAtivos)
    setLCltTemLegados(cltTemLegados)

    // MERCANTIL locals
    setLMercantilSituacao(mercantilSituacao)
    setLMercantilStatus(mercantilStatusFilter)
    setLMercantilConsultaFrom(mercantilConsultaFrom)
    setLMercantilConsultaTo(mercantilConsultaTo)
    setLMercantilParcelaMin(mercantilParcelaMin)
    setLMercantilParcelaMax(mercantilParcelaMax)
    setLMercantilQtdParcelasMin(mercantilQtdParcelasMin)
    setLMercantilQtdParcelasMax(mercantilQtdParcelasMax)
    setLMercantilOrigens(mercantilOrigensFilter)
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
    withPhonesFilter,
    noPhonesFilter,
    dateFromFilter,
    dateToFilter,
    higienizacaoFilter,
    vendorsFilter,
    birthMonthFilter,
    fgtsAuthorizedFilter,
    fgtsConsultaFromFilter,
    fgtsConsultaToFilter,
    // CLT deps
    cltConsultado, cltSituacao, cltConsultaFrom, cltConsultaTo,
    cltAdmissaoFrom, cltAdmissaoTo, cltMesesMin, cltMesesMax,
    cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltCategoriaCodigos,
    cltIdadeMin, cltIdadeMax, cltSexo,
    cltRendaMin, cltRendaMax, cltBaseMin, cltBaseMax,
    cltMargemMin, cltMargemMax, cltPrestacaoMin, cltPrestacaoMax,
    cltAtivosMin, cltAtivosMax, cltTemAtivos,
    cltTemLegados,
    // MERCANTIL deps
    mercantilSituacao, mercantilStatusFilter,
    mercantilConsultaFrom, mercantilConsultaTo,
    mercantilParcelaMin, mercantilParcelaMax,
    mercantilQtdParcelasMin, mercantilQtdParcelasMax,
    mercantilOrigensFilter,
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
    onContractDateFromFilterChange(localContractFrom)
    onContractDateToFilterChange(localContractTo)
    onMotivosFilterChange(localMotivos)
    onOrigemFilterChange(localOrigens)
    onCpfMassFilterChange(localCpfMass.trim())
    onNamesMassFilterChange(localNamesMass.trim())
    onPhonesMassFilterChange(localPhonesMass.trim())
    onWithPhonesFilterChange(localWithPhones)
    onNoPhonesFilterChange(localNoPhones)
    onDateFromFilterChange(localDateFrom)
    onDateToFilterChange(localDateTo)
    onHigienizacaoFilterChange(localHigienizacao)
    onVendorsFilterChange(localVendors)
    onBirthMonthFilterChange(normalizedMonths)

    if (mode === "FGTS") {
      onFgtsAuthorizedFilterChange(localFgtsAuthorized)
      onFgtsConsultaFromFilterChange(localFgtsFrom)
      onFgtsConsultaToFilterChange(localFgtsTo)
    }

    if (mode === "CLT") {
      onCltConsultadoChange(lCltConsultado)
      onCltSituacaoChange(lCltSituacao)
      onCltConsultaFromChange(lCltConsultaFrom)
      onCltConsultaToChange(lCltConsultaTo)
      onCltAdmissaoFromChange(lCltAdmissaoFrom)
      onCltAdmissaoToChange(lCltAdmissaoTo)
      onCltMesesMinChange(lCltMesesMin)
      onCltMesesMaxChange(lCltMesesMax)
      onCltInicioEmpregadorFromChange(lCltInicioEmpFrom)
      onCltInicioEmpregadorToChange(lCltInicioEmpTo)
      onCltCategoriaCodigosChange(lCltCategoria)
      onCltIdadeMinChange(lCltIdadeMin)
      onCltIdadeMaxChange(lCltIdadeMax)
      onCltSexoChange(lCltSexo)
      onCltRendaMinChange(lCltRendaMin)
      onCltRendaMaxChange(lCltRendaMax)
      onCltBaseMinChange(lCltBaseMin)
      onCltBaseMaxChange(lCltBaseMax)
      onCltMargemMinChange(lCltMargemMin)
      onCltMargemMaxChange(lCltMargemMax)
      onCltPrestacaoMinChange(lCltPrestacaoMin)
      onCltPrestacaoMaxChange(lCltPrestacaoMax)
      onCltAtivosMinChange(lCltAtivosMin)
      onCltAtivosMaxChange(lCltAtivosMax)
      onCltTemAtivosChange(lCltTemAtivos)
      onCltTemLegadosChange(lCltTemLegados)
    }

    if (mode === "MERCANTIL") {
      onMercantilSituacaoChange(lMercantilSituacao)
      onMercantilStatusFilterChange(lMercantilSituacao === "sem_consulta" ? [] : lMercantilStatus)
      onMercantilConsultaFromChange(lMercantilConsultaFrom)
      onMercantilConsultaToChange(lMercantilConsultaTo)
      onMercantilParcelaMinChange(lMercantilParcelaMin)
      onMercantilParcelaMaxChange(lMercantilParcelaMax)
      onMercantilQtdParcelasMinChange(lMercantilQtdParcelasMin)
      onMercantilQtdParcelasMaxChange(lMercantilQtdParcelasMax)
      onMercantilOrigensFilterChange(lMercantilOrigens)
    }

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
  const isWithPhonesActive = localWithPhones
  const isNoPhonesActive = localNoPhones
  const isPhonesPresenceActive = isWithPhonesActive || isNoPhonesActive

  // CLT actives
  const actCltSituacao =
    (lCltConsultado !== "todos") ||
    (lCltSituacao !== "todos") ||
    any([lCltConsultaFrom, lCltConsultaTo])

  const actCltVinculo =
    any([lCltAdmissaoFrom, lCltAdmissaoTo]) ||
    any([lCltMesesMin, lCltMesesMax]) ||
    any([lCltInicioEmpFrom, lCltInicioEmpTo]) ||
    !!lCltCategoria.trim()

  const actCltPerfil =
    any([lCltIdadeMin, lCltIdadeMax]) ||
    (lCltSexo && lCltSexo.length > 0)

  const actCltRenda =
    any([lCltRendaMin, lCltRendaMax, lCltBaseMin, lCltBaseMax, lCltMargemMin, lCltMargemMax, lCltPrestacaoMin, lCltPrestacaoMax])

  const actCltHistorico =
    any([lCltAtivosMin, lCltAtivosMax]) || lCltTemAtivos !== "todos" ||
    lCltTemLegados !== "todos"

  const mercantilStatusActive =
    lMercantilSituacao !== "sem_consulta" && lMercantilStatus.length > 0

  const actMercantilSituacao =
    lMercantilSituacao !== "todos" ||
    mercantilStatusActive ||
    any([lMercantilConsultaFrom, lMercantilConsultaTo]) ||
    lMercantilOrigens.length > 0

  const actMercantilFinanceiro =
    any([lMercantilParcelaMin, lMercantilParcelaMax, lMercantilQtdParcelasMin, lMercantilQtdParcelasMax])

  const chips: string[] = []
  if (isSearchActive) chips.push("Pesquisa")
  if (isOrigensActive) chips.push(`Origem (${localOrigens.length})`)
  if (isWithPhonesActive) chips.push("Com telefone")
  if (isNoPhonesActive) chips.push("Sem telefone")
  if (mode === "FGTS") {
    if (isMotivosActive) chips.push(`Motivos (${localMotivos.length})`)
    if (isHigienizacaoActive) chips.push(`Higienização (${localHigienizacao.length})`)
    if (isVendorsActive) chips.push(`Vendedores (${localVendors.length})`)
    if (isContractPeriodActive) chips.push("Período de contratos")
    if (isUpdatedPeriodActive) chips.push("Período de higienização")
    if (isFgtsStatusActive) chips.push("FGTS OFF status")
    if (isFgtsPeriodActive) chips.push("FGTS OFF período")
  } else if (mode === "CLT") {
    if (actCltSituacao) chips.push("CLT · Situação")
    if (actCltVinculo) chips.push("CLT · Vínculo")
    if (actCltPerfil) chips.push("CLT · Perfil")
    if (actCltRenda) chips.push("CLT · Renda/Margem")
    if (actCltHistorico) chips.push("CLT · Histórico")
  } else if (mode === "MERCANTIL") {
    if (actMercantilSituacao) chips.push("Mercantil · Situação")
    if (actMercantilFinanceiro) chips.push("Mercantil · Financeiro")
  }
  if (isBirthActive) chips.push(`Aniversário (${localBirthMonths.length})`)
  if (isMassActive) chips.push("Filtros em massa")

  const modeLabel =
    mode === "BASE"
      ? "Leads"
      : mode === "FGTS"
      ? "FGTS (Facta FGTS Base offline)"
      : mode === "CLT"
        ? "CLT (Facta Crédito do Trabalhador)"
        : mode === "MERCANTIL"
          ? "CLT (Mercantil)"
          : "CLT (UY3)"

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-[2px] p-4">
      {/* Wrapper com escopo p/ reset de focus */}
      <div className="filters-modal flex max-h[90vh] max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/10">
        {/* Cabeçalho */}
        <header className="flex flex-col gap-3 border-b p-4 sm:p-6 flex-shrink-0 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/70 shadow-sm">
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
                  className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-medium text-blue-700 shadow-[0_1px_0_rgba(0,0,0,0.05)]"
                >
                  <span className="inline-block h-1.5 w-1.5 rounded-full bg-blue-600 shadow-inner" />
                  {c}
                </span>
              ))
            )}
          </div>
        </header>

        {/* Barra de modo */}
        <div className="px-4 sm:px-6 py-2 bg-gray-50/90 backdrop-blur border-b flex items-center gap-2 shadow-[inset_0_-1px_0_rgba(0,0,0,0.03)]">
          <Info className="w-4 h-4 text-gray-500" />
          <span className="text-xs sm:text-sm text-gray-700">
            Filtrando dados de: <strong>{modeLabel}</strong>
          </span>
        </div>

        {/* Conteúdo rolável */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 bg-gradient-to-b from-white to-gray-50">
          {/* ======= Grupo: Geral ======= */}
          <Group title="Geral">
            {/* Pesquisa */}
            <div>
              <Section title="Pesquisa" description="Busque por nome, CPF ou telefone." active={isSearchActive}>
                <div className={cn(isSearchActive && "rounded-md ring-1 ring-blue-200")}>
                  <Label text="Pesquisa geral" active={isSearchActive} />
                  <Input
                    value={localSearch}
                    onChange={(e) => setLocalSearch(e.target.value)}
                    placeholder="Digite termos…"
                    className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                  />
                </div>
              </Section>
            </div>

            {/* Origem (comum) */}
            <div>
              <Section
                title="Origem"
                description="Refine pela origem do lead."
                active={isOrigensActive}
              >
                <div>
                  <Label text="Origem dos leads" active={isOrigensActive} />
                  <MultiSelect
                    options={availableOrigens}
                    selected={localOrigens}
                    onChange={setLocalOrigens}
                    placeholder="Selecionar origens…"
                  />
                </div>
              </Section>
            </div>

            {/* Aniversário */}
            <div>
              <Section title="Telefones" description="Mostra só leads com ou sem telefone cadastrado." active={isPhonesPresenceActive}>
                <div className="space-y-3">
                  <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50/60 p-3 cursor-pointer">
                    <Checkbox
                      checked={localWithPhones}
                      onCheckedChange={(checked) => {
                        const next = !!checked
                        setLocalWithPhones(next)
                        if (next) setLocalNoPhones(false)
                      }}
                      className="mt-0.5"
                    />
                    <div className="space-y-1">
                      <div className={cn("text-sm font-medium", isWithPhonesActive ? "text-blue-700" : "text-gray-800")}>
                        Com algum telefone
                      </div>
                      <p className="text-xs text-gray-500">Considera `fone1` a `fone4` com ao menos um valor preenchido.</p>
                    </div>
                  </label>
                  <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50/60 p-3 cursor-pointer">
                    <Checkbox
                      checked={localNoPhones}
                      onCheckedChange={(checked) => {
                        const next = !!checked
                        setLocalNoPhones(next)
                        if (next) setLocalWithPhones(false)
                      }}
                      className="mt-0.5"
                    />
                    <div className="space-y-1">
                      <div className={cn("text-sm font-medium", isNoPhonesActive ? "text-blue-700" : "text-gray-800")}>
                        Sem nenhum telefone
                      </div>
                      <p className="text-xs text-gray-500">Considera `fone1` a `fone4` vazios.</p>
                    </div>
                  </label>
                </div>
              </Section>
            </div>

            <div>
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
            </div>
          </Group>

          {/* ======= Grupo: FGTS específico ======= */}
          {mode === "FGTS" && (
            <Group title="FGTS">
              {/* Motivos e Higienização */}
              <div>
                <Section
                  title="Motivos e Higienização"
                  description="Motivos e origem das higienizações."
                  active={isMotivosActive || isHigienizacaoActive}
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
                    <Label text="Origem das higienizações" active={isHigienizacaoActive} />
                    <MultiSelect
                      options={availableHigienizacoes}
                      selected={localHigienizacao}
                      onChange={setLocalHigienizacao}
                      placeholder="Selecionar origens…"
                    />
                  </div>
                </Section>
              </div>

              {/* Vendedores */}
              <div>
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

              {/* Períodos */}
              <div>
                <Section
                  title="Períodos"
                  description="Intervalos de datas para contratos e higienização."
                  active={isContractPeriodActive || isUpdatedPeriodActive}
                >
                  <div>
                    <Label text="Período de contratos" active={isContractPeriodActive} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input
                        type="date"
                        value={localContractFrom}
                        onChange={(e) => setLocalContractFrom(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localContractFrom && "ring-1 ring-blue-200")}
                      />
                      <Input
                        type="date"
                        value={localContractTo}
                        onChange={(e) => setLocalContractTo(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localContractTo && "ring-1 ring-blue-200")}
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
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localDateFrom && "ring-1 ring-blue-200")}
                      />
                      <Input
                        type="date"
                        value={localDateTo}
                        onChange={(e) => setLocalDateTo(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localDateTo && "ring-1 ring-blue-200")}
                      />
                    </div>
                  </div>
                </Section>
              </div>

              {/* FGTS OFF */}
              <div>
                <Section
                  title="FGTS OFF"
                  description="Status da autorização e período da consulta."
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
                      <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", isFgtsStatusActive && "ring-1 ring-blue-200")}>
                        <SelectValue placeholder="Selecionar..." />
                      </SelectTrigger>
                      <SelectContent className="shadow-lg">
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
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localFgtsFrom && "ring-1 ring-blue-200")}
                      />
                      <Input
                        type="date"
                        value={localFgtsTo}
                        onChange={(e) => setLocalFgtsTo(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localFgtsTo && "ring-1 ring-blue-200")}
                      />
                    </div>
                  </div>
                </Section>
              </div>
            </Group>
          )}

          {/* ======= Grupo: CLT específico ======= */}
          {mode === "CLT" && (
            <Group title="CLT">
              <div>
                <Section
                  title="Situação"
                  description="Consultado, situação unificada e período da última consulta."
                  active={actCltSituacao}
                >
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <Label text="Consultado?" active={lCltConsultado !== "todos"} />
                      <Select value={lCltConsultado} onValueChange={(v) => setLCltConsultado(v as any)}>
                        <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                        <SelectContent className="shadow-lg">
                          <SelectItem value="todos">Todos</SelectItem>
                          <SelectItem value="sim">Sim</SelectItem>
                          <SelectItem value="nao">Não</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div>
                      <Label text="Situação" active={lCltSituacao !== "todos"} />
                      <Select value={lCltSituacao} onValueChange={(v) => setLCltSituacao(v as any)}>
                        <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                        <SelectContent className="shadow-lg">
                          <SelectItem value="todos">Todos</SelectItem>
                          <SelectItem value="elegivel">Elegível</SelectItem>
                          <SelectItem value="nao_elegivel">Não elegível</SelectItem>
                          <SelectItem value="nao_encontrado">Não encontrado</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>

                  <div>
                    <Label text="Período da consulta CLT" active={any([lCltConsultaFrom, lCltConsultaTo])} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input type="date" value={lCltConsultaFrom} onChange={(e) => setLCltConsultaFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                      <Input type="date" value={lCltConsultaTo} onChange={(e) => setLCltConsultaTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section
                  title="Vínculo"
                  description="Admissão, tempo de casa, início de atividade do empregador e categoria."
                  active={actCltVinculo}
                >
                  <div>
                    <Label text="Período de admissão" active={any([lCltAdmissaoFrom, lCltAdmissaoTo])} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input type="date" value={lCltAdmissaoFrom} onChange={(e) => setLCltAdmissaoFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                      <Input type="date" value={lCltAdmissaoTo} onChange={(e) => setLCltAdmissaoTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Meses de admissão (mín)" active={!!lCltMesesMin} />
                      <Input value={lCltMesesMin} onChange={(e) => setLCltMesesMin(e.target.value)} placeholder="ex.: 6" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Meses de admissão (máx)" active={!!lCltMesesMax} />
                      <Input value={lCltMesesMax} onChange={(e) => setLCltMesesMax(e.target.value)} placeholder="ex.: 120" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>

                  <div>
                    <Label text="Início atividade do empregador" active={any([lCltInicioEmpFrom, lCltInicioEmpTo])} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input type="date" value={lCltInicioEmpFrom} onChange={(e) => setLCltInicioEmpFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                      <Input type="date" value={lCltInicioEmpTo} onChange={(e) => setLCltInicioEmpTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>

                  <div>
                    <Label text="Categoria(s) trabalhador (códigos)" active={!!lCltCategoria.trim()} />
                    <Textarea
                      rows={2}
                      placeholder="Ex.: 123, 456, 789"
                      value={lCltCategoria}
                      onChange={(e) => setLCltCategoria(e.target.value)}
                      className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                    />
                  </div>
                </Section>
              </div>

              <div>
                <Section
                  title="Perfil"
                  description="Idade e sexo."
                  active={actCltPerfil}
                >
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Idade mín." active={!!lCltIdadeMin} />
                      <Input value={lCltIdadeMin} onChange={(e) => setLCltIdadeMin(e.target.value)} placeholder="ex.: 18" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Idade máx." active={!!lCltIdadeMax} />
                      <Input value={lCltIdadeMax} onChange={(e) => setLCltIdadeMax(e.target.value)} placeholder="ex.: 75" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>

                  <div>
                    <Label text="Sexo" active={lCltSexo.length > 0} />
                    <MultiSelect
                      options={["M", "F"]}
                      selected={lCltSexo}
                      onChange={setLCltSexo}
                      placeholder="Selecionar sexo…"
                    />
                  </div>
                </Section>
              </div>

              <div>
                <Section
                  title="Renda e Margem"
                  description="Faixas de renda, base, margem e prestação."
                  active={actCltRenda}
                >
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Renda mín." active={!!lCltRendaMin} />
                      <Input value={lCltRendaMin} onChange={(e) => setLCltRendaMin(e.target.value)} placeholder="ex.: 1200,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Renda máx." active={!!lCltRendaMax} />
                      <Input value={lCltRendaMax} onChange={(e) => setLCltRendaMax(e.target.value)} placeholder="ex.: 5000,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>

                    <div>
                      <Label text="Base mín." active={!!lCltBaseMin} />
                      <Input value={lCltBaseMin} onChange={(e) => setLCltBaseMin(e.target.value)} placeholder="ex.: 1000,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Base máx." active={!!lCltBaseMax} />
                      <Input value={lCltBaseMax} onChange={(e) => setLCltBaseMax(e.target.value)} placeholder="ex.: 4000,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>

                    <div>
                      <Label text="Margem mín." active={!!lCltMargemMin} />
                      <Input value={lCltMargemMin} onChange={(e) => setLCltMargemMin(e.target.value)} placeholder="ex.: 100,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Margem máx." active={!!lCltMargemMax} />
                      <Input value={lCltMargemMax} onChange={(e) => setLCltMargemMax(e.target.value)} placeholder="ex.: 2000,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>

                    <div>
                      <Label text="Prestação mín." active={!!lCltPrestacaoMin} />
                      <Input value={lCltPrestacaoMin} onChange={(e) => setLCltPrestacaoMin(e.target.value)} placeholder="ex.: 100,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Prestação máx." active={!!lCltPrestacaoMax} />
                      <Input value={lCltPrestacaoMax} onChange={(e) => setLCltPrestacaoMax(e.target.value)} placeholder="ex.: 800,00" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section
                  title="Histórico de Crédito"
                  description="Qtd. de empréstimos ativos/suspensos e legados."
                  active={actCltHistorico}
                >
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Ativos/Susp. mín." active={!!lCltAtivosMin} />
                      <Input value={lCltAtivosMin} onChange={(e) => setLCltAtivosMin(e.target.value)} placeholder="ex.: 0" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                    <div>
                      <Label text="Ativos/Susp. máx." active={!!lCltAtivosMax} />
                      <Input value={lCltAtivosMax} onChange={(e) => setLCltAtivosMax(e.target.value)} placeholder="ex.: 10" className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} />
                    </div>
                  </div>

                  <div>
                    <Label text="Tem ativos/suspensos?" active={lCltTemAtivos !== "todos"} />
                    <Select value={lCltTemAtivos} onValueChange={(v) => setLCltTemAtivos(v as any)}>
                      <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent className="shadow-lg">
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="sim">Sim</SelectItem>
                        <SelectItem value="nao">Não</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Apenas booleano de legados */}
                  <div>
                    <Label text="Tem legados?" active={lCltTemLegados !== "todos"} />
                    <Select value={lCltTemLegados} onValueChange={(v) => setLCltTemLegados(v as any)}>
                      <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent className="shadow-lg">
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="sim">Sim</SelectItem>
                        <SelectItem value="nao">Não</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </Section>
              </div>
            </Group>
          )}

          {/* ======= Grupo: MERCANTIL específico ======= */}
          {mode === "MERCANTIL" && (
            <Group title="Mercantil">
              <div>
                <Section
                  title="Situação e Status"
                  description="Situação agregada, status de retorno e origem do job Mercantil."
                  active={actMercantilSituacao}
                >
                  <div>
                    <Label text="Situação" active={lMercantilSituacao !== "todos"} />
                    <Select value={lMercantilSituacao} onValueChange={(v) => setLMercantilSituacao(v as any)}>
                      <SelectTrigger className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent className="shadow-lg">
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="consultado">Consultado</SelectItem>
                        <SelectItem value="sem_consulta">Sem consulta</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div>
                    <Label
                      text="Status Mercantil"
                      active={lMercantilSituacao !== "sem_consulta" && lMercantilStatus.length > 0}
                    />
                    <MultiSelect
                      options={availableMercantilStatuses}
                      selected={lMercantilStatus}
                      onChange={setLMercantilStatus}
                      placeholder="Selecionar status…"
                      disabled={lMercantilSituacao === "sem_consulta"}
                    />
                    {lMercantilSituacao === "sem_consulta" && (
                      <p className="mt-1 text-[11px] text-gray-500">
                        Status bloqueado quando Situação = Sem consulta.
                      </p>
                    )}
                  </div>

                  <div>
                    <Label text="Origem da importação Mercantil" active={lMercantilOrigens.length > 0} />
                    <MultiSelect
                      options={availableMercantilOrigens}
                      selected={lMercantilOrigens}
                      onChange={setLMercantilOrigens}
                      placeholder="Selecionar origens…"
                    />
                  </div>

                  <div>
                    <Label text="Período da consulta" active={any([lMercantilConsultaFrom, lMercantilConsultaTo])} />
                    <div className="mt-2 grid grid-cols-2 gap-3">
                      <Input
                        type="date"
                        value={lMercantilConsultaFrom}
                        onChange={(e) => setLMercantilConsultaFrom(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                      <Input
                        type="date"
                        value={lMercantilConsultaTo}
                        onChange={(e) => setLMercantilConsultaTo(e.target.value)}
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                    </div>
                  </div>

                </Section>
              </div>

              <div>
                <Section
                  title="Financeiro"
                  description="Faixas para valor da parcela e quantidade de parcelas."
                  active={actMercantilFinanceiro}
                >
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Valor parcela mín." active={!!lMercantilParcelaMin} />
                      <Input
                        value={lMercantilParcelaMin}
                        onChange={(e) => setLMercantilParcelaMin(e.target.value)}
                        placeholder="ex.: 150,00"
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                    </div>
                    <div>
                      <Label text="Valor parcela máx." active={!!lMercantilParcelaMax} />
                      <Input
                        value={lMercantilParcelaMax}
                        onChange={(e) => setLMercantilParcelaMax(e.target.value)}
                        placeholder="ex.: 1200,00"
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                    </div>
                    <div>
                      <Label text="Qtd. parcelas mín." active={!!lMercantilQtdParcelasMin} />
                      <Input
                        value={lMercantilQtdParcelasMin}
                        onChange={(e) => setLMercantilQtdParcelasMin(e.target.value)}
                        placeholder="ex.: 12"
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                    </div>
                    <div>
                      <Label text="Qtd. parcelas máx." active={!!lMercantilQtdParcelasMax} />
                      <Input
                        value={lMercantilQtdParcelasMax}
                        onChange={(e) => setLMercantilQtdParcelasMax(e.target.value)}
                        placeholder="ex.: 84"
                        className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}
                      />
                    </div>
                  </div>
                </Section>
              </div>
            </Group>
          )}

          {/* ======= Grupo: Em massa (sempre por último, full width) ======= */}
          <Group title="Em massa">
            <div className="col-span-full">
              <Section title="Filtros em massa" description="Cole listas para filtrar rapidamente." active={isMassActive}>
                <div className="grid grid-cols-1 gap-3">
                  <div>
                    <Label text="CPFs" active={!!localCpfMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="CPFs separados por vírgula, ponto e vírgula ou quebra de linha"
                      value={localCpfMass}
                      onChange={(e) => setLocalCpfMass(e.target.value)}
                      className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", !!localCpfMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>

                  <div>
                    <Label text="Nomes" active={!!localNamesMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="Nomes separados por quebra de linha"
                      value={localNamesMass}
                      onChange={(e) => setLocalNamesMass(e.target.value)}
                      className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", !!localNamesMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>

                  <div>
                    <Label text="Telefones" active={!!localPhonesMass.trim()} />
                    <Textarea
                      rows={3}
                      placeholder="Telefones separados por quebra de linha"
                      value={localPhonesMass}
                      onChange={(e) => setLocalPhonesMass(e.target.value)}
                      className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", !!localPhonesMass.trim() && "ring-1 ring-blue-200")}
                    />
                  </div>
                </div>
              </Section>
            </div>
          </Group>
        </main>

        {/* Rodapé */}
        <footer className="flex flex-col-reverse gap-2 border-t p-4 sm:flex-row sm:items-center sm:justify-end sm:gap-2 flex-shrink-0 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/70 shadow-sm">
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

          <Button className={cn("bg-blue-600 hover:bg-blue-700 shadow-md hover:shadow-lg transition-shadow", NO_FOCUS)} onClick={commitAndApply}>
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
