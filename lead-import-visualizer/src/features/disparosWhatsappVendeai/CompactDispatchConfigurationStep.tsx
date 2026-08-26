import { useEffect, useState, type Dispatch, type SetStateAction } from "react";
import { ChevronDown, Clock3, Image, MessageSquareText, RefreshCw, Send, Type } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { type OfficialInbox, type OfficialTemplate } from "./configurationFixtures";
import { formatPhone } from "@/lib/formatters";
import { cn } from "@/lib/utils";

const PANEL_CLASS_NAME = "rounded-lg border border-slate-300 bg-slate-50 shadow-sm";

type LeadSource = "pasted_numbers" | "registered_leads";
type ParameterSource = "fixed" | "lead_field";
type SenderConfiguration = {
  inboxId: string;
  templateIds: string[];
  sendLimitEnabled: boolean;
  maxSends: string;
};
type DispatchConfiguration = {
  senders: SenderConfiguration[];
  intervalSeconds: string;
  startMode: "now" | "scheduled";
  scheduledAt: string;
  resendProtectionEnabled: boolean;
  resendProtectionDays: string;
  sendWindowEnabled: boolean;
  sendWindowStart: string;
  sendWindowEnd: string;
  templateParameters: Record<
    string,
    Record<string, { source: ParameterSource; value: string }>
  >;
  templateHeaders: Record<string, string>;
};

const selectedTemplates = (configuration: DispatchConfiguration, inboxes: OfficialInbox[]) => {
  const templates = new Map<string, OfficialTemplate>();
  configuration.senders.forEach((sender) =>
    inboxes.find((inbox) => inbox.id === sender.inboxId)
      ?.templates.filter((template) => sender.templateIds.includes(template.id))
      .forEach((template) => templates.set(template.id, template)),
  );
  return [...templates.values()];
};
const templateStatusStyles = (status: string) => ({
  label: (({ APPROVED: "Aprovado", PAUSED: "Pausado", DISABLED: "Desativado" } as Record<string, string>)[status] ?? status) || "Sem status",
  className: status === "APPROVED" ? "border-emerald-200 bg-emerald-50 text-emerald-800" : status === "PAUSED" ? "border-amber-200 bg-amber-50 text-amber-800" : status === "DISABLED" ? "border-red-200 bg-red-50 text-red-800" : "border-slate-200 bg-slate-50 text-slate-700",
});

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <Label className="text-sm font-medium text-gray-700">{label}</Label>
      {children}
    </div>
  );
}

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

