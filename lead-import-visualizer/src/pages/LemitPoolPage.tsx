import { useEffect, useMemo, useRef, useState, type ReactNode } from "react"
import { AlertCircle, Lock, Loader2 } from "lucide-react"
import { toast } from "sonner"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { usePersistedState } from "@/hooks/usePersistedState"
import { cn } from "@/lib/utils"
import {
  createDefaultLemitPoolFilters,
  previewLemitPool,
  type LemitBankKey,
  type LemitCombinationMode,
  type LemitLoanSituation,
  type LemitPoolFiltersDraft,
  type LemitPoolPreviewResponse,
} from "@/api/lemit"

const STORAGE_KEY = "lemit:pool:draft-filters:v1"
const RESULT_STORAGE_KEY = "lemit:pool:result-state:v1"
const RESULT_STORAGE_TTL_MS = 5 * 60 * 1000
const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"
const PRIMARY_BUTTON_CLASS_NAME = "bg-blue-600 text-white hover:bg-blue-700"
const OUTLINE_BUTTON_CLASS_NAME = "border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
const STEP_BADGE_CLASS_NAME = "flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white"
const FILTER_FIELD_CLASS_NAME = "!outline-none focus:!outline-none focus-visible:!outline-none focus:!ring-0 focus:!ring-offset-0 focus-visible:!ring-0 focus-visible:!ring-offset-0 focus:!border-blue-500 focus-visible:!border-blue-500"
const ACTIVE_FILTER_FIELD_CLASS_NAME = "border-blue-500 bg-blue-50/50 text-blue-900"

const BANK_OPTIONS: Array<{
  value: LemitBankKey
  label: string
  imageSrc: string
  alt: string
}> = [
  { value: "facta", label: "Facta CLT", imageSrc: factaLogo, alt: "Facta" },
  { value: "mercantil", label: "CLT Mercantil", imageSrc: mercantilLogo, alt: "Mercantil" },
  { value: "uy3", label: "CLT UY3", imageSrc: uy3Logo, alt: "UY3" },
]

function cloneFilters(filters: LemitPoolFiltersDraft) {
  return JSON.parse(JSON.stringify(filters)) as LemitPoolFiltersDraft
}

function bankLabel(bank: LemitBankKey) {
  return BANK_OPTIONS.find((option) => option.value === bank)?.label ?? bank
}

function combinationLabel(mode: LemitCombinationMode, short = false) {
  if (mode === "all") {
    return short ? "Todos" : "Todos os bancos selecionados"
  }

  return short ? "Qualquer" : "Qualquer banco selecionado"
}

type ErrorWithResponse = {
  message?: string
  response?: {
    data?: {
      message?: string
      errors?: Record<string, string[] | undefined>
    }
  }
}

type PersistedResultState = {
  savedAt: number
  previewResult: LemitPoolPreviewResponse | null
  requestedQuantity: string
  appliedSignature: string | null
  appliedFilters: LemitPoolFiltersDraft | null
}

type AppliedFilterGroup = {
  title: string
  labels: string[]
}

function getErrorMessage(error: unknown, fallback: string) {
  const normalizedError = error as ErrorWithResponse
  const responseMessage = normalizedError.response?.data?.message
  if (typeof responseMessage === "string" && responseMessage.trim()) {
    return responseMessage
  }

  const fieldErrors = normalizedError.response?.data?.errors
  if (fieldErrors && typeof fieldErrors === "object") {
    const firstKey = Object.keys(fieldErrors)[0]
    const firstError = firstKey ? fieldErrors[firstKey]?.[0] : null
    if (typeof firstError === "string" && firstError.trim()) {
      return firstError
    }
  }

  const errorMessage = normalizedError.message
  return typeof errorMessage === "string" && errorMessage.trim() ? errorMessage : fallback
}

function bankFilterIsFilled(filters: LemitPoolFiltersDraft, bank: LemitBankKey) {
  switch (bank) {
    case "facta":
      return Boolean(
        filters.facta.facta_situacao ||
        filters.facta.facta_consulta_from ||
        filters.facta.facta_consulta_to ||
        filters.facta.facta_meses_admissao_min.trim() ||
        filters.facta.facta_meses_admissao_max.trim() ||
        filters.facta.facta_margem_min.trim() ||
        filters.facta.facta_margem_max.trim() ||
        filters.facta.facta_numero_parcelas_min.trim() ||
        filters.facta.facta_numero_parcelas_max.trim()
      )
    case "mercantil":
      return Boolean(
        filters.mercantil.mercantil_situacao ||
        filters.mercantil.mercantil_consulta_from ||
        filters.mercantil.mercantil_consulta_to ||
        filters.mercantil.mercantil_valor_parcela_min.trim() ||
        filters.mercantil.mercantil_valor_parcela_max.trim() ||
        filters.mercantil.mercantil_numero_parcelas_min.trim() ||
        filters.mercantil.mercantil_numero_parcelas_max.trim()
      )
    case "uy3":
      return Boolean(
        filters.uy3.uy3_situacao ||
        filters.uy3.uy3_consulta_from ||
        filters.uy3.uy3_consulta_to ||
        filters.uy3.uy3_meses_admissao_min.trim() ||
        filters.uy3.uy3_meses_admissao_max.trim() ||
        filters.uy3.uy3_margem_min.trim() ||
        filters.uy3.uy3_margem_max.trim() ||
        filters.uy3.uy3_valor_liberado_min.trim() ||
        filters.uy3.uy3_valor_liberado_max.trim() ||
        filters.uy3.uy3_numero_parcelas_min.trim() ||
        filters.uy3.uy3_numero_parcelas_max.trim()
      )
  }
}

