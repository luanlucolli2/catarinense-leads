import { useQuery } from "@tanstack/react-query"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import {
  formatCPF,
  formatCurrency,
  formatPhone,
  formatDateOnly
} from "@/lib/formatters"
import { format } from "date-fns"
import { ptBR } from "date-fns/locale"
import { cn } from "@/lib/utils"
import { fetchLeadDetail, LeadDetailFromApi } from "@/api/leads"

/* ---------- helpers locais ---------- */


const Item = ({ label, value }: { label: string; value: string }) => (
  <div>
    <label className="text-sm text-gray-500">{label}</label>
    <p className="text-sm">{value}</p>
  </div>
)

interface LeadDetailsModalProps {
  isOpen: boolean
  onClose: () => void
  leadId: number | null
}

export const LeadDetailsModal = ({
  isOpen,
  onClose,
  leadId,
}: LeadDetailsModalProps) => {
  const { data: lead, isLoading } = useQuery<LeadDetailFromApi>({
    queryKey: ["lead", leadId],
    queryFn: () => fetchLeadDetail(leadId as number),
    enabled: !!leadId,
  })

  if (!leadId) return null

  const contracts = lead?.contracts ?? []
  const importJobs = (lead as any)?.import_jobs ?? []

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      {/* 90 vh + overflow dá rolagem global, mas listas longas tem scroll próprio */}
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Detalhes do Lead</DialogTitle>
        </DialogHeader>

        {isLoading || !lead ? (
          <div className="p-6 text-center">Carregando…</div>
        ) : (
          <div className="space-y-6">
            {/* ============ DADOS BÁSICOS (ordem ajustada) ============ */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Item label="Nome" value={lead.nome ?? "--"} />
              <Item label="CPF" value={formatCPF(lead.cpf)} />
              <Item label="Data nasc." value={formatDateOnly(lead.data_nascimento)} />

              <div>
                <label className="text-sm text-gray-500">
                  Status / Consulta
                </label>
                <div className="flex items-center space-x-2">
                  <span
                    className={cn(
                      "px-2 py-1 text-xs font-semibold rounded-full",
                      lead.consulta === "Saldo FACTA" &&
                        parseFloat(lead.libera ?? "0") > 0
                        ? "bg-green-100 text-green-800"
                        : "bg-gray-100 text-gray-800"
                    )}
                  >
                    {lead.consulta === "Saldo FACTA" &&
                      parseFloat(lead.libera ?? "0") > 0
                      ? "Elegível"
                      : "Inelegível"}
                  </span>
                  <span className="text-xs text-gray-600">
                    • {lead.consulta ?? "--"}
                  </span>
                </div>
              </div>

              <Item label="Saldo" value={formatCurrency(lead.saldo)} />
              <Item label="Libera" value={formatCurrency(lead.libera)} />
            </div>

            {/* ============ TELEFONES ============ */}
            {(lead.fone1 || lead.fone2 || lead.fone3 || lead.fone4) && (
              <section>
                <label className="text-sm text-gray-500">Telefones</label>
                <div className="mt-2 space-y-1">
                  {[1, 2, 3, 4].map((i) => {
                    const f = (lead as any)[`fone${i}`]
                    const c = (lead as any)[`classe_fone${i}`]
                    if (!f) return null
                    return (
                      <div
                        key={i}
                        className="flex justify-between bg-gray-50 p-2 rounded"
                      >
                        <span>{formatPhone(f)}</span>
                        <span
                          className={cn(
                            "px-2 py-1 text-xs font-semibold rounded-full",
                            c === "Quente"
                              ? "bg-red-100 text-red-800"
                              : "bg-blue-100 text-blue-800"
                          )}
                        >
                          {c}
                        </span>
                      </div>
                    )
                  })}
                </div>
              </section>
            )}

            {/* ============ CONTRATOS (lista com scroll próprio) ============ */}
            <section>
              <label className="text-sm text-gray-500">
                Contratos ({contracts.length})
              </label>


              <ul className="mt-2 space-y-1 list-disc list-inside max-h-48 overflow-y-auto pr-2">
                {contracts.map((c) => (
                  <li key={c.id} className="flex justify-between">
                    <span>{"- "+formatDateOnly(c.data_contrato)}</span>
                    <span className="text-xs text-gray-600">
                      {c.vendor?.name ?? "Sem vendedor"}
                    </span>
                  </li>
                ))}
              </ul>
            </section>

            {/* ============ IMPORTS (lista com scroll próprio) ============ */}
            <section>
              <label className="text-sm text-gray-500">
                Histórico de Imports
              </label>
              <ul className="mt-2 space-y-1 max-h-48 overflow-y-auto pr-2">
                {importJobs.map((j: any) => (
                  <li
                    key={j.id}
                    className="flex justify-between bg-gray-50 p-2 rounded"
                  >
                    <span className="capitalize">{j.type}</span>
                    <span className="truncate">{j.origin}</span>
                    <span className="text-xs text-gray-500">
                      {formatDateOnly(j.created_at)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Fechar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
