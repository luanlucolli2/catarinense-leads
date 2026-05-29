import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { format, parseISO, subHours } from "date-fns";
import { ptBR } from "date-fns/locale";
import {
  AlertCircle,
  Calendar,
  CheckCircle2,
  Clock,
  Download,
  FileDown,
  Filter,
  Loader2,
  RefreshCw,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";

import {
  downloadVendeaiExport,
  getVendeaiExportStatus,
  getVendeaiMetrics,
  listVendeaiAttempts,
  startVendeaiExport,
  type VendeaiAttemptStatus,
  type VendeaiExportType,
  type VendeaiMetricBucket,
  type VendeaiSortDirection,
} from "@/api/vendeai";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

type FiltersState = {
  from: string;
  to: string;
  status: VendeaiAttemptStatus;
  direction: VendeaiSortDirection;
};

const STORAGE_KEY = "vendeai:integracoes:filters:v1";
const AUTO_REFRESH_MS = 60_000;
const MANUAL_REFRESH_COOLDOWN_MS = 10_000;
const brMoney = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });

const statusOptions: Array<{ value: VendeaiAttemptStatus; label: string }> = [
  { value: "all", label: "Todas" },
  { value: "success", label: "Sucesso" },
  { value: "failed", label: "Falha" },
  { value: "pending", label: "Não enviada" },
];

const directionOptions: Array<{ value: VendeaiSortDirection; label: string }> = [
  { value: "desc", label: "Mais recentes" },
  { value: "asc", label: "Mais antigas" },
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
    status: "all",
    direction: "desc",
  };
}

