import { useEffect, useMemo, useRef, useState } from "react";
import { FgtsOffControls } from "@/components/FgtsOffControls";
import { FgtsOffHistoryTable } from "@/components/FgtsOffHistoryTable";
import { NewFgtsOffConsultModal } from "@/components/NewFGTSOffConsultModal";
import {
  listFgtsOffConsultJobs,
  createFgtsOffConsultJob,
  downloadFgtsOffReport,
  downloadFgtsOffPreview,
  cancelFgtsOffConsultJob,
  deleteFgtsOffConsultJob,
  FgtsOffConsultJobListItem,
  getFgtsOffConsultJob,
  requestFgtsOffPreview,
} from "@/api/fgtsOff";
import { useFgtsOffJobPolling } from "@/hooks/useFgtsOffJobPolling";
import { toast } from "sonner";

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

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

const FGTSOfflineConsultaPage = () => {
  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [loading, setLoading] = useState(false);

  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [items, setItems] = useState<FgtsOffConsultJobListItem[]>([]);

  const [watchingJobId, setWatchingJobId] = useState<number | null>(null);
  const { job: watchedJob } = useFgtsOffJobPolling(watchingJobId, {
    enabled: !!watchingJobId,
    intervalMs: 3000,
    stopOn: ["concluido", "falhou", "cancelado", "expirado"],
  });

  // 🔒 evita cliques repetidos (um lock por jobId)
  const inFlight = useRef<Set<number>>(new Set());

  const titleOf = (id: number) =>
    items.find((i) => i.id === id)?.title ?? `#${id}`;

  async function fetchPage(p = 1) {
    setLoading(true);
    try {
      const res = await listFgtsOffConsultJobs(p);
      setItems(res.data);
      setTotal(res.total);
      setLastPage(res.last_page);
      setPage(res.current_page);
    } catch (e: any) {
      toast.error(e?.message ?? "Falha ao carregar histórico");
    } finally {
      setLoading(false);
    }
  }

  async function fetchPageSilent(p = page) {
    try {
      const res = await listFgtsOffConsultJobs(p);
      setItems(res.data);
      setTotal(res.total);
      setLastPage(res.last_page);
      setPage(res.current_page);
    } catch {
      // silencioso
    }
  }

  useEffect(() => {
    fetchPage(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const hasOpen = useMemo(
    () =>
      items.some(
        (i) =>
          i.status === "pendente" ||
          i.status === "em_progresso" ||
          i.status === "agendado"
      ),
    [items]
  );

  useEffect(() => {
    if (!hasOpen) return;
    const t = window.setInterval(() => {
      void fetchPageSilent(page);
    }, 5000);
    return () => window.clearInterval(t);
  }, [hasOpen, page]);

  useEffect(() => {
    if (!watchedJob) return;
    const niceTitle = watchedJob.title ?? titleOf(watchedJob.id);

    if (watchedJob.status === "concluido") {
      setWatchingJobId(null);
      toast.success(`Consulta "${niceTitle}" concluída.`);
      void fetchPage(page);
    } else if (watchedJob.status === "expirado") {
      setWatchingJobId(null);
      toast.info(`Consulta "${niceTitle}" finalizada por expiração da janela.`);
      void fetchPage(page);
    } else if (watchedJob.status === "falhou") {
      setWatchingJobId(null);
      toast.error(`Consulta "${niceTitle}" falhou.`);
      void fetchPage(page);
    } else if (watchedJob.status === "cancelado") {
      setWatchingJobId(null);
      toast.info(`Consulta "${niceTitle}" cancelada.`);
      void fetchPage(page);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [watchedJob]);

  const handleNewConsult = async (
    titulo: string,
    cpfs: string,
    opts?: { runAt?: string | null; endAt?: string | null; timezone?: string | null }
  ) => {
    try {
      const payload: any = { title: titulo, cpfs };
      if (opts?.runAt) payload.run_at = opts.runAt;
      if (opts?.endAt) payload.end_at = opts.endAt;
      if (opts?.timezone) payload.timezone = opts.timezone;

      const { id } = await createFgtsOffConsultJob(payload);
      setWatchingJobId(id);
      toast.success(`Consulta "${titulo}" criada.`);
      await fetchPage(1);
    } catch (e: any) {
      toast.error(e?.message ?? "Falha ao criar consulta");
    }
  };

  /** Polling ad-hoc até prévia pronta (ou final pronto). */
  /** Polling ad-hoc até prévia pronta (ou final pronto). */
  // substitui TODO o pollPreviewAndDownload atual por este:

  const pollPreviewAndDownload = async (id: number) => {
    const toastId = toast.info("Gerando prévia…", { description: "Aguarde enquanto preparamos o XLSX." });

    let sawQueuedOrRunning = false;
    let lastPreviewStatus: string | null = null;
    let lastJobStatus: string | null = null;

    // backoff: 2s,3s,4s… até 15s (reinicia quando houver mudança de estado)
    let delayMs = 2000;
    const nextDelay = () => {
      delayMs = Math.min(delayMs + 1000, 15000);
      return delayMs;
    };
    const resetDelay = () => (delayMs = 2000);

    // loop “até dar boa ou ruim”
    while (true) {
      let j: Awaited<ReturnType<typeof getFgtsOffConsultJob>>;
      try {
        j = await getFgtsOffConsultJob(id);
      } catch (e: any) {
        // falha transitória de rede → espera e tenta de novo
        await sleep(nextDelay());
        continue;
      }

      // mudança de estado → acelera próximo tick
      if (j.preview_status !== lastPreviewStatus || j.status !== lastJobStatus) {
        resetDelay();
        lastPreviewStatus = j.preview_status;
        lastJobStatus = j.status;
      }

      // FINAL pronto
      if (j.has_file) {
        toast.dismiss(toastId);
        await downloadFgtsOffReport(id);
        return;
      }

      // PRÉVIA estados
      if (j.preview_status === "queued" || j.preview_status === "running") {
        sawQueuedOrRunning = true;
      }

      if (j.preview_status === "ready") {
        if (sawQueuedOrRunning) {
          toast.success("Prévia pronta! Baixando planilha…", { id: toastId });
        } else {
          toast.dismiss(toastId);
        }
        await downloadFgtsOffPreview(id);
        return;
      }

      if (j.preview_status === "error") {
        toast.error(j.preview_error ? `Falha ao gerar prévia: ${j.preview_error}` : "Falha ao gerar prévia.", { id: toastId });
        return;
      }

      // estados terminais do job
      if (["concluido", "falhou", "cancelado", "expirado"].includes(j.status)) {
        toast.dismiss(toastId);
        if (j.has_file) {
          await downloadFgtsOffReport(id);
        } else {
          toast.error("Job finalizado sem arquivo disponível.");
        }
        return;
      }

      // ainda em andamento → espera com backoff incremental
      await sleep(nextDelay());
    }
  };


  /** Botão único: decide final vs. prévia sob demanda (fila). */
  const handleDownload = async (id: number) => {
    // 🔒 idempotência por job: bloqueia cliques repetidos
    if (inFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    inFlight.current.add(id);
    try {
      const j = await getFgtsOffConsultJob(id);

      if (j.preview_status === "error") {
        const msg = j.preview_error ? ` Detalhe: ${j.preview_error}` : "";
        toast.error(`Falha na última geração de prévia.${msg}`);
      }

      if (j.has_file) {
        await downloadFgtsOffReport(id);
        return;
      }

      if (j.preview_status === "queued" || j.preview_status === "running") {
        await pollPreviewAndDownload(id);
        return;
      }

      const status = await requestFgtsOffPreview(id);

      if (status === 202) {
        await pollPreviewAndDownload(id);
        return;
      }

      if (status === 200) {
        await downloadFgtsOffPreview(id);
        return;
      }

      if (status === 409) {
        await pollPreviewAndDownload(id);
        return;
      }

      if (j.has_preview) {
        await downloadFgtsOffPreview(id);
        return;
      }

      toast.error("Não foi possível solicitar a geração da prévia.");
    } catch (e: any) {
      const apiMsg = e?.response?.data?.message || e?.message;
      toast.error(apiMsg ?? "Falha no download");
    } finally {
      inFlight.current.delete(id);
    }
  };

  const handleCancel = async (id: number, reason?: string) => {
    const niceTitle = titleOf(id);
    try {
      await cancelFgtsOffConsultJob(id, reason);
      if (id === watchingJobId) setWatchingJobId(null);
      toast.info(`Consulta "${niceTitle}" cancelada.`);
      await fetchPage(page);
    } catch (e: any) {
      toast.error(e?.message ?? "Não foi possível cancelar");
    }
  };

  const handleDelete = async (id: number) => {
    const niceTitle = titleOf(id);
    try {
      await deleteFgtsOffConsultJob(id);
      if (id === watchingJobId) setWatchingJobId(null);
      toast.success(`Consulta "${niceTitle}" excluída.`);
      await fetchPage(page);
    } catch (e: any) {
      toast.error(e?.message ?? "Não foi possível excluir");
    }
  };

  const filteredItems = useMemo(() => {
    const q = searchValue.trim().toLowerCase();
    if (!q) return items;
    return items.filter((i) => i.title.toLowerCase().includes(q));
  }, [items, searchValue]);

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          Consulta FGTS (Base Offline)
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          Realize consulta FGTS Base Offline em massa colando CPFs e baixe o resultado em Excel. É possível agendar uma janela de execução.
        </p>
      </div>

      <div className="space-y-6">
        <FgtsOffControls
          onNewConsultClick={() => setIsNewConsultModalOpen(true)}
          searchValue={searchValue}
          onSearchChange={setSearchValue}
        />

        <FgtsOffHistoryTable
          items={filteredItems}
          loading={loading}
          onDownload={handleDownload}
          onCancel={handleCancel}
          onDelete={handleDelete}
          onRefresh={() => fetchPage(page)}
          page={page}
          lastPage={lastPage}
          onPageChange={(p) => fetchPage(p)}
          formatDateTimeBR={formatDateTimeBR}
        />
      </div>

      <NewFgtsOffConsultModal
        isOpen={isNewConsultModalOpen}
        onClose={() => setIsNewConsultModalOpen(false)}
        onSubmit={handleNewConsult}
      />
    </div>
  );
};

export default FGTSOfflineConsultaPage;
