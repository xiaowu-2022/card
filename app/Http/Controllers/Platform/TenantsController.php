<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class TenantsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('platform/Tenants');
    }
}
