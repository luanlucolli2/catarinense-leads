// src/components/LeadsTable.tsx

import { useState, useMemo } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Eye,
  ChevronUp,
  ChevronDown,
  Phone,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { LeadDetailsModal } from "./LeadDetailsModal";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";

export interface Telefone {
  fone: string;
  classe: string | null;
}

export interface ProcessedLead {
  id: number;
  cpf: string;
  nome: string;
  data_nascimento: string;
  telefones: Telefone[];
  status: "Elegível" | "Inelegível";
  contratos: number;
  saldo: string;
  libera: string;
  data_atualizacao: string;
  consulta: string;
  primeira_origem: string;
  fgts_off_authorized: boolean | null;
  fgts_off_consultado_em: string; // string data formatada OU ""
}

type SortField =
  | "nome"
  | "cpf"
  | "status"
  | "saldo"
  | "libera"
  | "data_atualizacao"
  | "contratos"
  | "primeira_origem"
  | "fgts_off_authorized"
  | "fgts_off_consultado_em";
type SortDirection = "asc" | "desc";

interface LeadsTableProps {
  leads: ProcessedLead[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  isLoading: boolean;
}

/** Placeholder padrão para campos vazios */
const EMPTY = "—";

/** largura fixa da coluna de ações p/ alinhar header + body */
const ACTIONS_COL_WIDTH = "w-[110px] min-w-[110px] max-w-[110px]";

const SkeletonRow = () => (
  <tr className="hover:bg-gray-50">
    <td className="px-3 xl:px-6 py-4 text-left"><Skeleton className="h-4 w-28" /></td>
    <td className="px-3 xl:px-6 py-4 text-left"><Skeleton className="h-4 w-40" /></td>
    <td className="px-3 xl:px-6 py-4 text-left"><Skeleton className="h-4 w-32" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-4 w-20 mx-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-4 w-24 mx-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-right"><Skeleton className="h-4 w-20 ml-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-right"><Skeleton className="h-4 w-20 ml-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-4 w-24 mx-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-4 w-24 mx-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-4 w-24 mx-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-right"><Skeleton className="h-4 w-16 ml-auto" /></td>
    <td className="px-3 xl:px-6 py-4 text-center"><Skeleton className="h-8 w-20 mx-auto" /></td>
    {/* Ações sticky */}
    <td
      className={cn(
        "px-3 xl:px-6 py-4 text-center sticky right-0 z-20 bg-white border-l border-gray-200",
        ACTIONS_COL_WIDTH
      )}
    >
      <Skeleton className="h-8 w-12 mx-auto" />
    </td>
  </tr>
);

export const LeadsTable = ({
  leads,
  currentPage,
  totalPages,
  onPageChange,
  isLoading,
}: LeadsTableProps) => {
  const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [sortField, setSortField] = useState<SortField | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>("asc");

  const handleViewLead = (lead: ProcessedLead) => {
    setSelectedLeadId(lead.id);
    setIsModalOpen(true);
  };
  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLeadId(null);
  };
  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    } else {
      setSortField(field);
      setSortDirection("asc");
    }
  };

  const sortedLeads = useMemo(() => {
    if (!sortField) return leads;

    const valueForSort = (x: ProcessedLead) => {
      switch (sortField) {
        case "data_atualizacao":
          return x.data_atualizacao ? new Date(x.data_atualizacao).getTime() : Number.POSITIVE_INFINITY;
        case "fgts_off_consultado_em":
          return x.fgts_off_consultado_em ? new Date(x.fgts_off_consultado_em).getTime() : Number.POSITIVE_INFINITY;
        case "fgts_off_authorized":
          if (x.fgts_off_authorized === true) return 0;
          if (x.fgts_off_authorized === false) return 1;
          return 2;
        case "contratos":
          return x.contratos ?? -1;
        case "saldo":
        case "libera":
          return (x as any)[sortField] ?? "";
        default: {
          const v = (x as any)[sortField];
          if (typeof v === "string") return v.toLowerCase();
          return v ?? "";
        }
      }
    };

    const sorted = [...leads].sort((a, b) => {
      const av = valueForSort(a);
      const bv = valueForSort(b);
      if (av < bv) return -1;
      if (av > bv) return 1;
      return 0;
    });

    return sortDirection === "asc" ? sorted : sorted.reverse();
  }, [leads, sortField, sortDirection]);

  const SortButton = ({
    field,
    children,
    align = "left",
  }: {
    field: SortField;
    children: React.ReactNode;
    align?: "left" | "center" | "right";
  }) => (
    <button
      onClick={() => handleSort(field)}
      className={cn(
        "flex items-center gap-1 hover:bg-gray-100 px-2 py-1 rounded transition-colors duration-150 w-full",
        align === "center" && "justify-center",
        align === "right" && "justify-end"
      )}
    >
      <span>{children}</span>
      {sortField === field ? (
        sortDirection === "asc" ? (
          <ChevronUp className="w-3 h-3" />
        ) : (
          <ChevronDown className="w-3 h-3" />
        )
      ) : (
        <div className="w-3 h-3" />
      )}
    </button>
  );

  const display = (val?: string | number | null) =>
    val === undefined || val === null || val === "" ? EMPTY : val;

  const renderFgtsOffPill = (auth: boolean | null) => {
    if (auth === true) {
      return (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
          Autorizado
        </span>
      );
    }
    if (auth === false) {
      return (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-amber-500 text-white">
          Não autorizado
        </span>
      );
    }
    return (
      <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
        Sem dados
      </span>
    );
  };


  const renderNotConsultedBadge = () => (
    <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
      Não consultado
    </span>
  );

  const renderTableBody = () => {
    if (isLoading) {
      return Array.from({ length: 8 }).map((_, i) => <SkeletonRow key={i} />);
    }
    if (leads.length === 0) {
      return (
        <tr>
          <td colSpan={13} className="text-center py-12 text-gray-500">
            Nenhum lead encontrado com os filtros aplicados.
          </td>
        </tr>
      );
    }
    return sortedLeads.map((lead) => (
      <tr
        key={lead.id}
        className="group hover:bg-gray-50 transition-colors duration-150"
      >
        {/* CPF */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 align-middle text-left min-w-[110px]">
          {display(lead.cpf)}
        </td>

        {/* Nome */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium align-middle text-left max-w-[220px] truncate">
          {display(lead.nome)}
        </td>

        {/* Telefone principal + contador */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-left min-w-[120px]">
          {lead.telefones.length > 0 ? (
            <div className="flex items-center space-x-2">
              <span className="font-mono">{display(lead.telefones[0].fone)}</span>
              {lead.telefones.length > 1 && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button className="flex items-center text-xs bg-gray-200 text-gray-600 rounded-full px-2 py-0.5">
                        <Phone className="w-3 h-3 mr-1" />
                        +{lead.telefones.length - 1}
                      </button>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>Este lead possui mais telefones.</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
          ) : (
            EMPTY
          )}
        </td>

        {/* Classe (pill) */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[90px]">
          {lead.telefones[0]?.classe ? (
            <span
              className={cn(
                "inline-flex px-2 py-1 text-xs font-semibold rounded-full",
                lead.telefones[0]?.classe === "Quente"
                  ? "bg-red-100 text-red-800"
                  : "bg-blue-100 text-blue-800"
              )}
            >
              {lead.telefones[0]?.classe}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              {EMPTY}
            </span>
          )}
        </td>

        {/* Status + motivo */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[140px]">
          <div className="flex flex-col items-center space-y-1">
            <span
              className={cn(
                "inline-flex px-2 py-1 text-xs font-semibold rounded-full w-fit",
                lead.status === "Elegível"
                  ? "bg-green-100 text-green-800"
                  : "bg-gray-100 text-gray-800"
              )}
            >
              {display(lead.status)}
            </span>
            <span className="text-xs text-gray-500 truncate max-w-[140px] text-center">
              {display(lead.consulta)}
            </span>
          </div>
        </td>

        {/* Saldo / Libera (direita) */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[100px]">
          {display(lead.saldo)}
        </td>
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[100px]">
          {display(lead.libera)}
        </td>

        {/* Data hig. */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[120px]">
          {display(lead.data_atualizacao)}
        </td>

        {/* Autorizado (OFF) */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[160px]">
          {renderFgtsOffPill(lead.fgts_off_authorized)}
        </td>

        {/* Data consulta (OFF) */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[160px]">
          {lead.fgts_off_consultado_em ? (
            lead.fgts_off_consultado_em
          ) : (
            renderNotConsultedBadge()
          )}
        </td>

        {/* Contratos */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[80px]">
          {typeof lead.contratos === "number" ? lead.contratos : EMPTY}
        </td>

        {/* Origem (pill) */}
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[120px]">
          {lead.primeira_origem ? (
            <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[140px] truncate mx-auto">
              {lead.primeira_origem}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              {EMPTY}
            </span>
          )}
        </td>

        {/* Ações sticky */}
        {/* Ações sticky */}
        <td
          className={cn(
            "px-3 xl:px-6 py-4 whitespace-nowrap align-middle sticky right-0 z-20 bg-white group-hover:bg-gray-50 border-l border-gray-200",
            ACTIONS_COL_WIDTH
          )}
        >
          <div className="flex justify-center">
            <Button
              onClick={() => handleViewLead(lead)}
              variant="outline"
              size="sm"
              className="flex items-center space-x-1"
            >
              <Eye className="w-4 h-4" />
              <span className="hidden xl:inline">Ver</span>
            </Button>
          </div>
        </td>

      </tr>
    ));
  };

  return (
    <>
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden w-full max-w-full">
        {/* Desktop Table */}
        <div className="hidden lg:block w-full max-w-full">
          <div className="overflow-x-auto relative max-w-full">
            <table className="w-full min-w-[1350px]">
              <thead className="bg-gray-50 sticky top-0 z-30">
                <tr>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[110px] text-left">
                    <SortButton field="cpf" align="left">CPF</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[140px] text-left">
                    <SortButton field="nome" align="left">Nome</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[120px] text-left">
                    Telefone
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[90px] text-center">
                    Classe
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[140px] text-center">
                    <SortButton field="status" align="center">Status</SortButton>
                  </th>

                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[100px] text-right">
                    <SortButton field="saldo" align="right">Saldo</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[100px] text-right">
                    <SortButton field="libera" align="right">Libera</SortButton>
                  </th>

                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[120px] text-center">
                    <SortButton field="data_atualizacao" align="center">Data hig.</SortButton>
                  </th>

                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[160px] text-center">
                    <SortButton field="fgts_off_authorized" align="center">Autorizado (FGTS OFF)</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[160px] text-center">
                    <SortButton field="fgts_off_consultado_em" align="center">Data consulta (FGTS OFF)</SortButton>
                  </th>

                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[80px] text-right">
                    <SortButton field="contratos" align="right">Contratos</SortButton>
                  </th>
                  <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[120px] text-center">
                    <SortButton field="primeira_origem" align="center">Origem</SortButton>
                  </th>

                  {/* Header Ações sticky */}
                  <th
                    className={cn(
                      "px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider text-center sticky right-0 z-40 bg-gray-50 border-l border-gray-200",
                      ACTIONS_COL_WIDTH
                    )}
                  >
                    Ações
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {renderTableBody()}
              </tbody>
            </table>
          </div>
        </div>

        {/* Mobile/Tablet Cards */}
        <div className="lg:hidden space-y-4 p-4 max-w-full">
          {sortedLeads.map((lead) => (
            <div
              key={lead.id}
              className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full"
            >
              <div className="flex justify-between items-start">
                <div className="flex-1 min-w-0">
                  <h3 className="font-medium text-gray-900 truncate">
                    {display(lead.nome)}
                  </h3>
                  <p className="text-sm font-mono text-gray-600 truncate">
                    {display(lead.cpf)}
                  </p>
                  <p className="text-sm font-mono text-gray-600 truncate">
                    {lead.telefones[0]?.fone || EMPTY}
                  </p>
                </div>
                <Button
                  onClick={() => handleViewLead(lead)}
                  variant="outline"
                  size="sm"
                  className="flex items-center space-x-1 ml-2 flex-shrink-0"
                >
                  <Eye className="w-4 h-4" />
                  <span>Ver</span>
                </Button>
              </div>

              <div className="flex items-center justify-between flex-wrap gap-2">
                <div className="flex items-center space-x-2 flex-wrap">
                  {lead.telefones[0]?.classe ? (
                    <span
                      className={cn(
                        "inline-flex px-2 py-1 text-xs font-semibold rounded-full",
                        lead.telefones[0]?.classe === "Quente"
                          ? "bg-red-100 text-red-800"
                          : "bg-blue-100 text-blue-800"
                      )}
                    >
                      {lead.telefones[0]?.classe}
                    </span>
                  ) : (
                    <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
                      {EMPTY}
                    </span>
                  )}
                  <span
                    className={cn(
                      "inline-flex px-2 py-1 text-xs font-semibold rounded-full",
                      lead.status === "Elegível"
                        ? "bg-green-100 text-green-800"
                        : "bg-gray-100 text-gray-800"
                    )}
                  >
                    {display(lead.status)}
                  </span>
                  {lead.primeira_origem ? (
                    <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full truncate max-w-[140px]">
                      {lead.primeira_origem}
                    </span>
                  ) : (
                    <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
                      {EMPTY}
                    </span>
                  )}
                </div>
                <span className="text-xs text-gray-500">
                  {typeof lead.contratos === "number" ? lead.contratos : EMPTY} contratos
                </span>
              </div>

              <div className="flex items-center justify-between text-xs">
                <div className="space-x-2 flex items-center">
                  <span className="text-gray-500">Autorizado (OFF):</span>
                  {renderFgtsOffPill(lead.fgts_off_authorized)}
                </div>
                <div className="flex items-center gap-1">
                  <span className="text-gray-500">Consulta (OFF):</span>
                  {lead.fgts_off_consultado_em
                    ? <span>{lead.fgts_off_consultado_em}</span>
                    : renderNotConsultedBadge()}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="text-gray-500">Saldo:</span>
                  <p className="font-semibold truncate">{display(lead.saldo)}</p>
                </div>
                <div>
                  <span className="text-gray-500">Libera:</span>
                  <p className="font-semibold truncate">{display(lead.libera)}</p>
                </div>
              </div>

              <div className="flex justify-between items-center text-xs text-gray-500 pt-2 border-t">
                <span>Data hig.: {display(lead.data_atualizacao)}</span>
                <span className="truncate">{display(lead.consulta)}</span>
              </div>
            </div>
          ))}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
          <div className="text-sm text-gray-500">
            Página {currentPage} de {totalPages}
          </div>
          <div className="flex items-center space-x-2">
            <Button
              onClick={() => onPageChange(currentPage - 1)}
              disabled={currentPage === 1 || isLoading}
              variant="outline"
              size="sm"
            >
              <ChevronLeft className="w-4 h-4" />
              <span className="sr-only">Anterior</span>
            </Button>
            <Button
              onClick={() => onPageChange(currentPage + 1)}
              disabled={currentPage === totalPages || isLoading}
              variant="outline"
              size="sm"
            >
              <ChevronRight className="w-4 h-4" />
              <span className="sr-only">Próxima</span>
            </Button>
          </div>
        </div>
      </div>

      <LeadDetailsModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        leadId={selectedLeadId}
      />
    </>
  );
};
