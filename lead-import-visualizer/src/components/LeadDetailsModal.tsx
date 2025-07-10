// Em src/components/LeadDetailsModal.tsx
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
// 1. Importar a interface compartilhada
import { ProcessedLead } from "./LeadsTable"; 

interface LeadDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  lead: ProcessedLead | null;
}

export const LeadDetailsModal = ({ isOpen, onClose, lead }: LeadDetailsModalProps) => {
  if (!lead) return null;

  // As funções locais de formatação foram removidas daqui.

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold text-gray-900">
            Detalhes do Lead
          </DialogTitle>
        </DialogHeader>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 py-4">
          {/* Coluna da Esquerda */}
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-500">CPF</label>
              {/* 2. Usar a função de formatação importada */}
              <p className="text-sm font-mono text-gray-900">{(lead.cpf)}</p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Nome</label>
              <p className="text-sm text-gray-900 font-medium">{lead.nome || '--'}</p>
            </div>
             <div>
              <label className="text-sm font-medium text-gray-500">Status</label>
              <div className="flex items-center space-x-2">
                <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", lead.status === "Elegível" ? "bg-green-100 text-green-800" : "bg-gray-100 text-gray-800")}>
                  {lead.status}
                </span>
                <span className="text-xs text-gray-600">• {lead.consulta || 'N/A'}</span>
              </div>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Origem dos Dados</label>
              <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">
                {lead.primeira_origem || '--'}
              </span>
            </div>
          </div>

          {/* Coluna da Direita */}
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-500">Saldo</label>
              <p className="text-sm text-gray-900 font-semibold">{(lead.saldo)}</p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Libera</label>
              <p className="text-sm text-gray-900 font-semibold">{(lead.libera)}</p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Data de Atualização</label>
              <p className="text-sm text-gray-900">{(lead.data_atualizacao)}</p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Número de Contratos</label>
              <p className="text-sm text-gray-900 font-semibold">{lead.contratos}</p>
            </div>
          </div>

          {/* Seção de Telefones */}
          {lead.telefones && lead.telefones.length > 0 && (
            <div className="md:col-span-2 pt-4 border-t">
              <label className="text-sm font-medium text-gray-500 mb-2 block">Telefones</label>
              <div className="space-y-2">
                {lead.telefones.map((tel, index) => (
                  <div key={index} className="flex items-center justify-between text-sm p-2 bg-gray-50 rounded-md">
                    <span className="font-mono text-gray-800">{tel.fone}</span>
                    <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", tel.classe === "Quente" ? "bg-red-100 text-red-800" : "bg-blue-100 text-blue-800")}>
                      {tel.classe || 'Frio'}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="pt-4 border-t">
          <Button onClick={onClose} variant="outline">
            Fechar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};