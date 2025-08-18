import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";

interface NewCLTConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (titulo: string, cpfs: string) => void;
}

export const NewCLTConsultModal = ({ isOpen, onClose, onSubmit }: NewCLTConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [cpfs, setCpfs] = useState("");
  const [cpfCount, setCpfCount] = useState(0);

  // Contar CPFs detectados
  useEffect(() => {
    if (cpfs.trim()) {
      const cpfList = cpfs.split(/[\n,\s]+/).filter(cpf => cpf.trim());
      setCpfCount(cpfList.length);
    } else {
      setCpfCount(0);
    }
  }, [cpfs]);

  const handleSubmit = () => {
    if (!titulo.trim()) {
      toast.error("Título da consulta é obrigatório");
      return;
    }
    
    if (!cpfs.trim()) {
      toast.error("Adicione pelo menos um CPF");
      return;
    }

    onSubmit(titulo, cpfs);
    
    // Reset form
    setTitulo("");
    setCpfs("");
    setCpfCount(0);
    
    toast.success("Consulta criada com sucesso!");
    onClose();
  };

  const handleClose = () => {
    setTitulo("");
    setCpfs("");
    setCpfCount(0);
    onClose();
  };

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta CLT</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="titulo" className="text-sm font-medium">
              Título da consulta *
            </Label>
            <Input
              id="titulo"
              value={titulo}
              onChange={(e) => setTitulo(e.target.value)}
              placeholder="Ex.: Lote CLT – Campanha Agosto"
              className="w-full"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="cpfs" className="text-sm font-medium">
              CPFs (um por linha ou separados por vírgula/espaço)
            </Label>
            <Textarea
              id="cpfs"
              value={cpfs}
              onChange={(e) => setCpfs(e.target.value)}
              placeholder="111.222.333-44&#10;55566677788&#10;01234567890, 98765432100"
              className="min-h-[200px] w-full font-mono text-sm"
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>Aceitamos quebras de linha, vírgulas ou espaços; removeremos pontos e traços.</span>
              <span className="font-medium text-blue-600">
                Detectados: {cpfCount} CPFs
              </span>
            </div>
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse sm:flex-row gap-2">
          <Button variant="outline" onClick={handleClose}>
            Cancelar
          </Button>
          <Button onClick={handleSubmit} className="bg-blue-600 hover:bg-blue-700">
            Criar consulta
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};