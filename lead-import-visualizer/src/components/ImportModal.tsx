import { useState, useEffect } from "react"
import { X, Upload, Download, Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { cn } from "@/lib/utils"

import { startImport } from "@/api/importJobs"
import { useImportProgressCtx } from "@/contexts/ImportProgressContext"
import { toast } from "sonner"              /* ✅ usar sonner direto */

interface ImportModalProps {
  isOpen: boolean
  onClose: () => void
  onImportSuccess: () => void
}

export const ImportModal = ({
  isOpen,
  onClose,
  onImportSuccess,
}: ImportModalProps) => {
  const [importType, setImportType] =
    useState<"cadastral" | "higienizacao">("cadastral")
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [origin, setOrigin] = useState("")
  const [isSubmitting, setIsSubmitting] = useState(false)

  const { addJob } = useImportProgressCtx()

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) setSelectedFile(file)
  }

  const handleClose = () => {
    if (isSubmitting) return
    onClose()
    setSelectedFile(null)
    setOrigin("")
    setImportType("cadastral")
  }

  const handleImport = async () => {
    // ... (código anterior igual)
    setIsSubmitting(true)

    const formData = new FormData()
    formData.append("file", selectedFile!)
    formData.append("type", importType)
    if (origin) formData.append("origin", origin)

    try {
      /* 1 ▸ POST /import (agora retorna apenas { job_id }) */
      const response = await startImport(formData) // `response` agora é { job_id: ... }

      /* 2 ▸ ativa acompanhamento global com o ID recebido */
      addJob(response.job_id) // Use o ID da resposta direta


      onImportSuccess()
      handleClose()
    } catch (error: any) {
      // ESTE BLOCO AGORA FUNCIONARÁ COMO ESPERADO PARA TODOS OS ERROS
      console.error("Erro na importação:", error)

      const res = error.response
      if (res) {
        const { status, data } = res

        // Conflito: já há um import em andamento
        // Conflito: já há um import em andamento
        if (status === 409) {
          toast.error("Importação Concorrente", {
            description: data.message || "Já existe uma importação em andamento. Aguarde a conclusão.",
          })
        }
        // Cabeçalhos faltando na planilha
        else if (status === 422 && Array.isArray(data.missing)) {
          toast.error("Cabeçalhos inválidos na planilha", {
            description: `Colunas faltando: ${data.missing.join(", ")}`,
          })
        }
        // Erros de validação do request (ex: arquivo > max, tipo errado, etc.)
        else if (status === 422 && data.errors) {
          const validationErrors = Object.values(data.errors).flat() as string[]
          toast.error("Erro de validação", {
            description:
              validationErrors[0] || "Verifique os dados do formulário.",
          })
        }
        // Qualquer outro retorno do servidor (500, 403, etc.)
        else {
          toast.error("Falha no envio", {
            description: data.message || "Tente novamente mais tarde.",
          })
        }
      } else {
        // Sem resposta (problema de rede)
        toast.error("Erro de rede", {
          description: "Verifique sua conexão e tente novamente.",
        })
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const handleDownloadTemplate = (
    type: "cadastral" | "higienizacao",
  ): void => {
    const url = `/templates/${type === "cadastral"
      ? "template_import_cadastral.xlsx"
      : "template_import_higienizacao.xlsx"
      }`
    window.open(url, "_blank")
  }

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 animate-fade-in">
      <div className="mx-4 w-full max-w-md animate-scale-in rounded-lg bg-white shadow-xl">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-gray-200 p-6">
          <h2 className="text-xl font-semibold text-gray-900">
            Importar Planilha
          </h2>
          <button
            onClick={handleClose}
            className="text-gray-400 transition-colors duration-200 hover:text-gray-600"
            disabled={isSubmitting}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Body */}
        <div className="space-y-6 p-6">
          {/* Tipo de importação */}
          <div>
            <label className="mb-3 block text-sm font-medium text-gray-700">
              Tipo de Importação
            </label>
            <div className="grid grid-cols-2 gap-2">
              <Button
                onClick={() => setImportType("cadastral")}
                className={cn(
                  "transition-colors duration-200",
                  importType === "cadastral"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border border-gray-300 bg-white text-gray-700 hover:bg-gray-100",
                )}
              >
                Dados Cadastrais
              </Button>
              <Button
                onClick={() => setImportType("higienizacao")}
                className={cn(
                  "transition-colors duration-200",
                  importType === "higienizacao"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border border-gray-300 bg-white text-gray-700 hover:bg-gray-100",
                )}
              >
                Dados de Higienização
              </Button>
            </div>
          </div>

          {/* Origem */}
          <div>
            <label
              htmlFor="origin"
              className="mb-2 block text-sm font-medium text-gray-700"
            >
              Origem da Planilha (Opcional)
            </label>
            <Input
              id="origin"
              type="text"
              placeholder="Ex: Base fria 06/2025"
              value={origin}
              onChange={(e) => setOrigin(e.target.value)}
            />
          </div>

          {/* Template */}
          <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div className="flex items-center justify-between">
              <div>
                <h4 className="mb-1 text-sm font-medium text-blue-800">
                  Planilha Modelo
                </h4>
                <p className="text-xs text-blue-600">
                  Baixe o template do tipo selecionado.
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={() => handleDownloadTemplate(importType)}
                className="border-blue-300 text-blue-700 hover:bg-blue-100"
              >
                <Download className="mr-1 h-4 w-4" /> Baixar
              </Button>
            </div>
          </div>

          {/* File input */}
          <div>
            <label className="mb-2 block text-sm font-medium text-gray-700">
              Selecione o arquivo (.xlsx)
            </label>
            <div className="rounded-lg border-2 border-dashed border-gray-300 p-4 text-center transition-colors duration-200 hover:border-blue-400">
              <input
                id="file-upload"
                type="file"
                accept=".xlsx,.xls"
                className="hidden"
                onChange={handleFileChange}
              />
              <label htmlFor="file-upload" className="cursor-pointer">
                <Upload className="mx-auto mb-2 h-8 w-8 text-gray-400" />
                <span className="text-sm font-medium text-gray-600">
                  {selectedFile ? selectedFile.name : "Clique para selecionar"}
                </span>
                <p className="text-xs text-gray-500">
                  ou arraste e solte o arquivo aqui
                </p>
              </label>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end space-x-3 border-t border-gray-200 p-6">
          <Button variant="outline" onClick={handleClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button
            onClick={handleImport}
            disabled={!selectedFile || isSubmitting}
            className="bg-green-700 text-white transition-colors duration-200 hover:bg-green-600"
          >
            {isSubmitting ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Upload className="mr-2 h-4 w-4" />
            )}
            {isSubmitting ? "Enviando..." : "Iniciar Importação"}
          </Button>
        </div>
      </div>
    </div>
  )
}
