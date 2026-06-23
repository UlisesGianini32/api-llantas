<x-layouts.app :title="__('Mis Productos')">
    <section class="bg-gray-50 dark:bg-gray-900 py-4 sm:py-6">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    {{ session('error') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-md sm:rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">

                <div class="p-4 border-b dark:border-gray-700">
                    <form class="flex items-center max-w-4xl mx-auto" method="GET" action="{{ route('producto.index') }}">
                        <input type="hidden" name="sort" value="{{ request('sort') }}">
                        <input type="hidden" name="dir" value="{{ request('dir') }}">
                        <input type="hidden" name="perPage" value="{{ request('perPage', 25) }}">
                        <input type="hidden" name="official_store_id" value="{{ request('official_store_id', $officialStoreId ?? '') }}">

                        @foreach ((array) request('categories') as $cat)
                            <input type="hidden" name="categories[]" value="{{ $cat }}">
                        @endforeach

                        <div class="relative w-full">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <input
                                type="text"
                                name="search"
                                value="{{ request('search') }}"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-base rounded-lg block w-full pl-12 p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                                placeholder="Buscar por nombre, ML ID, SKU, marca o categoría Shopify..."
                            >
                        </div>
                    </form>
                </div>

                <div class="p-4 border-b dark:border-gray-700 space-y-4">
                    <div class="w-full">
                        <label for="categories" class="block mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Categorías ML (Ctrl/Cmd + clic)
                        </label>
                        <select
                            id="categories"
                            multiple
                            name="categories[]"
                            class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white h-64 overflow-y-auto focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                            onchange="applyCategoryFilter()"
                        >
                            <option value="">Todas las categorías</option>
                            @foreach ($categories as $catId => $catName)
                                <option value="{{ $catId }}" {{ in_array($catId, (array) request('categories')) ? 'selected' : '' }}>
                                    {{ $catName ?? $catId }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-col xl:flex-row gap-4 items-end">
                        <div class="w-full sm:w-52">
                            <label for="official_store_id" class="block mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Tienda oficial
                            </label>
                            <select
                                id="official_store_id"
                                name="official_store_id"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                                onchange="applyStoreFilter()"
                            >
                                <option value="" {{ request('official_store_id', $officialStoreId ?? '') === '' ? 'selected' : '' }}>
                                    Todas
                                </option>
                                <option value="{{ $tobeautyStoreId }}" {{ request('official_store_id', $officialStoreId ?? '') == $tobeautyStoreId ? 'selected' : '' }}>
                                    TOBEAUTY ({{ $tobeautyStoreId }})
                                </option>
                            </select>
                        </div>

                        <div class="w-full sm:w-40">
                            <label for="perPage" class="block mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Por página
                            </label>
                            <select
                                id="perPage"
                                name="perPage"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                                onchange="applyPerPageFilter()"
                            >
                                <option value="10" {{ request('perPage', 25) == 10 ? 'selected' : '' }}>10</option>
                                <option value="25" {{ request('perPage', 25) == 25 ? 'selected' : '' }}>25</option>
                                <option value="50" {{ request('perPage', 25) == 50 ? 'selected' : '' }}>50</option>
                                <option value="100" {{ request('perPage', 25) == 100 ? 'selected' : '' }}>100</option>
                            </select>
                        </div>

                        <div class="w-full sm:w-auto flex flex-wrap gap-3 items-end">
                            <form method="POST" action="{{ route('producto.sync') }}"
                                  onsubmit="return confirm('¿Sincronizar todos los productos desde MercadoLibre?\nEsto puede tardar varios minutos.')">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-6 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 dark:bg-indigo-700 dark:hover:bg-indigo-800 transition duration-200 shadow-md">
                                    🔄 Sincronizar todos
                                </button>
                            </form>

                            <a href="{{ route('producto.export.shopify.tobeauty', request()->query()) }}"
                               class="inline-flex items-center px-6 py-3 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 focus:ring-4 focus:ring-purple-300 transition duration-200 shadow-md">
                                ⬇ Exportar Shopify TOBEAUTY
                            </a>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    @php
                        $sort = request('sort', 'name');
                        $dir = request('dir', 'asc');
                        $dir = in_array(strtolower($dir), ['asc', 'desc']) ? $dir : 'asc';

                        $toggleDir = fn($col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';

                        $sortLink = fn($col) => request()->fullUrlWithQuery([
                            'sort' => $col,
                            'dir' => $toggleDir($col),
                            'page' => null,
                            'search' => request('search'),
                            'perPage' => request('perPage'),
                            'categories' => request('categories'),
                            'official_store_id' => request('official_store_id', $officialStoreId ?? ''),
                        ]);

                        $arrow = function($col) use ($sort, $dir) {
                            $active = $sort === $col;
                            $upActive = $active && $dir === 'asc';
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
                    @endphp

                    <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                        <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3">Imagen</th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('name') }}" class="inline-flex items-center">
                                        Nombre {!! $arrow('name') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('brand') }}" class="inline-flex items-center">
                                        Marca {!! $arrow('brand') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('ml') }}" class="inline-flex items-center">
                                        ML ID {!! $arrow('ml') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('sku') }}" class="inline-flex items-center">
                                        SKU {!! $arrow('sku') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('official_store_id') }}" class="inline-flex items-center">
                                        Tienda Oficial {!! $arrow('official_store_id') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('category_name') }}" class="inline-flex items-center">
                                        Categoría ML {!! $arrow('category_name') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('shopify_category_name') }}" class="inline-flex items-center">
                                        Categoría Shopify {!! $arrow('shopify_category_name') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3">
                                    Fuente Shopify
                                </th>
                                <th class="px-4 py-3 text-right cursor-pointer">
                                    <a href="{{ $sortLink('price') }}" class="inline-flex items-center justify-end w-full">
                                        Precio {!! $arrow('price') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-right cursor-pointer">
                                    <a href="{{ $sortLink('stock') }}" class="inline-flex items-center justify-end w-full">
                                        Stock {!! $arrow('stock') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 cursor-pointer">
                                    <a href="{{ $sortLink('status_ml') }}" class="inline-flex items-center">
                                        Estado ML {!! $arrow('status_ml') !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($products as $product)
                                <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-4 py-4">
                                        @if (!empty($product->thumbnail))
                                            <img src="{{ $product->thumbnail }}"
                                                 alt="{{ $product->name }}"
                                                 class="w-14 h-14 object-cover rounded-lg border border-gray-200 dark:border-gray-600 bg-white">
                                        @elseif (!empty($product->pictures[0] ?? null))
                                            <img src="{{ $product->pictures[0] }}"
                                                 alt="{{ $product->name }}"
                                                 class="w-14 h-14 object-cover rounded-lg border border-gray-200 dark:border-gray-600 bg-white">
                                        @else
                                            <div class="w-14 h-14 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-xs text-gray-400">
                                                Sin foto
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-4 py-4 font-medium text-gray-900 dark:text-white max-w-xs">
                                        <div class="line-clamp-2">{{ $product->name }}</div>
                                    </td>

                                    <td class="px-4 py-4">
                                        {{ $product->brand ?: '--' }}
                                    </td>

                                    <td class="px-4 py-4 font-mono text-blue-600 dark:text-blue-400">
                                        {{ $product->ml }}
                                    </td>

                                    <td class="px-4 py-4 max-w-xs truncate">
                                        {{ $product->sku ?? '--' }}
                                    </td>

                                    <td class="px-4 py-4">
                                        {{ $product->official_store_id ?? '--' }}
                                    </td>

                                    <td class="px-4 py-4 max-w-xs">
                                        <div class="line-clamp-2">
                                            {{ $product->category_name ?? $product->category_id ?? '--' }}
                                        </div>
                                        @if (!empty($product->category_id))
                                            <div class="mt-1 text-xs text-gray-400 font-mono">
                                                {{ $product->category_id }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-4 py-4 max-w-xs">
                                        @if (!empty($product->shopify_category_name))
                                            <div class="line-clamp-2 text-gray-900 dark:text-gray-100">
                                                {{ $product->shopify_category_name }}
                                            </div>
                                            @if (!empty($product->shopify_category_id))
                                                <div class="mt-1 text-xs text-indigo-600 dark:text-indigo-400 font-mono break-all">
                                                    {{ $product->shopify_category_id }}
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">Sin resolver</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-4">
                                        @if (!empty($product->shopify_category_source))
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                                {{ $product->shopify_category_source }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">--</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-4 text-right">
                                        ${{ number_format((float) $product->price, 2) }}
                                    </td>

                                    <td class="px-4 py-4 text-right font-bold
                                        {{ $product->stock <= 5 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                        {{ $product->stock }}
                                    </td>

                                    <td class="px-4 py-4 font-medium text-center
                                        {{ $product->status_ml === 'active' ? 'text-green-600 dark:text-green-400' : '' }}
                                        {{ $product->status_ml === 'paused' ? 'text-yellow-600 dark:text-yellow-400' : '' }}
                                        {{ $product->status_ml === 'closed' ? 'text-red-600 dark:text-red-400' : '' }}
                                        {{ !$product->status_ml ? 'text-gray-500 dark:text-gray-400' : '' }}">
                                        {{ ucfirst($product->status_ml ?? 'desconocido') }}
                                    </td>

                                    <td class="px-4 py-4 text-center">
                                        <div class="flex flex-col sm:flex-row gap-2 justify-center">
                                            @if (!empty($product->permalink))
                                                <a href="{{ $product->permalink }}"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="inline-flex items-center justify-center px-3 py-2 text-xs font-medium text-white bg-sky-600 rounded-lg hover:bg-sky-700 focus:ring-4 focus:ring-sky-300">
                                                    Ver ML
                                                </a>
                                            @endif

                                            @if (!empty($product->ml))
                                                <a href="{{ route('producto.ml.republish.form', ['ml' => $product->ml]) }}"
                                                   class="inline-flex items-center justify-center px-3 py-2 text-xs font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 focus:ring-4 focus:ring-amber-300">
                                                    Republicar
                                                </a>
                                            @else
                                                <span class="text-gray-400">--</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No hay productos registrados. Haz clic en "Sincronizar todos" para obtenerlos desde MercadoLibre.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4 flex justify-center">
                    {{ $products->appends(request()->query())->links('vendor.pagination.tailwind') }}
                </div>
            </div>
        </div>
    </section>

    <script>
        function applyCategoryFilter() {
            const select = document.getElementById('categories');
            const selected = Array.from(select.selectedOptions)
                .map(opt => opt.value)
                .filter(v => v !== '');

            const url = new URL(window.location);
            url.searchParams.delete('categories[]');
            selected.forEach(cat => url.searchParams.append('categories[]', cat));
            url.searchParams.set('page', 1);
            window.location = url;
        }

        function applyPerPageFilter() {
            const select = document.getElementById('perPage');
            const url = new URL(window.location);
            url.searchParams.set('perPage', select.value);
            url.searchParams.set('page', 1);
            window.location = url;
        }

        function applyStoreFilter() {
            const select = document.getElementById('official_store_id');
            const url = new URL(window.location);

            if (select.value) {
                url.searchParams.set('official_store_id', select.value);
            } else {
                url.searchParams.delete('official_store_id');
            }

            url.searchParams.set('page', 1);
            window.location = url;
        }
    </script>
</x-layouts.app>