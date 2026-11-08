<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        abort_unless((bool) config('core.auth.allow_registration'), 404);

        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // This is the single gate for self-registration. The route is always
        // registered so the switch is evaluated per request and takes effect on
        // `config:cache` alone, without re-caching routes.
        abort_unless((bool) config('core.auth.allow_registration'), 404);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // A self-registered account starts with no role, so it can see nothing
        // until an administrator grants access. Assign one only if the
        // deployment explicitly configures a default.
        if ($role = config('core.auth.default_registration_role')) {
            $user->syncRoles([$role]);
        }

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
