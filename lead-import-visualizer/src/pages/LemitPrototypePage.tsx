import { useEffect, useMemo } from "react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { MultiSelect } from "@/components/ui/multi-select"
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
  getPrototypeBankLabel,
  getPrototypeMonthOptions,
  getPrototypeOptionCatalog,
  validatePrototypeBankSelections,
} from "@/modules/lemit-prototype/mock"
import type {
  LemitPrototypeBankKey,
  LemitPrototypeFilters,
  LemitPrototypeLead,
  LemitPrototypeLot,
} from "@/modules/lemit-prototype/types"

const DEFAULT_LEADS = createMockLeadsDataset()
const DEFAULT_FILTERS = createDefaultLemitPrototypeFilters()

const BANK_OPTIONS: Array<{ value: LemitPrototypeBankKey; label: string }> = [
  { value: "fgts", label: "FGTS" },
  { value: "clt", label: "CLT Facta" },
  { value: "mercantil", label: "CLT Mercantil" },
  { value: "uy3", label: "CLT UY3" },
]

const MONTH_OPTIONS = getPrototypeMonthOptions().map((month) => ({
  value: month,
  label: new Intl.DateTimeFormat("pt-BR", { month: "long" }).format(new Date(2026, Number(month) - 1, 1)),
}))

const CHECKBOX_CLASS_NAME = "border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600"
const PRIMARY_BUTTON_CLASS_NAME = "bg-blue-600 text-white hover:bg-blue-700"
const OUTLINE_BUTTON_CLASS_NAME = "border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"

function cloneFilters(filters: LemitPrototypeFilters) {
  return JSON.parse(JSON.stringify(filters)) as LemitPrototypeFilters
}

