<?php

namespace App\Http\Controllers\Platform;

use App\Application\Card\PlatformCardQuery;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class CardOperationsController extends Controller
{
    public function __invoke(PlatformCardQuery $query): Response
    {
        return Inertia::render('platform/Cards', $query->get());
    }
}
