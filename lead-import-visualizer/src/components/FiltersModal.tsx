
import { useState } from "react";
import { X, Calendar, Filter } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { MultiSelect } from "@/components/ui/multi-select";
import { cn } from "@/lib/utils";

interface FiltersModalProps {
  isOpen: boolean;
  onClose: () => void;
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
  onApplyFilters: () => void;
  onClearFilters: () => void;
  availableMotivos: string[];
  availableOrigens: string[];
}

export const FiltersModal = ({
  isOpen,
  onClose,
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
  onApplyFilters,
  onClearFilters,
  availableMotivos,
  availableOrigens,
}: FiltersModalProps) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div className="flex items-center space-x-2">
            <Filter className="w-5 h-5 text-gray-600" />
            <h2 className="text-xl font-semibold text-gray-900">Filtros Avançados</h2>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 transition-colors duration-200"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 overflow-y-auto max-h-[calc(90vh-140px)]">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Left Column */}
            <div className="space-y-6">
              {/* Search */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Pesquisa Geral</label>
                <Input
                  type="text"
                  placeholder="Pesquisar por nome, CPF ou telefone..."
                  value={searchValue}
                  onChange={(e) => onSearchChange(e.target.value)}
                />
              </div>

              {/* Status Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Status de Elegibilidade</label>
                <Select value={eligibleFilter} onValueChange={onEligibleFilterChange}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos</SelectItem>
                    <SelectItem value="elegiveis">Elegíveis</SelectItem>
                    <SelectItem value="nao-elegiveis">Inelegíveis</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Contract Date Range Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Período de Contratos</label>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-xs text-gray-500">Data Inicial</label>
                    <Input
                      type="date"
                      value={contractDateFromFilter}
                      onChange={(e) => onContractDateFromFilterChange(e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="text-xs text-gray-500">Data Final</label>
                    <Input
                      type="date"
                      value={contractDateToFilter}
                      onChange={(e) => onContractDateToFilterChange(e.target.value)}
                    />
                  </div>
                </div>
              </div>

              {/* Motivos Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Motivos de Consulta</label>
                <MultiSelect
                  options={availableMotivos}
                  selected={motivosFilter}
                  onChange={onMotivosFilterChange}
                  placeholder="Selecionar motivos..."
                />
              </div>

              {/* Origem Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Origem dos Dados</label>
                <MultiSelect
                  options={availableOrigens}
                  selected={origemFilter}
                  onChange={onOrigemFilterChange}
                  placeholder="Selecionar origens..."
                />
              </div>

              {/* Date Range Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Período de Atualização</label>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-xs text-gray-500">Data Inicial</label>
                    <Input
                      type="date"
                      value={dateFromFilter}
                      onChange={(e) => onDateFromFilterChange(e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="text-xs text-gray-500">Data Final</label>
                    <Input
                      type="date"
                      value={dateToFilter}
                      onChange={(e) => onDateToFilterChange(e.target.value)}
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* Right Column */}
            <div className="space-y-6">
              {/* CPF Mass Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">CPFs em Massa</label>
                <Textarea
                  placeholder="Cole aqui uma lista de CPFs separados por vírgula, ponto-e-vírgula ou quebra de linha..."
                  value={cpfMassFilter}
                  onChange={(e) => onCpfMassFilterChange(e.target.value)}
                  rows={4}
                />
              </div>

              {/* Names Mass Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Nomes em Massa</label>
                <Textarea
                  placeholder="Cole aqui uma lista de nomes separados por vírgula, ponto-e-vírgula ou quebra de linha..."
                  value={namesMassFilter}
                  onChange={(e) => onNamesMassFilterChange(e.target.value)}
                  rows={4}
                />
              </div>

              {/* Phones Mass Filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium text-gray-700">Telefones em Massa</label>
                <Textarea
                  placeholder="Cole aqui uma lista de telefones separados por vírgula, ponto-e-vírgula ou quebra de linha..."
                  value={phonesMassFilter}
                  onChange={(e) => onPhonesMassFilterChange(e.target.value)}
                  rows={4}
                />
              </div>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
          <Button
            variant="outline"
            onClick={onClearFilters}
            className="text-gray-700 border-gray-300 hover:bg-gray-50"
          >
            Limpar Filtros
          </Button>
          <Button
            variant="outline"
            onClick={onClose}
            className="text-gray-700 border-gray-300 hover:bg-gray-50"
          >
            Cancelar
          </Button>
          <Button
            onClick={() => {
              onApplyFilters();
              onClose();
            }}
            className="bg-blue-600 hover:bg-blue-700"
          >
            Aplicar Filtros
          </Button>
        </div>
      </div>
    </div>
  );
};