export function CompactDispatchConfigurationStep({
  source,
  recipientCount,
  configuration,
  setConfiguration,
  inboxes,
  onRefresh,
  isRefreshing,
  validationErrors,
}: {
  source: LeadSource;
  recipientCount: number;
  configuration: DispatchConfiguration;
  setConfiguration: Dispatch<SetStateAction<DispatchConfiguration>>;
  inboxes: OfficialInbox[];
  onRefresh: () => void;
  isRefreshing: boolean;
  validationErrors: string[];
}) {
  const templates = selectedTemplates(configuration, inboxes);
  const configurableTemplates = templates.filter((template) => template.parameters.length > 0 || template.headerType);
  const canUseLeadFields = source === "registered_leads";
  const inboxesWithTemplates = inboxes.filter((inbox) => inbox.templates.length > 0);
  const inboxesWithoutTemplates = inboxes.filter((inbox) => inbox.templates.length === 0);
  const [showEmptyInboxes, setShowEmptyInboxes] = useState(false);
  const allSendersHaveLimits = configuration.senders.length > 0 && configuration.senders.every((sender) => sender.sendLimitEnabled && Number.isInteger(Number(sender.maxSends)) && Number(sender.maxSends) > 0);
  const totalCapacity = configuration.senders.reduce((total, sender) => total + (Number(sender.maxSends) || 0), 0);
  const senderErrors = validationErrors.filter((error) => /número remetente|template para cada número/i.test(error));
  const contentErrors = validationErrors.filter((error) => /variáveis|cabeçalho/i.test(error));
  const deliveryErrors = validationErrors.filter((error) => /intervalo|data e hora|janela|reenvio/i.test(error));

  useEffect(() => {
    if (canUseLeadFields) return;

    setConfiguration((current) => {
      let changed = false;
      const templateParameters = Object.fromEntries(
        Object.entries(current.templateParameters).map(([templateId, parameters]) => [
          templateId,
          Object.fromEntries(Object.entries(parameters).map(([key, parameter]) => {
            if (parameter.source !== "lead_field") return [key, parameter];
            changed = true;
            return [key, { source: "fixed" as const, value: "" }];
          })),
        ]),
      );

      return changed ? { ...current, templateParameters } : current;
    });
  }, [canUseLeadFields, setConfiguration]);
  const leadFields =
    source === "registered_leads"
      ? [
          { value: "name", label: "Nome" },
          { value: "cpf", label: "CPF" },
          { value: "birth_date", label: "Data de nascimento" },
          { value: "phone", label: "Telefone" },
        ]
      : [{ value: "phone", label: "Telefone" }];
  const toggleSender = (inbox: OfficialInbox) =>
    setConfiguration((current) =>
      current.senders.some((sender) => sender.inboxId === inbox.id)
        ? {
            ...current,
            senders: current.senders.filter(
              (sender) => sender.inboxId !== inbox.id,
            ),
          }
        : {
            ...current,
            senders: [
              ...current.senders,
              {
                inboxId: inbox.id,
                templateIds: [],
                sendLimitEnabled: false,
                maxSends: "",
              },
            ],
          },
    );
  const updateSender = (
    inboxId: string,
    update: (sender: SenderConfiguration) => SenderConfiguration,
  ) =>
    setConfiguration((current) => ({
      ...current,
      senders: current.senders.map((sender) =>
        sender.inboxId === inboxId ? update(sender) : sender,
      ),
    }));
  const toggleTemplate = (inboxId: string, template: OfficialTemplate) =>
    setConfiguration((current) => {
      if (template.status !== "APPROVED") return current;
      const sender = current.senders.find((item) => item.inboxId === inboxId);
      if (!sender) return current;
      const selected = sender.templateIds.includes(template.id);
      return {
        ...current,
        senders: current.senders.map((item) =>
          item.inboxId === inboxId
            ? {
                ...item,
                templateIds: selected
                  ? item.templateIds.filter((id) => id !== template.id)
                  : [...item.templateIds, template.id],
              }
            : item,
        ),
        templateParameters: selected
          ? current.templateParameters
          : {
              ...current.templateParameters,
              [template.id]:
                current.templateParameters[template.id] ??
                Object.fromEntries(
                  template.parameters.map((parameter) => [
                    parameter.key,
                    { source: "fixed" as const, value: "" },
                  ]),
                ),
            },
        templateHeaders: selected
          ? current.templateHeaders
          : {
              ...current.templateHeaders,
              [template.id]: current.templateHeaders[template.id] ?? template.headerText ?? "",
            },
      };
    });
  const updateParameter = (
    templateId: string,
    key: string,
    update: Partial<{ source: ParameterSource; value: string }>,
  ) =>
    setConfiguration((current) => ({
      ...current,
      templateParameters: {
        ...current.templateParameters,
        [templateId]: {
          ...current.templateParameters[templateId],
          [key]: {
            ...current.templateParameters[templateId]?.[key],
            ...update,
          },
        },
      },
    }));
  const updateTemplateHeader = (templateId: string, value: string) =>
    setConfiguration((current) => ({
      ...current,
      templateHeaders: { ...current.templateHeaders, [templateId]: value },
    }));

  return (
    <section className="space-y-5">
      <div className="flex items-start justify-between gap-3">
        <SectionHeading
          number="2"
          title="Configurar disparo"
          description="Defina quem envia, qual mensagem usar e quando iniciar."
        />
        <Badge
          variant="outline"
          className="border-blue-200 bg-blue-50 text-blue-800"
        >
          {recipientCount.toLocaleString("pt-BR")} destinatários
        </Badge>
      </div>
      <div className="space-y-4">
        <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
          <div className="flex items-start justify-between gap-3">
            <SectionLabel
              icon={<Send className="h-4 w-4" />}
              title="Remetentes e templates"
              description="Escolha os números e a mensagem disponível para cada um. A divisão será automática."
            />
            <Button variant="outline" size="sm" onClick={onRefresh} disabled={isRefreshing}>
              <RefreshCw className={cn("mr-1.5 h-3.5 w-3.5", isRefreshing && "animate-spin")} />
              Atualizar
            </Button>
          </div>
          <div className="mt-4 divide-y divide-gray-100">
            {inboxesWithTemplates.map((inbox) => {
              const sender = configuration.senders.find(
                (item) => item.inboxId === inbox.id,
              );
              return (
                <div
                  key={inbox.id}
                  className={cn(
                    "px-4 py-3",
                    sender && "bg-blue-50/30",
                  )}
                >
                  <div className="flex items-center gap-3">
                    <Checkbox
                      id={`compact-sender-${inbox.id}`}
                      checked={Boolean(sender)}
                      onCheckedChange={() => toggleSender(inbox)}
                      className="border-blue-600 data-[state=checked]:border-blue-600 data-[state=checked]:bg-blue-600 data-[state=checked]:text-white"
                    />
                    <label
                      htmlFor={`compact-sender-${inbox.id}`}
                      className={cn(
                        "min-w-0 flex-1 cursor-pointer",
                      )}
                    >
                      <span className="block text-sm font-medium text-gray-900">
                        {inbox.name}
                      </span>
                      <span className="block text-xs text-gray-500">
                        {formatPhone(inbox.phoneNumber)}
                      </span>
                    </label>
                    <Badge
                      variant="outline"
                      className="hidden border-slate-200 bg-slate-50 text-slate-700 sm:inline-flex"
                    >
                      Qualidade não consultada
                    </Badge>
                    <span className="text-xs text-gray-500">
                      {`${inbox.templates.length} modelos`}
                    </span>
                  </div>
                  {sender ? (
                    <div className="ml-7 mt-3 space-y-3 border-l border-blue-200 pl-3">
                      <div className="flex flex-wrap gap-2">
                        {inbox.templates.map((template) => (
                          <button
                            key={template.id}
                            type="button"
                            disabled={template.status !== "APPROVED"}
                            onClick={() => toggleTemplate(inbox.id, template)}
                            className={cn(
                              "rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors",
                              template.status !== "APPROVED" && "cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400 opacity-80",
                              sender.templateIds.includes(template.id)
                                ? "border-blue-600 bg-blue-600 text-white"
                                : "border-gray-200 bg-white text-gray-700 hover:border-blue-300",
                            )}
                          >
                            <span className="flex items-center gap-2">
                              <span className="truncate">{template.name}</span>
                              <Badge variant="outline" className={cn("shrink-0 px-1.5 py-0 text-[10px]", templateStatusStyles(template.status).className)}>
                                {templateStatusStyles(template.status).label}
                              </Badge>
                            </span>
                            <span
                              className={cn(
                                "mt-0.5 block max-w-[28rem] truncate text-left text-[11px] font-normal",
                                sender.templateIds.includes(template.id)
                                  ? "text-blue-100"
                                  : "text-gray-500",
                              )}
                            >
                              {template.body}
                            </span>
                          </button>
                        ))}
                      </div>
                      <div className="flex flex-wrap items-center gap-3">
                        <div className="flex items-center gap-2">
                          <Switch
                            id={`compact-limit-${inbox.id}`}
                            checked={sender.sendLimitEnabled}
                            className="data-[state=checked]:bg-blue-600"
                            onCheckedChange={(checked) =>
                              updateSender(inbox.id, (current) => ({
                                ...current,
                                sendLimitEnabled: checked,
                              }))
                            }
                          />
                          <Label
                            htmlFor={`compact-limit-${inbox.id}`}
                            className="text-sm font-medium text-gray-800"
                          >
                            Limitar envios
                          </Label>
                        </div>
                        {sender.sendLimitEnabled ? (
                          <Input
                            aria-label={`Limite de envios para ${inbox.name}`}
                            type="number"
                            min="1"
                            value={sender.maxSends}
                            onChange={(event) =>
                              updateSender(inbox.id, (current) => ({
                                ...current,
                                maxSends: event.target.value,
                              }))
                            }
                            placeholder="Máximo"
                            className="h-8 w-28 border-gray-300 bg-white text-sm"
                          />
                        ) : null}
                      </div>
                    </div>
                  ) : null}
                </div>
              );
            })}
            {inboxesWithoutTemplates.length > 0 ? (
              <div className="pt-3">
                <button
                  type="button"
                  onClick={() => setShowEmptyInboxes((current) => !current)}
                  className="flex w-full items-center justify-between rounded-md border border-dashed border-gray-200 px-3 py-2 text-left text-xs text-gray-500 hover:border-blue-300 hover:text-gray-700"
                  aria-expanded={showEmptyInboxes}
                >
                  <span>{showEmptyInboxes ? "Ocultar" : "Ver"} {inboxesWithoutTemplates.length} inbox(es) sem templates</span>
                  <ChevronDown className={cn("h-4 w-4 transition-transform", showEmptyInboxes && "rotate-180")} />
                </button>
                {showEmptyInboxes ? (
                  <div className="mt-2 space-y-1 rounded-md bg-gray-50 p-2">
                    {inboxesWithoutTemplates.map((inbox) => (
                      <div key={inbox.id} className="flex flex-wrap items-center justify-between gap-2 px-2 py-1.5 text-xs text-gray-500">
                        <span className="font-medium text-gray-700">{inbox.name}</span>
                        <span>{formatPhone(inbox.phoneNumber)} · Sem templates disponíveis</span>
                      </div>
                    ))}
                  </div>
                ) : null}
              </div>
            ) : null}
            {allSendersHaveLimits && totalCapacity < recipientCount ? <div role="alert" className="mx-4 my-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Os limites somam {totalCapacity.toLocaleString("pt-BR")} envios para uma base de {recipientCount.toLocaleString("pt-BR")} destinatários.</div> : null}
            {senderErrors.length > 0 ? <div role="alert" className="mx-4 my-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">{senderErrors.map((error) => <p key={error}>{error}</p>)}</div> : null}
          </div>
        </section>
        {configurableTemplates.length > 0 ? (
          <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
            <div className="flex items-start justify-between gap-3">
              <SectionLabel
                icon={<MessageSquareText className="h-4 w-4" />}
                title="Personalizar mensagens"
                description="Preencha somente os campos exigidos pelos templates selecionados."
              />
              <span className="shrink-0 text-xs text-gray-500">
                {configurableTemplates.length} com campos
              </span>
            </div>
            <div className="mt-4 divide-y divide-gray-100">
              {configurableTemplates.map((template) => (
                <div key={template.id} className="px-4 py-3">
                  <div className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-sm font-medium text-gray-900">
                      {template.name}
                    </span>
                    <span className="text-xs text-gray-500">
                      {template.body}
                    </span>
                  </div>
                  {template.headerType ? (
                    <div className="mt-3 rounded-md border border-blue-100 bg-blue-50/60 p-3">
                      <div className="flex items-start gap-2">
                        <span className="mt-0.5 text-blue-700">
                          {template.headerType === "TEXT" ? <Type className="h-4 w-4" /> : <Image className="h-4 w-4" />}
                        </span>
                        <div className="min-w-0 flex-1">
                          <div className="text-xs font-semibold text-blue-900">
                            Cabeçalho {template.headerType === "TEXT" ? "de texto" : `de ${template.headerType.toLowerCase()}`}
                          </div>
                          <p className="mt-1 text-xs text-blue-800">
                            {template.headerType === "TEXT" ? "Informe o valor que será enviado no cabeçalho." : "Use uma URL pública, acessível sem autenticação."}
                          </p>
                          <Input
                            value={configuration.templateHeaders[template.id] ?? ""}
                            onChange={(event) => updateTemplateHeader(template.id, event.target.value)}
                            type={template.headerType === "TEXT" ? "text" : "url"}
                            placeholder={template.headerType === "TEXT" ? template.headerText ?? "Texto do cabeçalho" : "https://seu-dominio.com/arquivo"}
                            className="mt-2 h-9 border-blue-200 bg-white"
                          />
                        </div>
                      </div>
                    </div>
                  ) : null}
                  {template.parameters.length > 0 ? (
                    <div className="mt-3 grid gap-2 md:grid-cols-2">
                      {template.parameters.map((parameter) => {
                        const value = configuration.templateParameters[
                          template.id
                        ]?.[parameter.key] ?? {
                          source: "fixed" as const,
                          value: "",
                        };
                        return (
                          <div
                            key={parameter.key}
                            className="grid grid-cols-[minmax(0,1fr)_150px] gap-2"
                          >
                            <Field label={parameter.label}>
                              {value.source === "fixed" ? (
                                <Input
                                  value={value.value}
                                  onChange={(event) =>
                                    updateParameter(
                                      template.id,
                                      parameter.key,
                                      { value: event.target.value },
                                    )
                                  }
                                  placeholder="Texto"
                                  className="h-9 border-gray-300"
                                />
                              ) : (
                                <Select
                                  value={value.value}
                                  onValueChange={(field) =>
                                    updateParameter(
                                      template.id,
                                      parameter.key,
                                      { value: field },
                                    )
                                  }
                                >
                                  <SelectTrigger className="h-9 border-gray-300 bg-white">
                                    <SelectValue placeholder="Campo" />
                                  </SelectTrigger>
                                  <SelectContent>
                                    {leadFields.map((field) => (
                                      <SelectItem
                                        key={field.value}
                                        value={field.value}
                                      >
                                        {field.label}
                                      </SelectItem>
                                    ))}
                                  </SelectContent>
                                </Select>
                              )}
                            </Field>
                            <Field label="Origem">
                              <Select
                                value={value.source}
                                onValueChange={(source) =>
                                  updateParameter(template.id, parameter.key, {
                                    source: source as ParameterSource,
                                    value: "",
                                  })
                                }
                              >
                                <SelectTrigger className="h-9 border-gray-300 bg-white">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="fixed">
                                    Texto fixo
                                  </SelectItem>
                                  <SelectItem value="lead_field" disabled={!canUseLeadFields}>
                                    Dados cadastrados do lead{!canUseLeadFields ? " (indisponível para lista)" : ""}
                                  </SelectItem>
                                </SelectContent>
                              </Select>
                            </Field>
                          </div>
                        );
                      })}
                    </div>
                  ) : null}
                </div>
              ))}
            </div>
            {contentErrors.length > 0 ? <div role="alert" className="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">{contentErrors.map((error) => <p key={error}>{error}</p>)}</div> : null}
          </section>
        ) : null}
        <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
          <SectionLabel
            icon={<Clock3 className="h-4 w-4" />}
            title="Programação de envio"
            description="Defina quando a campanha começa, seu ritmo e as proteções aplicadas."
          />
          <div className="mt-4 divide-y divide-gray-100">
            <div className="py-4 first:pt-0">
              <p className="text-sm font-semibold text-gray-800">Quando enviar</p>
              <p className="mt-1 text-xs text-gray-600">Escolha o início e, se necessário, limite os horários em que o disparo pode continuar.</p>
              <div className="mt-3 grid gap-2 sm:grid-cols-2">
                {(["now", "scheduled"] as const).map((mode) => (
                  <button key={mode} type="button" onClick={() => setConfiguration((current) => ({ ...current, startMode: mode }))} className={cn("min-h-16 rounded-md border bg-white px-3 py-2 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500", configuration.startMode === mode ? "border-blue-500 bg-blue-50 text-blue-900" : "border-gray-300 text-gray-700 hover:border-blue-300")}>
                    <span className="block text-sm font-medium">{mode === "now" ? "Enviar assim que possível" : "Agendar início"}</span>
                    <span className="mt-0.5 block text-xs text-gray-500">{mode === "now" ? "Começa quando o envio estiver disponível" : "Escolha data e hora"}</span>
                  </button>
                ))}
              </div>
              {configuration.startMode === "scheduled" ? <div className="mt-3"><Field label="Data e hora de início"><Input type="datetime-local" value={configuration.scheduledAt} onChange={(event) => setConfiguration((current) => ({ ...current, scheduledAt: event.target.value }))} className="border-gray-300 bg-white" /></Field></div> : null}
              <div className="mt-4 flex items-center justify-between gap-3 border-t border-gray-100 pt-4">
                <div><Label htmlFor="compact-send-window" className="text-sm font-medium text-gray-800">Limitar a uma faixa de horário</Label><p className="mt-1 text-xs text-gray-600">Fora da faixa, a campanha aguarda o próximo horário permitido.</p></div>
                <Switch id="compact-send-window" checked={configuration.sendWindowEnabled} className="data-[state=checked]:bg-blue-600" onCheckedChange={(checked) => setConfiguration((current) => ({ ...current, sendWindowEnabled: checked }))} />
              </div>
              {configuration.sendWindowEnabled ? <div className="mt-3 grid gap-3 sm:grid-cols-2"><Field label="A partir de"><Input type="time" value={configuration.sendWindowStart} onChange={(event) => setConfiguration((current) => ({ ...current, sendWindowStart: event.target.value }))} className="border-gray-300 bg-white" /></Field><Field label="Até"><Input type="time" value={configuration.sendWindowEnd} onChange={(event) => setConfiguration((current) => ({ ...current, sendWindowEnd: event.target.value }))} className="border-gray-300 bg-white" /></Field><p className="text-xs text-gray-500 sm:col-span-2">Horário de Brasília (UTC−03:00).</p></div> : null}
            </div>
            <div className="py-4 last:pb-0">
              <p className="mb-3 text-sm font-semibold text-gray-800">Ritmo e proteção</p>
              <div className="space-y-4">
                <div className="max-w-sm"><Field label="Intervalo entre mensagens (seg.)"><Input type="number" min="1" value={configuration.intervalSeconds} onChange={(event) => setConfiguration((current) => ({ ...current, intervalSeconds: event.target.value }))} className="border-gray-300 bg-white" /></Field></div>
                <div className="rounded-md border border-gray-200 px-3 py-2.5"><div className="flex items-center justify-between gap-3"><div><Label htmlFor="compact-resend" className="text-sm font-medium text-gray-800">Evitar reenvio</Label><p className="mt-1 text-xs text-gray-600">Bloqueia novo contato pelo período definido.</p></div><Switch id="compact-resend" checked={configuration.resendProtectionEnabled} className="data-[state=checked]:bg-blue-600" onCheckedChange={(checked) => setConfiguration((current) => ({ ...current, resendProtectionEnabled: checked }))} /></div>{configuration.resendProtectionEnabled ? <div className="mt-3 max-w-sm"><Field label="Sem reenvio (dias)"><Input type="number" min="1" value={configuration.resendProtectionDays} onChange={(event) => setConfiguration((current) => ({ ...current, resendProtectionDays: event.target.value }))} className="border-gray-300 bg-white" /></Field></div> : null}</div>
              </div>
              {deliveryErrors.length > 0 ? <div role="alert" className="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">{deliveryErrors.map((error) => <p key={error}>{error}</p>)}</div> : null}
            </div>
          </div>
        </section>
      </div>
    </section>
  );
}
