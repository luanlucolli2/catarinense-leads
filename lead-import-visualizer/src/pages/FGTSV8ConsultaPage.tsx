import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { V8Controls } from "@/components/V8Controls";
import { NewV8FgtsConsultModal } from "@/components/NewV8FgtsConsultModal";
import { V8FgtsHistoryTable } from "@/components/V8FgtsHistoryTable";
import { usePersistedState } from "@/hooks/usePersistedState";
import {
  V8FgtsConsultJobListItem,
  V8FgtsConsultJobShow,
  cancelV8FgtsConsultJob,
  createV8FgtsConsultJob,
  deleteV8FgtsConsultJob,
  downloadV8FgtsPreview,
  downloadV8FgtsReport,
  getV8FgtsConsultJob,
  listV8FgtsConsultJobs,
} from "@/api/v8Fgts";

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

function getErrorMessage(error: unknown, fallback: string) {
  if (typeof error === "object" && error !== null) {
    const maybeError = error as {
      message?: string;
      status?: number;
      response?: { data?: { message?: string } };
    };

    return maybeError.response?.data?.message || maybeError.message || fallback;
  }

  return fallback;
}

const FGTSV8ConsultaPage = () => {
  const qc = useQueryClient();

  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [page, setPage] = useState(1);
  const [watchingJobId, setWatchingJobId] = usePersistedState<number | null>(
    "v8Fgts:watchJobId",
    null
  );

  const inFlight = useRef<Set<number>>(new Set());
  const lastSnapshot = useRef<{ id: number; status?: string | null; finishedAt?: string | null } | null>(null);

  const {
    data: jobsPage,
    isLoading: listLoading,
    refetch: refetchList,
  } = useQuery({
    queryKey: ["v8-fgts:list", page],
    queryFn: () => listV8FgtsConsultJobs(page),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: 30000,
  });

  const items = useMemo(() => jobsPage?.data ?? [], [jobsPage?.data]);
  const lastPage = jobsPage?.last_page ?? 1;

  const titleOf = useCallback(
    (id: number) => (jobsPage?.data ?? []).find((i) => i.id === id)?.title ?? `#${id}`,
    [jobsPage?.data]
  );

  const { data: watchedJob } = useQuery<V8FgtsConsultJobShow>({
    queryKey: ["v8-fgts:job", watchingJobId],
    queryFn: () => getV8FgtsConsultJob(watchingJobId as number),
    enabled: !!watchingJobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as V8FgtsConsultJobShow | undefined;
      if (!job) return false;
      const open =
        job.status === "pendente" ||
        job.status === "em_progresso" ||
        (job.status === "cancelado" && !job.finished_at);
      return open ? 5000 : false;
    },
  });

  const itemsWithOverlay: V8FgtsConsultJobListItem[] = useMemo(() => {
    if (!watchedJob) return items;

    return items.map((i) => {
      if (i.id !== watchedJob.id) return i;
      return {
        ...i,
        status: watchedJob.status,
        phase: watchedJob.phase,
        total_cpfs: watchedJob.total_cpfs,
        success_count: watchedJob.success_count,
        nao_elegivel_count: watchedJob.nao_elegivel_count,
        fail_count: watchedJob.fail_count,
        spool_bytes: watchedJob.spool_bytes ?? i.spool_bytes,
        finished_at: watchedJob.finished_at ?? i.finished_at,
        canceled_at: watchedJob.canceled_at ?? i.canceled_at,
      };
    });
  }, [items, watchedJob]);

  const filteredItems = useMemo(() => {
    const q = searchValue.trim().toLowerCase();
    if (!q) return itemsWithOverlay;
    return itemsWithOverlay.filter((i) => i.title.toLowerCase().includes(q));
  }, [itemsWithOverlay, searchValue]);

  useEffect(() => {
    if (!watchedJob) return;

    const niceTitle = watchedJob.title ?? `#${watchedJob.id}`;
    const cancelStopPending = watchedJob.status === "cancelado" && !watchedJob.finished_at;
    const isTerminal =
      watchedJob.status === "concluido" ||
      watchedJob.status === "falhou" ||
      (watchedJob.status === "cancelado" && !cancelStopPending);

    const prev = lastSnapshot.current;
    const changed =
      !prev ||
      prev.id !== watchedJob.id ||
      prev.status !== watchedJob.status ||
      prev.finishedAt !== watchedJob.finished_at;

    if (!changed) return;

    lastSnapshot.current = {
      id: watchedJob.id,
      status: watchedJob.status,
      finishedAt: watchedJob.finished_at,
    };

    if (cancelStopPending) {
      void qc.invalidateQueries({ queryKey: ["v8-fgts:list"] });
      return;
    }

    if (isTerminal) {
      if (watchedJob.status === "concluido") toast.success(`Consulta "${niceTitle}" concluída.`);
      else if (watchedJob.status === "falhou") toast.error(`Consulta "${niceTitle}" falhou.`);
      else if (watchedJob.status === "cancelado") toast.info(`Consulta "${niceTitle}" cancelada.`);

      setWatchingJobId(null);
      void qc.invalidateQueries({ queryKey: ["v8-fgts:list"] });
    }
  }, [watchedJob, qc, setWatchingJobId]);

  const createMutation = useMutation({
    mutationFn: createV8FgtsConsultJob,
    onSuccess: (data, vars) => {
      setWatchingJobId(data.id);
      toast.success(`Consulta "${vars.title}" criada.`);
      setPage(1);
      void qc.invalidateQueries({ queryKey: ["v8-fgts:list"] });
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, "Falha ao criar consulta")),
  });

  const cancelMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      cancelV8FgtsConsultJob(id, reason),
    onSuccess: (data, { id }) => {
      setWatchingJobId(id);
      if (data.finished_at) {
        toast.info(`Consulta "${titleOf(id)}" cancelada.`);
      } else {
        toast.info(`Cancelamento solicitado para "${titleOf(id)}". A prévia seguirá disponível enquanto houver spool.`);
      }
      void qc.invalidateQueries({ queryKey: ["v8-fgts:list"] });
      void qc.invalidateQueries({ queryKey: ["v8-fgts:job", id] });
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, "Não foi possível cancelar")),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteV8FgtsConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.success(`Consulta "${titleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["v8-fgts:list"] });
      void qc.removeQueries({ queryKey: ["v8-fgts:job", id] });
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, "Não foi possível excluir")),
  });

  const handleNewConsult = async (titulo: string, cpfs: string) => {
    await createMutation.mutateAsync({
      title: titulo,
      cpfs,
    });
  };

  const handleDownload = async (id: number, opts?: { preview?: boolean }) => {
    if (inFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    inFlight.current.add(id);

    try {
      const job = await qc.ensureQueryData<V8FgtsConsultJobShow>({
        queryKey: ["v8-fgts:job", id],
        queryFn: () => getV8FgtsConsultJob(id),
      });

      if (!opts?.preview && job.has_file) {
        await downloadV8FgtsReport(id);
        inFlight.current.delete(id);
        return;
      }

      try {
        await downloadV8FgtsPreview(id);
      } catch (error: unknown) {
        const status = typeof error === "object" && error !== null
          ? (error as { status?: number }).status
          : undefined;

        if (status === 409) {
          toast.info("Prévia indisponível ainda. Aguarde o início do processamento.");
        } else {
          toast.error(getErrorMessage(error, "Falha ao baixar a prévia"));
        }
      } finally {
        inFlight.current.delete(id);
        void qc.invalidateQueries({ queryKey: ["v8-fgts:job", id] });
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Falha no download"));
      inFlight.current.delete(id);
    }
  };

  const handleCancel = async (id: number) => {
    await cancelMutation.mutateAsync({ id });
  };

  const handleDelete = async (id: number) => {
    await deleteMutation.mutateAsync(id);
  };

  return (
    <div className="space-y-6">
      <V8Controls
        onNewConsultClick={() => setIsNewConsultModalOpen(true)}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
      />

      <V8FgtsHistoryTable
        items={filteredItems}
        loading={!!(listLoading && !jobsPage)}
        onDownload={handleDownload}
        onCancel={handleCancel}
        onDelete={handleDelete}
        page={page}
        lastPage={lastPage}
        onPageChange={(p) => setPage(p)}
        formatDateTimeBR={formatDateTimeBR}
      />

      <NewV8FgtsConsultModal
        isOpen={isNewConsultModalOpen}
        onClose={() => setIsNewConsultModalOpen(false)}
        onSubmit={handleNewConsult}
      />
    </div>
  );
};

export default FGTSV8ConsultaPage;
