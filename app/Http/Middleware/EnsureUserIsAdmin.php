<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Membatasi rute CRUD pre-trained model hanya untuk Administrator. */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === 'administrator', 403, 'Halaman ini hanya untuk Administrator.');

        return $next($request);
    }
}
