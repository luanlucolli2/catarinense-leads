import { Search, Upload, Download, Filter, Columns as ColumnsIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { useState, useMemo } from "react";
import { FiltersModal } from "./FiltersModal";
import { ColumnsModal } from "./columns/ColumnsModal";

interface LeadsControlsProps {
  mode: "FGTS" | "CLT";

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

  dateFromFilter: string;
  onDateFromFilterChange: (value: string) => void;
  dateToFilter: string;
  onDateToFilterChange: (value: string) => void;

  birthMonthFilter: string[];
  onBirthMonthFilterChange: (values: string[]) => void;

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

  visibleColumnsFGTS: string[];
  onVisibleColumnsFGTSChange: (cols: string[]) => void;
  visibleColumnsCLT: string[];
  onVisibleColumnsCLTChange: (cols: string[]) => void;

  defaultVisibleColumnsFGTS: string[];
  defaultVisibleColumnsCLT: string[];
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
  dateFromFilter,
  onDateFromFilterChange,
  dateToFilter,
  onDateToFilterChange,
  birthMonthFilter,
  onBirthMonthFilterChange,
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
  visibleColumnsFGTS,
  onVisibleColumnsFGTSChange,
  visibleColumnsCLT,
  onVisibleColumnsCLTChange,
  defaultVisibleColumnsFGTS,
  defaultVisibleColumnsCLT,
}: LeadsControlsProps) => {
  const [isFiltersModalOpen, setIsFiltersModalOpen] = useState(false);
  const [isColumnsModalOpen, setIsColumnsModalOpen] = useState(false);

  const currentVisible = mode === "FGTS" ? visibleColumnsFGTS : visibleColumnsCLT;
  const currentDefaults = mode === "FGTS" ? defaultVisibleColumnsFGTS : defaultVisibleColumnsCLT;

  const onSaveVisible = (cols: string[]) => {
    if (mode === "FGTS") onVisibleColumnsFGTSChange(cols);
    else onVisibleColumnsCLTChange(cols);
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

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm mb-6">
      <div className="px-3 sm:px-4 py-3 sm:py-4">
        {/* Linha 1: busca */}
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 justify-between">
          <div className="relative w-full sm:flex-1 min-w-0">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
            <Input
              type="text"
              placeholder="Nome, CPF ou Telefone"
              value={searchValue}
              onChange={(e) => onSearchChange(e.target.value)}
              className="pl-10 w-full"
            />
          </div>

          {/* Linha 2: ações – grid no mobile, linha no desktop */}
          <div className="w-full sm:w-auto">
            <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center sm:gap-2">
              <Button
                onClick={() => setIsColumnsModalOpen(true)}
                variant="outline"
                size="sm"
                className={cn(
                  "flex items-center justify-center gap-2 px-3 border-gray-300 hover:bg-gray-50 relative w-full sm:w-auto",
                  hasCustomColumns && "border-blue-500 bg-blue-50 text-blue-700"
                )}
                title="Selecionar colunas visíveis"
              >
                <ColumnsIcon className="w-4 h-4" />
                <span className="hidden xs:inline sm:inline">Colunas</span>
                {hasCustomColumns && (
                  <span className="absolute -top-1 -right-1 w-2 h-2 bg-blue-500 rounded-full" />
                )}
              </Button>

              <Button
                onClick={() => setIsFiltersModalOpen(true)}
                variant="outline"
                size="sm"
                className={cn(
                  "flex items-center justify-center gap-2 px-3 border-gray-300 hover:bg-gray-50 relative w-full sm:w-auto",
                  hasActiveFilters && "border-blue-500 bg-blue-50 text-blue-700"
                )}
              >
                <Filter className="w-4 h-4" />
                <span className="hidden xs:inline sm:inline">Filtros</span>
                {hasActiveFilters && (
                  <span className="absolute -top-1 -right-1 w-2 h-2 bg-blue-500 rounded-full" />
                )}
              </Button>

              <Button
                onClick={onExportClick}
                variant="outline"
                size="sm"
                className="flex items-center justify-center gap-2 px-3 border-gray-300 hover:bg-gray-50 w-full sm:w-auto"
              >
                <Download className="w-4 h-4" />
                <span className="hidden xs:inline sm:inline">Exportar</span>
              </Button>

              <Button
                onClick={onImportClick}
                size="sm"
                className="flex items-center justify-center gap-2 px-3 bg-blue-600 hover:bg-blue-700 w-full sm:w-auto"
              >
                <Upload className="w-4 h-4" />
                <span className="hidden xs:inline sm:inline">Importar</span>
              </Button>
            </div>
          </div>
        </div>

        {/* Indicador de filtros ativos */}
        {hasActiveFilters && (
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-blue-50 border border-blue-200 rounded-lg p-3 mt-4">
            <div className="flex items-center gap-2">
              <Filter className="w-4 h-4 text-blue-600" />
              <span className="text-sm text-blue-800 font-medium">Filtros ativos aplicados</span>
            </div>
            <Button
              onClick={onClearFilters}
              variant="outline"
              size="sm"
              className="text-xs border-blue-300 text-blue-700 hover:bg-blue-100 self-start sm:self-auto"
            >
              Limpar
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
