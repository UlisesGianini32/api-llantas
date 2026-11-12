<?php

namespace App\Http\Responses;

use App\Services\Autopartes\AutopartesLoginRedirector;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class AutopartesTwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(
        private readonly AutopartesLoginRedirector $redirector,
    ) {}

    public function toResponse($request)
    {
        $user = $request->user();
        $target = $user
            ? $this->redirector->resolvePostLoginTarget($request, $user, Fortify::redirects('login'))
            : Fortify::redirects('login');

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->to($target);
    }
}
