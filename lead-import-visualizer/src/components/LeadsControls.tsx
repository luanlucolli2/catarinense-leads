import { Search, Upload, Download, Filter, Columns as ColumnsIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { useState, useMemo, useEffect } from "react";
import { FiltersModal } from "./FiltersModal";
import { ColumnsModal } from "./columns/ColumnsModal";
import type { LeadSort } from "@/api/leads";

interface LeadsControlsProps {
  mode: "BASE" | "FGTS" | "CLT" | "MERCANTIL";

  onImportClick: () => void;
  onExportClick: () => void;

  searchValue: string;
  onSearchChange: (value: string) => void;

  eligibleFilter: "todos" | "elegiveis" | "nao-elegiveis";
  onEligibleFilterChange: (value: "todos" | "elegiveis" | "nao-elegiveis") => void;

  contractDateFromFilter: string;
  onContractDateFromFilterChange: (value: string) => void;
  contractDateToFilter: string;
  onContractDateToFilterChange: (value: string) => void;

  motivosFilter: string[];
  onMotivosFilterChange: (values: string[]) => void;

  origemFilter: string[];
  onOrigemFilterChange: (values: string[]) => void;

  cpfMassFilter: string;
  onCpfMassFilterChange: (value: string) => void;
  namesMassFilter: string;
  onNamesMassFilterChange: (value: string) => void;
  phonesMassFilter: string;
  onPhonesMassFilterChange: (value: string) => void;
  noPhonesFilter: boolean;
  onNoPhonesFilterChange: (value: boolean) => void;

  dateFromFilter: string;
  onDateFromFilterChange: (value: string) => void;
  dateToFilter: string;
  onDateToFilterChange: (value: string) => void;

  birthMonthFilter: string[];
  onBirthMonthFilterChange: (values: string[]) => void;
  sortBy: LeadSort | "";
  onSortByChange: (value: LeadSort | "") => void;

  onApplyFilters: () => void;
  onClearFilters: () => void;
  availableMotivos: string[];
  availableOrigens: string[];
  higienizacaoFilter: string[];
  onHigienizacaoFilterChange: (values: string[]) => void;
  availableHigienizacoes: string[];
  vendorsFilter: string[];
  onVendorsFilterChange: (values: string[]) => void;
  availableVendors: { id: number; name: string }[];
  hasActiveFilters: boolean;
  filteredCount?: number;

  fgtsAuthorizedFilter: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado";
  onFgtsAuthorizedFilterChange: (v: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado") => void;
  fgtsConsultaFromFilter: string;
  onFgtsConsultaFromFilterChange: (v: string) => void;
  fgtsConsultaToFilter: string;
  onFgtsConsultaToFilterChange: (v: string) => void;

  cltConsultado: "todos" | "sim" | "nao";
  onCltConsultadoChange: (v: "todos" | "sim" | "nao") => void;

  cltSituacao: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel";
  onCltSituacaoChange: (v: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel") => void;

  cltConsultaFrom: string;
  onCltConsultaFromChange: (v: string) => void;
  cltConsultaTo: string;
  onCltConsultaToChange: (v: string) => void;

  cltAdmissaoFrom: string;
  onCltAdmissaoFromChange: (v: string) => void;
  cltAdmissaoTo: string;
  onCltAdmissaoToChange: (v: string) => void;

  cltMesesMin: string;
  onCltMesesMinChange: (v: string) => void;
  cltMesesMax: string;
  onCltMesesMaxChange: (v: string) => void;

  cltInicioEmpregadorFrom: string;
  onCltInicioEmpregadorFromChange: (v: string) => void;
  cltInicioEmpregadorTo: string;
  onCltInicioEmpregadorToChange: (v: string) => void;

  cltCategoriaCodigos: string;
  onCltCategoriaCodigosChange: (v: string) => void;

  cltIdadeMin: string;
  onCltIdadeMinChange: (v: string) => void;
  cltIdadeMax: string;
  onCltIdadeMaxChange: (v: string) => void;

  cltSexo: string[];
  onCltSexoChange: (values: string[]) => void;

  cltRendaMin: string;
  onCltRendaMinChange: (v: string) => void;
  cltRendaMax: string;
  onCltRendaMaxChange: (v: string) => void;

  cltBaseMin: string;
  onCltBaseMinChange: (v: string) => void;
  cltBaseMax: string;
  onCltBaseMaxChange: (v: string) => void;

  cltMargemMin: string;
  onCltMargemMinChange: (v: string) => void;
  cltMargemMax: string;
  onCltMargemMaxChange: (v: string) => void;

  cltPrestacaoMin: string;
  onCltPrestacaoMinChange: (v: string) => void;
  cltPrestacaoMax: string;
  onCltPrestacaoMaxChange: (v: string) => void;

  cltAtivosMin: string;
  onCltAtivosMinChange: (v: string) => void;
  cltAtivosMax: string;
  onCltAtivosMaxChange: (v: string) => void;
  cltTemAtivos: "todos" | "sim" | "nao";
  onCltTemAtivosChange: (v: "todos" | "sim" | "nao") => void;

  cltTemLegados: "todos" | "sim" | "nao";
  onCltTemLegadosChange: (v: "todos" | "sim" | "nao") => void;

  mercantilSituacao: "todos" | "consultado" | "sem_consulta";
  onMercantilSituacaoChange: (v: "todos" | "consultado" | "sem_consulta") => void;
  mercantilStatusFilter: string[];
  onMercantilStatusFilterChange: (values: string[]) => void;
  mercantilConsultaFrom: string;
  onMercantilConsultaFromChange: (v: string) => void;
  mercantilConsultaTo: string;
  onMercantilConsultaToChange: (v: string) => void;
  mercantilParcelaMin: string;
  onMercantilParcelaMinChange: (v: string) => void;
  mercantilParcelaMax: string;
  onMercantilParcelaMaxChange: (v: string) => void;
  mercantilQtdParcelasMin: string;
  onMercantilQtdParcelasMinChange: (v: string) => void;
  mercantilQtdParcelasMax: string;
  onMercantilQtdParcelasMaxChange: (v: string) => void;
  mercantilOrigensFilter: string[];
  onMercantilOrigensFilterChange: (values: string[]) => void;
  availableMercantilOrigens: string[];
  availableMercantilStatuses: string[];

  visibleColumnsBASE: string[];
  onVisibleColumnsBASEChange: (cols: string[]) => void;
  visibleColumnsFGTS: string[];
  onVisibleColumnsFGTSChange: (cols: string[]) => void;
  visibleColumnsCLT: string[];
  onVisibleColumnsCLTChange: (cols: string[]) => void;
  visibleColumnsMERCANTIL: string[];
  onVisibleColumnsMERCANTILChange: (cols: string[]) => void;

  defaultVisibleColumnsBASE: string[];
  defaultVisibleColumnsFGTS: string[];
  defaultVisibleColumnsCLT: string[];
  defaultVisibleColumnsMERCANTIL: string[];

  disableFilters?: boolean;
  disableExport?: boolean;
}

const SORT_OPTIONS: Record<"BASE" | "CLT" | "MERCANTIL", { value: LeadSort; label: string }[]> = {
  BASE: [
    { value: "lead_updated_at", label: "Atualizados recentemente" },
    { value: "lead_created_at", label: "Criados recentemente" },
  ],
  CLT: [
    { value: "clt_consulted_at", label: "Consultados recentemente" },
    { value: "clt_updated_at", label: "Dados atualizados recentemente" },
    { value: "lead_updated_at", label: "Lead atualizado recentemente" },
  ],
  MERCANTIL: [
    { value: "mercantil_consulted_at", label: "Consultados recentemente" },
    { value: "lead_updated_at", label: "Lead atualizado recentemente" },
  ],
}

export const LeadsControls = ({
  mode,
  onImportClick,
  onExportClick,
  searchValue,
  onSearchChange,
  eligibleFilter,
  onEligibleFilterChange,
  contractDateFromFilter,
  onContractDateFromFilterChange,
  contractDateToFilter,
  onContractDateToFilterChange,
  motivosFilter,
  onMotivosFilterChange,
  origemFilter,
  onOrigemFilterChange,
  cpfMassFilter,
  onCpfMassFilterChange,
  namesMassFilter,
  onNamesMassFilterChange,
  phonesMassFilter,
  onPhonesMassFilterChange,
  noPhonesFilter,
  onNoPhonesFilterChange,
  dateFromFilter,
  onDateFromFilterChange,
  dateToFilter,
  onDateToFilterChange,
  birthMonthFilter,
  onBirthMonthFilterChange,
  sortBy,
  onSortByChange,
  onApplyFilters,
  onClearFilters,
  availableMotivos,
  availableOrigens,
  higienizacaoFilter,
  onHigienizacaoFilterChange,
  availableHigienizacoes,
  vendorsFilter,
  onVendorsFilterChange,
  availableVendors,
  hasActiveFilters,
  filteredCount,
  fgtsAuthorizedFilter,
  onFgtsAuthorizedFilterChange,
  fgtsConsultaFromFilter,
  onFgtsConsultaFromFilterChange,
  fgtsConsultaToFilter,
  onFgtsConsultaToFilterChange,
  cltConsultado,
  onCltConsultadoChange,
  cltSituacao,
  onCltSituacaoChange,
  cltConsultaFrom,
  onCltConsultaFromChange,
  cltConsultaTo,
  onCltConsultaToChange,
  cltAdmissaoFrom,
  onCltAdmissaoFromChange,
  cltAdmissaoTo,
  onCltAdmissaoToChange,
  cltMesesMin,
  onCltMesesMinChange,
  cltMesesMax,
  onCltMesesMaxChange,
  cltInicioEmpregadorFrom,
  onCltInicioEmpregadorFromChange,
  cltInicioEmpregadorTo,
  onCltInicioEmpregadorToChange,
  cltCategoriaCodigos,
  onCltCategoriaCodigosChange,
  cltIdadeMin,
  onCltIdadeMinChange,
  cltIdadeMax,
  onCltIdadeMaxChange,
  cltSexo,
  onCltSexoChange,
  cltRendaMin,
  onCltRendaMinChange,
  cltRendaMax,
  onCltRendaMaxChange,
  cltBaseMin,
  onCltBaseMinChange,
  cltBaseMax,
  onCltBaseMaxChange,
  cltMargemMin,
  onCltMargemMinChange,
  cltMargemMax,
  onCltMargemMaxChange,
  cltPrestacaoMin,
  onCltPrestacaoMinChange,
  cltPrestacaoMax,
  onCltPrestacaoMaxChange,
  cltAtivosMin,
  onCltAtivosMinChange,
  cltAtivosMax,
  onCltAtivosMaxChange,
  cltTemAtivos,
  onCltTemAtivosChange,
  cltTemLegados,
  onCltTemLegadosChange,
  mercantilSituacao,
  onMercantilSituacaoChange,
  mercantilStatusFilter,
  onMercantilStatusFilterChange,
  mercantilConsultaFrom,
  onMercantilConsultaFromChange,
  mercantilConsultaTo,
  onMercantilConsultaToChange,
  mercantilParcelaMin,
  onMercantilParcelaMinChange,
  mercantilParcelaMax,
  onMercantilParcelaMaxChange,
  mercantilQtdParcelasMin,
  onMercantilQtdParcelasMinChange,
  mercantilQtdParcelasMax,
  onMercantilQtdParcelasMaxChange,
  mercantilOrigensFilter,
  onMercantilOrigensFilterChange,
  availableMercantilOrigens,
  availableMercantilStatuses,
  visibleColumnsBASE,
  onVisibleColumnsBASEChange,
  visibleColumnsFGTS,
  onVisibleColumnsFGTSChange,
  visibleColumnsCLT,
  onVisibleColumnsCLTChange,
  visibleColumnsMERCANTIL,
  onVisibleColumnsMERCANTILChange,
  defaultVisibleColumnsBASE,
  defaultVisibleColumnsFGTS,
  defaultVisibleColumnsCLT,
  defaultVisibleColumnsMERCANTIL,
  disableFilters = false,
  disableExport = false,
}: LeadsControlsProps) => {
  const [isFiltersModalOpen, setIsFiltersModalOpen] = useState(false);
  const [isColumnsModalOpen, setIsColumnsModalOpen] = useState(false);
  const [localSearchValue, setLocalSearchValue] = useState(searchValue);

  useEffect(() => {
    setLocalSearchValue(searchValue);
  }, [searchValue]);

  useEffect(() => {
    if (localSearchValue === searchValue) return;

    const timeout = window.setTimeout(() => {
      onSearchChange(localSearchValue);
    }, 500);

    return () => window.clearTimeout(timeout);
  }, [localSearchValue, onSearchChange, searchValue]);

  const currentVisible =
    mode === "BASE"
      ? visibleColumnsBASE
      : mode === "FGTS"
      ? visibleColumnsFGTS
      : mode === "CLT"
        ? visibleColumnsCLT
        : visibleColumnsMERCANTIL;
  const currentDefaults =
    mode === "BASE"
      ? defaultVisibleColumnsBASE
      : mode === "FGTS"
      ? defaultVisibleColumnsFGTS
      : mode === "CLT"
        ? defaultVisibleColumnsCLT
        : defaultVisibleColumnsMERCANTIL;

  const onSaveVisible = (cols: string[]) => {
    if (mode === "BASE") onVisibleColumnsBASEChange(cols);
    else if (mode === "FGTS") onVisibleColumnsFGTSChange(cols);
    else if (mode === "CLT") onVisibleColumnsCLTChange(cols);
    else onVisibleColumnsMERCANTILChange(cols);
  };

  const hasCustomColumns = useMemo(() => {
    if (!currentDefaults?.length) return false;
    if (!currentVisible?.length) return false;
    if (currentVisible.length !== currentDefaults.length) return true;
    const a = new Set(currentVisible);
    for (const d of currentDefaults) {
      if (!a.has(d)) return true;
    }
    return false;
  }, [currentVisible, currentDefaults]);

  const sortLabels: Partial<Record<LeadSort, string>> = {
    lead_updated_at: "Lead atualizado",
    lead_created_at: "Criados recentemente",
    clt_updated_at: "Dados CLT atualizados",
    clt_consulted_at: "Consultados CLT",
    mercantil_updated_at: "Dados Mercantil atualizados",
    mercantil_consulted_at: "Consultados Mercantil",
  };

  const summarizeList = (values: string[], max = 3) => {
    if (!values.length) return "";
    if (values.length <= max) return values.join(", ");
    return `${values.slice(0, max).join(", ")} +${values.length - max}`;
  };

  const rangeLabel = (label: string, from?: string, to?: string) => {
    if (from && to) return `${label}: ${from} a ${to}`;
    if (from) return `${label}: a partir de ${from}`;
    if (to) return `${label}: até ${to}`;
    return null;
  };

  const activeFilterLabels = useMemo(() => {
    const items: string[] = [];

    if (searchValue) items.push(`Busca: ${searchValue}`);
    if (origemFilter.length) items.push(`Origem: ${summarizeList(origemFilter)}`);
    if (cpfMassFilter) items.push(`CPFs: ${cpfMassFilter.split(/\r?\n|[;,]+/).map((v) => v.trim()).filter(Boolean).length}`);
    if (namesMassFilter) items.push(`Nomes: ${namesMassFilter.split(/\r?\n/).map((v) => v.trim()).filter(Boolean).length}`);
    if (phonesMassFilter) items.push(`Telefones: ${phonesMassFilter.split(/\r?\n|[;,]+/).map((v) => v.trim()).filter(Boolean).length}`);
    if (noPhonesFilter) items.push("Sem telefone");
    if (birthMonthFilter.length) items.push(`Mês nasc.: ${summarizeList(birthMonthFilter)}`);

    if (mode === "FGTS") {
      if (eligibleFilter !== "todos") items.push(`Status: ${eligibleFilter === "elegiveis" ? "Elegíveis" : "Não elegíveis"}`);
      if (motivosFilter.length) items.push(`Motivos: ${summarizeList(motivosFilter)}`);
      if (higienizacaoFilter.length) items.push(`Origem hig.: ${summarizeList(higienizacaoFilter)}`);
      const periodoAtualizacao = rangeLabel("Data hig.", dateFromFilter, dateToFilter);
      if (periodoAtualizacao) items.push(periodoAtualizacao);
      const periodoContrato = rangeLabel("Data contrato", contractDateFromFilter, contractDateToFilter);
      if (periodoContrato) items.push(periodoContrato);
      if (vendorsFilter.length) items.push(`Vendedores: ${summarizeList(vendorsFilter)}`);
      if (fgtsAuthorizedFilter !== "todos") {
        const map = {
          autorizado: "Autorizado",
          nao_autorizado: "Não autorizado",
          nao_consultado: "Não consultado",
        } as const;
        items.push(`FGTS Off: ${map[fgtsAuthorizedFilter]}`);
      }
      const fgtsConsulta = rangeLabel("Consulta FGTS Off", fgtsConsultaFromFilter, fgtsConsultaToFilter);
      if (fgtsConsulta) items.push(fgtsConsulta);
    }

    if (mode === "CLT") {
      if (eligibleFilter !== "todos") items.push(`Status: ${eligibleFilter === "elegiveis" ? "Elegíveis" : "Não elegíveis"}`);
      if (cltConsultado !== "todos") items.push(`Consultado: ${cltConsultado === "sim" ? "Sim" : "Não"}`);
      if (cltSituacao !== "todos") {
        const map = {
          nao_encontrado: "Não encontrado",
          elegivel: "Elegível",
          nao_elegivel: "Não elegível",
        } as const;
        items.push(`Situação: ${map[cltSituacao]}`);
      }
      const consulta = rangeLabel("Data consulta", cltConsultaFrom, cltConsultaTo);
      if (consulta) items.push(consulta);
      const admissao = rangeLabel("Admissão", cltAdmissaoFrom, cltAdmissaoTo);
      if (admissao) items.push(admissao);
      if (cltMesesMin || cltMesesMax) items.push(`Meses adm.: ${cltMesesMin || "0"} a ${cltMesesMax || "max"}`);
      const inicioEmp = rangeLabel("Início empregador", cltInicioEmpregadorFrom, cltInicioEmpregadorTo);
      if (inicioEmp) items.push(inicioEmp);
      if (cltCategoriaCodigos.trim()) items.push(`Categorias: ${cltCategoriaCodigos.trim()}`);
      if (cltIdadeMin || cltIdadeMax) items.push(`Idade: ${cltIdadeMin || "0"} a ${cltIdadeMax || "max"}`);
      if (cltSexo.length) items.push(`Sexo: ${summarizeList(cltSexo)}`);
      if (cltRendaMin || cltRendaMax) items.push(`Renda: ${cltRendaMin || "0"} a ${cltRendaMax || "max"}`);
      if (cltBaseMin || cltBaseMax) items.push(`Base margem: ${cltBaseMin || "0"} a ${cltBaseMax || "max"}`);
      if (cltMargemMin || cltMargemMax) items.push(`Margem: ${cltMargemMin || "0"} a ${cltMargemMax || "max"}`);
      if (cltPrestacaoMin || cltPrestacaoMax) items.push(`Prestação: ${cltPrestacaoMin || "0"} a ${cltPrestacaoMax || "max"}`);
      if (cltAtivosMin || cltAtivosMax) items.push(`Ativos: ${cltAtivosMin || "0"} a ${cltAtivosMax || "max"}`);
      if (cltTemAtivos !== "todos") items.push(`Tem ativos: ${cltTemAtivos === "sim" ? "Sim" : "Não"}`);
      if (cltTemLegados !== "todos") items.push(`Tem legados: ${cltTemLegados === "sim" ? "Sim" : "Não"}`);
    }

    if (mode === "MERCANTIL") {
      if (mercantilSituacao !== "todos") items.push(`Situação: ${mercantilSituacao === "consultado" ? "Consultado" : "Sem consulta"}`);
      if (mercantilStatusFilter.length) items.push(`Status: ${summarizeList(mercantilStatusFilter)}`);
      const consulta = rangeLabel("Data consulta", mercantilConsultaFrom, mercantilConsultaTo);
      if (consulta) items.push(consulta);
      if (mercantilParcelaMin || mercantilParcelaMax) items.push(`Parcela: ${mercantilParcelaMin || "0"} a ${mercantilParcelaMax || "max"}`);
      if (mercantilQtdParcelasMin || mercantilQtdParcelasMax) items.push(`Qtd. parcelas: ${mercantilQtdParcelasMin || "0"} a ${mercantilQtdParcelasMax || "max"}`);
      if (mercantilOrigensFilter.length) items.push(`Origem: ${summarizeList(mercantilOrigensFilter)}`);
    }

    return items;
  }, [
    birthMonthFilter, cltAdmissaoFrom, cltAdmissaoTo, cltAtivosMax, cltAtivosMin,
    cltCategoriaCodigos, cltConsultaFrom, cltConsultaTo, cltConsultado, cltIdadeMax,
    cltIdadeMin, cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltBaseMax, cltBaseMin,
    cltMargemMax, cltMargemMin, cltMesesMax, cltMesesMin, cltPrestacaoMax, cltPrestacaoMin,
    cltRendaMax, cltRendaMin, cltSexo, cltSituacao, cltTemAtivos, cltTemLegados,
    contractDateFromFilter, contractDateToFilter, cpfMassFilter, dateFromFilter,
    dateToFilter, eligibleFilter, fgtsAuthorizedFilter, fgtsConsultaFromFilter,
    fgtsConsultaToFilter, higienizacaoFilter, mercantilConsultaFrom, mercantilConsultaTo,
    mercantilOrigensFilter, mercantilParcelaMax, mercantilParcelaMin, mercantilQtdParcelasMax,
    mercantilQtdParcelasMin, mercantilSituacao, mercantilStatusFilter, mode, motivosFilter,
    namesMassFilter, noPhonesFilter, origemFilter, phonesMassFilter, searchValue, vendorsFilter,
  ]);

  const currentSortLabel = sortBy ? sortLabels[sortBy] ?? sortBy : null;
  const sortOptions = mode === "BASE" || mode === "CLT" || mode === "MERCANTIL" ? SORT_OPTIONS[mode] : [];

  return (
    <div className="bg-white border border-gray-200 rounded-xl shadow-sm mb-6">
      <div className="p-4">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          
          {/* Inputs Section (Search & Sort) */}
          <div className="flex flex-col sm:flex-row sm:items-end gap-3 w-full lg:flex-1">
            <label className="w-full sm:max-w-[320px]">
              <span className="mb-1 block text-xs font-medium text-gray-700">Busca rápida</span>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
                <Input
                  type="text"
                  placeholder="Nome, CPF ou Telefone"
                  value={localSearchValue}
                  onChange={(e) => setLocalSearchValue(e.target.value)}
                  className="pl-9 h-10 w-full"
                />
              </div>
            </label>

            {sortOptions.length > 0 && (
              <label className="w-full sm:w-[260px]">
                <span className="mb-1 block text-xs font-medium text-gray-700">Ordenação</span>
                <div className="relative flex items-center">
                  <select
                    value={sortBy}
                    onChange={(event) => onSortByChange(event.target.value as LeadSort)}
                    className="h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  >
                    <option value="" disabled>Ordenar por...</option>
                    {sortOptions.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </div>
              </label>
            )}
          </div>

          {/* Action Buttons Section */}
          <div className="grid grid-cols-2 sm:flex sm:flex-row sm:items-end sm:justify-end gap-2 w-full lg:w-auto shrink-0 self-end">
            <Button
              onClick={() => setIsColumnsModalOpen(true)}
              variant="outline"
              className={cn(
                "h-10 flex items-center justify-center gap-2 px-4 border-gray-200 hover:bg-gray-50 relative w-full sm:w-auto",
                hasCustomColumns && "border-blue-500 bg-blue-50/50 text-blue-700 hover:bg-blue-50"
              )}
              title="Selecionar colunas visíveis"
            >
              <ColumnsIcon className="w-4 h-4" />
              <span>Colunas</span>
              {hasCustomColumns && (
                <span className="absolute top-0 right-0 -mt-1 -mr-1 h-2.5 w-2.5 rounded-full bg-blue-500" />
              )}
            </Button>

            <Button
              onClick={() => !disableFilters && setIsFiltersModalOpen(true)}
              variant="outline"
              disabled={disableFilters}
              className={cn(
                "h-10 flex items-center justify-center gap-2 px-4 border-gray-200 hover:bg-gray-50 relative w-full sm:w-auto",
                hasActiveFilters && !disableFilters && "border-blue-500 bg-blue-50/50 text-blue-700 hover:bg-blue-50"
              )}
              title={disableFilters ? "Filtros indisponíveis para CLT (Mercantil)" : undefined}
            >
              <Filter className="w-4 h-4" />
              <span>Filtros</span>
              {hasActiveFilters && !disableFilters && (
                <span className="absolute top-0 right-0 -mt-1 -mr-1 h-2.5 w-2.5 rounded-full bg-blue-500" />
              )}
            </Button>

            <Button
              onClick={onExportClick}
              variant="outline"
              disabled={disableExport}
              className="h-10 flex items-center justify-center gap-2 px-4 border-gray-200 hover:bg-gray-50 w-full sm:w-auto"
              title={disableExport ? "Exportação indisponível para CLT (Mercantil)" : undefined}
            >
              <Download className="w-4 h-4" />
              <span className="hidden sm:inline">Exportar</span>
            </Button>

            <Button
              onClick={onImportClick}
              className="h-10 flex items-center justify-center gap-2 px-4 bg-blue-600 hover:bg-blue-700 w-full sm:w-auto"
            >
              <Upload className="w-4 h-4" />
              <span className="hidden sm:inline">Importar</span>
            </Button>
          </div>
        </div>

        {/* Indicador de filtros ativos */}
        {hasActiveFilters && !disableFilters && (
          <div className="mt-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 bg-blue-50/50 border border-blue-100 rounded-lg p-3">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-2">
                <Filter className="w-4 h-4 text-blue-600" />
                <span className="text-sm font-medium text-blue-900">
                  Filtros aplicados
                  {typeof filteredCount === "number" && (
                    <span className="text-blue-600 font-normal ml-1">· {filteredCount} leads encontrados</span>
                  )}
                </span>
              </div>
              <div className="flex flex-wrap gap-2">
                {activeFilterLabels.map((label) => (
                  <span key={label} className="inline-flex items-center rounded-md border border-blue-200 bg-white px-2.5 py-1 text-xs font-medium text-blue-800 shadow-sm">
                    {label}
                  </span>
                ))}
                {currentSortLabel && (
                  <span className="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800 shadow-sm">
                    Ordenação: {currentSortLabel}
                  </span>
                )}
              </div>
            </div>
            <Button
              onClick={onClearFilters}
              variant="ghost"
              size="sm"
              className="h-8 text-xs text-blue-700 hover:text-blue-800 hover:bg-blue-100/50 shrink-0 self-start w-full sm:w-auto"
            >
              Limpar todos
            </Button>
          </div>
        )}
      </div>

      <FiltersModal
        mode={mode}
        isOpen={isFiltersModalOpen}
        onClose={() => setIsFiltersModalOpen(false)}
        searchValue={searchValue}
        onSearchChange={onSearchChange}
        eligibleFilter={eligibleFilter}
        onEligibleFilterChange={onEligibleFilterChange}
        contractDateFromFilter={contractDateFromFilter}
        onContractDateFromFilterChange={onContractDateFromFilterChange}
        contractDateToFilter={contractDateToFilter}
        onContractDateToFilterChange={onContractDateToFilterChange}
        motivosFilter={motivosFilter}
        onMotivosFilterChange={onMotivosFilterChange}
        origemFilter={origemFilter}
        onOrigemFilterChange={onOrigemFilterChange}
        higienizacaoFilter={higienizacaoFilter}
        onHigienizacaoFilterChange={onHigienizacaoFilterChange}
        availableHigienizacoes={availableHigienizacoes}
        cpfMassFilter={cpfMassFilter}
        onCpfMassFilterChange={onCpfMassFilterChange}
        namesMassFilter={namesMassFilter}
        onNamesMassFilterChange={onNamesMassFilterChange}
        phonesMassFilter={phonesMassFilter}
        onPhonesMassFilterChange={onPhonesMassFilterChange}
        noPhonesFilter={noPhonesFilter}
        onNoPhonesFilterChange={onNoPhonesFilterChange}
        dateFromFilter={dateFromFilter}
        onDateFromFilterChange={onDateFromFilterChange}
        dateToFilter={dateToFilter}
        onDateToFilterChange={onDateToFilterChange}
        vendorsFilter={vendorsFilter}
        onVendorsFilterChange={onVendorsFilterChange}
        availableVendors={availableVendors}
        birthMonthFilter={birthMonthFilter}
        onBirthMonthFilterChange={onBirthMonthFilterChange}
        onApplyFilters={onApplyFilters}
        onClearFilters={onClearFilters}
        availableMotivos={availableMotivos}
        availableOrigens={availableOrigens}
        fgtsAuthorizedFilter={fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={onFgtsAuthorizedFilterChange}
        fgtsConsultaFromFilter={fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={onFgtsConsultaFromFilterChange}
        fgtsConsultaToFilter={fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={onFgtsConsultaToFilterChange}
        cltConsultado={cltConsultado}
        onCltConsultadoChange={onCltConsultadoChange}
        cltSituacao={cltSituacao}
        onCltSituacaoChange={onCltSituacaoChange}
        cltConsultaFrom={cltConsultaFrom}
        onCltConsultaFromChange={onCltConsultaFromChange}
        cltConsultaTo={cltConsultaTo}
        onCltConsultaToChange={onCltConsultaToChange}
        cltAdmissaoFrom={cltAdmissaoFrom}
        onCltAdmissaoFromChange={onCltAdmissaoFromChange}
        cltAdmissaoTo={cltAdmissaoTo}
        onCltAdmissaoToChange={onCltAdmissaoToChange}
        cltMesesMin={cltMesesMin}
        onCltMesesMinChange={onCltMesesMinChange}
        cltMesesMax={cltMesesMax}
        onCltMesesMaxChange={onCltMesesMaxChange}
        cltInicioEmpregadorFrom={cltInicioEmpregadorFrom}
        onCltInicioEmpregadorFromChange={onCltInicioEmpregadorFromChange}
        cltInicioEmpregadorTo={cltInicioEmpregadorTo}
        onCltInicioEmpregadorToChange={onCltInicioEmpregadorToChange}
        cltCategoriaCodigos={cltCategoriaCodigos}
        onCltCategoriaCodigosChange={onCltCategoriaCodigosChange}
        cltIdadeMin={cltIdadeMin}
        onCltIdadeMinChange={onCltIdadeMinChange}
        cltIdadeMax={cltIdadeMax}
        onCltIdadeMaxChange={onCltIdadeMaxChange}
        cltSexo={cltSexo}
        onCltSexoChange={onCltSexoChange}
        cltRendaMin={cltRendaMin}
        onCltRendaMinChange={onCltRendaMinChange}
        cltRendaMax={cltRendaMax}
        onCltRendaMaxChange={onCltRendaMaxChange}
        cltBaseMin={cltBaseMin}
        onCltBaseMinChange={onCltBaseMinChange}
        cltBaseMax={cltBaseMax}
        onCltBaseMaxChange={onCltBaseMaxChange}
        cltMargemMin={cltMargemMin}
        onCltMargemMinChange={onCltMargemMinChange}
        cltMargemMax={cltMargemMax}
        onCltMargemMaxChange={onCltMargemMaxChange}
        cltPrestacaoMin={cltPrestacaoMin}
        onCltPrestacaoMinChange={onCltPrestacaoMinChange}
        cltPrestacaoMax={cltPrestacaoMax}
        onCltPrestacaoMaxChange={onCltPrestacaoMaxChange}
        cltAtivosMin={cltAtivosMin}
        onCltAtivosMinChange={onCltAtivosMinChange}
        cltAtivosMax={cltAtivosMax}
        onCltAtivosMaxChange={onCltAtivosMaxChange}
        cltTemAtivos={cltTemAtivos}
        onCltTemAtivosChange={onCltTemAtivosChange}
        cltTemLegados={cltTemLegados}
        onCltTemLegadosChange={onCltTemLegadosChange}
        mercantilSituacao={mercantilSituacao}
        onMercantilSituacaoChange={onMercantilSituacaoChange}
        mercantilStatusFilter={mercantilStatusFilter}
        onMercantilStatusFilterChange={onMercantilStatusFilterChange}
        mercantilConsultaFrom={mercantilConsultaFrom}
        onMercantilConsultaFromChange={onMercantilConsultaFromChange}
        mercantilConsultaTo={mercantilConsultaTo}
        onMercantilConsultaToChange={onMercantilConsultaToChange}
        mercantilParcelaMin={mercantilParcelaMin}
        onMercantilParcelaMinChange={onMercantilParcelaMinChange}
        mercantilParcelaMax={mercantilParcelaMax}
        onMercantilParcelaMaxChange={onMercantilParcelaMaxChange}
        mercantilQtdParcelasMin={mercantilQtdParcelasMin}
        onMercantilQtdParcelasMinChange={onMercantilQtdParcelasMinChange}
        mercantilQtdParcelasMax={mercantilQtdParcelasMax}
        onMercantilQtdParcelasMaxChange={onMercantilQtdParcelasMaxChange}
        mercantilOrigensFilter={mercantilOrigensFilter}
        onMercantilOrigensFilterChange={onMercantilOrigensFilterChange}
        availableMercantilOrigens={availableMercantilOrigens}
        availableMercantilStatuses={availableMercantilStatuses}
      />

      <ColumnsModal
        isOpen={isColumnsModalOpen}
        onClose={() => setIsColumnsModalOpen(false)}
        mode={mode}
        visibleColumns={currentVisible}
        onSave={onSaveVisible}
        defaultVisibleColumns={currentDefaults}
      />
    </div>
  );
};
