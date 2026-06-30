import { useEffect, useMemo, useState } from "react"
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
import { MultiSelect } from "@/components/ui/multi-select"
import factaLogo from "@/assets/factalogo.png"
import mercantilLogo from "@/assets/mercantilogo.png"
import uy3Logo from "@/assets/logouy3png.png"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { usePersistedState } from "@/hooks/usePersistedState"
import { cn } from "@/lib/utils"
import { LemitPrototypeHistoryTable } from "@/modules/lemit-prototype/LemitPrototypeHistoryTable"
import { downloadPrototypeLotCsv, getPrototypeCombinationLabel } from "@/modules/lemit-prototype/history"
import {
  countPoolWithPhones,
  countPoolWithoutPhones,
  createDefaultLemitPrototypeFilters,
  createMockLeadsDataset,
  createPrototypeLotExecution,
  filterPrototypeLeads,
  finalizePrototypeLots,
  getPrototypeOptionCatalog,
  validatePrototypeBankSelections,
} from "@/modules/lemit-prototype/mock"
import type {
  LemitPrototypeBankKey,
  LemitPrototypeFilters,
  LemitPrototypeLead,
  LemitPrototypeLot,
  LemitPrototypeLoanSituation,
} from "@/modules/lemit-prototype/types"

const DEFAULT_LEADS = createMockLeadsDataset()
const DEFAULT_FILTERS = createDefaultLemitPrototypeFilters()

const BANK_OPTIONS: Array<{ value: LemitPrototypeBankKey; label: string }> = [
  { value: "clt", label: "CLT Facta" },
  { value: "mercantil", label: "CLT Mercantil" },
  { value: "uy3", label: "CLT UY3" },
]

const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"
const PRIMARY_BUTTON_CLASS_NAME = "bg-blue-600 text-white hover:bg-blue-700"
const OUTLINE_BUTTON_CLASS_NAME = "border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"
const STEP_BADGE_CLASS_NAME = "flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white"

function cloneFilters(filters: LemitPrototypeFilters) {
  return JSON.parse(JSON.stringify(filters)) as LemitPrototypeFilters
}

