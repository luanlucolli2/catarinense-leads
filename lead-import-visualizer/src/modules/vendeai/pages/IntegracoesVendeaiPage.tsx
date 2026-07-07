import { useEffect, useMemo, useRef, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { AlertCircle, Loader2 } from "lucide-react";
import { toast } from "sonner";

import {
  downloadVendeaiExport,
  getVendeaiExportStatus,
  getVendeaiFilterOptions,
  getVendeaiMetrics,
  listVendeaiLeads,
  startVendeaiExport,
  type VendeaiLeadAttempt,
  type VendeaiLead,
  type VendeaiLeadPeriodBasis,
  type VendeaiLeadSortField,
  type VendeaiNewcorbanStatusFilter,
  type VendeaiNewcorbanStatusValue,
  type VendeaiProductValue,
  type VendeaiSortDirection,
} from "@/api/vendeai";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { formatCPF, formatPhone } from "@/lib/formatters";
import newcorbanLogo from "@/assets/newcorbanlogo.png";
import { VendeaiControls } from "../components/VendeaiControls";
import { VendeaiFiltersModal } from "../components/VendeaiFiltersModal";

type PeriodPreset = "always" | "today" | "yesterday" | "last7Days" | "last30Days" | "custom";

type FiltersState = {
  from: string;
  to: string;
  leadPeriodBasis: VendeaiLeadPeriodBasis;
  search: string;
  sort: VendeaiLeadSortField;
  direction: VendeaiSortDirection;
  windowMode: "always" | "rolling" | "fixed";
  periodPreset: PeriodPreset;
  product: VendeaiProductValue[];
  bank: string[];
  stage: string[];
  proposalStatus: string[];
  newcorbanStatus: VendeaiNewcorbanStatusValue[];
  inboxPhoneNumber: string[];
  tags: string[];
};

type BrazilDateTimeParts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
};

const STORAGE_KEY = "vendeai:integracoes:filters:v3";
const AUTO_REFRESH_MS = 60_000;
const MANUAL_REFRESH_COOLDOWN_MS = 10_000;
const BRAZIL_TIME_ZONE = "America/Sao_Paulo";
const NO_PROPOSAL_VALUE = "no_proposal";

const brMoney = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
const brazilDateTimeFormatter = new Intl.DateTimeFormat("pt-BR", {
  timeZone: BRAZIL_TIME_ZONE,
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
  hourCycle: "h23",
});
const brazilDateFormatter = new Intl.DateTimeFormat("pt-BR", {
  timeZone: BRAZIL_TIME_ZONE,
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});
const brazilDateTimePartsFormatter = new Intl.DateTimeFormat("en-CA", {
  timeZone: BRAZIL_TIME_ZONE,
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
  hourCycle: "h23",
});

const windowModeOptions: Array<{ value: FiltersState["windowMode"]; label: string }> = [
  { value: "always", label: "Sempre" },
  { value: "rolling", label: "Janela móvel" },
  { value: "fixed", label: "Intervalo fixo" },
];

function pad(value: number, size: number = 2): string {
  return String(value).padStart(size, "0");
}

function getBrazilDateTimeParts(date: Date): BrazilDateTimeParts {
  const mapped = Object.fromEntries(
    brazilDateTimePartsFormatter
      .formatToParts(date)
      .filter((part) => part.type !== "literal")
      .map((part) => [part.type, part.value])
  ) as Record<string, string>;

  return {
    year: Number(mapped.year),
    month: Number(mapped.month),
    day: Number(mapped.day),
    hour: Number(mapped.hour),
    minute: Number(mapped.minute),
    second: Number(mapped.second),
  };
}

function toDateTimeLocalValue(date: Date): string {
  const parts = getBrazilDateTimeParts(date);
  return `${pad(parts.year, 4)}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`;
}

function toDateTimeLocalFromParts(year: number, month: number, day: number, hour: number, minute: number): string {
  return `${pad(year, 4)}-${pad(month)}-${pad(day)}T${pad(hour)}:${pad(minute)}`;
}

function parseDateOnlyParts(value: string): { year: number; month: number; day: number } | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) return null;

  return {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
  };
}

function parseDateTimeLocalParts(value: string): BrazilDateTimeParts | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!match) return null;

  return {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4]),
    minute: Number(match[5]),
    second: Number(match[6] ?? "0"),
  };
}

function toPseudoUtcTimestamp(parts: BrazilDateTimeParts): number {
  return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
}

function formatLocalParts(parts: BrazilDateTimeParts): string {
  return `${pad(parts.day)}/${pad(parts.month)}/${pad(parts.year, 4)} ${pad(parts.hour)}:${pad(parts.minute)}:${pad(parts.second)}`;
}

function parseBrazilDateTimeLocalToUtcDate(value: string): Date | null {
  const target = parseDateTimeLocalParts(value);
  if (!target) return null;

  let timestamp = toPseudoUtcTimestamp(target);

  for (let attempt = 0; attempt < 4; attempt += 1) {
    const current = getBrazilDateTimeParts(new Date(timestamp));
    const diff = toPseudoUtcTimestamp(target) - toPseudoUtcTimestamp(current);
    timestamp += diff;
    if (diff === 0) break;
  }

  const roundTrip = getBrazilDateTimeParts(new Date(timestamp));
  if (
    roundTrip.year !== target.year ||
    roundTrip.month !== target.month ||
    roundTrip.day !== target.day ||
    roundTrip.hour !== target.hour ||
    roundTrip.minute !== target.minute
  ) {
    return null;
  }

  return new Date(timestamp);
}

function isValidDateTimeLocal(value: unknown): value is string {
  return typeof value === "string" && value.trim() !== "" && parseBrazilDateTimeLocalToUtcDate(value) !== null;
}

function toUtcIso(value: string): string | undefined {
  if (!value) return undefined;
  const date = parseBrazilDateTimeLocalToUtcDate(value);
  return date ? date.toISOString() : undefined;
}

function shiftBrazilDate(parts: { year: number; month: number; day: number }, days: number): { year: number; month: number; day: number } {
  const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day));
  date.setUTCDate(date.getUTCDate() + days);

  return {
    year: date.getUTCFullYear(),
    month: date.getUTCMonth() + 1,
    day: date.getUTCDate(),
  };
}

