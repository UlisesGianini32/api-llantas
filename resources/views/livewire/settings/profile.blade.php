<?php
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <!-- Perfil básico (Nombre y Email) -->
    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        @if (session('success'))
            <div class="mb-4 rounded-lg border border-green-300 bg-green-100 px-4 py-3 text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-300 bg-red-100 px-4 py-3 text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}
                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-settings.layout>

    <!-- Sección de Autorización de MercadoLibre -->
    <div class="mt-12">
        <x-settings.layout :heading="__('MercadoLibre Account')" :subheading="__('Vincula tu cuenta de vendedor de MercadoLibre')">
            @if (session('success'))
                <div class="mb-4 rounded-lg border border-green-300 bg-green-100 px-4 py-3 text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-300">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-lg border border-red-300 bg-red-100 px-4 py-3 text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            <div class="my-6 w-full space-y-6">
                @if (Auth::check() && !Auth::user()->meli_id)
                    <form action="{{ route('meli.redirect') }}" method="GET" class="space-y-6">
                        <flux:text class="text-sm text-gray-600 dark:text-gray-400">
                            Haz clic en el botón para autorizar tu cuenta de MercadoLibre y permitir que la aplicación gestione tus publicaciones y stock.
                        </flux:text>

                        <div class="flex items-center gap-4">
                            <flux:button type="submit" variant="primary" class="w-full">
                                {{ __('Vincular cuenta de MercadoLibre') }}
                            </flux:button>
                        </div>
                    </form>
                @elseif (Auth::check() && Auth::user()->meli_id)
                    <div class="space-y-4">
                        <flux:text class="text-lg font-medium !text-green-600 dark:!text-green-400">
                            ✓ Cuenta de MercadoLibre vinculada correctamente
                        </flux:text>

                        <flux:text class="text-sm text-gray-600 dark:text-gray-400">
                            ID de vendedor:
                            <span class="font-mono font-semibold">{{ Auth::user()->meli_id }}</span>
                        </flux:text>

                        @if (Auth::user()->official_store_id)
                            <flux:text class="text-sm text-gray-600 dark:text-gray-400">
                                Official Store ID:
                                <span class="font-mono font-semibold">{{ Auth::user()->official_store_id }}</span>
                            </flux:text>
                        @endif

                        <div class="space-y-3 pt-2">
                            <form action="{{ route('meli.redirect') }}" method="GET">
                                <flux:button type="submit" variant="outline" class="w-full">
                                    {{ __('Cambiar / Revincular cuenta') }}
                                </flux:button>
                            </form>

                            <form
                                action="{{ route('meli.unlink') }}"
                                method="POST"
                                onsubmit="return confirm('¿Seguro que deseas desvincular la cuenta de MercadoLibre?');"
                            >
                                @csrf
                                @method('DELETE')

                                <button
                                    type="submit"
                                    class="w-full inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition"
                                >
                                    Desvincular MercadoLibre
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </x-settings.layout>
    </div>
</section>