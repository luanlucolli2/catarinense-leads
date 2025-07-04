import { useState, useMemo } from "react";
import { ChevronLeft, ChevronRight, Eye, ChevronUp, ChevronDown, Phone } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { LeadDetailsModal } from "./LeadDetailsModal";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

// Definindo a "fonte da verdade" para a estrutura de um Lead no frontend.
export interface Telefone {
  fone: string; // Já vem formatado
  classe: string | null;
}

export interface ProcessedLead {
  id: number;
  cpf: string; // Já vem formatado
  nome: string;
  data_nascimento: string; // Já vem formatado
  telefones: Telefone[];
  status: "Elegível" | "Inelegível";
  contratos: number;
  saldo: string; // Já vem formatado
  libera: string; // Já vem formatado
  data_atualizacao: string; // Já vem formatado
  consulta: string;
  origem_cadastro: string;
}

type SortField = 'nome' | 'cpf' | 'status' | 'saldo' | 'libera' | 'data_atualizacao' | 'contratos' | 'origem_cadastro';
type SortDirection = 'asc' | 'desc';

interface LeadsTableProps {
  leads: ProcessedLead[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  isLoading: boolean;
}

const SkeletonRow = () => (
  <tr className="hover:bg-gray-50">
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-28" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-40" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-32" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-24" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-24" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-20" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-20" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-24" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-16" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-4 w-24" /></td>
    <td className="px-3 xl:px-6 py-4"><Skeleton className="h-8 w-12" /></td>
  </tr>
);

export const LeadsTable = ({ leads, currentPage, totalPages, onPageChange, isLoading }: LeadsTableProps) => {
  const [selectedLead, setSelectedLead] = useState<ProcessedLead | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [sortField, setSortField] = useState<SortField | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');

  const handleViewLead = (lead: ProcessedLead) => {
    setSelectedLead(lead);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLead(null);
  };

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const sortedLeads = useMemo(() => {
    if (!sortField) return leads;

    return [...leads].sort((a, b) => {
      let aValue: any = a[sortField];
      let bValue: any = b[sortField];

      if (aValue === null || aValue === undefined) return 1;
      if (bValue === null || bValue === undefined) return -1;

      if (sortField === 'data_atualizacao') {
        aValue = new Date(aValue);
        bValue = new Date(bValue);
      }

      if (typeof aValue === 'string') aValue = aValue.toLowerCase();
      if (typeof bValue === 'string') bValue = bValue.toLowerCase();

      if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
      if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });
  }, [leads, sortField, sortDirection]);

  const SortButton = ({ field, children }: { field: SortField; children: React.ReactNode }) => (
    <button onClick={() => handleSort(field)} className="flex items-center space-x-1 hover:bg-gray-100 px-2 py-1 rounded transition-colors duration-150">
      <span>{children}</span>
      {sortField === field ? (sortDirection === 'asc' ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />) : (<div className="w-3 h-3" />)}
    </button>
  );

  const renderTableBody = () => {
    if (isLoading) {
      return Array.from({ length: 8 }).map((_, index) => <SkeletonRow key={index} />);
    }
    if (leads.length === 0) {
      return (
        <tr>
          <td colSpan={11} className="text-center py-12 text-gray-500">
            Nenhum lead encontrado com os filtros aplicados.
          </td>
        </tr>
      );
    }
    return sortedLeads.map((lead) => (
      <tr key={lead.id} className="hover:bg-gray-50 transition-colors duration-150">
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 align-top">{lead.cpf || '--'}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium max-w-[200px] truncate align-top">{lead.nome || '--'}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono align-top">
          {lead.telefones.length > 0 ? (
            <div className="flex items-center space-x-2">
              <span>{lead.telefones[0].fone}</span>
              {lead.telefones.length > 1 && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button className="flex items-center text-xs bg-gray-200 text-gray-600 rounded-full px-2 py-0.5 cursor-pointer">
                        <Phone className="w-3 h-3 mr-1" /> +{lead.telefones.length - 1}
                      </button>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>Este lead possui mais telefones.</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
          ) : '--'}
        </td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
          <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", lead.telefones[0]?.classe === "Quente" ? "bg-red-100 text-red-800" : "bg-blue-100 text-blue-800")}>
            {lead.telefones[0]?.classe || 'Frio'}
          </span>
        </td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
          <div className="flex flex-col space-y-1">
            <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full w-fit", lead.status === "Elegível" ? "bg-green-100 text-green-800" : "bg-gray-100 text-gray-800")}>
              {lead.status}
            </span>
            <span className="text-xs text-gray-500 truncate max-w-[120px]">{lead.consulta || '--'}</span>
          </div>
        </td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">{(lead.saldo)}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">{(lead.libera)}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-top">{(lead.data_atualizacao)}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">{lead.contratos}</td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-top">
          <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[100px] truncate">
            {lead.origem_cadastro}
          </span>
        </td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
          <Button onClick={() => handleViewLead(lead)} variant="outline" size="sm" className="flex items-center space-x-1">
            <Eye className="w-4 h-4" />
            <span className="hidden xl:inline">Ver</span>
          </Button>
        </td>
      </tr>
    ));
  };

  return (
    <>
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden w-full max-w-full">
        <div className="overflow-x-auto max-w-full">
          <table className="w-full min-w-[1200px]">
            <thead className="bg-gray-50 sticky top-0">
              <tr>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[110px]"><SortButton field="cpf">CPF</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[140px]"><SortButton field="nome">Nome</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[120px]">Telefone</th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">Classe</th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[140px]"><SortButton field="status">Status</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]"><SortButton field="saldo">Saldo</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]"><SortButton field="libera">Libera</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]"><SortButton field="data_atualizacao">Atualização</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]"><SortButton field="contratos">Contratos</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]"><SortButton field="origem_cadastro">Origem</SortButton></th>
                <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">Ações</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {renderTableBody()}
            </tbody>
          </table>
        </div>
      </div>

      <div className="bg-white px-4 lg:px-6 py-3 border-t border-b border-x border-gray-200 rounded-b-lg flex items-center justify-between max-w-full">
        <div className="text-sm text-gray-500 flex-shrink-0">
          Página {currentPage} de {totalPages}
        </div>
        <div className="flex items-center space-x-2 flex-shrink-0">
          <Button onClick={() => onPageChange(currentPage - 1)} disabled={currentPage === 1 || isLoading} variant="outline" size="sm">
            <ChevronLeft className="w-4 h-4" />
            <span className="sr-only">Anterior</span>
          </Button>
          <Button onClick={() => onPageChange(currentPage + 1)} disabled={currentPage === totalPages || isLoading} variant="outline" size="sm">
            <ChevronRight className="w-4 h-4" />
            <span className="sr-only">Próxima</span>
          </Button>
        </div>
      </div>

      {selectedLead && (
        <LeadDetailsModal
          isOpen={isModalOpen}
          onClose={handleCloseModal}
          lead={selectedLead}
        />
      )}
    </>
  );
};