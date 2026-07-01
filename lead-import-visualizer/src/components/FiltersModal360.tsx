import { useEffect, useState } from "react"
import { X, Filter } from "lucide-react"
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
import type { LeadBankCombinationMode, LeadBankKey } from "@/api/leads"

type YesNoAll = "todos" | "sim" | "nao"
type FgtsStatusFilter = "todos" | "autorizado" | "nao_autorizado" | "nao_consultado"
type CltSituacaoFilter = "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel"
type Uy3SituacaoFilter = "todos" | "aprovado" | "nao_aprovado"

const MONTH_LABELS: Record<string, string> = {
  "1": "Janeiro (1)",
  "2": "Fevereiro (2)",
  "3": "Marco (3)",
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

const BANK_LABELS: Record<LeadBankKey, string> = {
  fgts: "FGTS",
  clt: "CLT Facta",
  mercantil: "Mercantil",
  uy3: "UY3",
}

const monthNumToLabel = (m: string) => MONTH_LABELS[String(parseInt(m, 10))] ?? m
const monthLabelToNum = (label: string) => {
  const match = label.match(/\((\d{1,2})\)\s*$/)
  return String(parseInt(match?.[1] ?? label.replace(/\D/g, ""), 10))
}
const isValidMonth = (m: string) => {
  const n = parseInt(m, 10)
  return !Number.isNaN(n) && n >= 1 && n <= 12
}
const NO_FOCUS = "focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0 focus:shadow-none"

type Props = {
  isOpen: boolean
  onClose: () => void
  onApplyFilters: () => void
  onClearFilters: () => void
  searchValue: string
  onSearchChange: (v: string) => void
  origemFilter: string[]
  onOrigemFilterChange: (v: string[]) => void
  cpfMassFilter: string
  onCpfMassFilterChange: (v: string) => void
  namesMassFilter: string
  onNamesMassFilterChange: (v: string) => void
  phonesMassFilter: string
  onPhonesMassFilterChange: (v: string) => void
  withPhonesFilter: boolean
  onWithPhonesFilterChange: (v: boolean) => void
  noPhonesFilter: boolean
  onNoPhonesFilterChange: (v: boolean) => void
  birthMonthFilter: string[]
  onBirthMonthFilterChange: (v: string[]) => void
  availableOrigens: string[]
  selectedBanks: LeadBankKey[]
  onSelectedBanksChange: (v: LeadBankKey[]) => void
  bankCombinationMode: LeadBankCombinationMode
  onBankCombinationModeChange: (v: LeadBankCombinationMode) => void
  motivosFilter: string[]
  onMotivosFilterChange: (v: string[]) => void
  higienizacaoFilter: string[]
  onHigienizacaoFilterChange: (v: string[]) => void
  dateFromFilter: string
  onDateFromFilterChange: (v: string) => void
  dateToFilter: string
  onDateToFilterChange: (v: string) => void
  contractDateFromFilter: string
  onContractDateFromFilterChange: (v: string) => void
  contractDateToFilter: string
  onContractDateToFilterChange: (v: string) => void
  vendorsFilter: string[]
  onVendorsFilterChange: (v: string[]) => void
  fgtsAuthorizedFilter: FgtsStatusFilter
  onFgtsAuthorizedFilterChange: (v: FgtsStatusFilter) => void
  fgtsConsultaFromFilter: string
  onFgtsConsultaFromFilterChange: (v: string) => void
  fgtsConsultaToFilter: string
  onFgtsConsultaToFilterChange: (v: string) => void
  availableMotivos: string[]
  availableHigienizacoes: string[]
  availableVendors: { id: number; name: string }[]
  cltConsultado: YesNoAll
  onCltConsultadoChange: (v: YesNoAll) => void
  cltSituacao: CltSituacaoFilter
  onCltSituacaoChange: (v: CltSituacaoFilter) => void
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
  onCltSexoChange: (v: string[]) => void
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
  cltTemAtivos: YesNoAll
  onCltTemAtivosChange: (v: YesNoAll) => void
  cltTemLegados: YesNoAll
  onCltTemLegadosChange: (v: YesNoAll) => void
  mercantilSituacao: "todos" | "consultado" | "sem_consulta"
  onMercantilSituacaoChange: (v: "todos" | "consultado" | "sem_consulta") => void
  mercantilStatusFilter: string[]
  onMercantilStatusFilterChange: (v: string[]) => void
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
  onMercantilOrigensFilterChange: (v: string[]) => void
  availableMercantilOrigens: string[]
  availableMercantilStatuses: string[]
  uy3Situacao: Uy3SituacaoFilter
  onUy3SituacaoChange: (v: Uy3SituacaoFilter) => void
  uy3ConsultaFrom: string
  onUy3ConsultaFromChange: (v: string) => void
  uy3ConsultaTo: string
  onUy3ConsultaToChange: (v: string) => void
  uy3MesesAdmissaoMin: string
  onUy3MesesAdmissaoMinChange: (v: string) => void
  uy3MesesAdmissaoMax: string
  onUy3MesesAdmissaoMaxChange: (v: string) => void
  uy3MargemMin: string
  onUy3MargemMinChange: (v: string) => void
  uy3MargemMax: string
  onUy3MargemMaxChange: (v: string) => void
  uy3ValorLiberadoMin: string
  onUy3ValorLiberadoMinChange: (v: string) => void
  uy3ValorLiberadoMax: string
  onUy3ValorLiberadoMaxChange: (v: string) => void
  uy3NumeroParcelasMin: string
  onUy3NumeroParcelasMinChange: (v: string) => void
  uy3NumeroParcelasMax: string
  onUy3NumeroParcelasMaxChange: (v: string) => void
}

const Section = ({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) => (
  <section className="rounded-lg border border-gray-200 bg-white p-4">
    <h3 className="mb-3 text-sm font-semibold text-gray-900">{title}</h3>
    <div className="space-y-3">{children}</div>
  </section>
)

const Label = ({ children }: { children: React.ReactNode }) => (
  <label className="text-xs font-medium text-gray-700">{children}</label>
)

const banksOrdered: LeadBankKey[] = ["fgts", "clt", "mercantil", "uy3"]

export const FiltersModal360 = ({
  isOpen,
  onClose,
  onApplyFilters,
  onClearFilters,
  searchValue,
  onSearchChange,
  origemFilter,
  onOrigemFilterChange,
  cpfMassFilter,
  onCpfMassFilterChange,
  namesMassFilter,
  onNamesMassFilterChange,
  phonesMassFilter,
  onPhonesMassFilterChange,
  withPhonesFilter,
  onWithPhonesFilterChange,
  noPhonesFilter,
  onNoPhonesFilterChange,
  birthMonthFilter,
  onBirthMonthFilterChange,
  availableOrigens,
  selectedBanks,
  onSelectedBanksChange,
  bankCombinationMode,
  onBankCombinationModeChange,
  motivosFilter,
  onMotivosFilterChange,
  higienizacaoFilter,
  onHigienizacaoFilterChange,
  dateFromFilter,
  onDateFromFilterChange,
  dateToFilter,
  onDateToFilterChange,
  contractDateFromFilter,
  onContractDateFromFilterChange,
  contractDateToFilter,
  onContractDateToFilterChange,
  vendorsFilter,
  onVendorsFilterChange,
  fgtsAuthorizedFilter,
  onFgtsAuthorizedFilterChange,
  fgtsConsultaFromFilter,
  onFgtsConsultaFromFilterChange,
  fgtsConsultaToFilter,
  onFgtsConsultaToFilterChange,
  availableMotivos,
  availableHigienizacoes,
  availableVendors,
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
}: Props) => {
  const [localSearch, setLocalSearch] = useState(searchValue)
  const [localOrigens, setLocalOrigens] = useState<string[]>(origemFilter)
  const [localCpf, setLocalCpf] = useState(cpfMassFilter)
  const [localNames, setLocalNames] = useState(namesMassFilter)
  const [localPhones, setLocalPhones] = useState(phonesMassFilter)
  const [localWithPhones, setLocalWithPhones] = useState(withPhonesFilter)
  const [localNoPhones, setLocalNoPhones] = useState(noPhonesFilter)
  const [localBirthMonths, setLocalBirthMonths] = useState<string[]>(birthMonthFilter.map(monthNumToLabel))
  const [localSelectedBanks, setLocalSelectedBanks] = useState<LeadBankKey[]>(selectedBanks)
  const [localBankMode, setLocalBankMode] = useState<LeadBankCombinationMode>(bankCombinationMode)

  const [localMotivos, setLocalMotivos] = useState<string[]>(motivosFilter)
  const [localHigienizacao, setLocalHigienizacao] = useState<string[]>(higienizacaoFilter)
  const [localDateFrom, setLocalDateFrom] = useState(dateFromFilter)
  const [localDateTo, setLocalDateTo] = useState(dateToFilter)
  const [localContractFrom, setLocalContractFrom] = useState(contractDateFromFilter)
  const [localContractTo, setLocalContractTo] = useState(contractDateToFilter)
  const [localVendors, setLocalVendors] = useState<string[]>(vendorsFilter)
  const [localFgtsStatus, setLocalFgtsStatus] = useState<FgtsStatusFilter>(fgtsAuthorizedFilter)
  const [localFgtsFrom, setLocalFgtsFrom] = useState(fgtsConsultaFromFilter)
  const [localFgtsTo, setLocalFgtsTo] = useState(fgtsConsultaToFilter)

  const [localCltConsultado, setLocalCltConsultado] = useState<YesNoAll>(cltConsultado)
  const [localCltSituacao, setLocalCltSituacao] = useState<CltSituacaoFilter>(cltSituacao)
  const [localCltConsultaFrom, setLocalCltConsultaFrom] = useState(cltConsultaFrom)
  const [localCltConsultaTo, setLocalCltConsultaTo] = useState(cltConsultaTo)
  const [localCltAdmissaoFrom, setLocalCltAdmissaoFrom] = useState(cltAdmissaoFrom)
  const [localCltAdmissaoTo, setLocalCltAdmissaoTo] = useState(cltAdmissaoTo)
  const [localCltMesesMin, setLocalCltMesesMin] = useState(cltMesesMin)
  const [localCltMesesMax, setLocalCltMesesMax] = useState(cltMesesMax)
  const [localCltInicioFrom, setLocalCltInicioFrom] = useState(cltInicioEmpregadorFrom)
  const [localCltInicioTo, setLocalCltInicioTo] = useState(cltInicioEmpregadorTo)
  const [localCltCategorias, setLocalCltCategorias] = useState(cltCategoriaCodigos)
  const [localCltIdadeMin, setLocalCltIdadeMin] = useState(cltIdadeMin)
  const [localCltIdadeMax, setLocalCltIdadeMax] = useState(cltIdadeMax)
  const [localCltSexo, setLocalCltSexo] = useState<string[]>(cltSexo)
  const [localCltRendaMin, setLocalCltRendaMin] = useState(cltRendaMin)
  const [localCltRendaMax, setLocalCltRendaMax] = useState(cltRendaMax)
  const [localCltBaseMin, setLocalCltBaseMin] = useState(cltBaseMin)
  const [localCltBaseMax, setLocalCltBaseMax] = useState(cltBaseMax)
  const [localCltMargemMin, setLocalCltMargemMin] = useState(cltMargemMin)
  const [localCltMargemMax, setLocalCltMargemMax] = useState(cltMargemMax)
  const [localCltPrestacaoMin, setLocalCltPrestacaoMin] = useState(cltPrestacaoMin)
  const [localCltPrestacaoMax, setLocalCltPrestacaoMax] = useState(cltPrestacaoMax)
  const [localCltAtivosMin, setLocalCltAtivosMin] = useState(cltAtivosMin)
  const [localCltAtivosMax, setLocalCltAtivosMax] = useState(cltAtivosMax)
  const [localCltTemAtivos, setLocalCltTemAtivos] = useState<YesNoAll>(cltTemAtivos)
  const [localCltTemLegados, setLocalCltTemLegados] = useState<YesNoAll>(cltTemLegados)

  const [localMercantilSituacao, setLocalMercantilSituacao] = useState(mercantilSituacao)
  const [localMercantilStatus, setLocalMercantilStatus] = useState<string[]>(mercantilStatusFilter)
  const [localMercantilConsultaFrom, setLocalMercantilConsultaFrom] = useState(mercantilConsultaFrom)
  const [localMercantilConsultaTo, setLocalMercantilConsultaTo] = useState(mercantilConsultaTo)
  const [localMercantilParcelaMin, setLocalMercantilParcelaMin] = useState(mercantilParcelaMin)
  const [localMercantilParcelaMax, setLocalMercantilParcelaMax] = useState(mercantilParcelaMax)
  const [localMercantilQtdMin, setLocalMercantilQtdMin] = useState(mercantilQtdParcelasMin)
  const [localMercantilQtdMax, setLocalMercantilQtdMax] = useState(mercantilQtdParcelasMax)
  const [localMercantilOrigens, setLocalMercantilOrigens] = useState<string[]>(mercantilOrigensFilter)

  const [localUy3Situacao, setLocalUy3Situacao] = useState<Uy3SituacaoFilter>(uy3Situacao)
  const [localUy3ConsultaFrom, setLocalUy3ConsultaFrom] = useState(uy3ConsultaFrom)
  const [localUy3ConsultaTo, setLocalUy3ConsultaTo] = useState(uy3ConsultaTo)
  const [localUy3MesesMin, setLocalUy3MesesMin] = useState(uy3MesesAdmissaoMin)
  const [localUy3MesesMax, setLocalUy3MesesMax] = useState(uy3MesesAdmissaoMax)
  const [localUy3MargemMin, setLocalUy3MargemMin] = useState(uy3MargemMin)
  const [localUy3MargemMax, setLocalUy3MargemMax] = useState(uy3MargemMax)
  const [localUy3ValorMin, setLocalUy3ValorMin] = useState(uy3ValorLiberadoMin)
  const [localUy3ValorMax, setLocalUy3ValorMax] = useState(uy3ValorLiberadoMax)
  const [localUy3ParcelasMin, setLocalUy3ParcelasMin] = useState(uy3NumeroParcelasMin)
  const [localUy3ParcelasMax, setLocalUy3ParcelasMax] = useState(uy3NumeroParcelasMax)

  useEffect(() => {
    if (!isOpen) return
    setLocalSearch(searchValue)
    setLocalOrigens(origemFilter)
    setLocalCpf(cpfMassFilter)
    setLocalNames(namesMassFilter)
    setLocalPhones(phonesMassFilter)
    setLocalWithPhones(withPhonesFilter)
    setLocalNoPhones(noPhonesFilter)
    setLocalBirthMonths(birthMonthFilter.map(monthNumToLabel))
    setLocalSelectedBanks(selectedBanks)
    setLocalBankMode(bankCombinationMode)
    setLocalMotivos(motivosFilter)
    setLocalHigienizacao(higienizacaoFilter)
    setLocalDateFrom(dateFromFilter)
    setLocalDateTo(dateToFilter)
    setLocalContractFrom(contractDateFromFilter)
    setLocalContractTo(contractDateToFilter)
    setLocalVendors(vendorsFilter)
    setLocalFgtsStatus(fgtsAuthorizedFilter)
    setLocalFgtsFrom(fgtsConsultaFromFilter)
    setLocalFgtsTo(fgtsConsultaToFilter)
    setLocalCltConsultado(cltConsultado)
    setLocalCltSituacao(cltSituacao)
    setLocalCltConsultaFrom(cltConsultaFrom)
    setLocalCltConsultaTo(cltConsultaTo)
    setLocalCltAdmissaoFrom(cltAdmissaoFrom)
    setLocalCltAdmissaoTo(cltAdmissaoTo)
    setLocalCltMesesMin(cltMesesMin)
    setLocalCltMesesMax(cltMesesMax)
    setLocalCltInicioFrom(cltInicioEmpregadorFrom)
    setLocalCltInicioTo(cltInicioEmpregadorTo)
    setLocalCltCategorias(cltCategoriaCodigos)
    setLocalCltIdadeMin(cltIdadeMin)
    setLocalCltIdadeMax(cltIdadeMax)
    setLocalCltSexo(cltSexo)
    setLocalCltRendaMin(cltRendaMin)
    setLocalCltRendaMax(cltRendaMax)
    setLocalCltBaseMin(cltBaseMin)
    setLocalCltBaseMax(cltBaseMax)
    setLocalCltMargemMin(cltMargemMin)
    setLocalCltMargemMax(cltMargemMax)
    setLocalCltPrestacaoMin(cltPrestacaoMin)
    setLocalCltPrestacaoMax(cltPrestacaoMax)
    setLocalCltAtivosMin(cltAtivosMin)
    setLocalCltAtivosMax(cltAtivosMax)
    setLocalCltTemAtivos(cltTemAtivos)
    setLocalCltTemLegados(cltTemLegados)
    setLocalMercantilSituacao(mercantilSituacao)
    setLocalMercantilStatus(mercantilStatusFilter)
    setLocalMercantilConsultaFrom(mercantilConsultaFrom)
    setLocalMercantilConsultaTo(mercantilConsultaTo)
    setLocalMercantilParcelaMin(mercantilParcelaMin)
    setLocalMercantilParcelaMax(mercantilParcelaMax)
    setLocalMercantilQtdMin(mercantilQtdParcelasMin)
    setLocalMercantilQtdMax(mercantilQtdParcelasMax)
    setLocalMercantilOrigens(mercantilOrigensFilter)
    setLocalUy3Situacao(uy3Situacao)
    setLocalUy3ConsultaFrom(uy3ConsultaFrom)
    setLocalUy3ConsultaTo(uy3ConsultaTo)
    setLocalUy3MesesMin(uy3MesesAdmissaoMin)
    setLocalUy3MesesMax(uy3MesesAdmissaoMax)
    setLocalUy3MargemMin(uy3MargemMin)
    setLocalUy3MargemMax(uy3MargemMax)
    setLocalUy3ValorMin(uy3ValorLiberadoMin)
    setLocalUy3ValorMax(uy3ValorLiberadoMax)
    setLocalUy3ParcelasMin(uy3NumeroParcelasMin)
    setLocalUy3ParcelasMax(uy3NumeroParcelasMax)
  }, [
    isOpen, searchValue, origemFilter, cpfMassFilter, namesMassFilter, phonesMassFilter, withPhonesFilter, noPhonesFilter,
    birthMonthFilter, selectedBanks, bankCombinationMode, motivosFilter, higienizacaoFilter, dateFromFilter, dateToFilter,
    contractDateFromFilter, contractDateToFilter, vendorsFilter, fgtsAuthorizedFilter, fgtsConsultaFromFilter, fgtsConsultaToFilter,
    cltConsultado, cltSituacao, cltConsultaFrom, cltConsultaTo, cltAdmissaoFrom, cltAdmissaoTo, cltMesesMin, cltMesesMax,
    cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltCategoriaCodigos, cltIdadeMin, cltIdadeMax, cltSexo, cltRendaMin,
    cltRendaMax, cltBaseMin, cltBaseMax, cltMargemMin, cltMargemMax, cltPrestacaoMin, cltPrestacaoMax, cltAtivosMin,
    cltAtivosMax, cltTemAtivos, cltTemLegados, mercantilSituacao, mercantilStatusFilter, mercantilConsultaFrom, mercantilConsultaTo,
    mercantilParcelaMin, mercantilParcelaMax, mercantilQtdParcelasMin, mercantilQtdParcelasMax, mercantilOrigensFilter,
    uy3Situacao, uy3ConsultaFrom, uy3ConsultaTo, uy3MesesAdmissaoMin, uy3MesesAdmissaoMax, uy3MargemMin, uy3MargemMax,
    uy3ValorLiberadoMin, uy3ValorLiberadoMax, uy3NumeroParcelasMin, uy3NumeroParcelasMax,
  ])

  useEffect(() => {
    if (isOpen) document.body.style.overflow = "hidden"
    return () => {
      document.body.style.overflow = ""
    }
  }, [isOpen])

  if (!isOpen) return null

  const toggleBank = (bank: LeadBankKey) => {
    setLocalSelectedBanks((current) =>
      current.includes(bank) ? current.filter((item) => item !== bank) : [...current, bank]
    )
  }

  const apply = () => {
    onSearchChange(localSearch.trim())
    onOrigemFilterChange(localOrigens)
    onCpfMassFilterChange(localCpf.trim())
    onNamesMassFilterChange(localNames.trim())
    onPhonesMassFilterChange(localPhones.trim())
    onWithPhonesFilterChange(localWithPhones)
    onNoPhonesFilterChange(localNoPhones)
    onBirthMonthFilterChange(localBirthMonths.map(monthLabelToNum).filter(isValidMonth))
    onSelectedBanksChange(localSelectedBanks)
    onBankCombinationModeChange(localBankMode)
    onMotivosFilterChange(localMotivos)
    onHigienizacaoFilterChange(localHigienizacao)
    onDateFromFilterChange(localDateFrom)
    onDateToFilterChange(localDateTo)
    onContractDateFromFilterChange(localContractFrom)
    onContractDateToFilterChange(localContractTo)
    onVendorsFilterChange(localVendors)
    onFgtsAuthorizedFilterChange(localFgtsStatus)
    onFgtsConsultaFromFilterChange(localFgtsFrom)
    onFgtsConsultaToFilterChange(localFgtsTo)
    onCltConsultadoChange(localCltConsultado)
    onCltSituacaoChange(localCltSituacao)
    onCltConsultaFromChange(localCltConsultaFrom)
    onCltConsultaToChange(localCltConsultaTo)
    onCltAdmissaoFromChange(localCltAdmissaoFrom)
    onCltAdmissaoToChange(localCltAdmissaoTo)
    onCltMesesMinChange(localCltMesesMin)
    onCltMesesMaxChange(localCltMesesMax)
    onCltInicioEmpregadorFromChange(localCltInicioFrom)
    onCltInicioEmpregadorToChange(localCltInicioTo)
    onCltCategoriaCodigosChange(localCltCategorias)
    onCltIdadeMinChange(localCltIdadeMin)
    onCltIdadeMaxChange(localCltIdadeMax)
    onCltSexoChange(localCltSexo)
    onCltRendaMinChange(localCltRendaMin)
    onCltRendaMaxChange(localCltRendaMax)
    onCltBaseMinChange(localCltBaseMin)
    onCltBaseMaxChange(localCltBaseMax)
    onCltMargemMinChange(localCltMargemMin)
    onCltMargemMaxChange(localCltMargemMax)
    onCltPrestacaoMinChange(localCltPrestacaoMin)
    onCltPrestacaoMaxChange(localCltPrestacaoMax)
    onCltAtivosMinChange(localCltAtivosMin)
    onCltAtivosMaxChange(localCltAtivosMax)
    onCltTemAtivosChange(localCltTemAtivos)
    onCltTemLegadosChange(localCltTemLegados)
    onMercantilSituacaoChange(localMercantilSituacao)
    onMercantilStatusFilterChange(localMercantilSituacao === "sem_consulta" ? [] : localMercantilStatus)
    onMercantilConsultaFromChange(localMercantilConsultaFrom)
    onMercantilConsultaToChange(localMercantilConsultaTo)
    onMercantilParcelaMinChange(localMercantilParcelaMin)
    onMercantilParcelaMaxChange(localMercantilParcelaMax)
    onMercantilQtdParcelasMinChange(localMercantilQtdMin)
    onMercantilQtdParcelasMaxChange(localMercantilQtdMax)
    onMercantilOrigensFilterChange(localMercantilOrigens)
    onUy3SituacaoChange(localUy3Situacao)
    onUy3ConsultaFromChange(localUy3ConsultaFrom)
    onUy3ConsultaToChange(localUy3ConsultaTo)
    onUy3MesesAdmissaoMinChange(localUy3MesesMin)
    onUy3MesesAdmissaoMaxChange(localUy3MesesMax)
    onUy3MargemMinChange(localUy3MargemMin)
    onUy3MargemMaxChange(localUy3MargemMax)
    onUy3ValorLiberadoMinChange(localUy3ValorMin)
    onUy3ValorLiberadoMaxChange(localUy3ValorMax)
    onUy3NumeroParcelasMinChange(localUy3ParcelasMin)
    onUy3NumeroParcelasMaxChange(localUy3ParcelasMax)
    onApplyFilters()
    onClose()
  }

  const showFgts = localSelectedBanks.includes("fgts")
  const showClt = localSelectedBanks.includes("clt")
  const showMercantil = localSelectedBanks.includes("mercantil")
  const showUy3 = localSelectedBanks.includes("uy3")

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
        <header className="flex items-center justify-between border-b p-4 sm:p-6">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Filtros avancados</h2>
            <p className="text-sm text-gray-500">360 Operacional</p>
          </div>
          <button onClick={onClose} className={cn("rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700", NO_FOCUS)}>
            <X className="h-5 w-5" />
          </button>
        </header>

        <main className="flex-1 overflow-y-auto bg-gray-50 p-4 sm:p-6">
          <div className="space-y-6">
            <Section title="Geral">
              <div>
                <Label>Busca geral</Label>
                <Input value={localSearch} onChange={(e) => setLocalSearch(e.target.value)} className={NO_FOCUS} placeholder="Nome, CPF ou telefone" />
              </div>
              <div>
                <Label>Origens</Label>
                <MultiSelect options={availableOrigens} selected={localOrigens} onChange={setLocalOrigens} placeholder="Selecionar origens..." />
              </div>
              <div className="grid gap-3 md:grid-cols-2">
                <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-white p-3">
                  <Checkbox
                    checked={localWithPhones}
                    onCheckedChange={(checked) => {
                      const next = !!checked
                      setLocalWithPhones(next)
                      if (next) setLocalNoPhones(false)
                    }}
                  />
                  <span className="text-sm text-gray-800">Com telefone</span>
                </label>
                <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-white p-3">
                  <Checkbox
                    checked={localNoPhones}
                    onCheckedChange={(checked) => {
                      const next = !!checked
                      setLocalNoPhones(next)
                      if (next) setLocalWithPhones(false)
                    }}
                  />
                  <span className="text-sm text-gray-800">Sem telefone</span>
                </label>
              </div>
              <div>
                <Label>Mes de nascimento</Label>
                <MultiSelect
                  options={Object.values(MONTH_LABELS)}
                  selected={localBirthMonths}
                  onChange={setLocalBirthMonths}
                  placeholder="Selecionar meses..."
                />
              </div>
            </Section>

            <Section title="Fontes">
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                {banksOrdered.map((bank) => (
                  <button
                    key={bank}
                    type="button"
                    onClick={() => toggleBank(bank)}
                    className={cn(
                      "rounded-lg border px-4 py-3 text-left text-sm transition-colors",
                      localSelectedBanks.includes(bank)
                        ? "border-blue-500 bg-blue-50 text-blue-700"
                        : "border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                    )}
                  >
                    {BANK_LABELS[bank]}
                  </button>
                ))}
              </div>
              <div className="max-w-xs">
                <Label>Combinacao</Label>
                <Select value={localBankMode} onValueChange={(value) => setLocalBankMode(value as LeadBankCombinationMode)}>
                  <SelectTrigger className={NO_FOCUS}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Qualquer fonte</SelectItem>
                    <SelectItem value="all">Todas as fontes</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </Section>

            {showFgts && (
              <Section title="FGTS">
                <div>
                  <Label>Motivos</Label>
                  <MultiSelect options={availableMotivos} selected={localMotivos} onChange={setLocalMotivos} placeholder="Selecionar motivos..." />
                </div>
                <div>
                  <Label>Origens de higienizacao</Label>
                  <MultiSelect options={availableHigienizacoes} selected={localHigienizacao} onChange={setLocalHigienizacao} placeholder="Selecionar origens..." />
                </div>
                <div>
                  <Label>Vendedores</Label>
                  <MultiSelect options={availableVendors.map((vendor) => vendor.name)} selected={localVendors} onChange={setLocalVendors} placeholder="Selecionar vendedores..." />
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  <div>
                    <Label>Higienizacao de</Label>
                    <Input type="date" value={localDateFrom} onChange={(e) => setLocalDateFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Higienizacao ate</Label>
                    <Input type="date" value={localDateTo} onChange={(e) => setLocalDateTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Contrato de</Label>
                    <Input type="date" value={localContractFrom} onChange={(e) => setLocalContractFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Contrato ate</Label>
                    <Input type="date" value={localContractTo} onChange={(e) => setLocalContractTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Status FGTS Off</Label>
                    <Select value={localFgtsStatus} onValueChange={(value) => setLocalFgtsStatus(value as FgtsStatusFilter)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="autorizado">Autorizado</SelectItem>
                        <SelectItem value="nao_autorizado">Nao autorizado</SelectItem>
                        <SelectItem value="nao_consultado">Nao consultado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div />
                  <div>
                    <Label>Consulta FGTS Off de</Label>
                    <Input type="date" value={localFgtsFrom} onChange={(e) => setLocalFgtsFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Consulta FGTS Off ate</Label>
                    <Input type="date" value={localFgtsTo} onChange={(e) => setLocalFgtsTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                </div>
              </Section>
            )}

            {showClt && (
              <Section title="CLT Facta">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <div>
                    <Label>Consultado</Label>
                    <Select value={localCltConsultado} onValueChange={(value) => setLocalCltConsultado(value as YesNoAll)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="sim">Sim</SelectItem>
                        <SelectItem value="nao">Nao</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Situacao</Label>
                    <Select value={localCltSituacao} onValueChange={(value) => setLocalCltSituacao(value as CltSituacaoFilter)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="elegivel">Elegivel</SelectItem>
                        <SelectItem value="nao_elegivel">Nao elegivel</SelectItem>
                        <SelectItem value="nao_encontrado">Nao encontrado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Consulta de</Label>
                    <Input type="date" value={localCltConsultaFrom} onChange={(e) => setLocalCltConsultaFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Consulta ate</Label>
                    <Input type="date" value={localCltConsultaTo} onChange={(e) => setLocalCltConsultaTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Admissao de</Label>
                    <Input type="date" value={localCltAdmissaoFrom} onChange={(e) => setLocalCltAdmissaoFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Admissao ate</Label>
                    <Input type="date" value={localCltAdmissaoTo} onChange={(e) => setLocalCltAdmissaoTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Meses admissao min</Label>
                    <Input value={localCltMesesMin} onChange={(e) => setLocalCltMesesMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Meses admissao max</Label>
                    <Input value={localCltMesesMax} onChange={(e) => setLocalCltMesesMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Inicio empregador de</Label>
                    <Input type="date" value={localCltInicioFrom} onChange={(e) => setLocalCltInicioFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Inicio empregador ate</Label>
                    <Input type="date" value={localCltInicioTo} onChange={(e) => setLocalCltInicioTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Idade min</Label>
                    <Input value={localCltIdadeMin} onChange={(e) => setLocalCltIdadeMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Idade max</Label>
                    <Input value={localCltIdadeMax} onChange={(e) => setLocalCltIdadeMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Sexo</Label>
                    <MultiSelect options={["M", "F"]} selected={localCltSexo} onChange={setLocalCltSexo} placeholder="Selecionar sexo..." />
                  </div>
                  <div>
                    <Label>Ativos</Label>
                    <Select value={localCltTemAtivos} onValueChange={(value) => setLocalCltTemAtivos(value as YesNoAll)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="sim">Sim</SelectItem>
                        <SelectItem value="nao">Nao</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Legados</Label>
                    <Select value={localCltTemLegados} onValueChange={(value) => setLocalCltTemLegados(value as YesNoAll)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="sim">Sim</SelectItem>
                        <SelectItem value="nao">Nao</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div>
                  <Label>Categoria(s) trabalhador</Label>
                  <Textarea value={localCltCategorias} onChange={(e) => setLocalCltCategorias(e.target.value)} rows={2} className={NO_FOCUS} />
                </div>
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <div>
                    <Label>Renda min</Label>
                    <Input value={localCltRendaMin} onChange={(e) => setLocalCltRendaMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Renda max</Label>
                    <Input value={localCltRendaMax} onChange={(e) => setLocalCltRendaMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Base min</Label>
                    <Input value={localCltBaseMin} onChange={(e) => setLocalCltBaseMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Base max</Label>
                    <Input value={localCltBaseMax} onChange={(e) => setLocalCltBaseMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Margem min</Label>
                    <Input value={localCltMargemMin} onChange={(e) => setLocalCltMargemMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Margem max</Label>
                    <Input value={localCltMargemMax} onChange={(e) => setLocalCltMargemMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Prestacao min</Label>
                    <Input value={localCltPrestacaoMin} onChange={(e) => setLocalCltPrestacaoMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Prestacao max</Label>
                    <Input value={localCltPrestacaoMax} onChange={(e) => setLocalCltPrestacaoMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Ativos min</Label>
                    <Input value={localCltAtivosMin} onChange={(e) => setLocalCltAtivosMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Ativos max</Label>
                    <Input value={localCltAtivosMax} onChange={(e) => setLocalCltAtivosMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                </div>
              </Section>
            )}

            {showMercantil && (
              <Section title="Mercantil">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <div>
                    <Label>Situacao</Label>
                    <Select value={localMercantilSituacao} onValueChange={(value) => setLocalMercantilSituacao(value as "todos" | "consultado" | "sem_consulta")}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="consultado">Consultado</SelectItem>
                        <SelectItem value="sem_consulta">Sem consulta</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Status</Label>
                    <MultiSelect
                      options={availableMercantilStatuses}
                      selected={localMercantilStatus}
                      onChange={setLocalMercantilStatus}
                      placeholder="Selecionar status..."
                      disabled={localMercantilSituacao === "sem_consulta"}
                    />
                  </div>
                  <div>
                    <Label>Consulta de</Label>
                    <Input type="date" value={localMercantilConsultaFrom} onChange={(e) => setLocalMercantilConsultaFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Consulta ate</Label>
                    <Input type="date" value={localMercantilConsultaTo} onChange={(e) => setLocalMercantilConsultaTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Parcela min</Label>
                    <Input value={localMercantilParcelaMin} onChange={(e) => setLocalMercantilParcelaMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Parcela max</Label>
                    <Input value={localMercantilParcelaMax} onChange={(e) => setLocalMercantilParcelaMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Qtd parcelas min</Label>
                    <Input value={localMercantilQtdMin} onChange={(e) => setLocalMercantilQtdMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Qtd parcelas max</Label>
                    <Input value={localMercantilQtdMax} onChange={(e) => setLocalMercantilQtdMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                </div>
                <div>
                  <Label>Origens mercantil</Label>
                  <MultiSelect options={availableMercantilOrigens} selected={localMercantilOrigens} onChange={setLocalMercantilOrigens} placeholder="Selecionar origens..." />
                </div>
              </Section>
            )}

            {showUy3 && (
              <Section title="UY3">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <div>
                    <Label>Situacao</Label>
                    <Select value={localUy3Situacao} onValueChange={(value) => setLocalUy3Situacao(value as Uy3SituacaoFilter)}>
                      <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="aprovado">Aprovado</SelectItem>
                        <SelectItem value="nao_aprovado">Nao aprovado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Consulta de</Label>
                    <Input type="date" value={localUy3ConsultaFrom} onChange={(e) => setLocalUy3ConsultaFrom(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Consulta ate</Label>
                    <Input type="date" value={localUy3ConsultaTo} onChange={(e) => setLocalUy3ConsultaTo(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div />
                  <div>
                    <Label>Meses admissao min</Label>
                    <Input value={localUy3MesesMin} onChange={(e) => setLocalUy3MesesMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Meses admissao max</Label>
                    <Input value={localUy3MesesMax} onChange={(e) => setLocalUy3MesesMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Margem min</Label>
                    <Input value={localUy3MargemMin} onChange={(e) => setLocalUy3MargemMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Margem max</Label>
                    <Input value={localUy3MargemMax} onChange={(e) => setLocalUy3MargemMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Valor liberado min</Label>
                    <Input value={localUy3ValorMin} onChange={(e) => setLocalUy3ValorMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Valor liberado max</Label>
                    <Input value={localUy3ValorMax} onChange={(e) => setLocalUy3ValorMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Parcelas min</Label>
                    <Input value={localUy3ParcelasMin} onChange={(e) => setLocalUy3ParcelasMin(e.target.value)} className={NO_FOCUS} />
                  </div>
                  <div>
                    <Label>Parcelas max</Label>
                    <Input value={localUy3ParcelasMax} onChange={(e) => setLocalUy3ParcelasMax(e.target.value)} className={NO_FOCUS} />
                  </div>
                </div>
              </Section>
            )}

            <Section title="Em massa">
              <div className="grid gap-3">
                <div>
                  <Label>CPFs</Label>
                  <Textarea rows={3} value={localCpf} onChange={(e) => setLocalCpf(e.target.value)} className={NO_FOCUS} />
                </div>
                <div>
                  <Label>Nomes</Label>
                  <Textarea rows={3} value={localNames} onChange={(e) => setLocalNames(e.target.value)} className={NO_FOCUS} />
                </div>
                <div>
                  <Label>Telefones</Label>
                  <Textarea rows={3} value={localPhones} onChange={(e) => setLocalPhones(e.target.value)} className={NO_FOCUS} />
                </div>
              </div>
            </Section>
          </div>
        </main>

        <footer className="flex flex-col-reverse gap-2 border-t bg-white p-4 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => { onClearFilters(); onClose() }}>
            Limpar filtros
          </Button>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button className="bg-blue-600 hover:bg-blue-700" onClick={apply}>
            <Filter className="mr-2 h-4 w-4" />
            Aplicar filtros
          </Button>
        </footer>
      </div>
    </div>
  )
}