function presetRange(preset: Exclude<PeriodPreset, "always" | "custom">, baseNow: Date = new Date()): { from: string; to: string } {
  const now = getBrazilDateTimeParts(baseNow);

  if (preset === "today") {
    return {
      from: toDateTimeLocalFromParts(now.year, now.month, now.day, 0, 0),
      to: toDateTimeLocalFromParts(now.year, now.month, now.day, now.hour, now.minute),
    };
  }

  if (preset === "yesterday") {
    const previous = shiftBrazilDate(now, -1);
    return {
      from: toDateTimeLocalFromParts(previous.year, previous.month, previous.day, 0, 0),
      to: toDateTimeLocalFromParts(previous.year, previous.month, previous.day, 23, 59),
    };
  }

  const start = shiftBrazilDate(now, preset === "last7Days" ? -6 : -29);

  return {
    from: toDateTimeLocalFromParts(start.year, start.month, start.day, 0, 0),
    to: toDateTimeLocalFromParts(now.year, now.month, now.day, now.hour, now.minute),
  };
}

function rollRangeToNow(fromValue: string, toValue: string, baseNow: Date = new Date()): { from: string; to: string } {
  const fromDate = parseBrazilDateTimeLocalToUtcDate(fromValue);
  const toDate = parseBrazilDateTimeLocalToUtcDate(toValue);

  if (fromDate === null || toDate === null || fromDate > toDate) {
    const now = baseNow;
    return { from: toDateTimeLocalValue(new Date(now.getTime() - 24 * 60 * 60 * 1000)), to: toDateTimeLocalValue(now) };
  }

  const durationMs = Math.max(60_000, toDate.getTime() - fromDate.getTime());
  const now = baseNow;

  return {
    from: toDateTimeLocalValue(new Date(now.getTime() - durationMs)),
    to: toDateTimeLocalValue(now),
  };
}

function defaultFilters(): FiltersState {
  return {
    from: "",
    to: "",
    leadPeriodBasis: "updated",
    search: "",
    sort: "last_received_at",
    direction: "desc",
    windowMode: "always",
    periodPreset: "always",
    product: [],
    bank: [],
    stage: [],
    proposalStatus: [],
    newcorbanStatus: [],
    inboxPhoneNumber: [],
    tags: [],
  };
}

function loadFilters(): FiltersState {
  const fallback = defaultFilters();
  if (typeof window === "undefined") return fallback;

  try {
    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "{}") as Partial<FiltersState> & {
      newcorbanFilter?: string;
    };

    const from = isValidDateTimeLocal(parsed.from) ? parsed.from : fallback.from;
    const to = isValidDateTimeLocal(parsed.to) ? parsed.to : fallback.to;
    const search = typeof parsed.search === "string" ? parsed.search : fallback.search;
    const leadPeriodBasis = parsed.leadPeriodBasis === "started" ? "started" : fallback.leadPeriodBasis;
    const sort =
      parsed.sort === "first_received_at" || parsed.sort === "last_received_at" || parsed.sort === "id"
        ? parsed.sort
        : fallback.sort;
    const windowMode =
      parsed.windowMode === "always" || parsed.windowMode === "fixed" || parsed.windowMode === "rolling"
        ? parsed.windowMode
        : fallback.windowMode;
    const periodPreset =
      parsed.periodPreset === "always" ||
      parsed.periodPreset === "today" ||
      parsed.periodPreset === "yesterday" ||
      parsed.periodPreset === "last7Days" ||
      parsed.periodPreset === "last30Days" ||
      parsed.periodPreset === "custom"
        ? parsed.periodPreset
        : windowMode === "always"
          ? "always"
          : "custom";
    const product = Array.isArray(parsed.product)
      ? parsed.product.filter((value): value is VendeaiProductValue => value === "clt" || value === "fgts")
      : parsed.product === "clt" || parsed.product === "fgts"
        ? [parsed.product]
        : fallback.product;
    const bank = Array.isArray(parsed.bank)
      ? parsed.bank.filter((value): value is string => typeof value === "string" && value !== "all")
      : typeof parsed.bank === "string" && parsed.bank !== "all"
        ? [parsed.bank]
        : fallback.bank;
    const stage = Array.isArray(parsed.stage)
      ? parsed.stage.filter((value): value is string => typeof value === "string" && value !== "all")
      : typeof parsed.stage === "string" && parsed.stage !== "all"
        ? [parsed.stage]
        : fallback.stage;
    const proposalStatus = Array.isArray(parsed.proposalStatus)
      ? parsed.proposalStatus.filter((value): value is string => typeof value === "string" && value !== "all")
      : typeof parsed.proposalStatus === "string" && parsed.proposalStatus !== "all"
        ? [parsed.proposalStatus]
        : fallback.proposalStatus;
    const newcorbanStatus: VendeaiNewcorbanStatusValue[] = Array.isArray(parsed.newcorbanStatus)
      ? parsed.newcorbanStatus.filter(
          (value): value is VendeaiNewcorbanStatusValue =>
            value === "not_sent" || value === "sent" || value === "success" || value === "failed"
        )
      : parsed.newcorbanStatus === "not_sent" ||
          parsed.newcorbanStatus === "sent" ||
          parsed.newcorbanStatus === "success" ||
          parsed.newcorbanStatus === "failed"
        ? [parsed.newcorbanStatus]
        : parsed.newcorbanFilter === "created" || parsed.newcorbanFilter === "sent"
          ? ["success"]
          : fallback.newcorbanStatus;
    const inboxPhoneNumber = Array.isArray(parsed.inboxPhoneNumber)
      ? parsed.inboxPhoneNumber.filter((value): value is string => typeof value === "string" && value !== "all")
      : typeof parsed.inboxPhoneNumber === "string" && parsed.inboxPhoneNumber !== "all"
        ? [parsed.inboxPhoneNumber]
        : fallback.inboxPhoneNumber;
    const tags = Array.isArray(parsed.tags) ? parsed.tags.filter((value): value is string => typeof value === "string") : fallback.tags;
    const direction = parsed.direction === "asc" ? "asc" : fallback.direction;

    const base = {
      search,
      leadPeriodBasis,
      sort,
      direction,
      product,
      bank,
      stage,
      proposalStatus,
      newcorbanStatus,
      inboxPhoneNumber,
      tags,
    };

    if (windowMode === "always") {
      return { from: "", to: "", windowMode, periodPreset: "always", ...base };
    }

    if (periodPreset !== "always" && periodPreset !== "custom") {
      const preset = presetRange(periodPreset);
      return { from: preset.from, to: preset.to, windowMode, periodPreset, ...base };
    }

    if (windowMode === "rolling") {
      const rolled = rollRangeToNow(from, to);
      return { from: rolled.from, to: rolled.to, windowMode, periodPreset, ...base };
    }

    return { from, to, windowMode, periodPreset, ...base };
  } catch {
    return fallback;
  }
}

