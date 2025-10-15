import React, { useState, useMemo } from "react";
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

/* =========================================================
 * FGTS TABLE
 * =======================================================*/
export interface ProcessedLeadFGTS {
  id: number;
  cpf: string;
  nome: string;
  data_nascimento: string;
  telefones: Telefone[];
  contratos: number;
  saldo: string;
  libera: string;
  data_atualizacao: string;
  consulta: string;
  ultima_origem_cadastral: string;
  ultima_origem_higienizacao: string;
  fgts_off_authorized: boolean | null;
  fgts_off_consultado_em: string; // string data formatada OU ""
}

type SortFieldFGTS =
  | "nome"
  | "cpf"
  | "consulta"
  | "saldo"
  | "libera"
  | "data_atualizacao"
  | "data_nascimento"
  | "contratos"
  | "ultima_origem_cadastral"
  | "ultima_origem_higienizacao"
  | "fgts_off_authorized"
  | "fgts_off_consultado_em";
type SortDirection = "asc" | "desc";

interface LeadsTableFGTSProps {
  leads: ProcessedLeadFGTS[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  isLoading: boolean;
  /** Lista de IDs de colunas visíveis (persistido no localStorage) */
  visibleColumns: string[];
}

const EMPTY = "--";
const ACTIONS_COL_WIDTH = "w-[110px] min-w-[110px] max-w-[110px]";

const getClasseBadge = (raw?: string | null) => {
  if (!raw) {
    return { label: EMPTY, cls: "bg-gray-100 text-gray-700" };
  }
  const norm = raw.toString().toLowerCase().replace(/[_\s]+/g, " ").trim();
  if (norm === "carteira") {
    return { label: "Carteira", cls: "bg-amber-100 text-amber-800" };
  }
  if (norm === "atendimento ia") {
    return { label: "Atendimento IA", cls: "bg-sky-100 text-sky-800" };
  }
  return { label: raw, cls: "bg-blue-100 text-blue-800" };
};

/** ===== Helper: visibilidade ===== */
const useColVisibility = (ids: string[]) => {
  const set = new Set(ids);
  const isVisible = (id: string) => set.has(id);
  return { isVisible };
};

/** ===== Skeleton célula padrão ===== */
const SkeletonCell = ({
  align = "left",
  w = "w-24",
}: {
  align?: "left" | "center" | "right";
  w?: string;
}) => (
  <td
    className={cn(
      "px-3 xl:px-6 py-4",
      align === "center" ? "text-center" : align === "right" ? "text-right" : "text-left"
    )}
  >
    <Skeleton
      className={cn("h-4", w, align === "center" && "mx-auto", align === "right" && "ml-auto")}
    />
  </td>
);

export const LeadsTableFGTS = ({
  leads,
  currentPage,
  totalPages,
  onPageChange,
  isLoading,
  visibleColumns,
}: LeadsTableFGTSProps) => {
  const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [sortField, setSortField] = useState<SortFieldFGTS | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>("asc");
  const { isVisible } = useColVisibility(visibleColumns);

  const handleViewLead = (lead: ProcessedLeadFGTS) => {
    setSelectedLeadId(lead.id);
    setIsModalOpen(true);
  };
  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLeadId(null);
  };
  const handleSort = (field: SortFieldFGTS) => {
    if (sortField === field) {
      setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    } else {
      setSortField(field);
      setSortDirection("asc");
    }
  };

