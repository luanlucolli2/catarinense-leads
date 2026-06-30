import { useMemo, useState, type ReactNode } from "react"
import { AlertCircle } from "lucide-react"
import { toast } from "sonner"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { usePersistedState } from "@/hooks/usePersistedState"
import { cn } from "@/lib/utils"
import { formatCPF, formatPhone } from "@/lib/formatters"
import {
  createDefaultLemitPoolFilters,
  previewLemitPool,
  sampleLemitPool,
  type LemitBankKey,
  type LemitCombinationMode,
  type LemitLoanSituation,
  type LemitPoolFiltersDraft,
  type LemitPoolPreviewResponse,
  type LemitPoolSampleResponse,
} from "@/api/lemit"

const STORAGE_KEY = "lemit:pool:draft-filters:v1"
const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"
const PRIMARY_BUTTON_CLASS_NAME = "bg-blue-600 text-white hover:bg-blue-700"
const OUTLINE_BUTTON_CLASS_NAME = "border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
const STEP_BADGE_CLASS_NAME = "flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white"

const BANK_OPTIONS: Array<{
  value: LemitBankKey
  label: string
  imageSrc: string
  alt: string
}> = [
  { value: "clt", label: "CLT Facta", imageSrc: factaLogo, alt: "Facta" },
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

function escapeCsv(value: unknown) {
  const stringValue = value === null || value === undefined ? "" : String(value)
  return `"${stringValue.replace(/"/g, '""')}"`
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
    case "clt":
      return Boolean(
        filters.clt.clt_situacao ||
        filters.clt.clt_consulta_from ||
        filters.clt.clt_consulta_to ||
        filters.clt.clt_meses_admissao_min.trim() ||
        filters.clt.clt_meses_admissao_max.trim() ||
        filters.clt.clt_margem_min.trim() ||
        filters.clt.clt_margem_max.trim() ||
        filters.clt.clt_numero_parcelas_min.trim() ||
        filters.clt.clt_numero_parcelas_max.trim()
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

function downloadSampleCsv(sample: LemitPoolSampleResponse) {
  const banksUsed = sample.selected_banks.join(",")
  const combination = sample.bank_combination_mode

  const rows = [
    ["cpf", "nome", "telefone_atual_antes", "bancos_usados", "modo_combinacao"],
    ...sample.items.map((item) => [
      item.cpf,
      item.nome ?? "",
      item.telefone_atual_antes ?? "",
      banksUsed,
      combination,
    ]),
  ]

  const csv = rows.map((row) => row.map((value) => escapeCsv(value)).join(",")).join("\n")
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement("a")

  link.href = url
  link.download = `lemit-amostra-${Date.now()}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}

function Section({
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  return (
    <div className="rounded-xl border p-4">
      <div className="mb-4 text-lg font-semibold text-gray-900">{title}</div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">{children}</div>
    </div>
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
    <div className="space-y-2">
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
  const [draftFilters, setDraftFilters] = usePersistedState<LemitPoolFiltersDraft>(
    STORAGE_KEY,
    createDefaultLemitPoolFilters()
  )
  const [previewResult, setPreviewResult] = useState<LemitPoolPreviewResponse | null>(null)
  const [lastSample, setLastSample] = useState<LemitPoolSampleResponse | null>(null)
  const [requestedQuantity, setRequestedQuantity] = useState("")
  const [previewLoading, setPreviewLoading] = useState(false)
  const [sampleLoading, setSampleLoading] = useState(false)
  const [confirmSampleOpen, setConfirmSampleOpen] = useState(false)
  const [appliedSignature, setAppliedSignature] = useState<string | null>(null)

  const draftSignature = useMemo(() => JSON.stringify(draftFilters), [draftFilters])
  const hasUnappliedChanges = appliedSignature !== null && appliedSignature !== draftSignature
  const isResultStepLocked = previewResult === null || hasUnappliedChanges

  const validationMessages = useMemo(
    () => draftFilters.selected_banks
      .filter((bank) => !bankFilterIsFilled(draftFilters, bank))
      .map((bank) => `Preencha ao menos um filtro no bloco ${bankLabel(bank)}.`),
    [draftFilters]
  )

  const parsedQuantity = Number(requestedQuantity)
  const quantityError = useMemo(() => {
    if (!previewResult) {
      return "Atualize o resultado antes de sortear a amostra."
    }
    if (!requestedQuantity.trim()) {
      return "Informe quantos CPFs deseja sortear."
    }
    if (!Number.isInteger(parsedQuantity) || parsedQuantity <= 0) {
      return "Informe um número inteiro maior que zero."
    }
    if (parsedQuantity > previewResult.pool_size) {
      return "A quantidade não pode ser maior que a base filtrada."
    }
    if (hasUnappliedChanges) {
      return "Atualize o resultado antes de sortear a amostra."
    }
    return null
  }, [hasUnappliedChanges, parsedQuantity, previewResult, requestedQuantity])

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
      setLastSample(null)
      toast.success("Resultado atualizado.")
    } catch (error) {
      toast.error(getErrorMessage(error, "Não foi possível atualizar o resultado."))
    } finally {
      setPreviewLoading(false)
    }
  }

  const handleClearFilters = () => {
    setDraftFilters(createDefaultLemitPoolFilters())
    setPreviewResult(null)
    setLastSample(null)
    setRequestedQuantity("")
    setAppliedSignature(null)
  }

  const handleSample = async () => {
    if (quantityError || sampleLoading) {
      return
    }

    setSampleLoading(true)
    try {
      const data = await sampleLemitPool(draftFilters, parsedQuantity)
      setLastSample(data)
      setConfirmSampleOpen(false)
      toast.success("Amostra gerada com sucesso.")
    } catch (error) {
      toast.error(getErrorMessage(error, "Não foi possível gerar a amostra."))
    } finally {
      setSampleLoading(false)
    }
  }

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          Higienização Lemit
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Monte a base, confira o resultado filtrado e gere uma amostra aleatória real.
        </p>
      </div>

      <Card className="shadow-sm">
        <CardHeader>
          <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
              <CardTitle className="text-lg">Filtros, resultado e amostra</CardTitle>
              <CardDescription>
                A persistência de histórico e o job externo entram na próxima fase.
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
                <div className={STEP_BADGE_CLASS_NAME}>2</div>
                <div>
                  <div className="text-sm font-semibold text-gray-900">Resultado e amostra</div>
                  <div className="text-xs text-gray-600">
                    {isResultStepLocked ? "Atualize o resultado para liberar esta etapa." : "Revise o total e sorteie a amostra."}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <Card className="shadow-sm">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className={STEP_BADGE_CLASS_NAME}>1</div>
                <div>
                  <CardTitle className="text-lg">Defina os filtros</CardTitle>
                  <CardDescription>
                    Monte a base que poderá entrar na amostra.
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-6">
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
                    <SelectTrigger className="bg-white">
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
                        className="flex items-center gap-3 rounded-lg border bg-background p-3 text-sm"
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
                      <SelectTrigger className="bg-white">
                        <SelectValue placeholder="Selecione" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todos os bancos selecionados</SelectItem>
                        <SelectItem value="any">Qualquer banco selecionado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </div>

              {draftFilters.selected_banks.includes("clt") ? (
                <Section title="Filtros CLT Facta">
                  <Field label="Situação">
                    <Select
                      value={draftFilters.clt.clt_situacao || "__empty__"}
                      onValueChange={(value) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: {
                            ...current.clt,
                            clt_situacao: value === "__empty__" ? "" : value as LemitLoanSituation,
                          },
                        }))
                      }}
                    >
                      <SelectTrigger>
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
                      value={draftFilters.clt.clt_consulta_from}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_consulta_from: event.target.value },
                        }))
                      }}
                    />
                  </Field>
                  <Field label="Consulta até">
                    <Input
                      type="date"
                      value={draftFilters.clt.clt_consulta_to}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_consulta_to: event.target.value },
                        }))
                      }}
                    />
                  </Field>
                  <Field label="Meses admissão mín.">
                    <Input
                      value={draftFilters.clt.clt_meses_admissao_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_meses_admissao_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 1"
                    />
                  </Field>
                  <Field label="Meses admissão máx.">
                    <Input
                      value={draftFilters.clt.clt_meses_admissao_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_meses_admissao_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 240"
                    />
                  </Field>
                  <Field label="Margem mínima">
                    <Input
                      value={draftFilters.clt.clt_margem_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_margem_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 0,00"
                    />
                  </Field>
                  <Field label="Margem máxima">
                    <Input
                      value={draftFilters.clt.clt_margem_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_margem_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 2000,00"
                    />
                  </Field>
                  <Field label="Qtd. parcelas mín.">
                    <Input
                      value={draftFilters.clt.clt_numero_parcelas_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_numero_parcelas_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 1"
                    />
                  </Field>
                  <Field label="Qtd. parcelas máx.">
                    <Input
                      value={draftFilters.clt.clt_numero_parcelas_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          clt: { ...current.clt, clt_numero_parcelas_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 120"
                    />
                  </Field>
                </Section>
              ) : null}

              {draftFilters.selected_banks.includes("mercantil") ? (
                <Section title="Filtros CLT Mercantil">
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
                      <SelectTrigger>
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
                      value={draftFilters.mercantil.mercantil_valor_parcela_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          mercantil: { ...current.mercantil, mercantil_valor_parcela_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 0,00"
                    />
                  </Field>
                  <Field label="Valor parcela máx.">
                    <Input
                      value={draftFilters.mercantil.mercantil_valor_parcela_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          mercantil: { ...current.mercantil, mercantil_valor_parcela_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 1000,00"
                    />
                  </Field>
                  <Field label="Qtd. parcelas mín.">
                    <Input
                      value={draftFilters.mercantil.mercantil_numero_parcelas_min}
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
                      value={draftFilters.mercantil.mercantil_numero_parcelas_max}
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
                <Section title="Filtros CLT UY3">
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
                      <SelectTrigger>
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
                      value={draftFilters.uy3.uy3_meses_admissao_min}
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
                      value={draftFilters.uy3.uy3_meses_admissao_max}
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
                      value={draftFilters.uy3.uy3_margem_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          uy3: { ...current.uy3, uy3_margem_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 0,00"
                    />
                  </Field>
                  <Field label="Margem máxima">
                    <Input
                      value={draftFilters.uy3.uy3_margem_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          uy3: { ...current.uy3, uy3_margem_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 2000,00"
                    />
                  </Field>
                  <Field label="Valor liberado mín.">
                    <Input
                      value={draftFilters.uy3.uy3_valor_liberado_min}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          uy3: { ...current.uy3, uy3_valor_liberado_min: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 0,00"
                    />
                  </Field>
                  <Field label="Valor liberado máx.">
                    <Input
                      value={draftFilters.uy3.uy3_valor_liberado_max}
                      onChange={(event) => {
                        updateFilters((current) => ({
                          ...current,
                          uy3: { ...current.uy3, uy3_valor_liberado_max: event.target.value },
                        }))
                      }}
                      placeholder="Ex.: 10000,00"
                    />
                  </Field>
                  <Field label="Qtd. parcelas mín.">
                    <Input
                      value={draftFilters.uy3.uy3_numero_parcelas_min}
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
                      value={draftFilters.uy3.uy3_numero_parcelas_max}
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

              {validationMessages.length ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                  {validationMessages.map((message) => (
                    <div key={message}>{message}</div>
                  ))}
                </div>
              ) : null}

              {hasUnappliedChanges ? (
                <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                  Existem alterações ainda não aplicadas. Clique em Atualizar resultado para liberar a etapa seguinte.
                </div>
              ) : null}

              <div className="flex flex-wrap gap-3">
                <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={() => void handleApplyFilters()} disabled={previewLoading || validationMessages.length > 0}>
                  {previewLoading ? "Atualizando..." : "Atualizar resultado"}
                </Button>
                <Button className={OUTLINE_BUTTON_CLASS_NAME} variant="outline" onClick={handleClearFilters} disabled={previewLoading || sampleLoading}>
                  Limpar filtros
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card className="shadow-sm">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className={STEP_BADGE_CLASS_NAME}>2</div>
                <div>
                  <CardTitle className="text-lg">Resultado e amostra</CardTitle>
                  <CardDescription>
                    Veja o total encontrado e gere a amostra a partir da base atual.
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              {isResultStepLocked ? (
                <div className="rounded-lg border border-slate-200 bg-slate-100 p-4 text-sm text-slate-600">
                  {previewResult && hasUnappliedChanges
                    ? "Há mudanças pendentes. Atualize o resultado antes de sortear a amostra."
                    : "Aplique os filtros da etapa 1 para liberar o resultado e a amostragem."}
                </div>
              ) : null}

              {previewResult ? (
                <div className="grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                  <div className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-2">
                      <SummaryCard label="Total encontrado" value={previewResult.pool_size} />
                      <SummaryCard label="Com telefone" value={previewResult.pool_with_phones} />
                      <SummaryCard label="Sem telefone" value={previewResult.pool_without_phones} />
                      <SummaryCard label="Combinação" value={combinationLabel(draftFilters.bank_combination_mode)} />
                    </div>
                  </div>

                  <div className="rounded-xl border bg-gradient-to-b from-slate-50 to-white p-4 sm:p-5">
                    <div className="mb-4 rounded-lg border bg-white p-4">
                      <div className="text-sm font-semibold text-gray-900">Sortear amostra</div>
                      <div className="mt-1 text-sm text-muted-foreground">
                        Defina quantos CPFs deseja sortear da base filtrada atual.
                      </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                      <Field label="Quantos deseja sortear">
                        <Input
                          type="number"
                          min={1}
                          max={previewResult.pool_size || 1}
                          value={requestedQuantity}
                          disabled={previewLoading || sampleLoading}
                          onChange={(event) => setRequestedQuantity(event.target.value)}
                          placeholder="Ex.: 1500"
                        />
                      </Field>
                      <div className="rounded-lg border bg-white p-4">
                        <div className="text-xs text-muted-foreground">Limite disponível</div>
                        <div className="mt-1 text-lg font-semibold text-gray-900">
                          Até {previewResult.pool_size} CPF(s)
                        </div>
                      </div>
                    </div>

                    <div className="space-y-3 mt-4">
                      <div
                        className={cn(
                          "rounded-lg border p-3 text-sm",
                          quantityError
                            ? "border-amber-200 bg-amber-50 text-amber-800"
                            : "border-slate-200 bg-white text-slate-700",
                        )}
                      >
                        {quantityError ?? `Pronto para sortear até ${previewResult.pool_size} CPF(s) da base filtrada atual.`}
                      </div>
                    </div>

                    <div className="mt-4 flex flex-wrap justify-end gap-3">
                      <Button
                        className={PRIMARY_BUTTON_CLASS_NAME}
                        onClick={() => setConfirmSampleOpen(true)}
                        disabled={Boolean(quantityError) || previewLoading || sampleLoading}
                      >
                        {sampleLoading ? "Sorteando..." : "Sortear amostra"}
                      </Button>
                    </div>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>
        </CardContent>
      </Card>

      {lastSample ? (
        <Card className="shadow-sm mt-6">
          <CardHeader>
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div>
                <CardTitle className="text-lg">Última amostra</CardTitle>
                <CardDescription>
                  {lastSample.sampled_quantity} CPF(s) sorteado(s) de uma base com {lastSample.pool_size} lead(s).
                </CardDescription>
              </div>
              <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={() => downloadSampleCsv(lastSample)}>
                Baixar CSV da amostra
              </Button>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              {lastSample.selected_banks.length ? (
                lastSample.selected_banks.map((bank) => (
                  <Badge key={bank} variant="outline" className="rounded-full px-3 py-1">
                    {bankLabel(bank)}
                  </Badge>
                ))
              ) : (
                <Badge variant="secondary">Somente filtros gerais</Badge>
              )}
              <Badge variant="outline" className="rounded-full px-3 py-1">
                {combinationLabel(lastSample.bank_combination_mode, true)}
              </Badge>
            </div>

            <div className="overflow-x-auto rounded-xl border">
              <table className="min-w-full text-sm">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-semibold text-slate-700">CPF</th>
                    <th className="px-4 py-3 text-left font-semibold text-slate-700">Nome</th>
                    <th className="px-4 py-3 text-left font-semibold text-slate-700">Telefone atual</th>
                  </tr>
                </thead>
                <tbody>
                  {lastSample.items.map((item) => (
                    <tr key={item.lead_id} className="border-t">
                      <td className="px-4 py-3 text-slate-800">{formatCPF(item.cpf)}</td>
                      <td className="px-4 py-3 text-slate-800">{item.nome ?? "--"}</td>
                      <td className="px-4 py-3 text-slate-800">
                        {item.telefone_atual_antes ? formatPhone(item.telefone_atual_antes) : "--"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <Card className="mt-6 border-amber-200 bg-amber-50/80 shadow-sm">
        <CardContent className="flex items-start gap-3 p-4 text-sm text-amber-900">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            O histórico persistido e a execução do job externo ainda não entram nesta entrega. Nesta fase, a página cobre filtros reais, preview da base e amostragem aleatória.
          </div>
        </CardContent>
      </Card>

      <AlertDialog open={confirmSampleOpen} onOpenChange={setConfirmSampleOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirmar amostragem?</AlertDialogTitle>
            <AlertDialogDescription>
              {`Sortear ${parsedQuantity || 0} CPF(s) da base filtrada atual.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Voltar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-blue-600 text-white hover:bg-blue-700"
              onClick={() => void handleSample()}
            >
              Confirmar e sortear
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