function periodPresetLabel(value: PeriodPreset): string {
  if (value === "today") return "Hoje";
  if (value === "yesterday") return "Ontem";
  if (value === "last7Days") return "7 dias";
  if (value === "last30Days") return "30 dias";
  if (value === "custom") return "Personalizado";
  return "Sempre";
}

function leadPeriodBasisLabel(value: VendeaiLeadPeriodBasis): string {
  return value === "started" ? "Somente iniciadas no período" : "Atualizadas no período (inclui iniciadas)";
}

function parseUtcDateTimeString(value: string): Date | null {
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?$/.test(value) && !/[zZ]|[+-]\d{2}:\d{2}$/.test(value)) {
    const normalized = value.replace(" ", "T");
    const hasSeconds = normalized.length > 16;
    return new Date(`${normalized}${hasSeconds ? "" : ":00"}Z`);
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatDateTime(value: string | null): string {
  if (!value) return "-";
  const localParts = parseDateTimeLocalParts(value);
  if (localParts) return formatLocalParts(localParts);

  const parsed = parseUtcDateTimeString(value);
  return parsed ? brazilDateTimeFormatter.format(parsed) : "-";
}

function formatDate(value: string | null): string {
  if (!value) return "-";
  const dateOnly = parseDateOnlyParts(value);
  if (dateOnly) return `${pad(dateOnly.day)}/${pad(dateOnly.month)}/${pad(dateOnly.year, 4)}`;

  const parsed = parseUtcDateTimeString(value);
  return parsed ? brazilDateFormatter.format(parsed) : "-";
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

function canonicalBankValue(label: string): string {
  const normalized = label.toLowerCase().trim().replace(/ /g, "_");
  if (normalized === "mercantil_api") return "mercantil";
  if (normalized === "novo_saque_api") return "novo_saque";
  if (normalized === "soma_celcoin" || normalized === "soma_uy3") return "soma";
  if (normalized === "presença") return "presenca";
  return normalized;
}

function bankLabel(label: string): string {
  const normalized = canonicalBankValue(label);
  if (normalized === "mercantil") return "Mercantil";
  if (normalized === "presenca") return "Presença Bank";
  if (normalized === "facta") return "FACTA";
  if (normalized === "v8") return "V8";
  if (normalized === "pan") return "Banco PAN";
  if (normalized === "c6") return "C6 Bank";
  if (normalized === "hubcredito") return "Hub Crédito";
  if (normalized === "novo_saque") return "Novo Saque";
  if (normalized === "soma") return "Soma";
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
  if (normalized === "cross_sell" || normalized === "_cross_sell") return "Crossell";
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

function proposalStatusLabel(label: string | null): string {
  if (!label) return "-";
  const normalized = label.toLowerCase();
  if (normalized === NO_PROPOSAL_VALUE) return "Sem proposta";
  if (normalized === "formalization_requested") return "Pendente formalização";
  if (normalized === "liquidated_to_customer") return "Pago ao cliente";
  if (normalized === "waiting_risk_analysis") return "Aguardando análise de risco";
  if (normalized === "pended_bank") return "Pendente no banco";
  if (normalized === "processing") return "Em processamento";
  if (normalized === "pended_ai_handle") return "Pendente de atendimento";
  return label
    .toLowerCase()
    .replace(/_/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function newcorbanStatusLabel(value: VendeaiNewcorbanStatusFilter): string {
  if (value === "not_sent") return "Não enviada para a New Corban";
  if (value === "sent") return "Enviada para a New Corban";
  if (value === "success") return "Enviada com sucesso";
  if (value === "failed") return "Enviada com erro";
  return "Todas";
}

function summarizeSelected(values: string[], getLabel: (value: string) => string): string {
  return values.map((value) => getLabel(value)).join(", ");
}

function sortFieldLabel(value: VendeaiLeadSortField): string {
  if (value === "first_received_at") return "Primeiro evento";
  if (value === "last_received_at") return "Último evento";
  return "ID";
}

function DetailLine({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value || value === "-") return null;
  return (
    <div className="break-words text-xs leading-5 text-gray-600">
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
    <div className="min-w-[220px] space-y-1">
      <DetailLine label="Produto" value={productLabel(data.simulation_product || "-")} />
      <DetailLine label="Banco" value={bankLabel(data.simulation_bank || "-")} />
      <DetailLine label="Valor líquido" value={formatCurrency(data.simulation_liquid_value)} />
      <DetailLine label="Melhor valor líquido" value={data.simulation_best_liquid_value ? formatCurrency(data.simulation_best_liquid_value) : "-"} />
      <DetailLine label="Parcela" value={formatCurrency(data.simulation_installment_value)} />
      <DetailLine label="Parcelas" value={data.simulation_number_of_payments ? String(data.simulation_number_of_payments) : "-"} />
      <DetailLine label="Taxa mensal" value={data.simulation_monthly_fee ? `${String(data.simulation_monthly_fee)}%` : "-"} />
      <DetailLine label="Tabela" value={data.simulation_table_name || data.simulation_table_id || "-"} />
      <DetailLine label="Melhor tabela" value={data.simulation_best_table_id || "-"} />
      <DetailLine label="Data" value={formatDateTime(data.simulation_received_at)} />
    </div>
  );
}

function AttemptLabel({ number }: { number: number }) {
  return <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Proposta {number}</div>;
}

function AttemptStatusPill({ status }: { status: VendeaiLeadAttempt["status"] }) {
  const label = status === "success" ? "Criada" : status === "failed" ? "Falha" : "Pendente";
  const tone =
    status === "success"
      ? "border-emerald-200 bg-emerald-50 text-emerald-700"
      : status === "failed"
        ? "border-rose-200 bg-rose-50 text-rose-700"
        : "border-amber-200 bg-amber-50 text-amber-700";

  return <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function sortAttemptsOldestFirst(attempts: VendeaiLeadAttempt[]): VendeaiLeadAttempt[] {
  return [...attempts].sort((left, right) => {
    const leftTime = Date.parse(left.proposal.proposal_created_at || left.received_at || left.newcorban_sent_at || "");
    const rightTime = Date.parse(right.proposal.proposal_created_at || right.received_at || right.newcorban_sent_at || "");

    if (Number.isNaN(leftTime) && Number.isNaN(rightTime)) {
      return left.id - right.id;
    }

    if (Number.isNaN(leftTime)) return 1;
    if (Number.isNaN(rightTime)) return -1;
    if (leftTime === rightTime) return left.id - right.id;

    return leftTime - rightTime;
  });
}

function AttemptProposalCard({ attempt, number }: { attempt: VendeaiLeadAttempt; number: number }) {
  return (
    <div className="w-full min-w-[280px] max-w-[420px] rounded-lg border border-slate-200 bg-slate-50/40 px-3 py-3">
      <div className="mb-2 flex items-start justify-between gap-3">
        <div className="space-y-1">
          <AttemptLabel number={number} />
          <div className="text-sm font-semibold text-slate-900">{attempt.proposal.proposal_id || "-"}</div>
        </div>
      </div>
      <div className="grid grid-cols-1 gap-3 2xl:grid-cols-2">
        <div className="min-w-0 space-y-1 rounded-md border border-slate-200 bg-white/80 px-3 py-2.5">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Dados da proposta</div>
          <DetailLine label="Número" value={attempt.proposal.proposal_number || "-"} />
          <DetailLine label="Status" value={proposalStatusLabel(attempt.proposal.proposal_status)} />
          <DetailLine label="Produto" value={productLabel(attempt.proposal.proposal_product || "-")} />
          <DetailLine label="Banco" value={bankLabel(attempt.proposal.proposal_bank || "-")} />
          <DetailLine label="Valor líquido" value={formatCurrency(attempt.proposal.proposal_liquid_value)} />
          <DetailLine label="Valor bruto" value={formatCurrency(attempt.proposal.proposal_gross_value)} />
          <DetailLine label="Parcela" value={formatCurrency(attempt.proposal.proposal_installment_value)} />
          <DetailLine label="Parcelas" value={attempt.proposal.proposal_number_of_payments ? String(attempt.proposal.proposal_number_of_payments) : "-"} />
          <DetailLine label="Tabela" value={attempt.proposal.proposal_table_name || attempt.proposal.proposal_table_id || "-"} />
          {attempt.proposal.proposal_formalization_link ? (
            <div className="break-all text-xs text-blue-700">
              <a href={attempt.proposal.proposal_formalization_link} target="_blank" rel="noreferrer" className="font-medium hover:underline">
                Link de formalização
              </a>
            </div>
          ) : null}
          <DetailLine label="Criada em" value={formatDateTime(attempt.proposal.proposal_created_at)} />
        </div>
        <div className="min-w-0 space-y-1 rounded-md border border-[#16324a] bg-[#0b1b2a] px-3 py-2.5">
          <div className="flex items-start justify-between gap-2">
            <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-sky-100/80">
              <img src={newcorbanLogo} alt="NewCorban" className="h-4 w-auto shrink-0 object-contain" />
              <span>Envio NewCorban</span>
            </div>
            <AttemptStatusPill status={attempt.status} />
          </div>
          <div className="text-sm font-medium text-white">{attempt.newcorban_proposta_id || "Não criada"}</div>
          {attempt.newcorban_sent_at || attempt.received_at ? (
            <div className="break-words text-xs leading-5 text-slate-200">
              <span className="font-medium text-white">Enviada em:</span> {formatDateTime(attempt.newcorban_sent_at || attempt.received_at)}
            </div>
          ) : null}
          {attempt.newcorban_error ? <div className="pt-1 text-xs font-medium text-rose-300">{attempt.newcorban_error}</div> : null}
        </div>
      </div>
    </div>
  );
}

function ProposalSummaryDetails({
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
    <div className="w-full min-w-[280px] max-w-[420px] rounded-lg border border-slate-200 bg-slate-50/40 px-3 py-3">
      <div className="mb-2 space-y-1">
        <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Proposta</div>
        <div className="text-sm font-semibold text-slate-900">{data.proposal_id || "-"}</div>
      </div>
      <div className="grid grid-cols-1 gap-3 2xl:grid-cols-2">
        <div className="min-w-0 space-y-1 rounded-md border border-slate-200 bg-white/80 px-3 py-2.5">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Dados da proposta</div>
          <DetailLine label="Número" value={data.proposal_number || "-"} />
          <DetailLine label="Status" value={proposalStatusLabel(data.proposal_status)} />
          <DetailLine label="Status anterior" value={proposalStatusLabel(data.previous_proposal_status)} />
          <DetailLine label="Produto" value={productLabel(data.proposal_product || "-")} />
          <DetailLine label="Banco" value={bankLabel(data.proposal_bank || "-")} />
          <DetailLine label="Valor líquido" value={formatCurrency(data.proposal_liquid_value)} />
          <DetailLine label="Valor bruto" value={formatCurrency(data.proposal_gross_value)} />
          <DetailLine label="Parcela" value={formatCurrency(data.proposal_installment_value)} />
          <DetailLine label="Parcelas" value={data.proposal_number_of_payments ? String(data.proposal_number_of_payments) : "-"} />
          <DetailLine label="Tabela" value={data.proposal_table_name || data.proposal_table_id || "-"} />
          {data.proposal_formalization_link ? (
            <div className="break-all text-xs text-blue-700">
              <a href={data.proposal_formalization_link} target="_blank" rel="noreferrer" className="font-medium hover:underline">
                Link de formalização
              </a>
            </div>
          ) : null}
          <DetailLine label="Criada em" value={formatDateTime(data.proposal_created_at)} />
          <DetailLine label="Atualizada em" value={formatDateTime(data.proposal_status_updated_at)} />
        </div>
        <div className="min-w-0 space-y-1 rounded-md border border-dashed border-[#16324a] bg-[#0b1b2a] px-3 py-2.5">
          <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-sky-100/80">
            <img src={newcorbanLogo} alt="NewCorban" className="h-4 w-auto shrink-0 object-contain" />
            <span>Envio NewCorban</span>
          </div>
          <div className="text-sm text-slate-300">Nenhum envio registrado</div>
        </div>
      </div>
    </div>
  );
}

function OutOfPeriodAttemptsNotice({ count, receivedAt }: { count: number; receivedAt: string | null }) {
  if (count <= 0) return null;

  return (
    <div className="w-full min-w-[280px] max-w-[420px] rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-800">
      {count} proposta{count > 1 ? "s" : ""} fora do período filtrado
      {count === 1 && receivedAt ? `, criada em ${formatDateTime(receivedAt)}` : ""}
    </div>
  );
}

export default function IntegracoesVendeaiPage() {
  const initial = useMemo(() => loadFilters(), []);
  const [fromInput, setFromInput] = useState(initial.from);
  const [toInput, setToInput] = useState(initial.to);
  const [searchInput, setSearchInput] = useState(initial.search);
  const [leadPeriodBasisInput, setLeadPeriodBasisInput] = useState<VendeaiLeadPeriodBasis>(initial.leadPeriodBasis);
  const [windowModeInput, setWindowModeInput] = useState<FiltersState["windowMode"]>(initial.windowMode);
  const [periodPresetInput, setPeriodPresetInput] = useState<PeriodPreset>(initial.periodPreset);
  const [directionInput, setDirectionInput] = useState<VendeaiSortDirection>(initial.direction);
  const [productInput, setProductInput] = useState<VendeaiProductValue[]>(initial.product);
  const [bankInput, setBankInput] = useState<string[]>(initial.bank);
  const [stageInput, setStageInput] = useState<string[]>(initial.stage);
  const [proposalStatusInput, setProposalStatusInput] = useState<string[]>(initial.proposalStatus);
  const [newcorbanStatusInput, setNewcorbanStatusInput] = useState<VendeaiNewcorbanStatusValue[]>(initial.newcorbanStatus);
  const [inboxPhoneNumberInput, setInboxPhoneNumberInput] = useState<string[]>(initial.inboxPhoneNumber);
  const [tagsInput, setTagsInput] = useState<string[]>(initial.tags);
  const [applied, setApplied] = useState<FiltersState>(initial);
  const [leadsPage, setLeadsPage] = useState(1);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const [manualRefreshLockedUntil, setManualRefreshLockedUntil] = useState(0);
  const [nowTs, setNowTs] = useState(() => Date.now());
  const [isFiltersModalOpen, setIsFiltersModalOpen] = useState(false);
  const skipNextSearchAutoApplyRef = useRef(false);

  const rollingTick = applied.windowMode === "rolling" ? Math.floor(nowTs / AUTO_REFRESH_MS) : 0;
  const presetTick = applied.periodPreset !== "always" && applied.periodPreset !== "custom" ? Math.floor(nowTs / AUTO_REFRESH_MS) : 0;

  const effectiveRange = useMemo(() => {
    if (applied.periodPreset !== "always" && applied.periodPreset !== "custom") {
      return presetRange(applied.periodPreset, new Date(presetTick * AUTO_REFRESH_MS));
    }

    if (applied.windowMode === "rolling") {
      return rollRangeToNow(applied.from, applied.to, new Date(rollingTick * AUTO_REFRESH_MS));
    }

    return { from: applied.from, to: applied.to };
  }, [applied.from, applied.to, applied.windowMode, applied.periodPreset, rollingTick, presetTick]);

  useEffect(() => {
    if (periodPresetInput === "always" || periodPresetInput === "custom") return;

    const nextRange = presetRange(periodPresetInput, new Date(nowTs));
    setFromInput((current) => (current === nextRange.from ? current : nextRange.from));
    setToInput((current) => (current === nextRange.to ? current : nextRange.to));
  }, [periodPresetInput, nowTs]);

  const fromIso = useMemo(() => toUtcIso(effectiveRange.from), [effectiveRange.from]);
  const toIso = useMemo(() => toUtcIso(effectiveRange.to), [effectiveRange.to]);
  const manualRefreshRemaining = Math.max(0, Math.ceil((manualRefreshLockedUntil - nowTs) / 1000));

  useEffect(() => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(applied));
  }, [applied]);

  useEffect(() => {
    const timer = window.setInterval(() => setNowTs(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    if (skipNextSearchAutoApplyRef.current) {
      skipNextSearchAutoApplyRef.current = false;
      return;
    }

    const nextSearch = searchInput.trim();
    const timer = window.setTimeout(() => {
      setApplied((current) => {
        if (current.search === nextSearch) return current;
        return { ...current, search: nextSearch };
      });
      setLeadsPage(1);
    }, 350);

    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const sharedFilters = useMemo(
    () => ({
      from: fromIso,
      to: toIso,
      leadPeriodBasis: applied.leadPeriodBasis,
      product: applied.product,
      search: applied.search,
      bank: applied.bank,
      stage: applied.stage,
      proposalStatus: applied.proposalStatus,
      newcorbanStatus: applied.newcorbanStatus,
      inboxPhoneNumber: applied.inboxPhoneNumber,
      tags: applied.tags,
    }),
    [
      fromIso,
      toIso,
      applied.product,
      applied.leadPeriodBasis,
      applied.search,
      applied.bank,
      applied.stage,
      applied.proposalStatus,
      applied.newcorbanStatus,
      applied.inboxPhoneNumber,
      applied.tags,
    ]
  );

  const metricsQuery = useQuery({
    queryKey: ["vendeai:metrics", sharedFilters],
    queryFn: ({ signal }) => getVendeaiMetrics(sharedFilters, signal),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
    gcTime: 120_000,
    retry: 1,
    refetchInterval: AUTO_REFRESH_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  });

  const leadsQuery = useQuery({
    queryKey: ["vendeai:leads", leadsPage, sharedFilters],
    queryFn: ({ signal }) =>
      listVendeaiLeads(
        {
          page: leadsPage,
          perPage: 20,
          sort: applied.sort,
          direction: applied.direction,
          ...sharedFilters,
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

  const filterOptionsRange = useMemo(() => {
    if (periodPresetInput === "always" || windowModeInput === "always") {
      return { from: undefined, to: undefined };
    }

    if (periodPresetInput !== "custom") {
      const nextRange = presetRange(periodPresetInput);
      return { from: toUtcIso(nextRange.from), to: toUtcIso(nextRange.to) };
    }

    if (!isValidDateTimeLocal(fromInput) || !isValidDateTimeLocal(toInput)) {
      return { from: fromIso, to: toIso };
    }

    if (windowModeInput === "rolling") {
      const rolled = rollRangeToNow(fromInput, toInput);
      return { from: toUtcIso(rolled.from), to: toUtcIso(rolled.to) };
    }

    return { from: toUtcIso(fromInput), to: toUtcIso(toInput) };
  }, [periodPresetInput, windowModeInput, fromInput, toInput, fromIso, toIso]);

  const filterOptionsQuery = useQuery({
    queryKey: ["vendeai:filter-options", filterOptionsRange.from, filterOptionsRange.to, leadPeriodBasisInput, productInput],
    queryFn: ({ signal }) =>
      getVendeaiFilterOptions(
        {
          from: filterOptionsRange.from,
          to: filterOptionsRange.to,
          leadPeriodBasis: leadPeriodBasisInput,
          product: productInput,
        },
        signal
      ),
    enabled: isFiltersModalOpen,
    staleTime: 30_000,
    gcTime: 120_000,
    retry: 1,
  });

  const metrics = metricsQuery.data;
  const leads = leadsQuery.data?.data ?? [];
  const currentLeadsPage = leadsQuery.data?.current_page ?? leadsPage;
  const lastLeadsPage = leadsQuery.data?.last_page ?? 1;
  const totalLeads = leadsQuery.data?.total ?? 0;

  const bankOptions = useMemo(() => {
    const banks = filterOptionsQuery.data?.banks ?? [];
    const grouped = new Map<string, { value: string; label: string }>();

    banks.forEach((value) => {
      const canonical = canonicalBankValue(value);

      if (!grouped.has(canonical)) {
        grouped.set(canonical, { value: canonical, label: bankLabel(canonical) });
      }
    });

    return Array.from(grouped.values()).sort((left, right) => left.label.localeCompare(right.label, "pt-BR"));
  }, [filterOptionsQuery.data?.banks]);
  const stageOptions = useMemo(
    () => (filterOptionsQuery.data?.stages ?? []).map((value) => ({ value, label: stageLabel(value) })),
    [filterOptionsQuery.data?.stages]
  );
  const proposalStatusOptions = useMemo(
    () => (filterOptionsQuery.data?.proposal_statuses ?? []).map((value) => ({ value, label: proposalStatusLabel(value) })),
    [filterOptionsQuery.data?.proposal_statuses]
  );
  const inboxPhoneNumberOptions = useMemo(
    () =>
      (filterOptionsQuery.data?.inbox_phone_numbers ?? []).map((value) => ({
        value,
        label: formatPhone(value) || value,
      })),
    [filterOptionsQuery.data?.inbox_phone_numbers]
  );
  const tagOptions = filterOptionsQuery.data?.tags ?? [];

  const controlLabels = [
    `Base do período: ${leadPeriodBasisLabel(applied.leadPeriodBasis)}`,
    `Ordenação: ${sortFieldLabel(applied.sort)} · ${applied.direction === "desc" ? "Mais recentes" : "Mais antigos"}`,
  ];
  const filterLabels = [
    ...(applied.periodPreset === "always"
      ? []
      : [
          `Período: ${periodPresetLabel(applied.periodPreset)}`,
          `Conversas: ${leadPeriodBasisLabel(applied.leadPeriodBasis)}`,
          `Modo: ${applied.windowMode === "rolling" ? "Janela móvel" : "Intervalo fixo"}`,
          `De ${formatDateTime(fromIso ?? effectiveRange.from)}`,
          `Até ${formatDateTime(toIso ?? effectiveRange.to)}`,
        ]),
    ...(applied.search ? [`Busca: ${applied.search}`] : []),
    ...(applied.product.length ? [`Produto: ${summarizeSelected(applied.product, productLabel)}`] : []),
    ...(applied.bank.length ? [`Banco: ${summarizeSelected(applied.bank, bankLabel)}`] : []),
    ...(applied.stage.length ? [`Etapa: ${summarizeSelected(applied.stage, (value) => stageLabel(value))}`] : []),
    ...(applied.inboxPhoneNumber.length ? [`Número da IA: ${summarizeSelected(applied.inboxPhoneNumber, (value) => formatPhone(value) || value)}`] : []),
    ...(applied.proposalStatus.length ? [`Status da proposta: ${summarizeSelected(applied.proposalStatus, (value) => proposalStatusLabel(value))}`] : []),
    ...(applied.newcorbanStatus.length ? [`New Corban: ${summarizeSelected(applied.newcorbanStatus, (value) => newcorbanStatusLabel(value as VendeaiNewcorbanStatusFilter))}`] : []),
    ...(applied.tags.length ? [`Tags: ${applied.tags.join(", ")}`] : []),
  ];

  const applyFilters = (): boolean => {
    const nextBase = {
      search: searchInput.trim(),
      leadPeriodBasis: leadPeriodBasisInput,
      sort: applied.sort,
      direction: directionInput,
      windowMode: windowModeInput,
      periodPreset: periodPresetInput,
      product: productInput,
      bank: bankInput,
      stage: stageInput,
      proposalStatus: proposalStatusInput,
      newcorbanStatus: newcorbanStatusInput,
      inboxPhoneNumber: inboxPhoneNumberInput,
      tags: tagsInput,
    };

    if (windowModeInput === "always" || periodPresetInput === "always") {
      setRangeError(null);
      setApplied({ from: "", to: "", ...nextBase, windowMode: "always", periodPreset: "always" });
      setLeadsPage(1);
      return true;
    }

    if (periodPresetInput !== "custom") {
      const nextRange = presetRange(periodPresetInput);
      setRangeError(null);
      setFromInput(nextRange.from);
      setToInput(nextRange.to);
      setApplied({ from: nextRange.from, to: nextRange.to, ...nextBase });
      setLeadsPage(1);
      return true;
    }

    const fromDate = parseBrazilDateTimeLocalToUtcDate(fromInput);
    const toDate = parseBrazilDateTimeLocalToUtcDate(toInput);

    if (!fromInput || !toInput || fromDate === null || toDate === null) {
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

    setApplied({ from: nextFrom, to: nextTo, ...nextBase, periodPreset: "custom" });
    setLeadsPage(1);
    return true;
  };

  const handleApplyFilters = () => {
    if (applyFilters()) setIsFiltersModalOpen(false);
  };

  const handleFromInputChange = (value: string) => {
    if (periodPresetInput !== "custom") setPeriodPresetInput("custom");
    setFromInput(value);
  };

  const handleToInputChange = (value: string) => {
    if (periodPresetInput !== "custom") setPeriodPresetInput("custom");
    setToInput(value);
  };

  const applyPeriodPreset = (preset: PeriodPreset) => {
    setPeriodPresetInput(preset);

    if (preset === "always") {
      setWindowModeInput("always");
      setFromInput("");
      setToInput("");
      setRangeError(null);
      return;
    }

    if (preset === "custom") {
      if (windowModeInput === "always") setWindowModeInput("fixed");
      setRangeError(null);
      return;
    }

    const next = presetRange(preset);
    if (windowModeInput === "always") setWindowModeInput("fixed");
    setFromInput(next.from);
    setToInput(next.to);
    setRangeError(null);
  };

  const clearFilters = () => {
    const defaults = defaultFilters();
    setFromInput(defaults.from);
    setToInput(defaults.to);
    setSearchInput(defaults.search);
    setLeadPeriodBasisInput(defaults.leadPeriodBasis);
    setWindowModeInput(defaults.windowMode);
    setPeriodPresetInput(defaults.periodPreset);
    setDirectionInput(defaults.direction);
    setProductInput(defaults.product);
    setBankInput(defaults.bank);
    setStageInput(defaults.stage);
    setProposalStatusInput(defaults.proposalStatus);
    setNewcorbanStatusInput(defaults.newcorbanStatus);
    setInboxPhoneNumberInput(defaults.inboxPhoneNumber);
    setTagsInput(defaults.tags);
    setRangeError(null);
    setApplied(defaults);
    setLeadsPage(1);
  };

  const handleSortChange = (value: "first_desc" | "first_asc" | "last_desc" | "last_asc") => {
    const nextSort = value.startsWith("first") ? "first_received_at" : "last_received_at";
    const nextDirection = value.endsWith("_asc") ? "asc" : "desc";
    setDirectionInput(nextDirection);
    setApplied((current) =>
      current.sort === nextSort && current.direction === nextDirection
        ? current
        : { ...current, sort: nextSort, direction: nextDirection }
    );
    setLeadsPage(1);
  };

  const resetModalFilters = () => {
    skipNextSearchAutoApplyRef.current = true;
    setFromInput("");
    setToInput("");
    setSearchInput("");
    setLeadPeriodBasisInput("updated");
    setWindowModeInput("always");
    setPeriodPresetInput("always");
    setProductInput([]);
    setBankInput([]);
    setStageInput([]);
    setProposalStatusInput([]);
    setNewcorbanStatusInput([]);
    setInboxPhoneNumberInput([]);
    setTagsInput([]);
    setRangeError(null);
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
      const { token } = await startVendeaiExport("leads", sharedFilters);

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
        modeLabel="Filtros e Visualização"
        filteredCount={totalLeads}
        countLabel="leads filtrados"
        searchValue={searchInput}
        searchPlaceholder="Nome, CPF, telefone, chat ou proposta"
        exportLabel="CSV filtrado"
        exportLoading={exporting}
        exportIcon="file"
        isRefreshing={metricsQuery.isFetching || leadsQuery.isFetching}
        refreshCountdown={manualRefreshRemaining}
        sortValue={`${applied.direction === "asc" ? (applied.sort === "first_received_at" ? "first_asc" : "last_asc") : applied.sort === "first_received_at" ? "first_desc" : "last_desc"}`}
        controlLabels={controlLabels}
        filterLabels={filterLabels}
        hasActiveFilters={filterLabels.length > 0}
        onSearchChange={setSearchInput}
        onSortChange={handleSortChange}
        onFilterClick={() => setIsFiltersModalOpen(true)}
        onExportClick={() => void exportCsv()}
        onRefreshClick={handleManualRefresh}
        onClearFilters={clearFilters}
      />

      <VendeaiFiltersModal
        isOpen={isFiltersModalOpen}
        title="Filtros dos leads VendeAI"
        subtitle="Ajuste visualização, busca, conversa, proposta VendeAI e New Corban."
        from={fromInput}
        to={toInput}
        search={searchInput}
        leadPeriodBasis={leadPeriodBasisInput}
        windowMode={windowModeInput}
        periodPreset={periodPresetInput}
        product={productInput}
        bank={bankInput}
        stage={stageInput}
        proposalStatus={proposalStatusInput}
        newcorbanStatus={newcorbanStatusInput}
        inboxPhoneNumber={inboxPhoneNumberInput}
        tags={tagsInput}
        bankOptions={bankOptions}
        stageOptions={stageOptions}
        proposalStatusOptions={proposalStatusOptions}
        inboxPhoneNumberOptions={inboxPhoneNumberOptions}
        tagOptions={tagOptions}
        windowModeOptions={windowModeOptions}
        rangeError={rangeError}
        onClose={() => setIsFiltersModalOpen(false)}
        onSearchChange={setSearchInput}
        onLeadPeriodBasisChange={setLeadPeriodBasisInput}
        onFromChange={handleFromInputChange}
        onToChange={handleToInputChange}
        onWindowModeChange={setWindowModeInput}
        onProductChange={setProductInput}
        onBankChange={setBankInput}
        onStageChange={setStageInput}
        onProposalStatusChange={setProposalStatusInput}
        onNewcorbanStatusChange={setNewcorbanStatusInput}
        onInboxPhoneNumberChange={setInboxPhoneNumberInput}
        onTagsChange={setTagsInput}
        onPeriodPresetChange={applyPeriodPreset}
        onClearFilters={resetModalFilters}
        onApply={handleApplyFilters}
      />

      <div className="space-y-4">
        {metricsQuery.isLoading && (
          <div className="flex items-center text-sm text-gray-500">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" /> Carregando resumo de métricas...
          </div>
        )}

        {metricsQuery.isError && (
          <div className="flex items-center text-sm text-red-600">
            <AlertCircle className="mr-2 h-4 w-4" /> Não foi possível carregar as métricas do período.
          </div>
        )}

        {!metricsQuery.isLoading && !metricsQuery.isError && metrics && (
          <div className="space-y-4">
            <Card className="p-5 shadow-sm">
              <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Resumo Financeiro</h3>
              <div className="grid grid-cols-1 gap-6 md:grid-cols-3 md:divide-x md:divide-gray-100">
                <div className="flex flex-col">
                  <span className="text-sm font-medium text-gray-500">Ofertado</span>
                  <span className="mt-1 text-3xl font-bold text-slate-700">{brMoney.format(metrics.leads.offered_total ?? 0)}</span>
                </div>
                <div className="flex flex-col md:pl-6">
                  <span className="text-sm font-medium text-gray-500">Total digitado</span>
                  <span className="mt-1 text-3xl font-bold text-blue-600">{brMoney.format(metrics.leads.typed_total ?? 0)}</span>
                </div>
                <div className="flex flex-col md:pl-6">
                  <span className="text-sm font-medium text-gray-500">Total pago (produção)</span>
                  <span className="mt-1 text-3xl font-bold text-emerald-600">{brMoney.format(metrics.leads.paid_total ?? 0)}</span>
                </div>
              </div>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Card className="flex flex-col justify-between p-5 shadow-sm">
                <div>
                  <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Conversas com a IA (VendeAI)</h3>
                  <div className="flex items-center gap-4 sm:gap-6">
                    <div className="flex flex-col">
                      <span className="text-2xl font-bold leading-none text-blue-600">{formatNumber(metrics.leads.total)}</span>
                      <span className="mt-1 text-xs font-medium uppercase text-gray-500">
                        {applied.leadPeriodBasis === "started" ? "Iniciadas" : "Atualizadas"}
                      </span>
                    </div>
                    <div className="hidden h-6 w-px bg-gray-200 sm:block" />
                    <div className="flex flex-col">
                      <span className="text-2xl font-bold leading-none text-slate-700">{formatNumber(metrics.leads.started_total ?? 0)}</span>
                      <span className="mt-1 text-xs font-medium uppercase text-gray-500">Iniciadas</span>
                    </div>
                  </div>
                </div>

                {metrics.leads.by_product?.length ? (
                  <div className="mt-4 flex flex-wrap gap-2">
                    {metrics.leads.by_product.map((item) => (
                      <div key={item.label} className="flex items-center gap-1.5 rounded-md border border-gray-100 bg-gray-50 px-2 py-1">
                        <span className="text-xs font-medium uppercase text-gray-500">{productLabel(item.label)}</span>
                        <span className="text-xs font-bold text-gray-700">{formatNumber(item.total)}</span>
                      </div>
                    ))}
                  </div>
                ) : null}
              </Card>

              <Card className="flex flex-col justify-between p-5 shadow-sm">
                <div>
                  <h3 className="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Criação de Propostas (NewCorban)</h3>
                  <div className="flex items-center gap-4 sm:gap-6">
                    <div className="flex flex-col">
                      <span className="text-2xl font-bold leading-none text-gray-700">{formatNumber(metrics.attempts.total)}</span>
                      <span className="mt-1 text-xs font-medium uppercase text-gray-500">Enviadas</span>
                    </div>
                    <div className="hidden h-6 w-px bg-gray-200 sm:block" />
                    <div className="flex flex-col">
                      <span className="text-2xl font-bold leading-none text-emerald-600">{formatNumber(metrics.attempts.success)}</span>
                      <span className="mt-1 text-xs font-medium uppercase text-emerald-700">Criadas</span>
                    </div>
                    <div className="hidden h-6 w-px bg-gray-200 sm:block" />
                    <div className="flex flex-col">
                      <span className="text-2xl font-bold leading-none text-rose-600">{formatNumber(metrics.attempts.failed)}</span>
                      <span className="mt-1 text-xs font-medium uppercase text-rose-700">Falhas</span>
                    </div>
                  </div>
                </div>

                {metrics.attempts.by_product?.length ? (
                  <div className="mt-4 flex flex-wrap gap-2">
                    {metrics.attempts.by_product.map((item) => (
                      <div key={item.label} className="flex items-center gap-1.5 rounded-md border border-gray-100 bg-gray-50 px-2 py-1">
                        <span className="text-xs font-medium uppercase text-gray-500">{productLabel(item.label)}</span>
                        <span className="text-xs font-bold text-gray-700">{formatNumber(item.total)}</span>
                      </div>
                    ))}
                  </div>
                ) : null}
              </Card>
            </div>
          </div>
        )}
      </div>

      <div>
        <div className="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
          <div>
            <h2 className="text-lg font-semibold text-foreground">Leads VendeAI</h2>
            <p className="text-sm text-muted-foreground">{`${leads.length} nesta página • ${formatNumber(totalLeads)} no total filtrado`}</p>
          </div>
          <div className="flex items-center gap-2 text-sm text-gray-500">
            {leadsQuery.isFetching ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <span className="h-2 w-2 rounded-full bg-emerald-500" />}
            {leadsQuery.isFetching ? "Atualizando..." : "Atualiza automaticamente a cada 60s"}
          </div>
        </div>

        <Card className="flex flex-col overflow-hidden border border-gray-200 shadow-sm">
          <div className="relative max-h-[600px] w-full overflow-auto">
            <table className="w-full text-sm">
              <thead className="sticky top-0 z-10 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 shadow-sm">
                <tr>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">CPF</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Nome</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Nascimento</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Telefone</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Número IA</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Chat</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Etapa</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Tags</th>
                  <th className="min-w-[200px] whitespace-nowrap px-4 py-3 text-left font-medium">Dados da simulação</th>
                  <th className="min-w-[300px] whitespace-nowrap px-4 py-3 text-left font-medium">Propostas</th>
                  <th className="whitespace-nowrap px-4 py-3 text-left font-medium">Eventos</th>
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
                  leads.map((lead) => {
                    const sortedAttempts = lead.newcorban_attempts?.length ? sortAttemptsOldestFirst(lead.newcorban_attempts) : [];
                    const numberedAttempts = sortedAttempts.map((attempt, index) => ({
                      attempt,
                      originalNumber: attempt.original_number ?? index + 1,
                    }));

                    return (
                        <tr key={lead.id} className="align-top transition-colors duration-150 hover:bg-gray-50">
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{formatCPF(lead.customer_cpf)}</td>
                          <td className="min-w-[180px] px-4 py-3 font-medium text-gray-900">{lead.customer_name || "-"}</td>
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{formatDate(lead.customer_birth_date)}</td>
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{formatPhone(lead.customer_phone)}</td>
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{formatPhone(lead.inbox_phone_number)}</td>
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{lead.chat_id || "-"}</td>
                          <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{stageLabel(lead.stage)}</td>
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
                            <SimulationDetails data={lead} />
                          </td>
                          <td className="px-4 py-3">
                            {sortedAttempts.length ? (
                              <div className="min-w-[280px] max-w-[420px] space-y-3">
                                {numberedAttempts.map(({ attempt, originalNumber }) => (
                                  <AttemptProposalCard key={attempt.id} attempt={attempt} number={originalNumber} />
                                ))}
                                <OutOfPeriodAttemptsNotice
                                  count={lead.newcorban_attempts_out_of_period_count ?? 0}
                                  receivedAt={lead.newcorban_attempts_out_of_period_received_at ?? null}
                                />
                              </div>
                            ) : (lead.newcorban_attempts_out_of_period_count ?? 0) > 0 ? (
                              <OutOfPeriodAttemptsNotice
                                count={lead.newcorban_attempts_out_of_period_count ?? 0}
                                receivedAt={lead.newcorban_attempts_out_of_period_received_at ?? null}
                              />
                            ) : (
                              <ProposalSummaryDetails
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
                            )}
                          </td>
                          <td className="whitespace-nowrap px-4 py-3">
                            <div className="font-medium text-blue-700">Último: {formatDateTime(lead.last_received_at)}</div>
                            <div className="text-xs text-gray-500">Primeiro: {formatDateTime(lead.first_received_at)}</div>
                          </td>
                        </tr>
                    );
                  })
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
