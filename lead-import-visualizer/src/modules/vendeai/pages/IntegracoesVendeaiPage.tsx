import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { format, parseISO, subHours } from "date-fns";
import { ptBR } from "date-fns/locale";
import { AlertCircle, Loader2, RefreshCw, XCircle } from "lucide-react";
import { toast } from "sonner";

import {
  downloadVendeaiExport,
  getVendeaiExportStatus,
  getVendeaiMetrics,
  listVendeaiAttempts,
  listVendeaiLeads,
  startVendeaiExport,
  type VendeaiExportType,
  type VendeaiMetricBucket,
  type VendeaiSortDirection,
} from "@/api/vendeai";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { VendeaiControls } from "../components/VendeaiControls";
import { VendeaiFiltersModal } from "../components/VendeaiFiltersModal";
import { formatCPF, formatPhone } from "@/lib/formatters";

type FiltersState = {
  from: string;
  to: string;
  direction: VendeaiSortDirection;
  windowMode: "rolling" | "fixed";
};

type ActiveTable = "leads" | "attempts";

const STORAGE_KEY = "vendeai:integracoes:filters:v1";
const AUTO_REFRESH_MS = 60_000;
const MANUAL_REFRESH_COOLDOWN_MS = 10_000;
const brMoney = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });

const directionOptions: Array<{ value: VendeaiSortDirection; label: string }> = [
  { value: "desc", label: "Mais recentes" },
  { value: "asc", label: "Mais antigas" },
];

const windowModeOptions: Array<{ value: FiltersState["windowMode"]; label: string }> = [
  { value: "rolling", label: "Janela móvel" },
  { value: "fixed", label: "Intervalo fixo" },
];

function toDateTimeLocalValue(date: Date): string {
  const offsetMs = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offsetMs).toISOString().slice(0, 16);
}

function toUtcIso(value: string): string | undefined {
  if (!value) return undefined;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString();
}

function defaultFilters(): FiltersState {
  return {
    from: toDateTimeLocalValue(subHours(new Date(), 24)),
    to: toDateTimeLocalValue(new Date()),
    direction: "desc",
    windowMode: "rolling",
  };
}

function isValidDateTimeLocal(value: unknown): value is string {
  if (typeof value !== "string" || value.trim() === "") return false;
  const parsed = new Date(value);
  return !Number.isNaN(parsed.getTime());
}

function rollRangeToNow(fromValue: string, toValue: string): { from: string; to: string } {
  const fromDate = new Date(fromValue);
  const toDate = new Date(toValue);

  if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime()) || fromDate > toDate) {
    const now = new Date();
    return {
      from: toDateTimeLocalValue(subHours(now, 24)),
      to: toDateTimeLocalValue(now),
    };
  }

  const durationMs = Math.max(60_000, toDate.getTime() - fromDate.getTime());
  const now = new Date();
  const nextFrom = new Date(now.getTime() - durationMs);

  return {
    from: toDateTimeLocalValue(nextFrom),
    to: toDateTimeLocalValue(now),
  };
}

function loadFilters(): FiltersState {
  const fallback = defaultFilters();
  if (typeof window === "undefined") return fallback;

  try {
    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "{}") as Partial<FiltersState>;
    const from = isValidDateTimeLocal(parsed.from) ? parsed.from : fallback.from;
    const to = isValidDateTimeLocal(parsed.to) ? parsed.to : fallback.to;
    const windowMode = parsed.windowMode === "fixed" || parsed.windowMode === "rolling" ? parsed.windowMode : fallback.windowMode;

    if (windowMode === "rolling") {
      const rolled = rollRangeToNow(from, to);
      return {
        from: rolled.from,
        to: rolled.to,
        direction: parsed.direction === "asc" || parsed.direction === "desc" ? parsed.direction : fallback.direction,
        windowMode,
      };
    }

    return {
      from,
      to,
      direction: parsed.direction === "asc" || parsed.direction === "desc" ? parsed.direction : fallback.direction,
      windowMode,
    };
  } catch {
    return fallback;
  }
}

