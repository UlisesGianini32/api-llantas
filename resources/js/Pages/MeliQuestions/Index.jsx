import AppShell from "@/Components/layout/AppShell";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { useEffect, useState } from "react";

function formatWhen(value) {
  if (!value) return "Fecha no disponible";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString("es-MX", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatMoney(amount, currency = "MXN") {
  if (amount == null || Number.isNaN(Number(amount))) return "—";
  try {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: currency || "MXN",
    }).format(Number(amount));
  } catch {
    return `${currency || "MXN"} ${amount}`;
  }
}

function formatMinutes(value) {
  const minutes = Number(value);
  if (!Number.isFinite(minutes)) return "Sin datos";
  if (minutes < 60) return `${Math.round(minutes)} min`;
  const hours = Math.floor(minutes / 60);
  const rest = Math.round(minutes % 60);
  return rest > 0 ? `${hours} h ${rest} min` : `${hours} h`;
}

function StatusBadge({ status }) {
  const normalized = String(status || "").toUpperCase();
  const styles =
    normalized === "UNANSWERED"
      ? "bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
      : normalized === "ANSWERED"
        ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
        : "bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300";
  const label =
    normalized === "UNANSWERED"
      ? "Por responder"
      : normalized === "ANSWERED"
        ? "Respondida"
        : normalized.replaceAll("_", " ") || "Sin estado";

  return (
    <span
      className={`rounded-full px-2.5 py-1 text-xs font-semibold ${styles}`}
    >
      {label}
    </span>
  );
}

function StatCard({ label, value, detail, tone = "indigo" }) {
  const toneClass = {
    indigo: "text-indigo-600 dark:text-indigo-300",
    amber: "text-amber-600 dark:text-amber-300",
    emerald: "text-emerald-600 dark:text-emerald-300",
  }[tone];

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
        {label}
      </p>
      <p className={`mt-2 text-3xl font-black ${toneClass}`}>{value}</p>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {detail}
      </p>
    </div>
  );
}

function QuestionCard({ question, maxAnswerLength }) {
  const form = useForm({ text: "" });
  const [expanded, setExpanded] = useState(question.status === "UNANSWERED");
  const unanswered = question.status === "UNANSWERED";
  const remaining = maxAnswerLength - form.data.text.length;

  const submit = (event) => {
    event.preventDefault();
    if (!unanswered || !form.data.text.trim()) return;

    form.post(`/meli/preguntas/${question.id}/responder`, {
      preserveScroll: true,
      onSuccess: () => form.reset("text"),
    });
  };

  return (
    <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none">
      <div className="flex flex-col gap-4 border-b border-slate-200 p-4 dark:border-neutral-800 sm:flex-row sm:items-center">
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-neutral-700">
            {question.item_thumbnail ? (
              <img
                src={question.item_thumbnail}
                alt=""
                className="h-full w-full object-contain"
                loading="lazy"
              />
            ) : (
              <span className="text-xs text-slate-400">Sin imagen</span>
            )}
          </div>
          <div className="min-w-0">
            {question.item_permalink ? (
              <a
                href={question.item_permalink}
                target="_blank"
                rel="noreferrer"
                className="line-clamp-2 font-semibold text-indigo-600 hover:underline dark:text-indigo-300"
              >
                {question.item_title || question.item_id}
              </a>
            ) : (
              <p className="line-clamp-2 font-semibold">
                {question.item_title || question.item_id}
              </p>
            )}
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
              <span>{question.item_id}</span>
              <span>SKU {question.sku || "—"}</span>
              <span>
                {formatMoney(question.item_price, question.currency_id)}
              </span>
              <span>Stock {question.available_quantity ?? "—"}</span>
            </div>
          </div>
        </div>
        <StatusBadge status={question.status} />
      </div>

      <div className="p-4">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
              Pregunta del cliente
            </p>
            <p className="mt-2 whitespace-pre-wrap text-base font-medium text-slate-900 dark:text-white">
              {question.text || "El texto fue ocultado por Mercado Libre."}
            </p>
          </div>
          <p className="shrink-0 text-xs text-slate-500 dark:text-slate-400">
            {formatWhen(question.question_created_at)}
          </p>
        </div>

        {(question.hold ||
          question.suspected_spam ||
          question.deleted_from_listing) && (
          <div className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
            Mercado Libre marcó esta pregunta para revisión, como posible spam o
            fuera de la publicación.
          </div>
        )}

        {!unanswered && question.answer_text && (
          <div className="mt-4 rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
            <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
              Tu respuesta
            </p>
            <p className="mt-1 whitespace-pre-wrap text-sm text-emerald-950 dark:text-emerald-100">
              {question.answer_text}
            </p>
            {question.answered_at && (
              <p className="mt-2 text-xs text-emerald-700 dark:text-emerald-300">
                {formatWhen(question.answered_at)}
              </p>
            )}
          </div>
        )}

        {unanswered && (
          <div className="mt-4">
            {!expanded ? (
              <button
                type="button"
                onClick={() => setExpanded(true)}
                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
              >
                Responder
              </button>
            ) : (
              <form onSubmit={submit}>
                <label htmlFor={`answer-${question.id}`} className="sr-only">
                  Respuesta
                </label>
                <textarea
                  id={`answer-${question.id}`}
                  rows={3}
                  maxLength={maxAnswerLength}
                  value={form.data.text}
                  onChange={(event) => form.setData("text", event.target.value)}
                  disabled={form.processing}
                  placeholder="Escribe una respuesta clara para el cliente…"
                  className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                />
                {form.errors.text && (
                  <p className="mt-1 text-sm text-rose-600">
                    {form.errors.text}
                  </p>
                )}
                <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      {remaining} caracteres disponibles. No incluyas datos de
                      contacto.
                    </p>
                    <p className="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                      Mercado Libre solo permite enviar una respuesta.
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => setExpanded(false)}
                      disabled={form.processing}
                      className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    >
                      Cancelar
                    </button>
                    <button
                      type="submit"
                      disabled={form.processing || !form.data.text.trim()}
                      className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                      {form.processing ? "Enviando…" : "Enviar respuesta"}
                    </button>
                  </div>
                </div>
              </form>
            )}
          </div>
        )}
      </div>
    </article>
  );
}

