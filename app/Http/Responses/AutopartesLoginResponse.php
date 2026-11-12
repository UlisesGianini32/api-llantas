<?php

namespace App\Http\Responses;

use App\Services\Autopartes\AutopartesLoginRedirector;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class AutopartesLoginResponse implements LoginResponseContract
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
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->to($target);
    }
}
