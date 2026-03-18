import axiosClient from "./axiosClient";

export interface Uy3PostItem {
  id: string;
  received_at: string | null;
  dados: unknown;
}

export interface Uy3PostsResponse {
  data: Uy3PostItem[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ListUy3PostsParams {
  page?: number;
  perPage?: number;
  q?: string;
  period?: "all" | "24h" | "7d" | "30d" | "90d";
  from?: string;
  to?: string;
  sort?: "received_at" | "id";
  direction?: "asc" | "desc";
}

export async function listUy3Posts(
  params: ListUy3PostsParams = {},
  signal?: AbortSignal
): Promise<Uy3PostsResponse> {
  const query: Record<string, string | number> = {};

  if (typeof params.page === "number" && Number.isFinite(params.page)) query.page = params.page;
  if (typeof params.perPage === "number" && Number.isFinite(params.perPage)) query.per_page = params.perPage;
  if (params.q?.trim()) query.q = params.q.trim();
  if (params.period) query.period = params.period;
  if (params.from) query.from = params.from;
  if (params.to) query.to = params.to;
  if (params.sort) query.sort = params.sort;
  if (params.direction) query.direction = params.direction;

  const { data } = await axiosClient.get<Uy3PostsResponse>("/uy3/posts", {
    params: query,
    signal,
  });

  return data;
}
