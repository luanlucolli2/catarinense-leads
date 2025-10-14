import { Search, Upload, Download, Filter } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { useState } from "react";
import { FiltersModal } from "./FiltersModal";

interface LeadsControlsProps {
  /** Modo atual da listagem / filtros */
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

  /* 🎂 meses de aniversário */
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

  /** ➕ FGTS OFF (tri-estado) */
  fgtsAuthorizedFilter: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado";
  onFgtsAuthorizedFilterChange: (v: "todos" | "autorizado" | "nao_autorizado" | "nao_consultado") => void;
  fgtsConsultaFromFilter: string;
  onFgtsConsultaFromFilterChange: (v: string) => void;
  fgtsConsultaToFilter: string;
  onFgtsConsultaToFilterChange: (v: string) => void;

  /** ➕ CLT (filtros específicos) */
  cltConsultado: "todos" | "sim" | "nao";
  onCltConsultadoChange: (v: "todos" | "sim" | "nao") => void;
  cltElegivel: "todos" | "sim" | "nao";
  onCltElegivelChange: (v: "todos" | "sim" | "nao") => void;
  cltNotFound: "todos" | "sim" | "nao";
  onCltNotFoundChange: (v: "todos" | "sim" | "nao") => void;

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

  /** Mantidos por compat (não usados na UI): */
 

  /** Somente booleano de legados */
  cltTemLegados: "todos" | "sim" | "nao";
  onCltTemLegadosChange: (v: "todos" | "sim" | "nao") => void;
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
  /* 🎂 */
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
  // ➕ FGTS OFF (tri-estado)
  fgtsAuthorizedFilter,
  onFgtsAuthorizedFilterChange,
  fgtsConsultaFromFilter,
  onFgtsConsultaFromFilterChange,
  fgtsConsultaToFilter,
  onFgtsConsultaToFilterChange,

  // ➕ CLT específicos
  cltConsultado,
  onCltConsultadoChange,
  cltElegivel,
  onCltElegivelChange,
  cltNotFound,
  onCltNotFoundChange,
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
}: LeadsControlsProps) => {
  const [isFiltersModalOpen, setIsFiltersModalOpen] = useState(false);

  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm mb-6">
      <div className="px-4 py-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
          {/* Search Field */}
          <div className="relative flex-1 min-w-0 max-w-xs">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <Input
              type="text"
              placeholder="Nome, CPF ou Telefone"
              value={searchValue}
              onChange={(e) => onSearchChange(e.target.value)}
              className="pl-10 w-full"
            />
          </div>

          {/* Action Buttons */}
          <div className="flex items-center gap-2">
            <Button
              onClick={() => setIsFiltersModalOpen(true)}
              variant="outline"
              size="sm"
              className={cn(
                "flex items-center gap-2 px-3 border-gray-300 hover:bg-gray-50 relative",
                hasActiveFilters && "border-blue-500 bg-blue-50 text-blue-700"
              )}
            >
              <Filter className="w-4 h-4" />
              Filtros
              {hasActiveFilters && (
                <span className="absolute -top-1 -right-1 w-2 h-2 bg-blue-500 rounded-full"></span>
              )}
            </Button>

            <Button
              onClick={onExportClick}
              variant="outline"
              size="sm"
              className="flex items-center gap-2 px-3 border-gray-300 hover:bg-gray-50"
            >
              <Download className="w-4 h-4" />
              Exportar
            </Button>

            <Button
              onClick={onImportClick}
              size="sm"
              className="flex items-center gap-2 px-3 bg-blue-600 hover:bg-blue-700"
            >
              <Upload className="w-4 h-4" />
              Importar
            </Button>
          </div>
        </div>

        {/* Active Filters Indicator */}
        {hasActiveFilters && (
          <div className="flex items-center justify-between bg-blue-50 border border-blue-200 rounded-lg p-3 mt-4">
            <div className="flex items-center space-x-2">
              <Filter className="w-4 h-4 text-blue-600" />
              <span className="text-sm text-blue-800 font-medium">Filtros ativos aplicados</span>
            </div>
            <Button
              onClick={onClearFilters}
              variant="outline"
              size="sm"
              className="text-xs border-blue-300 text-blue-700 hover:bg-blue-100"
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
        /* 🎂 */
        birthMonthFilter={birthMonthFilter}
        onBirthMonthFilterChange={onBirthMonthFilterChange}
        onApplyFilters={onApplyFilters}
        onClearFilters={onClearFilters}
        availableMotivos={availableMotivos}
        availableOrigens={availableOrigens}
        // ➕ FGTS OFF (só no FGTS)
        fgtsAuthorizedFilter={fgtsAuthorizedFilter}
        onFgtsAuthorizedFilterChange={onFgtsAuthorizedFilterChange}
        fgtsConsultaFromFilter={fgtsConsultaFromFilter}
        onFgtsConsultaFromFilterChange={onFgtsConsultaFromFilterChange}
        fgtsConsultaToFilter={fgtsConsultaToFilter}
        onFgtsConsultaToFilterChange={onFgtsConsultaToFilterChange}
        /* CLT específicos */
        cltConsultado={cltConsultado}
        onCltConsultadoChange={onCltConsultadoChange}
        cltElegivel={cltElegivel}
        onCltElegivelChange={onCltElegivelChange}
        cltNotFound={cltNotFound}
        onCltNotFoundChange={onCltNotFoundChange}
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
    </div>
  );
};
