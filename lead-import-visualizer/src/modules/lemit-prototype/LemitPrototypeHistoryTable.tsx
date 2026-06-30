import { useState } from "react"
import { AlertCircle, CheckCircle, Clock, Download, MoreHorizontal, Trash2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { formatLocalDateTime } from "@/lib/formatters"
import { cn } from "@/lib/utils"
import {
  getPrototypeBanksLabel,
  getPrototypeCombinationLabel,
  getPrototypeLotStatusClassName,
  getPrototypeLotStatusLabel,
} from "./history"
import type { LemitPrototypeLot } from "./types"

type Props = {
  lots: LemitPrototypeLot[]
  onDownload: (lot: LemitPrototypeLot) => void
  onDelete: (lotId: number) => void
}

function getLotStatusIcon(status: LemitPrototypeLot["status"]) {
  return status === "concluido"
    ? <CheckCircle className="w-4 h-4" />
    : <Clock className="w-4 h-4" />
}

function getLotMetrics(lot: LemitPrototypeLot) {
  const successCount = lot.items.filter((item) => item.resultado !== "erro_simulado").length
  const errorCount = lot.items.filter((item) => item.resultado === "erro_simulado").length
  const pendingCount = Math.max(0, lot.sampled_quantity - successCount - errorCount)

  const total = Math.max(0, lot.pool_size)
  const successPct = total > 0 ? (successCount / total) * 100 : 0
  const errorPct = total > 0 ? (errorCount / total) * 100 : 0
  const pendingPct = total > 0 ? (pendingCount / total) * 100 : 0
  const chosenPct = total > 0 ? (lot.sampled_quantity / total) * 100 : 0

  return {
    successCount,
    errorCount,
    pendingCount,
    successPct,
    errorPct,
    pendingPct,
    chosenPct,
  }
}

function LemitLotProgressBar({ lot }: { lot: LemitPrototypeLot }) {
  const metrics = getLotMetrics(lot)
  const filteredCount = Math.max(0, lot.pool_size)
  const chosenNotProcessedCount = Math.max(0, lot.sampled_quantity - metrics.successCount - metrics.errorCount)
  const unchosenCount = Math.max(0, filteredCount - lot.sampled_quantity)

  const chosenNotProcessedPct = filteredCount > 0 ? (chosenNotProcessedCount / filteredCount) * 100 : 0
  const unchosenPct = filteredCount > 0 ? (unchosenCount / filteredCount) * 100 : 0
  const totalPct = metrics.successPct + metrics.errorPct + chosenNotProcessedPct

  return (
    <div className="rounded-xl border border-border/70 bg-muted/5 p-4 shadow-sm">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          {getLotStatusIcon(lot.status)}
          <span className="text-sm font-semibold">Execução do lote</span>
        </div>
        <span className="text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
          {lot.sampled_quantity.toLocaleString()} / {lot.pool_size.toLocaleString()} CPFs
        </span>
      </div>

      <div className="relative h-2.5 bg-muted rounded-full overflow-hidden mb-3">
        {metrics.successPct > 0 && (
          <div
            className="absolute left-0 top-0 h-full bg-emerald-500 transition-all duration-500"
            style={{ width: `${metrics.successPct}%` }}
          />
        )}
        {metrics.errorPct > 0 && (
          <div
            className="absolute top-0 h-full bg-destructive transition-all duration-500"
            style={{ left: `${metrics.successPct}%`, width: `${metrics.errorPct}%` }}
          />
        )}
        {chosenNotProcessedPct > 0 && (
          <div
            className="absolute top-0 h-full bg-blue-500 transition-all duration-500"
            style={{ left: `${metrics.successPct + metrics.errorPct}%`, width: `${chosenNotProcessedPct}%` }}
          />
        )}
        {unchosenPct > 0 && (
          <div
            className="absolute top-0 h-full bg-slate-300 transition-all duration-500"
            style={{ left: `${totalPct}%`, width: `${unchosenPct}%` }}
          />
        )}
      </div>

      <div className="flex items-center gap-3 flex-wrap text-xs">
        <div className="flex items-center gap-1.5">
          <div className="w-2 h-2 rounded-full bg-slate-300" />
          <span className="text-muted-foreground">Filtrados</span>
          <span className="font-semibold text-foreground">{lot.pool_size.toLocaleString()}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-2 h-2 rounded-full bg-blue-500" />
          <span className="text-muted-foreground">Escolhidos</span>
          <span className="font-semibold text-foreground">{lot.sampled_quantity.toLocaleString()}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-2 h-2 rounded-full bg-emerald-500" />
          <span className="text-muted-foreground">Sucesso</span>
          <span className="font-semibold text-foreground">{metrics.successCount.toLocaleString()}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-2 h-2 rounded-full bg-destructive" />
          <span className="text-muted-foreground">Erro</span>
          <span className="font-semibold text-foreground">{metrics.errorCount.toLocaleString()}</span>
        </div>
      </div>
    </div>
  )
}

export function LemitPrototypeHistoryTable({ lots, onDownload, onDelete }: Props) {
  const [deleteLotId, setDeleteLotId] = useState<number | null>(null)

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Histórico de lotes</CardTitle>
          <CardDescription>
            Lotes simulados localmente para validar o fluxo operacional.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {lots.length === 0 ? (
            <Card>
              <CardContent className="flex items-center justify-center py-10 sm:py-12">
                <div className="text-center">
                  <AlertCircle className="w-10 h-10 sm:w-12 sm:h-12 text-muted-foreground mx-auto mb-3 sm:mb-4" />
                  <p className="text-sm sm:text-base text-muted-foreground">
                    Nenhum lote executado ainda
                  </p>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-3 sm:space-y-4">
              {lots.map((lot) => {
                const isActive = lot.status === "em_andamento"
                const metrics = getLotMetrics(lot)

                return (
                  <Card
                    key={lot.id}
                    className={cn(
                      "relative rounded-xl border transition-all duration-500",
                      isActive
                        ? "border-blue-400/60 shadow-[0_0_15px_rgba(59,130,246,0.15)] bg-blue-50/40 ring-1 ring-blue-400/20"
                        : "border-slate-200/80 bg-gradient-to-b from-white to-neutral-50 shadow-md hover:shadow-lg ring-1 ring-black/5",
                    )}
                  >
                    <CardHeader className="pb-3">
                      <div className="flex flex-col gap-2 sm:gap-3">
                        <div className="flex items-start justify-between">
                          <div className="flex-1 min-w-0">
                            <h3 className="font-semibold text-card-foreground truncate mb-1 text-base sm:text-lg">
                              {lot.title}
                            </h3>
                            <div className="flex flex-wrap items-center gap-2 sm:gap-4 text-xs sm:text-sm text-muted-foreground">
                              <span>Criado em {formatLocalDateTime(lot.created_at)}</span>
                              <span>{getPrototypeBanksLabel(lot.banks)}</span>
                            </div>
                          </div>

                          <div className="sm:hidden ml-2">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                  <MoreHorizontal className="h-4 w-4" />
                                  <span className="sr-only">Mais ações</span>
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-40">
                                <DropdownMenuItem onClick={() => onDownload(lot)}>
                                  <Download className="w-4 h-4 mr-2" />
                                  Baixar CSV
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  onClick={() => setDeleteLotId(lot.id)}
                                  className="text-destructive"
                                >
                                  <Trash2 className="w-4 h-4 mr-2" />
                                  Excluir
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>

                          <div className="hidden sm:flex flex-wrap items-center justify-end gap-2 sm:gap-3 ml-4">
                            <Badge
                              variant="outline"
                              className={cn(
                                "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full",
                                getPrototypeLotStatusClassName(lot.status),
                              )}
                            >
                              {getLotStatusIcon(lot.status)}
                              <span className="whitespace-nowrap">{getPrototypeLotStatusLabel(lot.status)}</span>
                            </Badge>

                            <Badge variant="outline" className="px-2.5 py-1 text-xs rounded-full">
                              {getPrototypeCombinationLabel(lot.bank_combination_mode, true)}
                            </Badge>

                            <Button
                              variant="outline"
                              size="sm"
                              className="h-8"
                              onClick={() => onDownload(lot)}
                            >
                              <Download className="w-4 h-4" />
                            </Button>

                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                  <MoreHorizontal className="h-4 w-4" />
                                  <span className="sr-only">Mais ações</span>
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-40">
                                <DropdownMenuItem
                                  onClick={() => setDeleteLotId(lot.id)}
                                  className="text-destructive"
                                >
                                  <Trash2 className="w-4 h-4 mr-2" />
                                  Excluir
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </div>

                        <div className="sm:hidden flex flex-wrap items-center gap-2">
                          <Badge
                            variant="outline"
                            className={cn(
                              "flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full",
                              getPrototypeLotStatusClassName(lot.status),
                            )}
                          >
                            {getLotStatusIcon(lot.status)}
                            <span className="whitespace-nowrap">{getPrototypeLotStatusLabel(lot.status)}</span>
                          </Badge>
                          <Badge variant="outline" className="px-2.5 py-1 text-xs rounded-full">
                            {getPrototypeCombinationLabel(lot.bank_combination_mode, true)}
                          </Badge>
                        </div>
                      </div>
                    </CardHeader>

                    <CardContent className="space-y-4">
                      <LemitLotProgressBar lot={lot} />

                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs text-muted-foreground sm:text-sm">Bancos usados:</span>
                        {lot.banks.length ? (
                          lot.banks.map((bank) => (
                            <Badge key={`${lot.id}-${bank}`} variant="outline" className="rounded-full px-3 py-1">
                              {getPrototypeBanksLabel([bank])}
                            </Badge>
                          ))
                        ) : (
                          <Badge variant="secondary">Somente filtros gerais</Badge>
                        )}
                      </div>

                      <div className="grid grid-cols-4 gap-3 sm:gap-4 pt-2 border-t border-border">
                        <div className="text-center">
                          <div className="text-base sm:text-lg font-semibold text-slate-600">
                            {lot.pool_size.toLocaleString()}
                          </div>
                          <div className="text-[11px] sm:text-xs text-muted-foreground">Filtrados</div>
                        </div>
                        <div className="text-center">
                          <div className="text-base sm:text-lg font-semibold text-blue-600">
                            {lot.sampled_quantity.toLocaleString()}
                          </div>
                          <div className="text-[11px] sm:text-xs text-muted-foreground">Escolhidos</div>
                        </div>
                        <div className="text-center">
                          <div className="text-base sm:text-lg font-semibold text-emerald-600">
                            {metrics.successCount.toLocaleString()}
                          </div>
                          <div className="text-[11px] sm:text-xs text-muted-foreground">Sucesso</div>
                        </div>
                        <div className="text-center">
                          <div className="text-base sm:text-lg font-semibold text-red-600">
                            {metrics.errorCount.toLocaleString()}
                          </div>
                          <div className="text-[11px] sm:text-xs text-muted-foreground">Erro</div>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>

      <AlertDialog open={deleteLotId !== null} onOpenChange={(open) => !open && setDeleteLotId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Excluir lote?</AlertDialogTitle>
            <AlertDialogDescription>
              O histórico e os resultados desse lote serão removidos do protótipo local.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (deleteLotId !== null) {
                  onDelete(deleteLotId)
                }
                setDeleteLotId(null)
              }}
            >
              Excluir
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
