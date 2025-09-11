// src/pages/CLTConsultaPage.tsx
import { useEffect, useMemo, useState } from "react";
import { CLTControls } from "@/components/CLTControls";
import { CLTHistoryTable } from "@/components/CLTHistoryTable";
import { NewCLTConsultModal } from "@/components/NewCLTConsultModal";
import {
  listCltConsultJobs,
  createCltConsultJob,
  downloadCltReport,
  downloadCltPreview,
  pauseCltConsultJob,
  resumeCltConsultJob,
  cancelCltConsultJob,
  deleteCltConsultJob,
  CltConsultJobListItem,
} from "@/api/clt";
import { useCltJobPolling } from "@/hooks/useCltJobPolling";
import { toast } from "sonner";
import type { AxiosError } from "axios";

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
  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [loading, setLoading] = useState(false);

  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [items, setItems] = useState<CltConsultJobListItem[]>([]);

  const [watchingJobId, setWatchingJobId] = useState<number | null>(null);
  const { job: watchedJob } = useCltJobPolling(watchingJobId, {
    enabled: !!watchingJobId,
    intervalMs: 3000,
    stopOn: ["concluido", "falhou", "cancelado"],
  });

  // helper para mostrar o nome nas mensagens
  const titleOf = (id: number) =>
    items.find((i) => i.id === id)?.title ?? `#${id}`;

  async function fetchPage(p = 1) {
    setLoading(true);
    try {
      const res = await listCltConsultJobs(p);
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
      const res = await listCltConsultJobs(p);
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
          i.status === "pausado"
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

  const handleNewConsult = async (titulo: string, cpfs: string) => {
    try {
      const { id } = await createCltConsultJob({ title: titulo, cpfs });
      setWatchingJobId(id);
      toast.success(`Consulta "${titulo}" criada.`);
      await fetchPage(1);
    } catch (e: any) {
      toast.error(e?.message ?? "Falha ao criar consulta");
    }
  };

  const handleDownload = async (id: number, opts?: { preview?: boolean }) => {
    try {
      if (opts?.preview) {
        // 🔁 força regeneração baseada no spool atual
        await downloadCltPreview(id, { refresh: true });
      } else {
        await downloadCltReport(id);
      }
    } catch (e: any) {
      const ax = e as AxiosError;
      if (opts?.preview && ax?.response?.status === 409) {
        toast.info("A prévia ainda não está disponível. Tente novamente em alguns segundos.");
        return;
      }
      toast.error((ax?.response as any)?.data?.message ?? e?.message ?? "Falha no download");
    }
  };

  const handlePause = async (id: number) => {
    const niceTitle = titleOf(id);
    try {
      await pauseCltConsultJob(id);
      toast.warning(`Consulta "${niceTitle}" pausada.`);
      await fetchPage(page);
    } catch (e: any) {
      toast.error(e?.message ?? "Não foi possível pausar");
    }
  };

  const handleResume = async (id: number) => {
    const niceTitle = titleOf(id);
    try {
      await resumeCltConsultJob(id);
      setWatchingJobId(id); // volta a observar este job
      toast.success(`Consulta "${niceTitle}" retomada.`);
      await fetchPage(page);
    } catch (e: any) {
      toast.error(e?.message ?? "Não foi possível retomar");
    }
  };

  const handleCancel = async (id: number, reason?: string) => {
    const niceTitle = titleOf(id);
    try {
      await cancelCltConsultJob(id, reason);
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
      await deleteCltConsultJob(id);
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
          Consulta CLT (Consignado em Folha)
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          As importações existentes são FGTS. Aqui você realiza consulta CLT em massa colando
          CPFs e baixa o resultado em Excel.
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
          loading={loading}
          onDownload={handleDownload}
          onPause={handlePause}
          onResume={handleResume}
          onCancel={handleCancel}
          onDelete={handleDelete}
          onRefresh={() => fetchPage(page)}
          page={page}
          lastPage={lastPage}
          onPageChange={(p) => fetchPage(p)}
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
