import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { format, parseISO, subHours } from "date-fns";
import { ptBR } from "date-fns/locale";
import { AlertCircle, Loader2 } from "lucide-react";
import { toast } from "sonner";

import {
  downloadVendeaiExport,
  getVendeaiExportStatus,
  getVendeaiMetrics,
  listVendeaiLeads,
  startVendeaiExport,
  type VendeaiLead,
  type VendeaiNewcorbanFilter,
  type VendeaiSortDirection,
} from "@/api/vendeai";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatCPF, formatPhone } from "@/lib/formatters";
import { VendeaiControls } from "../components/VendeaiControls";
import { VendeaiFiltersModal } from "../components/VendeaiFiltersModal";

type FiltersState = {
  from: string;
  to: string;
  direction: VendeaiSortDirection;
  windowMode: "rolling" | "fixed";
  newcorbanFilter: VendeaiNewcorbanFilter;
};

const STORAGE_KEY = "vendeai:integracoes:filters:v2";
const AUTO_REFRESH_MS = 60_000;
const MANUAL_REFRESH_COOLDOWN_MS = 10_000;
const brMoney = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });

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
    newcorbanFilter: "all",
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
    return { from: toDateTimeLocalValue(subHours(now, 24)), to: toDateTimeLocalValue(now) };
  }

  const durationMs = Math.max(60_000, toDate.getTime() - fromDate.getTime());
  const now = new Date();

  return {
    from: toDateTimeLocalValue(new Date(now.getTime() - durationMs)),
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
    const storedNewcorbanFilter =
      typeof (parsed as { newcorbanFilter?: unknown }).newcorbanFilter === "string"
        ? (parsed as { newcorbanFilter?: string }).newcorbanFilter
        : null;
    const newcorbanFilter =
      storedNewcorbanFilter === "created" || storedNewcorbanFilter === "sent" ? "sent" : fallback.newcorbanFilter;

    if (windowMode === "rolling") {
      const rolled = rollRangeToNow(from, to);
      return { from: rolled.from, to: rolled.to, direction: "desc", windowMode, newcorbanFilter };
    }

    return { from, to, direction: "desc", windowMode, newcorbanFilter };
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
  if (normalized === "cross_sell" || normalized === "_cross_sell") return "Cross sell";
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

function DetailLine({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value || value === "-") return null;
  return (
    <div className="text-xs text-gray-600">
      <span className="font-medium text-gray-700">{label}:</span> {value}
    </div>
  );
}

function SimulationDetails({
  data,
}: {
  data: Pick<
    VendeaiLead,
    | "simulation_product"
    | "simulation_bank"
    | "simulation_liquid_value"
    | "simulation_number_of_payments"
    | "simulation_installment_value"
    | "simulation_monthly_fee"
    | "simulation_table_name"
    | "simulation_table_id"
    | "simulation_best_liquid_value"
    | "simulation_best_table_id"
    | "simulation_received_at"
  >;
}) {
  if (
    !data.simulation_product &&
    !data.simulation_bank &&
    !data.simulation_liquid_value &&
    !data.simulation_number_of_payments &&
    !data.simulation_installment_value &&
    !data.simulation_monthly_fee &&
    !data.simulation_table_name &&
    !data.simulation_best_liquid_value &&
    !data.simulation_best_table_id &&
    !data.simulation_received_at
  ) {
    return <span className="text-gray-400">-</span>;
  }

  return (
    <div className="min-w-[240px] space-y-1">
      <DetailLine label="Produto" value={productLabel(data.simulation_product || "-")} />
      <DetailLine label="Banco" value={bankLabel(data.simulation_bank || "-")} />
      <DetailLine label="Valor líquido" value={formatCurrency(data.simulation_liquid_value)} />
      <DetailLine label="Parcela" value={formatCurrency(data.simulation_installment_value)} />
      <DetailLine label="Parcelas" value={data.simulation_number_of_payments ? String(data.simulation_number_of_payments) : "-"} />
      <DetailLine label="Taxa mensal" value={data.simulation_monthly_fee ? `${String(data.simulation_monthly_fee)}%` : "-"} />
      <DetailLine label="Tabela" value={data.simulation_table_name || data.simulation_table_id || "-"} />
      <DetailLine label="Melhor valor" value={data.simulation_best_liquid_value ? formatCurrency(data.simulation_best_liquid_value) : "-"} />
      <DetailLine label="Melhor tabela" value={data.simulation_best_table_id || "-"} />
      <DetailLine label="Data" value={formatDateTime(data.simulation_received_at)} />
    </div>
  );
}

function ProposalDetails({
  data,
}: {
  data: {
    proposal_id: string | null;
    proposal_number: string | null;
    proposal_bank: string | null;
    proposal_product: string | null;
    proposal_status: string | null;
    previous_proposal_status: string | null;
    proposal_liquid_value: string | null;
    proposal_gross_value: string | null;
    proposal_number_of_payments: number | null;
    proposal_installment_value: string | null;
    proposal_table_name: string | null;
    proposal_table_id: string | null;
    proposal_formalization_link: string | null;
    proposal_created_at: string | null;
    proposal_status_updated_at: string | null;
  };
}) {
  if (
    !data.proposal_id &&
    !data.proposal_status &&
    !data.previous_proposal_status &&
    !data.proposal_bank &&
    !data.proposal_product &&
    !data.proposal_liquid_value &&
    !data.proposal_gross_value &&
    !data.proposal_number_of_payments &&
    !data.proposal_installment_value &&
    !data.proposal_table_name &&
    !data.proposal_formalization_link &&
    !data.proposal_created_at &&
    !data.proposal_status_updated_at
  ) {
    return <span className="text-gray-400">-</span>;
  }

  return (
    <div className="min-w-[260px] space-y-1">
      <div className="font-medium text-gray-900">{data.proposal_id || "-"}</div>
      <DetailLine label="Número" value={data.proposal_number || "-"} />
      <DetailLine label="Status" value={data.proposal_status || "-"} />
      <DetailLine label="Status anterior" value={data.previous_proposal_status || "-"} />
      <DetailLine label="Produto" value={productLabel(data.proposal_product || "-")} />
      <DetailLine label="Banco" value={bankLabel(data.proposal_bank || "-")} />
      <DetailLine label="Valor líquido" value={formatCurrency(data.proposal_liquid_value)} />
      <DetailLine label="Valor bruto" value={formatCurrency(data.proposal_gross_value)} />
      <DetailLine label="Parcela" value={formatCurrency(data.proposal_installment_value)} />
      <DetailLine label="Parcelas" value={data.proposal_number_of_payments ? String(data.proposal_number_of_payments) : "-"} />
      <DetailLine label="Tabela" value={data.proposal_table_name || data.proposal_table_id || "-"} />
      {data.proposal_formalization_link ? (
        <div className="text-xs text-blue-700">
          <a href={data.proposal_formalization_link} target="_blank" rel="noreferrer" className="font-medium hover:underline">
            Link de formalização
          </a>
        </div>
      ) : null}
      <DetailLine label="Criada em" value={formatDateTime(data.proposal_created_at)} />
      <DetailLine label="Atualizada em" value={formatDateTime(data.proposal_status_updated_at)} />
    </div>
  );
}

export default function IntegracoesVendeaiPage() {
  const initial = useMemo(() => loadFilters(), []);
  const [fromInput, setFromInput] = useState(initial.from);
  const [toInput, setToInput] = useState(initial.to);
  const [windowModeInput, setWindowModeInput] = useState<FiltersState["windowMode"]>(initial.windowMode);
  const [newcorbanFilterInput, setNewcorbanFilterInput] = useState<VendeaiNewcorbanFilter>(initial.newcorbanFilter);
  const [applied, setApplied] = useState<FiltersState>(initial);
  const [leadsPage, setLeadsPage] = useState(1);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
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
    const timer = window.setInterval(() => setNowTs(Date.now()), 1000);
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

  const leadsQuery = useQuery({
    queryKey: ["vendeai:leads", leadsPage, fromIso, toIso, applied.newcorbanFilter],
    queryFn: ({ signal }) =>
      listVendeaiLeads(
        {
          page: leadsPage,
          perPage: 20,
          from: fromIso,
          to: toIso,
          direction: applied.direction,
          sort: "first_received_at",
          newcorbanFilter: applied.newcorbanFilter,
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
  const appliedLabels = [
    `Modo: ${applied.windowMode === "rolling" ? "Janela móvel" : "Intervalo fixo"}`,
    `De ${formatDateTime(fromIso ?? applied.from)}`,
    `Até ${formatDateTime(toIso ?? applied.to)}`,
    ...(applied.newcorbanFilter === "sent" ? ["Proposta enviada NewCorban"] : []),
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
      direction: "desc",
      windowMode: windowModeInput,
      newcorbanFilter: newcorbanFilterInput,
    });
    setLeadsPage(1);
    return true;
  };

  const handleApplyFilters = () => {
    if (applyFilters()) setIsFiltersModalOpen(false);
  };

  const resetFilterInputs = () => {
    const next = defaultFilters();
    setFromInput(next.from);
    setToInput(next.to);
    setWindowModeInput(next.windowMode);
    setNewcorbanFilterInput(next.newcorbanFilter);
    setRangeError(null);
  };

  const clearFilters = () => {
    const next = defaultFilters();
    setFromInput(next.from);
    setToInput(next.to);
    setWindowModeInput(next.windowMode);
    setNewcorbanFilterInput(next.newcorbanFilter);
    setRangeError(null);
    setApplied(next);
    setLeadsPage(1);
  };

  const handleManualRefresh = () => {
    if (metricsQuery.isFetching || leadsQuery.isFetching || manualRefreshRemaining > 0) return;
    setManualRefreshLockedUntil(Date.now() + MANUAL_REFRESH_COOLDOWN_MS);
    void metricsQuery.refetch();
    void leadsQuery.refetch();
  };

  const exportCsv = async () => {
    if (exporting) return;

    setExporting(true);
    const toastId = toast.loading("Gerando CSV VendeAI...", { duration: Infinity });

    try {
      const { token } = await startVendeaiExport("leads", {
        from: fromIso,
        to: toIso,
        direction: applied.direction,
        newcorbanFilter: applied.newcorbanFilter,
      });

      for (let attempt = 0; attempt < 180; attempt += 1) {
        const status = await getVendeaiExportStatus(token);

        if (status.status === "ready") {
          toast.success("CSV pronto. Baixando...", { id: toastId });
          await downloadVendeaiExport(token);
          toast.dismiss(toastId);
          return;
        }

        if (status.status === "error") throw new Error(status.error || status.message || "Falha ao gerar CSV.");
        if (status.status === "deleted") throw new Error(status.message || "Export expirou antes do download.");

        await sleep(pollDelay(attempt));
      }

      throw new Error("O export demorou além do esperado.");
    } catch (error) {
      toast.error(errorMessage(error), { id: toastId });
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="flex min-w-0 max-w-full flex-col gap-6 p-4 lg:p-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="mb-1 text-xl font-bold text-gray-900 lg:text-2xl">Integração VendeAI</h1>
          <p className="text-sm text-gray-600 lg:text-base">Métricas de conversas, propostas e integrações com a NewCorban.</p>
        </div>
      </div>

      <VendeaiControls
        modeLabel="Leads VendeAI"
        filteredCount={totalLeads}
        countLabel="leads encontrados"
        exportLabel="CSV filtrado"
        exportLoading={exporting}
        exportIcon="file"
        isRefreshing={metricsQuery.isFetching || leadsQuery.isFetching}
        refreshCountdown={manualRefreshRemaining}
        activeLabels={appliedLabels}
        hasActiveFilters
        onFilterClick={() => setIsFiltersModalOpen(true)}
        onExportClick={() => void exportCsv()}
        onRefreshClick={handleManualRefresh}
        onClearFilters={clearFilters}
      />

      <VendeaiFiltersModal
        isOpen={isFiltersModalOpen}
        title="Filtros dos leads VendeAI"
        subtitle="Ajuste o período e o recorte de proposta enviada NewCorban."
        from={fromInput}
        to={toInput}
        windowMode={windowModeInput}
        newcorbanFilter={newcorbanFilterInput}
        windowModeOptions={windowModeOptions}
        rangeError={rangeError}
        onClose={() => setIsFiltersModalOpen(false)}
        onFromChange={setFromInput}
        onToChange={setToInput}
        onWindowModeChange={setWindowModeInput}
        onNewcorbanFilterChange={setNewcorbanFilterInput}
        onReset={resetFilterInputs}
        onApply={handleApplyFilters}
      />

      <div>
        <div className="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Leads VendeAI</h2>
            <p className="text-sm text-muted-foreground">{`${leads.length} nesta página • ${formatNumber(totalLeads)} no total`}</p>
          </div>
          <div className="flex items-center gap-2 text-sm text-gray-500">
            {leadsQuery.isFetching ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <span className="h-2 w-2 rounded-full bg-emerald-500" />}
            {leadsQuery.isFetching ? "Atualizando..." : "Atualiza automaticamente a cada 60s"}
          </div>
        </div>

        <Card className="overflow-hidden border border-gray-200 shadow-sm flex flex-col">
          {/* HEADER DE MÉTRICAS INTEGRADO */}
          {metricsQuery.isLoading && (
            <div className="bg-gray-50/50 border-b border-gray-100 p-4 flex items-center text-sm text-gray-500">
              <Loader2 className="w-4 h-4 mr-2 animate-spin" /> Carregando métricas de resumo...
            </div>
          )}
          {metricsQuery.isError && (
            <div className="bg-red-50/50 border-b border-red-100 p-4 flex items-center text-sm text-red-600">
              <AlertCircle className="w-4 h-4 mr-2" /> Não foi possível carregar as métricas do período.
            </div>
          )}
          {!metricsQuery.isLoading && !metricsQuery.isError && metrics && (
            <div className="flex flex-col divide-y divide-gray-100 border-b border-gray-200">
              {/* Seção 1: Leads/Conversas IA */}
              <div className="bg-blue-50/20 p-4 flex flex-col md:flex-row md:items-center gap-4 lg:gap-8">
                <div className="flex flex-col min-w-[120px]">
                  <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Conversas com IA</span>
                  <span className="text-2xl font-bold text-blue-600 leading-none">{formatNumber(metrics.leads.total)}</span>
                </div>
                {metrics.leads.by_product && metrics.leads.by_product.length > 0 && (
                  <div className="hidden md:block w-px h-8 bg-gray-200" />
                )}
                <div className="flex-1 flex flex-col">
                  {metrics.leads.by_product && metrics.leads.by_product.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mr-1">Por Produto:</span>
                      {metrics.leads.by_product.map(item => (
                        <Badge key={item.label} variant="outline" className="bg-white border-gray-200 text-gray-700 shadow-sm font-medium py-0.5">
                          {productLabel(item.label)} <span className="ml-1.5 font-bold text-gray-900">{formatNumber(item.total)}</span>
                        </Badge>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              {/* Seção 2: Propostas NewCorban */}
              <div className="bg-gray-50/30 p-4 flex flex-col md:flex-row md:items-center gap-4 lg:gap-8">
                <div className="flex items-center gap-6 lg:gap-8">
                  <div className="flex flex-col">
                    <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Propostas Enviadas</span>
                    <span className="text-2xl font-bold text-gray-700 leading-none">{formatNumber(metrics.attempts.total)}</span>
                  </div>
                  <div className="flex flex-col">
                    <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Criadas NewCorban</span>
                    <span className="text-2xl font-bold text-emerald-600 leading-none">{formatNumber(metrics.attempts.success)}</span>
                  </div>
                  <div className="flex flex-col">
                    <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Falhas</span>
                    <span className="text-2xl font-bold text-rose-600 leading-none">{formatNumber(metrics.attempts.failed)}</span>
                  </div>
                </div>
                {metrics.attempts.by_product && metrics.attempts.by_product.length > 0 && (
                  <div className="hidden md:block w-px h-8 bg-gray-200" />
                )}
                <div className="flex-1 flex flex-col">
                  {metrics.attempts.by_product && metrics.attempts.by_product.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mr-1">Por Produto:</span>
                      {metrics.attempts.by_product.map(item => (
                        <Badge key={item.label} variant="outline" className="bg-white border-gray-200 text-gray-700 shadow-sm font-medium py-0.5">
                          {productLabel(item.label)} <span className="ml-1.5 font-bold text-gray-900">{formatNumber(item.total)}</span>
                        </Badge>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">CPF</th>
                  <th className="px-4 py-3 text-left font-medium">Nome</th>
                  <th className="px-4 py-3 text-left font-medium">Nascimento</th>
                  <th className="px-4 py-3 text-left font-medium">Telefone</th>
                  <th className="px-4 py-3 text-left font-medium">Chat</th>
                  <th className="px-4 py-3 text-left font-medium">Etapa</th>
                  <th className="px-4 py-3 text-left font-medium">Tags</th>
                  <th className="px-4 py-3 text-left font-medium">Dados da simulação</th>
                  <th className="px-4 py-3 text-left font-medium">Dados da proposta</th>
                  <th className="px-4 py-3 text-left font-medium">Proposta NewCorban</th>
                  <th className="px-4 py-3 text-left font-medium">Eventos</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 bg-white">
                {leadsQuery.isLoading ? (
                  <tr>
                    <td colSpan={11} className="px-4 py-12 text-center text-gray-500">
                      <Loader2 className="mx-auto mb-2 h-5 w-5 animate-spin" />
                      Carregando leads...
                    </td>
                  </tr>
                ) : leadsQuery.isError ? (
                  <tr>
                    <td colSpan={11} className="px-4 py-12 text-center text-red-600">Falha ao carregar leads.</td>
                  </tr>
                ) : leads.length === 0 ? (
                  <tr>
                    <td colSpan={11} className="px-4 py-12 text-center text-gray-500">Nenhum lead no período.</td>
                  </tr>
                ) : (
                  leads.map((lead) => (
                    <tr key={lead.id} className="align-top transition-colors duration-150 hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium text-gray-900">{formatCPF(lead.customer_cpf)}</td>
                      <td className="px-4 py-3 font-medium text-gray-900">{lead.customer_name || "-"}</td>
                      <td className="px-4 py-3 font-medium text-gray-900">{formatDate(lead.customer_birth_date)}</td>
                      <td className="px-4 py-3 font-medium text-gray-900">{formatPhone(lead.customer_phone)}</td>
                      <td className="px-4 py-3 font-medium text-gray-900">{lead.chat_id || "-"}</td>
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
                      <td className="px-4 py-3"><SimulationDetails data={lead} /></td>
                      <td className="px-4 py-3">
                        <ProposalDetails
                          data={{
                            proposal_id: lead.proposal_id,
                            proposal_number: lead.proposal_number,
                            proposal_bank: lead.proposal_bank,
                            proposal_product: lead.proposal_product,
                            proposal_status: lead.proposal_status,
                            previous_proposal_status: lead.previous_proposal_status,
                            proposal_liquid_value: lead.proposal_liquid_value,
                            proposal_gross_value: lead.proposal_gross_value,
                            proposal_number_of_payments: lead.proposal_number_of_payments,
                            proposal_installment_value: lead.proposal_installment_value,
                            proposal_table_name: lead.proposal_table_name,
                            proposal_table_id: lead.proposal_table_id,
                            proposal_formalization_link: lead.proposal_formalization_link,
                            proposal_created_at: lead.proposal_created_at,
                            proposal_status_updated_at: lead.proposal_status_updated_at,
                          }}
                        />
                      </td>
                      <td className="px-4 py-3">
                        {lead.newcorban_error ? (
                          <div className="max-w-[320px] text-sm text-red-700">{lead.newcorban_error}</div>
                        ) : (
                          <div className="font-medium text-gray-900">{lead.newcorban_proposta_id || "-"}</div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-blue-700">{formatDateTime(lead.first_received_at)}</div>
                        <div className="text-xs text-gray-500">{formatDateTime(lead.last_received_at)}</div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>

        {lastLeadsPage > 1 ? (
          <div className="mt-4 flex items-center justify-end gap-3">
            <Button variant="outline" size="sm" onClick={() => setLeadsPage((current) => Math.max(1, current - 1))} disabled={currentLeadsPage <= 1 || leadsQuery.isFetching}>
              Anterior
            </Button>
            <span className="min-w-[100px] text-center text-sm font-medium text-gray-600">Pág. {currentLeadsPage} de {lastLeadsPage}</span>
            <Button variant="outline" size="sm" onClick={() => setLeadsPage((current) => Math.min(lastLeadsPage, current + 1))} disabled={currentLeadsPage >= lastLeadsPage || leadsQuery.isFetching}>
              Próxima
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  );
}
