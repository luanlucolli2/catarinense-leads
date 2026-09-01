import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ArrowLeft, Download, Loader2, Search, X } from "lucide-react";
import { Link, useParams } from "react-router-dom";
import { shortLinksApi, type AnalyticsPeriod } from "@/api/shortLinks";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

const periods: Array<{ value: AnalyticsPeriod; label: string }> = [
  { value: "24h", label: "Últimas 24 horas" },
  { value: "7d", label: "Últimos 7 dias" },
  { value: "30d", label: "Últimos 30 dias" },
  { value: "90d", label: "Últimos 90 dias" },
  { value: "365d", label: "Último ano" },
];
const dimensions: Record<string, string> = {
  countries: "Países",
  cities: "Cidades",
  devices: "Dispositivos",
  browsers: "Navegadores",
  operating_systems: "Sistemas",
  referrers: "Referrers",
};

const LinkMetricsPage = () => {
  const { id = "" } = useParams();
  const [period, setPeriod] = useState<AnalyticsPeriod>("30d");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [eventType, setEventType] = useState("real");
  const [page, setPage] = useState(1);
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [search]);
  const link = useQuery({
    queryKey: ["short-link", id],
    queryFn: () => shortLinksApi.get(id),
    enabled: Boolean(id),
  });
  const analytics = useQuery({
    queryKey: ["short-link-analytics", id, period],
    queryFn: () => shortLinksApi.analytics(id, period),
    enabled: Boolean(id),
  });
  const clicks = useQuery({
    queryKey: [
      "short-link-clicks",
      id,
      period,
      debouncedSearch,
      eventType,
      page,
    ],
    queryFn: () =>
      shortLinksApi.clicks(id, {
        period,
        search: debouncedSearch,
        event_type: eventType,
        page,
        per_page: 20,
      }),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  });
  const summary = analytics.data?.summary;
  const exportUrl = shortLinksApi.exportUrl(id, {
    period,
    search: debouncedSearch,
    event_type: eventType,
  });
  const formatDate = (value: string) =>
    new Intl.DateTimeFormat("pt-BR", {
      timeZone: "America/Sao_Paulo",
      dateStyle: "short",
      timeStyle: "medium",
    }).format(new Date(value));

  return (
    <div className="p-4 lg:p-6">
      <Link
        to="/ferramentas/links"
        className="mb-4 inline-flex items-center gap-1 text-sm text-green-700 hover:underline"
      >
        <ArrowLeft className="h-4 w-4" />
        Voltar para links
      </Link>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900 lg:text-2xl">
            Métricas do link
          </h1>
          <p className="mt-1 break-all text-sm text-muted-foreground">
            {link.data?.short_url ?? "Carregando link..."}
          </p>
        </div>
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={period}
          onChange={(event) => {
            setPeriod(event.target.value as AnalyticsPeriod);
            setPage(1);
          }}
        >
          {periods.map((item) => (
            <option key={item.value} value={item.value}>
              {item.label}
            </option>
          ))}
        </select>
      </div>
      {analytics.isLoading ? (
        <Card>
          <CardContent className="flex min-h-40 items-center justify-center text-muted-foreground">
            <Loader2 className="mr-2 h-5 w-5 animate-spin" />
            Carregando métricas...
          </CardContent>
        </Card>
      ) : analytics.isError ? (
        <Card>
          <CardContent className="py-12 text-center">
            <p>Não foi possível carregar as métricas.</p>
            <Button
              className="mt-3"
              variant="outline"
              onClick={() => analytics.refetch()}
            >
              Tentar novamente
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {[
              ["Cliques reais", summary?.real_clicks],
              ["IPs únicos", summary?.unique_ips],
              ["Países", summary?.countries],
              ["Dispositivos", summary?.devices],
              ["Acessos ignorados", summary?.ignored_clicks],
              ["Total bruto", summary?.total_clicks],
            ].map(([label, value]) => (
              <Card key={String(label)}>
                <CardContent className="p-4">
                  <p className="text-xs text-muted-foreground">{label}</p>
                  <strong className="mt-2 block text-2xl">{value ?? 0}</strong>
                </CardContent>
              </Card>
            ))}
          </div>
          <p className="mt-3 text-xs text-muted-foreground">
            IP único é um identificador técnico e não representa necessariamente
            uma pessoa única.
          </p>
          <div className="mt-6 grid gap-4 xl:grid-cols-2">
            <Series
              title="Cliques por dia"
              points={analytics.data?.by_day ?? []}
            />
            <Series
              title="Cliques por hora (Brasília)"
              points={analytics.data?.by_hour ?? []}
            />
          </div>
          <div className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {Object.entries(dimensions).map(([key, title]) => (
              <Card key={key}>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">{title}</CardTitle>
                </CardHeader>
                <CardContent>
                  {(analytics.data?.dimensions[key] ?? []).length ? (
                    <ol className="space-y-2 text-sm">
                      {(analytics.data?.dimensions[key] ?? []).map((item) => (
                        <li
                          key={item.label}
                          className="flex justify-between gap-3"
                        >
                          <span className="truncate">
                            {item.label === "Desconhecido" &&
                            key === "referrers"
                              ? "Direto"
                              : item.label}
                          </span>
                          <strong className="shrink-0">
                            {item.count} · {item.percentage.toFixed(1)}%
                          </strong>
                        </li>
                      ))}
                    </ol>
                  ) : (
                    <p className="text-sm text-muted-foreground">Sem dados</p>
                  )}
                </CardContent>
              </Card>
            ))}
          </div>
        </>
      )}
      <section className="mt-8">
        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h2 className="text-lg font-semibold">Cliques detalhados</h2>
            <p className="text-xs text-muted-foreground">
              Dados apresentados no horário de Brasília.
            </p>
          </div>
          <Button variant="outline" asChild>
            <a href={exportUrl} download>
              <Download className="h-4 w-4" />
              Exportar CSV
            </a>
          </Button>
        </div>
        <div className="mb-4 grid gap-3 sm:grid-cols-[1fr_180px]">
          <div className="relative">
            <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <Input
              className="pl-9"
              value={search}
              maxLength={200}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="IP, local, dispositivo ou destino"
            />
            {search && (
              <button
                className="absolute right-2 top-2.5"
                onClick={() => setSearch("")}
              >
                <X className="h-4 w-4" />
              </button>
            )}
          </div>
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={eventType}
            onChange={(event) => {
              setEventType(event.target.value);
              setPage(1);
            }}
          >
            <option value="real">Reais</option>
            <option value="ignored">Ignorados</option>
            <option value="all">Todos</option>
          </select>
        </div>
        {clicks.isLoading ? (
          <Card>
            <CardContent className="flex min-h-40 items-center justify-center">
              <Loader2 className="h-5 w-5 animate-spin" />
            </CardContent>
          </Card>
        ) : clicks.isError ? (
          <Card>
            <CardContent className="py-10 text-center">
              <p>Não foi possível carregar os cliques.</p>
              <Button
                className="mt-3"
                variant="outline"
                onClick={() => clicks.refetch()}
              >
                Tentar novamente
              </Button>
            </CardContent>
          </Card>
        ) : clicks.data?.items.length ? (
          <>
            <Card className="overflow-x-auto">
              <table className="w-full min-w-[1000px] text-left text-xs">
                <thead className="bg-muted/50">
                  <tr>
                    {[
                      "Data",
                      "IP",
                      "Local",
                      "Dispositivo",
                      "Navegador",
                      "Sistema",
                      "Referrer",
                      "Destino",
                      "Evento",
                    ].map((title) => (
                      <th className="px-3 py-3 font-medium" key={title}>
                        {title}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {clicks.data.items.map((click) => (
                    <tr key={click.id} className="border-t">
                      <td className="whitespace-nowrap px-3 py-3">
                        {formatDate(click.occurred_at)}
                      </td>
                      <td className="px-3 py-3">
                        {click.ip_masked || "Sem dados"}
                      </td>
                      <td className="px-3 py-3">
                        {[click.city, click.region, click.country]
                          .filter(Boolean)
                          .join(" · ") || "Sem dados"}
                      </td>
                      <td className="px-3 py-3">
                        {click.device || "Sem dados"}
                      </td>
                      <td className="px-3 py-3">
                        {click.browser || "Sem dados"}
                      </td>
                      <td className="px-3 py-3">
                        {click.operating_system || "Sem dados"}
                      </td>
                      <td className="px-3 py-3">
                        {click.referrer || "Direto"}
                      </td>
                      <td
                        className="max-w-64 truncate px-3 py-3"
                        title={click.destination}
                      >
                        {click.destination}
                      </td>
                      <td className="px-3 py-3">
                        {click.event_type === "real"
                          ? "Real"
                          : `Ignorado${click.bot_reason ? ` · ${click.bot_reason}` : ""}`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
            {(clicks.data.pagination.total_pages ?? 0) > 1 && (
              <div className="mt-4 flex items-center justify-center gap-3">
                <Button
                  variant="outline"
                  disabled={page <= 1 || clicks.isFetching}
                  onClick={() => setPage((current) => current - 1)}
                >
                  Anterior
                </Button>
                <span className="text-sm text-muted-foreground">
                  Página {page} de {clicks.data.pagination.total_pages}
                </span>
                <Button
                  variant="outline"
                  disabled={
                    page >= clicks.data.pagination.total_pages ||
                    clicks.isFetching
                  }
                  onClick={() => setPage((current) => current + 1)}
                >
                  Próxima
                </Button>
              </div>
            )}
          </>
        ) : (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              Nenhum clique encontrado.
            </CardContent>
          </Card>
        )}
      </section>
    </div>
  );
};

function Series({
  title,
  points,
}: {
  title: string;
  points: Array<{ key: string; real: number; ignored: number }>;
}) {
  const maximum = Math.max(
    1,
    ...points.map((point) => point.real + point.ignored),
  );
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <div
          className="flex h-44 items-end gap-1"
          role="img"
          aria-label={title}
        >
          {points.map((point) => (
            <div
              className="flex h-full min-w-1 flex-1 flex-col justify-end"
              key={point.key}
              title={`${point.key}: ${point.real} reais, ${point.ignored} ignorados`}
            >
              <div
                className="rounded-t bg-green-700"
                style={{
                  height: `${point.real ? Math.max(3, (point.real / maximum) * 100) : 0}%`,
                }}
              />
              <div
                className="bg-amber-400"
                style={{
                  height: `${point.ignored ? Math.max(2, (point.ignored / maximum) * 100) : 0}%`,
                }}
              />
            </div>
          ))}
        </div>
        <div className="mt-3 flex gap-4 text-xs text-muted-foreground">
          <span>
            <i className="mr-1 inline-block h-2 w-2 rounded-full bg-green-700" />
            Reais
          </span>
          <span>
            <i className="mr-1 inline-block h-2 w-2 rounded-full bg-amber-400" />
            Ignorados
          </span>
        </div>
      </CardContent>
    </Card>
  );
}

export default LinkMetricsPage;
