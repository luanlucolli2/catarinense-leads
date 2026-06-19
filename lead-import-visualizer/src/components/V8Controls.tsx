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

type JobStatusFilterValue =
  | "todos"
  | "agendado"
  | "pendente"
  | "em_progresso"
  | "pausado"
  | "concluido"
  | "falhou"
  | "cancelado";

interface V8ControlsProps {
  onNewConsultClick: () => void;
  searchValue: string;
  onSearchChange: (value: string) => void;
  statusFilter?: JobStatusFilterValue;
  onStatusFilterChange?: (value: JobStatusFilterValue) => void;
}

export const V8Controls = ({
  onNewConsultClick,
  searchValue,
  onSearchChange,
  statusFilter,
  onStatusFilterChange,
}: V8ControlsProps) => {
  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
          <div className="flex flex-col sm:flex-row items-stretch gap-3 flex-1 min-w-0">
            <div className="relative flex-1 min-w-0 sm:max-w-xs">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
              <Input
                type="text"
                placeholder="Buscar por título..."
                value={searchValue}
                onChange={(e) => onSearchChange(e.target.value)}
                className="pl-10 w-full"
              />
            </div>

            {statusFilter && onStatusFilterChange ? (
              <Select
                value={statusFilter}
                onValueChange={(value) => onStatusFilterChange(value as JobStatusFilterValue)}
              >
                <SelectTrigger className="w-full sm:w-[210px]">
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
            ) : null}
          </div>

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
