import { Plus, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FactaCltJobStatusFilter, FactaCltJobVariantFilter } from "@/api/facta";

interface FactaControlsProps {
  onNewConsultClick: () => void;
  searchValue: string;
  onSearchChange: (value: string) => void;
  statusFilter: FactaCltJobStatusFilter;
  onStatusFilterChange: (value: FactaCltJobStatusFilter) => void;
  variantFilter: FactaCltJobVariantFilter;
  onVariantFilterChange: (value: FactaCltJobVariantFilter) => void;
}

export const FactaControls = ({
  onNewConsultClick,
  searchValue,
  onSearchChange,
  statusFilter,
  onStatusFilterChange,
  variantFilter,
  onVariantFilterChange,
}: FactaControlsProps) => {
  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
          {/* Search + Status */}
          <div className="flex flex-col sm:flex-row items-stretch gap-3 flex-1 min-w-0">
            <div className="relative flex-1 min-w-0 sm:max-w-xs">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
              <Input
                type="text"
                placeholder="Buscar por título..."
                value={searchValue}
                onChange={(e) => onSearchChange(e.target.value)}
                className="pl-10 w-full focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:ring-offset-0"
              />
            </div>

            <Select
              value={statusFilter}
              onValueChange={(value) => onStatusFilterChange(value as FactaCltJobStatusFilter)}
            >
              <SelectTrigger className="w-full sm:w-[210px] focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:ring-offset-0 data-[state=open]:ring-0 data-[state=open]:ring-offset-0">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="todos">Todos os status</SelectItem>
                <SelectItem value="agendado">Agendado</SelectItem>
                <SelectItem value="pendente">Pendente</SelectItem>
                <SelectItem value="em_progresso">Em andamento</SelectItem>
                <SelectItem value="pausado">Pausado</SelectItem>
                <SelectItem value="concluido">Concluído</SelectItem>
                <SelectItem value="falhou">Falhou</SelectItem>
                <SelectItem value="cancelado">Cancelado</SelectItem>
              </SelectContent>
            </Select>

            <Select
              value={variantFilter}
              onValueChange={(value) => onVariantFilterChange(value as FactaCltJobVariantFilter)}
            >
              <SelectTrigger className="w-full sm:w-[180px] focus:ring-0 focus:ring-offset-0 focus-visible:ring-0 focus-visible:ring-offset-0 data-[state=open]:ring-0 data-[state=open]:ring-offset-0">
                <SelectValue placeholder="Tipo" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="todos">Todos os tipos</SelectItem>
                <SelectItem value="online">Online (ON)</SelectItem>
                <SelectItem value="offline">Offline (OFF)</SelectItem>
                <SelectItem value="hybrid">Híbrido</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Action Button */}
          <div className="flex items-center gap-2">
            <Button
              onClick={onNewConsultClick}
              size="sm"
              className="flex items-center gap-2 px-3 bg-blue-600 hover:bg-blue-700"
            >
              <Plus className="w-4 h-4" />
              Nova consulta
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
