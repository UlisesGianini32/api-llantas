<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveBranchMembership;
use App\Models\User;
use App\Support\AutopartesConnectionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AutopartesLoginRedirector
{
    /**
     * Required capabilities (any-of) for each GET route under autopartes.*.
     *
     * @var array<string, list<string>>
     */
    private const ROUTE_CAPABILITY_REQUIREMENTS = [
        'autopartes.index' => [BranchCatalogAccess::CATALOG_VIEW],
        'autopartes.show' => [BranchCatalogAccess::CATALOG_VIEW],
        'autopartes.inventory.index' => [BranchCatalogAccess::STOCK_VIEW],
        'autopartes.receipts.index' => [
            BranchCatalogAccess::RECEIPT_DRAFT,
            BranchCatalogAccess::RECEIPT_CONFIRM,
            BranchCatalogAccess::STOCK_VIEW,
        ],
        'autopartes.receipts.show' => [
            BranchCatalogAccess::RECEIPT_DRAFT,
            BranchCatalogAccess::RECEIPT_CONFIRM,
            BranchCatalogAccess::STOCK_VIEW,
        ],
        'autopartes.movements.index' => [BranchCatalogAccess::MOVEMENTS_VIEW],
        'autopartes.imports.index' => [BranchCatalogAccess::IMPORT],
        'autopartes.imports.show' => [BranchCatalogAccess::IMPORT],
        'autopartes.imports.original' => [BranchCatalogAccess::IMPORT],
        'autopartes.pos.index' => [BranchCatalogAccess::POS_VIEW, BranchCatalogAccess::POS_SELL],
        'autopartes.pos.products.search' => [BranchCatalogAccess::POS_VIEW, BranchCatalogAccess::POS_SELL],
        'autopartes.pos.sales.receipt' => [
            BranchCatalogAccess::POS_VIEW,
            BranchCatalogAccess::POS_SELL,
            BranchCatalogAccess::SALES_VIEW,
        ],
        'autopartes.sellers.index' => [
            BranchCatalogAccess::SELLERS_MANAGE,
            BranchCatalogAccess::REPORTS_VIEW,
            BranchCatalogAccess::POS_VIEW,
        ],
        'autopartes.prices.index' => [
            BranchCatalogAccess::PRICES_VIEW,
            BranchCatalogAccess::PRICES_MANAGE,
        ],
        'autopartes.prices.history' => [
            BranchCatalogAccess::PRICES_VIEW,
            BranchCatalogAccess::PRICES_MANAGE,
        ],
        'autopartes.discounts.index' => [
            BranchCatalogAccess::DISCOUNTS_VIEW,
            BranchCatalogAccess::DISCOUNTS_MANAGE,
            BranchCatalogAccess::POS_VIEW,
            BranchCatalogAccess::DISCOUNTS_APPROVE,
        ],
        'autopartes.sales.index' => [BranchCatalogAccess::SALES_VIEW],
        'autopartes.reports.index' => [BranchCatalogAccess::REPORTS_VIEW],
        'autopartes.reports.export' => [BranchCatalogAccess::REPORTS_EXPORT],
    ];

    /**
     * Ordered landing route candidates per capability.
     *
     * @var array<string, list<string>>
     */
    private const LANDING_ROUTE_TABLE = [
        'autopartes.pos.index' => [BranchCatalogAccess::POS_VIEW, BranchCatalogAccess::POS_SELL],
        'autopartes.index' => [BranchCatalogAccess::CATALOG_VIEW],
        'autopartes.inventory.index' => [BranchCatalogAccess::STOCK_VIEW],
        'autopartes.receipts.index' => [BranchCatalogAccess::RECEIPT_DRAFT, BranchCatalogAccess::RECEIPT_CONFIRM],
        'autopartes.sales.index' => [BranchCatalogAccess::SALES_VIEW],
        'autopartes.prices.index' => [BranchCatalogAccess::PRICES_VIEW, BranchCatalogAccess::PRICES_MANAGE],
        'autopartes.discounts.index' => [
            BranchCatalogAccess::DISCOUNTS_VIEW,
            BranchCatalogAccess::DISCOUNTS_MANAGE,
            BranchCatalogAccess::DISCOUNTS_APPROVE,
        ],
        'autopartes.sellers.index' => [BranchCatalogAccess::SELLERS_MANAGE],
        'autopartes.reports.index' => [BranchCatalogAccess::REPORTS_VIEW],
        'autopartes.movements.index' => [BranchCatalogAccess::MOVEMENTS_VIEW],
        'autopartes.imports.index' => [BranchCatalogAccess::IMPORT],
    ];

    public function __construct(
        private readonly BranchCatalogAccess $access,
    ) {}

    /**
     * Return branch navigation entries for Inertia shared props using strictly the 'autopartes' DB.
     *
     * @return list<array{name: string, url: string}>
     */
    public function authorizedBranchAccessEntries(User $user, bool $absolute = true): array
    {
        try {
            if (! $this->isAutopartesSchemaReady()) {
                return [];
            }

            $branchIds = AutomotiveBranchMembership::query()
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->pluck('branch_id')
                ->all();

            if ($branchIds === []) {
                return [];
            }

            return AutomotiveBranch::query()
                ->whereIn('id', $branchIds)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->map(function (AutomotiveBranch $branch) use ($user, $absolute): ?array {
                    $caps = $this->access->capabilities($user, $branch);
                    if ($caps === []) {
                        return null;
                    }

                    $url = $this->landingUrlForBranch($branch, $caps, $absolute);

                    return $url ? [
                        'code' => $branch->code,
                        'name' => $branch->name,
                        'url' => $url,
                    ] : null;
                })
                ->filter()
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('Autopartes branch access lookup degraded gracefully.', [
                'user_id' => $user->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve landing URL for a single branch given explicit capabilities.
     *
     * @param  list<string>  $capabilities
     */
    public function landingUrlForBranch(AutomotiveBranch $branch, array $capabilities, bool $absolute = true): ?string
    {
        foreach (self::LANDING_ROUTE_TABLE as $routeName => $acceptedCapabilities) {
            foreach ($acceptedCapabilities as $capability) {
                if (in_array($capability, $capabilities, true)) {
                    return route($routeName, $branch->code, $absolute);
                }
            }
        }

        return null;
    }

    /**
     * Resolve the first authorized Autopartes entry URL across active branch memberships.
     */
    public function firstAuthorizedBranchLandingUrl(User $user, bool $absolute = true): ?string
    {
        $entries = $this->authorizedBranchAccessEntries($user, $absolute);

        return $entries[0]['url'] ?? null;
    }

    /**
     * Verify whether an intended URL targets an authorized same-origin Autopartes GET route.
     */
    public function isIntendedUrlAuthorized(User $user, Request $request, string $intendedUrl): bool
    {
        $intendedUrl = trim($intendedUrl);
        if ($intendedUrl === '' || str_starts_with($intendedUrl, '//')) {
            return false;
        }

        $parsed = parse_url($intendedUrl);
        if ($parsed === false) {
            return false;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $intendedHost = strtolower((string) ($parsed['host'] ?? ''));
        if ($intendedHost !== '') {
            $requestHost = strtolower($request->getHost());
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

            if ($intendedHost !== $requestHost && ($appHost === '' || $intendedHost !== $appHost)) {
                return false;
            }
        }

        $path = $parsed['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if (! $this->isAutopartesSchemaReady()) {
            return false;
        }

        try {
            $matchedRoute = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return false;
        }

        $routeName = $matchedRoute->getName();
        if (! is_string($routeName) || ! isset(self::ROUTE_CAPABILITY_REQUIREMENTS[$routeName])) {
            return false;
        }

        $branchCode = strtoupper(trim((string) $matchedRoute->parameter('branch')));
        if ($branchCode === '') {
            return false;
        }

        $branch = AutomotiveBranch::query()
            ->where('code', $branchCode)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            return false;
        }

        foreach (self::ROUTE_CAPABILITY_REQUIREMENTS[$routeName] as $capability) {
            if ($this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, $capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the safe post-login redirect target for the authenticated user.
     */
    public function resolvePostLoginTarget(Request $request, User $user, string $defaultRedirect): string
    {
        if (! $user->isBranchRestricted()) {
            $intended = $request->hasSession() ? $request->session()->pull('url.intended') : null;

            return is_string($intended) && $intended !== '' ? $intended : $defaultRedirect;
        }

        $intended = $request->hasSession() ? $request->session()->pull('url.intended') : null;
        if (is_string($intended) && $this->isIntendedUrlAuthorized($user, $request, $intended)) {
            return $intended;
        }

        return $this->requireBranchRestrictedHome($user);
    }

    /**
     * Resolve the home target for an already-authenticated user visiting /login, /, or /home.
     */
    public function resolveAuthenticatedHome(User $user, string $defaultRedirect = '/dashboard'): string
    {
        if (! $user->isBranchRestricted()) {
            return $defaultRedirect;
        }

        return $this->requireBranchRestrictedHome($user);
    }

    private function isAutopartesSchemaReady(): bool
    {
        if (! config('autopartes.enabled')) {
            return false;
        }

        try {
            $conn = AutopartesConnectionGuard::assertDedicatedConnectionSafe()['connection'];
            $schema = Schema::connection($conn);

            return $schema->hasTable('automotive_branches')
                && $schema->hasTable('automotive_branch_users')
                && $schema->hasTable('automotive_branch_capabilities');
        } catch (Throwable $e) {
            Log::warning('Autopartes schema readiness check failed cleanly.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function requireBranchRestrictedHome(User $user): string
    {
        $landingUrl = $this->firstAuthorizedBranchLandingUrl($user);

        if ($landingUrl === null) {
            abort(403, 'Tu cuenta restringida no tiene una sucursal o módulo de Autopartes habilitado.');
        }

        return $landingUrl;
    }
}
