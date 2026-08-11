import { useEffect, useMemo, useRef, useState } from "react";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { PresencaHistoryTable } from "@/components/PresencaHistoryTable";
import { V8Controls } from "@/components/V8Controls";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";
import {
  cancelSomaCltConsultJob,
  createSomaCltConsultJob,
  deleteSomaCltConsultJob,
  downloadSomaCltPreview,
  downloadSomaCltReport,
  getSomaCltConsultJob,
  listSomaCltConsultJobs,
  pauseSomaCltConsultJob,
  resumeSomaCltConsultJob,
  SomaCltConsultJobListItem,
  SomaCltConsultJobShow,
  SomaCltJobStatusFilter,
} from "@/api/somaClt";
import { usePersistedState } from "@/hooks/usePersistedState";

type Props = { active: boolean; formatDateTimeBR: (iso?: string | null) => string };

const SOMA_MODE_OPTIONS = [
  { value: "uy3" as const, label: "UY3", helper: "Consulta UY3", description: "Consulta e simulação pela integração UY3." },
  { value: "celcoin" as const, label: "CELCOIN", helper: "Consulta Celcoin", description: "Consulta e simulação pela integração Celcoin." },
  { value: "both" as const, label: "UY3 + CELCOIN", helper: "Consulta dupla", description: "Executa a consulta nas duas bancarizadoras, com aceite independente." },
];

