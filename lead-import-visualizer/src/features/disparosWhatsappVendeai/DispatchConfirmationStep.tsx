import {
  Check,
  ClipboardList,
  Clock3,
  Send,
  ShieldCheck,
  Users,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { type CampaignProduct, type OfficialInbox, type OfficialTemplate } from "./configurationFixtures";
import { cn } from "@/lib/utils";
import { formatPhone } from "@/lib/formatters";

type LeadSource = "pasted_numbers" | "registered_leads";
type SenderConfiguration = {
  inboxId: string;
  templateIds: string[];
  sendLimitEnabled: boolean;
  maxSends: string;
};
type DispatchConfiguration = {
  product: CampaignProduct | "";
  campaign: string;
  senders: SenderConfiguration[];
  intervalSeconds: string;
  startMode: "now" | "scheduled";
  scheduledAt: string;
  resendProtectionEnabled: boolean;
  resendProtectionDays: string;
  templateHeaders: Record<string, string>;
};

const PANEL_CLASS_NAME = "rounded-lg border border-gray-200 bg-white shadow-sm";
const productLabels: Record<CampaignProduct, string> = {
  clt: "Crédito do Trabalhador",
  fgts: "FGTS",
};
const templateStatusLabel = (status: string) => (({ APPROVED: "Aprovado", PAUSED: "Pausado", DISABLED: "Desativado" } as Record<string, string>)[status] ?? status) || "Sem status";

function SectionHeading({
  number,
  title,
  description,
}: {
  number: string;
  title: string;
  description: string;
}) {
  return (
    <div>
      <div className="flex items-center gap-2">
        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">
          {number}
        </span>
        <h2 className="text-base font-semibold text-gray-900">{title}</h2>
      </div>
      <p className="mt-1 text-sm text-gray-600">{description}</p>
    </div>
  );
}

function SectionLabel({
  icon,
  title,
  description,
}: {
  icon: React.ReactNode;
  title: string;
  description: string;
}) {
  return (
    <div className="flex gap-3">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-700">
        {icon}
      </span>
      <div>
        <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
        <p className="mt-1 text-sm text-gray-600">{description}</p>
      </div>
    </div>
  );
}

function selectedTemplates(
  configuration: DispatchConfiguration,
  inboxes: OfficialInbox[],
): OfficialTemplate[] {
  const templates = new Map<string, OfficialTemplate>();
  configuration.senders.forEach((sender) =>
    inboxes.find((inbox) => inbox.id === sender.inboxId)
      ?.templates.filter((template) => sender.templateIds.includes(template.id))
      .forEach((template) => templates.set(template.id, template)),
  );
  return [...templates.values()];
}

function formatSchedule(configuration: DispatchConfiguration): string {
  if (configuration.startMode === "now") return "Início imediato";
  return configuration.scheduledAt
    ? new Intl.DateTimeFormat("pt-BR", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(configuration.scheduledAt))
    : "Agendamento pendente";
}

export function DispatchConfirmationStep({
  source,
  recipientCount,
  configuration,
  inboxes,
  acknowledged,
  onAcknowledgedChange,
}: {
  source: LeadSource;
  recipientCount: number;
  configuration: DispatchConfiguration;
  inboxes: OfficialInbox[];
  acknowledged: boolean;
  onAcknowledgedChange: (checked: boolean) => void;
}) {
  const templates = selectedTemplates(configuration, inboxes);

  return (
    <section className="space-y-5">
      <SectionHeading
        number="3"
        title="Revisar e confirmar"
        description="Confira os pontos essenciais. Para corrigir algo, volte à etapa anterior."
      />
      <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
        <SectionLabel
          icon={<ClipboardList className="h-4 w-4" />}
          title="Resumo da campanha"
          description="Visão rápida antes de liberar o disparo."
        />
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <SummaryItem
            icon={<Users className="h-4 w-4" />}
            label="Destinatários"
            value={recipientCount.toLocaleString("pt-BR")}
            detail={
              source === "registered_leads"
                ? "Leads cadastrados"
                : "Lista de números"
            }
          />
          <SummaryItem
            icon={<Send className="h-4 w-4" />}
            label="Remetentes"
            value={String(configuration.senders.length)}
            detail={`${templates.length} template(s) selecionado(s)`}
          />
          <SummaryItem
            icon={<Clock3 className="h-4 w-4" />}
            label="Início"
            value={configuration.startMode === "now" ? "Agora" : "Agendado"}
            detail={formatSchedule(configuration)}
          />
        </div>
        <dl className="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-gray-500">Produto</dt>
            <dd className="mt-1 font-medium text-gray-900">
              {configuration.product
                ? productLabels[configuration.product]
                : "Não informado"}
            </dd>
          </div>
          <div>
            <dt className="text-gray-500">Campanha</dt>
            <dd className="mt-1 font-medium text-gray-900">
              {configuration.campaign || "Sem identificação"}
            </dd>
          </div>
        </dl>
      </section>
      <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
        <SectionLabel
          icon={<Send className="h-4 w-4" />}
          title="Remetentes e mensagens"
          description="Distribuição que será usada no disparo."
        />
        <div className="mt-4 divide-y divide-gray-100">
          {configuration.senders.map((sender) => {
            const inbox = inboxes.find(
              (item) => item.id === sender.inboxId,
            );
            if (!inbox) return null;
            const senderTemplates = inbox.templates.filter((template) =>
              sender.templateIds.includes(template.id),
            );
            return (
              <div key={sender.inboxId} className="py-3 first:pt-0 last:pb-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-semibold text-gray-900">
                    {inbox.name}
                  </span>
                  <Badge variant="outline" className="border-slate-200 bg-slate-50 text-slate-700">
                    Qualidade não consultada
                  </Badge>
                  <span className="text-xs text-gray-500">
                    {formatPhone(inbox.phoneNumber)}
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-2">
                  {senderTemplates.map((template) => (
                    <div key={template.id} className="rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700">
                      <span>{template.name} · Status: {templateStatusLabel(template.status)}</span>
                      {template.headerType ? <span className="mt-1 block text-gray-500">Cabeçalho: {configuration.templateHeaders[template.id] || "Pendente"}</span> : null}
                    </div>
                  ))}
                </div>
                <p className="mt-2 text-xs text-gray-600">
                  {sender.sendLimitEnabled
                    ? `Limite: ${Number(sender.maxSends).toLocaleString("pt-BR")} envios`
                    : "Sem limite individual"}
                </p>
              </div>
            );
          })}
        </div>
      </section>
      <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
        <SectionLabel
          icon={<ShieldCheck className="h-4 w-4" />}
          title="Regras de envio"
          description="Estas regras serão aplicadas durante a execução."
        />
        <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
          <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
            <span className="text-gray-500">Intervalo</span>
            <strong className="mt-1 block text-gray-900">
              {configuration.intervalSeconds} segundos entre mensagens
            </strong>
          </div>
          <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-3">
            <span className="text-gray-500">Proteção contra reenvio</span>
            <strong className="mt-1 block text-gray-900">
              {configuration.resendProtectionEnabled
                ? `${configuration.resendProtectionDays} dias sem novo contato`
                : "Não ativada"}
            </strong>
          </div>
        </div>
      </section>
      <label
        className={cn(
          "flex cursor-pointer items-start gap-3 rounded-lg border p-4 shadow-sm transition-colors",
          acknowledged
            ? "border-blue-500 bg-blue-50"
            : "border-gray-200 bg-white hover:border-blue-300",
        )}
      >
        <Checkbox
          checked={acknowledged}
          onCheckedChange={(value) => onAcknowledgedChange(Boolean(value))}
          className="mt-0.5 border-blue-300 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600 data-[state=checked]:text-white"
        />
        <span>
          <span className="block text-sm font-semibold text-gray-900">
            Revisei a base, os remetentes e as regras de envio.
          </span>
          <span className="mt-1 block text-sm text-gray-600">
            Esta confirmação apenas revisa a configuração; o envio ainda não será iniciado.
          </span>
        </span>
      </label>
    </section>
  );
}

function SummaryItem({
  icon,
  label,
  value,
  detail,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  detail: string;
}) {
  return (
    <div className="rounded-md border border-gray-200 bg-white px-3 py-3">
      <span className="flex h-7 w-7 items-center justify-center rounded-md bg-blue-50 text-blue-700">
        {icon}
      </span>
      <div className="mt-3 text-xs text-gray-600">{label}</div>
      <div className="mt-0.5 text-lg font-semibold text-gray-900">{value}</div>
      <div className="mt-1 truncate text-xs text-gray-500">{detail}</div>
    </div>
  );
}
