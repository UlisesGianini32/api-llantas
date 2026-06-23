<x-layouts.app title="Productos Compuestos">

<div class="space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Productos compuestos</h1>

    {{-- BUSCADOR + POR PÁGINA --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        {{-- 🔍 Buscador --}}
        <form method="GET" action="{{ route('productos.index') }}" class="w-full sm:max-w-xl">
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="dir"  value="{{ request('dir') }}">
            <input type="hidden" name="per_page" value="{{ request('per_page', 25) }}">
            <input type="hidden" name="ml_status" value="{{ request('ml_status', $mlStatus ?? 'all') }}">

            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Buscar por SKU, título o MLM..."
                class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 placeholder-zinc-400
                       focus:outline-none focus:ring-2 focus:ring-indigo-500
                       dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
            >
        </form>

        {{-- 🔽 Por página --}}
        <form method="GET" action="{{ route('productos.index') }}" class="w-full sm:w-auto">
            <input type="hidden" name="search" value="{{ request('search') }}">
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="dir"  value="{{ request('dir') }}">
            <input type="hidden" name="ml_status" value="{{ request('ml_status', $mlStatus ?? 'all') }}">

            <div class="flex items-center gap-2">
                <span class="text-sm text-zinc-600 dark:text-gray-300">Por página</span>

                <select name="per_page"
                        onchange="this.form.submit()"
                        class="w-full sm:w-auto rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900
                               focus:outline-none focus:ring-2 focus:ring-indigo-500
                               dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
                    @foreach ([10,25,50,100,200] as $n)
                        <option value="{{ $n }}" @selected((int)request('per_page', 25) === $n)>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    {{-- ✅ FILTRO ESTATUS ML --}}
    @php
        $current = request('ml_status', $mlStatus ?? 'all');

        $base = [
            'search'   => request('search'),
            'per_page' => request('per_page', 25),
            'sort'     => request('sort'),
            'dir'      => request('dir'),
        ];

        $tabBase = "group inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold
                   transition-all duration-200 outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2
                   ring-offset-white dark:ring-offset-neutral-900";

        $tabIdle = "text-zinc-700 hover:text-zinc-900 hover:bg-zinc-100/70
                   dark:text-neutral-200 dark:hover:text-white dark:hover:bg-neutral-800/70";

        $badgeBase = "inline-flex items-center justify-center min-w-[2rem] h-6 px-2 rounded-full text-xs font-bold";
        $badgeIdle = "bg-zinc-200/70 text-zinc-800 group-hover:bg-zinc-300/70
                     dark:bg-neutral-700/70 dark:text-neutral-100 dark:group-hover:bg-neutral-600/70";

        $badgeActive = "bg-black/15 text-inherit";

        $tabs = [
            ['key' => 'all',          'label' => 'Todas',        'count' => $counts['all'] ?? null],
            ['key' => 'no_publicada', 'label' => 'No publicada', 'count' => $counts['no_publicada'] ?? null],
            ['key' => 'activa',       'label' => 'Activa',       'count' => $counts['activa'] ?? null],
            ['key' => 'pausada',      'label' => 'Pausada',      'count' => $counts['pausada'] ?? null],
            ['key' => 'en_revision',  'label' => 'En revisión',  'count' => $counts['en_revision'] ?? null],
        ];
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-neutral-400">
                Filtro de MercadoLibre
            </div>

            <div class="text-xs text-zinc-500 dark:text-neutral-400">
                Mostrando:
                <span class="font-semibold text-zinc-700 dark:text-neutral-200">
                    {{ collect($tabs)->firstWhere('key', $current)['label'] ?? 'Todas' }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-zinc-200 bg-white/60 p-2
                    shadow-sm shadow-zinc-900/5 backdrop-blur
                    dark:border-neutral-800 dark:bg-neutral-900/50 dark:shadow-none">

            @foreach($tabs as $t)
                @php
                    $isActive = $current === $t['key'];

                    $activeColor = match($t['key']) {
                        'all' => "bg-indigo-600 text-white shadow-sm shadow-indigo-600/20
                                 dark:bg-indigo-500 dark:shadow-indigo-500/25",

                        'no_publicada' => "bg-slate-600 text-white shadow-sm shadow-slate-600/20
                                          dark:bg-slate-500 dark:shadow-slate-500/25",

                        'activa' => "bg-emerald-600 text-white shadow-sm shadow-emerald-600/20
                                    dark:bg-emerald-500 dark:shadow-emerald-500/25",

                        'pausada' => "bg-amber-400 text-black shadow-sm shadow-amber-400/25
                                     dark:bg-amber-300 dark:shadow-amber-300/25",

                        'en_revision' => "bg-purple-600 text-white shadow-sm shadow-purple-600/20
                                         dark:bg-purple-500 dark:shadow-purple-500/25",

                        default => "bg-indigo-600 text-white shadow-sm shadow-indigo-600/20
                                   dark:bg-indigo-500 dark:shadow-indigo-500/25",
                    };
                @endphp

                <a
                    href="{{ route('productos.index', array_merge($base, ['ml_status' => $t['key'], 'page' => null])) }}"
                    class="{{ $tabBase }} {{ $isActive ? $activeColor : $tabIdle }}"
                >
                    <span>{{ $t['label'] }}</span>

                    @if(!is_null($t['count']))
                        <span class="{{ $badgeBase }} {{ $isActive ? $badgeActive : $badgeIdle }}">
                            {{ number_format((int)$t['count']) }}
                        </span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 text-sm text-green-800 bg-green-100 rounded-lg dark:bg-green-900 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @php
        $sort = request('sort', $sort ?? 'id');
        $dir  = request('dir',  $dir  ?? 'desc');
        $dir  = in_array($dir, ['asc','desc']) ? $dir : 'desc';

        $toggleDir = fn($col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';

        $sortLink = fn($col) =>
            request()->fullUrlWithQuery([
                'sort' => $col,
                'dir'  => $toggleDir($col),
                'page' => null,
            ]);

        $arrow = function($col) use ($sort, $dir) {
            $active = $sort === $col;
            $upActive   = $active && $dir === 'asc';
            $downActive = $active && $dir === 'desc';

            $upClass = $upActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400 dark:text-zinc-500';
            $dnClass = $downActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400 dark:text-zinc-500';

            return '
            <span class="ml-1 inline-flex flex-col items-center justify-center select-none">
                <svg class="h-3 w-3 '.$upClass.' transition-colors" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 0 1-1.06.02L10 9.31l-3.71 3.5a.75.75 0 1 1-1.04-1.08l4.24-4a.75.75 0 0 1 1.04 0l4.24 4a.75.75 0 0 1 .02 1.06Z" clip-rule="evenodd"/>
                </svg>
                <svg class="h-3 w-3 -mt-1 '.$dnClass.' transition-colors" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06-.02L10 10.69l3.71-3.5a.75.75 0 1 1 1.04 1.08l-4.24 4a.75.75 0 0 1-1.04 0l-4.24-4a.75.75 0 0 1-.02-1.06Z" clip-rule="evenodd"/>
                </svg>
            </span>';
        };

        $statusBadge = function(?string $status) {
            $status = strtolower((string) $status);

            return match ($status) {
                'active' => ['ACTIVA', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200'],
                'paused' => ['PAUSADA', 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'],
                'under_review' => ['EN REVISIÓN', 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'],
                'closed' => ['CERRADA', 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200'],
                'suspended' => ['BLOQUEADA', 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'],
                '' => ['NO PUBLICADA', 'bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300'],
                default => [strtoupper($status), 'bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300'],
            };
        };
    @endphp

    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-100 text-zinc-600 dark:bg-neutral-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <a href="{{ $sortLink('sku') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                SKU {!! $arrow('sku') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3"><span class="font-medium">Marca</span></th>
                        <th class="px-4 py-3"><span class="font-medium">Medida</span></th>

                        <th class="px-4 py-3 text-center">
                            <span class="font-medium">Estatus ML</span>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('meli_pubs_count') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Publicaciones {!! $arrow('meli_pubs_count') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('descripcion') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Descripción {!! $arrow('descripcion') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-right">
                            <a href="{{ $sortLink('costo') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Costo {!! $arrow('costo') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-right">
                            <a href="{{ $sortLink('precio_ML') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Precio ML {!! $arrow('precio_ML') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('title_familyname') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Título {!! $arrow('title_familyname') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-center">
                            <a href="{{ $sortLink('stock') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Stock {!! $arrow('stock') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-center">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($compuestos as $compuesto)
                        @php
                            $pubsCount = (int) ($compuesto->meli_pubs_count ?? 0);
                            $latestPub = $compuesto->meliPublications->first() ?? null;

                            [$label, $cls] = $pubsCount > 0
                                ? $statusBadge($latestPub->status ?? '')
                                : $statusBadge('');
                        @endphp

                        <tr class="border-t border-zinc-200 hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-800">

                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">
                                {{ $compuesto->sku }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $compuesto->llanta->marca ?? '—' }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $compuesto->llanta->medida ?? '—' }}
                            </td>

                            <td class="px-4 py-2 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $cls }}">
                                    {{ $label }}
                                </span>
                            </td>

                            <td class="px-4 py-2">
                                @if ($pubsCount <= 0)
                                    <span class="text-zinc-500 dark:text-gray-400">—</span>
                                @else
                                    @php $pubs = $compuesto->meliPublications; @endphp

                                    @if ($pubsCount <= 2)
                                        <div class="flex flex-col gap-1">
                                            @foreach ($pubs as $p)
                                                <a href="{{ $p->permalink ?? '#' }}" target="_blank"
                                                   class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-mono">
                                                    {{ $p->mlm }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        <details class="group">
                                            <summary class="cursor-pointer select-none text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 text-sm font-semibold">
                                                {{ $pubsCount }} publicaciones
                                                <span class="text-xs text-zinc-500 dark:text-gray-400 font-normal"> (ver)</span>
                                            </summary>
                                            <div class="mt-2 space-y-1">
                                                @foreach ($pubs as $p)
                                                    <div class="flex items-center justify-between gap-2">
                                                        <a href="{{ $p->permalink ?? '#' }}" target="_blank"
                                                           class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-mono">
                                                            {{ $p->mlm }}
                                                        </a>
                                                        <span class="text-[11px] text-zinc-500 dark:text-gray-400">
                                                            {{ $p->status ?? '—' }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                @endif
                            </td>

                            <td class="px-4 py-2 text-zinc-500 dark:text-gray-400">
                                {{ $compuesto->descripcion ?? '—' }}
                            </td>

                            <td class="px-4 py-2 text-right">
                                ${{ number_format($compuesto->costo, 2) }}
                            </td>

                            <td class="px-4 py-2 text-right text-emerald-600 dark:text-green-400 font-semibold">
                                ${{ number_format($compuesto->precio_ML, 2) }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $compuesto->title_familyname }}
                            </td>

                            <td class="px-4 py-2 text-center font-bold
                                {{ $compuesto->stock <= 4 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-green-400' }}">
                                {{ $compuesto->stock }}
                            </td>

                            <td class="px-4 py-2 text-center">
    @php
        $hasPub = (int)($compuesto->meli_pubs_count ?? 0) > 0 && $latestPub;
    @endphp

    {{-- Arriba: Editar --}}
    <div class="flex justify-end">
        <a href="{{ route('productos.edit', [
                'id'       => $compuesto->id,
                'page'     => request('page', 1),
                'search'   => request('search', ''),
                'sort'     => request('sort'),
                'dir'      => request('dir'),
                'per_page' => request('per_page', 25),
                'ml_status' => request('ml_status', $mlStatus ?? 'all'),
            ]) }}"
           class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
            Editar
        </a>
    </div>

    {{-- Abajo: Status · Republicar · Publicar (como Llantas) --}}
    <div class="mt-2 flex items-center justify-between gap-3">
        {{-- Status --}}
        @if($hasPub)
            <form method="POST" action="{{ route('ml.publications.refresh', $latestPub) }}">
                @csrf
                <button type="submit"
                        class="px-3 py-1 rounded-md border border-zinc-600/60 text-xs text-zinc-200
                               hover:bg-zinc-800/60 hover:text-white
                               dark:border-neutral-700 dark:hover:bg-neutral-800">
                    Status
                </button>
            </form>
        @else
            <button type="button" disabled
                    class="px-3 py-1 rounded-md border border-zinc-700/40 text-xs text-zinc-500 cursor-not-allowed
                           dark:border-neutral-800">
                Status
            </button>
        @endif

        <div class="flex items-center gap-3">
            {{-- Republicar --}}
            @if($hasPub)
                <form method="POST" action="{{ route('productos.ml.republish', $compuesto->id) }}">
                    @csrf
                    <button type="submit"
                            class="text-sm text-zinc-200 hover:text-white">
                        Republicar
                    </button>
                </form>
            @else
                <span class="text-sm text-zinc-500">Republicar</span>
            @endif

            {{-- Publicar --}}
            <a href="{{ route('productos.ml.publish.form', $compuesto->id) }}"
               class="text-sm text-zinc-200 hover:text-white">
                Publicar
            </a>
        </div>
    </div>
</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-6 text-center text-zinc-500 dark:text-gray-400">
                                No se encontraron productos con ese filtro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $compuestos->appends(request()->query())->links() }}

</div>

</x-layouts.app>