export function SomaCltTab({ active, formatDateTimeBR }: Props) {
  const qc = useQueryClient();
  const [newOpen, setNewOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [status, setStatus] = usePersistedState<SomaCltJobStatusFilter>("soma-clt:statusFilter", "todos");
  const [page, setPage] = useState(1);
  const [watchingId, setWatchingId] = usePersistedState<number | null>("soma-clt:watchJobId", null);
  const inFlight = useRef<Set<number>>(new Set());
  const lastSnapshot = useRef<{ id: number; status: string; finishedAt?: string | null } | null>(null);

  const { data: jobsPage, isLoading, refetch } = useQuery({
    queryKey: ["soma-clt:list", page, status],
    queryFn: () => listSomaCltConsultJobs(page, status),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: active ? 30000 : false,
  });
  const jobs = jobsPage?.data ?? [];

  const { data: watchedJob } = useQuery<SomaCltConsultJobShow>({
    queryKey: ["soma-clt:job", watchingId],
    queryFn: () => getSomaCltConsultJob(watchingId as number),
    enabled: !!watchingId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as SomaCltConsultJobShow | undefined;
      if (!job) return false;
      if (job.status === "agendado") return 15000;
      return job.status === "pendente" || job.status === "em_progresso" ? 5000 : false;
    },
  });

  const items = useMemo<SomaCltConsultJobListItem[]>(() => {
    if (!watchedJob) return jobs;
    return jobs.map((job) => job.id !== watchedJob.id ? job : {
      ...job,
      ...watchedJob,
      created_at: job.created_at,
    });
  }, [jobs, watchedJob]);
  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return needle ? items.filter((job) => job.title.toLowerCase().includes(needle)) : items;
  }, [items, search]);

  useEffect(() => {
    if (!watchedJob) return;
    const previous = lastSnapshot.current;
    const changed = !previous || previous.id !== watchedJob.id || previous.status !== watchedJob.status || previous.finishedAt !== watchedJob.finished_at;
    if (!changed) return;
    lastSnapshot.current = { id: watchedJob.id, status: watchedJob.status, finishedAt: watchedJob.finished_at };

    if (["concluido", "falhou", "cancelado"].includes(watchedJob.status)) {
      const message = watchedJob.status === "concluido" ? "concluída" : watchedJob.status === "falhou" ? "falhou" : "cancelada";
      toast.info(`Consulta \"${watchedJob.title}\" ${message}.`);
      setWatchingId(null);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
    }
  }, [watchedJob, qc, setWatchingId]);

  const create = useMutation({
    mutationFn: createSomaCltConsultJob,
    onSuccess: (job, input) => {
      setWatchingId(job.id);
      setPage(1);
      toast.success(job.status === "agendado" ? `Consulta \"${input.title}\" agendada.` : `Consulta \"${input.title}\" criada.`);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
    },
    onError: (error: any) => toast.error(error?.response?.data?.message || "Falha ao criar consulta"),
  });
  const pause = useMutation({
    mutationFn: pauseSomaCltConsultJob,
    onSuccess: (_data, id) => {
      setWatchingId(id);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
      void qc.invalidateQueries({ queryKey: ["soma-clt:job", id] });
    },
    onError: (error: any) => toast.error(error?.response?.data?.message || "Não foi possível pausar"),
  });
  const resume = useMutation({
    mutationFn: resumeSomaCltConsultJob,
    onSuccess: (_data, id) => {
      setWatchingId(id);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
      void qc.invalidateQueries({ queryKey: ["soma-clt:job", id] });
    },
    onError: (error: any) => toast.error(error?.response?.data?.message || "Não foi possível retomar"),
  });
  const cancel = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => cancelSomaCltConsultJob(id, reason),
    onSuccess: (_data, { id }) => {
      setWatchingId(id);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
      void qc.invalidateQueries({ queryKey: ["soma-clt:job", id] });
    },
    onError: (error: any) => toast.error(error?.response?.data?.message || "Não foi possível cancelar"),
  });
  const remove = useMutation({
    mutationFn: deleteSomaCltConsultJob,
    onSuccess: (_data, id) => {
      if (id === watchingId) setWatchingId(null);
      void qc.invalidateQueries({ queryKey: ["soma-clt:list"] });
      void qc.removeQueries({ queryKey: ["soma-clt:job", id] });
    },
    onError: (error: any) => toast.error(error?.response?.data?.message || "Não foi possível excluir"),
  });

  const download = async (id: number, options?: { preview?: boolean }) => {
    if (inFlight.current.has(id)) return;
    inFlight.current.add(id);
    try {
      const job = await qc.ensureQueryData({ queryKey: ["soma-clt:job", id], queryFn: () => getSomaCltConsultJob(id) });
      if (!options?.preview && job.has_file) await downloadSomaCltReport(id);
      else await downloadSomaCltPreview(id);
    } catch (error: any) {
      toast.error(error?.response?.data?.message || "Falha no download");
    } finally {
      inFlight.current.delete(id);
    }
  };

  return <>
    <V8Controls
      onNewConsultClick={() => setNewOpen(true)}
      searchValue={search}
      onSearchChange={setSearch}
      statusFilter={status}
      onStatusFilterChange={(value) => { setStatus(value as SomaCltJobStatusFilter); setPage(1); }}
    />
    <PresencaHistoryTable
      items={filtered}
      loading={isLoading && !jobsPage}
      onDownload={download}
      onCancel={async (id) => { await cancel.mutateAsync({ id }); }}
      onPause={async (id) => { await pause.mutateAsync(id); }}
      onResume={async (id) => { await resume.mutateAsync(id); }}
      onDelete={async (id) => { await remove.mutateAsync(id); }}
      onRefresh={() => void refetch()}
      page={page}
      lastPage={jobsPage?.last_page ?? 1}
      onPageChange={setPage}
      formatDateTimeBR={formatDateTimeBR}
      metricLabels={{ success: "Sucessos", declined: "Recusas", failure: "Falhas" }}
    />
    <NewSomaCltConsultModal
      isOpen={newOpen}
      onClose={() => setNewOpen(false)}
      onSubmit={async (input) => { await create.mutateAsync(input); }}
    />
  </>;
}