function loadFilters(): FiltersState {
  const fallback = defaultFilters();
  if (typeof window === "undefined") return fallback;

  try {
    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "{}") as Partial<FiltersState>;
    return {
      from: typeof parsed.from === "string" && parsed.from ? parsed.from : fallback.from,
      to: typeof parsed.to === "string" && parsed.to ? parsed.to : fallback.to,
      status: statusOptions.some((option) => option.value === parsed.status) ? parsed.status as VendeaiAttemptStatus : fallback.status,
      direction: parsed.direction === "asc" || parsed.direction === "desc" ? parsed.direction : fallback.direction,
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

function MetricCard({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <Card className="border-gray-100">
      <CardContent className="p-4">
        <p className="text-sm text-gray-500">{label}</p>
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

function ProductList({ title, items }: { title: string; items: VendeaiMetricBucket[] }) {
  return (
    <Card className="border-gray-100">
      <CardContent className="p-4">
        <p className="text-sm font-medium text-gray-800">{title}</p>
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
  const [statusInput, setStatusInput] = useState<VendeaiAttemptStatus>(initial.status);
  const [directionInput, setDirectionInput] = useState<VendeaiSortDirection>(initial.direction);
  const [applied, setApplied] = useState<FiltersState>(initial);
  const [page, setPage] = useState(1);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<VendeaiExportType | null>(null);
  const [manualRefreshLockedUntil, setManualRefreshLockedUntil] = useState(0);
  const [nowTs, setNowTs] = useState(() => Date.now());

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
    queryKey: ["vendeai:attempts", page, fromIso, toIso, applied.status, applied.direction],
    queryFn: ({ signal }) =>
      listVendeaiAttempts(
        {
          page,
          perPage: 20,
          from: fromIso,
          to: toIso,
          status: applied.status,
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

  const metrics = metricsQuery.data;
  const attempts = attemptsQuery.data?.data ?? [];
  const currentPage = attemptsQuery.data?.current_page ?? page;
  const lastPage = attemptsQuery.data?.last_page ?? 1;
  const totalAttempts = attemptsQuery.data?.total ?? 0;

  const applyFilters = () => {
    const fromDate = new Date(fromInput);
    const toDate = new Date(toInput);

    if (!fromInput || !toInput || Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
      setRangeError("Preencha um intervalo válido com data e hora.");
      return;
    }

    if (fromDate.getTime() > toDate.getTime()) {
      setRangeError("A data/hora inicial não pode ser maior que a final.");
      return;
    }

    setRangeError(null);
    setApplied({ from: fromInput, to: toInput, status: statusInput, direction: directionInput });
    setPage(1);
  };

  const handleManualRefresh = () => {
    if (metricsQuery.isFetching || attemptsQuery.isFetching || manualRefreshRemaining > 0) {
      return;
    }

    setManualRefreshLockedUntil(Date.now() + MANUAL_REFRESH_COOLDOWN_MS);
    void metricsQuery.refetch();
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
        status: type === "newcorban-proposal-attempts" ? applied.status : undefined,
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
            Métricas de conversas e tentativas de criação de propostas na New Corban.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" onClick={() => void exportCsv("leads")} disabled={exporting !== null}>
            {exporting === "leads" ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <FileDown className="w-4 h-4 mr-2" />}
            CSV leads
          </Button>
          <Button variant="outline" onClick={() => void exportCsv("newcorban-proposal-attempts")} disabled={exporting !== null}>
            {exporting === "newcorban-proposal-attempts" ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <Download className="w-4 h-4 mr-2" />}
            CSV tentativas
          </Button>
          <Button
            variant="outline"
            onClick={handleManualRefresh}
            disabled={metricsQuery.isFetching || attemptsQuery.isFetching || manualRefreshRemaining > 0}
          >
            {metricsQuery.isFetching || attemptsQuery.isFetching ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : <RefreshCw className="w-4 h-4 mr-2" />}
            {manualRefreshRemaining > 0 ? `Atualizar (${manualRefreshRemaining}s)` : "Atualizar"}
          </Button>
        </div>
      </div>

      <div className="py-5 border-y border-gray-100">
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr_220px_180px_auto] lg:items-end">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Calendar className="w-4 h-4 text-gray-400" />
              Data inicial
            </label>
            <Input type="datetime-local" value={fromInput} onChange={(event) => setFromInput(event.target.value)} />
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Calendar className="w-4 h-4 text-gray-400" />
              Data final
            </label>
            <Input type="datetime-local" value={toInput} onChange={(event) => setToInput(event.target.value)} />
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <CheckCircle2 className="w-4 h-4 text-gray-400" />
              Situação da tentativa
            </label>
            <Select value={statusInput} onValueChange={(value) => setStatusInput(value as VendeaiAttemptStatus)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {statusOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium text-gray-700 flex items-center gap-1.5">
              <Clock className="w-4 h-4 text-gray-400" />
              Ordenação
            </label>
            <Select value={directionInput} onValueChange={(value) => setDirectionInput(value as VendeaiSortDirection)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {directionOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <Button onClick={applyFilters}>
            <Filter className="w-4 h-4 mr-2" />
            Aplicar
          </Button>
        </div>

        {rangeError && (
          <p className="mt-3 text-sm text-red-600 flex items-center gap-1.5">
            <AlertCircle className="w-4 h-4" />
            {rangeError}
          </p>
        )}
      </div>

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
            <div>
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Conversas com a IA</h2>
              <div className="mt-3 grid grid-cols-1 gap-3">
                <MetricCard label="Conversas com a IA" value={formatNumber(metrics?.leads.total)} />
              </div>
            </div>

            <div>
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Criação de propostas na New Corban</h2>
              <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <MetricCard label="Tentativas de criação" value={formatNumber(metrics?.attempts.total)} />
                <MetricCard label="Criadas na New Corban" value={formatNumber(metrics?.attempts.success)} />
                <MetricCard label="Falhas na criação" value={formatNumber(metrics?.attempts.failed)} />
                <MetricCard label="Taxa de criação" value={`${metrics?.attempts.success_rate ?? 0}%`} detail={`${formatNumber(metrics?.attempts.pending)} não enviada(s)`} />
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <ProductList title="Conversas por produto" items={metrics?.leads.by_product ?? []} />
            <ProductList title="Propostas por produto" items={metrics?.attempts.by_product ?? []} />
          </div>
        </>
      )}

      <div>
        <div className="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Tentativas de criação de proposta New Corban</h2>
            <p className="text-muted-foreground text-sm">{attempts.length} nesta página • {formatNumber(totalAttempts)} no total</p>
          </div>
          <div className="text-sm text-gray-500 flex items-center gap-2">
            {attemptsQuery.isFetching ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <span className="w-2 h-2 rounded-full bg-emerald-500" />}
            {attemptsQuery.isFetching ? "Atualizando..." : "Atualiza automaticamente a cada 60s"}
          </div>
        </div>

        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Tentativa</th>
                  <th className="px-4 py-3 text-left font-medium">Nome</th>
                  <th className="px-4 py-3 text-left font-medium">CPF</th>
                  <th className="px-4 py-3 text-left font-medium">Chat</th>
                  <th className="px-4 py-3 text-left font-medium">Nascimento</th>
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
                    <td colSpan={10} className="px-4 py-12 text-center text-gray-500">
                      <Loader2 className="mx-auto mb-2 h-5 w-5 animate-spin" />
                      Carregando tentativas...
                    </td>
                  </tr>
                ) : attemptsQuery.isError ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-12 text-center text-red-600">Falha ao carregar tentativas.</td>
                  </tr>
                ) : attempts.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-12 text-center text-gray-500">Nenhuma tentativa no período.</td>
                  </tr>
                ) : (
                  attempts.map((attempt) => (
                    <tr key={attempt.id} className="align-top">
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">#{attempt.id}</div>
                        <div className="text-xs text-gray-500">{formatDateTime(attempt.received_at)}</div>
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
                        <div className="font-medium text-gray-900">{attempt.lead.customer_name || "-"}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{attempt.lead.customer_cpf || "-"}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{attempt.lead.chat_id || "-"}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{formatDate(attempt.lead.customer_birth_date)}</div>
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
          </div>
        </Card>

        {lastPage > 1 && (
          <div className="mt-4 flex items-center justify-end gap-3">
            <Button variant="outline" size="sm" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={currentPage <= 1 || attemptsQuery.isFetching}>
              Anterior
            </Button>
            <span className="text-sm font-medium text-gray-600 min-w-[100px] text-center">
              Pág. {currentPage} de {lastPage}
            </span>
            <Button variant="outline" size="sm" onClick={() => setPage((current) => Math.min(lastPage, current + 1))} disabled={currentPage >= lastPage || attemptsQuery.isFetching}>
              Próxima
            </Button>
          </div>
        )}
      </div>
    </div>
  );
};

export default IntegracoesVendeaiPage;
