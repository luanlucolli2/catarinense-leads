import { Search, Upload, Download, Filter, Columns as ColumnsIcon, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { useState, useMemo, useEffect } from "react";
import { FiltersModal } from "./FiltersModal";
import { ColumnsModal } from "./columns/ColumnsModal";
import factaLogo from "@/assets/factalogo.png";
import mercantilLogo from "@/assets/mercantilogo.png";
import uy3Logo from "@/assets/logouy3png.png";
import type { LeadBankCombinationMode, LeadBankKey, LeadSort } from "@/api/leads";

interface LeadsControlsProps {
  mode: "360" | "BASE" | "FGTS" | "CLT" | "MERCANTIL" | "UY3";

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
  withPhonesFilter: boolean;
  onWithPhonesFilterChange: (value: boolean) => void;
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

  cltSituacao: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado";
  onCltSituacaoChange: (v: "todos" | "nao_encontrado" | "elegivel" | "nao_elegivel" | "aprovado" | "nao_aprovado") => void;

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

  mercantilSituacao: "todos" | "aprovado" | "nao_aprovado";
  onMercantilSituacaoChange: (v: "todos" | "aprovado" | "nao_aprovado") => void;
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
  selectedBanks: LeadBankKey[];
  onSelectedBanksChange: (values: LeadBankKey[]) => void;
  bankCombinationMode: LeadBankCombinationMode;
  onBankCombinationModeChange: (value: LeadBankCombinationMode) => void;
  uy3Situacao: "todos" | "aprovado" | "nao_aprovado";
  onUy3SituacaoChange: (value: "todos" | "aprovado" | "nao_aprovado") => void;
  uy3ConsultaFrom: string;
  onUy3ConsultaFromChange: (value: string) => void;
  uy3ConsultaTo: string;
  onUy3ConsultaToChange: (value: string) => void;
  uy3MesesAdmissaoMin: string;
  onUy3MesesAdmissaoMinChange: (value: string) => void;
  uy3MesesAdmissaoMax: string;
  onUy3MesesAdmissaoMaxChange: (value: string) => void;
  uy3MargemMin: string;
  onUy3MargemMinChange: (value: string) => void;
  uy3MargemMax: string;
  onUy3MargemMaxChange: (value: string) => void;
  uy3ValorLiberadoMin: string;
  onUy3ValorLiberadoMinChange: (value: string) => void;
  uy3ValorLiberadoMax: string;
  onUy3ValorLiberadoMaxChange: (value: string) => void;
  uy3NumeroParcelasMin: string;
  onUy3NumeroParcelasMinChange: (value: string) => void;
  uy3NumeroParcelasMax: string;
  onUy3NumeroParcelasMaxChange: (value: string) => void;

  visibleColumns360: string[];
  onVisibleColumns360Change: (cols: string[]) => void;
  visibleColumnsBASE: string[];
  onVisibleColumnsBASEChange: (cols: string[]) => void;
  visibleColumnsFGTS: string[];
  onVisibleColumnsFGTSChange: (cols: string[]) => void;
  visibleColumnsCLT: string[];
  onVisibleColumnsCLTChange: (cols: string[]) => void;
  visibleColumnsMERCANTIL: string[];
  onVisibleColumnsMERCANTILChange: (cols: string[]) => void;
  visibleColumnsUY3: string[];
  onVisibleColumnsUY3Change: (cols: string[]) => void;

  defaultVisibleColumns360: string[];
  defaultVisibleColumnsBASE: string[];
  defaultVisibleColumnsFGTS: string[];
  defaultVisibleColumnsCLT: string[];
  defaultVisibleColumnsMERCANTIL: string[];
  defaultVisibleColumnsUY3: string[];
  stickyIdentityColumns360: boolean;
  onStickyIdentityColumns360Change: (value: boolean) => void;

  disableFilters?: boolean;
  disableExport?: boolean;
}

const SORT_OPTIONS: Record<"360" | "BASE" | "CLT" | "MERCANTIL" | "UY3", { value: LeadSort; label: string }[]> = {
  360: [
    { value: "lead_updated_at", label: "Atualizado recentemente" },
    { value: "lead_created_at", label: "Criado recentemente" },
  ],
  BASE: [
    { value: "lead_updated_at", label: "Atualizado recentemente" },
    { value: "lead_created_at", label: "Criado recentemente" },
  ],
  CLT: [
    { value: "clt_consulted_at", label: "Consulta recente (CLT)" },
    { value: "clt_updated_at", label: "Atualizado recentemente (CLT)" },
    { value: "lead_updated_at", label: "Cadastro atualizado" },
  ],
  MERCANTIL: [
    { value: "mercantil_consulted_at", label: "Consulta recente (Mercantil)" },
    { value: "lead_updated_at", label: "Cadastro atualizado" },
  ],
  UY3: [
    { value: "uy3_consulted_at", label: "Atualizado recentemente (UY3)" },
    { value: "lead_updated_at", label: "Cadastro atualizado" },
  ],
};

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
  withPhonesFilter,
  onWithPhonesFilterChange,
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
  selectedBanks,
  onSelectedBanksChange,
  bankCombinationMode,
  onBankCombinationModeChange,
  uy3Situacao,
  onUy3SituacaoChange,
  uy3ConsultaFrom,
  onUy3ConsultaFromChange,
  uy3ConsultaTo,
  onUy3ConsultaToChange,
  uy3MesesAdmissaoMin,
  onUy3MesesAdmissaoMinChange,
  uy3MesesAdmissaoMax,
  onUy3MesesAdmissaoMaxChange,
  uy3MargemMin,
  onUy3MargemMinChange,
  uy3MargemMax,
  onUy3MargemMaxChange,
  uy3ValorLiberadoMin,
  onUy3ValorLiberadoMinChange,
  uy3ValorLiberadoMax,
  onUy3ValorLiberadoMaxChange,
  uy3NumeroParcelasMin,
  onUy3NumeroParcelasMinChange,
  uy3NumeroParcelasMax,
  onUy3NumeroParcelasMaxChange,
  visibleColumns360,
  onVisibleColumns360Change,
  visibleColumnsBASE,
  onVisibleColumnsBASEChange,
  visibleColumnsFGTS,
  onVisibleColumnsFGTSChange,
  visibleColumnsCLT,
  onVisibleColumnsCLTChange,
  visibleColumnsMERCANTIL,
  onVisibleColumnsMERCANTILChange,
  visibleColumnsUY3,
  onVisibleColumnsUY3Change,
  defaultVisibleColumns360,
  defaultVisibleColumnsBASE,
  defaultVisibleColumnsFGTS,
  defaultVisibleColumnsCLT,
  defaultVisibleColumnsMERCANTIL,
  defaultVisibleColumnsUY3,
  stickyIdentityColumns360,
  onStickyIdentityColumns360Change,
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
    mode === "360"
      ? visibleColumns360
      : mode === "BASE"
      ? visibleColumnsBASE
      : mode === "FGTS"
      ? visibleColumnsFGTS
      : mode === "CLT"
        ? visibleColumnsCLT
        : mode === "MERCANTIL"
          ? visibleColumnsMERCANTIL
          : visibleColumnsUY3;

  const currentDefaults =
    mode === "360"
      ? defaultVisibleColumns360
      : mode === "BASE"
      ? defaultVisibleColumnsBASE
      : mode === "FGTS"
      ? defaultVisibleColumnsFGTS
      : mode === "CLT"
        ? defaultVisibleColumnsCLT
        : mode === "MERCANTIL"
          ? defaultVisibleColumnsMERCANTIL
          : defaultVisibleColumnsUY3;

  const onSaveVisible = (cols: string[]) => {
    if (mode === "360") onVisibleColumns360Change(cols);
    else if (mode === "BASE") onVisibleColumnsBASEChange(cols);
    else if (mode === "FGTS") onVisibleColumnsFGTSChange(cols);
    else if (mode === "CLT") onVisibleColumnsCLTChange(cols);
    else if (mode === "MERCANTIL") onVisibleColumnsMERCANTILChange(cols);
    else onVisibleColumnsUY3Change(cols);
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
    lead_updated_at: "Atualizado recentemente",
    lead_created_at: "Criado recentemente",
    clt_updated_at: "Atualizado recentemente (CLT)",
    clt_consulted_at: "Consulta recente (CLT)",
    mercantil_updated_at: "Atualizado recentemente (Mercantil)",
    mercantil_consulted_at: "Consulta recente (Mercantil)",
    uy3_consulted_at: "Atualizado recentemente (UY3)",
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

  const currentSortLabel =
    mode === "UY3" && sortBy === "lead_updated_at"
      ? "Atualizado recentemente"
      : sortBy
        ? sortLabels[sortBy] ?? sortBy
        : null;

  const sortOptions = mode === "360" || mode === "BASE" || mode === "CLT" || mode === "MERCANTIL" || mode === "UY3" ? SORT_OPTIONS[mode] : [];

  const activeFilterGroups360 = useMemo(() => {
    if (mode !== "360") return [];

    const bankLabels: Record<LeadBankKey, string> = {
      fgts: "FGTS",
      clt: "Facta",
      mercantil: "Mercantil",
      uy3: "UY3",
    };

    const general: string[] = [];
    const clt: string[] = [];
    const mercantil: string[] = [];
    const uy3: string[] = [];

    if (searchValue) general.push(`Busca: ${searchValue}`);
    if (currentSortLabel) general.push(`Ordenação: ${currentSortLabel}`);
    if (withPhonesFilter) general.push("Com telefone");
    if (noPhonesFilter) general.push("Sem telefone");
    if (selectedBanks.length) {
      general.push(`Fontes: ${selectedBanks.filter((bank) => bank !== "fgts").map((bank) => bankLabels[bank]).join(", ")}`);
      general.push(`Combinação: ${bankCombinationMode === "all" ? "Todos os bancos" : "Qualquer banco"}`);
    }

    if (cltSituacao !== "todos") clt.push(`Situação: ${cltSituacao === "aprovado" ? "Aprovado" : "Não aprovado"}`);
    const cltConsulta = rangeLabel("Consulta", cltConsultaFrom, cltConsultaTo);
    if (cltConsulta) clt.push(cltConsulta);
    if (cltMesesMin || cltMesesMax) clt.push(`Meses admissão: ${cltMesesMin || "0"} a ${cltMesesMax || "max"}`);
    if (cltMargemMin || cltMargemMax) clt.push(`Margem: ${cltMargemMin || "0"} a ${cltMargemMax || "max"}`);
    if (cltPrestacaoMin || cltPrestacaoMax) clt.push(`Parcelas: ${cltPrestacaoMin || "0"} a ${cltPrestacaoMax || "max"}`);

    if (mercantilSituacao !== "todos") mercantil.push(`Situação: ${mercantilSituacao === "aprovado" ? "Aprovado" : "Não aprovado"}`);
    const mercantilConsulta = rangeLabel("Consulta", mercantilConsultaFrom, mercantilConsultaTo);
    if (mercantilConsulta) mercantil.push(mercantilConsulta);
    if (mercantilParcelaMin || mercantilParcelaMax) mercantil.push(`Valor parcela: ${mercantilParcelaMin || "0"} a ${mercantilParcelaMax || "max"}`);
    if (mercantilQtdParcelasMin || mercantilQtdParcelasMax) mercantil.push(`Qtd. parcelas: ${mercantilQtdParcelasMin || "0"} a ${mercantilQtdParcelasMax || "max"}`);

    if (uy3Situacao !== "todos") uy3.push(`Situação: ${uy3Situacao === "aprovado" ? "Aprovado" : "Não aprovado"}`);
    const uy3Consulta = rangeLabel("Consulta", uy3ConsultaFrom, uy3ConsultaTo);
    if (uy3Consulta) uy3.push(uy3Consulta);
    if (uy3MesesAdmissaoMin || uy3MesesAdmissaoMax) uy3.push(`Meses admissão: ${uy3MesesAdmissaoMin || "0"} a ${uy3MesesAdmissaoMax || "max"}`);
    if (uy3MargemMin || uy3MargemMax) uy3.push(`Margem: ${uy3MargemMin || "0"} a ${uy3MargemMax || "max"}`);
    if (uy3ValorLiberadoMin || uy3ValorLiberadoMax) uy3.push(`Valor liberado: ${uy3ValorLiberadoMin || "0"} a ${uy3ValorLiberadoMax || "max"}`);
    if (uy3NumeroParcelasMin || uy3NumeroParcelasMax) uy3.push(`Qtd. parcelas: ${uy3NumeroParcelasMin || "0"} a ${uy3NumeroParcelasMax || "max"}`);

    return [
      { title: "Gerais", labels: general, imageSrc: null },
      { title: "Facta", labels: clt, imageSrc: factaLogo },
      { title: "Mercantil", labels: mercantil, imageSrc: mercantilLogo },
      { title: "UY3", labels: uy3, imageSrc: uy3Logo },
    ].filter((group) => group.labels.length > 0);
  }, [
    mode, searchValue, currentSortLabel, withPhonesFilter, noPhonesFilter, selectedBanks, bankCombinationMode,
    cltSituacao, cltConsultaFrom, cltConsultaTo, cltMesesMin, cltMesesMax, cltMargemMin, cltMargemMax, cltPrestacaoMin, cltPrestacaoMax,
    mercantilSituacao, mercantilConsultaFrom, mercantilConsultaTo, mercantilParcelaMin, mercantilParcelaMax, mercantilQtdParcelasMin, mercantilQtdParcelasMax,
    uy3Situacao, uy3ConsultaFrom, uy3ConsultaTo, uy3MesesAdmissaoMin, uy3MesesAdmissaoMax, uy3MargemMin, uy3MargemMax, uy3ValorLiberadoMin, uy3ValorLiberadoMax, uy3NumeroParcelasMin, uy3NumeroParcelasMax,
  ]);

  const activeFilterLabels = useMemo(() => {
    if (mode === "360") return [];
    const items: string[] = [];

    if (searchValue) items.push(`Busca: ${searchValue}`);
    if (origemFilter.length) items.push(`Origem: ${summarizeList(origemFilter)}`);
    if (cpfMassFilter) items.push(`CPFs em lote (${cpfMassFilter.split(/\r?\n|[;,]+/).filter(Boolean).length})`);
    if (namesMassFilter) items.push(`Nomes em lote (${namesMassFilter.split(/\r?\n/).filter(Boolean).length})`);
    if (phonesMassFilter) items.push(`Telefones em lote (${phonesMassFilter.split(/\r?\n|[;,]+/).filter(Boolean).length})`);
    if (withPhonesFilter) items.push("Com telefone");
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
    cltCategoriaCodigos, cltConsultaFrom, cltConsultaTo, cltIdadeMax,
    cltIdadeMin, cltInicioEmpregadorFrom, cltInicioEmpregadorTo, cltBaseMax, cltBaseMin,
    cltMargemMax, cltMargemMin, cltMesesMax, cltMesesMin, cltPrestacaoMax, cltPrestacaoMin,
    cltRendaMax, cltRendaMin, cltSexo, cltSituacao, cltTemAtivos, cltTemLegados,
    contractDateFromFilter, contractDateToFilter, cpfMassFilter, dateFromFilter,
    dateToFilter, eligibleFilter, fgtsAuthorizedFilter, fgtsConsultaFromFilter,
    fgtsConsultaToFilter, higienizacaoFilter, mercantilConsultaFrom, mercantilConsultaTo,
    mercantilOrigensFilter, mercantilParcelaMax, mercantilParcelaMin, mercantilQtdParcelasMax,
    mercantilQtdParcelasMin, mercantilSituacao, mercantilStatusFilter, mode, motivosFilter,
    namesMassFilter, noPhonesFilter, origemFilter, phonesMassFilter, searchValue, vendorsFilter, withPhonesFilter,
  ]);

  return (
    <div className="bg-white border border-gray-200 rounded-xl shadow-sm mb-6 flex flex-col overflow-hidden">
      <div className="p-4 sm:p-5">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          
          {/* Inputs Section (Search & Sort) */}
          <div className="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:flex-1">
            <div className="relative w-full sm:max-w-[320px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
              <Input
                type="text"
                placeholder="Nome, CPF ou Telefone"
                value={localSearchValue}
                onChange={(e) => setLocalSearchValue(e.target.value)}
                className="pl-9 h-10 w-full transition-shadow focus:ring-2 focus:ring-blue-100 focus:border-blue-400"
              />
            </div>

            {sortOptions.length > 0 && (
              <div className="relative w-full sm:max-w-[260px]">
                <select
                  value={sortBy}
                  onChange={(event) => onSortByChange(event.target.value as LeadSort)}
                  className="h-10 w-full appearance-none rounded-md border border-gray-300 bg-white pl-3 pr-8 text-sm text-gray-700 shadow-sm outline-none transition-shadow hover:border-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                >
                  <option value="" disabled>Ordenar resultados por...</option>
                  {sortOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
                <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                  <svg className="h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
                </div>
              </div>
            )}
          </div>

          {/* Action Buttons Section */}
          <div className="grid grid-cols-2 sm:flex sm:flex-row sm:items-center justify-end gap-2 shrink-0">
            <Button
              onClick={() => setIsColumnsModalOpen(true)}
              variant="outline"
              className={cn(
                "h-10 flex items-center justify-center gap-2 px-4 relative transition-colors shadow-sm",
                hasCustomColumns 
                  ? "border-blue-300 bg-blue-50 text-blue-700 hover:bg-blue-100 hover:text-blue-800" 
                  : "border-gray-200 text-gray-700 hover:bg-gray-50"
              )}
            >
              <ColumnsIcon className="w-4 h-4" />
              <span>Colunas</span>
              {hasCustomColumns && (
                <span className="absolute -top-1 -right-1 h-3 w-3 rounded-full bg-blue-500 ring-2 ring-white" />
              )}
            </Button>

            <Button
              onClick={() => !disableFilters && setIsFiltersModalOpen(true)}
              variant="outline"
              disabled={disableFilters}
              className={cn(
                "h-10 flex items-center justify-center gap-2 px-4 relative transition-colors shadow-sm",
                hasActiveFilters && !disableFilters 
                  ? "border-blue-300 bg-blue-50 text-blue-700 hover:bg-blue-100 hover:text-blue-800"
                  : "border-gray-200 text-gray-700 hover:bg-gray-50"
              )}
              title={disableFilters ? "Filtros indisponíveis neste modo" : undefined}
            >
              <Filter className="w-4 h-4" />
              <span>Filtros</span>
              {hasActiveFilters && !disableFilters && (
                <span className="absolute -top-1 -right-1 h-3 w-3 rounded-full bg-blue-500 ring-2 ring-white" />
              )}
            </Button>

            <Button
              onClick={onExportClick}
              variant="outline"
              disabled={disableExport}
              className="h-10 flex items-center justify-center gap-2 px-4 border-gray-200 text-gray-700 hover:bg-gray-50 shadow-sm"
              title={disableExport ? "Exportação indisponível neste modo" : undefined}
            >
              <Download className="w-4 h-4" />
              <span className="hidden sm:inline">Exportar</span>
            </Button>

            <Button
              onClick={onImportClick}
              className="h-10 flex items-center justify-center gap-2 px-4 bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition-colors"
            >
              <Upload className="w-4 h-4" />
              <span className="hidden sm:inline">Importar</span>
            </Button>
          </div>
        </div>
      </div>

      {/* Seção de Filtros Ativos (Rodapé do Header) */}
      {(hasActiveFilters || typeof filteredCount === "number") && !disableFilters && (
        <div className="bg-white px-4 pb-4 sm:px-5 sm:pb-5">
          <div className="border-t border-gray-200 bg-gradient-to-r from-white to-transparent pt-4 sm:pt-5">
          <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            
            <div className="flex-1 min-w-0">
              <div className="flex flex-wrap items-center gap-2 mb-3">
                <div className="flex items-center gap-2 text-gray-800 font-medium text-sm">
                  <Filter className="w-4 h-4 text-gray-500" />
                  {hasActiveFilters ? "Filtros aplicados" : "Resultado atual"}
                </div>
                {typeof filteredCount === "number" && (
                  <>
                    <span className="text-gray-300">•</span>
                    <span className="text-sm font-medium text-blue-600 bg-blue-100/50 px-2 py-0.5 rounded-full">
                      {filteredCount} leads encontrados
                    </span>
                  </>
                )}
              </div>

              {/* Modo 360 - Renderização por grupos limpos */}
              {hasActiveFilters && mode === "360" ? (
                <div className="flex flex-wrap gap-x-8 gap-y-4">
                  {activeFilterGroups360.map((group) => (
                    <div key={group.title} className="flex flex-col gap-1.5">
                      <span className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                        {group.imageSrc ? <img src={group.imageSrc} alt="" className="h-3.5 w-3.5 object-contain" /> : null}
                        {group.title}
                      </span>
                      <div className="flex flex-wrap gap-1.5">
                        {group.labels.map((label) => (
                          <span key={`${group.title}-${label}`} className="inline-flex items-center rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-[0_1px_2px_rgba(0,0,0,0.02)]">
                            {label}
                          </span>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              ) : hasActiveFilters ? (
                /* Outros Modos - Flat list */
                <div className="flex flex-wrap gap-2">
                  {activeFilterLabels.map((label) => (
                    <span key={label} className="inline-flex items-center rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-[0_1px_2px_rgba(0,0,0,0.02)]">
                      {label}
                    </span>
                  ))}
                  {currentSortLabel && (
                    <span className="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 shadow-[0_1px_2px_rgba(0,0,0,0.02)]">
                      Ordenação: {currentSortLabel}
                    </span>
                  )}
                </div>
              ) : currentSortLabel ? (
                <div className="flex flex-wrap gap-2">
                  <span className="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 shadow-[0_1px_2px_rgba(0,0,0,0.02)]">
                    Ordenação: {currentSortLabel}
                  </span>
                </div>
              ) : null}
            </div>

            {/* Botão de Limpar (Canto superior direito em telas maiores) */}
            {hasActiveFilters ? (
              <Button
                onClick={onClearFilters}
                variant="ghost"
                size="sm"
                className="h-8 text-xs text-gray-500 hover:text-red-600 hover:bg-red-50 transition-colors shrink-0 self-start w-full sm:w-auto"
              >
                <X className="w-3.5 h-3.5 mr-1" />
                Limpar todos
              </Button>
            ) : null}
          </div>
          </div>
        </div>
      )}

      {/* Modais omitidos para concisão (mesma lógica) */}
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
        withPhonesFilter={withPhonesFilter}
        onWithPhonesFilterChange={onWithPhonesFilterChange}
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
        selectedBanks={selectedBanks}
        onSelectedBanksChange={onSelectedBanksChange}
        bankCombinationMode={bankCombinationMode}
        onBankCombinationModeChange={onBankCombinationModeChange}
        uy3Situacao={uy3Situacao}
        onUy3SituacaoChange={onUy3SituacaoChange}
        uy3ConsultaFrom={uy3ConsultaFrom}
        onUy3ConsultaFromChange={onUy3ConsultaFromChange}
        uy3ConsultaTo={uy3ConsultaTo}
        onUy3ConsultaToChange={onUy3ConsultaToChange}
        uy3MesesAdmissaoMin={uy3MesesAdmissaoMin}
        onUy3MesesAdmissaoMinChange={onUy3MesesAdmissaoMinChange}
        uy3MesesAdmissaoMax={uy3MesesAdmissaoMax}
        onUy3MesesAdmissaoMaxChange={onUy3MesesAdmissaoMaxChange}
        uy3MargemMin={uy3MargemMin}
        onUy3MargemMinChange={onUy3MargemMinChange}
        uy3MargemMax={uy3MargemMax}
        onUy3MargemMaxChange={onUy3MargemMaxChange}
        uy3ValorLiberadoMin={uy3ValorLiberadoMin}
        onUy3ValorLiberadoMinChange={onUy3ValorLiberadoMinChange}
        uy3ValorLiberadoMax={uy3ValorLiberadoMax}
        onUy3ValorLiberadoMaxChange={onUy3ValorLiberadoMaxChange}
        uy3NumeroParcelasMin={uy3NumeroParcelasMin}
        onUy3NumeroParcelasMinChange={onUy3NumeroParcelasMinChange}
        uy3NumeroParcelasMax={uy3NumeroParcelasMax}
        onUy3NumeroParcelasMaxChange={onUy3NumeroParcelasMaxChange}
      />

      <ColumnsModal
        isOpen={isColumnsModalOpen}
        onClose={() => setIsColumnsModalOpen(false)}
        mode={mode}
        visibleColumns={currentVisible}
        onSave={onSaveVisible}
        defaultVisibleColumns={currentDefaults}
        stickyIdentityColumns={mode === "360" ? stickyIdentityColumns360 : undefined}
        onStickyIdentityColumnsChange={mode === "360" ? onStickyIdentityColumns360Change : undefined}
      />
    </div>
  );
};
