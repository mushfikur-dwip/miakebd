<?php

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\CaptureMetaClickIds;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\Installed;
use App\Http\Middleware\localization;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied to every response, web and API alike. See the class for what
        // each header is for and why there is no CSP yet.
        $middleware->append([
            SecurityHeaders::class,
        ]);
        // Page loads only: saves Meta's ad-click and browser ids as cookies so
        // a sale is still credited to its ad when the pixel script is blocked.
        $middleware->web(append: [
            CaptureMetaClickIds::class,
        ]);
        // Meta's own cookies, read by its pixel in the browser and by the
        // Conversions API on the server. Encrypting them would make both see
        // gibberish. They hold Meta ids, nothing of ours.
        $middleware->encryptCookies(except: ['_fbc', '_fbp']);
        $middleware->validateCsrfTokens(
            except: [
                '/payment/sslcommerz/*',
                '/payment/paytm/*',
                '/payment/cashfree/*',
                '/payment/phonepe/*',
            ]
        );
        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.basic' => AuthenticateWithBasicAuth::class,
            'auth.session' => AuthenticateSession::class,
            'cache.headers' => SetCacheHeaders::class,
            'can' => Authorize::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.confirm' => RequirePassword::class,
            'precognitive' => HandlePrecognitiveRequests::class,
            'permission' => PermissionMiddleware::class,
            'signed' => ValidateSignature::class,
            'throttle' => ThrottleRequests::class,
            'verified' => EnsureEmailIsVerified::class,
            'apiKey' => ApiKeyMiddleware::class,
            'localization' => localization::class,
            'installed' => Installed::class,
            'active' => EnsureUserIsActive::class,
            'staff' => EnsureStaff::class,

        ]);
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']],
    )
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                if ($e instanceof UnauthorizedException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User does not have the right permissions.',
                    ], 403);
                }

                if ($e instanceof ModelNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No query results for model.',
                    ], 404);
                }

                if ($e instanceof MethodNotAllowedHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Method not supported for the route.',
                    ], 405);
                }

                if ($e instanceof NotFoundHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The specified URL cannot be found.',
                    ], 404);
                }

                if ($e instanceof HttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'HTTP error.',
                    ], $e->getStatusCode());
                }

                if ($e instanceof QueryException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A database error occurred.',
                        'error' => config('app.debug') ? $e->getMessage() : null,
                    ], 422);
                }
            }
        });
    })->create();
