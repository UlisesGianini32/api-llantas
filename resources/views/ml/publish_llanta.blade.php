<x-layouts.app title="Publicar en MercadoLibre">

<div class="mx-auto max-w-6xl space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Publicar en MercadoLibre</h1>
            <div class="text-sm text-zinc-500 dark:text-neutral-400 mt-1">
                SKU: <span class="font-mono">{{ $llanta->sku }}</span>
                · Precio: <b>${{ number_format($llanta->precio_ML, 2) }}</b>
                · Stock actual: <b>{{ $llanta->stock }}</b>
            </div>
        </div>

        <a href="{{ route('llantas.index') }}"
           class="px-4 py-2 rounded-lg border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
            Volver
        </a>
    </div>

    @if (session('error'))
        <div class="p-4 text-sm text-red-800 bg-red-100 rounded-lg dark:bg-red-900 dark:text-red-200 whitespace-pre-line">
            {{ session('error') }}
        </div>
    @endif

    @if (session('success'))
        <div class="p-4 text-sm text-green-800 bg-green-100 rounded-lg dark:bg-green-900 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 text-sm text-red-800 bg-red-100 rounded-lg dark:bg-red-900 dark:text-red-200">
            <div class="font-semibold mb-2">Hay errores:</div>
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-2xl border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-800 overflow-hidden">
        <div class="border-b border-zinc-200 dark:border-neutral-800 px-6 py-4">
            <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="step-tab px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white"
                        data-step="1">
                    Paso 1 · Categoría / Catálogo
                </button>

                <button type="button"
                        class="step-tab px-4 py-2 rounded-lg text-sm font-semibold border border-zinc-300 dark:border-neutral-700"
                        data-step="2">
                    Paso 2 · Datos del producto
                </button>

                <button type="button"
                        class="step-tab px-4 py-2 rounded-lg text-sm font-semibold border border-zinc-300 dark:border-neutral-700"
                        data-step="3">
                    Paso 3 · Condiciones de venta
                </button>
            </div>
        </div>

        <form id="publishForm"
              method="POST"
              action="{{ route('llantas.ml.publish', $llanta->id) }}"
              enctype="multipart/form-data"
              class="space-y-0">
            @csrf

            {{-- =========================
                STEP 1
            ========================== --}}
            <section class="step-pane p-6 space-y-6" data-step-pane="1">

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Paso 1. Categoría / Catálogo</h2>
                    <p class="text-sm text-zinc-500 dark:text-neutral-400 mt-1">
                        Igual que en Mercado Libre: primero eliges la categoría y, si aplica, el producto del catálogo.
                    </p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Buscar categoría</label>

                    <div class="flex gap-2 mt-2">
                        <input id="catQuery" type="text" placeholder="Ej: llanta 285 65 r18"
                               class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <button id="catBtn" type="button"
                                class="shrink-0 px-4 py-2 rounded-lg text-sm border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                            Buscar
                        </button>
                    </div>

                    <input id="category_id" type="hidden" name="category_id" value="{{ old('category_id') }}">
                    <input id="category_name" type="hidden" name="category_name" value="{{ old('category_name') }}">
                    <input id="is_catalog_category" type="hidden" value="{{ old('catalog_product_id') ? '1' : '' }}">

                    <div id="catResults" class="mt-3 text-sm">
                        <div class="text-[11px] text-zinc-500 dark:text-neutral-400">
                            Busca y selecciona una categoría antes de continuar.
                        </div>
                    </div>

                    <div id="catChosen"
                         class="mt-3 hidden p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:border-emerald-900">
                        Categoría seleccionada:
                        <span class="font-semibold" id="catChosenName"></span>
                        · ID:
                        <span class="font-mono font-bold" id="catChosenId"></span>
                    </div>

                    <div id="catCatalogHint"
                         class="mt-3 hidden p-3 rounded-lg border text-sm"></div>

                    <div id="catErr" class="mt-2 hidden text-[11px] text-rose-600"></div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Catálogo / Buy Box</div>
                    <div class="text-[11px] text-zinc-500 dark:text-neutral-400 mt-1">
                        Si la categoría usa catálogo, puedes elegir un producto. Si no lo encuentras, tendrás que buscar otra categoría.
                    </div>

                    <input type="hidden" name="catalog_mode" id="catalog_mode" value="{{ old('catalog_mode', 'search') }}">

                    <div class="mt-4">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">catalog_product_id (opcional)</label>
                        <input id="catalog_product_id"
                               type="text"
                               name="catalog_product_id"
                               value="{{ old('catalog_product_id') }}"
                               placeholder="Ej: MLPxxxx"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                    </div>

                    <div class="mt-4">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Buscar producto en catálogo</label>
                        <div class="flex gap-2 mt-2">
                            <input id="catalogQuery"
                                   type="text"
                                   value="{{ old('catalogQuery', trim(($llanta->marca ?? '').' '.($llanta->medida ?? '').' '.($llanta->title_familyname ?? ''))) }}"
                                   placeholder="Ej: PEGASUS 285/65R18 ATX 4S"
                                   class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                            <button id="catalogBtn" type="button"
                                    class="shrink-0 px-4 py-2 rounded-lg text-sm border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                Buscar catálogo
                            </button>
                        </div>

                        <div id="catalogResults" class="mt-3 text-sm">
                            <div class="text-[11px] text-zinc-500 dark:text-neutral-400">
                                Primero selecciona categoría y luego busca catálogo.
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button"
                                    id="catalogNoFoundBtn"
                                    class="px-3 py-2 rounded-lg text-sm border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                No encuentro mi opción
                            </button>

                            <button type="button"
                                    id="catalogClearBtn"
                                    class="px-3 py-2 rounded-lg text-sm border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                Limpiar selección de catálogo
                            </button>
                        </div>

                        <div id="catalogErr" class="mt-2 hidden text-[11px] text-rose-600"></div>

                        <div id="catalogChosen"
                             class="mt-3 hidden p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:border-emerald-900">
                            Catálogo seleccionado:
                            <span class="font-semibold" id="catalogChosenTitle"></span>
                            · catalog_product_id:
                            <span class="font-mono font-bold" id="catalogChosenId"></span>
                        </div>

                        <div id="catalogSkipped"
                             class="mt-3 hidden p-3 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:border-amber-900">
                            Continuarás sin catálogo.
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button"
                            class="next-step px-5 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
                            data-go-step="2">
                        Continuar a datos del producto
                    </button>
                </div>
            </section>

            {{-- =========================
                STEP 2
            ========================== --}}
            <section class="step-pane hidden p-6 space-y-6" data-step-pane="2">

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Paso 2. Datos del producto</h2>
                    <p class="text-sm text-zinc-500 dark:text-neutral-400 mt-1">
                        Aquí va todo lo que te mostró ML: características principales, empaque, condición, fotos, título y descripción.
                    </p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Características principales</div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Marca</label>
                            <input type="text" name="brand" value="{{ old('brand', $llanta->marca) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Modelo</label>
                            <input type="text" name="model" value="{{ old('model') }}"
                                   placeholder="Ej: ATX 4S"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Índice de carga</label>
                            <input type="text" name="load_index" value="{{ old('load_index') }}"
                                   placeholder="Ej: 125/122"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Cantidad de llantas</label>
                            <input type="number" min="1" max="20" name="tire_quantity" value="{{ old('tire_quantity', 1) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Línea</label>
                            <input type="text" name="line" value="{{ old('line') }}"
                                   placeholder="Ej: ATX 4S"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Lateral</label>
                            <input type="text" name="sidewall" value="{{ old('sidewall') }}"
                                   placeholder="Opcional"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Tipo de servicio</label>
                            <select name="service_type"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="">Selecciona</option>
                                <option value="P" @selected(old('service_type')==='P')>P</option>
                                <option value="LT" @selected(old('service_type','LT')==='LT')>LT</option>
                                <option value="T" @selected(old('service_type')==='T')>T</option>
                                <option value="ST" @selected(old('service_type')==='ST')>ST</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">¿Es run flat?</label>
                            <select name="run_flat"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="0" @selected(old('run_flat','0')==='0')>No</option>
                                <option value="1" @selected(old('run_flat')==='1')>Sí</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Empaque / medidas del paquete</div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Ancho (cm)</label>
                            <input type="number" step="0.01" min="1" max="300" name="package_width_cm"
                                   value="{{ old('package_width_cm', 83) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Alto (cm)</label>
                            <input type="number" step="0.01" min="1" max="300" name="package_height_cm"
                                   value="{{ old('package_height_cm', 83) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Profundidad / largo (cm)</label>
                            <input type="number" step="0.01" min="1" max="300" name="package_length_cm"
                                   value="{{ old('package_length_cm', 30) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Peso (kg)</label>
                            <input type="number" step="0.01" min="0.1" max="99.99" name="package_weight_kg"
                                   value="{{ old('package_weight_kg', 26) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Condición</div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="condition" value="new" @checked(old('condition','new')==='new')>
                            <span>Nueva</span>
                        </label>

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="condition" value="used" @checked(old('condition')==='used')>
                            <span>Usada</span>
                        </label>

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="condition" value="not_specified" @checked(old('condition')==='not_specified')>
                            <span>No especificar</span>
                        </label>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Variantes y fotos</div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Índice de velocidad</label>
                            <input type="text" maxlength="10" name="speed_rating"
                                   value="{{ old('speed_rating') }}"
                                   placeholder="Ej: S"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Stock a publicar</label>
                            <input type="number" min="1" max="9999" name="stock_input"
                                   value="{{ old('stock_input', max(1, (int)$llanta->stock)) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">GTIN / Código universal</label>
                            <input type="text" name="gtin"
                                   value="{{ old('gtin') }}"
                                   placeholder="Opcional"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">SKU</label>
                            <input type="text" name="seller_sku"
                                   value="{{ old('seller_sku', $llanta->sku) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>
                    </div>

                    <div class="mt-5">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                            Subir imágenes (desde tu PC)
                        </label>

                        <input type="file"
                               name="pictures_files[]"
                               multiple
                               accept="image/jpeg,image/png,image/webp"
                               class="mt-2 block w-full text-sm text-zinc-700 dark:text-neutral-200
                                      file:mr-4 file:py-2 file:px-4
                                      file:rounded-lg file:border-0
                                      file:text-sm file:font-semibold
                                      file:bg-emerald-50 file:text-emerald-700
                                      hover:file:bg-emerald-100
                                      dark:file:bg-emerald-900 dark:file:text-emerald-100 dark:hover:file:bg-emerald-800" />

                        <div class="text-[11px] text-zinc-500 dark:text-neutral-400 mt-1">
                            Máximo 12 fotos. Usa JPG / PNG / WEBP.
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Imágenes por URL (opcional)</label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-1">
                            @for($i=0; $i<6; $i++)
                                <input type="text" name="pictures_urls[]"
                                       value="{{ old('pictures_urls.'.$i) }}"
                                       placeholder="https://....jpg"
                                       class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                            @endfor
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Título</div>
                    <input type="text" name="title"
                           value="{{ old('title', $llanta->title_familyname ?: ('LLANTA '.$llanta->medida.' '.$llanta->marca)) }}"
                           maxlength="60"
                           class="mt-3 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Características secundarias</div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Relación de aspecto</label>
                            <input type="number" step="0.01" min="10" max="100" name="aspect_ratio"
                                   value="{{ old('aspect_ratio') }}"
                                   placeholder="65"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Ancho de sección (mm)</label>
                            <input type="number" step="0.01" min="50" max="500" name="section_width"
                                   value="{{ old('section_width') }}"
                                   placeholder="285"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Diámetro del rin</label>
                            <input type="number" step="0.01" min="8" max="30" name="rim_diameter"
                                   value="{{ old('rim_diameter') }}"
                                   placeholder="18"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">UTQG</label>
                            <input type="text" name="utqg"
                                   value="{{ old('utqg') }}"
                                   placeholder="Opcional"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Rango de cargas</label>
                            <input type="text" name="load_range"
                                   value="{{ old('load_range') }}"
                                   placeholder="Ej: 10C / XL / C"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Tipo de terreno</label>
                            <select name="terrain_type"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="">Selecciona</option>
                                <option value="HT" @selected(old('terrain_type')==='HT')>HT</option>
                                <option value="AT" @selected(old('terrain_type','AT')==='AT')>AT</option>
                                <option value="MT" @selected(old('terrain_type')==='MT')>MT</option>
                                <option value="AS" @selected(old('terrain_type')==='AS')>AS</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Tipo de construcción</label>
                            <select name="construction_type"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="">Selecciona</option>
                                <option value="Radial" @selected(old('construction_type','Radial')==='Radial')>Radial</option>
                                <option value="Diagonal" @selected(old('construction_type')==='Diagonal')>Diagonal</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Descripción</div>
                    <textarea name="description" rows="8"
                              class="mt-3 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                              placeholder="Describe el producto...">{{ old('description') }}</textarea>
                </div>

                <div class="flex justify-between gap-2">
                    <button type="button"
                            class="prev-step px-5 py-2 rounded-lg border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            data-go-step="1">
                        Volver
                    </button>

                    <button type="button"
                            class="next-step px-5 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
                            data-go-step="3">
                        Continuar a condiciones de venta
                    </button>
                </div>
            </section>

            {{-- =========================
                STEP 3
            ========================== --}}
            <section class="step-pane hidden p-6 space-y-6" data-step-pane="3">

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Paso 3. Condiciones de venta</h2>
                    <p class="text-sm text-zinc-500 dark:text-neutral-400 mt-1">
                        Igual que en ML: tienda oficial, garantía y tipo de publicación.
                    </p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Tienda oficial</div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="official_store_mode" value="marketmax" @checked(old('official_store_mode')==='marketmax')>
                            <span>MARKETMAX</span>
                        </label>

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="official_store_mode" value="tobeauty" @checked(old('official_store_mode','tobeauty')==='tobeauty')>
                            <span>TOBEAUTY</span>
                        </label>

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="radio" name="official_store_mode" value="none" @checked(old('official_store_mode')==='none')>
                            <span>Publicar fuera de tienda oficial</span>
                        </label>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Garantía</div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Tipo de garantía</label>
                            <select name="warranty_type"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="seller" @selected(old('warranty_type','seller')==='seller')>Garantía del vendedor</option>
                                <option value="factory" @selected(old('warranty_type')==='factory')>Garantía de fábrica</option>
                                <option value="none" @selected(old('warranty_type')==='none')>Sin garantía</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Tiempo</label>
                            <input type="number" min="1" max="120" name="warranty_time_value"
                                   value="{{ old('warranty_time_value', 30) }}"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-zinc-600 dark:text-neutral-300">Unidad</label>
                            <select name="warranty_time_unit"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                <option value="days" @selected(old('warranty_time_unit','days')==='days')>Días</option>
                                <option value="months" @selected(old('warranty_time_unit')==='months')>Meses</option>
                                <option value="years" @selected(old('warranty_time_unit')==='years')>Años</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:bg-neutral-950 dark:border-neutral-800">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Tipo de publicación</div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <label class="rounded-xl border border-zinc-300 dark:border-neutral-700 p-4 cursor-pointer">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="listing_type_id" value="gold_special"
                                       @checked(old('listing_type_id','gold_special')==='gold_special')>
                                <span class="font-semibold">Clásica</span>
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-neutral-400 mt-2">
                                Equivalente a publicación clásica.
                            </div>
                        </label>

                        <label class="rounded-xl border border-zinc-300 dark:border-neutral-700 p-4 cursor-pointer">
                            <div class="flex items-center gap-2">
                                <input type="radio" name="listing_type_id" value="gold_pro"
                                       @checked(old('listing_type_id')==='gold_pro')>
                                <span class="font-semibold">Premium</span>
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-neutral-400 mt-2">
                                Equivalente a publicación premium.
                            </div>
                        </label>
                    </div>
                </div>

                <div class="flex justify-between gap-2">
                    <button type="button"
                            class="prev-step px-5 py-2 rounded-lg border border-zinc-300 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            data-go-step="2">
                        Volver
                    </button>

                    <button id="publishBtn" type="submit"
                            class="px-5 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                        Publicar ahora
                    </button>
                </div>
            </section>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('publishForm');
    const tabs = Array.from(document.querySelectorAll('.step-tab'));
    const panes = Array.from(document.querySelectorAll('.step-pane'));

    function setStep(step) {
        tabs.forEach(t => {
            const active = t.dataset.step === String(step);
            t.classList.toggle('bg-emerald-600', active);
            t.classList.toggle('text-white', active);
            t.classList.toggle('border', !active);
            t.classList.toggle('border-zinc-300', !active);
            t.classList.toggle('dark:border-neutral-700', !active);
        });

        panes.forEach(p => {
            p.classList.toggle('hidden', p.dataset.stepPane !== String(step));
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    const catBtn = document.getElementById('catBtn');
    const catQuery = document.getElementById('catQuery');
    const catResults = document.getElementById('catResults');
    const catHidden = document.getElementById('category_id');
    const catNameHidden = document.getElementById('category_name');
    const catIsCatalogHidden = document.getElementById('is_catalog_category');
    const chosenBox = document.getElementById('catChosen');
    const chosenId = document.getElementById('catChosenId');
    const chosenName = document.getElementById('catChosenName');
    const catErr = document.getElementById('catErr');
    const catCatalogHint = document.getElementById('catCatalogHint');

    const catalogBtn = document.getElementById('catalogBtn');
    const catalogQuery = document.getElementById('catalogQuery');
    const catalogResults = document.getElementById('catalogResults');
    const catalogErr = document.getElementById('catalogErr');
    const catalogInput = document.getElementById('catalog_product_id');
    const catalogMode = document.getElementById('catalog_mode');
    const catalogNoFoundBtn = document.getElementById('catalogNoFoundBtn');
    const catalogClearBtn = document.getElementById('catalogClearBtn');

    const catalogChosen = document.getElementById('catalogChosen');
    const catalogChosenId = document.getElementById('catalogChosenId');
    const catalogChosenTitle = document.getElementById('catalogChosenTitle');
    const catalogSkipped = document.getElementById('catalogSkipped');

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, m => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#39;'
        })[m]);
    }

    function showChosen(id, name) {
        chosenId.textContent = id || '';
        chosenName.textContent = name || '';
        chosenBox.classList.toggle('hidden', !id);
        catErr.classList.add('hidden');
        catErr.textContent = '';
    }

    function showCatalogChosen(cpid, title) {
        catalogMode.value = 'search';
        catalogChosenId.textContent = cpid || '';
        catalogChosenTitle.textContent = title || '';
        catalogChosen.classList.toggle('hidden', !cpid);
        catalogSkipped.classList.add('hidden');
        catalogErr.classList.add('hidden');
        catalogErr.textContent = '';
    }

    function showCatalogSkipped() {
        // ✅ Esto es "PUBLICAR SEPARADO"
        catalogMode.value = 'no_catalog';
        catalogInput.value = '';
        catalogChosen.classList.add('hidden');
        catalogSkipped.classList.remove('hidden');
        catalogErr.classList.add('hidden');
        catalogErr.textContent = '';
    }

    function clearCatalogSelection() {
        catalogMode.value = 'search';
        catalogInput.value = '';
        catalogChosen.classList.add('hidden');
        catalogSkipped.classList.add('hidden');
        catalogErr.classList.add('hidden');
        catalogErr.textContent = '';
    }

    function clearCategorySelection() {
        catHidden.value = '';
        catNameHidden.value = '';
        catIsCatalogHidden.value = '';
        chosenBox.classList.add('hidden');
        catCatalogHint.classList.add('hidden');
        catCatalogHint.textContent = '';
    }

    function paintCatalogHint(isCatalog) {
        if (String(isCatalog) === '1') {
            catCatalogHint.className = 'mt-3 p-3 rounded-lg border text-sm bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:border-amber-900';
            catCatalogHint.innerHTML = 'La categoría seleccionada <b>usa catálogo</b>. Puedes <b>competir</b> eligiendo un producto del catálogo o <b>publicar separado</b> con “No encuentro mi opción”.';
            catCatalogHint.classList.remove('hidden');
        } else if (String(isCatalog) === '0') {
            catCatalogHint.className = 'mt-3 p-3 rounded-lg border text-sm bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:border-emerald-900';
            catCatalogHint.innerHTML = 'La categoría seleccionada <b>no usa catálogo</b>. Puedes continuar sin catalog_product_id.';
            catCatalogHint.classList.remove('hidden');
        } else {
            catCatalogHint.classList.add('hidden');
            catCatalogHint.textContent = '';
        }
    }

    async function loadCategoryMeta(categoryId) {
        try {
            const res = await fetch(`{{ route('ml.categories.meta') }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': `{{ csrf_token() }}`,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ category_id: categoryId })
            });

            const json = await res.json();
            if (!json || !json.ok) {
                catIsCatalogHidden.value = '';
                paintCatalogHint('');
                return;
            }

            const isCatalog = json?.data?.is_catalog_category ? '1' : '0';
            catIsCatalogHidden.value = isCatalog;
            paintCatalogHint(isCatalog);
        } catch (e) {
            catIsCatalogHidden.value = '';
            paintCatalogHint('');
        }
    }

    async function searchCats() {
        const q = (catQuery.value || '').trim();
        if (!q) {
            catResults.innerHTML = `<div class="text-[11px] text-rose-600">Escribe algo para buscar categorías.</div>`;
            return;
        }

        catResults.innerHTML = `<div class="text-[11px] text-zinc-500 dark:text-neutral-400">Buscando categorías...</div>`;

        try {
            const res = await fetch(`{{ route('ml.categories.suggest') }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': `{{ csrf_token() }}`,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ q })
            });

            const json = await res.json();
            const data = json?.data || [];

            if (!Array.isArray(data) || data.length === 0) {
                catResults.innerHTML = `<div class="text-[11px] text-rose-600">No se encontraron categorías.</div>`;
                return;
            }

            catResults.innerHTML = `
                <div class="space-y-2">
                    ${data.map((c) => {
                        const cid = esc(c.category_id || '');
                        const name = esc(c.category_name || cid);
                        const dom = c.domain_name ? ` <span class="text-[11px] text-zinc-500 dark:text-neutral-400">(${esc(c.domain_name)})</span>` : '';
                        return `
                            <button type="button"
                                    data-pick="${cid}"
                                    data-name="${name}"
                                    class="w-full text-left px-3 py-2 rounded-lg border border-zinc-200 hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-800">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-white">${name}${dom}</div>
                                <div class="text-[11px] text-zinc-500 dark:text-neutral-400">ID: <span class="font-mono">${cid}</span></div>
                            </button>
                        `;
                    }).join('')}
                </div>
            `;
        } catch (e) {
            catResults.innerHTML = `<div class="text-[11px] text-rose-600">Error buscando categorías.</div>`;
        }
    }

    async function searchCatalog() {
        const q = (catalogQuery.value || '').trim();
        if (!q) {
            catalogResults.innerHTML = `<div class="text-[11px] text-rose-600">Escribe algo para buscar catálogo.</div>`;
            return;
        }

        if (!catHidden.value) {
            catalogResults.innerHTML = `<div class="text-[11px] text-rose-600">Primero selecciona una categoría.</div>`;
            return;
        }

        catalogResults.innerHTML = `<div class="text-[11px] text-zinc-500 dark:text-neutral-400">Buscando productos de catálogo...</div>`;

        try {
            const res = await fetch(`{{ route('ml.catalog.search') }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': `{{ csrf_token() }}`,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    q,
                    category_id: (catHidden.value || '').trim() || null
                })
            });

            const json = await res.json();
            if (!json || !json.ok) {
                catalogResults.innerHTML = `<div class="text-[11px] text-rose-600">Error buscando catálogo.</div>`;
                return;
            }

            const data = json?.data || [];
            if (!Array.isArray(data) || data.length === 0) {
                catalogResults.innerHTML = `
                    <div class="text-[11px] text-zinc-500 dark:text-neutral-400">
                        Sin resultados. Puedes publicar separado con “No encuentro mi opción”.
                    </div>
                `;
                return;
            }

            catalogResults.innerHTML = `
                <div class="space-y-2">
                    ${data.map((it) => {
                        const cpid = esc(it.catalog_product_id || '');
                        const title = esc(it.title || cpid || 'Producto');
                        const price = it.price != null ? esc(it.price) : '';
                        const link = esc(it.permalink || '');
                        return `
                            <button type="button"
                                    data-cpid="${cpid}"
                                    data-title="${title}"
                                    class="w-full text-left px-3 py-2 rounded-lg border border-zinc-200 hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-800">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-white">${title}</div>
                                <div class="text-[11px] text-zinc-500 dark:text-neutral-400">
                                    catalog_product_id: <span class="font-mono font-bold">${cpid}</span>
                                    ${price ? ` · $${price}` : ``}
                                </div>
                                ${link ? `<div class="text-[11px] text-zinc-500 dark:text-neutral-400 truncate">${link}</div>` : ``}
                            </button>
                        `;
                    }).join('')}
                </div>
            `;
        } catch (e) {
            catalogResults.innerHTML = `<div class="text-[11px] text-rose-600">Error buscando catálogo.</div>`;
        }
    }

    // ✅ CAMBIO CLAVE: solo exigir catálogo si NO estás en no_catalog
    function validateStep1BeforeContinue() {
        if (!catHidden.value) {
            catErr.textContent = 'Selecciona una categoría antes de continuar.';
            catErr.classList.remove('hidden');
            setStep(1);
            return false;
        }

        if (catIsCatalogHidden.value === '1' && !catalogInput.value && catalogMode.value !== 'no_catalog') {
            catErr.textContent =
                'Esta categoría usa catálogo. ' +
                'Si quieres COMPETIR, selecciona un producto del catálogo. ' +
                'Si quieres PUBLICAR SEPARADO, usa “No encuentro mi opción”.';
            catErr.classList.remove('hidden');
            setStep(1);
            return false;
        }

        return true;
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const step = tab.dataset.step;
            if ((step === '2' || step === '3') && !validateStep1BeforeContinue()) return;
            setStep(step);
        });
    });

    document.querySelectorAll('.next-step, .prev-step').forEach(btn => {
        btn.addEventListener('click', () => {
            const step = btn.dataset.goStep;
            if ((step === '2' || step === '3') && !validateStep1BeforeContinue()) return;
            setStep(step);
        });
    });

    catBtn?.addEventListener('click', searchCats);
    catQuery?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchCats();
        }
    });

    catResults?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-pick]');
        if (!btn) return;

        const cid = btn.getAttribute('data-pick');
        const name = btn.getAttribute('data-name') || cid;

        catHidden.value = cid;
        catNameHidden.value = name;
        showChosen(cid, name);

        clearCatalogSelection();
        await loadCategoryMeta(cid);
    });

    catalogBtn?.addEventListener('click', searchCatalog);
    catalogQuery?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchCatalog();
        }
    });

    catalogResults?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-cpid]');
        if (!btn) return;

        const cpid = btn.getAttribute('data-cpid') || '';
        const title = btn.getAttribute('data-title') || 'Producto';

        catalogInput.value = cpid;
        showCatalogChosen(cpid, title);
    });

    // ✅ CAMBIO CLAVE: ahora SIEMPRE permite "publicar separado"
    catalogNoFoundBtn?.addEventListener('click', () => {
        showCatalogSkipped();

        if (catIsCatalogHidden.value === '1') {
            catErr.textContent =
                'Vas a PUBLICAR SEPARADO (sin catálogo). ' +
                'Si MercadoLibre exige catálogo en esta categoría, te marcará error y tendrás que seleccionar catálogo o cambiar de categoría.';
            catErr.classList.remove('hidden');
        } else {
            catErr.classList.add('hidden');
            catErr.textContent = '';
        }
    });

    catalogClearBtn?.addEventListener('click', () => {
        clearCatalogSelection();
    });

    form?.addEventListener('submit', (e) => {
        if (!validateStep1BeforeContinue()) {
            e.preventDefault();
            return false;
        }
        return true;
    });

    if (catHidden.value) {
        showChosen(catHidden.value, catNameHidden.value || 'Categoría');
        loadCategoryMeta(catHidden.value);
    }

    if (catalogMode.value === 'no_catalog') {
        showCatalogSkipped();
    } else if (catalogInput.value) {
        showCatalogChosen(catalogInput.value, 'Seleccionado');
    }

    setStep(1);
})();
</script>

</x-layouts.app>