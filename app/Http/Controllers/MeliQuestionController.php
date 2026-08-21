<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\MeliQuestion;
use App\Services\MeliQuestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MeliQuestionController extends Controller
{
    public function index(Request $request, MeliQuestionService $service): Response
    {
        $owner = $request->user();
        $accounts = $owner->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();
        $selectedAccount = $this->resolveSelectedAccount($request, $accounts);
        $syncError = null;

        if (
            $selectedAccount
            && filled($selectedAccount->access_token)
            && ! MeliQuestion::query()
                ->where('user_id', $owner->id)
                ->where('meli_account_id', $selectedAccount->id)
                ->exists()
        ) {
            try {
                $service->syncAccount($selectedAccount, 2);
            } catch (\Throwable $e) {
                report($e);
                $syncError = $e->getMessage();
            }
        }

        $status = strtolower((string) $request->query('status', 'unanswered'));
        if (! in_array($status, ['unanswered', 'answered', 'all'], true)) {
            $status = 'unanswered';
        }

        $sort = strtolower((string) $request->query('sort', 'oldest'));
        if (! in_array($sort, ['oldest', 'newest'], true)) {
            $sort = 'oldest';
        }

        $search = trim((string) $request->query('search', ''));
        $days = (int) $request->query('days', 15);
        if (! in_array($days, [0, 7, 15, 30, 90], true)) {
            $days = 15;
        }

        $base = MeliQuestion::query()
            ->where('user_id', $owner->id)
            ->when($selectedAccount, fn (Builder $query) => $query->where(
                'meli_account_id',
                $selectedAccount->id
            ))
            ->when(! $selectedAccount, fn (Builder $query) => $query->whereRaw('1 = 0'));

        $questionsQuery = (clone $base)
            ->when($status === 'unanswered', fn (Builder $query) => $query->where('status', 'UNANSWERED'))
            ->when($status === 'answered', fn (Builder $query) => $query->where('status', 'ANSWERED'))
            ->when($days > 0, fn (Builder $query) => $query->where(
                'question_created_at',
                '>=',
                now()->subDays($days)
            ))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $nested) use ($search) {
                    $like = '%'.$search.'%';
                    $nested->where('text', 'like', $like)
                        ->orWhere('item_title', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('item_id', 'like', $like)
                        ->orWhere('question_id', 'like', $like);
                });
            });

        if ($sort === 'newest') {
            $questionsQuery->orderByDesc('question_created_at')->orderByDesc('id');
        } else {
            $questionsQuery->orderBy('question_created_at')->orderBy('id');
        }

        $questions = $questionsQuery
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MeliQuestion $question): array => [
                'id' => $question->id,
                'question_id' => $question->question_id,
                'item_id' => $question->item_id,
                'status' => $question->status,
                'text' => $question->text,
                'answer_text' => $question->answer_text,
                'answer_status' => $question->answer_status,
                'question_created_at' => $question->question_created_at?->toIso8601String(),
                'answered_at' => $question->answered_at?->toIso8601String(),
                'deleted_from_listing' => $question->deleted_from_listing,
                'hold' => $question->hold,
                'suspected_spam' => $question->suspected_spam,
                'item_title' => $question->item_title,
                'item_thumbnail' => $question->item_thumbnail,
                'item_permalink' => $question->item_permalink,
                'item_price' => $question->item_price !== null
                    ? (float) $question->item_price
                    : null,
                'currency_id' => $question->currency_id,
                'sku' => $question->sku,
                'available_quantity' => $question->available_quantity,
                'last_synced_at' => $question->last_synced_at?->toIso8601String(),
            ]);

        $stats = [
            'unanswered' => (clone $base)->where('status', 'UNANSWERED')->count(),
            'answered_15_days' => (clone $base)
                ->where('status', 'ANSWERED')
                ->where('answered_at', '>=', now()->subDays(15))
                ->count(),
            'total_15_days' => (clone $base)
                ->where('question_created_at', '>=', now()->subDays(15))
                ->count(),
        ];

        $responseTime = null;
        if ($selectedAccount && filled($selectedAccount->access_token)) {
            try {
                $responseTime = Cache::remember(
                    'meli_questions_response_time_'.$selectedAccount->id,
                    now()->addMinutes(10),
                    fn () => $service->responseTime($selectedAccount)
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return Inertia::render('MeliQuestions/Index', [
            'questions' => $questions,
            'accounts' => $accounts->map(static fn (MeliAccount $account): array => [
                'id' => $account->id,
                'meli_user_id' => (string) $account->meli_user_id,
                'nickname' => filled($account->nickname)
                    ? $account->nickname
                    : 'Cuenta '.$account->meli_user_id,
                'is_default' => (bool) $account->is_default,
                'has_access_token' => filled($account->access_token),
            ])->values(),
            'selectedAccountId' => $selectedAccount?->id,
            'selectedAccountLinked' => (bool) ($selectedAccount && filled($selectedAccount->access_token)),
            'filters' => compact('status', 'sort', 'search', 'days'),
            'stats' => $stats,
            'responseTime' => $responseTime,
            'syncError' => $syncError,
            'maxAnswerLength' => 2000,
        ]);
    }

    public function sync(Request $request, MeliQuestionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = MeliAccount::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail((int) $validated['account_id']);

        try {
            $result = $service->syncAccount($account, 6);
            Cache::forget('meli_questions_response_time_'.$account->id);

            return back()->with(
                'ok',
                'Preguntas actualizadas: '.$result['saved'].' recibidas de Mercado Libre.'
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('err', $e->getMessage());
        }
    }

    public function answer(
        Request $request,
        MeliQuestionService $service,
        MeliQuestion $question
    ): RedirectResponse {
        abort_unless((int) $question->user_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        try {
            $service->answer($question, (string) $validated['text']);
            Cache::forget('meli_questions_response_time_'.$question->meli_account_id);

            return back()->with('ok', 'Respuesta enviada correctamente a Mercado Libre.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('err', $e->getMessage());
        }
    }

    private function resolveSelectedAccount(Request $request, $accounts): ?MeliAccount
    {
        if ($accounts->isEmpty()) {
            return null;
        }

        $requestedId = $request->integer('account_id');
        if ($requestedId) {
            $requested = $accounts->firstWhere('id', $requestedId);
            if ($requested) {
                return $requested;
            }
        }

        return $accounts->firstWhere('is_default', true) ?? $accounts->first();
    }
}