  const sortedLeads = useMemo(() => {
    if (!sortField) return leads;

    const valueForSort = (x: ProcessedLeadFGTS) => {
      switch (sortField) {
        case "data_atualizacao":
        case "data_nascimento":
          return (x as any)[sortField] ? new Date((x as any)[sortField]).getTime() : Number.POSITIVE_INFINITY;
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
    field: SortFieldFGTS;
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
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-emerald-400 text-white">
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

  /** ===== catálogo de colunas FGTS ===== */
  const cols = [
    {
      id: "cpf",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[96px] text-left">
          <SortButton field="cpf" align="left">CPF</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 align-middle text-left min-w-[96px]">
          {display(lead.cpf)}
        </td>
      ),
    },
    {
      id: "nome",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[140px] text-left">
          <SortButton field="nome" align="left">Nome</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium align-middle text-left max-w-[220px] truncate">
          {display(lead.nome)}
        </td>
      ),
    },
    {
      id: "data_nascimento",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[110px] text-center">
          <SortButton field="data_nascimento" align="center">Data nasc.</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[110px]">
          {display(lead.data_nascimento)}
        </td>
      ),
    },
    {
      id: "telefone",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[110px] text-left">
          Telefone
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-left min-w-[110px]">
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
      ),
    },
    {
      id: "classe",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[84px] text-center">
          Classe
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => {
        const classeInfo = getClasseBadge(lead.telefones[0]?.classe);
        return (
          <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[84px]">
            <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", classeInfo.cls)}>
              {classeInfo.label}
            </span>
          </td>
        );
      },
    },
    {
      id: "consulta",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[150px] text-center">
          <SortButton field="consulta" align="center">Motivo</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[150px]">
          <span className="text-xs text-gray-600 truncate max-w-[150px] inline-block">
            {display(lead.consulta)}
          </span>
        </td>
      ),
    },
    {
      id: "saldo",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[96px] text-right">
          <SortButton field="saldo" align="right">Saldo</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[96px]">
          {display(lead.saldo)}
        </td>
      ),
    },
    {
      id: "libera",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[96px] text-right">
          <SortButton field="libera" align="right">Libera</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[96px]">
          {display(lead.libera)}
        </td>
      ),
    },
    {
      id: "data_atualizacao",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[110px] text-center">
          <SortButton field="data_atualizacao" align="center">Data hig.</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[110px]">
          {display(lead.data_atualizacao)}
        </td>
      ),
    },
    {
      id: "fgts_off_authorized",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[150px] text-center">
          <SortButton field="fgts_off_authorized" align="center">Autorizado (FGTS OFF)</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[150px]">
          {renderFgtsOffPill(lead.fgts_off_authorized)}
        </td>
      ),
    },
    {
      id: "fgts_off_consultado_em",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[150px] text-center">
          <SortButton field="fgts_off_consultado_em" align="center">Data consulta (FGTS OFF)</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[150px]">
          {lead.fgts_off_consultado_em ? lead.fgts_off_consultado_em : renderNotConsultedBadge()}
        </td>
      ),
    },
    {
      id: "contratos",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[72px] text-right">
          <SortButton field="contratos" align="right">Contratos</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[72px]">
          {typeof lead.contratos === "number" ? lead.contratos : EMPTY}
        </td>
      ),
    },
    {
      id: "ultima_origem_cadastral",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[130px] text-center">
          <SortButton field="ultima_origem_cadastral" align="center">Última origem (cad.)</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[130px]">
          {lead.ultima_origem_cadastral ? (
            <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[160px] truncate mx-auto">
              {lead.ultima_origem_cadastral}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              {EMPTY}
            </span>
          )}
        </td>
      ),
    },
    {
      id: "ultima_origem_higienizacao",
      header: (
        <th className="px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider min-w-[130px] text-center">
          <SortButton field="ultima_origem_higienizacao" align="center">Última origem (hig.)</SortButton>
        </th>
      ),
      cell: (lead: ProcessedLeadFGTS) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[130px]">
          {lead.ultima_origem_higienizacao ? (
            <span className="inline-flex px-2 py-1 text-xs font-medium bg-violet-100 text-violet-800 rounded-full max-w-[160px] truncate mx-auto">
              {lead.ultima_origem_higienizacao}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              {EMPTY}
            </span>
          )}
        </td>
      ),
    },
  ] as const;

  const visibleCols = cols.filter((c) => isVisible(c.id));
  const headerColCount = visibleCols.length + 1; // + Ações

  // ===== largura mínima dinâmica (FGTS) =====
  const tableMinWidthFGTS = useMemo(() => {
    const colsCount = headerColCount; // já inclui Ações
    if (colsCount <= 3) return "min-w-[520px]";
    if (colsCount <= 6) return "min-w-[900px]";
    if (colsCount <= 10) return "min-w-[1200px]";
    return "min-w-[1600px]";
  }, [headerColCount]);

  const renderSkeleton = (key: number) => (
    <tr className="hover:bg-gray-50" key={key}>
      {visibleCols.map((c, idx) => {
        if (c.id === "cpf") return <SkeletonCell key={idx} w="w-28" align="left" />;
        if (c.id === "nome") return <SkeletonCell key={idx} w="w-40" align="left" />;
        if (c.id === "data_nascimento" || c.id === "data_atualizacao" || c.id === "fgts_off_consultado_em")
          return <SkeletonCell key={idx} w="w-24" align="center" />;
        if (
          c.id === "classe" ||
          c.id === "consulta" ||
          c.id === "ultima_origem_cadastral" ||
          c.id === "ultima_origem_higienizacao" ||
          c.id === "fgts_off_authorized"
        )
          return <SkeletonCell key={idx} w="w-24" align="center" />;
        if (c.id === "saldo" || c.id === "libera" || c.id === "contratos")
          return <SkeletonCell key={idx} w="w-16" align="right" />;
        return <SkeletonCell key={idx} w="w-32" align="left" />;
      })}
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

  const renderTableBody = () => {
    if (isLoading) {
      return Array.from({ length: 8 }).map((_, i) => renderSkeleton(i));
    }
    if (leads.length === 0) {
      return (
        <tr>
          <td colSpan={headerColCount} className="text-center py-12 text-gray-500">
            Nenhum lead encontrado com os filtros aplicados.
          </td>
        </tr>
      );
    }
    return sortedLeads.map((lead) => (
      <tr key={lead.id} className="group hover:bg-gray-50 transition-colors duration-150">
        {visibleCols.map((c, idx) => (
          <React.Fragment key={`${lead.id}-${c.id}-${idx}`}>{c.cell(lead)}</React.Fragment>
        ))}
        {/* Ações */}
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
            <table className={cn("w-full", tableMinWidthFGTS)}>
              <thead className="bg-gray-50 sticky top-0 z-30">
                <tr>
                  {visibleCols.map((c) => React.cloneElement(c.header, { key: c.id }))}
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
              <tbody className="bg-white divide-y divide-gray-200">{renderTableBody()}</tbody>
            </table>
          </div>
        </div>

        {/* Mobile/Tablet Cards (somente em telas < lg) */}
        <div className="lg:hidden space-y-4 p-4 max-w-full">
          {leads.map((lead) => {
            const classeInfo = getClasseBadge(lead.telefones[0]?.classe);
            return (
              <div key={lead.id} className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full">
                <div className="flex justify-between items-start">
                  <div className="flex-1 min-w-0">
                    <h3 className="font-medium text-gray-900 truncate">{lead.nome || EMPTY}</h3>
                    <p className="text-sm font-mono text-gray-600 truncate">{lead.cpf || EMPTY}</p>
                    <p className="text-xs text-gray-600">Data nasc.: {display(lead.data_nascimento)}</p>
                    <p className="text-sm font-mono text-gray-600 truncate">{lead.telefones[0]?.fone || EMPTY}</p>
                    <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full mt-1", classeInfo.cls)}>
                      {classeInfo.label}
                    </span>
                  </div>
                  <Button
                    onClick={() => {
                      setSelectedLeadId(lead.id);
                      setIsModalOpen(true);
                    }}
                    variant="outline"
                    size="sm"
                    className="flex items-center space-x-1 ml-2 flex-shrink-0"
                  >
                    <Eye className="w-4 h-4" />
                    <span>Ver</span>
                  </Button>
                </div>
              </div>
            );
          })}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
          <div className="text-sm text-gray-500">Página {currentPage} de {totalPages}</div>
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

      <LeadDetailsModal isOpen={isModalOpen} onClose={handleCloseModal} leadId={selectedLeadId} />
    </>
  );
};

/* =========================================================
 * CLT TABLE
 * =======================================================*/
export interface ProcessedLeadCLT {
  id: number;
  cpf: string;
  nome: string;
  data_nascimento: string;
  telefones: Telefone[];
  ultima_origem_cadastral: string;

  elegivel: boolean | null;
  not_found: boolean;
  clt_consultado_em: string;

  idade: number | null;
  sexo: string | null;
  data_admissao: string;
  meses_admissao: number | null;

  valor_renda: string;
  valor_base_margem: string;
  margem_disponivel: string;
  valor_max_prestacao: string;

  categoria_trabalhador_codigo: string;
  inicio_atividade_empregador: string;

  qtd_emprestimos_ativos_suspensos: number | null;
  emprestimos_legados: number | null;
}

type SortFieldCLT =
  | "cpf"
  | "nome"
  | "data_nascimento"
  | "idade"
  | "sexo"
  | "ultima_origem_cadastral"
  | "elegivel"
  | "clt_consultado_em"
  | "data_admissao"
  | "meses_admissao"
  | "categoria_trabalhador_codigo"
  | "valor_renda"
  | "valor_base_margem"
  | "margem_disponivel"
  | "valor_max_prestacao"
  | "qtd_emprestimos_ativos_suspensos"
  | "emprestimos_legados";

interface LeadsTableCLTProps {
  leads: ProcessedLeadCLT[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  isLoading: boolean;
  /** Lista de IDs de colunas visíveis (persistido no localStorage) */
  visibleColumns: string[];
}

export const LeadsTableCLT = ({
  leads,
  currentPage,
  totalPages,
  onPageChange,
  isLoading,
  visibleColumns,
}: LeadsTableCLTProps) => {
  const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [sortField, setSortField] = useState<SortFieldCLT | null>(null);
  const [sortDirection, setSortDirection] = useState<SortDirection>("asc");
  const { isVisible } = useColVisibility(visibleColumns);

  const handleViewLead = (lead: ProcessedLeadCLT) => {
    setSelectedLeadId(lead.id);
    setIsModalOpen(true);
  };
  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLeadId(null);
  };
  const handleSort = (field: SortFieldCLT) => {
    if (sortField === field) {
      setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    } else {
      setSortField(field);
      setSortDirection("asc");
    }
  };

  const valueForSort = (x: ProcessedLeadCLT, field: SortFieldCLT) => {
    switch (field) {
      case "clt_consultado_em":
      case "data_admissao":
      case "data_nascimento":
        return (x as any)[field] ? new Date((x as any)[field]).getTime() : Number.POSITIVE_INFINITY;
      case "elegivel":
        return x.elegivel === true ? 0 : x.elegivel === false ? 1 : 2;
      case "idade":
      case "meses_admissao":
      case "qtd_emprestimos_ativos_suspensos":
      case "emprestimos_legados":
        return (x as any)[field] ?? -1;
      default: {
        const v = (x as any)[field];
        if (typeof v === "string") return v.toLowerCase();
        return v ?? "";
      }
    }
  };

  const sortedLeads = useMemo(() => {
    if (!sortField) return leads;
    const sorted = [...leads].sort((a, b) => {
      const av = valueForSort(a, sortField);
      const bv = valueForSort(b, sortField);
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
    field: SortFieldCLT;
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

  const renderElegivelPill = (elegivel: boolean | null, notFound: boolean) => {
    if (notFound) {
      return (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
          Não encontrado
        </span>
      );
    }
    if (elegivel === true) {
      return (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-emerald-500 text-white">
          Elegível
        </span>
      );
    }
    if (elegivel === false) {
      return (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-rose-500 text-white">
          Não elegível
        </span>
      );
    }
    return (
      <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
        Sem dados
      </span>
    );
  };

  /** ===== catálogo de colunas CLT ===== */
  const cols = [
    {
      id: "cpf",
      header: <Th><SortButton field="cpf">CPF</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 text-left min-w-[96px]">
          {display(lead.cpf)}
        </td>
      ),
    },
    {
      id: "nome",
      header: <Th><SortButton field="nome">Nome</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium text-left max-w-[220px] truncate">
          {display(lead.nome)}
        </td>
      ),
    },
    {
      id: "data_nascimento",
      header: <Th align="center"><SortButton field="data_nascimento" align="center">Data nasc.</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[110px]">
          {display(lead.data_nascimento)}
        </td>
      ),
    },
    {
      id: "telefone",
      header: <Th>Telefone</Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-left min-w-[110px]">
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
      ),
    },
    {
      id: "classe",
      header: <Th align="center">Classe</Th>,
      cell: (lead: ProcessedLeadCLT) => {
        const classeInfo = getClasseBadge(lead.telefones[0]?.classe);
        return (
          <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[84px]">
            <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", classeInfo.cls)}>
              {classeInfo.label}
            </span>
          </td>
        );
      },
    },
    {
      id: "idade",
      header: <Th align="center"><SortButton field="idade" align="center">Idade</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[72px]">
          {display(lead.idade)}
        </td>
      ),
    },
    {
      id: "sexo",
      header: <Th align="center"><SortButton field="sexo" align="center">Sexo</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[60px]">
          {display(lead.sexo)}
        </td>
      ),
    },
    {
      id: "elegivel",
      header: <Th align="center"><SortButton field="elegivel" align="center">Situação</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
          {renderElegivelPill(lead.elegivel, lead.not_found)}
        </td>
      ),
    },
    {
      id: "clt_consultado_em",
      header: <Th align="center"><SortButton field="clt_consultado_em" align="center">Consulta (CLT)</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[150px]">
          {lead.clt_consultado_em ? (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
              {lead.clt_consultado_em}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              Ainda não consultado
            </span>
          )}
        </td>
      ),
    },
    {
      id: "data_admissao",
      header: <Th align="center"><SortButton field="data_admissao" align="center">Admissão</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[120px]">
          {display(lead.data_admissao)}
        </td>
      ),
    },
    {
      id: "meses_admissao",
      header: <Th align="center"><SortButton field="meses_admissao" align="center">Tempo (meses)</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[100px]">
          {display(lead.meses_admissao)}
        </td>
      ),
    },
    {
      id: "categoria_trabalhador_codigo",
      header: <Th align="center"><SortButton field="categoria_trabalhador_codigo" align="center">Categoria trab. (cód.)</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
          {display(lead.categoria_trabalhador_codigo)}
        </td>
      ),
    },
    {
      id: "valor_renda",
      header: <Th align="right"><SortButton field="valor_renda" align="right">Renda</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">
          {display(lead.valor_renda)}
        </td>
      ),
    },
    {
      id: "valor_base_margem",
      header: <Th align="right"><SortButton field="valor_base_margem" align="right">Base margem</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">
          {display(lead.valor_base_margem)}
        </td>
      ),
    },
    {
      id: "margem_disponivel",
      header: <Th align="right"><SortButton field="margem_disponivel" align="right">Margem disp.</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">
          {display(lead.margem_disponivel)}
        </td>
      ),
    },
    {
      id: "valor_max_prestacao",
      header: <Th align="right"><SortButton field="valor_max_prestacao" align="right">Prestação máx.</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">
          {display(lead.valor_max_prestacao)}
        </td>
      ),
    },
    {
      id: "qtd_emprestimos_ativos_suspensos",
      header: <Th align="center"><SortButton field="qtd_emprestimos_ativos_suspensos" align="center">Empréstimos ativos</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
          {display(lead.qtd_emprestimos_ativos_suspensos)}
        </td>
      ),
    },
    {
      id: "emprestimos_legados",
      header: <Th align="center"><SortButton field="emprestimos_legados" align="center">Legados</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[110px]">
          {display(lead.emprestimos_legados)}
        </td>
      ),
    },
    {
      id: "ultima_origem_cadastral",
      header: <Th align="center"><SortButton field="ultima_origem_cadastral" align="center">Última origem (cad.)</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => (
        <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
          {lead.ultima_origem_cadastral ? (
            <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[160px] truncate mx-auto">
              {lead.ultima_origem_cadastral}
            </span>
          ) : (
            <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
              {EMPTY}
            </span>
          )}
        </td>
      ),
    },
  ] as const;

  const visibleCols = cols.filter((c) => isVisible(c.id));
  const headerColCount = visibleCols.length + 1; // + Ações

  // ===== largura mínima dinâmica (CLT) =====
  const tableMinWidthCLT = useMemo(() => {
    const colsCount = headerColCount; // inclui Ações
    if (colsCount <= 3) return "min-w-[520px]";
    if (colsCount <= 6) return "min-w-[960px]";
    if (colsCount <= 10) return "min-w-[1250px]";
    return "min-w-[1700px]";
  }, [headerColCount]);

  const renderSkeleton = (key: number) => (
    <tr className="hover:bg-gray-50" key={key}>
      {visibleCols.map((c, idx) => {
        if (c.id === "cpf") return <SkeletonCell key={idx} w="w-28" align="left" />;
        if (c.id === "nome") return <SkeletonCell key={idx} w="w-40" align="left" />;
        if (["data_nascimento", "data_admissao", "clt_consultado_em"].includes(c.id))
          return <SkeletonCell key={idx} w="w-24" align="center" />;
        if (
          [
            "classe",
            "idade",
            "sexo",
            "categoria_trabalhador_codigo",
            "qtd_emprestimos_ativos_suspensos",
            "emprestimos_legados",
            "ultima_origem_cadastral",
            "elegivel",
          ].includes(c.id)
        )
          return <SkeletonCell key={idx} w="w-24" align="center" />;
        if (["valor_renda", "valor_base_margem", "margem_disponivel", "valor_max_prestacao"].includes(c.id))
          return <SkeletonCell key={idx} w="w-16" align="right" />;
        return <SkeletonCell key={idx} w="w-32" align="left" />;
      })}
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

  const renderTableBody = () => {
    if (isLoading) {
      return Array.from({ length: 8 }).map((_, i) => renderSkeleton(i));
    }
    if (leads.length === 0) {
      return (
        <tr>
          <td colSpan={headerColCount} className="text-center py-12 text-gray-500">
            Nenhum lead encontrado com os filtros aplicados.
          </td>
        </tr>
      );
    }
    return sortedLeads.map((lead) => (
      <tr key={lead.id} className="group hover:bg-gray-50 transition-colors duration-150">
        {visibleCols.map((c, idx) => (
          <React.Fragment key={`${lead.id}-${c.id}-${idx}`}>{c.cell(lead)}</React.Fragment>
        ))}
        {/* Ações */}
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
        <div className="hidden lg:block w-full max-w-full">
          <div className="overflow-x-auto relative max-w-full">
            {/* largura mínima dinâmica */}
            <table className={cn("w-full", tableMinWidthCLT)}>
              <thead className="bg-gray-50 sticky top-0 z-30">
                <tr>
                  {visibleCols.map((c) => React.cloneElement(c.header, { key: c.id }))}
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
              <tbody className="bg-white divide-y divide-gray-200">{renderTableBody()}</tbody>
            </table>
          </div>
        </div>

        {/* Mobile simples (somente em telas < lg) */}
        <div className="lg:hidden space-y-4 p-4 max-w-full">
          {leads.map((lead) => (
            <div key={lead.id} className="bg-white border border-gray-200 rounded-lg p-4 space-y-3">
              <div className="flex justify-between items-start">
                <div className="flex-1 min-w-0">
                  <h3 className="font-medium text-gray-900 truncate">{display(lead.nome)}</h3>
                  <p className="text-sm font-mono text-gray-600 truncate">{display(lead.cpf)}</p>
                  <p className="text-xs text-gray-600">Data nasc.: {display(lead.data_nascimento)}</p>
                  <p className="text-xs text-gray-600">Categoria: {display(lead.categoria_trabalhador_codigo)}</p>
                </div>
                <Button
                  onClick={() => {
                    setSelectedLeadId(lead.id);
                    setIsModalOpen(true);
                  }}
                  variant="outline"
                  size="sm"
                >
                  <Eye className="w-4 h-4 mr-1" /> Ver
                </Button>
              </div>
              <div className="text-xs text-gray-600">
                <p>Consulta CLT: {lead.clt_consultado_em || "—"}</p>
                <p>
                  Situação:{" "}
                  {lead.not_found
                    ? "Não encontrado"
                    : lead.elegivel === true
                    ? "Elegível"
                    : lead.elegivel === false
                    ? "Não elegível"
                    : "—"}
                </p>
              </div>
            </div>
          ))}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
          <div className="text-sm text-gray-500">Página {currentPage} de {totalPages}</div>
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

      <LeadDetailsModal isOpen={isModalOpen} onClose={handleCloseModal} leadId={selectedLeadId} />
    </>
  );
};

/* util header cell */
const Th = ({
  children,
  align = "left",
}: {
  children: React.ReactNode;
  align?: "left" | "center" | "right";
}) => (
  <th
    className={cn(
      "px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider",
      align === "center" ? "text-center" : align === "right" ? "text-right" : "text-left",
      "min-w-[120px]"
    )}
  >
    {children}
  </th>
);
