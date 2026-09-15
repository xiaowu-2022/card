<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\CreatePlatformAdminAction;
use App\Application\Admin\PlatformAdminTeamQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePlatformAdminRequest;
use Inertia\Inertia;

final class AdministratorController extends Controller
{
    public function index(PlatformAdminTeamQuery $query)
    {
        return Inertia::render('platform/Administrators', ['team' => $query->execute()]);
    }

    public function store(CreatePlatformAdminRequest $request, CreatePlatformAdminAction $create)
    {
        $data = $request->validated();
        $create->execute($request->user('platform_admin'), $data['name'], $data['email'], $data['password'], $data['role'], $data['current_password'], $request->attributes->get('request_id'));

        return back()->with('success', 'Platform administrator created.');
    }
}
