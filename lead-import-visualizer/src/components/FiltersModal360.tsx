import { useEffect, useState } from "react"
import { X, Filter, Check, Info } from "lucide-react"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"
import type { LeadBankCombinationMode, LeadBankKey } from "@/api/leads"

type LoanSituation = "todos" | "aprovado" | "nao_aprovado"

type Props = {
  isOpen: boolean
  onClose: () => void
  onApplyFilters: () => void
  onClearFilters: () => void
  withPhonesFilter: boolean
  onWithPhonesFilterChange: (v: boolean) => void
  noPhonesFilter: boolean
  onNoPhonesFilterChange: (v: boolean) => void
  selectedBanks: LeadBankKey[]
  onSelectedBanksChange: (v: LeadBankKey[]) => void
  bankCombinationMode: LeadBankCombinationMode
  onBankCombinationModeChange: (v: LeadBankCombinationMode) => void
  cltSituacao: LoanSituation
  onCltSituacaoChange: (v: LoanSituation) => void
  cltConsultaFrom: string
  onCltConsultaFromChange: (v: string) => void
  cltConsultaTo: string
  onCltConsultaToChange: (v: string) => void
  cltMesesAdmissaoMin: string
  onCltMesesAdmissaoMinChange: (v: string) => void
  cltMesesAdmissaoMax: string
  onCltMesesAdmissaoMaxChange: (v: string) => void
  cltMargemMin: string
  onCltMargemMinChange: (v: string) => void
  cltMargemMax: string
  onCltMargemMaxChange: (v: string) => void
  cltNumeroParcelasMin: string
  onCltNumeroParcelasMinChange: (v: string) => void
  cltNumeroParcelasMax: string
  onCltNumeroParcelasMaxChange: (v: string) => void
  mercantilSituacao: LoanSituation
  onMercantilSituacaoChange: (v: LoanSituation) => void
  mercantilConsultaFrom: string
  onMercantilConsultaFromChange: (v: string) => void
  mercantilConsultaTo: string
  onMercantilConsultaToChange: (v: string) => void
  mercantilValorParcelaMin: string
  onMercantilValorParcelaMinChange: (v: string) => void
  mercantilValorParcelaMax: string
  onMercantilValorParcelaMaxChange: (v: string) => void
  mercantilNumeroParcelasMin: string
  onMercantilNumeroParcelasMinChange: (v: string) => void
  mercantilNumeroParcelasMax: string
  onMercantilNumeroParcelasMaxChange: (v: string) => void
  uy3Situacao: LoanSituation
  onUy3SituacaoChange: (v: LoanSituation) => void
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

const NO_FOCUS = "focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0 focus:shadow-none"
const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"

const any = (arr: (string | null | undefined)[]) => arr.some((v) => !!(v && String(v).trim()))

function Section({
  title,
  description,
  active = false,
  imageSrc,
  children,
}: {
  title: string
  description?: string
  active?: boolean
  imageSrc?: string
  children: React.ReactNode
}) {
  return (
    <section
      className={cn(
        "rounded-lg border p-4 sm:p-5 bg-white transition-all duration-200",
        "shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:shadow-md h-full",
        active
          ? "border-blue-300 ring-1 ring-blue-200 shadow-md"
          : "border-gray-200"
      )}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          {imageSrc && <img src={imageSrc} alt="" className="h-5 w-5 object-contain" />}
          <div>
            <h3 className={cn("text-sm font-semibold tracking-tight", active ? "text-blue-700" : "text-gray-800")}>
              {title}
            </h3>
            {description && <p className="mt-0.5 text-xs text-gray-500">{description}</p>}
          </div>
        </div>
        {active && (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50/80 px-2 py-0.5 text-[11px] font-medium text-blue-700 shadow-sm whitespace-nowrap">
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

function Group({
  title,
  imageSrc,
  imageAlt,
  children,
}: {
  title: string
  imageSrc?: string
  imageAlt?: string
  children: React.ReactNode
}) {
  return (
    <div className="mb-6">
      <div className="mb-3 border-b pb-2 bg-gradient-to-r from-white to-transparent">
        <div className="flex items-center gap-2">
          {imageSrc ? <img src={imageSrc} alt={imageAlt ?? ""} className="h-5 w-5 object-contain" /> : null}
          <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
        </div>
      </div>
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

const BANKS = [
  { value: "clt" as LeadBankKey, label: "Facta", imageSrc: factaLogo, alt: "Facta" },
  { value: "mercantil" as LeadBankKey, label: "Mercantil", imageSrc: mercantilLogo, alt: "Mercantil" },
  { value: "uy3" as LeadBankKey, label: "UY3", imageSrc: uy3Logo, alt: "UY3" },
]

export const FiltersModal360 = ({
  isOpen,
  onClose,
  onApplyFilters,
  onClearFilters,
  withPhonesFilter,
  onWithPhonesFilterChange,
  noPhonesFilter,
  onNoPhonesFilterChange,
  selectedBanks = [],
  onSelectedBanksChange,
  bankCombinationMode,
  onBankCombinationModeChange,
  cltSituacao,
  onCltSituacaoChange,
  cltConsultaFrom,
  onCltConsultaFromChange,
  cltConsultaTo,
  onCltConsultaToChange,
  cltMesesAdmissaoMin,
  onCltMesesAdmissaoMinChange,
  cltMesesAdmissaoMax,
  onCltMesesAdmissaoMaxChange,
  cltMargemMin,
  onCltMargemMinChange,
  cltMargemMax,
  onCltMargemMaxChange,
  cltNumeroParcelasMin,
  onCltNumeroParcelasMinChange,
  cltNumeroParcelasMax,
  onCltNumeroParcelasMaxChange,
  mercantilSituacao,
  onMercantilSituacaoChange,
  mercantilConsultaFrom,
  onMercantilConsultaFromChange,
  mercantilConsultaTo,
  onMercantilConsultaToChange,
  mercantilValorParcelaMin,
  onMercantilValorParcelaMinChange,
  mercantilValorParcelaMax,
  onMercantilValorParcelaMaxChange,
  mercantilNumeroParcelasMin,
  onMercantilNumeroParcelasMinChange,
  mercantilNumeroParcelasMax,
  onMercantilNumeroParcelasMaxChange,
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
  const [localWithPhones, setLocalWithPhones] = useState(withPhonesFilter)
  const [localNoPhones, setLocalNoPhones] = useState(noPhonesFilter)
  const [localSelectedBanks, setLocalSelectedBanks] = useState<LeadBankKey[]>(selectedBanks.filter((bank) => bank !== "fgts"))
  const [localBankMode, setLocalBankMode] = useState<LeadBankCombinationMode>(bankCombinationMode)
  const [localCltSituacao, setLocalCltSituacao] = useState<LoanSituation>(cltSituacao)
  const [localCltConsultaFrom, setLocalCltConsultaFrom] = useState(cltConsultaFrom)
  const [localCltConsultaTo, setLocalCltConsultaTo] = useState(cltConsultaTo)
  const [localCltMesesMin, setLocalCltMesesMin] = useState(cltMesesAdmissaoMin)
  const [localCltMesesMax, setLocalCltMesesMax] = useState(cltMesesAdmissaoMax)
  const [localCltMargemMin, setLocalCltMargemMin] = useState(cltMargemMin)
  const [localCltMargemMax, setLocalCltMargemMax] = useState(cltMargemMax)
  const [localCltParcelasMin, setLocalCltParcelasMin] = useState(cltNumeroParcelasMin)
  const [localCltParcelasMax, setLocalCltParcelasMax] = useState(cltNumeroParcelasMax)
  const [localMercantilSituacao, setLocalMercantilSituacao] = useState<LoanSituation>(mercantilSituacao)
  const [localMercantilConsultaFrom, setLocalMercantilConsultaFrom] = useState(mercantilConsultaFrom)
  const [localMercantilConsultaTo, setLocalMercantilConsultaTo] = useState(mercantilConsultaTo)
  const [localMercantilParcelaMin, setLocalMercantilParcelaMin] = useState(mercantilValorParcelaMin)
  const [localMercantilParcelaMax, setLocalMercantilParcelaMax] = useState(mercantilValorParcelaMax)
  const [localMercantilParcelasMin, setLocalMercantilParcelasMin] = useState(mercantilNumeroParcelasMin)
  const [localMercantilParcelasMax, setLocalMercantilParcelasMax] = useState(mercantilNumeroParcelasMax)
  const [localUy3Situacao, setLocalUy3Situacao] = useState<LoanSituation>(uy3Situacao)
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
    setLocalWithPhones(withPhonesFilter)
    setLocalNoPhones(noPhonesFilter)
    setLocalSelectedBanks(selectedBanks.filter((bank) => bank !== "fgts"))
    setLocalBankMode(bankCombinationMode)
    setLocalCltSituacao(cltSituacao)
    setLocalCltConsultaFrom(cltConsultaFrom)
    setLocalCltConsultaTo(cltConsultaTo)
    setLocalCltMesesMin(cltMesesAdmissaoMin)
    setLocalCltMesesMax(cltMesesAdmissaoMax)
    setLocalCltMargemMin(cltMargemMin)
    setLocalCltMargemMax(cltMargemMax)
    setLocalCltParcelasMin(cltNumeroParcelasMin)
    setLocalCltParcelasMax(cltNumeroParcelasMax)
    setLocalMercantilSituacao(mercantilSituacao)
    setLocalMercantilConsultaFrom(mercantilConsultaFrom)
    setLocalMercantilConsultaTo(mercantilConsultaTo)
    setLocalMercantilParcelaMin(mercantilValorParcelaMin)
    setLocalMercantilParcelaMax(mercantilValorParcelaMax)
    setLocalMercantilParcelasMin(mercantilNumeroParcelasMin)
    setLocalMercantilParcelasMax(mercantilNumeroParcelasMax)
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
    isOpen, withPhonesFilter, noPhonesFilter, selectedBanks, bankCombinationMode,
    cltSituacao, cltConsultaFrom, cltConsultaTo, cltMesesAdmissaoMin, cltMesesAdmissaoMax, cltMargemMin, cltMargemMax, cltNumeroParcelasMin, cltNumeroParcelasMax,
    mercantilSituacao, mercantilConsultaFrom, mercantilConsultaTo, mercantilValorParcelaMin, mercantilValorParcelaMax, mercantilNumeroParcelasMin, mercantilNumeroParcelasMax,
    uy3Situacao, uy3ConsultaFrom, uy3ConsultaTo, uy3MesesAdmissaoMin, uy3MesesAdmissaoMax, uy3MargemMin, uy3MargemMax, uy3ValorLiberadoMin, uy3ValorLiberadoMax, uy3NumeroParcelasMin, uy3NumeroParcelasMax,
  ])

  useEffect(() => {
    if (isOpen) document.body.style.overflow = "hidden"
    return () => { document.body.style.overflow = "" }
  }, [isOpen])

  if (!isOpen) return null

  const toggleBank = (bank: LeadBankKey) => {
    setLocalSelectedBanks((current) =>
      current.includes(bank) ? current.filter((item) => item !== bank) : [...current, bank]
    )
  }

  const apply = () => {
    onWithPhonesFilterChange(localWithPhones)
    onNoPhonesFilterChange(localNoPhones)
    onSelectedBanksChange(localSelectedBanks)
    onBankCombinationModeChange(localBankMode)
    onCltSituacaoChange(localCltSituacao)
    onCltConsultaFromChange(localCltConsultaFrom)
    onCltConsultaToChange(localCltConsultaTo)
    onCltMesesAdmissaoMinChange(localCltMesesMin)
    onCltMesesAdmissaoMaxChange(localCltMesesMax)
    onCltMargemMinChange(localCltMargemMin)
    onCltMargemMaxChange(localCltMargemMax)
    onCltNumeroParcelasMinChange(localCltParcelasMin)
    onCltNumeroParcelasMaxChange(localCltParcelasMax)
    onMercantilSituacaoChange(localMercantilSituacao)
    onMercantilConsultaFromChange(localMercantilConsultaFrom)
    onMercantilConsultaToChange(localMercantilConsultaTo)
    onMercantilValorParcelaMinChange(localMercantilParcelaMin)
    onMercantilValorParcelaMaxChange(localMercantilParcelaMax)
    onMercantilNumeroParcelasMinChange(localMercantilParcelasMin)
    onMercantilNumeroParcelasMaxChange(localMercantilParcelasMax)
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

  const showClt = localSelectedBanks.includes("clt")
  const showMercantil = localSelectedBanks.includes("mercantil")
  const showUy3 = localSelectedBanks.includes("uy3")

  // Actives logic for header chips
  const actPhones = localWithPhones || localNoPhones
  const actClt = localCltSituacao !== "todos" || any([localCltConsultaFrom, localCltConsultaTo, localCltMesesMin, localCltMesesMax, localCltMargemMin, localCltMargemMax, localCltParcelasMin, localCltParcelasMax])
  const actMercantil = localMercantilSituacao !== "todos" || any([localMercantilConsultaFrom, localMercantilConsultaTo, localMercantilParcelaMin, localMercantilParcelaMax, localMercantilParcelasMin, localMercantilParcelasMax])
  const actUy3 = localUy3Situacao !== "todos" || any([localUy3ConsultaFrom, localUy3ConsultaTo, localUy3MesesMin, localUy3MesesMax, localUy3MargemMin, localUy3MargemMax, localUy3ValorMin, localUy3ValorMax, localUy3ParcelasMin, localUy3ParcelasMax])

  const chips: string[] = []
  if (localWithPhones) chips.push("Com telefone")
  if (localNoPhones) chips.push("Sem telefone")
  if (localSelectedBanks.length > 0) chips.push(`Bancos (${localSelectedBanks.length})`)
  if (showClt && actClt) chips.push("Facta")
  if (showMercantil && actMercantil) chips.push("Mercantil")
  if (showUy3 && actUy3) chips.push("UY3")

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-[2px] p-4">
      <div className="filters-modal flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/10">
        
        {/* Cabeçalho */}
        <header className="flex flex-col gap-3 border-b p-4 sm:p-6 flex-shrink-0 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/70 shadow-sm">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Filter className="h-5 w-5 text-gray-600" />
              <div>
                <h2 className="text-lg sm:text-xl font-semibold text-gray-900">Filtros avançados</h2>
              </div>
            </div>
            <button
              onClick={onClose}
              className={cn("rounded p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700", NO_FOCUS)}
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

        <div className="px-4 sm:px-6 py-2 bg-gray-50/90 backdrop-blur border-b flex items-center gap-2 shadow-[inset_0_-1px_0_rgba(0,0,0,0.03)]">
          <Info className="w-4 h-4 text-gray-500" />
          <span className="text-xs sm:text-sm text-gray-700">
            Filtrando dados de: <strong>360</strong>
          </span>
        </div>

        <main className="flex-1 overflow-y-auto p-4 sm:p-6 bg-gradient-to-b from-white to-gray-50">
          
          <Group title="Geral">
            <div>
              <Section title="Telefones" description="Filtre pela presença de telefone." active={actPhones}>
                <div className="space-y-3">
                  <label className="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50/60 p-3 cursor-pointer">
                    <Checkbox
                      checked={localWithPhones}
                      onCheckedChange={(checked) => {
                        const next = !!checked
                        setLocalWithPhones(next)
                        if (next) setLocalNoPhones(false)
                      }}
                      className={cn("mt-0.5", CHECKBOX_CLASS_NAME)}
                    />
                    <div className="space-y-1">
                      <div className={cn("text-sm font-medium", localWithPhones ? "text-blue-700" : "text-gray-800")}>
                        Com telefone
                      </div>
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
                      className={cn("mt-0.5", CHECKBOX_CLASS_NAME)}
                    />
                    <div className="space-y-1">
                      <div className={cn("text-sm font-medium", localNoPhones ? "text-blue-700" : "text-gray-800")}>
                        Sem telefone
                      </div>
                    </div>
                  </label>
                </div>
              </Section>
            </div>

            <div className="col-span-1 lg:col-span-2">
              <Section title="Bancos da Consulta" description="Combine múltiplos bancos e defina a regra." active={localSelectedBanks.length > 0}>
                <div className="grid gap-3 md:grid-cols-3 mb-4">
                  {BANKS.map((bank) => (
                    <label
                      key={bank.value}
                      className={cn(
                        "flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors",
                        localSelectedBanks.includes(bank.value)
                          ? "border-blue-500 bg-blue-50/50 text-blue-900 shadow-sm"
                          : "border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                      )}
                    >
                      <Checkbox
                        className={CHECKBOX_CLASS_NAME}
                        checked={localSelectedBanks.includes(bank.value)}
                        onCheckedChange={(checked) => {
                          if (Boolean(checked) !== localSelectedBanks.includes(bank.value)) toggleBank(bank.value)
                        }}
                      />
                      <img src={bank.imageSrc} alt={bank.alt} className="h-6 w-6 object-contain" />
                      <span className="font-medium">{bank.label}</span>
                    </label>
                  ))}
                </div>
                
                <div>
                  <Label text="Modo de combinação" active={localBankMode !== "any"} />
                  <Select value={localBankMode} onValueChange={(value) => setLocalBankMode(value as LeadBankCombinationMode)}>
                    <SelectTrigger className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localBankMode !== "any" && "ring-1 ring-blue-200")}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="any">Qualquer banco selecionado</SelectItem>
                      <SelectItem value="all">Todos os bancos selecionados</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </Section>
            </div>
          </Group>

          {showClt && (
            <Group title="Filtros Facta" imageSrc={factaLogo} imageAlt="Facta">
              <div>
                <Section title="Situação e Período" active={localCltSituacao !== "todos" || any([localCltConsultaFrom, localCltConsultaTo])}>
                  <div className="mb-3">
                    <Label text="Situação" active={localCltSituacao !== "todos"} />
                    <Select value={localCltSituacao} onValueChange={(value) => setLocalCltSituacao(value as LoanSituation)}>
                      <SelectTrigger className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="aprovado">Aprovado</SelectItem>
                        <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label text="Período da Consulta" active={any([localCltConsultaFrom, localCltConsultaTo])} />
                    <div className="mt-1 grid grid-cols-2 gap-3">
                      <Input type="date" value={localCltConsultaFrom} onChange={(e) => setLocalCltConsultaFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localCltConsultaFrom && "ring-1 ring-blue-200")} />
                      <Input type="date" value={localCltConsultaTo} onChange={(e) => setLocalCltConsultaTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localCltConsultaTo && "ring-1 ring-blue-200")} />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section title="Vínculo" active={any([localCltMesesMin, localCltMesesMax])}>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Meses admissão (mín)" active={!!localCltMesesMin} />
                      <Input type="number" value={localCltMesesMin} onChange={(e) => setLocalCltMesesMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 1" />
                    </div>
                    <div>
                      <Label text="Meses admissão (máx)" active={!!localCltMesesMax} />
                      <Input type="number" value={localCltMesesMax} onChange={(e) => setLocalCltMesesMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 240" />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section title="Margem e Parcelas" active={any([localCltMargemMin, localCltMargemMax, localCltParcelasMin, localCltParcelasMax])}>
                  <div className="grid grid-cols-2 gap-3 mb-3">
                    <div>
                      <Label text="Margem (mín)" active={!!localCltMargemMin} />
                      <Input inputMode="decimal" value={localCltMargemMin} onChange={(e) => setLocalCltMargemMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 0,00" />
                    </div>
                    <div>
                      <Label text="Margem (máx)" active={!!localCltMargemMax} />
                      <Input inputMode="decimal" value={localCltMargemMax} onChange={(e) => setLocalCltMargemMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 2000,00" />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Qtd. parcelas (mín)" active={!!localCltParcelasMin} />
                      <Input type="number" value={localCltParcelasMin} onChange={(e) => setLocalCltParcelasMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 1" />
                    </div>
                    <div>
                      <Label text="Qtd. parcelas (máx)" active={!!localCltParcelasMax} />
                      <Input type="number" value={localCltParcelasMax} onChange={(e) => setLocalCltParcelasMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 120" />
                    </div>
                  </div>
                </Section>
              </div>
            </Group>
          )}

          {showMercantil && (
            <Group title="Filtros Mercantil" imageSrc={mercantilLogo} imageAlt="Mercantil">
              <div>
                <Section title="Situação e Período" active={localMercantilSituacao !== "todos" || any([localMercantilConsultaFrom, localMercantilConsultaTo])}>
                  <div className="mb-3">
                    <Label text="Situação" active={localMercantilSituacao !== "todos"} />
                    <Select value={localMercantilSituacao} onValueChange={(value) => setLocalMercantilSituacao(value as LoanSituation)}>
                      <SelectTrigger className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="aprovado">Aprovado</SelectItem>
                        <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label text="Período da Consulta" active={any([localMercantilConsultaFrom, localMercantilConsultaTo])} />
                    <div className="mt-1 grid grid-cols-2 gap-3">
                      <Input type="date" value={localMercantilConsultaFrom} onChange={(e) => setLocalMercantilConsultaFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localMercantilConsultaFrom && "ring-1 ring-blue-200")} />
                      <Input type="date" value={localMercantilConsultaTo} onChange={(e) => setLocalMercantilConsultaTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localMercantilConsultaTo && "ring-1 ring-blue-200")} />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section title="Financeiro" active={any([localMercantilParcelaMin, localMercantilParcelaMax, localMercantilParcelasMin, localMercantilParcelasMax])}>
                  <div className="grid grid-cols-2 gap-3 mb-3">
                    <div>
                      <Label text="Valor parcela (mín)" active={!!localMercantilParcelaMin} />
                      <Input inputMode="decimal" value={localMercantilParcelaMin} onChange={(e) => setLocalMercantilParcelaMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 0,00" />
                    </div>
                    <div>
                      <Label text="Valor parcela (máx)" active={!!localMercantilParcelaMax} />
                      <Input inputMode="decimal" value={localMercantilParcelaMax} onChange={(e) => setLocalMercantilParcelaMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 1000,00" />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Qtd. parcelas (mín)" active={!!localMercantilParcelasMin} />
                      <Input type="number" value={localMercantilParcelasMin} onChange={(e) => setLocalMercantilParcelasMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 1" />
                    </div>
                    <div>
                      <Label text="Qtd. parcelas (máx)" active={!!localMercantilParcelasMax} />
                      <Input type="number" value={localMercantilParcelasMax} onChange={(e) => setLocalMercantilParcelasMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 120" />
                    </div>
                  </div>
                </Section>
              </div>
            </Group>
          )}

          {showUy3 && (
            <Group title="Filtros UY3" imageSrc={uy3Logo} imageAlt="UY3">
              <div>
                <Section title="Situação e Período" active={localUy3Situacao !== "todos" || any([localUy3ConsultaFrom, localUy3ConsultaTo])}>
                  <div className="mb-3">
                    <Label text="Situação" active={localUy3Situacao !== "todos"} />
                    <Select value={localUy3Situacao} onValueChange={(value) => setLocalUy3Situacao(value as LoanSituation)}>
                      <SelectTrigger className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")}><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="todos">Todos</SelectItem>
                        <SelectItem value="aprovado">Aprovado</SelectItem>
                        <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label text="Atualização de" active={any([localUy3ConsultaFrom, localUy3ConsultaTo])} />
                    <div className="mt-1 grid grid-cols-2 gap-3">
                      <Input type="date" value={localUy3ConsultaFrom} onChange={(e) => setLocalUy3ConsultaFrom(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localUy3ConsultaFrom && "ring-1 ring-blue-200")} />
                      <Input type="date" value={localUy3ConsultaTo} onChange={(e) => setLocalUy3ConsultaTo(e.target.value)} className={cn(NO_FOCUS, "shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]", localUy3ConsultaTo && "ring-1 ring-blue-200")} />
                    </div>
                  </div>
                </Section>
              </div>

              <div>
                <Section title="Vínculo" active={any([localUy3MesesMin, localUy3MesesMax])}>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <Label text="Meses admissão (mín)" active={!!localUy3MesesMin} />
                      <Input type="number" value={localUy3MesesMin} onChange={(e) => setLocalUy3MesesMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 1" />
                    </div>
                    <div>
                      <Label text="Meses admissão (máx)" active={!!localUy3MesesMax} />
                      <Input type="number" value={localUy3MesesMax} onChange={(e) => setLocalUy3MesesMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 240" />
                    </div>
                  </div>
                </Section>
              </div>

              <div className="col-span-1 lg:col-span-2">
                <Section title="Financeiro" active={any([localUy3MargemMin, localUy3MargemMax, localUy3ValorMin, localUy3ValorMax, localUy3ParcelasMin, localUy3ParcelasMax])}>
                  <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div className="space-y-3">
                      <div>
                        <Label text="Margem (mín)" active={!!localUy3MargemMin} />
                        <Input inputMode="decimal" value={localUy3MargemMin} onChange={(e) => setLocalUy3MargemMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 0,00" />
                      </div>
                      <div>
                        <Label text="Margem (máx)" active={!!localUy3MargemMax} />
                        <Input inputMode="decimal" value={localUy3MargemMax} onChange={(e) => setLocalUy3MargemMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 2000,00" />
                      </div>
                    </div>

                    <div className="space-y-3">
                      <div>
                        <Label text="Valor liberado (mín)" active={!!localUy3ValorMin} />
                        <Input inputMode="decimal" value={localUy3ValorMin} onChange={(e) => setLocalUy3ValorMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 0,00" />
                      </div>
                      <div>
                        <Label text="Valor liberado (máx)" active={!!localUy3ValorMax} />
                        <Input inputMode="decimal" value={localUy3ValorMax} onChange={(e) => setLocalUy3ValorMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="R$ 10000,00" />
                      </div>
                    </div>

                    <div className="space-y-3">
                      <div>
                        <Label text="Qtd. parcelas (mín)" active={!!localUy3ParcelasMin} />
                        <Input type="number" value={localUy3ParcelasMin} onChange={(e) => setLocalUy3ParcelasMin(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 1" />
                      </div>
                      <div>
                        <Label text="Qtd. parcelas (máx)" active={!!localUy3ParcelasMax} />
                        <Input type="number" value={localUy3ParcelasMax} onChange={(e) => setLocalUy3ParcelasMax(e.target.value)} className={cn(NO_FOCUS, "mt-1 shadow-[inset_0_1px_2px_rgba(0,0,0,0.03)]")} placeholder="Ex.: 120" />
                      </div>
                    </div>
                  </div>
                </Section>
              </div>
            </Group>
          )}

        </main>

        <footer className="flex flex-col-reverse gap-2 border-t p-4 sm:flex-row sm:items-center sm:justify-end sm:gap-2 flex-shrink-0 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/70 shadow-sm">
          <Button
            variant="outline"
            className={cn("border-gray-300 text-gray-700 hover:bg-gray-50", NO_FOCUS)}
            onClick={() => { onClearFilters(); onClose() }}
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

          <Button className={cn("bg-blue-600 hover:bg-blue-700 shadow-md hover:shadow-lg transition-shadow", NO_FOCUS)} onClick={apply}>
            <Filter className="mr-2 h-4 w-4" />
            Aplicar filtros
          </Button>
        </footer>
      </div>

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
