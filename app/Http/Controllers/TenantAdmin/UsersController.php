<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class UsersController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('tenant-admin/Users');
    }
}