function formatDateTime(value: string | null): string {
  if (!value) return "-";

  try {
    return format(parseISO(value), "dd/MM/yyyy HH:mm:ss", { locale: ptBR });
  } catch {
    return "-";
  }
}

function formatDate(value: string | null): string {
  if (!value) return "-";

  try {
    return format(parseISO(value), "dd/MM/yyyy", { locale: ptBR });
  } catch {
    return "-";
  }
}

function formatNumber(value: number | null | undefined): string {
  return new Intl.NumberFormat("pt-BR").format(value ?? 0);
}

function formatCurrency(value: string | null): string {
  const number = Number(value);
  return Number.isFinite(number) ? brMoney.format(number) : "-";
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function pollDelay(attempt: number): number {
  if (attempt < 10) return 2000;
  if (attempt < 30) return 3000;
  return 5000;
}

function errorMessage(error: unknown): string {
  if (typeof error === "object" && error !== null) {
    const record = error as { response?: { data?: { message?: string } }; message?: string };
    return record.response?.data?.message || record.message || "Não foi possível concluir a ação.";
  }

  return "Não foi possível concluir a ação.";
}

function MetricCard({
  label,
  value,
  detail,
  tone = "default",
}: {
  label: string;
  value: string;
  detail?: string;
  tone?: "default" | "blue" | "green" | "rose";
}) {
  const toneClasses = {
    default: "border-gray-200 bg-white",
    blue: "border-blue-100 bg-blue-50/40",
    green: "border-emerald-100 bg-emerald-50/40",
    rose: "border-rose-100 bg-rose-50/40",
  };

  return (
    <Card className={`shadow-sm ${toneClasses[tone]}`}>
      <CardContent className="p-4">
        <p className="text-sm text-gray-600">{label}</p>
        <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
        {detail && <p className="mt-1 text-xs text-gray-500">{detail}</p>}
      </CardContent>
    </Card>
  );
}

function productLabel(label: string): string {
  const normalized = label.toLowerCase();

  if (normalized === "clt") return "Crédito do Trabalhador";
  if (normalized === "fgts") return "FGTS";
  if (normalized === "sem_valor") return "Não informado";

  return label;
}

function bankLabel(label: string): string {
  const normalized = label.toLowerCase();

  if (normalized === "mercantil") return "Mercantil";
  if (normalized === "presenca" || normalized === "presença") return "Presença Bank";
  if (normalized === "facta") return "FACTA";
  if (normalized === "v8") return "V8";
  if (normalized === "pan") return "Banco PAN";
  if (normalized === "c6") return "C6 Bank";
  if (normalized === "novo_saque" || normalized === "novo saque") return "Novo Saque";
  if (normalized === "sem_valor") return "Não informado";

  return label;
}

function stageLabel(label: string | null): string {
  if (!label) return "-";

  const normalized = label.toLowerCase().trim();

  if (normalized === "get_cpf") return "Coleta de CPF";
  if (normalized === "send_authorization") return "Envio de autorização";
  if (normalized === "vendedor") return "Vendedor";
  if (normalized === "oferta") return "Oferta";
  if (normalized === "cross_sell") return "Cross sell";
  if (normalized === "_cross_sell") return "Cross sell";
  if (normalized === "get_sim_data") return "Coleta de dados da simulação";
  if (normalized === "first_message") return "Primeira mensagem";
  if (normalized === "simulation") return "Simulação";
  if (normalized === "simulation_rejected") return "Simulação rejeitada";
  if (normalized === "option") return "Escolha de opção";
  if (normalized === "negotiator") return "Negociador";
  if (normalized === "digitador") return "Digitador";
  if (normalized === "teimosinha") return "Teimosinha";
  if (normalized === "proposal_sent") return "Proposta enviada";
  if (normalized === "proposal_signed") return "Proposta assinada";
  if (normalized === "proposal_created") return "Proposta criada";
  if (normalized === "resolvable_error") return "Erro tratável";
  if (normalized === "unresolvable_error") return "Erro não tratável";
  if (normalized === "stage_updated") return "Etapa atualizada";
  if (normalized === "tag_updated") return "Tags atualizadas";

  return label.replace(/_/g, " ");
}

function ProductList({ title, items }: { title: string; items: VendeaiMetricBucket[] }) {
  return (
    <Card className="border border-blue-100 bg-blue-50/40 shadow-sm">
      <CardContent className="p-4">
        <p className="text-sm font-medium text-blue-900">{title}</p>
        <div className="mt-3 space-y-2">
          {items.length === 0 ? (
            <p className="text-sm text-gray-500">Sem dados no período.</p>
          ) : (
            items.map((item) => (
              <div key={item.label} className="flex items-center justify-between gap-3 text-sm">
                <span className="min-w-0 truncate text-gray-600">{productLabel(item.label)}</span>
                <span className="font-medium text-gray-900">{formatNumber(item.total)}</span>
              </div>
            ))
          )}
        </div>
      </CardContent>
    </Card>
  );
}

const IntegracoesVendeaiPage = () => {
  const initial = useMemo(() => loadFilters(), []);
  const [fromInput, setFromInput] = useState(initial.from);
  const [toInput, setToInput] = useState(initial.to);
  const [directionInput, setDirectionInput] = useState<VendeaiSortDirection>(initial.direction);
  const [windowModeInput, setWindowModeInput] = useState<FiltersState["windowMode"]>(initial.windowMode);
  const [activeTable, setActiveTable] = useState<ActiveTable>("leads");
  const [applied, setApplied] = useState<FiltersState>(initial);
  const [leadsPage, setLeadsPage] = useState(1);
  const [attemptsPage, setAttemptsPage] = useState(1);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<VendeaiExportType | null>(null);
  const [manualRefreshLockedUntil, setManualRefreshLockedUntil] = useState(0);
  const [nowTs, setNowTs] = useState(() => Date.now());
  const [isFiltersModalOpen, setIsFiltersModalOpen] = useState(false);

  const fromIso = useMemo(() => toUtcIso(applied.from), [applied.from]);
  const toIso = useMemo(() => toUtcIso(applied.to), [applied.to]);
  const manualRefreshRemaining = Math.max(0, Math.ceil((manualRefreshLockedUntil - nowTs) / 1000));

  useEffect(() => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(applied));
  }, [applied]);

  useEffect(() => {
    const timer = window.setInterval(() => {
      setNowTs(Date.now());
    }, 1000);

    return () => window.clearInterval(timer);
  }, []);

  const metricsQuery = useQuery({
    queryKey: ["vendeai:metrics", fromIso, toIso],
    queryFn: ({ signal }) => getVendeaiMetrics({ from: fromIso, to: toIso }, signal),
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: AUTO_REFRESH_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  });

  const attemptsQuery = useQuery({
    queryKey: ["vendeai:attempts", attemptsPage, fromIso, toIso, applied.direction],
    queryFn: ({ signal }) =>
      listVendeaiAttempts(
        {
          page: attemptsPage,
          perPage: 20,
          from: fromIso,
          to: toIso,
          direction: applied.direction,
          sort: "received_at",
        },
        signal
      ),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: AUTO_REFRESH_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  });

  const leadsQuery = useQuery({
    queryKey: ["vendeai:leads", leadsPage, fromIso, toIso, applied.direction],
    queryFn: ({ signal }) =>
      listVendeaiLeads(
        {
          page: leadsPage,
          perPage: 20,
          from: fromIso,
          to: toIso,
          direction: applied.direction,
          sort: "first_received_at",
        },
        signal
      ),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: AUTO_REFRESH_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  });

  const metrics = metricsQuery.data;
  const leads = leadsQuery.data?.data ?? [];
  const currentLeadsPage = leadsQuery.data?.current_page ?? leadsPage;
  const lastLeadsPage = leadsQuery.data?.last_page ?? 1;
  const totalLeads = leadsQuery.data?.total ?? 0;
  const attempts = attemptsQuery.data?.data ?? [];
  const currentAttemptsPage = attemptsQuery.data?.current_page ?? attemptsPage;
  const lastAttemptsPage = attemptsQuery.data?.last_page ?? 1;
  const totalAttempts = attemptsQuery.data?.total ?? 0;
  const activeCount = activeTable === "leads" ? totalLeads : totalAttempts;
  const activeModeLabel = activeTable === "leads" ? "Leads VendeAI" : "Propostas NewCorban";
  const activeCountLabel = activeTable === "leads" ? "leads encontrados" : "propostas encontradas";
  const activeExportType: VendeaiExportType = activeTable === "leads" ? "leads" : "newcorban-proposal-attempts";
  const activeExportLabel = activeTable === "leads" ? "CSV leads VendeAI" : "CSV propostas NewCorban";
  const activeExportIcon = activeTable === "leads" ? "file" : "download";
  const appliedLabels = [
    `Modo: ${windowModeInput === "rolling" ? "Janela móvel" : "Intervalo fixo"}`,
    `De ${formatDateTime(fromIso ?? applied.from)}`,
    `Até ${formatDateTime(toIso ?? applied.to)}`,
  ];

  const applyFilters = (): boolean => {
    const fromDate = new Date(fromInput);
    const toDate = new Date(toInput);

    if (!fromInput || !toInput || Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
      setRangeError("Preencha um intervalo válido com data e hora.");
      return false;
    }

    if (fromDate.getTime() > toDate.getTime()) {
      setRangeError("A data/hora inicial não pode ser maior que a final.");
      return false;
    }

    setRangeError(null);

    let nextFrom = fromInput;
    let nextTo = toInput;

    if (windowModeInput === "rolling") {
      const rolled = rollRangeToNow(fromInput, toInput);
      nextFrom = rolled.from;
      nextTo = rolled.to;
      setFromInput(nextFrom);
      setToInput(nextTo);
    }

    setApplied({
      from: nextFrom,
      to: nextTo,
      direction: directionInput,
      windowMode: windowModeInput,
    });
    setLeadsPage(1);
    setAttemptsPage(1);
    return true;
  };

  const handleApplyFilters = () => {
    if (applyFilters()) {
      setIsFiltersModalOpen(false);
    }
  };

  const resetFilterInputs = () => {
    const next = defaultFilters();
    setFromInput(next.from);
    setToInput(next.to);
    setWindowModeInput(next.windowMode);
    setDirectionInput(next.direction);
    setRangeError(null);
  };

  const clearFilters = () => {
    const next = defaultFilters();
    setFromInput(next.from);
    setToInput(next.to);
    setWindowModeInput(next.windowMode);
    setDirectionInput(next.direction);
    setRangeError(null);
    setApplied(next);
    setLeadsPage(1);
    setAttemptsPage(1);
  };

  const handleManualRefresh = () => {
    if (metricsQuery.isFetching || attemptsQuery.isFetching || leadsQuery.isFetching || manualRefreshRemaining > 0) {
      return;
    }

    setManualRefreshLockedUntil(Date.now() + MANUAL_REFRESH_COOLDOWN_MS);
    void metricsQuery.refetch();
    void leadsQuery.refetch();
    void attemptsQuery.refetch();
  };

  const exportCsv = async (type: VendeaiExportType) => {
    if (exporting) return;

    setExporting(type);
    const toastId = toast.loading("Gerando CSV VendeAI...", { duration: Infinity });

    try {
      const { token } = await startVendeaiExport(type, {
        from: fromIso,
        to: toIso,
        direction: applied.direction,
      });

      for (let attempt = 0; attempt < 180; attempt += 1) {
        const status = await getVendeaiExportStatus(token);

        if (status.status === "ready") {
          toast.success("CSV pronto. Baixando...", { id: toastId });
          await downloadVendeaiExport(token);
          toast.dismiss(toastId);
          return;
        }

        if (status.status === "error") {
          throw new Error(status.error || status.message || "Falha ao gerar CSV.");
        }

        if (status.status === "deleted") {
          throw new Error(status.message || "Export expirou antes do download.");
        }

        await sleep(pollDelay(attempt));
      }

      throw new Error("O export demorou além do esperado.");
    } catch (error) {
      toast.error(errorMessage(error), { id: toastId });
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0 flex flex-col gap-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-1">Integração VendeAI</h1>
          <p className="text-gray-600 text-sm lg:text-base">
            Métricas de conversas e propostas na New Corban.
          </p>
        </div>

        <div className="flex w-full flex-col gap-3 md:w-auto md:items-end">
          <label className="flex w-full max-w-xs flex-col gap-1 text-sm font-medium text-gray-700">
            Dados da tabela
            <select
              value={activeTable}
              onChange={(event) => {
                setActiveTable(event.target.value as ActiveTable);
                setLeadsPage(1);
                setAttemptsPage(1);
              }}
              className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="leads">Leads VendeAI</option>
              <option value="attempts">Propostas NewCorban</option>
            </select>
          </label>
        </div>
      </div>

      <VendeaiControls
        modeLabel={activeModeLabel}
        filteredCount={activeCount}
        countLabel={activeCountLabel}
        exportLabel={activeExportLabel}
        exportLoading={exporting === activeExportType}
        exportIcon={activeExportIcon}
        isRefreshing={metricsQuery.isFetching || attemptsQuery.isFetching || leadsQuery.isFetching}
        refreshCountdown={manualRefreshRemaining}
        activeLabels={appliedLabels}
        hasActiveFilters
        onFilterClick={() => setIsFiltersModalOpen(true)}
        onExportClick={() => void exportCsv(activeExportType)}
        onRefreshClick={handleManualRefresh}
        onClearFilters={clearFilters}
      />

      <VendeaiFiltersModal
        isOpen={isFiltersModalOpen}
        title={`Filtros de ${activeModeLabel}`}
        subtitle="Ajuste o período e aplique na visualização atual."
        from={fromInput}
        to={toInput}
        windowMode={windowModeInput}
        windowModeOptions={windowModeOptions}
        rangeError={rangeError}
        onClose={() => setIsFiltersModalOpen(false)}
        onFromChange={setFromInput}
        onToChange={setToInput}
        onWindowModeChange={setWindowModeInput}
        onReset={resetFilterInputs}
        onApply={handleApplyFilters}
      />

      {metricsQuery.isLoading ? (
        <Card className="border-dashed">
          <CardContent className="py-10 flex items-center justify-center text-gray-500">
            <Loader2 className="w-5 h-5 animate-spin mr-2" />
            Carregando métricas...
          </CardContent>
        </Card>
      ) : metricsQuery.isError ? (
        <Card className="border-red-100 bg-red-50/50">
          <CardContent className="py-10 text-center text-red-700">Falha ao carregar métricas.</CardContent>
        </Card>
      ) : (
        <>
          <div className="space-y-3">
            {activeTable === "leads" ? (
              <div>
                <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Leads VendeAI</h2>
                <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                  <MetricCard label="Conversas com a IA" value={formatNumber(metrics?.leads.total)} tone="blue" />
                  <ProductList title="Conversas por produto" items={metrics?.leads.by_product ?? []} />
                </div>
              </div>
            ) : (
              <div>
                <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Criação de propostas na New Corban</h2>
                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                  <MetricCard label="Propostas enviadas" value={formatNumber(metrics?.attempts.total)} tone="blue" />
                  <MetricCard label="Criadas na New Corban" value={formatNumber(metrics?.attempts.success)} tone="green" />
                  <MetricCard label="Falhas" value={formatNumber(metrics?.attempts.failed)} tone="rose" />
                  <ProductList title="Propostas por produto" items={metrics?.attempts.by_product ?? []} />
                </div>
              </div>
            )}
          </div>
        </>
      )}

      <div>
        <div className="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold text-foreground">
              {activeTable === "leads" ? "Leads VendeAI" : "Propostas NewCorban"}
            </h2>
            <p className="text-muted-foreground text-sm">
              {activeTable === "leads"
                ? `${leads.length} nesta página • ${formatNumber(totalLeads)} no total`
                : `${attempts.length} nesta página • ${formatNumber(totalAttempts)} no total`}
            </p>
          </div>
          <div className="text-sm text-gray-500 flex items-center gap-2">
            {activeTable === "leads"
              ? leadsQuery.isFetching
                ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                : <span className="w-2 h-2 rounded-full bg-emerald-500" />
              : attemptsQuery.isFetching
                ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                : <span className="w-2 h-2 rounded-full bg-emerald-500" />}
            {activeTable === "leads"
              ? leadsQuery.isFetching ? "Atualizando..." : "Atualiza automaticamente a cada 60s"
              : attemptsQuery.isFetching ? "Atualizando..." : "Atualiza automaticamente a cada 60s"}
          </div>
        </div>

        <Card className="overflow-hidden border border-gray-200 shadow-sm">
          <div className="overflow-x-auto">
            {activeTable === "leads" ? (
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium">CPF</th>
                    <th className="px-4 py-3 text-left font-medium">Nome</th>
                    <th className="px-4 py-3 text-left font-medium">Nascimento</th>
                    <th className="px-4 py-3 text-left font-medium">Telefone</th>
                    <th className="px-4 py-3 text-left font-medium">Chat</th>
                    <th className="px-4 py-3 text-left font-medium">Produto</th>
                    <th className="px-4 py-3 text-left font-medium">Etapa</th>
                    <th className="px-4 py-3 text-left font-medium">Tags</th>
                    <th className="px-4 py-3 text-left font-medium">Proposta VendeAI</th>
                    <th className="px-4 py-3 text-left font-medium">Banco</th>
                    <th className="px-4 py-3 text-left font-medium">Valor</th>
                    <th className="px-4 py-3 text-left font-medium">Eventos</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {leadsQuery.isLoading ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-gray-500">
                        <Loader2 className="mx-auto mb-2 h-5 w-5 animate-spin" />
                        Carregando leads...
                      </td>
                    </tr>
                  ) : leadsQuery.isError ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-red-600">Falha ao carregar leads.</td>
                    </tr>
                  ) : leads.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-gray-500">Nenhum lead no período.</td>
                    </tr>
                  ) : (
                    leads.map((lead) => (
                      <tr key={lead.id} className="align-top hover:bg-gray-50 transition-colors duration-150">
                        <td className="px-4 py-3 font-medium text-gray-900">{formatCPF(lead.customer_cpf)}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{lead.customer_name || "-"}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{formatDate(lead.customer_birth_date)}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{formatPhone(lead.customer_phone)}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{lead.chat_id || "-"}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{productLabel(lead.proposal_product || lead.chat_product || "-")}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{stageLabel(lead.stage)}</td>
                        <td className="px-4 py-3">
                          <div className="flex max-w-[240px] flex-wrap gap-1">
                            {lead.tags?.length ? (
                              lead.tags.slice(0, 4).map((tag) => (
                                <span key={tag} className="inline-flex rounded-md border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-800">
                                  {tag}
                                </span>
                              ))
                            ) : (
                              <span className="text-gray-400">-</span>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{lead.proposal_id || "-"}</div>
                          <div className="text-xs text-gray-500">{lead.proposal_status || "-"}</div>
                        </td>
                        <td className="px-4 py-3 font-medium text-gray-900">{bankLabel(lead.proposal_bank || "-")}</td>
                        <td className="px-4 py-3 font-medium text-gray-900">{formatCurrency(lead.proposal_liquid_value)}</td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-blue-700">{formatDateTime(lead.first_received_at)}</div>
                          <div className="text-xs text-gray-500">{formatDateTime(lead.last_received_at)}</div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium">Proposta</th>
                    <th className="px-4 py-3 text-left font-medium">CPF</th>
                    <th className="px-4 py-3 text-left font-medium">Nome</th>
                    <th className="px-4 py-3 text-left font-medium">Nascimento</th>
                    <th className="px-4 py-3 text-left font-medium">Telefone</th>
                    <th className="px-4 py-3 text-left font-medium">Chat</th>
                    <th className="px-4 py-3 text-left font-medium">Proposta New Corban</th>
                    <th className="px-4 py-3 text-left font-medium">Banco</th>
                    <th className="px-4 py-3 text-left font-medium">Produto</th>
                    <th className="px-4 py-3 text-left font-medium">Valor</th>
                    <th className="px-4 py-3 text-left font-medium">Proposta VendeAI</th>
                    <th className="px-4 py-3 text-left font-medium">Erro</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {attemptsQuery.isLoading ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-gray-500">
                        <Loader2 className="mx-auto mb-2 h-5 w-5 animate-spin" />
                        Carregando propostas...
                      </td>
                    </tr>
                  ) : attemptsQuery.isError ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-red-600">Falha ao carregar propostas.</td>
                    </tr>
                  ) : attempts.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-gray-500">Nenhuma proposta no período.</td>
                    </tr>
                  ) : (
                    attempts.map((attempt) => (
                      <tr key={attempt.id} className="align-top hover:bg-gray-50 transition-colors duration-150">
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">#{attempt.id}</div>
                          <div className="text-xs text-blue-700">{formatDateTime(attempt.received_at)}</div>
                          <Badge
                            variant="outline"
                            className={
                              attempt.status === "success"
                                ? "mt-2 border-emerald-200 bg-emerald-50 text-emerald-700"
                                : attempt.status === "failed"
                                  ? "mt-2 border-red-200 bg-red-50 text-red-700"
                                  : "mt-2 border-amber-200 bg-amber-50 text-amber-700"
                            }
                          >
                            {attempt.status === "success" ? "sucesso" : attempt.status === "failed" ? "falha" : "não enviada"}
                          </Badge>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{formatCPF(attempt.lead.customer_cpf)}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{attempt.lead.customer_name || "-"}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{formatDate(attempt.lead.customer_birth_date)}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{formatPhone(attempt.lead.customer_phone)}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{attempt.lead.chat_id || "-"}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{attempt.newcorban_proposta_id || "Não criada"}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{bankLabel(attempt.proposal.bank || "-")}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{productLabel(attempt.proposal.product || "-")}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{formatCurrency(attempt.proposal.liquid_value)}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-medium text-gray-900">{attempt.proposal.proposal_id || "-"}</div>
                          <div className="text-xs text-gray-500">{attempt.proposal.status || "-"}</div>
                        </td>
                        <td className="px-4 py-3 max-w-[360px]">
                          {attempt.newcorban_error ? (
                            <div className="flex gap-2 text-red-700">
                              <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                              <span className="line-clamp-3">{attempt.newcorban_error}</span>
                            </div>
                          ) : (
                            <span className="text-gray-400">-</span>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            )}
          </div>
        </Card>

        {activeTable === "leads" && lastLeadsPage > 1 && (
          <div className="mt-4 flex items-center justify-end gap-3">
            <Button variant="outline" size="sm" onClick={() => setLeadsPage((current) => Math.max(1, current - 1))} disabled={currentLeadsPage <= 1 || leadsQuery.isFetching}>
              Anterior
            </Button>
            <span className="text-sm font-medium text-gray-600 min-w-[100px] text-center">
              Pág. {currentLeadsPage} de {lastLeadsPage}
            </span>
            <Button variant="outline" size="sm" onClick={() => setLeadsPage((current) => Math.min(lastLeadsPage, current + 1))} disabled={currentLeadsPage >= lastLeadsPage || leadsQuery.isFetching}>
              Próxima
            </Button>
          </div>
        )}

        {activeTable === "attempts" && lastAttemptsPage > 1 && (
          <div className="mt-4 flex items-center justify-end gap-3">
            <Button variant="outline" size="sm" onClick={() => setAttemptsPage((current) => Math.max(1, current - 1))} disabled={currentAttemptsPage <= 1 || attemptsQuery.isFetching}>
              Anterior
            </Button>
            <span className="text-sm font-medium text-gray-600 min-w-[100px] text-center">
              Pág. {currentAttemptsPage} de {lastAttemptsPage}
            </span>
            <Button variant="outline" size="sm" onClick={() => setAttemptsPage((current) => Math.min(lastAttemptsPage, current + 1))} disabled={currentAttemptsPage >= lastAttemptsPage || attemptsQuery.isFetching}>
              Próxima
            </Button>
          </div>
        )}
      </div>
    </div>
  );
};

export default IntegracoesVendeaiPage;
