<?php

namespace App\Services\Pos;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PosSale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerCreditService
{
    /**
     * Registra un abono/pago a una venta a crédito o a la cuenta del cliente.
     *
     * @param  array{
     *     customer_id: int,
     *     pos_sale_id?: int|null,
     *     amount: float,
     *     payment_method: string,
     *     notes?: string|null
     * }  $data
     */
    public function registerPayment(array $data, User $user): CustomerPayment
    {
        $customerId = (int) $data['customer_id'];
        $customer = Customer::findOrFail($customerId);

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('El monto del abono debe ser mayor a cero.');
        }

        $paymentMethod = $data['payment_method'] ?? 'cash';
        $saleId = ! empty($data['pos_sale_id']) ? (int) $data['pos_sale_id'] : null;

        return DB::transaction(function () use ($customer, $saleId, $amount, $paymentMethod, $data, $user) {
            $receiptNumber = $this->generatePaymentReceiptNumber();

            // Si se especificó una venta en particular
            if ($saleId) {
                $sale = PosSale::where('id', $saleId)
                    ->where('customer_id', $customer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($sale->balance_due <= 0) {
                    throw new InvalidArgumentException("La venta {$sale->sale_number} ya se encuentra liquidada.");
                }

                $appliedAmount = min($amount, (float) $sale->balance_due);
                $newBalance = max(0.0, round((float) $sale->balance_due - $appliedAmount, 2));
                $newPaid = round((float) $sale->amount_paid + $appliedAmount, 2);

                $newStatus = $newBalance <= 0
                    ? PosSale::PAYMENT_STATUS_PAID
                    : PosSale::PAYMENT_STATUS_CREDIT_PARTIAL;

                $sale->update([
                    'balance_due' => $newBalance,
                    'amount_paid' => $newPaid,
                    'payment_status' => $newStatus,
                ]);

                $payment = CustomerPayment::create([
                    'customer_id' => $customer->id,
                    'pos_sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                    'payment_date' => now(),
                    'receipt_number' => $receiptNumber,
                    'notes' => $data['notes'] ?? null,
                ]);

                return $payment->load(['customer', 'sale', 'user']);
            }

            // Si no se especificó venta, aplicar en cascada a las ventas a crédito más antiguas (FIFO)
            $pendingSales = PosSale::query()
                ->where('customer_id', $customer->id)
                ->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
                ->where('balance_due', '>', 0)
                ->where('status', '!=', PosSale::STATUS_CANCELLED)
                ->orderBy('credit_due_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingAmount = $amount;
            $firstSaleId = $pendingSales->first()?->id;

            foreach ($pendingSales as $sale) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $toPay = min($remainingAmount, (float) $sale->balance_due);
                $newBalance = max(0.0, round((float) $sale->balance_due - $toPay, 2));
                $newPaid = round((float) $sale->amount_paid + $toPay, 2);
                $newStatus = $newBalance <= 0
                    ? PosSale::PAYMENT_STATUS_PAID
                    : PosSale::PAYMENT_STATUS_CREDIT_PARTIAL;

                $sale->update([
                    'balance_due' => $newBalance,
                    'amount_paid' => $newPaid,
                    'payment_status' => $newStatus,
                ]);

                $remainingAmount = round($remainingAmount - $toPay, 2);
            }

            $payment = CustomerPayment::create([
                'customer_id' => $customer->id,
                'pos_sale_id' => $firstSaleId,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_date' => now(),
                'receipt_number' => $receiptNumber,
                'notes' => $data['notes'] ?? null,
            ]);

            return $payment->load(['customer', 'sale', 'user']);
        });
    }

    /**
     * Resumen general de cartera de crédito y alertas.
     *
     * @return array<string, mixed>
     */
    public function getCreditPortfolioSummary(): array
    {
        $today = Carbon::today()->toDateString();
        $in3Days = Carbon::today()->addDays(3)->toDateString();

        $totalCustomers = Customer::count();
        $activeWithCredit = Customer::withActiveCredit()->count();
        $withOverdueCredit = Customer::withOverdueCredit()->count();
        $withoutDebt = Customer::withoutDebt()->count();

        // Totales adeudados
        $totalDebt = (float) PosSale::query()
            ->pendingCredit()
            ->sum('balance_due');

        $totalOverdueDebt = (float) PosSale::query()
            ->overdueCredit()
            ->sum('balance_due');

        // Ventas por vencer pronto (próximos 3 días)
        $dueSoonSalesCount = PosSale::query()
            ->dueSoonCredit(3)
            ->count();

        // Ventas ya vencidas
        $overdueSalesCount = PosSale::query()
            ->overdueCredit()
            ->count();

        return [
            'total_customers' => $totalCustomers,
            'active_with_credit' => $activeWithCredit,
            'with_overdue_credit' => $withOverdueCredit,
            'without_debt' => $withoutDebt,
            'total_debt' => $totalDebt,
            'total_overdue_debt' => $totalOverdueDebt,
            'due_soon_sales_count' => $dueSoonSalesCount,
            'overdue_sales_count' => $overdueSalesCount,
        ];
    }

    protected function generatePaymentReceiptNumber(): string
    {
        $prefix = 'ABN-'.Carbon::now()->format('Ymd').'-';
        $last = CustomerPayment::query()
            ->where('receipt_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('receipt_number');

        $seq = 1;
        if ($last) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        }

        $receiptNumber = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        while (CustomerPayment::where('receipt_number', $receiptNumber)->exists()) {
            $seq++;
            $receiptNumber = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        }

        return $receiptNumber;
    }
}