export default function Index({
  questions,
  accounts = [],
  selectedAccountId = null,
  selectedAccountLinked = false,
  filters = {},
  stats = {},
  responseTime = null,
  syncError = null,
  maxAnswerLength = 2000,
}) {
  const [search, setSearch] = useState(filters.search || "");
  const syncForm = useForm({ account_id: selectedAccountId });

  useEffect(() => {
    syncForm.setData("account_id", selectedAccountId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedAccountId]);

  const navigate = (changes) => {
    router.get(
      "/meli/preguntas",
      {
        account_id: selectedAccountId,
        status: filters.status || "unanswered",
        sort: filters.sort || "oldest",
        days: filters.days ?? 15,
        search: filters.search || "",
        ...changes,
      },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  };

  const submitSearch = (event) => {
    event.preventDefault();
    navigate({ search });
  };

  const sync = () => {
    syncForm.post("/meli/preguntas/sincronizar", {
      preserveScroll: true,
    });
  };

  const totalResponseMinutes = responseTime?.total?.response_time;

  return (
    <>
      <Head title="Preguntas de Mercado Libre" />
      <AppShell title="Preguntas de Mercado Libre">
        <div className="w-full max-w-none space-y-5 text-slate-900 dark:text-slate-100">
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none">
            <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                  Mercado Libre · Preventa
                </p>
                <h1 className="mt-2 text-2xl font-black">
                  Preguntas de productos
                </h1>
                <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                  Consulta y responde preguntas de tus publicaciones sin salir
                  del sistema. Las pendientes más antiguas aparecen primero para
                  evitar perder ventas.
                </p>
              </div>

              <div className="flex w-full flex-col gap-3 sm:flex-row xl:w-auto">
                <div className="min-w-64">
                  <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Cuenta Mercado Libre
                  </label>
                  <select
                    value={selectedAccountId || ""}
                    onChange={(event) =>
                      navigate({
                        account_id: Number(event.target.value),
                        search: "",
                        page: 1,
                      })
                    }
                    disabled={accounts.length === 0}
                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-neutral-700 dark:bg-neutral-950"
                  >
                    {accounts.length === 0 ? (
                      <option value="">Sin cuentas vinculadas</option>
                    ) : (
                      accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                          {account.nickname}
                          {account.is_default ? " — Principal" : ""} ·{" "}
                          {account.meli_user_id}
                        </option>
                      ))
                    )}
                  </select>
                </div>
                <button
                  type="button"
                  onClick={sync}
                  disabled={!selectedAccountLinked || syncForm.processing}
                  className="self-end rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                >
                  {syncForm.processing
                    ? "Actualizando…"
                    : "Actualizar preguntas"}
                </button>
              </div>
            </div>

            {!selectedAccountLinked && (
              <p className="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                La cuenta seleccionada no tiene token. Refresca el token o
                vuelve a vincularla.
              </p>
            )}
            {syncError && (
              <p className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                {syncError}
              </p>
            )}
          </section>

          <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Por responder"
              value={stats.unanswered ?? 0}
              detail="Preguntas pendientes de esta cuenta"
              tone="amber"
            />
            <StatCard
              label="Respondidas"
              value={stats.answered_15_days ?? 0}
              detail="Respuestas enviadas en los últimos 15 días"
              tone="emerald"
            />
            <StatCard
              label="Preguntas recientes"
              value={stats.total_15_days ?? 0}
              detail="Recibidas durante los últimos 15 días"
            />
            <StatCard
              label="Tiempo de respuesta"
              value={formatMinutes(totalResponseMinutes)}
              detail="Promedio oficial de Mercado Libre, últimos 14 días"
              tone="indigo"
            />
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none">
            <div className="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_auto_auto_auto]">
              <form onSubmit={submitSearch} className="flex gap-2">
                <input
                  type="search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Buscar pregunta, producto, SKU o MLM…"
                  className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-neutral-700 dark:bg-neutral-950"
                />
                <button
                  type="submit"
                  className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                >
                  Buscar
                </button>
              </form>
              <select
                value={filters.status || "unanswered"}
                onChange={(event) =>
                  navigate({ status: event.target.value, page: 1 })
                }
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option value="unanswered">Por responder</option>
                <option value="answered">Respondidas</option>
                <option value="all">Todos los estados</option>
              </select>
              <select
                value={filters.days ?? 15}
                onChange={(event) =>
                  navigate({ days: Number(event.target.value), page: 1 })
                }
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option value={7}>Últimos 7 días</option>
                <option value={15}>Últimos 15 días</option>
                <option value={30}>Últimos 30 días</option>
                <option value={90}>Últimos 90 días</option>
                <option value={0}>Todo el historial</option>
              </select>
              <select
                value={filters.sort || "oldest"}
                onChange={(event) =>
                  navigate({ sort: event.target.value, page: 1 })
                }
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option value="oldest">Más antiguas primero</option>
                <option value="newest">Más recientes primero</option>
              </select>
            </div>
          </section>

          <section className="space-y-4">
            {(questions?.data || []).length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-neutral-700 dark:bg-neutral-900">
                <p className="font-semibold">
                  No hay preguntas con estos filtros.
                </p>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                  Presiona “Actualizar preguntas” para consultar Mercado Libre.
                </p>
              </div>
            ) : (
              questions.data.map((question) => (
                <QuestionCard
                  key={question.id}
                  question={question}
                  maxAnswerLength={maxAnswerLength}
                />
              ))
            )}
          </section>

          {(questions?.links || []).length > 3 && (
            <nav
              className="flex flex-wrap justify-center gap-1"
              aria-label="Paginación"
            >
              {questions.links.map((link, index) => (
                <Link
                  key={`${link.label}-${index}`}
                  href={link.url || "#"}
                  preserveScroll
                  className={`rounded-lg border px-3 py-2 text-sm transition ${
                    link.active
                      ? "border-indigo-600 bg-indigo-600 text-white"
                      : "border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                  } ${!link.url ? "pointer-events-none opacity-40" : ""}`}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </nav>
          )}
        </div>
      </AppShell>
    </>
  );
}
