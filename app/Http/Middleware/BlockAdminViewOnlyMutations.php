<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminViewAsUserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockAdminViewOnlyMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(AdminViewAsUserService::class)->isActive()) {
            return $next($request);
        }

        if ($request->routeIs('admin.view_as.exit', 'platform.switch.logout')) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return redirect()->route('admin.view_as.exit');
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $message = __('View-only: admin cannot change this account.');

        if ($request->header('X-Inertia') || $request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }
}
