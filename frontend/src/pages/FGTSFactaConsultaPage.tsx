import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient, keepPreviousData } from "@tanstack/react-query";
import { toast } from "sonner";

import { FgtsOffControls } from "@/components/FgtsOffControls";
import { FgtsOffHistoryTable } from "@/components/FgtsOffHistoryTable";
import { NewFgtsOffConsultModal } from "@/components/NewFGTSOffConsultModal";
import { usePersistedState } from "@/hooks/usePersistedState";

import {
  listFgtsOffConsultJobs,
  createFgtsOffConsultJob,
  downloadFgtsOffReport,
  downloadFgtsOffPreview,
  cancelFgtsOffConsultJob,
  deleteFgtsOffConsultJob,
  FgtsOffConsultJobListItem,
  FgtsOffConsultJobShow,
  getFgtsOffConsultJob,
} from "@/api/fgtsOff";

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

const FGTSFactaConsultaPage = () => {
  const qc = useQueryClient();

  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [page, setPage] = useState(1);
  const [watchingJobId, setWatchingJobId] = usePersistedState<number | null>(
    "fgtsOff:watchJobId",
    null
  );
  const inFlight = useRef<Set<number>>(new Set());

  const {
    data: jobsPage,
    isLoading: listLoading,
    refetch: refetchList,
  } = useQuery({
    queryKey: ["fgtsOff:list", page],
    queryFn: () => listFgtsOffConsultJobs(page),
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

  const { data: watchedJob } = useQuery<FgtsOffConsultJobShow>({
    queryKey: ["fgtsOff:job", watchingJobId],
    queryFn: () => getFgtsOffConsultJob(watchingJobId as number),
    enabled: !!watchingJobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as FgtsOffConsultJobShow | undefined;
      if (!job) return false;
      const open =
        job.status === "pendente" ||
        job.status === "em_progresso" ||
        job.status === "agendado";
      return open ? 5000 : false;
    },
  });

  const itemsWithOverlay: FgtsOffConsultJobListItem[] = useMemo(() => {
    if (!watchedJob) return items;
    return items.map((i) => {
      if (i.id !== watchedJob.id) return i;
      return {
        ...i,
        status: watchedJob.status,
        total_cpfs: watchedJob.total_cpfs,
        success_count: watchedJob.success_count,
        not_authorized_count: watchedJob.not_authorized_count,
        fail_count: watchedJob.fail_count,
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

    const niceTitle = watchedJob.title ?? titleOf(watchedJob.id);
    const isTerminal = ["concluido", "falhou", "cancelado", "expirado"].includes(watchedJob.status);

    if (isTerminal) {
      if (watchedJob.status === "concluido") toast.success(`Consulta "${niceTitle}" concluída.`);
      else if (watchedJob.status === "expirado") toast.info(`Consulta "${niceTitle}" finalizada por expiração da janela.`);
      else if (watchedJob.status === "falhou") toast.error(`Consulta "${niceTitle}" falhou.`);
      else if (watchedJob.status === "cancelado") toast.info(`Consulta "${niceTitle}" cancelada.`);

      setWatchingJobId(null);
      void qc.invalidateQueries({ queryKey: ["fgtsOff:list"] });
    }
  }, [watchedJob, qc, setWatchingJobId, titleOf]);

  const createMutation = useMutation({
    mutationFn: createFgtsOffConsultJob,
    onSuccess: (data, vars) => {
      setWatchingJobId(data.id);
      toast.success(`Consulta "${(vars as { title?: string }).title}" criada.`);
      setPage(1);
      void qc.invalidateQueries({ queryKey: ["fgtsOff:list"] });
    },
    onError: (e: Error) => toast.error(e.message ?? "Falha ao criar consulta"),
  });

  const cancelMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      cancelFgtsOffConsultJob(id, reason),
    onSuccess: (_data, { id }) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.info(`Consulta "${titleOf(id)}" cancelada.`);
      void qc.invalidateQueries({ queryKey: ["fgtsOff:list"] });
      void qc.invalidateQueries({ queryKey: ["fgtsOff:job", id] });
    },
    onError: (e: Error) => toast.error(e.message ?? "Não foi possível cancelar"),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteFgtsOffConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.success(`Consulta "${titleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["fgtsOff:list"] });
      void qc.removeQueries({ queryKey: ["fgtsOff:job", id] });
    },
    onError: (e: Error) => toast.error(e.message ?? "Não foi possível excluir"),
  });

  const handleNewConsult = async (
    titulo: string,
    cpfs: string,
    opts?: { runAt?: string | null; endAt?: string | null; timezone?: string | null }
  ) => {
    const payload: {
      title: string;
      cpfs: string;
      run_at?: string | null;
      end_at?: string | null;
      timezone?: string | null;
    } = { title: titulo, cpfs };

    if (opts?.runAt) payload.run_at = opts.runAt;
    if (opts?.endAt) payload.end_at = opts.endAt;
    if (opts?.timezone) payload.timezone = opts.timezone;

    await createMutation.mutateAsync(payload);
  };

  const handleDownload = async (id: number, opts?: { preview?: boolean }) => {
    if (inFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    inFlight.current.add(id);

    try {
      const j = await qc.ensureQueryData<FgtsOffConsultJobShow>({
        queryKey: ["fgtsOff:job", id],
        queryFn: () => getFgtsOffConsultJob(id),
      });

      if (!opts?.preview && j.has_file) {
        await downloadFgtsOffReport(id);
        inFlight.current.delete(id);
        return;
      }

      try {
        await downloadFgtsOffPreview(id);
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
        void qc.invalidateQueries({ queryKey: ["fgtsOff:job", id] });
      }
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, "Falha no download"));
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
    <div className="space-y-6">
      <FgtsOffControls
        onNewConsultClick={() => setIsNewConsultModalOpen(true)}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
      />

      <FgtsOffHistoryTable
        items={filteredItems}
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

      <NewFgtsOffConsultModal
        isOpen={isNewConsultModalOpen}
        onClose={() => setIsNewConsultModalOpen(false)}
        onSubmit={handleNewConsult}
      />
    </div>
  );
};

export default FGTSFactaConsultaPage;
