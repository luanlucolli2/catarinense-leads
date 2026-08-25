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

const PANEL_CLASS_NAME = "rounded-lg border border-gray-200 bg-white shadow-sm";

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
}: {
  source: LeadSource;
  recipientCount: number;
  configuration: DispatchConfiguration;
  setConfiguration: Dispatch<SetStateAction<DispatchConfiguration>>;
  inboxes: OfficialInbox[];
  onRefresh: () => void;
  isRefreshing: boolean;
}) {
  const templates = selectedTemplates(configuration, inboxes);
  const canUseLeadFields = source === "registered_leads";
  const inboxesWithTemplates = inboxes.filter((inbox) => inbox.templates.length > 0);
  const inboxesWithoutTemplates = inboxes.filter((inbox) => inbox.templates.length === 0);
  const [showEmptyInboxes, setShowEmptyInboxes] = useState(false);

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
              description="Selecione os números oficiais e as mensagens que cada um poderá enviar."
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
          </div>
        </section>
        {templates.length > 0 ? (
          <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
            <div className="flex items-start justify-between gap-3">
              <SectionLabel
                icon={<MessageSquareText className="h-4 w-4" />}
                title="Conteúdo dos templates"
                description="Preencha variáveis e cabeçalhos exigidos pelos templates escolhidos."
              />
              <span className="shrink-0 text-xs text-gray-500">
                {templates.length} selecionado(s)
              </span>
            </div>
            <div className="mt-4 divide-y divide-gray-100">
              {templates.map((template) => (
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
          </section>
        ) : null}
        <section className={cn(PANEL_CLASS_NAME, "p-4 sm:p-5")}>
          <SectionLabel
            icon={<Clock3 className="h-4 w-4" />}
            title="Entrega"
            description="Defina o intervalo, o início e a proteção contra reenvio."
          />
          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <Field label="Intervalo (seg.)">
              <Input
                type="number"
                min="1"
                value={configuration.intervalSeconds}
                onChange={(event) =>
                  setConfiguration((current) => ({
                    ...current,
                    intervalSeconds: event.target.value,
                  }))
                }
                className="border-gray-300"
              />
            </Field>
            <Field label="Início">
              <Select
                value={configuration.startMode}
                onValueChange={(value) =>
                  setConfiguration((current) => ({
                    ...current,
                    startMode: value as DispatchConfiguration["startMode"],
                  }))
                }
              >
                <SelectTrigger className="border-gray-300 bg-white">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="now">Agora</SelectItem>
                  <SelectItem value="scheduled">Agendar</SelectItem>
                </SelectContent>
              </Select>
            </Field>
            {configuration.startMode === "scheduled" ? (
              <Field label="Data e hora">
                <Input
                  type="datetime-local"
                  value={configuration.scheduledAt}
                  onChange={(event) =>
                    setConfiguration((current) => ({
                      ...current,
                      scheduledAt: event.target.value,
                    }))
                  }
                  className="border-gray-300"
                />
              </Field>
            ) : (
              <div className="flex items-end">
                <div className="flex h-10 items-center gap-2">
                  <Switch
                    id="compact-resend"
                    checked={configuration.resendProtectionEnabled}
                    className="data-[state=checked]:bg-blue-600"
                    onCheckedChange={(checked) =>
                      setConfiguration((current) => ({
                        ...current,
                        resendProtectionEnabled: checked,
                      }))
                    }
                  />
                  <Label
                    htmlFor="compact-resend"
                    className="text-sm font-medium text-gray-800"
                  >
                    Evitar reenvio
                  </Label>
                </div>
              </div>
            )}
            {configuration.resendProtectionEnabled ? (
              <Field label="Sem reenvio (dias)">
                <Input
                  type="number"
                  min="1"
                  value={configuration.resendProtectionDays}
                  onChange={(event) =>
                    setConfiguration((current) => ({
                      ...current,
                      resendProtectionDays: event.target.value,
                    }))
                  }
                  className="border-gray-300"
                />
              </Field>
            ) : null}
          </div>
        </section>
      </div>
    </section>
  );
}