export default function LemitPrototypePage() {
  const [prototypeLeads, setPrototypeLeads] = usePersistedState<LemitPrototypeLead[]>(
    "lemit-prototype:leads:v1",
    DEFAULT_LEADS,
  )
  const [draftFilters, setDraftFilters] = usePersistedState<LemitPrototypeFilters>(
    "lemit-prototype:draft-filters:v2",
    DEFAULT_FILTERS,
  )
  const [appliedFilters, setAppliedFilters] = usePersistedState<LemitPrototypeFilters>(
    "lemit-prototype:applied-filters:v2",
    DEFAULT_FILTERS,
  )
  const [lots, setLots] = usePersistedState<LemitPrototypeLot[]>(
    "lemit-prototype:lots:v1",
    [],
  )
  const [requestedQuantity, setRequestedQuantity] = usePersistedState<string>(
    "lemit-prototype:requested-quantity:v1",
    "",
  )
  const [lotTitle, setLotTitle] = usePersistedState<string>(
    "lemit-prototype:lot-title:v1",
    "",
  )
  const [isNewLotOpen, setIsNewLotOpen] = usePersistedState<boolean>(
    "lemit-prototype:new-lot-open:v1",
    false,
  )
  const [isResultReady, setIsResultReady] = useState(false)
  const [isRunDialogOpen, setIsRunDialogOpen] = useState(false)

  useEffect(() => {
    setLots((currentLots) => (
      currentLots.some((lot) => lot.status === "em_andamento")
        ? finalizePrototypeLots(currentLots)
        : currentLots
    ))
  }, [setLots])

  const optionCatalog = useMemo(
    () => getPrototypeOptionCatalog(prototypeLeads),
    [prototypeLeads],
  )

  const validationMessages = useMemo(
    () => validatePrototypeBankSelections(draftFilters),
    [draftFilters],
  )

  const hasUnappliedChanges = useMemo(
    () => JSON.stringify(draftFilters) !== JSON.stringify(appliedFilters),
    [draftFilters, appliedFilters],
  )

  const filteredPool = useMemo(
    () => filterPrototypeLeads(prototypeLeads, appliedFilters),
    [prototypeLeads, appliedFilters],
  )

  const poolWithPhones = useMemo(
    () => countPoolWithPhones(filteredPool),
    [filteredPool],
  )

  const poolWithoutPhones = useMemo(
    () => countPoolWithoutPhones(filteredPool),
    [filteredPool],
  )

  const parsedQuantity = Number(requestedQuantity)
  const normalizedLotTitle = lotTitle.trim()
  const lotTitleError = useMemo(() => {
    if (!normalizedLotTitle) {
      return "Informe o nome do lote."
    }
    return null
  }, [normalizedLotTitle])
  const quantityError = useMemo(() => {
    if (!requestedQuantity.trim()) {
      return "Informe quantos CPFs deseja rodar."
    }
    if (!Number.isInteger(parsedQuantity) || parsedQuantity <= 0) {
      return "Informe um número inteiro maior que zero."
    }
    if (parsedQuantity > filteredPool.length) {
      return "A quantidade não pode ser maior que a base filtrada."
    }
    if (hasUnappliedChanges) {
      return "Atualize a base filtrada antes de rodar o lote."
    }
    return null
  }, [filteredPool.length, hasUnappliedChanges, parsedQuantity, requestedQuantity])

  const isResultStepLocked = !isResultReady || hasUnappliedChanges
  const canSearchPool = validationMessages.length === 0
  const canRunLot = canSearchPool && !isResultStepLocked && !quantityError && !lotTitleError && filteredPool.length > 0

  const updateGeneralFilter = <K extends keyof LemitPrototypeFilters["general"]>(
    key: K,
    value: LemitPrototypeFilters["general"][K],
  ) => {
    setIsResultReady(false)
    setDraftFilters((current) => ({
      ...current,
      general: {
        ...current.general,
        [key]: value,
      },
    }))
  }

  const updateBankFilter = <
    B extends keyof LemitPrototypeFilters["bank_filters"],
    K extends keyof LemitPrototypeFilters["bank_filters"][B],
  >(
    bank: B,
    key: K,
    value: LemitPrototypeFilters["bank_filters"][B][K],
  ) => {
    setIsResultReady(false)
    setDraftFilters((current) => ({
      ...current,
      bank_filters: {
        ...current.bank_filters,
        [bank]: {
          ...current.bank_filters[bank],
          [key]: value,
        },
      },
    }))
  }

  const toggleBankSelection = (bank: LemitPrototypeBankKey, checked: boolean) => {
    setIsResultReady(false)
    setDraftFilters((current) => ({
      ...current,
      selected_banks: checked
        ? Array.from(new Set([...current.selected_banks, bank]))
        : current.selected_banks.filter((currentBank) => currentBank !== bank),
    }))
  }

  const handleApplyFilters = () => {
    if (!canSearchPool) return
    setAppliedFilters(cloneFilters(draftFilters))
    setIsResultReady(true)
  }

  const handleClearFilters = () => {
    const resetFilters = createDefaultLemitPrototypeFilters()
    setDraftFilters(resetFilters)
    setAppliedFilters(resetFilters)
    setRequestedQuantity("")
    setLotTitle("")
    setIsResultReady(false)
  }

  const handleCancelLot = () => {
    handleClearFilters()
    setIsNewLotOpen(false)
  }

  const handleRunLot = () => {
    if (!canRunLot) return

    const nextLotId = lots.length ? Math.max(...lots.map((lot) => lot.id)) + 1 : 1
    const execution = createPrototypeLotExecution(
      prototypeLeads,
      filteredPool,
      appliedFilters,
      parsedQuantity,
      nextLotId,
      normalizedLotTitle,
    )

    setPrototypeLeads(execution.updatedLeads)
    setLots((currentLots) => [execution.lot, ...currentLots])
    handleClearFilters()
    setIsNewLotOpen(false)
    setIsRunDialogOpen(false)

    window.setTimeout(() => {
      setLots((currentLots) => currentLots.map((lot) => (
        lot.id === execution.lot.id ? { ...lot, status: "concluido" } : lot
      )))
    }, 600)
  }

  const handleDeleteLot = (lotId: number) => {
    setLots((currentLots) => currentLots.filter((lot) => lot.id !== lotId))
  }

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          Higienização Lemit
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Protótipo frontend/local para validar filtros, seleção aleatória e execução de lotes.
        </p>
      </div>

      <div className="space-y-6">
        {!isNewLotOpen ? (
          <div className="flex flex-wrap gap-3">
            <Button
              className={PRIMARY_BUTTON_CLASS_NAME}
              onClick={() => {
                setIsNewLotOpen(true)
              }}
            >
              Iniciar lote
            </Button>
          </div>
        ) : null}

        {isNewLotOpen ? (
          <Card className="shadow-sm">
            <CardHeader>
              <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                  <CardTitle className="text-lg">Iniciar lote</CardTitle>
                  <CardDescription>
                    Ajuste os critérios, confira o total encontrado e defina quantos CPFs deseja rodar.
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
                      <div className="text-sm font-semibold text-gray-900">Resultado e lote</div>
                      <div className="text-xs text-gray-600">
                        {isResultStepLocked ? "Aplique os filtros para liberar esta etapa." : "Revise o total e finalize o envio."}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div className="space-y-6">
                  <Card className="shadow-sm">
                    <CardHeader>
                      <div className="flex items-start gap-3">
                        <div className={STEP_BADGE_CLASS_NAME}>1</div>
                        <div>
                          <CardTitle className="text-lg">Defina os filtros</CardTitle>
                          <CardDescription>
                            Monte a base que poderá entrar no lote.
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
                              draftFilters.general.with_phones
                                ? "with_phones"
                                : draftFilters.general.without_phones
                                  ? "without_phones"
                                  : "all"
                            }
                            onValueChange={(value) => {
                              updateGeneralFilter("with_phones", value === "with_phones")
                              updateGeneralFilter("without_phones", value === "without_phones")
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
                                  onCheckedChange={(checked) => toggleBankSelection(bank.value, Boolean(checked))}
                                />
                                {bank.value === "clt" ? (
                                  <img src={factaLogo} alt="Facta" className="h-6 w-6 object-contain" />
                                ) : null}
                                {bank.value === "mercantil" ? (
                                  <img src={mercantilLogo} alt="Mercantil" className="h-6 w-6 object-contain" />
                                ) : null}
                                {bank.value === "uy3" ? (
                                  <img src={uy3Logo} alt="UY3" className="h-6 w-6 object-contain" />
                                ) : null}
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
                                setDraftFilters((current) => ({
                                  ...current,
                                  bank_combination_mode: value as LemitPrototypeFilters["bank_combination_mode"],
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
                        <div className="rounded-xl border p-4">
                          <div className="mb-4 flex items-center gap-3 text-lg font-semibold text-gray-900">
                            <img src={factaLogo} alt="Facta" className="h-7 w-auto object-contain" />
                            <span>Filtros CLT Facta</span>
                          </div>
                          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div className="space-y-2">
                              <div className="text-sm font-medium">Situação</div>
                              <Select
                                value={draftFilters.bank_filters.clt.clt_situacao || "__empty__"}
                                onValueChange={(value) => updateBankFilter("clt", "clt_situacao", value === "__empty__" ? "" : value as LemitPrototypeLoanSituation)}
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
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Consulta de</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.clt.clt_consulta_from}
                                onChange={(event) => updateBankFilter("clt", "clt_consulta_from", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Consulta até</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.clt.clt_consulta_to}
                                onChange={(event) => updateBankFilter("clt", "clt_consulta_to", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Meses admissão mín.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_meses_admissao_min}
                                onChange={(event) => updateBankFilter("clt", "clt_meses_admissao_min", event.target.value)}
                                placeholder="Ex.: 1"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Meses admissão máx.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_meses_admissao_max}
                                onChange={(event) => updateBankFilter("clt", "clt_meses_admissao_max", event.target.value)}
                                placeholder="Ex.: 240"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Margem mínima</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_margem_min}
                                onChange={(event) => updateBankFilter("clt", "clt_margem_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Margem máxima</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_margem_max}
                                onChange={(event) => updateBankFilter("clt", "clt_margem_max", event.target.value)}
                                placeholder="Ex.: 2000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado mín.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_valor_liberado_min}
                                onChange={(event) => updateBankFilter("clt", "clt_valor_liberado_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado máx.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_valor_liberado_max}
                                onChange={(event) => updateBankFilter("clt", "clt_valor_liberado_max", event.target.value)}
                                placeholder="Ex.: 10000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas mín.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_numero_parcelas_min}
                                onChange={(event) => updateBankFilter("clt", "clt_numero_parcelas_min", event.target.value)}
                                placeholder="Ex.: 1"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas máx.</div>
                              <Input
                                value={draftFilters.bank_filters.clt.clt_numero_parcelas_max}
                                onChange={(event) => updateBankFilter("clt", "clt_numero_parcelas_max", event.target.value)}
                                placeholder="Ex.: 120"
                              />
                            </div>
                          </div>
                        </div>
                      ) : null}

                      {draftFilters.selected_banks.includes("mercantil") ? (
                        <div className="rounded-xl border p-4">
                          <div className="mb-4 flex items-center gap-3 text-lg font-semibold text-gray-900">
                            <img src={mercantilLogo} alt="Mercantil" className="h-7 w-auto object-contain" />
                            <span>Filtros CLT Mercantil</span>
                          </div>
                          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div className="space-y-2">
                              <div className="text-sm font-medium">Situação</div>
                              <Select
                                value={draftFilters.bank_filters.mercantil.mercantil_situacao || "__empty__"}
                                onValueChange={(value) => updateBankFilter("mercantil", "mercantil_situacao", value === "__empty__" ? "" : value as LemitPrototypeLoanSituation)}
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
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Consulta de</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.mercantil.mercantil_consulta_from}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_consulta_from", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Consulta até</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.mercantil.mercantil_consulta_to}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_consulta_to", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor parcela mín.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_valor_parcela_min}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_valor_parcela_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor parcela máx.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_valor_parcela_max}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_valor_parcela_max", event.target.value)}
                                placeholder="Ex.: 1000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado mín.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_valor_liberado_min}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_valor_liberado_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado máx.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_valor_liberado_max}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_valor_liberado_max", event.target.value)}
                                placeholder="Ex.: 10000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas mín.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_numero_parcelas_min}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_numero_parcelas_min", event.target.value)}
                                placeholder="Ex.: 1"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas máx.</div>
                              <Input
                                value={draftFilters.bank_filters.mercantil.mercantil_numero_parcelas_max}
                                onChange={(event) => updateBankFilter("mercantil", "mercantil_numero_parcelas_max", event.target.value)}
                                placeholder="Ex.: 120"
                              />
                            </div>
                          </div>

                        </div>
                      ) : null}

                      {draftFilters.selected_banks.includes("uy3") ? (
                        <div className="rounded-xl border p-4">
                          <div className="mb-4 flex items-center gap-3 text-lg font-semibold text-gray-900">
                            <img src={uy3Logo} alt="UY3" className="h-7 w-auto object-contain" />
                            <span>Filtros CLT UY3</span>
                          </div>
                          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div className="space-y-2">
                              <div className="text-sm font-medium">Situação</div>
                              <Select
                                value={draftFilters.bank_filters.uy3.uy3_situacao || "__empty__"}
                                onValueChange={(value) => updateBankFilter("uy3", "uy3_situacao", value === "__empty__" ? "" : value as LemitPrototypeLoanSituation)}
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
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Atualização de</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.uy3.uy3_consulta_from}
                                onChange={(event) => updateBankFilter("uy3", "uy3_consulta_from", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Atualização até</div>
                              <Input
                                type="date"
                                value={draftFilters.bank_filters.uy3.uy3_consulta_to}
                                onChange={(event) => updateBankFilter("uy3", "uy3_consulta_to", event.target.value)}
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Meses admissão mín.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_meses_admissao_min}
                                onChange={(event) => updateBankFilter("uy3", "uy3_meses_admissao_min", event.target.value)}
                                placeholder="Ex.: 1"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Meses admissão máx.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_meses_admissao_max}
                                onChange={(event) => updateBankFilter("uy3", "uy3_meses_admissao_max", event.target.value)}
                                placeholder="Ex.: 240"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Margem mínima</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_margem_min}
                                onChange={(event) => updateBankFilter("uy3", "uy3_margem_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Margem máxima</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_margem_max}
                                onChange={(event) => updateBankFilter("uy3", "uy3_margem_max", event.target.value)}
                                placeholder="Ex.: 2000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado mín.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_valor_liberado_min}
                                onChange={(event) => updateBankFilter("uy3", "uy3_valor_liberado_min", event.target.value)}
                                placeholder="Ex.: 0,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Valor liberado máx.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_valor_liberado_max}
                                onChange={(event) => updateBankFilter("uy3", "uy3_valor_liberado_max", event.target.value)}
                                placeholder="Ex.: 10000,00"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas mín.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_numero_parcelas_min}
                                onChange={(event) => updateBankFilter("uy3", "uy3_numero_parcelas_min", event.target.value)}
                                placeholder="Ex.: 1"
                              />
                            </div>

                            <div className="space-y-2">
                              <div className="text-sm font-medium">Qtd. parcelas máx.</div>
                              <Input
                                value={draftFilters.bank_filters.uy3.uy3_numero_parcelas_max}
                                onChange={(event) => updateBankFilter("uy3", "uy3_numero_parcelas_max", event.target.value)}
                                placeholder="Ex.: 120"
                              />
                            </div>
                          </div>
                        </div>
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
                        <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={handleApplyFilters} disabled={!canSearchPool}>
                          Atualizar resultado
                        </Button>
                        <Button className={OUTLINE_BUTTON_CLASS_NAME} variant="outline" onClick={handleClearFilters}>
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
                          <CardTitle className="text-lg">Resultado e lote</CardTitle>
                          <CardDescription>
                            Veja o total encontrado e já confirme o lote no mesmo passo.
                          </CardDescription>
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      {isResultStepLocked ? (
                        <div className="rounded-lg border border-slate-200 bg-slate-100 p-4 text-sm text-slate-600">
                          Aplique os filtros da etapa 1 para liberar o resultado e a criação do lote.
                        </div>
                      ) : null}

                      {!isResultStepLocked ? (
                        <div className="grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                        <div className="space-y-4">
                          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-2">
                            <div className="rounded-lg border p-4">
                              <div className="text-xs text-muted-foreground">Total encontrado</div>
                              <div className="mt-1 text-2xl font-semibold text-gray-900">{filteredPool.length}</div>
                            </div>
                            <div className="rounded-lg border p-4">
                              <div className="text-xs text-muted-foreground">Com telefone</div>
                              <div className="mt-1 text-2xl font-semibold text-gray-900">{poolWithPhones}</div>
                            </div>
                            <div className="rounded-lg border p-4">
                              <div className="text-xs text-muted-foreground">Sem telefone</div>
                              <div className="mt-1 text-2xl font-semibold text-gray-900">{poolWithoutPhones}</div>
                            </div>
                            <div className="rounded-lg border p-4">
                              <div className="text-xs text-muted-foreground">Combinação</div>
                              <div className="mt-1 text-sm font-semibold text-gray-900">
                                {getPrototypeCombinationLabel(appliedFilters.bank_combination_mode)}
                              </div>
                            </div>
                          </div>

                        </div>

                        <div className="rounded-xl border bg-gradient-to-b from-slate-50 to-white p-4 sm:p-5">
                          <div className="mb-4 rounded-lg border bg-white p-4">
                            <div className="text-sm font-semibold text-gray-900">Confirmar lote</div>
                            <div className="mt-1 text-sm text-muted-foreground">
                              Dê um nome ao lote e defina quantos CPFs serão sorteados da base filtrada.
                            </div>
                          </div>

                          <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2 md:col-span-2">
                              <div className="text-sm font-medium">Nome do lote</div>
                              <Input
                                value={lotTitle}
                                disabled={isResultStepLocked}
                                onChange={(event) => setLotTitle(event.target.value)}
                                placeholder="Ex.: Lemit sem telefone mercantil"
                              />
                            </div>
                            <div className="space-y-2">
                              <div className="text-sm font-medium">Quantos deseja rodar</div>
                              <Input
                                type="number"
                                min={1}
                                max={filteredPool.length || 1}
                                value={requestedQuantity}
                                disabled={isResultStepLocked}
                                onChange={(event) => setRequestedQuantity(event.target.value)}
                                placeholder="Ex.: 1500"
                              />
                            </div>
                            <div className="rounded-lg border bg-white p-4">
                              <div className="text-xs text-muted-foreground">Limite disponível</div>
                              <div className="mt-1 text-lg font-semibold text-gray-900">
                                Até {filteredPool.length} CPF(s)
                              </div>
                            </div>
                          </div>

                          <div className="space-y-3 mt-4">
                            {validationMessages.length ? (
                              <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                Ajuste os filtros dos bancos selecionados antes de atualizar o resultado.
                              </div>
                            ) : null}

                            {hasUnappliedChanges ? (
                              <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                                Há mudanças pendentes. Atualize o resultado antes de rodar o lote.
                              </div>
                            ) : null}

                            {lotTitleError ? (
                              <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                {lotTitleError}
                              </div>
                            ) : null}

                            <div
                              className={cn(
                                "rounded-lg border p-3 text-sm",
                                quantityError
                                  ? "border-amber-200 bg-amber-50 text-amber-800"
                                  : "border-slate-200 bg-white text-slate-700",
                              )}
                            >
                              {quantityError ?? `Pronto para rodar até ${filteredPool.length} CPF(s) da base filtrada atual.`}
                            </div>
                          </div>

                        </div>
                      </div>
                      ) : null}
                    </CardContent>
                  </Card>

                  <div className="flex flex-wrap justify-end gap-3">
                    <Button
                      className={OUTLINE_BUTTON_CLASS_NAME}
                      variant="outline"
                      onClick={handleCancelLot}
                    >
                      Cancelar lote
                    </Button>
                    <Button
                      className={PRIMARY_BUTTON_CLASS_NAME}
                      onClick={() => setIsRunDialogOpen(true)}
                      disabled={!canRunLot}
                    >
                      Rodar lote
                    </Button>
                  </div>
              </div>
            </CardContent>
          </Card>
        ) : null}

        <AlertDialog open={isRunDialogOpen} onOpenChange={setIsRunDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Confirmar execução do lote?</AlertDialogTitle>
              <AlertDialogDescription>
                {`Lote "${normalizedLotTitle || "Sem nome"}" com ${parsedQuantity || 0} CPF(s) da base filtrada atual.`}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Voltar</AlertDialogCancel>
              <AlertDialogAction
                className="bg-blue-600 text-white hover:bg-blue-700"
                onClick={handleRunLot}
              >
                Confirmar e rodar
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        <LemitPrototypeHistoryTable
          lots={lots}
          onDownload={downloadPrototypeLotCsv}
          onDelete={handleDeleteLot}
        />
      </div>
    </div>
  )
}
