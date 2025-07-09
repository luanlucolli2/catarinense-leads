// Em src/components/ImportModal.tsx

import { useState } from "react";
import { X, Upload, Download, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import axiosClient from "@/api/axiosClient";

interface ImportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onImportSuccess: () => void; // Nova prop para notificar o pai sobre o sucesso
}

const suggestedOrigins = [
  "Site Corporativo", "Landing Page", "Facebook Ads", "Google Ads", "Indicação",
];

export const ImportModal = ({ isOpen, onClose, onImportSuccess }: ImportModalProps) => {
  const [importType, setImportType] = useState("cadastral");
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [origin, setOrigin] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (file) {
      setSelectedFile(file);
    }
  };

  const handleClose = () => {
    if (isSubmitting) return; // Impede de fechar durante o envio
    onClose();
    // Resetar o estado ao fechar
    setSelectedFile(null);
    setOrigin("");
    setImportType("cadastral");
  };

  const handleImport = async () => {
    if (!selectedFile) {
      toast.error("Por favor, selecione um arquivo para importar.");
      return;
    }

    setIsSubmitting(true);

    // 1. FormData é a forma correta de enviar arquivos via HTTP
    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('type', importType);
    if (importType === 'cadastral' && origin) {
      formData.append('origin', origin);
    }

    try {
      // 2. Chamada de API real com o axiosClient
      const response = await axiosClient.post('/import', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });

      toast.success("Arquivo enviado com sucesso!", {
        description: response.data.message,
      });
      
      onImportSuccess(); // 3. Notifica o Dashboard para atualizar a lista de leads
      handleClose(); // Fecha o modal

    } catch (error: any) {
      console.error("Erro na importação:", error);
      if (error.response?.data?.errors) {
        // Trata erros de validação do Laravel
        const validationErrors = Object.values(error.response.data.errors).flat();
        toast.error("Erro de validação", {
          description: (validationErrors[0] as string) || "Verifique os dados enviados.",
        });
      } else {
        toast.error("Falha no envio", {
          description: "Não foi possível enviar o arquivo. Tente novamente.",
        });
      }
    } finally {
      setIsSubmitting(false);
    }
  };
  
  const handleDownloadTemplate = (type: 'cadastral' | 'higienizacao') => {
    // Aponta para a pasta 'public' do seu projeto Laravel
    const url = type === 'cadastral'
      ? '/templates/template_import_cadastral.xlsx'
      : '/templates/template_import_higienizacao.xlsx';
    
    // Cria um link e simula o clique para iniciar o download
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', url.split('/').pop() || 'template.xlsx');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };


  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 animate-fade-in">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 animate-scale-in">
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-xl font-semibold text-gray-900">Importar Planilha</h2>
          <button onClick={handleClose} className="text-gray-400 hover:text-gray-600 transition-colors duration-200" disabled={isSubmitting}>
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="p-6 space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-3">Tipo de Importação</label>
            <div className="grid grid-cols-2 gap-2">
                <Button variant={importType === 'cadastral' ? 'default' : 'outline'} onClick={() => setImportType('cadastral')}>Dados Cadastrais</Button>
                <Button variant={importType === 'higienizacao' ? 'default' : 'outline'} onClick={() => setImportType('higienizacao')}>Dados de Higienização</Button>
            </div>
          </div>
          
          {importType === "cadastral" && (
            <div className="relative">
              <label htmlFor="origin" className="block text-sm font-medium text-gray-700 mb-2">Origem da Planilha (Opcional)</label>
              <Input id="origin" type="text" placeholder="Ex: Campanha Facebook Junho" value={origin} onChange={(e) => setOrigin(e.target.value)} />
            </div>
          )}

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
             <div className="flex items-center justify-between">
              <div>
                <h4 className="text-sm font-medium text-blue-800 mb-1">Planilha Modelo</h4>
                <p className="text-xs text-blue-600">Baixe o template do tipo selecionado.</p>
              </div>
              <Button onClick={() => handleDownloadTemplate(importType as any)} variant="outline" size="sm" className="border-blue-300 text-blue-700 hover:bg-blue-100">
                <Download className="w-4 h-4 mr-1" /> Baixar
              </Button>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Selecione o arquivo (.xlsx)</label>
            <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 hover:border-blue-400 transition-colors duration-200 text-center">
              <input type="file" accept=".xlsx,.xls" onChange={handleFileChange} className="hidden" id="file-upload" />
              <label htmlFor="file-upload" className="cursor-pointer">
                <Upload className="w-8 h-8 text-gray-400 mb-2 mx-auto" />
                <span className="text-sm text-gray-600 font-medium">
                  {selectedFile ? selectedFile.name : "Clique para selecionar"}
                </span>
                <p className="text-xs text-gray-500">ou arraste e solte o arquivo aqui</p>
              </label>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
          <Button variant="outline" onClick={handleClose} disabled={isSubmitting}>Cancelar</Button>
          <Button onClick={handleImport} disabled={!selectedFile || isSubmitting}>
            {isSubmitting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}
            {isSubmitting ? "Enviando..." : "Iniciar Importação"}
          </Button>
        </div>
      </div>
    </div>
  );
};