function readPersistedResultState() {
  if (typeof window === "undefined") {
    return null
  }

  const raw = window.localStorage.getItem(RESULT_STORAGE_KEY)
  if (!raw) {
    return null
  }

  try {
    const parsed = JSON.parse(raw) as PersistedResultState
    if (!parsed?.savedAt || Date.now() - parsed.savedAt > RESULT_STORAGE_TTL_MS) {
      window.localStorage.removeItem(RESULT_STORAGE_KEY)
      return null
    }

    return parsed
  } catch {
    window.localStorage.removeItem(RESULT_STORAGE_KEY)
    return null
  }
}

function clearPersistedResultState() {
  if (typeof window !== "undefined") {
    window.localStorage.removeItem(RESULT_STORAGE_KEY)
  }
}

function formatDateLabel(value: string) {
  if (!value) {
    return ""
  }

  const [year, month, day] = value.split("-")
  if (!year || !month || !day) {
    return value
  }

  return `${day}/${month}/${year}`
}

function filterFieldClassName(active: boolean) {
  return cn(FILTER_FIELD_CLASS_NAME, active && ACTIVE_FILTER_FIELD_CLASS_NAME)
}

function buildAppliedFilterGroups(filters: LemitPoolFiltersDraft): AppliedFilterGroup[] {
  const general: string[] = []
  const facta: string[] = []
  const mercantil: string[] = []
  const uy3: string[] = []

  if (filters.with_phones) {
    general.push("Telefone: com telefone")
  } else if (filters.without_phones) {
    general.push("Telefone: sem telefone")
  }

  if (filters.selected_banks.length) {
    general.push(`Bancos: ${filters.selected_banks.map(bankLabel).join(", ")}`)
    general.push(`Combinação: ${combinationLabel(filters.bank_combination_mode)}`)
  }

  if (filters.facta.facta_situacao) facta.push(`Situação: ${filters.facta.facta_situacao === "aprovado" ? "Aprovado" : "Não aprovado"}`)
  if (filters.facta.facta_consulta_from) facta.push(`Consulta de: ${formatDateLabel(filters.facta.facta_consulta_from)}`)
  if (filters.facta.facta_consulta_to) facta.push(`Consulta até: ${formatDateLabel(filters.facta.facta_consulta_to)}`)
  if (filters.facta.facta_meses_admissao_min.trim()) facta.push(`Meses admissão mín.: ${filters.facta.facta_meses_admissao_min.trim()}`)
  if (filters.facta.facta_meses_admissao_max.trim()) facta.push(`Meses admissão máx.: ${filters.facta.facta_meses_admissao_max.trim()}`)
  if (filters.facta.facta_margem_min.trim()) facta.push(`Margem mín.: ${filters.facta.facta_margem_min.trim()}`)
  if (filters.facta.facta_margem_max.trim()) facta.push(`Margem máx.: ${filters.facta.facta_margem_max.trim()}`)
  if (filters.facta.facta_numero_parcelas_min.trim()) facta.push(`Parcelas mín.: ${filters.facta.facta_numero_parcelas_min.trim()}`)
  if (filters.facta.facta_numero_parcelas_max.trim()) facta.push(`Parcelas máx.: ${filters.facta.facta_numero_parcelas_max.trim()}`)

  if (filters.mercantil.mercantil_situacao) mercantil.push(`Situação: ${filters.mercantil.mercantil_situacao === "aprovado" ? "Aprovado" : "Não aprovado"}`)
  if (filters.mercantil.mercantil_consulta_from) mercantil.push(`Consulta de: ${formatDateLabel(filters.mercantil.mercantil_consulta_from)}`)
  if (filters.mercantil.mercantil_consulta_to) mercantil.push(`Consulta até: ${formatDateLabel(filters.mercantil.mercantil_consulta_to)}`)
  if (filters.mercantil.mercantil_valor_parcela_min.trim()) mercantil.push(`Parcela mín.: ${filters.mercantil.mercantil_valor_parcela_min.trim()}`)
  if (filters.mercantil.mercantil_valor_parcela_max.trim()) mercantil.push(`Parcela máx.: ${filters.mercantil.mercantil_valor_parcela_max.trim()}`)
  if (filters.mercantil.mercantil_numero_parcelas_min.trim()) mercantil.push(`Parcelas mín.: ${filters.mercantil.mercantil_numero_parcelas_min.trim()}`)
  if (filters.mercantil.mercantil_numero_parcelas_max.trim()) mercantil.push(`Parcelas máx.: ${filters.mercantil.mercantil_numero_parcelas_max.trim()}`)

  if (filters.uy3.uy3_situacao) uy3.push(`Situação: ${filters.uy3.uy3_situacao === "aprovado" ? "Aprovado" : "Não aprovado"}`)
  if (filters.uy3.uy3_consulta_from) uy3.push(`Atualização de: ${formatDateLabel(filters.uy3.uy3_consulta_from)}`)
  if (filters.uy3.uy3_consulta_to) uy3.push(`Atualização até: ${formatDateLabel(filters.uy3.uy3_consulta_to)}`)
  if (filters.uy3.uy3_meses_admissao_min.trim()) uy3.push(`Meses admissão mín.: ${filters.uy3.uy3_meses_admissao_min.trim()}`)
  if (filters.uy3.uy3_meses_admissao_max.trim()) uy3.push(`Meses admissão máx.: ${filters.uy3.uy3_meses_admissao_max.trim()}`)
  if (filters.uy3.uy3_margem_min.trim()) uy3.push(`Margem mín.: ${filters.uy3.uy3_margem_min.trim()}`)
  if (filters.uy3.uy3_margem_max.trim()) uy3.push(`Margem máx.: ${filters.uy3.uy3_margem_max.trim()}`)
  if (filters.uy3.uy3_valor_liberado_min.trim()) uy3.push(`Valor liberado mín.: ${filters.uy3.uy3_valor_liberado_min.trim()}`)
  if (filters.uy3.uy3_valor_liberado_max.trim()) uy3.push(`Valor liberado máx.: ${filters.uy3.uy3_valor_liberado_max.trim()}`)
  if (filters.uy3.uy3_numero_parcelas_min.trim()) uy3.push(`Parcelas mín.: ${filters.uy3.uy3_numero_parcelas_min.trim()}`)
  if (filters.uy3.uy3_numero_parcelas_max.trim()) uy3.push(`Parcelas máx.: ${filters.uy3.uy3_numero_parcelas_max.trim()}`)

  return [
    { title: "Gerais", labels: general },
    { title: "Facta CLT", labels: facta },
    { title: "CLT Mercantil", labels: mercantil },
    { title: "CLT UY3", labels: uy3 },
  ].filter((group) => group.labels.length > 0)
}

