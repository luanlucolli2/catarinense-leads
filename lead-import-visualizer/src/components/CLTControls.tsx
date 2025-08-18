import { Plus, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface CLTControlsProps {
  onNewConsultClick: () => void;
  searchValue: string;
  onSearchChange: (value: string) => void;
}

export const CLTControls = ({ onNewConsultClick, searchValue, onSearchChange }: CLTControlsProps) => {
  return (
    <div className="bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="px-4 py-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 justify-between">
          {/* Search Field */}
          <div className="relative flex-1 min-w-0 max-w-xs">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
            <Input
              type="text"
              placeholder="Buscar por título..."
              value={searchValue}
              onChange={(e) => onSearchChange(e.target.value)}
              className="pl-10 w-full"
            />
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