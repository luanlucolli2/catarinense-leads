import React, { useState, useMemo } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Eye,
  ChevronUp,
  ChevronDown,
  Phone,
  DollarSign,
  FileText,
  Database,
  User,
  Briefcase,
  AlertCircle,
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

/* =========================================================
 * Tipos compartilhados
 * =======================================================*/
export interface Telefone {
  fone: string;
  classe: string | null;
}

const EMPTY = "--";
const ACTIONS_COL_WIDTH = "w-[110px] min-w-[110px] max-w-[110px]";

/* Badge para classe de telefone (mantido do teu padrão) */
const getClasseBadge = (raw?: string | null) => {
  if (!raw) return { label: EMPTY, cls: "bg-gray-100 text-gray-700" };
  const norm = raw.toString().toLowerCase().replace(/[_\s]+/g, " ").trim();
  if (norm === "carteira") return { label: "Carteira", cls: "bg-amber-100 text-amber-800" };
  if (norm === "atendimento ia") return { label: "Atendimento IA", cls: "bg-sky-100 text-sky-800" };
  return { label: raw, cls: "bg-blue-100 text-blue-800" };
};

const display = (val?: string | number | null) =>
  val === undefined || val === null || val === "" ? EMPTY : val;

/* Helpers de UI usados só nos cards mobile */
const DataRow = ({
  label,
  value,
  mono = false,
  alignRight = true,
}: {
  label: string;
  value: string | number;
  mono?: boolean;
  alignRight?: boolean;
}) => (
  <div className="flex justify-between items-start gap-2 text-xs">
    <span className="text-gray-600 font-medium shrink-0">{label}:</span>
    <span className={cn("text-gray-900 break-words", mono && "font-mono", alignRight && "text-right")}>
      {value || EMPTY}
    </span>
  </div>
);

const Section = ({
  title,
  icon: Icon,
  children,
}: {
  title: string;
  icon: any;
  children: React.ReactNode;
}) => (
  <div className="space-y-2">
    <div className="flex items-center gap-1.5 text-xs font-semibold text-gray-800">
      <Icon className="w-3.5 h-3.5" />
      <span>{title}</span>
    </div>
    <div className="space-y-1.5 pl-5">{children}</div>
  </div>
);

/* =========================================================
 * Util: visibilidade
 * =======================================================*/