function Section({
  value,
  title,
  imageSrc,
  imageAlt,
  children,
}: {
  value: string
  title: string
  imageSrc?: string
  imageAlt?: string
  children: ReactNode
}) {
  return (
    <AccordionItem value={value} className="rounded-xl border border-border bg-card px-4 shadow-sm">
      <AccordionTrigger className="hover:no-underline">
        <div className="flex items-center gap-3 text-lg font-semibold text-gray-900">
          {imageSrc ? <img src={imageSrc} alt={imageAlt ?? title} className="h-7 w-7 object-contain" /> : null}
          <span>{title}</span>
        </div>
      </AccordionTrigger>
      <AccordionContent>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4 pb-2 pt-2">{children}</div>
      </AccordionContent>
    </AccordionItem>
  )
}

function Field({
  label,
  children,
}: {
  label: string
  children: ReactNode
}) {
  return (
    <div className="space-y-2 flex flex-col justify-end">
      <div className="text-sm font-medium">{label}</div>
      {children}
    </div>
  )
}

function SummaryCard({
  label,
  value,
}: {
  label: string
  value: string | number
}) {
  return (
    <div className="rounded-lg border p-4">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1 text-2xl font-semibold text-gray-900">{value}</div>
    </div>
  )
}

export default function LemitPoolPage() {
  const stepTwoRef = useRef<HTMLDivElement>(null)
  
  const persistedResultState = readPersistedResultState()
  const [draftFilters, setDraftFilters] = usePersistedState<LemitPoolFiltersDraft>(
    STORAGE_KEY,
    createDefaultLemitPoolFilters()
  )
  const [previewResult, setPreviewResult] = useState<LemitPoolPreviewResponse | null>(persistedResultState?.previewResult ?? null)
  const [requestedQuantity, setRequestedQuantity] = useState(persistedResultState?.requestedQuantity ?? "")
  const [previewLoading, setPreviewLoading] = useState(false)
  const [lotCreationLoading, setLotCreationLoading] = useState(false)
  const [appliedSignature, setAppliedSignature] = useState<string | null>(persistedResultState?.appliedSignature ?? null)
  const [appliedFilters, setAppliedFilters] = useState<LemitPoolFiltersDraft | null>(persistedResultState?.appliedFilters ?? null)
  const [isNewLotOpen, setIsNewLotOpen] = useState(false)

  const draftSignature = useMemo(() => JSON.stringify(draftFilters), [draftFilters])
  const hasUnappliedChanges = appliedSignature !== null && appliedSignature !== draftSignature
  const isResultStepLocked = previewResult === null || hasUnappliedChanges
  const appliedFilterGroups = useMemo(
    () => (appliedFilters ? buildAppliedFilterGroups(appliedFilters) : []),
    [appliedFilters]
  )

  useEffect(() => {
    if (!previewResult && !requestedQuantity && !appliedSignature && !appliedFilters) {
      clearPersistedResultState()
      return
    }

    if (typeof window === "undefined") {
      return
    }

    const payload: PersistedResultState = {
      savedAt: Date.now(),
      previewResult,
      requestedQuantity,
      appliedSignature,
      appliedFilters,
    }

    window.localStorage.setItem(RESULT_STORAGE_KEY, JSON.stringify(payload))
  }, [appliedFilters, appliedSignature, previewResult, requestedQuantity])

  const validationMessages = useMemo(
    () => draftFilters.selected_banks
      .filter((bank) => !bankFilterIsFilled(draftFilters, bank))
      .map((bank) => `Preencha ao menos um filtro no bloco ${bankLabel(bank)}.`),
    [draftFilters]
  )

  const parsedQuantity = Number(requestedQuantity)
  const quantityError = useMemo(() => {
    if (!previewResult) {
      return "Atualize o resultado antes de criar o lote."
    }
    if (!requestedQuantity.trim()) {
      return "Informe quantos CPFs deseja incluir."
    }
    if (!Number.isInteger(parsedQuantity) || parsedQuantity <= 0) {
      return "Informe um número inteiro maior que zero."
    }
    if (parsedQuantity > previewResult.pool_size) {
      return "A quantidade não pode ser maior que a base filtrada."
    }
    if (hasUnappliedChanges) {
      return "Atualize o resultado antes de criar o lote."
    }
    return null
  }, [hasUnappliedChanges, parsedQuantity, previewResult, requestedQuantity])
  const canCreateLot = !isResultStepLocked && !quantityError && previewResult !== null

  const updateFilters = (updater: (current: LemitPoolFiltersDraft) => LemitPoolFiltersDraft) => {
    setDraftFilters((current) => updater(cloneFilters(current)))
  }

  const handleToggleBank = (bank: LemitBankKey, checked: boolean) => {
    updateFilters((current) => ({
      ...current,
      selected_banks: checked
        ? Array.from(new Set([...current.selected_banks, bank]))
        : current.selected_banks.filter((currentBank) => currentBank !== bank),
    }))
  }

  const handleApplyFilters = async () => {
    if (validationMessages.length > 0 || previewLoading) {
      return
    }

    setPreviewLoading(true)
    try {
      const data = await previewLemitPool(draftFilters)
      setPreviewResult(data)
      setAppliedSignature(draftSignature)
      setAppliedFilters(cloneFilters(draftFilters))
      toast.success("Resultado atualizado.")
      
      setTimeout(() => {
        stepTwoRef.current?.scrollIntoView({ behavior: "smooth", block: "start" })
      }, 100)
    } catch (error) {
      toast.error(getErrorMessage(error, "Não foi possível atualizar o resultado."))
    } finally {
      setPreviewLoading(false)
    }
  }

  const handleCreateLot = async () => {
    if (!canCreateLot) return
    setLotCreationLoading(true)
    
    // Simulação da chamada de criação de lote. 
    // Substituir pela integração real quando a API estiver pronta.
    setTimeout(() => {
      setLotCreationLoading(false)
      toast.success(`Lote de ${requestedQuantity} CPFs criado com sucesso!`)
      handleCancelLot()
    }, 1500)
  }

  const handleClearFilters = () => {
    setDraftFilters(createDefaultLemitPoolFilters())
    setPreviewResult(null)
    setRequestedQuantity("")
    setAppliedSignature(null)
    setAppliedFilters(null)
    clearPersistedResultState()
  }

  const handleCancelLot = () => {
    handleClearFilters()
    setIsNewLotOpen(false)
  }

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          Higienização Lemit
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Monte a base, confira o resultado filtrado e prepare o lote.
        </p>
      </div>

      <div className="space-y-6">
        {!isNewLotOpen ? (
          <div className="flex flex-wrap gap-3">
            <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={() => setIsNewLotOpen(true)}>
              Iniciar lote
            </Button>
          </div>
        ) : null}

        {isNewLotOpen ? (
          <>
            <Card className="shadow-sm relative">
              <CardHeader>
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div>
                    <CardTitle className="text-lg">Iniciar lote</CardTitle>
                    <CardDescription>
                      Ajuste os critérios, confira o total encontrado e defina quantos CPFs deseja incluir.
                    </CardDescription>
                  </div>
                  <Badge variant="outline">{draftFilters.selected_banks.length} banco(s)</Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="grid gap-3 lg:grid-cols-2">
                  <div className="rounded-lg border bg-blue-50 p-4">
                    <div className="flex items-center gap-3">
                      <div className={STEP_BADGE_CLASS_NAME}>1</div>
                      <div>
                        <div className="text-sm font-semibold text-gray-900">Defina os filtros</div>
                        <div className="text-xs text-gray-600">Escolha quem entra na base.</div>
                      </div>
                    </div>
                  </div>
                  <div
                    className={cn(
                      "rounded-lg border p-4 transition-opacity",
                      isResultStepLocked ? "bg-slate-100 opacity-60" : "bg-slate-50",
                    )}
                  >
                    <div className="flex items-center gap-3">
                      <div className={STEP_BADGE_CLASS_NAME}>
                        {isResultStepLocked ? <Lock className="h-3.5 w-3.5" /> : "2"}
                      </div>
                      <div>
                        <div className="text-sm font-semibold text-gray-900">Resultado e lote</div>
                        <div className="text-xs text-gray-600">
                          {isResultStepLocked ? "Atualize o resultado para liberar esta etapa." : "Revise o total e finalize o envio."}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div className="space-y-3 rounded-lg border bg-muted/20 p-4">
                  <div className="text-sm font-medium">Status de telefone</div>
                  <Select
                    value={
                      draftFilters.with_phones
                        ? "with_phones"
                        : draftFilters.without_phones
                          ? "without_phones"
                          : "all"
                    }
                    onValueChange={(value) => {
                      updateFilters((current) => ({
                        ...current,
                        with_phones: value === "with_phones",
                        without_phones: value === "without_phones",
                      }))
                    }}
                  >
                    <SelectTrigger className={filterFieldClassName(draftFilters.with_phones || draftFilters.without_phones)}>
                      <SelectValue placeholder="Selecione" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="with_phones">Com telefone</SelectItem>
                      <SelectItem value="without_phones">Sem telefone</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-4">
                <div>
                  <div className="text-sm font-medium text-gray-900">Bancos da consulta</div>
                  <div className="mt-1 text-sm text-muted-foreground">
                    Você pode combinar múltiplos bancos na mesma base filtrada.
                  </div>
                </div>

                <div className="space-y-4">
                  <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {BANK_OPTIONS.map((bank) => (
                      <label
                        key={bank.value}
                        className={cn(
                          "flex items-center gap-3 rounded-lg border bg-background p-3 text-sm transition-colors cursor-pointer",
                          draftFilters.selected_banks.includes(bank.value) && "border-blue-500 bg-blue-50/50 text-blue-900"
                        )}
                      >
                        <Checkbox
                          className={CHECKBOX_CLASS_NAME}
                          checked={draftFilters.selected_banks.includes(bank.value)}
                          onCheckedChange={(checked) => handleToggleBank(bank.value, Boolean(checked))}
                        />
                        <img src={bank.imageSrc} alt={bank.alt} className="h-6 w-6 object-contain" />
                        <span className="font-medium">{bank.label}</span>
                      </label>
                    ))}
                  </div>

                  <div className="rounded-lg border bg-muted/20 p-4">
                    <div className="mb-2 text-sm font-medium text-gray-900">Modo de combinação</div>
                    <div className="mb-3 text-sm text-muted-foreground">
                      Defina se o lead precisa atender todos os bancos marcados ou apenas um deles.
                    </div>
                    <Select
                      value={draftFilters.bank_combination_mode}
                      onValueChange={(value) => {
                        updateFilters((current) => ({
                          ...current,
                          bank_combination_mode: value as LemitCombinationMode,
                        }))
                      }}
                    >
                      <SelectTrigger className={filterFieldClassName(draftFilters.bank_combination_mode !== "any")}>
                        <SelectValue placeholder="Selecione" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="any">Qualquer banco selecionado</SelectItem>
                        <SelectItem value="all">Todos os bancos selecionados</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </div>

              {draftFilters.selected_banks.length > 0 && (
                <Accordion type="multiple" defaultValue={["facta", "mercantil", "uy3"]} className="space-y-4 mt-6">
                  {draftFilters.selected_banks.includes("facta") ? (
                    <Section value="facta" title="Filtros Facta CLT" imageSrc={factaLogo} imageAlt="Facta">
                      <Field label="Situação">
                        <Select
                          value={draftFilters.facta.facta_situacao || "__empty__"}
                          onValueChange={(value) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: {
                                ...current.facta,
                                facta_situacao: value === "__empty__" ? "" : value as LemitLoanSituation,
                              },
                            }))
                          }}
                        >
                          <SelectTrigger className={filterFieldClassName(Boolean(draftFilters.facta.facta_situacao))}>
                            <SelectValue placeholder="Ex.: Aprovado" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="__empty__">Todas</SelectItem>
                            <SelectItem value="aprovado">Aprovado</SelectItem>
                            <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                          </SelectContent>
                        </Select>
                      </Field>
                      <Field label="Consulta de">
                        <Input
                          type="date"
                          value={draftFilters.facta.facta_consulta_from}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_consulta_from))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_consulta_from: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Consulta até">
                        <Input
                          type="date"
                          value={draftFilters.facta.facta_consulta_to}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_consulta_to))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_consulta_to: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Meses admissão mín.">
                        <Input
                          type="number"
                          value={draftFilters.facta.facta_meses_admissao_min}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_meses_admissao_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_meses_admissao_min: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 1"
                        />
                      </Field>
                      <Field label="Meses admissão máx.">
                        <Input
                          type="number"
                          value={draftFilters.facta.facta_meses_admissao_max}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_meses_admissao_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_meses_admissao_max: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 240"
                        />
                      </Field>
                      <Field label="Margem mínima">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.facta.facta_margem_min}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_margem_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_margem_min: event.target.value },
                            }))
                          }}
                          placeholder="R$ 0,00"
                        />
                      </Field>
                      <Field label="Margem máxima">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.facta.facta_margem_max}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_margem_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_margem_max: event.target.value },
                            }))
                          }}
                          placeholder="R$ 2000,00"
                        />
                      </Field>
                      <Field label="Qtd. parcelas mín.">
                        <Input
                          type="number"
                          value={draftFilters.facta.facta_numero_parcelas_min}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_numero_parcelas_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_numero_parcelas_min: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 1"
                        />
                      </Field>
                      <Field label="Qtd. parcelas máx.">
                        <Input
                          type="number"
                          value={draftFilters.facta.facta_numero_parcelas_max}
                          className={filterFieldClassName(Boolean(draftFilters.facta.facta_numero_parcelas_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              facta: { ...current.facta, facta_numero_parcelas_max: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 120"
                        />
                      </Field>
                    </Section>
                  ) : null}

                  {draftFilters.selected_banks.includes("mercantil") ? (
                    <Section value="mercantil" title="Filtros CLT Mercantil" imageSrc={mercantilLogo} imageAlt="Mercantil">
                      <Field label="Situação">
                        <Select
                          value={draftFilters.mercantil.mercantil_situacao || "__empty__"}
                          onValueChange={(value) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: {
                                ...current.mercantil,
                                mercantil_situacao: value === "__empty__" ? "" : value as LemitLoanSituation,
                              },
                            }))
                          }}
                        >
                          <SelectTrigger className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_situacao))}>
                            <SelectValue placeholder="Ex.: Aprovado" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="__empty__">Todas</SelectItem>
                            <SelectItem value="aprovado">Aprovado</SelectItem>
                            <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                          </SelectContent>
                        </Select>
                      </Field>
                      <Field label="Consulta de">
                        <Input
                          type="date"
                          value={draftFilters.mercantil.mercantil_consulta_from}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_consulta_from))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_consulta_from: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Consulta até">
                        <Input
                          type="date"
                          value={draftFilters.mercantil.mercantil_consulta_to}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_consulta_to))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_consulta_to: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Valor parcela mín.">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.mercantil.mercantil_valor_parcela_min}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_valor_parcela_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_valor_parcela_min: event.target.value },
                            }))
                          }}
                          placeholder="R$ 0,00"
                        />
                      </Field>
                      <Field label="Valor parcela máx.">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.mercantil.mercantil_valor_parcela_max}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_valor_parcela_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_valor_parcela_max: event.target.value },
                            }))
                          }}
                          placeholder="R$ 1000,00"
                        />
                      </Field>
                      <Field label="Qtd. parcelas mín.">
                        <Input
                          type="number"
                          value={draftFilters.mercantil.mercantil_numero_parcelas_min}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_numero_parcelas_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_numero_parcelas_min: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 1"
                        />
                      </Field>
                      <Field label="Qtd. parcelas máx.">
                        <Input
                          type="number"
                          value={draftFilters.mercantil.mercantil_numero_parcelas_max}
                          className={filterFieldClassName(Boolean(draftFilters.mercantil.mercantil_numero_parcelas_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              mercantil: { ...current.mercantil, mercantil_numero_parcelas_max: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 120"
                        />
                      </Field>
                    </Section>
                  ) : null}

                  {draftFilters.selected_banks.includes("uy3") ? (
                    <Section value="uy3" title="Filtros CLT UY3" imageSrc={uy3Logo} imageAlt="UY3">
                      <Field label="Situação">
                        <Select
                          value={draftFilters.uy3.uy3_situacao || "__empty__"}
                          onValueChange={(value) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: {
                                ...current.uy3,
                                uy3_situacao: value === "__empty__" ? "" : value as LemitLoanSituation,
                              },
                            }))
                          }}
                        >
                          <SelectTrigger className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_situacao))}>
                            <SelectValue placeholder="Ex.: Aprovado" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="__empty__">Todos</SelectItem>
                            <SelectItem value="aprovado">Aprovado</SelectItem>
                            <SelectItem value="nao_aprovado">Não aprovado</SelectItem>
                          </SelectContent>
                        </Select>
                      </Field>
                      <Field label="Atualização de">
                        <Input
                          type="date"
                          value={draftFilters.uy3.uy3_consulta_from}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_consulta_from))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_consulta_from: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Atualização até">
                        <Input
                          type="date"
                          value={draftFilters.uy3.uy3_consulta_to}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_consulta_to))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_consulta_to: event.target.value },
                            }))
                          }}
                        />
                      </Field>
                      <Field label="Meses admissão mín.">
                        <Input
                          type="number"
                          value={draftFilters.uy3.uy3_meses_admissao_min}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_meses_admissao_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_meses_admissao_min: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 1"
                        />
                      </Field>
                      <Field label="Meses admissão máx.">
                        <Input
                          type="number"
                          value={draftFilters.uy3.uy3_meses_admissao_max}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_meses_admissao_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_meses_admissao_max: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 240"
                        />
                      </Field>
                      <Field label="Margem mínima">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.uy3.uy3_margem_min}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_margem_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_margem_min: event.target.value },
                            }))
                          }}
                          placeholder="R$ 0,00"
                        />
                      </Field>
                      <Field label="Margem máxima">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.uy3.uy3_margem_max}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_margem_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_margem_max: event.target.value },
                            }))
                          }}
                          placeholder="R$ 2000,00"
                        />
                      </Field>
                      <Field label="Valor liberado mín.">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.uy3.uy3_valor_liberado_min}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_valor_liberado_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_valor_liberado_min: event.target.value },
                            }))
                          }}
                          placeholder="R$ 0,00"
                        />
                      </Field>
                      <Field label="Valor liberado máx.">
                        <Input
                          inputMode="decimal"
                          value={draftFilters.uy3.uy3_valor_liberado_max}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_valor_liberado_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_valor_liberado_max: event.target.value },
                            }))
                          }}
                          placeholder="R$ 10000,00"
                        />
                      </Field>
                      <Field label="Qtd. parcelas mín.">
                        <Input
                          type="number"
                          value={draftFilters.uy3.uy3_numero_parcelas_min}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_numero_parcelas_min.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_numero_parcelas_min: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 1"
                        />
                      </Field>
                      <Field label="Qtd. parcelas máx.">
                        <Input
                          type="number"
                          value={draftFilters.uy3.uy3_numero_parcelas_max}
                          className={filterFieldClassName(Boolean(draftFilters.uy3.uy3_numero_parcelas_max.trim()))}
                          onChange={(event) => {
                            updateFilters((current) => ({
                              ...current,
                              uy3: { ...current.uy3, uy3_numero_parcelas_max: event.target.value },
                            }))
                          }}
                          placeholder="Ex.: 120"
                        />
                      </Field>
                    </Section>
                  ) : null}
                </Accordion>
              )}

              {/* Action Bar - Reorganizada com validações sem sticky */}
              <div className="flex flex-col gap-4 mt-6">
                
                {validationMessages.length > 0 ? (
                  <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    {validationMessages.map((message) => (
                      <div key={message} className="flex items-center gap-2">
                        <AlertCircle className="h-4 w-4" />
                        {message}
                      </div>
                    ))}
                  </div>
                ) : null}

                {hasUnappliedChanges && validationMessages.length === 0 ? (
                  <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                    Existem alterações ainda não aplicadas. Atualize o resultado para liberar a etapa seguinte.
                  </div>
                ) : null}

                <div className="flex flex-wrap gap-3">
                  <Button 
                    className={PRIMARY_BUTTON_CLASS_NAME} 
                    onClick={() => void handleApplyFilters()} 
                    disabled={previewLoading || validationMessages.length > 0}
                  >
                    {previewLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    {previewLoading ? "Atualizando..." : "Atualizar resultado"}
                  </Button>
                  <Button 
                    className={OUTLINE_BUTTON_CLASS_NAME} 
                    variant="outline" 
                    onClick={handleClearFilters} 
                    disabled={previewLoading}
                  >
                    Limpar filtros
                  </Button>
                </div>
              </div>

            </CardContent>
          </Card>

           {/* ETAPA 2: RESULTADO - UI/UX REFINADA */}
            <Card className="shadow-sm border-blue-100" ref={stepTwoRef}>
              <CardHeader className="border-b bg-slate-50/50">
                <div className="flex items-start gap-3">
                  <div className={STEP_BADGE_CLASS_NAME}>
                    {isResultStepLocked ? <Lock className="h-3.5 w-3.5" /> : "2"}
                  </div>
                  <div>
                    <CardTitle className="text-lg">Resultado e Lote</CardTitle>
                    <CardDescription>
                      Confira a base filtrada e defina a quantidade para o lote.
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              
              <CardContent className="pt-6 space-y-8">
                {isResultStepLocked ? (
                  <div className="rounded-lg border border-slate-200 bg-slate-100 p-4 text-sm text-slate-600 flex items-center gap-2">
                    <Lock className="h-4 w-4" />
                    {previewResult && hasUnappliedChanges
                      ? "Há mudanças pendentes. Atualize o resultado antes de preparar o lote."
                      : "Aplique os filtros da etapa 1 para liberar o resultado."}
                  </div>
                ) : (
                  <div className="grid gap-8 xl:grid-cols-[1fr_auto]">
                    
                    {/* COLUNA ESQUERDA: DADOS */}
                    <div className="space-y-6">
                      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-blue-600 text-white rounded-lg p-4 shadow-sm">
                          <div className="text-blue-100 text-xs font-medium uppercase">Total Encontrado</div>
                          <div className="text-3xl font-bold mt-1">{previewResult?.pool_size}</div>
                        </div>
                        <SummaryCard label="Com telefone" value={previewResult?.pool_with_phones ?? 0} />
                        <SummaryCard label="Sem telefone" value={previewResult?.pool_without_phones ?? 0} />
                        <SummaryCard label="Combinação" value={combinationLabel(draftFilters.bank_combination_mode, true)} />
                      </div>

                      <div className="rounded-lg border bg-slate-50/50 p-4">
                        <h4 className="text-sm font-semibold text-slate-900 mb-3">Critérios aplicados</h4>
                        <div className="flex flex-wrap gap-2">
                          {appliedFilterGroups.map((group) => (
                            <div key={group.title} className="flex flex-wrap gap-2">
                              {group.labels.map((label) => (
                                <Badge key={label} variant="secondary" className="font-normal bg-white border-slate-200">
                                  <span className="text-slate-500 mr-1 italic">{group.title}:</span>
                                  {label.replace(`${group.title}: `, '')}
                                </Badge>
                              ))}
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>

                    {/* COLUNA DIREITA: AÇÃO DE LOTE */}
                    <div className="bg-slate-50 rounded-xl p-6 border border-slate-200 w-full xl:w-80 flex flex-col justify-center">
                      <h4 className="text-sm font-bold text-slate-900 mb-4">Finalizar Lote</h4>
                      <div className="space-y-4">
                        <Field label="Quantidade de CPFs">
                          <div className="flex gap-2">
                            <Input
                              type="number"
                              min={1}
                              max={previewResult?.pool_size || 1}
                              value={requestedQuantity}
                              disabled={lotCreationLoading}
                              onChange={(event) => setRequestedQuantity(event.target.value)}
                              placeholder="Ex: 500"
                            />
                            <Button 
                              type="button" 
                              variant="outline" 
                              onClick={() => setRequestedQuantity(previewResult?.pool_size.toString() ?? "")}
                            >
                              Max
                            </Button>
                          </div>
                        </Field>
                        
                        {quantityError && (
                          <p className="text-xs text-amber-600 bg-amber-50 p-2 rounded">{quantityError}</p>
                        )}

                        <Button
                          className={cn("w-full", PRIMARY_BUTTON_CLASS_NAME)}
                          disabled={!canCreateLot || lotCreationLoading}
                          onClick={handleCreateLot}
                        >
                          {lotCreationLoading ? (
                            <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Criando...</>
                          ) : "Criar lote agora"}
                        </Button>
                      </div>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>

            <div className="flex justify-end gap-3">
              <Button variant="ghost" onClick={handleCancelLot}>
                Fechar / Limpar
              </Button>
            </div>

            <div className="flex flex-wrap justify-end gap-3">
              <Button
                className={OUTLINE_BUTTON_CLASS_NAME}
                variant="outline"
                onClick={handleCancelLot}
                disabled={previewLoading || lotCreationLoading}
              >
                Cancelar
              </Button>
              <Button
                className={PRIMARY_BUTTON_CLASS_NAME}
                disabled={!canCreateLot || previewLoading || lotCreationLoading}
                onClick={handleCreateLot}
              >
                {lotCreationLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {lotCreationLoading ? "Criando lote..." : "Criar lote"}
              </Button>
            </div>
          </>
        ) : null}
      </div>

      <Card className="mt-6 border-amber-200 bg-amber-50/80 shadow-sm">
        <CardContent className="flex items-start gap-3 p-4 text-sm text-amber-900">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />  
          <div>
            O histórico persistido e a execução do job externo ainda não entram nesta entrega. Nesta fase, a página cobre filtros reais e preview da base.
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
