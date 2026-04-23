// src/pages/CLTConsultaPage.tsx
import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient, keepPreviousData } from "@tanstack/react-query";
import { toast } from "sonner";

import { CLTControls } from "@/components/CLTControls";
import { CLTHistoryTable } from "@/components/CLTHistoryTable";
import { NewCLTConsultModal } from "@/components/NewCLTConsultModal";
import { V8Controls } from "@/components/V8Controls";
import { V8HistoryTable } from "@/components/V8HistoryTable";
import { NewV8ConsultModal } from "@/components/NewV8ConsultModal";
import { PresencaHistoryTable } from "@/components/PresencaHistoryTable";
import { NewPresencaConsultModal } from "@/components/NewPresencaConsultModal";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { usePersistedState } from "@/hooks/usePersistedState";
import factaLogo from "@/assets/factalogo.png";
import v8Logo from "@/assets/v8logo.png";
import pbankLogo from "@/assets/pbanklogo.png";

import {
  listCltConsultJobs,
  createCltConsultJob,
  downloadCltReport,
  downloadCltPreview,
  cancelCltConsultJob,
  rerunCltConsultJobPhase2,
  deleteCltConsultJob,
  CltConsultJobListItem,
  CltConsultJobShow,
  getCltConsultJob,
  getCltJobHttpCounters,
  CltJobHttpCountersResponse,
  CltJobStatusFilter,
  CltJobVariantFilter,
  requestCltPreview,
} from "@/api/clt";
import {
  listV8ConsultJobs,
  createV8ConsultJob,
  downloadV8Report,
  downloadV8Preview,
  cancelV8ConsultJob,
  deleteV8ConsultJob,
  V8ConsultJobListItem,
  V8ConsultJobShow,
  getV8ConsultJob,
} from "@/api/v8";
import {
  listPresencaConsultJobs,
  createPresencaConsultJob,
  downloadPresencaReport,
  downloadPresencaPreview,
  cancelPresencaConsultJob,
  deletePresencaConsultJob,
  PresencaConsultJobListItem,
  PresencaConsultJobShow,
  getPresencaConsultJob,
} from "@/api/presenca";

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

function apiErrorMessage(error: any, fallback: string) {
  return error?.response?.data?.message || error?.message || fallback;
}

function deleteJobErrorMessage(error: any) {
  const status = error?.response?.status ?? error?.status;
  if (status === 409) {
    return (
      error?.response?.data?.message ||
      "Cancelamento em finalização. Tente excluir novamente em alguns segundos."
    );
  }

  return apiErrorMessage(error, "Não foi possível excluir");
}

