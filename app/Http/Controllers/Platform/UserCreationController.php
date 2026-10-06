<?php

namespace App\Http\Controllers\Platform;

use App\Application\User\CreatePlatformUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class UserCreationController extends Controller
{
    public function __invoke(Request $request, string $tenant, CreatePlatformUserAction $action)
    {
        $request->merge(['email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email')]);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(6)],
            'request_id' => ['required', 'uuid'],
        ]);
        $action->execute($tenant, $request->user('platform_admin'), $data);

        return back()->with('success', 'Account created.');
    }
}