function NewSomaCltConsultModal({ isOpen, onClose, onSubmit }: {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (input: { title: string; mode: 'uy3' | 'celcoin' | 'both'; lines: string; run_at?: string; timezone?: string }) => Promise<void>;
}) {
  const [title, setTitle] = useState("");
  const [mode, setMode] = useState<"" | "uy3" | "celcoin" | "both">("");
  const [lines, setLines] = useState("");
  const [scheduled, setScheduled] = useState(false);
  const [runAt, setRunAt] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const lineCount = useMemo(() => lines.split(/\r?\n/).filter((line) => line.trim()).length, [lines]);
  const reset = () => { setTitle(""); setMode(""); setLines(""); setScheduled(false); setRunAt(""); };
  const submit = async () => {
    if (!title.trim() || !mode || !lines.trim() || (scheduled && !runAt)) {
      toast.error("Preencha todos os campos obrigatórios.");
      return;
    }
    if (lineCount > 40000) { toast.error("O limite é de 40.000 linhas."); return; }
    try {
      setSubmitting(true);
      await onSubmit({ title, mode, lines, ...(scheduled ? { run_at: runAt, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone } : {}) });
      reset(); onClose();
    } finally { setSubmitting(false); }
  };
  const minNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  const noFocus = "focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0";
  const selectedMode = SOMA_MODE_OPTIONS.find((option) => option.value === mode);

  return <Dialog open={isOpen} onOpenChange={(open) => { if (!open && !submitting) { reset(); onClose(); } }}>
    <DialogContent className="max-w-2xl">
      <DialogHeader><DialogTitle className="text-xl font-semibold">Nova consulta Soma CLT</DialogTitle></DialogHeader>
      <div className="space-y-4 py-4">
        <div className="space-y-2"><Label htmlFor="soma-title" className="text-sm font-medium">Título da consulta *</Label><Input id="soma-title" value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Ex.: Lote Soma CLT – Campanha Agosto" disabled={submitting} className={cn("w-full", noFocus)} /></div>
        <div className="space-y-3"><Label className="text-sm font-medium">Modo de Consulta *</Label>
          <Tabs value={mode} onValueChange={(value) => setMode(value as "uy3" | "celcoin" | "both")}><TabsList className="grid h-auto w-full grid-cols-1 gap-3 bg-transparent p-0 sm:grid-cols-3">
            {SOMA_MODE_OPTIONS.map((option) => <TabsTrigger key={option.value} value={option.value} disabled={submitting} className={cn(noFocus, "h-auto min-h-[72px] flex-col items-start gap-1 rounded-lg border-2 px-4 py-3 text-left transition-all duration-200 sm:items-center sm:text-center", "border-gray-100 bg-gray-50/50 text-gray-600 hover:border-gray-300 hover:bg-gray-100", "data-[state=active]:border-blue-600 data-[state=active]:bg-blue-600 data-[state=active]:text-white data-[state=active]:shadow-md")}>
              <span className="text-sm font-bold">{option.label}</span><span className={cn("text-[10px] leading-tight transition-colors sm:text-xs", mode === option.value ? "text-blue-100" : "text-gray-500")}>{option.helper}</span>
            </TabsTrigger>)}
          </TabsList></Tabs>
          <div className="rounded-md border border-gray-200 bg-gray-50/70 px-3 py-2"><p className="text-xs font-medium text-gray-700">{selectedMode?.label ?? "Selecione um modo"}{selectedMode ? " :" : ""}</p><p className="mt-1 text-xs leading-5 text-gray-600">{selectedMode?.description ?? "Escolha como a consulta deve ser executada."}</p></div>
        </div>
        <div className="space-y-2"><Label htmlFor="soma-lines" className="text-sm font-medium">Linhas (CPF e Nome completo) *</Label><Textarea id="soma-lines" value={lines} onChange={(event) => setLines(event.target.value)} placeholder={'700367136\tRICARDO MENDES FIGUEIRA\n123.456.789-09, MARIA SILVA'} className={cn("min-h-[200px] w-full font-mono text-sm", noFocus)} disabled={submitting} />
          <div className="flex flex-col gap-2 text-xs text-gray-600 sm:flex-row sm:items-center sm:justify-between"><span>Informe CPF e nome em cada linha. Use CPF;NOME, CPF,NOME ou espaço/tab. CPFs recebem zeros à esquerda.</span><span className="font-medium text-blue-600">Detectadas: {lineCount.toLocaleString()} linhas</span></div></div>
        <div className="space-y-3 border-t border-gray-100 pt-4"><div className="flex items-center space-x-2"><Checkbox id="soma-schedule" checked={scheduled} onCheckedChange={(checked) => setScheduled(!!checked)} disabled={submitting} /><Label htmlFor="soma-schedule" className="cursor-pointer text-sm font-medium">Agendar início</Label></div>
          {scheduled && <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50/70 p-3"><Label htmlFor="soma-run-at" className="text-sm font-medium">Iniciar em</Label><Input id="soma-run-at" type="datetime-local" value={runAt} onChange={(event) => setRunAt(event.target.value)} min={minNow} disabled={submitting} className={cn("max-w-xs", noFocus)} /><p className="text-xs leading-5 text-gray-600">O lote ficará como agendado e entrará automaticamente na fila quando esse horário chegar.</p></div>}
        </div>
      </div>
      <DialogFooter className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row"><Button variant="outline" onClick={() => { reset(); onClose(); }} disabled={submitting} className={noFocus}>Cancelar</Button><Button onClick={() => void submit()} disabled={submitting || !mode} className={cn("bg-blue-600 text-white shadow-sm hover:bg-blue-700", noFocus)}>{submitting ? "Criando..." : scheduled ? "Agendar consulta" : "Criar consulta"}</Button></DialogFooter>
    </DialogContent>
  </Dialog>;
}
