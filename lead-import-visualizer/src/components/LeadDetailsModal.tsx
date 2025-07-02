
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";

interface Lead {
  id: string;
  cpf: string;
  nome: string;
  telefone: string;
  classe: "Quente" | "Frio";
  status: "Elegível" | "Inelegível";
  contratos: number;
  saldo: number;
  libera: number;
  dataAtualizacao: string;
  motivo: string;
  origem: string;
}

interface LeadDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  lead: Lead | null;
}

export const LeadDetailsModal = ({ isOpen, onClose, lead }: LeadDetailsModalProps) => {
  if (!lead) return null;

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold text-gray-900">
            Detalhes do Lead
          </DialogTitle>
        </DialogHeader>

        <div className="grid grid-cols-2 gap-6 py-4">
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-500">CPF</label>
              <p className="text-sm font-mono text-gray-900">{lead.cpf}</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Nome</label>
              <p className="text-sm text-gray-900 font-medium">{lead.nome}</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Telefone</label>
              <p className="text-sm font-mono text-gray-900">{lead.telefone}</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Classe</label>
              <span
                className={cn(
                  "inline-flex px-2 py-1 text-xs font-semibold rounded-full",
                  lead.classe === "Quente"
                    ? "bg-red-100 text-red-800"
                    : "bg-blue-100 text-blue-800"
                )}
              >
                {lead.classe}
              </span>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Status</label>
              <div className="flex items-center space-x-2">
                <span
                  className={cn(
                    "inline-flex px-2 py-1 text-xs font-semibold rounded-full",
                    lead.status === "Elegível"
                      ? "bg-green-100 text-green-800"
                      : "bg-gray-100 text-gray-800"
                  )}
                >
                  {lead.status}
                </span>
                <span className="text-xs text-gray-600">• {lead.motivo}</span>
              </div>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Origem dos Dados</label>
              <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">
                {lead.origem}
              </span>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-500">Saldo</label>
              <p className="text-sm text-gray-900 font-semibold">{formatCurrency(lead.saldo)}</p>
              <p className="text-xs text-gray-500 mt-1">Valor total de FGTS retornado pelo robô</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Libera</label>
              <p className="text-sm text-gray-900 font-semibold">{formatCurrency(lead.libera)}</p>
              <p className="text-xs text-gray-500 mt-1">Valor disponível para liberação</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Data de Atualização</label>
              <p className="text-sm text-gray-900">{lead.dataAtualizacao}</p>
            </div>

            <div>
              <label className="text-sm font-medium text-gray-500">Número de Contratos</label>
              <p className="text-sm text-gray-900 font-semibold">{lead.contratos}</p>
            </div>
          </div>
        </div>

        <div className="flex justify-end pt-4 border-t">
          <Button onClick={onClose} variant="outline">
            Fechar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};
