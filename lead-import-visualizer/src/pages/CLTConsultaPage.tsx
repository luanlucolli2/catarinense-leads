// src/pages/CLTConsultaPage.tsx
import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient, keepPreviousData } from "@tanstack/react-query";
import { toast } from "sonner";

import { CLTControls } from "@/components/CLTControls";
import { CLTHistoryTable } from "@/components/CLTHistoryTable";
import { NewCLTConsultModal } from "@/components/NewCLTConsultModal";
import { usePersistedState } from "@/hooks/usePersistedState";

import {
  listCltConsultJobs,
  createCltConsultJob,
  downloadCltReport,
  downloadCltPreview,
  cancelCltConsultJob,
  deleteCltConsultJob,
  CltConsultJobListItem,
  CltConsultJobShow,
  getCltConsultJob,
  requestCltPreview,
} from "@/api/clt";

function formatDateTimeBR(iso: string | null | undefined) {
  if (!iso) return "-";
  const d = new Date(iso);
  return d.toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

const CLTConsultaPage = () => {
  const qc = useQueryClient();

  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");

  const [page, setPage] = useState(1);
  // persiste o job observado entre reloads/voltas
  const [watchingJobId, setWatchingJobId] = usePersistedState<number | null>(
    "clt:watchJobId",
    null
  );

  // 🔒 evita cliques repetidos (um lock por jobId)
  const inFlight = useRef<Set<number>>(new Set());
  // controla “esperando prévia” + toasts por job
  const waitingPreview = useRef<Set<number>>(new Set());
  const previewToastById = useRef<Map<number, string | number>>(new Map());
  const lastWatchedSnapshot = useRef<{ id: number; status?: string | null; pstatus?: string | null } | null>(null);

  /** ---------- LISTA (React Query) ---------- */
  const {
    data: jobsPage,
    isLoading: listLoading,
    refetch: refetchList,
  } = useQuery({
    queryKey: ["clt:list", page],
    queryFn: () => listCltConsultJobs(page),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: 30000, // polling lento fixo (30s)
  });

  const items = jobsPage?.data ?? [];
  const lastPage = jobsPage?.last_page ?? 1;

  const titleOf = (id: number) =>
    (jobsPage?.data ?? []).find((i) => i.id === id)?.title ?? `#${id}`;

  /** ---------- WATCH de 1 job (React Query) ---------- */
  const { data: watchedJob } = useQuery<CltConsultJobShow>({
    queryKey: ["clt:job", watchingJobId],
    queryFn: () => getCltConsultJob(watchingJobId as number),
    enabled: !!watchingJobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    // polling rápido (5s) somente quando aberto
    refetchInterval: (query) => {
      const job = query.state.data as CltConsultJobShow | undefined;
      if (!job) return false;
      const open =
        job.status === "pendente" ||
        job.status === "em_progresso";
      return open ? 5000 : false;
    },
  });

  /** 🔁 Overlay do watchedJob por cima da lista para refletir progresso/status na UI */
  const itemsWithOverlay: CltConsultJobListItem[] = useMemo(() => {
    if (!watchedJob) return items;
    return items.map((i) => {
      if (i.id !== watchedJob.id) return i;
      return {
        ...i,
        status: watchedJob.status,
        total_cpfs: watchedJob.total_cpfs,
        success_count: watchedJob.success_count,
        not_found_count: watchedJob.not_found_count,
        fail_count: watchedJob.fail_count,
        preview_updated_at: watchedJob.preview_updated_at ?? i.preview_updated_at,
      };
    });
  }, [items, watchedJob]);

  const filteredItems = useMemo(() => {
    const q = searchValue.trim().toLowerCase();
    if (!q) return itemsWithOverlay;
    return itemsWithOverlay.filter((i) => i.title.toLowerCase().includes(q));
  }, [itemsWithOverlay, searchValue]);

  /** Reações a mudanças do job observado */
  useEffect(() => {
    if (!watchedJob) return;

    const niceTitle = watchedJob.title ?? titleOf(watchedJob.id);
    const isTerminal = ["concluido", "falhou", "cancelado"].includes(watchedJob.status);

    // Evita repetir toasts em cada tick
    const prev = lastWatchedSnapshot.current;
    const changed =
      !prev ||
      prev.id !== watchedJob.id ||
      prev.status !== watchedJob.status ||
      prev.pstatus !== watchedJob.preview_status;

    if (!changed) return;
    lastWatchedSnapshot.current = {
      id: watchedJob.id,
      status: watchedJob.status,
      pstatus: watchedJob.preview_status,
    };

    // Se estamos aguardando prévia desse id:
    if (waitingPreview.current.has(watchedJob.id)) {
      if (watchedJob.has_file) {
        const tid = previewToastById.current.get(watchedJob.id);
        if (tid) toast.dismiss(tid);
        void downloadCltReport(watchedJob.id);
        waitingPreview.current.delete(watchedJob.id);
        previewToastById.current.delete(watchedJob.id);
        inFlight.current.delete(watchedJob.id);
      } else if (watchedJob.preview_status === "ready") {
        const tid = previewToastById.current.get(watchedJob.id);
        if (tid) toast.success("Prévia pronta! Baixando planilha…", { id: tid });
        void downloadCltPreview(watchedJob.id);
        waitingPreview.current.delete(watchedJob.id);
        previewToastById.current.delete(watchedJob.id);
        inFlight.current.delete(watchedJob.id);
      } else if (watchedJob.preview_status === "error") {
        const tid = previewToastById.current.get(watchedJob.id);
        const msg = watchedJob.preview_error
          ? `Falha ao gerar prévia: ${watchedJob.preview_error}`
          : "Falha ao gerar prévia.";
        tid ? toast.error(msg, { id: tid }) : toast.error(msg);
        waitingPreview.current.delete(watchedJob.id);
        previewToastById.current.delete(watchedJob.id);
        inFlight.current.delete(watchedJob.id);
      }
    }

    if (isTerminal) {
      if (watchedJob.status === "concluido") toast.success(`Consulta "${niceTitle}" concluída.`);
      else if (watchedJob.status === "falhou") toast.error(`Consulta "${niceTitle}" falhou.`);
      else if (watchedJob.status === "cancelado") toast.info(`Consulta "${niceTitle}" cancelada.`);

      setWatchingJobId(null); // para o polling do job (e limpa persistência)
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
    }
  }, [watchedJob, qc, setWatchingJobId]);

  /** ---------- MUTATIONS ---------- */

  const createMutation = useMutation({
    mutationFn: createCltConsultJob,
    onSuccess: (data, vars) => {
      setWatchingJobId(data.id);
      toast.success(`Consulta "${(vars as any).title}" criada.`);
      setPage(1);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Falha ao criar consulta"),
  });

  const cancelMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      cancelCltConsultJob(id, reason),
    onSuccess: (_data, { id }) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.info(`Consulta "${titleOf(id)}" cancelada.`);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
      void qc.invalidateQueries({ queryKey: ["clt:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível cancelar"),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteCltConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.success(`Consulta "${titleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
      void qc.removeQueries({ queryKey: ["clt:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível excluir"),
  });

  const requestPreviewMutation = useMutation({
    mutationFn: (id: number) => requestCltPreview(id),
  });

  /** ---------- Helpers ---------- */

  // Usa sempre o mesmo id de toast por job; evita duplicatas
  const getOrCreatePreviewToast = (id: number) => {
    const existing = previewToastById.current.get(id);
    if (existing) return existing;
    const stableId = `clt-prev-${id}`;
    toast.info("Gerando prévia…", {
      id: stableId,
      description: "Aguarde enquanto preparamos o XLSX.",
      duration: Infinity, // controlamos manualmente
    });
    previewToastById.current.set(id, stableId);
    return stableId;
  };

  /** ---------- Handlers ---------- */

  const handleNewConsult = async (titulo: string, cpfs: string) => {
    await createMutation.mutateAsync({ title: titulo, cpfs });
  };

  /** Botão único: decide final vs. prévia sob demanda. */
  const handleDownload = async (id: number, opts?: { preview?: boolean }) => {
    // Se já estamos aguardando a prévia desse job, não criamos outro toast nem outro POST
    if (waitingPreview.current.has(id)) {
      toast.warning("Já estamos gerando a prévia deste job.");
      return;
    }

    if (inFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    inFlight.current.add(id);

    try {
      const j = await qc.ensureQueryData<CltConsultJobShow>({
        queryKey: ["clt:job", id],
        queryFn: () => getCltConsultJob(id),
      });

      // FINAL disponível → baixa
      if (!opts?.preview && j.has_file) {
        await downloadCltReport(id);
        inFlight.current.delete(id);
        return;
      }

      // Intenção: PRÉVIA (força generate sempre)
      if (opts?.preview) {
        const tid = getOrCreatePreviewToast(id);
        waitingPreview.current.add(id);
        setWatchingJobId(id); // ativa o polling rápido do job (5s)

        const status = await requestPreviewMutation.mutateAsync(id);

        if (status === 200) {
          // já pronta → fecha toast e baixa imediatamente
          toast.dismiss(tid);
          previewToastById.current.delete(id);
          waitingPreview.current.delete(id);

          await downloadCltPreview(id);
          inFlight.current.delete(id);
          void qc.invalidateQueries({ queryKey: ["clt:job", id] });
          return;
        }

        // 202/409 → aguarda via polling
        void qc.invalidateQueries({ queryKey: ["clt:job", id] });
        return;
      }

      // Sem final ainda → comportamento igual ao da prévia
      const tid = getOrCreatePreviewToast(id);
      waitingPreview.current.add(id);
      setWatchingJobId(id);

      const status = await requestPreviewMutation.mutateAsync(id);
      if (status === 200) {
        toast.dismiss(tid);
        previewToastById.current.delete(id);
        waitingPreview.current.delete(id);

        await downloadCltPreview(id);
        inFlight.current.delete(id);
        void qc.invalidateQueries({ queryKey: ["clt:job", id] });
        return;
      }
      void qc.invalidateQueries({ queryKey: ["clt:job", id] });
    } catch (e: any) {
      const apiMsg = e?.response?.data?.message || e?.message;
      toast.error(apiMsg ?? "Falha no download");

      // limpeza defensiva
      waitingPreview.current.delete(id);
      const tid = previewToastById.current.get(id);
      if (tid) {
        toast.dismiss(tid);
        previewToastById.current.delete(id);
      }
      inFlight.current.delete(id);
    }
  };

  const handleCancel = async (id: number, reason?: string) => {
    await cancelMutation.mutateAsync({ id, reason });
  };

  const handleDelete = async (id: number) => {
    await deleteMutation.mutateAsync(id);
  };

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          Consulta CLT (Facta Crédito do Trabalhador)
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Realize consultas CLT em massa colando CPFs e baixe o resultado em Excel.
        </p>
      </div>

      <div className="space-y-6">
        <CLTControls
          onNewConsultClick={() => setIsNewConsultModalOpen(true)}
          searchValue={searchValue}
          onSearchChange={setSearchValue}
        />

        <CLTHistoryTable
          items={filteredItems}
          // evita “piscar” em refetch: só mostra loading no 1º load (sem dados ainda)
          loading={!!(listLoading && !jobsPage)}
          onDownload={handleDownload}
          onCancel={handleCancel}
          onDelete={handleDelete}
          onRefresh={() => refetchList()}
          page={page}
          lastPage={lastPage}
          onPageChange={(p) => setPage(p)}
          formatDateTimeBR={formatDateTimeBR}
        />
      </div>

      <NewCLTConsultModal
        isOpen={isNewConsultModalOpen}
        onClose={() => setIsNewConsultModalOpen(false)}
        onSubmit={handleNewConsult}
      />
    </div>
  );
};

export default CLTConsultaPage;
