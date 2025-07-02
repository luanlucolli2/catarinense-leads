
import { useState } from "react";
import { ChevronLeft, ChevronRight, Eye, ChevronUp, ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { LeadDetailsModal } from "./LeadDetailsModal";

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

type SortField = 'nome' | 'cpf' | 'telefone' | 'classe' | 'status' | 'saldo' | 'libera' | 'dataAtualizacao' | 'contratos' | 'origem';
type SortDirection = 'asc' | 'desc';

interface LeadsTableProps {
  leads: Lead[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
}

export const LeadsTable = ({ leads, currentPage, totalPages, onPageChange }: LeadsTableProps) => {
  const [selectedLead, setSelectedLead] = useState<Lead | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [sortField, setSortField] = useState<SortField | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');

  const handleViewLead = (lead: Lead) => {
    setSelectedLead(lead);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLead(null);
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(value);
  };

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const sortedLeads = [...leads].sort((a, b) => {
    if (!sortField) return 0;

    let aValue: any = a[sortField];
    let bValue: any = b[sortField];

    // Handle date sorting
    if (sortField === 'dataAtualizacao') {
      const [dayA, monthA, yearA] = aValue.split('/');
      const [dayB, monthB, yearB] = bValue.split('/');
      aValue = new Date(parseInt(yearA), parseInt(monthA) - 1, parseInt(dayA));
      bValue = new Date(parseInt(yearB), parseInt(monthB) - 1, parseInt(dayB));
    }

    // Handle string sorting
    if (typeof aValue === 'string' && typeof bValue === 'string') {
      aValue = aValue.toLowerCase();
      bValue = bValue.toLowerCase();
    }

    if (aValue < bValue) {
      return sortDirection === 'asc' ? -1 : 1;
    }
    if (aValue > bValue) {
      return sortDirection === 'asc' ? 1 : -1;
    }
    return 0;
  });

  const SortButton = ({ field, children }: { field: SortField; children: React.ReactNode }) => (
    <button
      onClick={() => handleSort(field)}
      className="flex items-center space-x-1 hover:bg-gray-100 px-2 py-1 rounded transition-colors duration-150"
    >
      <span>{children}</span>
      {sortField === field ? (
        sortDirection === 'asc' ? (
          <ChevronUp className="w-3 h-3" />
        ) : (
          <ChevronDown className="w-3 h-3" />
        )
      ) : (
        <div className="w-3 h-3" />
      )}
    </button>
  );

  return (
    <>
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden w-full max-w-full">
        {/* Desktop Table */}
        <div className="hidden lg:block w-full max-w-full">
          <div className="overflow-x-auto max-w-full">
            <table className="w-full min-w-[1200px]">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[110px]">
                    <SortButton field="cpf">CPF</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[140px]">
                    <SortButton field="nome">Nome</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[120px]">
                    <SortButton field="telefone">Telefone</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">
                    <SortButton field="classe">Classe</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[140px]">
                    <SortButton field="status">Status</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                    <SortButton field="saldo">Saldo</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                    <SortButton field="libera">Libera</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                    <SortButton field="dataAtualizacao">Atualização</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">
                    <SortButton field="contratos">Contratos</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[100px]">
                    <SortButton field="origem">Origem</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[80px]">
                    Ações
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {sortedLeads.map((lead) => (
                  <tr key={lead.id} className="hover:bg-gray-50 transition-colors duration-150">
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 align-top">
                      {lead.cpf}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium max-w-[200px] truncate align-top">
                      {lead.nome}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono align-top">
                      {lead.telefone}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
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
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
                      <div className="flex flex-col space-y-1">
                        <span
                          className={cn(
                            "inline-flex px-2 py-1 text-xs font-semibold rounded-full w-fit",
                            lead.status === "Elegível"
                              ? "bg-green-100 text-green-800"
                              : "bg-gray-100 text-gray-800"
                          )}
                        >
                          {lead.status}
                        </span>
                        <span className="text-xs text-gray-500 truncate max-w-[120px]">{lead.motivo}</span>
                      </div>
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">
                      {formatCurrency(lead.saldo)}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">
                      {formatCurrency(lead.libera)}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-top">
                      {lead.dataAtualizacao}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-top">
                      {lead.contratos}
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-top">
                      <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[100px] truncate">
                        {lead.origem}
                      </span>
                    </td>
                    <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-top">
                      <Button
                        onClick={() => handleViewLead(lead)}
                        variant="outline"
                        size="sm"
                        className="flex items-center space-x-1"
                      >
                        <Eye className="w-4 h-4" />
                        <span className="hidden xl:inline">Ver</span>
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Mobile/Tablet Cards */}
        <div className="lg:hidden space-y-4 p-4 max-w-full">
          {sortedLeads.map((lead) => (
            <div key={lead.id} className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full">
              {/* Header */}
              <div className="flex justify-between items-start min-w-0">
                <div className="min-w-0 flex-1">
                  <h3 className="font-medium text-gray-900 truncate">{lead.nome}</h3>
                  <p className="text-sm font-mono text-gray-600 truncate">{lead.cpf}</p>
                  <p className="text-sm font-mono text-gray-600 truncate">{lead.telefone}</p>
                </div>
                <Button
                  onClick={() => handleViewLead(lead)}
                  variant="outline"
                  size="sm"
                  className="flex items-center space-x-1 flex-shrink-0 ml-2"
                >
                  <Eye className="w-4 h-4" />
                  <span>Ver</span>
                </Button>
              </div>

              {/* Status and Class */}
              <div className="flex items-center justify-between flex-wrap gap-2 min-w-0">
                <div className="flex items-center space-x-2 flex-wrap min-w-0">
                  <span
                    className={cn(
                      "inline-flex px-2 py-1 text-xs font-semibold rounded-full flex-shrink-0",
                      lead.classe === "Quente"
                        ? "bg-red-100 text-red-800"
                        : "bg-blue-100 text-blue-800"
                    )}
                  >
                    {lead.classe}
                  </span>
                  <span
                    className={cn(
                      "inline-flex px-2 py-1 text-xs font-semibold rounded-full flex-shrink-0",
                      lead.status === "Elegível"
                        ? "bg-green-100 text-green-800"
                        : "bg-gray-100 text-gray-800"
                    )}
                  >
                    {lead.status}
                  </span>
                  <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full flex-shrink-0 max-w-[120px] truncate">
                    {lead.origem}
                  </span>
                </div>
                <span className="text-xs text-gray-500 flex-shrink-0">{lead.contratos} contratos</span>
              </div>

              {/* Financial Info */}
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div className="min-w-0">
                  <span className="text-gray-500">Saldo:</span>
                  <p className="font-semibold truncate">{formatCurrency(lead.saldo)}</p>
                </div>
                <div className="min-w-0">
                  <span className="text-gray-500">Libera:</span>
                  <p className="font-semibold truncate">{formatCurrency(lead.libera)}</p>
                </div>
              </div>

              {/* Bottom Info */}
              <div className="flex justify-between items-center text-xs text-gray-500 pt-2 border-t min-w-0">
                <span className="flex-shrink-0">Atualizado: {lead.dataAtualizacao}</span>
                <span className="truncate ml-2 min-w-0">{lead.motivo}</span>
              </div>
            </div>
          ))}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between max-w-full">
          <div className="text-sm text-gray-500 flex-shrink-0">
            Página {currentPage} de {totalPages}
          </div>
          <div className="flex items-center space-x-2 flex-shrink-0">
            <button
              onClick={() => onPageChange(currentPage - 1)}
              disabled={currentPage === 1}
              className="p-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={() => onPageChange(currentPage + 1)}
              disabled={currentPage === totalPages}
              className="p-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <LeadDetailsModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        lead={selectedLead}
      />
    </>
  );
};
