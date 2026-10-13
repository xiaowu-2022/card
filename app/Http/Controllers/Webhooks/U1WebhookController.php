<?php

namespace App\Http\Controllers\Webhooks;

use App\Application\CardProviderDirectory\U1Accounts;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessU1Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class U1WebhookController extends Controller
{
    public function __invoke(string $connection, Request $request, U1Accounts $accounts)
    {
        try {
            $facts = $accounts->receive($connection, $request->getContent());
        } catch (RecordsNotFoundException|ModelNotFoundException) {
            return response('NOT_FOUND', 404)->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $error) {
            return response('REJECTED', $error->getStatusCode())->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            // Never acknowledge a storage/key failure or report raw callback/OTP content.
            return response('RETRY', 503)->header('Cache-Control', 'no-store');
        }

        if ($facts['verified']) {
            ProcessU1Notification::dispatch($connection, $facts['id'])->afterCommit();
        }

        return response($facts['verified'] ? 'SUCCESS' : 'INVALID_NOTIFICATION', $facts['verified'] ? 200 : 400)
            ->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
    }
}
