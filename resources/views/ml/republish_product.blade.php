<x-layouts.app :title="__('Republicar publicación')">
    <section class="bg-gray-50 dark:bg-gray-900 py-4 sm:py-6">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">

            @php
                $isUserProduct = $isUserProduct ?? (!empty($item['family_name']) || !empty($item['user_product_id']));
                $currentLabel = $isUserProduct
                    ? ($item['family_name'] ?? $item['title'] ?? '--')
                    : ($item['title'] ?? $item['family_name'] ?? '--');

                $selectedStoreMode = old('official_store_mode', $defaultOfficialStoreMode ?? 'tobeauty');
            @endphp

            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded whitespace-pre-wrap">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-md rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        Republicar publicación
                    </h1>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        Se copiarán las fotos, descripción y configuración base de la publicación original.
                        Solo cambiarás {{ $isUserProduct ? 'el nombre de familia y el precio' : 'el título y el precio' }}.
                    </p>
                </div>

                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">MLM original</div>
                            <div class="mt-1 font-mono text-sm text-gray-900 dark:text-white">{{ $ml }}</div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">SKU</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-white">
                                {{ $pub->sku ?? ($item['seller_custom_field'] ?? '--') }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 md:col-span-2">
                            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                {{ $isUserProduct ? 'Nombre de familia actual' : 'Título actual' }}
                            </div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-white">
                                {{ $currentLabel }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Precio actual</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-white">
                                ${{ number_format((float) ($defaultPrice ?? 0), 2) }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Estado</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-white">
                                {{ $item['status'] ?? '--' }}
                            </div>
                        </div>
                    </div>

                    @if ($isUserProduct)
                        <div class="bg-blue-100 border border-blue-400 text-blue-800 px-4 py-3 rounded">
                            Esta publicación usa User Product. En este caso Mercado Libre trabaja con <strong>family_name</strong> y el atributo <strong>NAME</strong>.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('producto.ml.republish', ['ml' => $ml]) }}" class="space-y-5">
                        @csrf

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <label class="block mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Tienda oficial
                            </label>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <label class="flex items-center gap-3 rounded-lg border border-gray-300 dark:border-gray-600 p-4 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="official_store_mode"
                                        value="marketmax"
                                        {{ $selectedStoreMode === 'marketmax' ? 'checked' : '' }}
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    >
                                    <span class="text-sm text-gray-900 dark:text-white">MARKETMAX</span>
                                </label>

                                <label class="flex items-center gap-3 rounded-lg border border-gray-300 dark:border-gray-600 p-4 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="official_store_mode"
                                        value="tobeauty"
                                        {{ $selectedStoreMode === 'tobeauty' ? 'checked' : '' }}
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    >
                                    <span class="text-sm text-gray-900 dark:text-white">TOBEAUTY</span>
                                </label>

                                <label class="flex items-center gap-3 rounded-lg border border-gray-300 dark:border-gray-600 p-4 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="official_store_mode"
                                        value="none"
                                        {{ $selectedStoreMode === 'none' ? 'checked' : '' }}
                                        class="text-indigo-600 focus:ring-indigo-500"
                                    >
                                    <span class="text-sm text-gray-900 dark:text-white">Publicar fuera de tienda oficial</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label for="title" class="block mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ $isUserProduct ? 'Nuevo nombre de familia' : 'Nuevo título' }}
                            </label>
                            <input
                                type="text"
                                id="title"
                                name="title"
                                maxlength="60"
                                value="{{ old('title', $defaultLabel ?? '') }}"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                                placeholder="{{ $isUserProduct ? 'Escribe el nuevo nombre de familia' : 'Escribe el nuevo título' }}"
                                required
                            >
                        </div>

                        <div>
                            <label for="price" class="block mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Nuevo precio
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="1"
                                id="price"
                                name="price"
                                value="{{ old('price', $defaultPrice ?? 0) }}"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg block w-full p-3 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400"
                                placeholder="0.00"
                                required
                            >
                        </div>

                        @if (!empty($item['catalog_product_id']))
                            <div class="flex items-center gap-2">
                                <input
                                    id="copy_catalog"
                                    name="copy_catalog"
                                    type="checkbox"
                                    value="1"
                                    {{ old('copy_catalog') ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <label for="copy_catalog" class="text-sm text-gray-700 dark:text-gray-300">
                                    Mantener catálogo original
                                </label>
                            </div>
                        @endif

                        <div class="flex flex-col sm:flex-row gap-3">
                            <a href="{{ route('producto.index') }}"
                               class="inline-flex justify-center items-center px-5 py-3 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600">
                                Cancelar
                            </a>

                            <button type="submit"
                                    class="inline-flex justify-center items-center px-5 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 dark:bg-indigo-700 dark:hover:bg-indigo-800">
                                Republicar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layouts.app>