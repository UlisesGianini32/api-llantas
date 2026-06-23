<?php



namespace App\Console\Commands;



use App\Services\SyscomApiService;

use App\Support\SyscomCarritoPagoHelper;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\Http;



class SyscomListOrderPaymentMethodsCommand extends Command

{

    protected $signature = 'syscom:order-pago-methods';



    protected $description = 'Lista métodos de pago SYSCOM (GET /carrito/pago) para configurar SYSCOM_ORDER_METODO_PAGO_ID';



    public function handle(SyscomApiService $api): int

    {

        try {

            $token = $api->getAccessToken();

        } catch (\Throwable $e) {

            $this->error($e->getMessage());



            return self::FAILURE;

        }



        $baseUrl = rtrim((string) config('services.syscom.base_url', 'https://developers.syscom.mx/api/v1'), '/');

        $resp = Http::withToken($token)

            ->acceptJson()

            ->timeout(30)

            ->get($baseUrl . '/carrito/pago');



        if (! $resp->successful()) {

            $this->error('SYSCOM /carrito/pago: ' . $resp->status() . ' ' . $resp->body());



            return self::FAILURE;

        }



        $json = $resp->json();

        $flat = SyscomCarritoPagoHelper::flattenPaymentMethods($json);



        if ($flat === []) {

            $this->warn('Sin métodos en la respuesta.');



            return self::SUCCESS;

        }



        $this->info('GET /carrito/pago — para POST /carrito/generar:');

        $this->line('  metodo_pago = metodo_pago_si_pue (ID interno forma.pue, NO codigo_sat)');

        $this->line('  tipo_pago   = pue|ppd → PDF «Método de pago» (una sola exhibición)');

        $this->line('  codigo_sat  → PDF «Forma de pago» (04 = tarjeta crédito)');

        $this->newLine();



        foreach ($flat as $row) {

            $parts = array_filter([

                (string) ($row['nombre'] ?? ''),

                ($row['titulo'] ?? '') !== '' ? '('.$row['titulo'].')' : null,

                ($row['codigo_sat'] ?? '') !== '' ? 'codigo_sat='.$row['codigo_sat'] : null,

                ($row['metodo_pago_pue'] ?? '') !== '' ? 'metodo_pago_si_pue='.$row['metodo_pago_pue'] : null,

                ($row['metodo_pago_ppd'] ?? '') !== '' ? 'metodo_pago_si_ppd='.$row['metodo_pago_ppd'] : null,

            ]);

            $this->line('  '.implode(' | ', $parts));

        }



        $tipoPago = (string) config('syscom.orders_from_meli.tipo_pago', 'pue');

        $prefer = (string) config('syscom.orders_from_meli.metodo_pago_prefer', 'sucursal+tarjeta+credito');

        $sat = (string) config('syscom.orders_from_meli.forma_pago_sat', '04');

        $resolved = SyscomCarritoPagoHelper::resolvePaymentForOrder($json, $tipoPago, $prefer);



        $this->newLine();

        $this->info(sprintf(

            'Selección automática: metodo_pago=%s | codigo_sat=%s | %s',

            $resolved['metodo_pago'] !== '' ? $resolved['metodo_pago'] : '(vacío)',

            $resolved['codigo_sat'] ?? '—',

            $resolved['label'] !== '' ? $resolved['label'] : 'sin coincidencia'

        ));

        $this->comment('En .env (recomendado):');

        $this->comment('  SYSCOM_ORDER_METODO_PAGO_ID=<metodo_pago_si_pue de tarjeta en sucursal>');

        $this->comment('  SYSCOM_ORDER_METODO_PAGO_PREFER=sucursal+tarjeta+credito');

        $this->comment('  SYSCOM_ORDER_FORMA_PAGO_SAT='.$sat);

        $this->comment('  SYSCOM_ORDER_TIPO_PAGO=pue');

        $this->comment('«CONDICIONADO A PAGO» = pedido creado; SYSCOM espera confirmar el cobro en portal/cartera.');



        return self::SUCCESS;

    }

}


