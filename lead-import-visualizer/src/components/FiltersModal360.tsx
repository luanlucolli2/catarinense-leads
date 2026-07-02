import { useEffect, useState } from "react"
import { X, Filter } from "lucide-react"
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

const Section = ({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) => (
  <section className="rounded-lg border border-gray-200 bg-white p-4">
    <h3 className="mb-3 text-sm font-semibold text-gray-900">{title}</h3>
    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">{children}</div>
  </section>
)

const Field = ({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) => (
  <div className="space-y-2">
    <label className="text-xs font-medium text-gray-700">{label}</label>
    {children}
  </div>
)

const BANKS: Array<{ value: LeadBankKey; label: string }> = [
  { value: "clt", label: "CLT Facta" },
  { value: "mercantil", label: "Mercantil" },
  { value: "uy3", label: "UY3" },
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
    isOpen,
    withPhonesFilter,
    noPhonesFilter,
    selectedBanks,
    bankCombinationMode,
    cltSituacao,
    cltConsultaFrom,
    cltConsultaTo,
    cltMesesAdmissaoMin,
    cltMesesAdmissaoMax,
    cltMargemMin,
    cltMargemMax,
    cltNumeroParcelasMin,
    cltNumeroParcelasMax,
    mercantilSituacao,
    mercantilConsultaFrom,
    mercantilConsultaTo,
    mercantilValorParcelaMin,
    mercantilValorParcelaMax,
    mercantilNumeroParcelasMin,
    mercantilNumeroParcelasMax,
    uy3Situacao,
    uy3ConsultaFrom,
    uy3ConsultaTo,
    uy3MesesAdmissaoMin,
    uy3MesesAdmissaoMax,
    uy3MargemMin,
    uy3MargemMax,
    uy3ValorLiberadoMin,
    uy3ValorLiberadoMax,
    uy3NumeroParcelasMin,
    uy3NumeroParcelasMax,
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

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
        <header className="flex items-center justify-between border-b p-4 sm:p-6">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Filtros avançados</h2>
            <p className="text-sm text-gray-500">360 Operacional</p>
          </div>
          <button onClick={onClose} className={cn("rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700", NO_FOCUS)}>
            <X className="h-5 w-5" />
          </button>
        </header>

        <main className="flex-1 overflow-y-auto bg-gray-50 p-4 sm:p-6">
          <div className="space-y-6">
            <Section title="Geral">
              <div className="md:col-span-2 xl:col-span-4 grid gap-3 md:grid-cols-2">
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
            </Section>

            <Section title="Fontes">
              <div className="md:col-span-2 xl:col-span-4 grid gap-3 md:grid-cols-3">
                {BANKS.map((bank) => (
                  <button
                    key={bank.value}
                    type="button"
                    onClick={() => toggleBank(bank.value)}
                    className={cn(
                      "rounded-lg border px-4 py-3 text-left text-sm transition-colors",
                      localSelectedBanks.includes(bank.value)
                        ? "border-blue-500 bg-blue-50 text-blue-700"
                        : "border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                    )}
                  >
                    {bank.label}
                  </button>
                ))}
              </div>
              <Field label="Combinação">
                <Select value={localBankMode} onValueChange={(value) => setLocalBankMode(value as LeadBankCombinationMode)}>
                  <SelectTrigger className={NO_FOCUS}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="any">Qualquer banco</SelectItem>
                    <SelectItem value="all">Todos os bancos</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
            </Section>

            {showClt && (
              <Section title="CLT Facta">
                <Field label="Situação">
                  <Select value={localCltSituacao} onValueChange={(value) => setLocalCltSituacao(value as LoanSituation)}>
                    <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="todos">Todos</SelectItem>
                      <SelectItem value="aprovado">Aprovado</SelectItem>
                      <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Consulta de">
                  <Input type="date" value={localCltConsultaFrom} onChange={(e) => setLocalCltConsultaFrom(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Consulta até">
                  <Input type="date" value={localCltConsultaTo} onChange={(e) => setLocalCltConsultaTo(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Meses admissão mín.">
                  <Input value={localCltMesesMin} onChange={(e) => setLocalCltMesesMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Meses admissão máx.">
                  <Input value={localCltMesesMax} onChange={(e) => setLocalCltMesesMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Margem mín.">
                  <Input value={localCltMargemMin} onChange={(e) => setLocalCltMargemMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Margem máx.">
                  <Input value={localCltMargemMax} onChange={(e) => setLocalCltMargemMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas mín.">
                  <Input value={localCltParcelasMin} onChange={(e) => setLocalCltParcelasMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas máx.">
                  <Input value={localCltParcelasMax} onChange={(e) => setLocalCltParcelasMax(e.target.value)} className={NO_FOCUS} />
                </Field>
              </Section>
            )}

            {showMercantil && (
              <Section title="Mercantil">
                <Field label="Situação">
                  <Select value={localMercantilSituacao} onValueChange={(value) => setLocalMercantilSituacao(value as LoanSituation)}>
                    <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="todos">Todos</SelectItem>
                      <SelectItem value="aprovado">Aprovado</SelectItem>
                      <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Consulta de">
                  <Input type="date" value={localMercantilConsultaFrom} onChange={(e) => setLocalMercantilConsultaFrom(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Consulta até">
                  <Input type="date" value={localMercantilConsultaTo} onChange={(e) => setLocalMercantilConsultaTo(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Valor parcela mín.">
                  <Input value={localMercantilParcelaMin} onChange={(e) => setLocalMercantilParcelaMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Valor parcela máx.">
                  <Input value={localMercantilParcelaMax} onChange={(e) => setLocalMercantilParcelaMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas mín.">
                  <Input value={localMercantilParcelasMin} onChange={(e) => setLocalMercantilParcelasMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas máx.">
                  <Input value={localMercantilParcelasMax} onChange={(e) => setLocalMercantilParcelasMax(e.target.value)} className={NO_FOCUS} />
                </Field>
              </Section>
            )}

            {showUy3 && (
              <Section title="UY3">
                <Field label="Situação">
                  <Select value={localUy3Situacao} onValueChange={(value) => setLocalUy3Situacao(value as LoanSituation)}>
                    <SelectTrigger className={NO_FOCUS}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="todos">Todos</SelectItem>
                      <SelectItem value="aprovado">Aprovado</SelectItem>
                      <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Consulta de">
                  <Input type="date" value={localUy3ConsultaFrom} onChange={(e) => setLocalUy3ConsultaFrom(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Consulta até">
                  <Input type="date" value={localUy3ConsultaTo} onChange={(e) => setLocalUy3ConsultaTo(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Meses admissão mín.">
                  <Input value={localUy3MesesMin} onChange={(e) => setLocalUy3MesesMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Meses admissão máx.">
                  <Input value={localUy3MesesMax} onChange={(e) => setLocalUy3MesesMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Margem mín.">
                  <Input value={localUy3MargemMin} onChange={(e) => setLocalUy3MargemMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Margem máx.">
                  <Input value={localUy3MargemMax} onChange={(e) => setLocalUy3MargemMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Valor liberado mín.">
                  <Input value={localUy3ValorMin} onChange={(e) => setLocalUy3ValorMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Valor liberado máx.">
                  <Input value={localUy3ValorMax} onChange={(e) => setLocalUy3ValorMax(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas mín.">
                  <Input value={localUy3ParcelasMin} onChange={(e) => setLocalUy3ParcelasMin(e.target.value)} className={NO_FOCUS} />
                </Field>
                <Field label="Parcelas máx.">
                  <Input value={localUy3ParcelasMax} onChange={(e) => setLocalUy3ParcelasMax(e.target.value)} className={NO_FOCUS} />
                </Field>
              </Section>
            )}
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