const useColVisibility = (ids: string[]) => {
  const set = new Set(ids);
  const isVisible = (id: string) => set.has(id);
  const hasAnyVisible = (idsToCheck: string[]) => idsToCheck.some((id) => set.has(id));
  return { isVisible, hasAnyVisible };
};

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
  /** último contrato (formatado p/ exibição; "" se não houver) */
  data_contrato_recente?: string;
  vendedor?: string;
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
  | "data_contrato_recente"
  | "vendedor"
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
  /** IDs de colunas visíveis (persistido no localStorage) */
  visibleColumns: string[];
}

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
    <Skeleton className={cn("h-4", w, align === "center" && "mx-auto", align === "right" && "ml-auto")} />
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
  const { isVisible, hasAnyVisible } = useColVisibility(visibleColumns);

  const handleViewLead = (lead: ProcessedLeadFGTS) => {
    setSelectedLeadId(lead.id);
    setIsModalOpen(true);
  };
  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLeadId(null);
  };

  const handleSort = (field: SortFieldFGTS) => {
    if (sortField === field) setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    else {
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
        case "data_contrato_recente":
          return (x as any)[sortField] ? new Date((x as any)[sortField] as string).getTime() : Number.POSITIVE_INFINITY;
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
        case "vendedor":
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
      {sortField === field ? sortDirection === "asc" ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" /> : <div className="w-3 h-3" />}
    </button>
  );

  // header cell
  const Th = ({
    children,
    align = "left",
    minW = "min-w-[120px]",
  }: {
    children: React.ReactNode;
    align?: "left" | "center" | "right";
    minW?: string;
  }) => (
    <th
      className={cn(
        "px-3 xl:px-6 py-3 text-xs font-medium text-gray-500 tracking-wider",
        align === "center" ? "text-center" : align === "right" ? "text-right" : "text-left",
        minW
      )}
    >
      {children}
    </th>
  );

  /** ===== catálogo de colunas FGTS (desktop) ===== */
  const phonePairCols = (idx: 1 | 2 | 3 | 4) =>
    [
      {
        id: `telefone_${idx}`,
        header: <Th minW="min-w-[110px]">Fone {idx}</Th>,
        cell: (lead: ProcessedLeadFGTS) => (
          <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-left min-w-[110px]">
            {lead.telefones[idx - 1]?.fone ? (
              <div className="flex items-center space-x-2">
                <span className="font-mono">{display(lead.telefones[idx - 1]?.fone)}</span>
                {idx === 1 && lead.telefones.length > 1 && (
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
        id: `classe_${idx}`,
        header: <Th align="center" minW="min-w-[84px]">Classe {idx}</Th>,
        cell: (lead: ProcessedLeadFGTS) => {
          const classeInfo = getClasseBadge(lead.telefones[idx - 1]?.classe);
          return (
            <td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[84px]">
              <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", classeInfo.cls)}>
                {classeInfo.label}
              </span>
            </td>
          );
        },
      },
    ] as const;

  const cols = [
    { id: "cpf", header: <Th minW="min-w-[96px]"><SortButton field="cpf">CPF</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 align-middle text-left min-w-[96px]">{display(lead.cpf)}</td>) },
    { id: "nome", header: <Th minW="min-w-[140px]"><SortButton field="nome">Nome</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium align-middle text-left max-w-[220px] truncate">{display(lead.nome)}</td>) },
    { id: "data_nascimento", header: <Th align="center" minW="min-w-[110px]"><SortButton field="data_nascimento" align="center">Data nasc.</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[110px]">{display(lead.data_nascimento)}</td>) },

    // Telefones 1..4 (pares fone/classe)
    ...phonePairCols(1),
    ...phonePairCols(2),
    ...phonePairCols(3),
    ...phonePairCols(4),

    { id: "consulta", header: <Th align="center" minW="min-w-[150px]"><SortButton field="consulta" align="center">Motivo</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[150px]"><span className="text-xs text-gray-600 truncate max-w-[150px] inline-block">{display(lead.consulta)}</span></td>) },
    { id: "saldo", header: <Th align="right" minW="min-w-[96px]"><SortButton field="saldo" align="right">Saldo</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[96px]">{display(lead.saldo)}</td>) },
    { id: "libera", header: <Th align="right" minW="min-w-[96px]"><SortButton field="libera" align="right">Libera</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[96px]">{display(lead.libera)}</td>) },
    { id: "data_atualizacao", header: <Th align="center" minW="min-w-[110px]"><SortButton field="data_atualizacao" align="center">Data hig.</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[110px]">{display(lead.data_atualizacao)}</td>) },

    { id: "data_contrato_recente", header: <Th align="center" minW="min-w-[130px]"><SortButton field="data_contrato_recente" align="center">Último contrato</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[130px]">{display(lead.data_contrato_recente)}</td>) },
    { id: "vendedor", header: <Th minW="min-w-[160px]"><SortButton field="vendedor">Vendedor</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-left min-w-[160px] max-w-[220px] truncate">{display(lead.vendedor)}</td>) },

    {
      id: "fgts_off_authorized", header: <Th align="center" minW="min-w-[150px]"><SortButton field="fgts_off_authorized" align="center">Autorizado (FGTS OFF)</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap align-middle text-center min-w-[150px]">
        {lead.fgts_off_authorized === true ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-emerald-400 text-white">Autorizado</span>
        ) : lead.fgts_off_authorized === false ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-amber-500 text-white">Não autorizado</span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Sem dados</span>
        )}
      </td>)
    },
    {
      id: "fgts_off_consultado_em", header: <Th align="center" minW="min-w-[150px]"><SortButton field="fgts_off_consultado_em" align="center">Data consulta (FGTS OFF)</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[150px]">
        {lead.fgts_off_consultado_em ? (
          lead.fgts_off_consultado_em
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Não consultado</span>
        )}
      </td>)
    },

    { id: "contratos", header: <Th align="right" minW="min-w-[72px]"><SortButton field="contratos" align="right">Contratos</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold align-middle text-right min-w-[72px]">{typeof lead.contratos === "number" ? lead.contratos : EMPTY}</td>) },
    {
      id: "ultima_origem_cadastral", header: <Th align="center" minW="min-w-[130px]"><SortButton field="ultima_origem_cadastral" align="center">Última origem (cad.)</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[130px]">
        {lead.ultima_origem_cadastral ? (
          <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[160px] truncate mx-auto">
            {lead.ultima_origem_cadastral}
          </span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
            {EMPTY}
          </span>
        )}
      </td>)
    },
    {
      id: "ultima_origem_higienizacao", header: <Th align="center" minW="min-w-[130px]"><SortButton field="ultima_origem_higienizacao" align="center">Última origem (hig.)</SortButton></Th>, cell: (lead: ProcessedLeadFGTS) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 align-middle text-center min-w-[130px]">
        {lead.ultima_origem_higienizacao ? (
          <span className="inline-flex px-2 py-1 text-xs font-medium bg-violet-100 text-violet-800 rounded-full max-w-[160px] truncate mx-auto">
            {lead.ultima_origem_higienizacao}
          </span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
            {EMPTY}
          </span>
        )}
      </td>)
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
    return "min-w-[1700px]";
  }, [headerColCount]);

  const renderSkeleton = (key: number) => (
    <tr className="hover:bg-gray-50" key={key}>
      {visibleCols.map((c, idx) => {
        if (c.id === "cpf") return <SkeletonCell key={idx} w="w-28" align="left" />;
        if (c.id === "nome") return <SkeletonCell key={idx} w="w-40" align="left" />;
        if (["data_nascimento", "data_atualizacao", "fgts_off_consultado_em", "data_contrato_recente"].includes(c.id))
          return <SkeletonCell key={idx} w="w-24" align="center" />;

        if (
          c.id.startsWith("classe_") ||
          ["consulta", "ultima_origem_cadastral", "ultima_origem_higienizacao", "fgts_off_authorized"].includes(c.id)
        )
          return <SkeletonCell key={idx} w="w-24" align="center" />;

        if (["saldo", "libera", "contratos"].includes(c.id)) return <SkeletonCell key={idx} w="w-16" align="right" />;

        if (c.id === "vendedor") return <SkeletonCell key={idx} w="w-40" align="left" />;

        // telefones_X e default
        return <SkeletonCell key={idx} w="w-32" align="left" />;
      })}
      <td className={cn("px-3 xl:px-6 py-4 text-center sticky right-0 z-20 bg-white border-l border-gray-200", ACTIONS_COL_WIDTH)}>
        <Skeleton className="h-8 w-12 mx-auto" />
      </td>
    </tr>
  );

  const renderTableBody = () => {
    if (isLoading) return Array.from({ length: 8 }).map((_, i) => renderSkeleton(i));
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
            <Button onClick={() => handleViewLead(lead)} variant="outline" size="sm" className="flex items-center space-x-1">
              <Eye className="w-4 h-4" />
              <span className="hidden xl:inline">Ver</span>
            </Button>
          </div>
        </td>
      </tr>
    ));
  };

  /* ======================= CARDS (MOBILE < lg) — FGTS ======================= */
  const FGTSCard = ({ lead }: { lead: ProcessedLeadFGTS }) => {
    const classeInfo = getClasseBadge(lead.telefones[0]?.classe);

    // helper: mostra filho se a coluna estiver visível
    const ShowIf: React.FC<{ id: string; children: React.ReactNode }> = ({ id, children }) =>
      isVisible(id) ? <>{children}</> : null;

    const sectionTelefonesVisible = hasAnyVisible([
      "telefone_1", "telefone_2", "telefone_3", "telefone_4",
      "classe_1", "classe_2", "classe_3", "classe_4",
    ]);

    const sectionDadosVisible = hasAnyVisible([
      "consulta", "saldo", "libera", "data_atualizacao", "contratos", "data_contrato_recente", "vendedor"
    ]);

    const sectionOrigensVisible = hasAnyVisible([
      "ultima_origem_cadastral", "ultima_origem_higienizacao"
    ]);

    const sectionFgtsOffVisible = hasAnyVisible([
      "fgts_off_authorized", "fgts_off_consultado_em"
    ]);

    const AuthPill = () =>
      lead.fgts_off_authorized === true ? (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
          Autorizado
        </span>
      ) : lead.fgts_off_authorized === false ? (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 border border-amber-200">
          Não autorizado
        </span>
      ) : (
        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 border border-gray-200">
          Sem dados
        </span>
      );

    return (
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full">
        <div className="flex justify-between items-start gap-3">
          <div className="flex-1 min-w-0">
            <ShowIf id="nome"><h3 className="font-semibold text-gray-900 truncate">{display(lead.nome)}</h3></ShowIf>
            <ShowIf id="cpf"><p className="text-xs font-mono text-gray-600 truncate">{display(lead.cpf)}</p></ShowIf>
            <ShowIf id="data_nascimento"><p className="text-xs text-gray-600">Data nasc.: {display(lead.data_nascimento)}</p></ShowIf>
            <ShowIf id="data_contrato_recente">{lead.data_contrato_recente && <p className="text-xs text-gray-600">Último contrato: {display(lead.data_contrato_recente)}</p>}</ShowIf>
            <ShowIf id="vendedor">{lead.vendedor && <p className="text-xs text-gray-600">Vendedor: {display(lead.vendedor)}</p>}</ShowIf>

            <div className="mt-1 flex items-center gap-2">
              <ShowIf id="telefone_1"><span className="text-xs font-mono text-gray-800">{display(lead.telefones[0]?.fone)}</span></ShowIf>
              <ShowIf id="classe_1"><span className={cn("inline-flex px-2 py-1 text-[10px] font-semibold rounded-full", classeInfo.cls)}>{classeInfo.label}</span></ShowIf>
            </div>
          </div>
          <Button
            onClick={() => {
              setSelectedLeadId(lead.id);
              setIsModalOpen(true);
            }}
            variant="outline"
            size="sm"
            className="shrink-0"
          >
            <Eye className="w-4 h-4 mr-1" /> Ver
          </Button>
        </div>

        {sectionTelefonesVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Telefones" icon={Phone}>
            <div className="grid grid-cols-1 gap-1.5">
              {[1, 2, 3, 4].map((i) => {
                const t = lead.telefones[i - 1];
                if (!t?.fone) return null;
                // render só se pelo menos telefone_i ou classe_i visível
                if (!hasAnyVisible([`telefone_${i}`, `classe_${i}`])) return null;
                const b = getClasseBadge(t.classe);
                return (
                  <div key={i} className="flex items-center justify-between gap-2">
                    {isVisible(`telefone_${i}`) && <span className="text-xs font-mono text-gray-900">{t.fone}</span>}
                    {isVisible(`classe_${i}`) && <span className={cn("inline-flex px-2 py-0.5 text-[10px] font-semibold rounded-full", b.cls)}>{b.label}</span>}
                  </div>
                );
              })}
            </div>
          </Section>
        </>}

        {sectionDadosVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Dados de negócio" icon={DollarSign}>
            {isVisible("consulta") && <DataRow label="Motivo" value={display(lead.consulta)} />}
            {isVisible("saldo") && <DataRow label="Saldo" value={display(lead.saldo)} />}
            {isVisible("libera") && <DataRow label="Libera" value={display(lead.libera)} />}
            {isVisible("data_atualizacao") && <DataRow label="Data higienização" value={display(lead.data_atualizacao)} />}
            {isVisible("contratos") && <DataRow label="Nº contratos" value={typeof lead.contratos === "number" ? lead.contratos : EMPTY} />}
          </Section>
        </>}

        {sectionOrigensVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Origens" icon={FileText}>
            {isVisible("ultima_origem_cadastral") && <DataRow label="Última origem (cad.)" value={display(lead.ultima_origem_cadastral)} />}
            {isVisible("ultima_origem_higienizacao") && <DataRow label="Última origem (hig.)" value={display(lead.ultima_origem_higienizacao)} />}
          </Section>
        </>}

        {sectionFgtsOffVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="FGTS OFF" icon={Database}>
            {isVisible("fgts_off_authorized") && (
              <div className="flex items-center justify-between">
                <span className="text-xs text-gray-600 font-medium">Autorizado:</span>
                <AuthPill />
              </div>
            )}
            {isVisible("fgts_off_consultado_em") && (
              <DataRow
                label="Data consulta"
                value={lead.fgts_off_consultado_em ? lead.fgts_off_consultado_em : "Não consultado"}
              />
            )}
          </Section>
        </>}
      </div>
    );
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
                  {visibleCols.map((c) => React.cloneElement(c.header as any, { key: c.id }))}
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
          {leads.map((lead) => (
            <FGTSCard key={lead.id} lead={lead} />
          ))}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
          <div className="text-sm text-gray-500">Página {currentPage} de {totalPages}</div>
          <div className="flex items-center space-x-2">
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
  clt_dados_atualizados_em: string; // 🆕

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
  | "cpf" | "nome" | "data_nascimento" | "idade" | "sexo"
  | "ultima_origem_cadastral" | "elegivel"
  | "clt_consultado_em"
  | "clt_dados_atualizados_em"
  | "data_admissao" | "meses_admissao"
  | "categoria_trabalhador_codigo"
  | "valor_renda" | "valor_base_margem" | "margem_disponivel" | "valor_max_prestacao"
  | "qtd_emprestimos_ativos_suspensos" | "emprestimos_legados";

interface LeadsTableCLTProps {
  leads: ProcessedLeadCLT[];
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  isLoading: boolean;
  /** IDs de colunas visíveis (persistido no localStorage) */
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
  const { isVisible, hasAnyVisible } = useColVisibility(visibleColumns);

  const handleViewLead = (lead: ProcessedLeadCLT) => {
    setSelectedLeadId(lead.id);
    setIsModalOpen(true);
  };
  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedLeadId(null);
  };

  const handleSort = (field: SortFieldCLT) => {
    if (sortField === field) setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    else {
      setSortField(field);
      setSortDirection("asc");
    }
  };

  const valueForSort = (x: ProcessedLeadCLT, field: SortFieldCLT) => {
    switch (field) {
      case "clt_consultado_em":
      case "clt_dados_atualizados_em":
      case "data_admissao":
      case "data_nascimento":
        return (x as any)[field] ? new Date((x as any)[field]).getTime() : Number.POSITIVE_INFINITY;
    }
    switch (field) {
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
      {sortField === field ? (sortDirection === "asc" ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />) : <div className="w-3 h-3" />}
    </button>
  );

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

  const phonePairCols = (idx: 1 | 2 | 3 | 4) =>
    [
      {
        id: `telefone_${idx}`,
        header: <Th>Fone {idx}</Th>,
        cell: (lead: ProcessedLeadCLT) => (
          <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-left min-w-[110px]">
            {lead.telefones[idx - 1]?.fone ? <span className="font-mono">{display(lead.telefones[idx - 1]?.fone)}</span> : EMPTY}
          </td>
        ),
      },
      {
        id: `classe_${idx}`,
        header: <Th align="center">Classe {idx}</Th>,
        cell: (lead: ProcessedLeadCLT) => {
          const classeInfo = getClasseBadge(lead.telefones[idx - 1]?.classe);
          return (
            <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[84px]">
              <span className={cn("inline-flex px-2 py-1 text-xs font-semibold rounded-full", classeInfo.cls)}>
                {classeInfo.label}
              </span>
            </td>
          );
        },
      },
    ] as const;

  /** ===== catálogo de colunas CLT (desktop) ===== */
  const cols = [
    { id: "cpf", header: <Th><SortButton field="cpf">CPF</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900 text-left min-w-[96px]">{display(lead.cpf)}</td>) },
    { id: "nome", header: <Th><SortButton field="nome">Nome</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium text-left max-w-[220px] truncate">{display(lead.nome)}</td>) },
    { id: "data_nascimento", header: <Th align="center"><SortButton field="data_nascimento" align="center">Data nasc.</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[110px]">{display(lead.data_nascimento)}</td>) },

    ...phonePairCols(1),
    ...phonePairCols(2),
    ...phonePairCols(3),
    ...phonePairCols(4),

    { id: "idade", header: <Th align="center"><SortButton field="idade" align="center">Idade</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[72px]">{display(lead.idade)}</td>) },
    { id: "sexo", header: <Th align="center"><SortButton field="sexo" align="center">Sexo</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[60px]">{display(lead.sexo)}</td>) },
    {
      id: "elegivel", header: <Th align="center"><SortButton field="elegivel" align="center">Situação</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
        {lead.not_found ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Não encontrado</span>
        ) : lead.elegivel === true ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-emerald-500 text-white">Elegível</span>
        ) : lead.elegivel === false ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-rose-500 text-white">Não elegível</span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Sem dados</span>
        )}
      </td>)
    },
    {
      id: "clt_consultado_em", header: <Th align="center"><SortButton field="clt_consultado_em" align="center">Data consulta</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[150px]">
        {lead.clt_consultado_em ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
            {lead.clt_consultado_em}
          </span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">Ainda não consultado</span>
        )}
      </td>)
    },
    {
      id: "clt_dados_atualizados_em", header: <Th align="center"><SortButton field="clt_dados_atualizados_em" align="center">Data dados</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[150px]">
        {lead.clt_dados_atualizados_em ? (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
            {lead.clt_dados_atualizados_em}
          </span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">--</span>
        )}
      </td>)
    },

    { id: "data_admissao", header: <Th align="center"><SortButton field="data_admissao" align="center">Admissão</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[120px]">{display(lead.data_admissao)}</td>) },
    { id: "meses_admissao", header: <Th align="center"><SortButton field="meses_admissao" align="center">Meses de admissão</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[100px]">{display(lead.meses_admissao)}</td>) },
    { id: "categoria_trabalhador_codigo", header: <Th align="center"><SortButton field="categoria_trabalhador_codigo" align="center">Categoria trab. (cód.)</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">{display(lead.categoria_trabalhador_codigo)}</td>) },
    { id: "inicio_atividade_empregador", header: <Th align="center">Início atividade (empregador)</Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[150px]">{display(lead.inicio_atividade_empregador)}</td>) },

    { id: "valor_renda", header: <Th align="right"><SortButton field="valor_renda" align="right">Renda</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">{display(lead.valor_renda)}</td>) },
    { id: "valor_base_margem", header: <Th align="right"><SortButton field="valor_base_margem" align="right">Base margem</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">{display(lead.valor_base_margem)}</td>) },
    { id: "margem_disponivel", header: <Th align="right"><SortButton field="margem_disponivel" align="right">Margem disp.</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">{display(lead.margem_disponivel)}</td>) },
    { id: "valor_max_prestacao", header: <Th align="right"><SortButton field="valor_max_prestacao" align="right">Prestação máx.</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-right min-w-[110px]">{display(lead.valor_max_prestacao)}</td>) },

    { id: "qtd_emprestimos_ativos_suspensos", header: <Th align="center"><SortButton field="qtd_emprestimos_ativos_suspensos" align="center">Empréstimos ativos</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">{display(lead.qtd_emprestimos_ativos_suspensos)}</td>) },
    {
      id: "emprestimos_legados",
      header: <Th align="center"><SortButton field="emprestimos_legados" align="center">Legados</SortButton></Th>,
      cell: (lead: ProcessedLeadCLT) => {
        const v = lead.emprestimos_legados;
        const label = v === 1 ? "Sim" : v === 0 ? "Não" : (v ?? EMPTY);
        return <td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[110px]">{label}</td>;
      },
    },

    {
      id: "ultima_origem_cadastral", header: <Th align="center"><SortButton field="ultima_origem_cadastral" align="center">Última origem (cad.)</SortButton></Th>, cell: (lead: ProcessedLeadCLT) => (<td className="px-3 xl:px-6 py-4 whitespace-nowrap text-center min-w-[130px]">
        {lead.ultima_origem_cadastral ? (
          <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full max-w-[160px] truncate mx-auto">
            {lead.ultima_origem_cadastral}
          </span>
        ) : (
          <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
            {EMPTY}
          </span>
        )}
      </td>)
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
        if (["data_nascimento", "data_admissao", "clt_consultado_em", "clt_dados_atualizados_em"].includes(c.id))
          return <SkeletonCell key={idx} w="w-24" align="center" />;

        if (
          [
            "classe_1", "classe_2", "classe_3", "classe_4",
            "idade", "sexo", "categoria_trabalhador_codigo",
            "qtd_emprestimos_ativos_suspensos", "emprestimos_legados",
            "ultima_origem_cadastral", "elegivel", "inicio_atividade_empregador"
          ].includes(c.id)
        )
          return <SkeletonCell key={idx} w="w-24" align="center" />;
        if (["valor_renda", "valor_base_margem", "margem_disponivel", "valor_max_prestacao"].includes(c.id))
          return <SkeletonCell key={idx} w="w-16" align="right" />;
        return <SkeletonCell key={idx} w="w-32" align="left" />;
      })}
      <td className={cn("px-3 xl:px-6 py-4 text-center sticky right-0 z-20 bg-white border-l border-gray-200", ACTIONS_COL_WIDTH)}>
        <Skeleton className="h-8 w-12 mx-auto" />
      </td>
    </tr>
  );

  const renderTableBody = () => {
    if (isLoading) return Array.from({ length: 8 }).map((_, i) => renderSkeleton(i));
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
            <Button onClick={() => handleViewLead(lead)} variant="outline" size="sm" className="flex items-center space-x-1">
              <Eye className="w-4 h-4" />
              <span className="hidden xl:inline">Ver</span>
            </Button>
          </div>
        </td>
      </tr>
    ));
  };

  /* ======================= CARDS (MOBILE < lg) — CLT ======================= */
  const CLTCard = ({ lead }: { lead: ProcessedLeadCLT }) => {
    const classeInfo = getClasseBadge(lead.telefones[0]?.classe);

    const ShowIf: React.FC<{ id: string; children: React.ReactNode }> = ({ id, children }) =>
      isVisible(id) ? <>{children}</> : null;

    const sectionTelefonesVisible = hasAnyVisible([
      "telefone_1", "telefone_2", "telefone_3", "telefone_4",
      "classe_1", "classe_2", "classe_3", "classe_4",
    ]);

    const sectionSituacaoVisible = hasAnyVisible([
      "elegivel", "clt_consultado_em", "clt_dados_atualizados_em"
    ]);

    const sectionPerfilVisible = hasAnyVisible(["idade", "sexo"]);
    const sectionVinculoVisible = hasAnyVisible([
      "data_admissao", "meses_admissao", "categoria_trabalhador_codigo", "inicio_atividade_empregador"
    ]);

    const sectionFinanceiroVisible = hasAnyVisible([
      "valor_renda", "valor_base_margem", "margem_disponivel", "valor_max_prestacao"
    ]);

    const sectionEmprestimosVisible = hasAnyVisible([
      "qtd_emprestimos_ativos_suspensos", "emprestimos_legados"
    ]);

    const sectionOrigensVisible = hasAnyVisible(["ultima_origem_cadastral"]);

    const SituacaoBadge = () =>
      lead.not_found ? (
        <span className="inline-flex px-2 py-1 text-[11px] font-semibold rounded-full bg-gray-100 text-gray-800 border border-gray-200">
          Não encontrado
        </span>
      ) : lead.elegivel === true ? (
        <span className="inline-flex px-2 py-1 text-[11px] font-semibold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
          Elegível
        </span>
      ) : lead.elegivel === false ? (
        <span className="inline-flex px-2 py-1 text-[11px] font-semibold rounded-full bg-rose-100 text-rose-800 border border-rose-200">
          Não elegível
        </span>
      ) : (
        <span className="inline-flex px-2 py-1 text-[11px] font-semibold rounded-full bg-gray-100 text-gray-800 border border-gray-200">
          Sem dados
        </span>
      );

    return (
      <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3 max-w-full">
        <div className="flex justify-between items-start gap-3">
          <div className="flex-1 min-w-0">
            <ShowIf id="nome"><h3 className="font-semibold text-gray-900 truncate">{display(lead.nome)}</h3></ShowIf>
            <ShowIf id="cpf"><p className="text-xs font-mono text-gray-600 truncate">{display(lead.cpf)}</p></ShowIf>
            <ShowIf id="data_nascimento"><p className="text-xs text-gray-600">Data nasc.: {display(lead.data_nascimento)}</p></ShowIf>
            <div className="mt-1 flex items-center gap-2">
              <ShowIf id="telefone_1"><span className="text-xs font-mono text-gray-800">{display(lead.telefones[0]?.fone)}</span></ShowIf>
              <ShowIf id="classe_1"><span className={cn("inline-flex px-2 py-1 text-[10px] font-semibold rounded-full", classeInfo.cls)}>{classeInfo.label}</span></ShowIf>
            </div>
          </div>
          <Button
            onClick={() => {
              setSelectedLeadId(lead.id);
              setIsModalOpen(true);
            }}
            variant="outline"
            size="sm"
            className="shrink-0"
          >
            <Eye className="w-4 h-4 mr-1" /> Ver
          </Button>
        </div>

        {sectionTelefonesVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Telefones" icon={Phone}>
            <div className="grid grid-cols-1 gap-1.5">
              {[1, 2, 3, 4].map((i) => {
                const t = lead.telefones[i - 1];
                if (!t?.fone) return null;
                if (!hasAnyVisible([`telefone_${i}`, `classe_${i}`])) return null;
                const b = getClasseBadge(t.classe);
                return (
                  <div key={i} className="flex items-center justify-between gap-2">
                    {isVisible(`telefone_${i}`) && <span className="text-xs font-mono text-gray-900">{t.fone}</span>}
                    {isVisible(`classe_${i}`) && <span className={cn("inline-flex px-2 py-0.5 text-[10px] font-semibold rounded-full", b.cls)}>{b.label}</span>}
                  </div>
                );
              })}
            </div>
          </Section>
        </>}

        {sectionSituacaoVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Situação CLT" icon={AlertCircle}>
            {isVisible("elegivel") && <div className="flex flex-wrap gap-2"><SituacaoBadge /></div>}
            {isVisible("clt_consultado_em") && (
              <DataRow label="Data consulta" value={lead.clt_consultado_em ? lead.clt_consultado_em : "Ainda não consultado"} />
            )}
            {isVisible("clt_dados_atualizados_em") && <DataRow label="Dados atualizados em" value={lead.clt_dados_atualizados_em || EMPTY} />}
          </Section>
        </>}

        {sectionPerfilVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Perfil" icon={User}>
            {isVisible("idade") && <DataRow label="Idade" value={lead.idade !== null ? `${lead.idade}` : EMPTY} />}
            {isVisible("sexo") && <DataRow label="Sexo" value={display(lead.sexo)} />}
          </Section>
        </>}

        {sectionVinculoVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Vínculo" icon={Briefcase}>
            {isVisible("data_admissao") && <DataRow label="Admissão" value={display(lead.data_admissao)} />}
            {isVisible("meses_admissao") && <DataRow label="Tempo (meses)" value={lead.meses_admissao ?? EMPTY} />}
            {isVisible("categoria_trabalhador_codigo") && <DataRow label="Categoria trab. (cód.)" value={display(lead.categoria_trabalhador_codigo)} mono />}
            {isVisible("inicio_atividade_empregador") && <DataRow label="Início atividade (empregador)" value={display(lead.inicio_atividade_empregador)} />}
          </Section>
        </>}

        {sectionFinanceiroVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Financeiro" icon={DollarSign}>
            {isVisible("valor_renda") && <DataRow label="Renda" value={display(lead.valor_renda)} />}
            {isVisible("valor_base_margem") && <DataRow label="Base margem" value={display(lead.valor_base_margem)} />}
            {isVisible("margem_disponivel") && <DataRow label="Margem disponível" value={display(lead.margem_disponivel)} />}
            {isVisible("valor_max_prestacao") && <DataRow label="Prestação máx." value={display(lead.valor_max_prestacao)} />}
          </Section>
        </>}

        {sectionEmprestimosVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Empréstimos" icon={FileText}>
            {isVisible("qtd_emprestimos_ativos_suspensos") && <DataRow label="Ativos/suspensos" value={lead.qtd_emprestimos_ativos_suspensos ?? EMPTY} />}
            {isVisible("emprestimos_legados") && (
              <DataRow
                label="Legados"
                value={lead.emprestimos_legados === 1 ? "Sim" : lead.emprestimos_legados === 0 ? "Não" : display(lead.emprestimos_legados)}
              />
            )}
          </Section>
        </>}

        {sectionOrigensVisible && <>
          <div className="h-px bg-gray-200" />
          <Section title="Origens" icon={FileText}>
            {isVisible("ultima_origem_cadastral") && <DataRow label="Última origem (cad.)" value={display(lead.ultima_origem_cadastral)} />}
          </Section>
        </>}
      </div>
    );
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
                  {visibleCols.map((c) => React.cloneElement(c.header as any, { key: c.id }))}
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

        {/* Mobile (cards) */}
        <div className="lg:hidden space-y-4 p-4 max-w-full">
          {leads.map((lead) => (
            <CLTCard key={lead.id} lead={lead} />
          ))}
        </div>

        {/* Pagination */}
        <div className="bg-white px-4 lg:px-6 py-3 border-t border-gray-200 flex items-center justify-between">
          <div className="text-sm text-gray-500">Página {currentPage} de {totalPages}</div>
          <div className="flex items-center space-x-2">
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
      </div>

      <LeadDetailsModal isOpen={isModalOpen} onClose={handleCloseModal} leadId={selectedLeadId} />
    </>
  );
};
