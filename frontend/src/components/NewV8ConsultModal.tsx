import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

interface NewV8ConsultModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (
    titulo: string,
    lines: string,
    opts?: { reuseRecentConsults?: boolean; runAt?: string | null; timezone?: string | null }
  ) => void;
}

export const NewV8ConsultModal = ({ isOpen, onClose, onSubmit }: NewV8ConsultModalProps) => {
  const [titulo, setTitulo] = useState("");
  const [lines, setLines] = useState("");
  const [lineCount, setLineCount] = useState(0);
  const [reuseRecentConsults, setReuseRecentConsults] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [isAgendado, setIsAgendado] = useState(false);
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
    if (!titulo.trim()) {
      toast.error("Título da consulta é obrigatório");
      return;
    }
    if (!lines.trim()) {
      toast.error("Adicione pelo menos uma linha");
      return;
    }
    if (isAgendado && !runAtLocal) {
      toast.error("Informe a data e hora do agendamento");
      return;
    }

    try {
      setSubmitting(true);
      await onSubmit(
        titulo,
        lines,
        isAgendado
          ? {
              reuseRecentConsults,
              runAt: runAtLocal,
              timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            }
          : { reuseRecentConsults }
      );
      setTitulo("");
      setLines("");
      setLineCount(0);
      setReuseRecentConsults(false);
      setIsAgendado(false);
      setRunAtLocal("");
      onClose();
    } finally {
      setSubmitting(false);
    }
  };

  const handleClose = () => {
    if (submitting) return;
    setTitulo("");
    setLines("");
    setLineCount(0);
    setReuseRecentConsults(false);
    setIsAgendado(false);
    setRunAtLocal("");
    onClose();
  };

  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";
  const minNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-xl font-semibold">Nova consulta V8</DialogTitle>
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
              placeholder="Ex.: Lote V8 – Campanha Janeiro"
              className={cn("w-full", noFocus)}
              disabled={submitting}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="lines" className="text-sm font-medium">
              Linhas (CPF, Nome completo, Data de nascimento) *
            </Label>
            <Textarea
              id="lines"
              value={lines}
              onChange={(e) => setLines(e.target.value)}
              placeholder={`08860163986 TIAGO BOLDRINI 1993-05-22\n39201843860 RACHEL MARQUES RODRIGUES 20/05/1985`}
              className={cn("min-h-[220px] w-full font-mono text-sm", noFocus)}
              disabled={submitting}
            />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-600">
              <span>Uma pessoa por linha. Nome pode ter espaços.</span>
              <span className="font-medium text-blue-600">
                Detectadas: {lineCount} linhas
              </span>
            </div>
          </div>

          <div className="space-y-3 border-t border-gray-100 pt-4">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="v8-reuse-recent-consults"
                checked={reuseRecentConsults}
                onCheckedChange={(checked) => setReuseRecentConsults(!!checked)}
                disabled={submitting}
              />
              <Label htmlFor="v8-reuse-recent-consults" className="cursor-pointer text-sm font-medium">
                Reaproveitar consentimentos recentes
              </Label>
            </div>

            <div className="flex items-center space-x-2">
              <Checkbox
                id="v8-agendamento"
                checked={isAgendado}
                onCheckedChange={(checked) => setIsAgendado(!!checked)}
                disabled={submitting}
              />
              <Label htmlFor="v8-agendamento" className="cursor-pointer text-sm font-medium">
                Agendar início
              </Label>
            </div>

            {isAgendado && (
              <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50/70 p-3">
                <Label htmlFor="v8-run-at" className="text-sm font-medium">
                  Iniciar em
                </Label>
                <Input
                  id="v8-run-at"
                  type="datetime-local"
                  value={runAtLocal}
                  onChange={(e) => setRunAtLocal(e.target.value)}
                  min={minNow}
                  className={cn("max-w-xs", noFocus)}
                  disabled={submitting}
                />
                <p className="text-xs leading-5 text-gray-600">
                  O lote ficará agendado e entrará automaticamente na fila quando esse horário chegar.
                </p>
              </div>
            )}
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
            {submitting ? "Criando..." : isAgendado ? "Agendar consulta" : "Criar consulta"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
