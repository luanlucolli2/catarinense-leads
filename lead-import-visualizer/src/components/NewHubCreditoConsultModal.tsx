import { useEffect, useState } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewHubCreditoConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (title: string, lines: string, opts?: { runAt?: string | null; timezone?: string | null }) => void | Promise<void>;
}

export const NewHubCreditoConsultModal = ({
  isOpen,
  onClose,
  onSubmit,
}: NewHubCreditoConsultModalProps) => {
  const [title, setTitle] = useState("");
  const [lines, setLines] = useState("");
  const [lineCount, setLineCount] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [isScheduled, setIsScheduled] = useState(false);
  const [runAtLocal, setRunAtLocal] = useState("");

  useEffect(() => {
    if (lines.trim()) {
      const list = lines.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
      setLineCount(list.length);
    } else {
      setLineCount(0);
    }
  }, [lines]);

  const handleSubmit = async () => {
    if (!title.trim()) {
      toast.error("Título da consulta é obrigatório");
      return;
    }
    if (!lines.trim()) {
      toast.error("Adicione pelo menos uma linha");
      return;
    }
    if (isScheduled && !runAtLocal) { toast.error("Informe a data e hora do agendamento"); return; }

    try {
      setSubmitting(true);
      await onSubmit(title, lines, isScheduled ? { runAt: runAtLocal, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone } : undefined);
      setTitle("");
      setLines("");
      setLineCount(0);
      setIsScheduled(false);
      setRunAtLocal("");
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  const handleClose = () => {
    if (submitting) return;
    setTitle("");
    setLines("");
    setLineCount(0);
    setIsScheduled(false);
    setRunAtLocal("");
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";
  const minNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta HubCredito</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="space-y-2">
            <Label htmlFor="titulo-hubcredito" className="text-sm font-medium">
              Título da consulta *
            </Label>
            <Input
              id="titulo-hubcredito"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Ex.: Lote HubCredito - Julho"
              className={cn("w-full", noFocus)}
              disabled={submitting}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="lines-hubcredito" className="text-sm font-medium">
              Linhas (CPF;Nome;Data de nascimento) *
            </Label>
            <Textarea
              id="lines-hubcredito"
              value={lines}
              onChange={(e) => setLines(e.target.value)}
              placeholder={`01711922145;LEIDIANE SILVA ALVES;06/04/1986\n01699639507;LEONARDO DA SILVA SAMPAIO;07/04/1983`}
              className={cn("min-h-[220px] w-full font-mono text-sm", noFocus)}
              disabled={submitting}
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>Uma pessoa por linha: `cpf;nome;data_nascimento`, com data em `dd/mm/aaaa` ou `aaaa-mm-dd`.</span>
              <span className="font-medium text-blue-600">Detectadas: {lineCount} linhas</span>
            </div>
          </div>

          <div className="space-y-3 border-t border-gray-100 pt-4">
            <div className="flex items-center space-x-2">
              <Checkbox id="hubcredito-agendamento" checked={isScheduled} onCheckedChange={(checked) => setIsScheduled(!!checked)} disabled={submitting} />
              <Label htmlFor="hubcredito-agendamento" className="cursor-pointer text-sm font-medium">Agendar início</Label>
            </div>
            {isScheduled && <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50/70 p-3">
              <Label htmlFor="hubcredito-run-at" className="text-sm font-medium">Iniciar em</Label>
              <Input id="hubcredito-run-at" type="datetime-local" value={runAtLocal} onChange={(e) => setRunAtLocal(e.target.value)} min={minNow} className={cn("max-w-xs", noFocus)} disabled={submitting} />
            </div>}
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse sm:flex-row gap-2">
          <Button variant="outline" onClick={handleClose} disabled={submitting} className={noFocus}>
            Cancelar
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={submitting}
            className={cn("bg-blue-600 hover:bg-blue-700", noFocus)}
          >
            {submitting ? "Criando..." : isScheduled ? "Agendar consulta" : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
