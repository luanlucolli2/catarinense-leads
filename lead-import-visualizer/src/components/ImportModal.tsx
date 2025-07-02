
import { useState } from "react";
import { X, Upload, AlertCircle, Download } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface ImportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onImport: (type: string, file: File, origin?: string) => void;
}

const suggestedOrigins = [
  "Site Corporativo",
  "Landing Page",
  "Facebook Ads",
  "Google Ads",
  "Instagram",
  "WhatsApp Business",
  "Parceiros",
  "Indicação",
  "Telemarketing",
  "Email Marketing",
  "Eventos",
  "Feiras"
];

export const ImportModal = ({ isOpen, onClose, onImport }: ImportModalProps) => {
  const [importType, setImportType] = useState("cadastrais");
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [origin, setOrigin] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [showOriginSuggestions, setShowOriginSuggestions] = useState(false);

  const filteredOrigins = suggestedOrigins.filter(suggestion =>
    suggestion.toLowerCase().includes(origin.toLowerCase())
  );

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (file) {
      setSelectedFile(file);
      // Simular validação de template
      if (file.name.includes("invalid")) {
        setErrors([
          "Coluna 'Email' não encontrada",
          "Coluna 'Data_Nascimento' fora da ordem esperada",
          "Formato de arquivo deve ser .xlsx"
        ]);
      } else {
        setErrors([]);
      }
    }
  };

  const handleOriginSelect = (selectedOrigin: string) => {
    setOrigin(selectedOrigin);
    setShowOriginSuggestions(false);
  };

  const handleImport = () => {
    if (selectedFile) {
      onImport(importType, selectedFile, importType === "cadastrais" ? origin : undefined);
      onClose();
      setSelectedFile(null);
      setOrigin("");
      setErrors([]);
    }
  };

  const handleClose = () => {
    onClose();
    setSelectedFile(null);
    setOrigin("");
    setErrors([]);
    setShowOriginSuggestions(false);
  };

  const handleDownloadTemplate = () => {
    console.log(`Downloading template for ${importType}`);
    const templateName = importType === 'cadastrais' ? 'template_dados_cadastrais.xlsx' : 'template_higienizacao.xlsx';
    
    const link = document.createElement('a');
    link.href = '#';
    link.download = templateName;
    link.click();
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 animate-scale-in">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">Importar Planilha</h2>
          <button
            onClick={handleClose}
            className="text-gray-400 hover:text-gray-600 transition-colors duration-200"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-6">
          {/* Import Type Selection */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-3">
              Tipo de Importação
            </label>
            <div className="space-y-2">
              <label className="flex items-center">
                <input
                  type="radio"
                  name="importType"
                  value="cadastrais"
                  checked={importType === "cadastrais"}
                  onChange={(e) => setImportType(e.target.value)}
                  className="mr-3 text-blue-600"
                />
                <span className="text-sm text-gray-700">Dados Cadastrais</span>
              </label>
              <label className="flex items-center">
                <input
                  type="radio"
                  name="importType"
                  value="higienizacao"
                  checked={importType === "higienizacao"}
                  onChange={(e) => setImportType(e.target.value)}
                  className="mr-3 text-blue-600"
                />
                <span className="text-sm text-gray-700">Dados de Higienização</span>
              </label>
            </div>
          </div>

          {/* Origin Field for Cadastrais */}
          {importType === "cadastrais" && (
            <div className="relative">
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Origem da Planilha
              </label>
              <Input
                type="text"
                placeholder="Digite ou selecione a origem..."
                value={origin}
                onChange={(e) => {
                  setOrigin(e.target.value);
                  setShowOriginSuggestions(true);
                }}
                onFocus={() => setShowOriginSuggestions(true)}
                onBlur={() => setTimeout(() => setShowOriginSuggestions(false), 200)}
              />
              {showOriginSuggestions && filteredOrigins.length > 0 && (
                <div className="absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                  {filteredOrigins.map((suggestion, index) => (
                    <button
                      key={index}
                      className="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 focus:bg-gray-100 focus:outline-none"
                      onClick={() => handleOriginSelect(suggestion)}
                    >
                      {suggestion}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Template Download */}
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div className="flex items-center justify-between">
              <div>
                <h4 className="text-sm font-medium text-blue-800 mb-1">
                  Planilha Modelo
                </h4>
                <p className="text-xs text-blue-600">
                  Baixe o template correto para garantir a importação
                </p>
              </div>
              <Button
                onClick={handleDownloadTemplate}
                variant="outline"
                size="sm"
                className="border-blue-300 text-blue-700 hover:bg-blue-100"
              >
                <Download className="w-4 h-4 mr-1" />
                Baixar
              </Button>
            </div>
          </div>

          {/* File Selection */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Selecione o arquivo (template)
            </label>
            <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 hover:border-gray-400 transition-colors duration-200">
              <input
                type="file"
                accept=".xlsx"
                onChange={handleFileChange}
                className="hidden"
                id="file-upload"
              />
              <label
                htmlFor="file-upload"
                className="cursor-pointer flex flex-col items-center"
              >
                <Upload className="w-8 h-8 text-gray-400 mb-2" />
                <span className="text-sm text-gray-600">
                  {selectedFile ? selectedFile.name : "Clique para selecionar arquivo .xlsx"}
                </span>
              </label>
            </div>
          </div>

          {/* Error Messages */}
          {errors.length > 0 && (
            <div className="bg-red-50 border border-red-200 rounded-lg p-4">
              <div className="flex items-start">
                <AlertCircle className="w-5 h-5 text-red-400 mr-2 mt-0.5 flex-shrink-0" />
                <div>
                  <h4 className="text-sm font-medium text-red-800 mb-2">
                    Problemas encontrados no template:
                  </h4>
                  <ul className="text-sm text-red-700 space-y-1">
                    {errors.map((error, index) => (
                      <li key={index}>• {error}</li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
          <Button
            variant="outline"
            onClick={handleClose}
            className="text-gray-700 border-gray-300 hover:bg-gray-50"
          >
            Cancelar
          </Button>
          <Button
            onClick={handleImport}
            disabled={!selectedFile || errors.length > 0 || (importType === "cadastrais" && !origin.trim())}
            className="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Iniciar Importação
          </Button>
        </div>
      </div>
    </div>
  );
};