const CLTConsultaPage = () => {
  const qc = useQueryClient();

  const [activeTab, setActiveTab] = usePersistedState<"facta" | "v8" | "presenca">(
    "clt:activeTab",
    "facta"
  );

  const [isNewConsultModalOpen, setIsNewConsultModalOpen] = useState(false);
  const [searchValue, setSearchValue] = useState("");
  const [statusFilter, setStatusFilter] = usePersistedState<CltJobStatusFilter>(
    "clt:statusFilter",
    "todos"
  );
  const [variantFilter, setVariantFilter] = usePersistedState<CltJobVariantFilter>(
    "clt:variantFilter",
    "todos"
  );
  const [page, setPage] = useState(1);

  const [isNewV8ModalOpen, setIsNewV8ModalOpen] = useState(false);
  const [searchValueV8, setSearchValueV8] = useState("");
  const [pageV8, setPageV8] = useState(1);
  const [isNewPresencaModalOpen, setIsNewPresencaModalOpen] = useState(false);
  const [searchValuePresenca, setSearchValuePresenca] = useState("");
  const [pagePresenca, setPagePresenca] = useState(1);

  const [watchingJobId, setWatchingJobId] = usePersistedState<number | null>(
    "clt:watchJobId",
    null
  );
  const [watchingV8JobId, setWatchingV8JobId] = usePersistedState<number | null>(
    "v8:watchJobId",
    null
  );
  const [watchingPresencaJobId, setWatchingPresencaJobId] = usePersistedState<number | null>(
    "presenca:watchJobId",
    null
  );
  const [httpCountersModalJob, setHttpCountersModalJob] = useState<{ id: number; title: string } | null>(null);
  const [httpCountersRefreshCooldownUntil, setHttpCountersRefreshCooldownUntil] = useState<number>(0);
  const [httpCountersNowMs, setHttpCountersNowMs] = useState<number>(Date.now());

  const inFlight = useRef<Set<number>>(new Set());
  const waitingPreview = useRef<Set<number>>(new Set());
  const previewToastById = useRef<Map<number, string | number>>(new Map());
  const lastWatchedSnapshot = useRef<{ id: number; status?: string | null; pstatus?: string | null } | null>(null);

  const v8InFlight = useRef<Set<number>>(new Set());
  const lastV8Snapshot = useRef<{ id: number; status?: string | null } | null>(null);
  const presencaInFlight = useRef<Set<number>>(new Set());
  const lastPresencaSnapshot = useRef<{ id: number; status?: string | null } | null>(null);

  const {
    data: jobsPage,
    isLoading: listLoading,
    refetch: refetchList,
  } = useQuery({
    queryKey: ["clt:list", page, statusFilter, variantFilter],
    queryFn: () => listCltConsultJobs(page, { status: statusFilter, variant: variantFilter }),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: 30000,
  });

  const items = jobsPage?.data ?? [];
  const lastPage = jobsPage?.last_page ?? 1;

  const titleOf = (id: number) =>
    (jobsPage?.data ?? []).find((i) => i.id === id)?.title ?? `#${id}`;

  const {
    data: v8JobsPage,
    isLoading: v8ListLoading,
    refetch: refetchV8List,
  } = useQuery({
    queryKey: ["v8:list", pageV8],
    queryFn: () => listV8ConsultJobs(pageV8),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: activeTab === "v8" ? 30000 : false,
  });

  const v8Items = v8JobsPage?.data ?? [];
  const v8LastPage = v8JobsPage?.last_page ?? 1;

  const v8TitleOf = (id: number) =>
    (v8JobsPage?.data ?? []).find((i) => i.id === id)?.title ?? `#${id}`;

  const {
    data: presencaJobsPage,
    isLoading: presencaListLoading,
    refetch: refetchPresencaList,
  } = useQuery({
    queryKey: ["presenca:list", pagePresenca],
    queryFn: () => listPresencaConsultJobs(pagePresenca),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: true,
    refetchInterval: activeTab === "presenca" ? 30000 : false,
  });

  const presencaItems = presencaJobsPage?.data ?? [];
  const presencaLastPage = presencaJobsPage?.last_page ?? 1;

  const presencaTitleOf = (id: number) =>
    (presencaJobsPage?.data ?? []).find((i) => i.id === id)?.title ?? `#${id}`;

  const { data: watchedJob } = useQuery<CltConsultJobShow>({
    queryKey: ["clt:job", watchingJobId],
    queryFn: () => getCltConsultJob(watchingJobId as number),
    enabled: !!watchingJobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as CltConsultJobShow | undefined;
      if (!job) return false;
      const open = job.status === "pendente" || job.status === "em_progresso";
      return open ? 5000 : false;
    },
  });

  const { data: watchedV8Job } = useQuery<V8ConsultJobShow>({
    queryKey: ["v8:job", watchingV8JobId],
    queryFn: () => getV8ConsultJob(watchingV8JobId as number),
    enabled: !!watchingV8JobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as V8ConsultJobShow | undefined;
      if (!job) return false;
      const open = job.status === "pendente" || job.status === "em_progresso";
      return open ? 5000 : false;
    },
  });

  const { data: watchedPresencaJob } = useQuery<PresencaConsultJobShow>({
    queryKey: ["presenca:job", watchingPresencaJobId],
    queryFn: () => getPresencaConsultJob(watchingPresencaJobId as number),
    enabled: !!watchingPresencaJobId,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchOnMount: "always",
    refetchInterval: (query) => {
      const job = query.state.data as PresencaConsultJobShow | undefined;
      if (!job) return false;
      const open = job.status === "pendente" || job.status === "em_progresso";
      return open ? 5000 : false;
    },
  });

  const { data: httpCountersData, isLoading: httpCountersLoading, isFetching: httpCountersFetching, error: httpCountersError, refetch: refetchHttpCounters } =
    useQuery<CltJobHttpCountersResponse>({
      queryKey: ["clt:http-counters", httpCountersModalJob?.id ?? null],
      queryFn: () => getCltJobHttpCounters(httpCountersModalJob!.id),
      enabled: !!httpCountersModalJob?.id,
      refetchOnWindowFocus: true,
      refetchInterval: (query) => {
        const data = query.state.data as CltJobHttpCountersResponse | undefined;
        if (!httpCountersModalJob?.id) return false;
        if (!data) return 15000;
        return data.status === "pendente" || data.status === "em_progresso" ? 15000 : false;
      },
    });

  useEffect(() => {
    if (httpCountersRefreshCooldownUntil <= Date.now()) return;

    const timer = window.setInterval(() => {
      setHttpCountersNowMs(Date.now());
    }, 250);

    return () => {
      window.clearInterval(timer);
    };
  }, [httpCountersRefreshCooldownUntil]);

  const itemsWithOverlay: CltConsultJobListItem[] = useMemo(() => {
    if (!watchedJob) return items;
    return items.map((i) => {
      if (i.id !== watchedJob.id) return i;
      return {
        ...i,
        status: watchedJob.status,
        phase: watchedJob.phase,
        phase2_total: watchedJob.phase2_total ?? i.phase2_total,
        phase2_attempt: watchedJob.phase2_attempt ?? i.phase2_attempt,
        phase2_aprovado_count: watchedJob.phase2_aprovado_count ?? i.phase2_aprovado_count,
        phase2_nao_aprovado_count: watchedJob.phase2_nao_aprovado_count ?? i.phase2_nao_aprovado_count,
        total_cpfs: watchedJob.total_cpfs,
        elegivel_count: watchedJob.elegivel_count,
        inelegivel_count: watchedJob.inelegivel_count,
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

  const v8ItemsWithOverlay: V8ConsultJobListItem[] = useMemo(() => {
    if (!watchedV8Job) return v8Items;
    return v8Items.map((i) => {
      if (i.id !== watchedV8Job.id) return i;
      return {
        ...i,
        status: watchedV8Job.status,
        phase: watchedV8Job.phase,
        total_cpfs: watchedV8Job.total_cpfs,
        success_count: watchedV8Job.success_count,
        nao_elegivel_count: watchedV8Job.nao_elegivel_count,
        fail_count: watchedV8Job.fail_count,
        spool_bytes: watchedV8Job.spool_bytes ?? i.spool_bytes,
      };
    });
  }, [v8Items, watchedV8Job]);

  const filteredV8Items = useMemo(() => {
    const q = searchValueV8.trim().toLowerCase();
    if (!q) return v8ItemsWithOverlay;
    return v8ItemsWithOverlay.filter((i) => i.title.toLowerCase().includes(q));
  }, [v8ItemsWithOverlay, searchValueV8]);

  const presencaItemsWithOverlay: PresencaConsultJobListItem[] = useMemo(() => {
    if (!watchedPresencaJob) return presencaItems;
    return presencaItems.map((i) => {
      if (i.id !== watchedPresencaJob.id) return i;
      return {
        ...i,
        status: watchedPresencaJob.status,
        phase: watchedPresencaJob.phase,
        total_cpfs: watchedPresencaJob.total_cpfs,
        success_count: watchedPresencaJob.success_count,
        policy_declined_count: watchedPresencaJob.policy_declined_count,
        fail_count: watchedPresencaJob.fail_count,
        spool_bytes: watchedPresencaJob.spool_bytes ?? i.spool_bytes,
      };
    });
  }, [presencaItems, watchedPresencaJob]);

  const filteredPresencaItems = useMemo(() => {
    const q = searchValuePresenca.trim().toLowerCase();
    if (!q) return presencaItemsWithOverlay;
    return presencaItemsWithOverlay.filter((i) => i.title.toLowerCase().includes(q));
  }, [presencaItemsWithOverlay, searchValuePresenca]);

  useEffect(() => {
    if (!watchedJob) return;

    const niceTitle = watchedJob.title ?? titleOf(watchedJob.id);
    const isTerminal = ["concluido", "falhou", "cancelado"].includes(watchedJob.status);

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

      setWatchingJobId(null);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
    }
  }, [watchedJob, qc, setWatchingJobId]);

  useEffect(() => {
    if (!watchedV8Job) return;

    const niceTitle = watchedV8Job.title ?? `#${watchedV8Job.id}`;
    const isTerminal = ["concluido", "falhou", "cancelado"].includes(watchedV8Job.status);

    const prev = lastV8Snapshot.current;
    const changed =
      !prev ||
      prev.id !== watchedV8Job.id ||
      prev.status !== watchedV8Job.status;

    if (!changed) return;
    lastV8Snapshot.current = { id: watchedV8Job.id, status: watchedV8Job.status };

    if (isTerminal) {
      if (watchedV8Job.status === "concluido") toast.success(`Consulta "${niceTitle}" concluída.`);
      else if (watchedV8Job.status === "falhou") toast.error(`Consulta "${niceTitle}" falhou.`);
      else if (watchedV8Job.status === "cancelado") toast.info(`Consulta "${niceTitle}" cancelada.`);

      setWatchingV8JobId(null);
      void qc.invalidateQueries({ queryKey: ["v8:list"] });
    }
  }, [watchedV8Job, qc, setWatchingV8JobId]);

  useEffect(() => {
    if (!watchedPresencaJob) return;

    const niceTitle = watchedPresencaJob.title ?? `#${watchedPresencaJob.id}`;
    const isTerminal = ["concluido", "falhou", "cancelado"].includes(watchedPresencaJob.status);

    const prev = lastPresencaSnapshot.current;
    const changed =
      !prev ||
      prev.id !== watchedPresencaJob.id ||
      prev.status !== watchedPresencaJob.status;

    if (!changed) return;
    lastPresencaSnapshot.current = { id: watchedPresencaJob.id, status: watchedPresencaJob.status };

    if (isTerminal) {
      if (watchedPresencaJob.status === "concluido") toast.success(`Consulta "${niceTitle}" concluída.`);
      else if (watchedPresencaJob.status === "falhou") toast.error(`Consulta "${niceTitle}" falhou.`);
      else if (watchedPresencaJob.status === "cancelado") toast.info(`Consulta "${niceTitle}" cancelada.`);

      setWatchingPresencaJobId(null);
      void qc.invalidateQueries({ queryKey: ["presenca:list"] });
    }
  }, [watchedPresencaJob, qc, setWatchingPresencaJobId]);

  // Aceita payload estendido
  const createMutation = useMutation<any, any, any>({
    mutationFn: (vars: any) => createCltConsultJob(vars),
    onSuccess: (data, vars) => {
      setWatchingJobId(data.id);
      const t = (vars as any).title;
      toast.success(`Consulta "${t}" criada.`);
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
      toast.info(`Cancelamento solicitado para "${titleOf(id)}". A exclusão será liberada após finalizar a limpeza.`);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
      void qc.invalidateQueries({ queryKey: ["clt:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível cancelar"),
  });

  const rerunPhase2Mutation = useMutation({
    mutationFn: (id: number) => rerunCltConsultJobPhase2(id),
    onSuccess: (_data, id) => {
      setWatchingJobId(id);
      toast.success(`Reprocessamento da fase 2 iniciado para "${titleOf(id)}".`);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
      void qc.invalidateQueries({ queryKey: ["clt:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível reprocessar a fase 2"),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteCltConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingJobId) setWatchingJobId(null);
      toast.success(`Consulta "${titleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["clt:list"] });
      void qc.removeQueries({ queryKey: ["clt:job", id] });
    },
    onError: (e: any) => toast.error(deleteJobErrorMessage(e)),
  });

  const requestPreviewMutation = useMutation({
    mutationFn: (id: number) => requestCltPreview(id),
  });

  const createV8Mutation = useMutation<any, any, { title: string; lines: string }>({
    mutationFn: (vars) => createV8ConsultJob(vars),
    onSuccess: (data, vars) => {
      setWatchingV8JobId(data.id);
      toast.success(`Consulta "${vars.title}" criada.`);
      setPageV8(1);
      void qc.invalidateQueries({ queryKey: ["v8:list"] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Falha ao criar consulta"),
  });

  const cancelV8Mutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      cancelV8ConsultJob(id, reason),
    onSuccess: (_data, { id }) => {
      if (id === watchingV8JobId) setWatchingV8JobId(null);
      toast.info(`Consulta "${v8TitleOf(id)}" cancelada.`);
      void qc.invalidateQueries({ queryKey: ["v8:list"] });
      void qc.invalidateQueries({ queryKey: ["v8:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível cancelar"),
  });

  const deleteV8Mutation = useMutation({
    mutationFn: (id: number) => deleteV8ConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingV8JobId) setWatchingV8JobId(null);
      toast.success(`Consulta "${v8TitleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["v8:list"] });
      void qc.removeQueries({ queryKey: ["v8:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível excluir"),
  });

  const createPresencaMutation = useMutation<any, any, { title: string; lines: string }>({
    mutationFn: (vars) => createPresencaConsultJob(vars),
    onSuccess: (data, vars) => {
      setWatchingPresencaJobId(data.id);
      toast.success(`Consulta "${vars.title}" criada.`);
      setPagePresenca(1);
      void qc.invalidateQueries({ queryKey: ["presenca:list"] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Falha ao criar consulta"),
  });

  const cancelPresencaMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      cancelPresencaConsultJob(id, reason),
    onSuccess: (_data, { id }) => {
      if (id === watchingPresencaJobId) setWatchingPresencaJobId(null);
      toast.info(`Cancelamento solicitado para "${presencaTitleOf(id)}". A exclusão será liberada após finalizar a limpeza.`);
      void qc.invalidateQueries({ queryKey: ["presenca:list"] });
      void qc.invalidateQueries({ queryKey: ["presenca:job", id] });
    },
    onError: (e: any) => toast.error(e?.message ?? "Não foi possível cancelar"),
  });

  const deletePresencaMutation = useMutation({
    mutationFn: (id: number) => deletePresencaConsultJob(id),
    onSuccess: (_data, id) => {
      if (id === watchingPresencaJobId) setWatchingPresencaJobId(null);
      toast.success(`Consulta "${presencaTitleOf(id)}" excluída.`);
      void qc.invalidateQueries({ queryKey: ["presenca:list"] });
      void qc.removeQueries({ queryKey: ["presenca:job", id] });
    },
    onError: (e: any) => toast.error(deleteJobErrorMessage(e)),
  });

  // Mapear modo → variant esperado pelo backend
  const handleNewConsult = async (titulo: string, cpfs: string, modo: "OFF" | "ONLINE" | "HYBRID") => {
    const variant = modo === "OFF"
      ? "offline"
      : modo === "HYBRID"
        ? "hybrid"
        : "online";
    await createMutation.mutateAsync({ title: titulo, cpfs, variant });
  };

  const handleNewV8Consult = async (titulo: string, lines: string) => {
    await createV8Mutation.mutateAsync({ title: titulo, lines });
  };

  const handleNewPresencaConsult = async (titulo: string, lines: string) => {
    await createPresencaMutation.mutateAsync({ title: titulo, lines });
  };

  const getOrCreatePreviewToast = (id: number) => {
    const existing = previewToastById.current.get(id);
    if (existing) return existing;
    const stableId = `clt-prev-${id}`;
    toast.info("Gerando prévia…", {
      id: stableId,
      description: "Aguarde enquanto preparamos o XLSX.",
      duration: Infinity,
    });
    previewToastById.current.set(id, stableId);
    return stableId;
  };

  const handleDownload = async (id: number, opts?: { preview?: boolean }) => {
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

      if (!opts?.preview && j.has_file) {
        await downloadCltReport(id);
        inFlight.current.delete(id);
        return;
      }

      if (opts?.preview) {
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
        return;
      }

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

  const handleRerunPhase2 = async (id: number) => {
    await rerunPhase2Mutation.mutateAsync(id);
  };

  const handleDelete = async (id: number) => {
    await deleteMutation.mutateAsync(id);
  };

  const handleViewHttpCounters = (id: number) => {
    const item = itemsWithOverlay.find((j) => j.id === id);
    if (item?.variant === "offline") {
      toast.info("Contadores HTTP disponíveis apenas para consultas online e híbridas.");
      return;
    }

    setHttpCountersModalJob({
      id,
      title: item?.title ?? titleOf(id),
    });
  };

  const handleManualRefreshHttpCounters = () => {
    if (httpCountersLoading || httpCountersFetching) {
      return;
    }

    const now = Date.now();
    if (httpCountersRefreshCooldownUntil > now) {
      return;
    }

    setHttpCountersNowMs(now);
    setHttpCountersRefreshCooldownUntil(now + 3000);
    void refetchHttpCounters();
  };

  const httpCountersRefreshCooldownActive = httpCountersRefreshCooldownUntil > httpCountersNowMs;
  const httpCountersRefreshCooldownSeconds = httpCountersRefreshCooldownActive
    ? Math.max(1, Math.ceil((httpCountersRefreshCooldownUntil - httpCountersNowMs) / 1000))
    : 0;

  const handleDownloadV8 = async (id: number, opts?: { preview?: boolean }) => {
    if (v8InFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    v8InFlight.current.add(id);

    try {
      const j = await qc.ensureQueryData<V8ConsultJobShow>({
        queryKey: ["v8:job", id],
        queryFn: () => getV8ConsultJob(id),
      });

      if (!opts?.preview && j.has_file) {
        await downloadV8Report(id);
        v8InFlight.current.delete(id);
        return;
      }

      try {
        await downloadV8Preview(id);
      } catch (e: any) {
        if (e?.status === 409) {
          toast.info("Prévia indisponível ainda. Aguarde o início do processamento.");
        } else {
          const apiMsg = e?.response?.data?.message || e?.message;
          toast.error(apiMsg ?? "Falha ao baixar a prévia");
        }
      } finally {
        v8InFlight.current.delete(id);
        void qc.invalidateQueries({ queryKey: ["v8:job", id] });
      }
    } catch (e: any) {
      const apiMsg = e?.response?.data?.message || e?.message;
      toast.error(apiMsg ?? "Falha no download");
      v8InFlight.current.delete(id);
    }
  };

  const handleCancelV8 = async (id: number, reason?: string) => {
    await cancelV8Mutation.mutateAsync({ id, reason });
  };

  const handleDeleteV8 = async (id: number) => {
    await deleteV8Mutation.mutateAsync(id);
  };

  const handleDownloadPresenca = async (id: number, opts?: { preview?: boolean }) => {
    if (presencaInFlight.current.has(id)) {
      toast.warning("Já estamos gerando/baixando para este job.");
      return;
    }
    presencaInFlight.current.add(id);

    try {
      const j = await qc.ensureQueryData<PresencaConsultJobShow>({
        queryKey: ["presenca:job", id],
        queryFn: () => getPresencaConsultJob(id),
      });

      if (!opts?.preview && j.has_file) {
        await downloadPresencaReport(id);
        presencaInFlight.current.delete(id);
        return;
      }

      try {
        await downloadPresencaPreview(id);
      } catch (e: any) {
        if (e?.status === 409) {
          toast.info("Prévia indisponível ainda. Aguarde o início do processamento.");
        } else {
          const apiMsg = e?.response?.data?.message || e?.message;
          toast.error(apiMsg ?? "Falha ao baixar a prévia");
        }
      } finally {
        presencaInFlight.current.delete(id);
        void qc.invalidateQueries({ queryKey: ["presenca:job", id] });
      }
    } catch (e: any) {
      const apiMsg = e?.response?.data?.message || e?.message;
      toast.error(apiMsg ?? "Falha no download");
      presencaInFlight.current.delete(id);
    }
  };

  const handleCancelPresenca = async (id: number, reason?: string) => {
    await cancelPresencaMutation.mutateAsync({ id, reason });
  };

  const handleDeletePresenca = async (id: number) => {
    await deletePresencaMutation.mutateAsync(id);
  };

  const isV8Tab = activeTab === "v8";
  const isPresencaTab = activeTab === "presenca";
  const headerTitle = isV8Tab
    ? "Consulta CLT (V8)"
    : isPresencaTab
      ? "Consulta CLT (Presença)"
      : "Consulta CLT (FACTA)";
  const headerDescription = isV8Tab
    ? "Envie CPF, nome e data de nascimento em massa e baixe o resultado em CSV."
    : isPresencaTab
      ? "Envie CPF e nome em massa para consulta Presença e baixe o resultado em CSV."
      : "Realize consultas CLT em massa colando CPFs e baixe o resultado em Excel.";

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          {headerTitle}
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          {headerDescription}
        </p>
      </div>

      <Tabs
        value={activeTab}
        onValueChange={(val) => setActiveTab(val as "facta" | "v8" | "presenca")}
        className="space-y-6"
      >
        <TabsList className="flex w-fit h-auto p-1 bg-muted/50 rounded-lg justify-start">
          <TabsTrigger
            value="facta"
            className="px-6 py-2 rounded-md text-sm font-medium transition-all duration-200 data-[state=active]:bg-background data-[state=active]:text-foreground text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          >
            <span className="inline-flex items-center gap-2">
              <img
                src={factaLogo}
                alt="Facta"
                className="h-4 w-4 object-contain"
              />
              Facta
            </span>
          </TabsTrigger>
          <TabsTrigger
            value="v8"
            className="px-6 py-2 rounded-md text-sm font-medium transition-all duration-200 data-[state=active]:bg-background data-[state=active]:text-foreground text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          >
            <span className="inline-flex items-center gap-2">
              <img
                src={v8Logo}
                alt="V8"
                className="h-4 w-4 object-contain"
              />
              V8
            </span>
          </TabsTrigger>
          <TabsTrigger
            value="presenca"
            className="px-6 py-2 rounded-md text-sm font-medium transition-all duration-200 data-[state=active]:bg-background data-[state=active]:text-foreground text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          >
            <span className="inline-flex items-center gap-2">
              <img
                src={pbankLogo}
                alt="Presença"
                className="h-4 w-4 object-contain"
              />
              Presença
            </span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="facta" className="space-y-6">
          <CLTControls
            onNewConsultClick={() => setIsNewConsultModalOpen(true)}
            searchValue={searchValue}
            onSearchChange={setSearchValue}
            statusFilter={statusFilter}
            onStatusFilterChange={(value) => {
              setStatusFilter(value);
              setPage(1);
            }}
            variantFilter={variantFilter}
            onVariantFilterChange={(value) => {
              setVariantFilter(value);
              setPage(1);
            }}
          />

          <CLTHistoryTable
            items={filteredItems}
            loading={!!(listLoading && !jobsPage)}
            onDownload={handleDownload}
            onCancel={handleCancel}
            onRerunPhase2={handleRerunPhase2}
            onDelete={handleDelete}
            onViewHttpCounters={handleViewHttpCounters}
            onRefresh={() => refetchList()}
            page={page}
            lastPage={lastPage}
            onPageChange={(p) => setPage(p)}
            formatDateTimeBR={formatDateTimeBR}
          />
        </TabsContent>

        <TabsContent value="v8" className="space-y-6">
          <V8Controls
            onNewConsultClick={() => setIsNewV8ModalOpen(true)}
            searchValue={searchValueV8}
            onSearchChange={setSearchValueV8}
          />

          <V8HistoryTable
            items={filteredV8Items}
            loading={!!(v8ListLoading && !v8JobsPage)}
            onDownload={handleDownloadV8}
            onCancel={handleCancelV8}
            onDelete={handleDeleteV8}
            onRefresh={() => refetchV8List()}
            page={pageV8}
            lastPage={v8LastPage}
            onPageChange={(p) => setPageV8(p)}
            formatDateTimeBR={formatDateTimeBR}
          />
        </TabsContent>

        <TabsContent value="presenca" className="space-y-6">
          <V8Controls
            onNewConsultClick={() => setIsNewPresencaModalOpen(true)}
            searchValue={searchValuePresenca}
            onSearchChange={setSearchValuePresenca}
          />

          <PresencaHistoryTable
            items={filteredPresencaItems}
            loading={!!(presencaListLoading && !presencaJobsPage)}
            onDownload={handleDownloadPresenca}
            onCancel={handleCancelPresenca}
            onDelete={handleDeletePresenca}
            onRefresh={() => refetchPresencaList()}
            page={pagePresenca}
            lastPage={presencaLastPage}
            onPageChange={(p) => setPagePresenca(p)}
            formatDateTimeBR={formatDateTimeBR}
          />
        </TabsContent>
      </Tabs>

      <NewCLTConsultModal
        isOpen={isNewConsultModalOpen}
        onClose={() => setIsNewConsultModalOpen(false)}
        onSubmit={handleNewConsult}
      />

      <Dialog
        open={!!httpCountersModalJob}
        onOpenChange={(open) => {
          if (!open) {
            setHttpCountersModalJob(null);
            setHttpCountersRefreshCooldownUntil(0);
            setHttpCountersNowMs(Date.now());
          }
        }}
      >
        <DialogContent className="max-w-5xl">
          <DialogHeader>
            <DialogTitle>
              Chamadas da API por job
            </DialogTitle>
          </DialogHeader>

          <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <div>
                <p className="font-medium text-foreground">
                  {httpCountersModalJob?.title ?? "-"} (#{httpCountersModalJob?.id ?? "-"})
                </p>
                <p className="text-muted-foreground">
                  {httpCountersData?.updated_at
                    ? `Atualizado em ${formatDateTimeBR(httpCountersData.updated_at)}`
                    : "Sem atualização ainda"}
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={handleManualRefreshHttpCounters}
                disabled={httpCountersLoading || httpCountersFetching || httpCountersRefreshCooldownActive}
              >
                {httpCountersFetching
                  ? "Atualizando..."
                  : httpCountersRefreshCooldownActive
                    ? `Aguardar ${httpCountersRefreshCooldownSeconds}s`
                    : "Atualizar"}
              </Button>
            </div>

            {httpCountersLoading ? (
              <div className="flex items-center justify-center py-10 text-muted-foreground">
                Carregando contagens...
              </div>
            ) : httpCountersError ? (
              <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                Falha ao carregar contagens da API para este job.
              </div>
            ) : !httpCountersData || httpCountersData.available === false ? (
              <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Contadores HTTP não disponíveis neste ambiente.
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                  <div className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">Requests</p>
                    <p className="text-lg font-semibold">{(httpCountersData.summary.request_count ?? 0).toLocaleString("pt-BR")}</p>
                  </div>
                  <div className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">Respostas</p>
                    <p className="text-lg font-semibold">{(httpCountersData.summary.response_count ?? 0).toLocaleString("pt-BR")}</p>
                  </div>
                  <div className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">Sem resposta</p>
                    <p className="text-lg font-semibold">{(httpCountersData.summary.no_response_count ?? 0).toLocaleString("pt-BR")}</p>
                  </div>
                  <div className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">Timeouts</p>
                    <p className="text-lg font-semibold">{(httpCountersData.summary.timeout_count ?? 0).toLocaleString("pt-BR")}</p>
                  </div>
                  <div className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">Conexão</p>
                    <p className="text-lg font-semibold">{(httpCountersData.summary.connection_exception_count ?? 0).toLocaleString("pt-BR")}</p>
                  </div>
                </div>

                <div className="text-xs text-muted-foreground">
                  <span className={httpCountersData.checks.request_balance_ok ? "text-emerald-700 dark:text-emerald-400" : "text-red-700 dark:text-red-400"}>
                    request = response + no_response: {httpCountersData.checks.request_balance_ok ? "OK" : "INCONSISTENTE"}
                  </span>
                  {" • "}
                  <span className={httpCountersData.checks.status_balance_ok ? "text-emerald-700 dark:text-emerald-400" : "text-red-700 dark:text-red-400"}>
                    response = 2xx+4xx+5xx+other: {httpCountersData.checks.status_balance_ok ? "OK" : "INCONSISTENTE"}
                  </span>
                </div>

                <div className="max-h-[48vh] overflow-auto rounded-md border">
                  <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-muted/80 backdrop-blur">
                      <tr className="text-left">
                        <th className="px-3 py-2">Endpoint</th>
                        <th className="px-3 py-2">Req</th>
                        <th className="px-3 py-2">Resp</th>
                        <th className="px-3 py-2">2xx</th>
                        <th className="px-3 py-2">4xx</th>
                        <th className="px-3 py-2">5xx</th>
                        <th className="px-3 py-2">Sem Resp</th>
                        <th className="px-3 py-2">Timeout</th>
                        <th className="px-3 py-2">Conexão</th>
                      </tr>
                    </thead>
                    <tbody>
                      {httpCountersData.endpoints.length === 0 ? (
                        <tr>
                          <td colSpan={9} className="px-3 py-6 text-center text-muted-foreground">
                            Sem chamadas contabilizadas neste job.
                          </td>
                        </tr>
                      ) : (
                        httpCountersData.endpoints.map((row) => (
                          <tr key={row.endpoint} className="border-t">
                            <td className="px-3 py-2 font-mono text-xs">{row.endpoint}</td>
                            <td className="px-3 py-2">{row.request_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.response_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.status_2xx_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.status_4xx_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.status_5xx_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.no_response_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.timeout_count.toLocaleString("pt-BR")}</td>
                            <td className="px-3 py-2">{row.connection_exception_count.toLocaleString("pt-BR")}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <NewV8ConsultModal
        isOpen={isNewV8ModalOpen}
        onClose={() => setIsNewV8ModalOpen(false)}
        onSubmit={handleNewV8Consult}
      />

      <NewPresencaConsultModal
        isOpen={isNewPresencaModalOpen}
        onClose={() => setIsNewPresencaModalOpen(false)}
        onSubmit={handleNewPresencaConsult}
      />
    </div>
  );
};

export default CLTConsultaPage;
