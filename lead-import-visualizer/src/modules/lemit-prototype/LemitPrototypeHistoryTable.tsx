import { useMemo, useState } from "react"
import { Download, Eye, Trash2 } from "lucide-react"
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { formatCPF, formatLocalDateTime } from "@/lib/formatters"
import { cn } from "@/lib/utils"
import {
  getPrototypeBanksLabel,
  getPrototypeCombinationLabel,
  getPrototypeLotStatusClassName,
  getPrototypeLotStatusLabel,
  getPrototypeResultClassName,
  getPrototypeResultLabel,
} from "./history"
import type { LemitPrototypeLot } from "./types"

type Props = {
  lots: LemitPrototypeLot[]
  onDownload: (lot: LemitPrototypeLot) => void
  onDelete: (lotId: number) => void
}

const ACTION_BUTTON_CLASS_NAME = "border-blue-200 text-blue-700 hover:bg-blue-50 hover:text-blue-800"

export function LemitPrototypeHistoryTable({ lots, onDownload, onDelete }: Props) {
  const [selectedLotId, setSelectedLotId] = useState<number | null>(null)
  const [deleteLotId, setDeleteLotId] = useState<number | null>(null)

  const selectedLot = useMemo(
    () => lots.find((lot) => lot.id === selectedLotId) ?? null,
    [lots, selectedLotId],
  )

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
            <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
              Nenhum lote executado ainda.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Lote</TableHead>
                  <TableHead>Criado em</TableHead>
                  <TableHead>Bancos</TableHead>
                  <TableHead>Combinação</TableHead>
                  <TableHead>Base filtrada</TableHead>
                  <TableHead>Solicitado</TableHead>
                  <TableHead>Sorteado</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Telefones encontrados</TableHead>
                  <TableHead>Leads atualizados</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lots.map((lot) => (
                  <TableRow key={lot.id}>
                    <TableCell className="font-medium">{lot.title}</TableCell>
                    <TableCell>{formatLocalDateTime(lot.created_at)}</TableCell>
                    <TableCell className="max-w-[220px]">{getPrototypeBanksLabel(lot.banks)}</TableCell>
                    <TableCell>{getPrototypeCombinationLabel(lot.bank_combination_mode, true)}</TableCell>
                    <TableCell>{lot.pool_size}</TableCell>
                    <TableCell>{lot.requested_quantity}</TableCell>
                    <TableCell>{lot.sampled_quantity}</TableCell>
                    <TableCell>
                      <Badge
                        variant="outline"
                        className={cn("pointer-events-none", getPrototypeLotStatusClassName(lot.status))}
                      >
                        {getPrototypeLotStatusLabel(lot.status)}
                      </Badge>
                    </TableCell>
                    <TableCell>{lot.phones_found_count}</TableCell>
                    <TableCell>{lot.leads_updated_count}</TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          className={ACTION_BUTTON_CLASS_NAME}
                          onClick={() => setSelectedLotId(lot.id)}
                        >
                          <Eye className="h-4 w-4" />
                          Ver detalhes
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          className={ACTION_BUTTON_CLASS_NAME}
                          onClick={() => onDownload(lot)}
                        >
                          <Download className="h-4 w-4" />
                          Baixar CSV
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          className="text-red-600"
                          onClick={() => setDeleteLotId(lot.id)}
                        >
                          <Trash2 className="h-4 w-4" />
                          Excluir
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={Boolean(selectedLot)} onOpenChange={(open) => !open && setSelectedLotId(null)}>
        <DialogContent className="max-h-[88vh] max-w-6xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{selectedLot?.title}</DialogTitle>
            <DialogDescription>
              {selectedLot
                ? `${getPrototypeBanksLabel(selectedLot.banks)} • ${getPrototypeCombinationLabel(selectedLot.bank_combination_mode)}`
                : ""}
            </DialogDescription>
          </DialogHeader>

          {selectedLot ? (
            <div className="space-y-4">
              <div className="grid gap-3 md:grid-cols-4">
                <div className="rounded-md border p-3">
                  <div className="text-xs text-muted-foreground">Criado em</div>
                  <div className="mt-1 text-sm font-medium">{formatLocalDateTime(selectedLot.created_at)}</div>
                </div>
                <div className="rounded-md border p-3">
                  <div className="text-xs text-muted-foreground">Base filtrada</div>
                  <div className="mt-1 text-sm font-medium">{selectedLot.pool_size}</div>
                </div>
                <div className="rounded-md border p-3">
                  <div className="text-xs text-muted-foreground">Telefones encontrados</div>
                  <div className="mt-1 text-sm font-medium">{selectedLot.phones_found_count}</div>
                </div>
                <div className="rounded-md border p-3">
                  <div className="text-xs text-muted-foreground">Leads atualizados</div>
                  <div className="mt-1 text-sm font-medium">{selectedLot.leads_updated_count}</div>
                </div>
              </div>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-base">Resultados por CPF</CardTitle>
                </CardHeader>
                <CardContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>CPF</TableHead>
                        <TableHead>Nome</TableHead>
                        <TableHead>Telefone anterior</TableHead>
                        <TableHead>Telefone preferido</TableHead>
                        <TableHead>Resultado</TableHead>
                        <TableHead>Atualizaria lead</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectedLot.items.map((item) => (
                        <TableRow key={`${selectedLot.id}-${item.cpf}`}>
                          <TableCell>{formatCPF(item.cpf)}</TableCell>
                          <TableCell>{item.nome}</TableCell>
                          <TableCell>{item.telefone_atual_antes || "--"}</TableCell>
                          <TableCell>{item.telefone_lemit || "--"}</TableCell>
                          <TableCell>
                            <Badge
                              variant="outline"
                              className={cn("pointer-events-none", getPrototypeResultClassName(item.resultado))}
                            >
                              {getPrototypeResultLabel(item.resultado)}
                            </Badge>
                          </TableCell>
                          <TableCell>{item.atualizaria_lead ? "Sim" : "Não"}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-base">Snapshot dos filtros</CardTitle>
                </CardHeader>
                <CardContent>
                  <pre className="overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-50">
                    {JSON.stringify(selectedLot.filters_snapshot, null, 2)}
                  </pre>
                </CardContent>
              </Card>
            </div>
          ) : null}
        </DialogContent>
      </Dialog>

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
