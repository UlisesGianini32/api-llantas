<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PosSale;
use App\Services\Pos\CustomerCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerCreditService $creditService
    ) {}

    public function index(Request $request): Response
    {
        $filter = (string) $request->input('filter', 'all');
        $search = trim((string) $request->input('q', ''));

        $query = Customer::query()->with([
            'sales' => function ($q) {
                $q->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
                    ->where('status', '!=', PosSale::STATUS_CANCELLED)
                    ->where('balance_due', '>', 0)
                    ->orderBy('credit_due_date');
            },
        ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

        match ($filter) {
            'with_credit' => $query->withActiveCredit(),
            'overdue' => $query->withOverdueCredit(),
            'no_debt' => $query->withoutDebt(),
            default => null,
        };

        $customers = $query->orderBy('name')->paginate(20)->withQueryString();

        // Transform collection to append live debt & credit stats
        $customers->getCollection()->transform(function (Customer $customer) {
            $totalDebt = (float) $customer->sales->sum('balance_due');
            $today = Carbon::today()->toDateString();
            $hasOverdue = $customer->sales->contains(function (PosSale $sale) use ($today) {
                return $sale->credit_due_date && $sale->credit_due_date->toDateString() < $today;
            });

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'business_name' => $customer->business_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'credit_limit' => (float) $customer->credit_limit,
                'credit_days_default' => (int) $customer->credit_days_default,
                'total_debt' => $totalDebt,
                'available_credit' => max(0.0, (float) $customer->credit_limit - $totalDebt),
                'has_overdue' => $hasOverdue,
                'pending_sales_count' => $customer->sales->count(),
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at?->format('d/m/Y'),
            ];
        });

        $summary = $this->creditService->getCreditPortfolioSummary();

        // Ventas vencidas y por vencer para el panel de notificaciones / alertas
        $urgentSales = PosSale::query()
            ->with(['customer:id,name,phone,business_name'])
            ->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
            ->where('status', '!=', PosSale::STATUS_CANCELLED)
            ->where('balance_due', '>', 0)
            ->whereNotNull('credit_due_date')
            ->where('credit_due_date', '<=', Carbon::today()->addDays(3)->toDateString())
            ->orderBy('credit_due_date')
            ->limit(10)
            ->get();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'summary' => $summary,
            'urgentSales' => $urgentSales,
            'filters' => [
                'filter' => $filter,
                'q' => $search,
            ],
        ]);
    }

    public function show(Customer $customer): Response|JsonResponse
    {
        $customer->load([
            'sales' => function ($q) {
                $q->with(['items', 'cashier:id,name', 'location:id,name'])
                    ->orderByDesc('id');
            },
            'payments' => function ($q) {
                $q->with(['sale:id,sale_number', 'user:id,name'])->orderByDesc('id');
            },
        ]);

        $totalDebt = $customer->total_debt;
        $hasOverdue = $customer->has_overdue_credit;
        $availableCredit = $customer->available_credit;

        $data = [
            'customer' => $customer,
            'total_debt' => $totalDebt,
            'has_overdue' => $hasOverdue,
            'available_credit' => $availableCredit,
        ];

        if (request()->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Customers/Show', $data);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days_default' => ['nullable', 'integer', 'in:7,15,30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['credit_limit'] = (float) ($validated['credit_limit'] ?? 5000);
        $validated['credit_days_default'] = (int) ($validated['credit_days_default'] ?? 15);
        $validated['is_active'] = true;

        $customer = Customer::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Cliente {$customer->name} registrado exitosamente.",
                'customer' => $customer,
            ]);
        }

        return redirect()->route('customers.index')->with('success', "Cliente {$customer->name} creado correctamente.");
    }

    public function update(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days_default' => ['nullable', 'integer', 'in:7,15,30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $customer->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Cliente {$customer->name} actualizado.",
                'customer' => $customer,
            ]);
        }

        return back()->with('success', "Datos de {$customer->name} actualizados.");
    }

    public function addPayment(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer'],
            'pos_sale_id' => ['nullable', 'integer', 'exists:pos_sales,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payment = $this->creditService->registerPayment([
                'customer_id' => $customer->id,
                'pos_sale_id' => $validated['pos_sale_id'] ?? null,
                'amount' => (float) $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'notes' => $validated['notes'] ?? null,
            ], $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Abono de \${$payment->amount} registrado exitosamente (Folio {$payment->receipt_number}).",
                    'payment' => $payment,
                ]);
            }

            return back()->with('success', "Abono de \${$payment->amount} registrado correctamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhere('business_name', 'like', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'customers' => $customers->map(function (Customer $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'business_name' => $c->business_name,
                    'phone' => $c->phone,
                    'credit_limit' => (float) $c->credit_limit,
                    'credit_days_default' => (int) $c->credit_days_default,
                    'total_debt' => $c->total_debt,
                    'available_credit' => $c->available_credit,
                    'has_overdue' => $c->has_overdue_credit,
                ];
            }),
        ]);
    }

    public function voucher(PosSale $posSale): View
    {
        $posSale->loadMissing([
            'customer',
            'cashier',
            'location',
            'items',
            'payments',
        ]);

        return view('pos.credit-voucher', [
            'sale' => $posSale,
        ]);
    }
}
