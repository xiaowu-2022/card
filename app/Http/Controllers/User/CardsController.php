<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class CardsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('user/Cards');
    }
}