export default function LemitPrototypePage() {
  const [prototypeLeads, setPrototypeLeads] = usePersistedState<LemitPrototypeLead[]>(
    "lemit-prototype:leads:v1",
    DEFAULT_LEADS,
  )
  const [draftFilters, setDraftFilters] = usePersistedState<LemitPrototypeFilters>(
    "lemit-prototype:draft-filters:v1",
    DEFAULT_FILTERS,
  )
  const [appliedFilters, setAppliedFilters] = usePersistedState<LemitPrototypeFilters>(
    "lemit-prototype:applied-filters:v1",
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
  const [isNewLotOpen, setIsNewLotOpen] = usePersistedState<boolean>(
    "lemit-prototype:new-lot-open:v1",
    false,
  )

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

  const canSearchPool = validationMessages.length === 0
  const canRunLot = canSearchPool && !quantityError && filteredPool.length > 0

  const updateGeneralFilter = <K extends keyof LemitPrototypeFilters["general"]>(
    key: K,
    value: LemitPrototypeFilters["general"][K],
  ) => {
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
    setDraftFilters((current) => ({
      ...current,
      selected_banks: checked
        ? Array.from(new Set([...current.selected_banks, bank]))
        : current.selected_banks.filter((currentBank) => currentBank !== bank),
    }))
  }

  const handlePhoneFlagChange = (field: "with_phones" | "without_phones", checked: boolean) => {
    updateGeneralFilter(field, checked)
    if (checked) {
      updateGeneralFilter(field === "with_phones" ? "without_phones" : "with_phones", false)
    }
  }

  const handleApplyFilters = () => {
    if (!canSearchPool) return
    setAppliedFilters(cloneFilters(draftFilters))
  }

  const handleClearFilters = () => {
    const resetFilters = createDefaultLemitPrototypeFilters()
    setDraftFilters(resetFilters)
    setAppliedFilters(resetFilters)
    setRequestedQuantity("")
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
    )

    setPrototypeLeads(execution.updatedLeads)
    setLots((currentLots) => [execution.lot, ...currentLots])

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
            <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={() => setIsNewLotOpen(true)}>
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
              <Card className="shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Filtros gerais</CardTitle>
                  <CardDescription>
                    Esses filtros sempre aplicam sobre a base local do protótipo.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  <div className="space-y-2">
                    <div className="text-sm font-medium">Mês de aniversário</div>
                    <MultiSelect
                      options={MONTH_OPTIONS}
                      selected={draftFilters.general.birth_month}
                      onChange={(value) => updateGeneralFilter("birth_month", value)}
                      placeholder="Selecionar meses"
                    />
                  </div>

                  <div className="space-y-3 rounded-lg border bg-muted/20 p-4">
                    <div className="text-sm font-medium">Status de telefone</div>
                    <label className="flex items-center gap-2 text-sm">
                      <Checkbox
                        className={CHECKBOX_CLASS_NAME}
                        checked={draftFilters.general.with_phones}
                        onCheckedChange={(checked) => handlePhoneFlagChange("with_phones", Boolean(checked))}
                      />
                      Com telefone
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                      <Checkbox
                        className={CHECKBOX_CLASS_NAME}
                        checked={draftFilters.general.without_phones}
                        onCheckedChange={(checked) => handlePhoneFlagChange("without_phones", Boolean(checked))}
                      />
                      Sem telefone
                    </label>
                  </div>
                </CardContent>
              </Card>

              <Card className="shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Seleção de bancos</CardTitle>
                  <CardDescription>
                    Você pode combinar múltiplos bancos na mesma base filtrada.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 lg:grid-cols-[1.4fr_0.8fr]">
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
                        <span className="font-medium">{bank.label}</span>
                      </label>
                    ))}
                  </div>

                  <div className="space-y-2">
                    <div className="text-sm font-medium">Modo de combinação</div>
                    <Select
                      value={draftFilters.bank_combination_mode}
                      onValueChange={(value) => {
                        setDraftFilters((current) => ({
                          ...current,
                          bank_combination_mode: value as LemitPrototypeFilters["bank_combination_mode"],
                        }))
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Selecione" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todos os bancos selecionados</SelectItem>
                        <SelectItem value="any">Qualquer banco selecionado</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </CardContent>
              </Card>

              {draftFilters.selected_banks.includes("fgts") ? (
                <Card className="shadow-sm">
                  <CardHeader>
                    <CardTitle className="text-lg">Filtros FGTS</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-2">
                      <div className="text-sm font-medium">Status</div>
                      <Select
                        value={draftFilters.bank_filters.fgts.fgts_status || "__empty__"}
                        onValueChange={(value) => updateBankFilter("fgts", "fgts_status", value === "__empty__" ? "" : value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Selecionar status" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__empty__">Todos</SelectItem>
                          <SelectItem value="autorizado">Autorizado</SelectItem>
                          <SelectItem value="nao_autorizado">Não autorizado</SelectItem>
                          <SelectItem value="nao_consultado">Não consultado</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Motivos</div>
                      <MultiSelect
                        options={optionCatalog.fgtsMotivos}
                        selected={draftFilters.bank_filters.fgts.motivos}
                        onChange={(value) => updateBankFilter("fgts", "motivos", value)}
                        placeholder="Selecionar motivos"
                      />
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Origens hig</div>
                      <MultiSelect
                        options={optionCatalog.fgtsOrigensHig}
                        selected={draftFilters.bank_filters.fgts.origens_hig}
                        onChange={(value) => updateBankFilter("fgts", "origens_hig", value)}
                        placeholder="Selecionar origens"
                      />
                    </div>
                  </CardContent>
                </Card>
              ) : null}

              {draftFilters.selected_banks.includes("clt") ? (
                <Card className="shadow-sm">
                  <CardHeader>
                    <CardTitle className="text-lg">Filtros CLT Facta</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-4 md:grid-cols-4">
                    <div className="space-y-2">
                      <div className="text-sm font-medium">Consultado</div>
                      <Select
                        value={draftFilters.bank_filters.clt.clt_consultado || "__empty__"}
                        onValueChange={(value) => updateBankFilter("clt", "clt_consultado", value === "__empty__" ? "" : value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Selecionar" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__empty__">Todos</SelectItem>
                          <SelectItem value="sim">Sim</SelectItem>
                          <SelectItem value="nao">Não</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Situação</div>
                      <Select
                        value={draftFilters.bank_filters.clt.clt_situacao || "__empty__"}
                        onValueChange={(value) => updateBankFilter("clt", "clt_situacao", value === "__empty__" ? "" : value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Selecionar" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__empty__">Todas</SelectItem>
                          <SelectItem value="elegivel">Elegível</SelectItem>
                          <SelectItem value="nao_elegivel">Não elegível</SelectItem>
                          <SelectItem value="nao_encontrado">Não encontrado</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Margem mínima</div>
                      <Input
                        value={draftFilters.bank_filters.clt.clt_margem_min}
                        onChange={(event) => updateBankFilter("clt", "clt_margem_min", event.target.value)}
                        placeholder="0,00"
                      />
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Margem máxima</div>
                      <Input
                        value={draftFilters.bank_filters.clt.clt_margem_max}
                        onChange={(event) => updateBankFilter("clt", "clt_margem_max", event.target.value)}
                        placeholder="2000,00"
                      />
                    </div>
                  </CardContent>
                </Card>
              ) : null}

              {draftFilters.selected_banks.includes("mercantil") ? (
                <Card className="shadow-sm">
                  <CardHeader>
                    <CardTitle className="text-lg">Filtros CLT Mercantil</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-2">
                      <div className="text-sm font-medium">Situação</div>
                      <Select
                        value={draftFilters.bank_filters.mercantil.mercantil_situacao || "__empty__"}
                        onValueChange={(value) => updateBankFilter("mercantil", "mercantil_situacao", value === "__empty__" ? "" : value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Selecionar" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__empty__">Todas</SelectItem>
                          <SelectItem value="consultado">Consultado</SelectItem>
                          <SelectItem value="sem_consulta">Sem consulta</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Status</div>
                      <MultiSelect
                        options={optionCatalog.mercantilStatus}
                        selected={draftFilters.bank_filters.mercantil.mercantil_status}
                        onChange={(value) => updateBankFilter("mercantil", "mercantil_status", value)}
                        placeholder="Selecionar status"
                      />
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Origens</div>
                      <MultiSelect
                        options={optionCatalog.mercantilOrigens}
                        selected={draftFilters.bank_filters.mercantil.mercantil_origens}
                        onChange={(value) => updateBankFilter("mercantil", "mercantil_origens", value)}
                        placeholder="Selecionar origens"
                      />
                    </div>
                  </CardContent>
                </Card>
              ) : null}

              {draftFilters.selected_banks.includes("uy3") ? (
                <Card className="shadow-sm">
                  <CardHeader>
                    <CardTitle className="text-lg">Filtros CLT UY3</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-2">
                      <div className="text-sm font-medium">Type webhook</div>
                      <MultiSelect
                        options={optionCatalog.uy3Types}
                        selected={draftFilters.bank_filters.uy3.uy3_type_webhook}
                        onChange={(value) => updateBankFilter("uy3", "uy3_type_webhook", value)}
                        placeholder="Selecionar tipos"
                      />
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Status</div>
                      <MultiSelect
                        options={optionCatalog.uy3Statuses}
                        selected={draftFilters.bank_filters.uy3.uy3_status}
                        onChange={(value) => updateBankFilter("uy3", "uy3_status", value)}
                        placeholder="Selecionar status"
                      />
                    </div>

                    <div className="space-y-2">
                      <div className="text-sm font-medium">Elegível empréstimo</div>
                      <Select
                        value={draftFilters.bank_filters.uy3.uy3_elegivel_emprestimo || "__empty__"}
                        onValueChange={(value) => updateBankFilter("uy3", "uy3_elegivel_emprestimo", value === "__empty__" ? "" : value)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Selecionar" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__empty__">Todos</SelectItem>
                          <SelectItem value="sim">Sim</SelectItem>
                          <SelectItem value="nao">Não</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </CardContent>
                </Card>
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
                  Existem alterações ainda não aplicadas. Clique em Buscar base para atualizar o resultado abaixo.
                </div>
              ) : null}

              <div className="flex flex-wrap gap-3">
                <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={handleApplyFilters} disabled={!canSearchPool}>
                  Buscar base
                </Button>
                <Button className={OUTLINE_BUTTON_CLASS_NAME} variant="outline" onClick={handleClearFilters}>
                  Limpar filtros
                </Button>
              </div>

              <Card className="shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Resultado encontrado</CardTitle>
                  <CardDescription>
                    Resultado consolidado do último conjunto de filtros aplicado.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-lg border p-4">
                      <div className="text-xs text-muted-foreground">Total de leads encontrados</div>
                      <div className="mt-1 text-2xl font-semibold">{filteredPool.length}</div>
                    </div>
                    <div className="rounded-lg border p-4">
                      <div className="text-xs text-muted-foreground">Com telefone</div>
                      <div className="mt-1 text-2xl font-semibold">{poolWithPhones}</div>
                    </div>
                    <div className="rounded-lg border p-4">
                      <div className="text-xs text-muted-foreground">Sem telefone</div>
                      <div className="mt-1 text-2xl font-semibold">{poolWithoutPhones}</div>
                    </div>
                    <div className="rounded-lg border p-4">
                      <div className="text-xs text-muted-foreground">Combinação</div>
                      <div className="mt-1 text-sm font-semibold">
                        {getPrototypeCombinationLabel(appliedFilters.bank_combination_mode)}
                      </div>
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-muted-foreground">Bancos ativos:</span>
                    {appliedFilters.selected_banks.length ? (
                      appliedFilters.selected_banks.map((bank) => (
                        <Badge key={bank} variant="outline">
                          {getPrototypeBankLabel(bank)}
                        </Badge>
                      ))
                    ) : (
                      <Badge variant="secondary">Somente filtros gerais</Badge>
                    )}
                  </div>

                  <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    A tela não lista os leads da base. O lote usa uma amostra aleatória única dentro do total filtrado acima.
                  </div>
                </CardContent>
              </Card>

              <Card className="shadow-sm">
                <CardHeader>
                  <CardTitle className="text-lg">Iniciar lote</CardTitle>
                  <CardDescription>
                    Defina quantos CPFs serão sorteados para a higienização.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 lg:grid-cols-[220px_1fr_auto] lg:items-end">
                  <div className="space-y-2">
                    <div className="text-sm font-medium">Quantos deseja rodar</div>
                    <Input
                      type="number"
                      min={1}
                      max={filteredPool.length || 1}
                      value={requestedQuantity}
                      onChange={(event) => setRequestedQuantity(event.target.value)}
                      placeholder="Ex.: 1500"
                    />
                  </div>

                  <div
                    className={cn(
                      "rounded-lg border p-3 text-sm",
                      quantityError
                        ? "border-amber-200 bg-amber-50 text-amber-800"
                        : "border-slate-200 bg-slate-50 text-slate-700",
                    )}
                  >
                    {quantityError ?? `Pronto para rodar até ${filteredPool.length} CPF(s) da base filtrada atual.`}
                  </div>

                  <div className="flex flex-wrap gap-3">
                    <Button className={PRIMARY_BUTTON_CLASS_NAME} onClick={handleRunLot} disabled={!canRunLot}>
                      Rodar lote
                    </Button>
                    <Button className={OUTLINE_BUTTON_CLASS_NAME} variant="outline" onClick={() => setIsNewLotOpen(false)}>
                      Cancelar lote
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </CardContent>
          </Card>
        ) : null}

        <LemitPrototypeHistoryTable
          lots={lots}
          onDownload={downloadPrototypeLotCsv}
          onDelete={handleDeleteLot}
        />
      </div>
    </div>
  )
}
