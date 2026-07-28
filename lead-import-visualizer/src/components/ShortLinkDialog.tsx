import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type {
  ShortLink,
  ShortLinkDestinationInput,
  ShortLinkKind,
  ShortLinkMode,
  ShortLinkStrategy,
} from "@/api/shortLinks";

export type ShortLinkDialogValue = {
  label: string;
  mode: ShortLinkMode;
  strategy: ShortLinkStrategy | null;
  destinations: ShortLinkDestinationInput[];
  retain_destination_id?: string;
};

const blankDestination = (
  kind: ShortLinkKind = "url",
  position = 1,
): ShortLinkDestinationInput => ({
  kind,
  url: "",
  phone: "",
  message: "",
  position,
  weight: 1,
});

const destinationsFromLink = (link?: ShortLink): ShortLinkDestinationInput[] =>
  link?.destinations?.map((destination) => ({
    kind: destination.kind,
    url: destination.kind === "url" ? destination.url : "",
    phone: destination.normalized_phone ?? "",
    message: destination.whatsapp_message ?? "",
    position: destination.position,
    weight: destination.weight,
  })) ?? [blankDestination()];

export function ShortLinkDialog({
  open,
  link,
  duplicate,
  pending,
  error,
  onOpenChange,
  onSubmit,
}: {
  open: boolean;
  link?: ShortLink;
  duplicate?: boolean;
  pending?: boolean;
  error?: string;
  onOpenChange: (open: boolean) => void;
  onSubmit: (value: ShortLinkDialogValue) => void;
}) {
  const [label, setLabel] = useState("");
  const [mode, setMode] = useState<ShortLinkMode>("single");
  const [strategy, setStrategy] = useState<ShortLinkStrategy>("sequential");
  const [destinations, setDestinations] = useState<ShortLinkDestinationInput[]>(
    [blankDestination()],
  );
  const [retainId, setRetainId] = useState("");
  const [validation, setValidation] = useState("");

  useEffect(() => {
    if (!open) return;
    setLabel(
      link ? (duplicate ? `Cópia de ${link.label || "link"}` : link.label) : "",
    );
    setMode(link?.mode ?? "single");
    setStrategy(link?.strategy ?? "sequential");
    setDestinations(destinationsFromLink(link));
    setRetainId(link?.destinations?.[0]?.id ?? "");
    setValidation("");
  }, [duplicate, link, open]);

  const editing = Boolean(link) && !duplicate;
  const convertingToSingle =
    editing && link?.mode === "rotating" && mode === "single";

  const setDestination = (
    index: number,
    patch: Partial<ShortLinkDestinationInput>,
  ) =>
    setDestinations((items) =>
      items.map((item, current) =>
        current === index ? { ...item, ...patch } : item,
      ),
    );
  const changeMode = (next: ShortLinkMode, kind?: ShortLinkKind) => {
    setMode(next);
    if (next === "single" && kind) setDestinations([blankDestination(kind)]);
    if (next === "rotating" && destinations.length < 2)
      setDestinations((items) => [...items, blankDestination("url", 2)]);
  };
  const reorder = (index: number, offset: number) =>
    setDestinations((items) => {
      const next = index + offset;
      if (next < 0 || next >= items.length) return items;
      const result = [...items];
      [result[index], result[next]] = [result[next], result[index]];
      return result.map((item, position) => ({
        ...item,
        position: position + 1,
      }));
    });

  const validate = () => {
    if (label.length > 200) return "O rótulo deve ter até 200 caracteres.";
    if (
      mode === "rotating" &&
      (destinations.length < 2 || destinations.length > 50)
    )
      return "O link rotativo precisa de 2 a 50 destinos.";
    if (
      mode === "rotating" &&
      strategy === "weighted" &&
      destinations.every((item) => Number(item.weight) <= 0)
    )
      return "Informe ao menos um peso maior que zero.";
    for (const destination of destinations) {
      if (destination.kind === "url") {
        try {
          const url = new URL(destination.url ?? "");
          if (!/^https?:$/.test(url.protocol)) throw new Error();
        } catch {
          return "Informe uma URL HTTP ou HTTPS válida.";
        }
      } else if (
        ![10, 11, 12, 13].includes(
          (destination.phone ?? "").replace(/\D/g, "").length,
        )
      )
        return "Informe um telefone com DDD.";
      else if ((destination.message ?? "").length > 500)
        return "A mensagem deve ter até 500 caracteres.";
      if (Number(destination.weight) < 0 || Number(destination.weight) > 1000)
        return "Os pesos devem estar entre 0 e 1000.";
    }
    return "";
  };

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const issue = convertingToSingle
      ? retainId
        ? ""
        : "Escolha o destino que será mantido."
      : validate();
    setValidation(issue);
    if (issue) return;
    if (convertingToSingle)
      return onSubmit({
        label: label.trim(),
        mode: "single",
        strategy: null,
        destinations: [],
        retain_destination_id: retainId,
      });
    onSubmit({
      label: label.trim(),
      mode,
      strategy: mode === "rotating" ? strategy : null,
      destinations: destinations.map((item, index) => ({
        ...item,
        position: index + 1,
        weight: Number(item.weight ?? 1),
      })),
    });
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto rounded-xl border-0 bg-white p-0 shadow-2xl ring-1 ring-black/10 sm:rounded-xl">
        <DialogHeader className="flex-shrink-0 border-b bg-white/90 p-4 shadow-sm backdrop-blur sm:p-6 supports-[backdrop-filter]:bg-white/70">
          <DialogTitle className="text-lg font-semibold text-gray-900 sm:text-xl">
            {duplicate ? "Duplicar link" : link ? "Editar link" : "Criar link"}
          </DialogTitle>
          <DialogDescription className="text-xs text-gray-500 sm:text-sm">
            Configure os destinos e a distribuição dos acessos.
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4 bg-gradient-to-b from-white to-gray-50 p-4 sm:p-6"
          onSubmit={submit}
        >
          {!link && (
            <div className="grid gap-2 sm:grid-cols-3">
              <Button
                type="button"
                className={
                  mode === "single" && destinations[0]?.kind === "url"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border-gray-300 text-gray-700 hover:bg-gray-50"
                }
                variant={
                  mode === "single" && destinations[0]?.kind === "url"
                    ? "default"
                    : "outline"
                }
                onClick={() => changeMode("single", "url")}
              >
                Link normal
              </Button>
              <Button
                type="button"
                className={
                  mode === "single" && destinations[0]?.kind === "whatsapp"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border-gray-300 text-gray-700 hover:bg-gray-50"
                }
                variant={
                  mode === "single" && destinations[0]?.kind === "whatsapp"
                    ? "default"
                    : "outline"
                }
                onClick={() => changeMode("single", "whatsapp")}
              >
                WhatsApp
              </Button>
              <Button
                type="button"
                className={
                  mode === "rotating"
                    ? "bg-blue-600 text-white hover:bg-blue-700"
                    : "border-gray-300 text-gray-700 hover:bg-gray-50"
                }
                variant={mode === "rotating" ? "default" : "outline"}
                onClick={() => changeMode("rotating")}
              >
                Link rotativo
              </Button>
            </div>
          )}
          {link && (
            <div className="space-y-1">
              <Label>Modo</Label>
              <select
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={mode}
                onChange={(event) =>
                  changeMode(event.target.value as ShortLinkMode)
                }
              >
                <option value="single">Único</option>
                <option value="rotating">Rotativo</option>
              </select>
            </div>
          )}
          <div className="space-y-1">
            <Label htmlFor="short-link-label">Rótulo interno (opcional)</Label>
            <Input
              id="short-link-label"
              value={label}
              maxLength={200}
              onChange={(event) => setLabel(event.target.value)}
            />
          </div>
          {mode === "rotating" && (
            <div className="space-y-1">
              <Label>Estratégia</Label>
              <select
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={strategy}
                onChange={(event) =>
                  setStrategy(event.target.value as ShortLinkStrategy)
                }
              >
                <option value="sequential">Sequencial</option>
                <option value="random">Aleatória</option>
                <option value="weighted">Distribuir por peso</option>
                <option value="first">Sempre o primeiro</option>
              </select>
              <p className="text-xs text-muted-foreground">
                {strategy === "sequential"
                  ? "Alterna os destinos pela ordem."
                  : strategy === "random"
                    ? "Escolhe um destino ao acaso."
                    : strategy === "weighted"
                      ? "Distribui proporcionalmente aos pesos."
                      : "Sempre usa o primeiro destino."}
              </p>
            </div>
          )}
          {convertingToSingle ? (
            <div className="space-y-1">
              <Label>Destino que será mantido</Label>
              <select
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={retainId}
                onChange={(event) => setRetainId(event.target.value)}
              >
                {link?.destinations?.map((destination) => (
                  <option key={destination.id} value={destination.id}>
                    {destination.position}. {destination.url}
                  </option>
                ))}
              </select>
            </div>
          ) : (
            <>
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="font-medium">Destinos</h3>
                  <p className="text-xs text-muted-foreground">
                    {mode === "rotating"
                      ? "Adicione e organize os destinos da rotação."
                      : "O acesso será encaminhado para este destino."}
                  </p>
                </div>
                <span className="text-sm text-muted-foreground">
                  {destinations.length}
                </span>
              </div>
              {destinations.map((destination, index) => (
                <fieldset
                  key={index}
                  className="space-y-3 rounded-lg border bg-muted/30 p-3"
                >
                  <legend className="px-1 text-sm font-medium">
                    Destino {index + 1}
                  </legend>
                  <div className="flex flex-wrap gap-2">
                    <select
                      className="h-9 flex-1 rounded-md border border-input bg-background px-2 text-sm"
                      value={destination.kind}
                      onChange={(event) =>
                        setDestination(index, {
                          kind: event.target.value as ShortLinkKind,
                          url: "",
                          phone: "",
                          message: "",
                        })
                      }
                    >
                      <option value="url">URL</option>
                      <option value="whatsapp">WhatsApp</option>
                    </select>
                    {mode === "rotating" && (
                      <>
                        <Button
                          type="button"
                          size="icon"
                          variant="outline"
                          disabled={index === 0}
                          onClick={() => reorder(index, -1)}
                        >
                          <ArrowUp className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          size="icon"
                          variant="outline"
                          disabled={index === destinations.length - 1}
                          onClick={() => reorder(index, 1)}
                        >
                          <ArrowDown className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          size="icon"
                          variant="outline"
                          disabled={destinations.length <= 2}
                          onClick={() =>
                            setDestinations((items) =>
                              items.filter((_, current) => current !== index),
                            )
                          }
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </>
                    )}
                  </div>
                  {destination.kind === "url" ? (
                    <div className="space-y-1">
                      <Label>URL de destino</Label>
                      <Input
                        type="url"
                        placeholder="https://exemplo.com"
                        value={destination.url ?? ""}
                        onChange={(event) =>
                          setDestination(index, { url: event.target.value })
                        }
                      />
                    </div>
                  ) : (
                    <>
                      <div className="space-y-1">
                        <Label>Telefone com DDD</Label>
                        <Input
                          inputMode="tel"
                          placeholder="(48) 99999-9999"
                          value={destination.phone ?? ""}
                          onChange={(event) =>
                            setDestination(index, { phone: event.target.value })
                          }
                        />
                      </div>
                      <div className="space-y-1">
                        <Label>Mensagem (opcional)</Label>
                        <Textarea
                          maxLength={500}
                          value={destination.message ?? ""}
                          onChange={(event) =>
                            setDestination(index, {
                              message: event.target.value,
                            })
                          }
                        />
                      </div>
                    </>
                  )}
                  {mode === "rotating" && strategy === "weighted" && (
                    <div className="space-y-1">
                      <Label>Peso</Label>
                      <Input
                        type="number"
                        min={0}
                        max={1000}
                        value={destination.weight ?? 1}
                        onChange={(event) =>
                          setDestination(index, {
                            weight: Number(event.target.value),
                          })
                        }
                      />
                    </div>
                  )}
                </fieldset>
              ))}
              {mode === "rotating" && destinations.length < 50 && (
                <div className="grid gap-2 sm:grid-cols-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                      setDestinations((items) => [
                        ...items,
                        blankDestination("url", items.length + 1),
                      ])
                    }
                  >
                    <Plus className="h-4 w-4" />
                    Adicionar URL
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                      setDestinations((items) => [
                        ...items,
                        blankDestination("whatsapp", items.length + 1),
                      ])
                    }
                  >
                    <Plus className="h-4 w-4" />
                    Adicionar WhatsApp
                  </Button>
                </div>
              )}
            </>
          )}
          {(validation || error) && (
            <p className="rounded-md bg-red-50 p-3 text-sm text-red-700">
              {validation || error}
            </p>
          )}
          <DialogFooter className="flex-shrink-0 border-t bg-white/90 p-4 shadow-sm backdrop-blur sm:p-4 supports-[backdrop-filter]:bg-white/70">
            <Button
              type="button"
              variant="outline"
              className="border-gray-300 text-gray-700 hover:bg-gray-50"
              disabled={pending}
              onClick={() => onOpenChange(false)}
            >
              Cancelar
            </Button>
            <Button
              className="bg-blue-600 text-white shadow-md transition-shadow hover:bg-blue-700 hover:shadow-lg"
              type="submit"
              disabled={pending}
            >
              {pending ? "Salvando..." : "Salvar link"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
