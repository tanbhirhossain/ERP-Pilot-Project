<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Support\PublishedCredentialGuard;
use Modules\HRM\Services\EmployeeLoginService;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // The login box speaks employee code as well as email. A code resolves to
        // the linked user's address so every guard below keeps speaking email;
        // an unknown code fails exactly like an unknown address.
        $logins = app(EmployeeLoginService::class);
        $login = (string) $this->string('email');
        $password = (string) $this->string('password');
        $email = $logins->resolveEmail($login);

        if ($email === null || ! Auth::attempt(['email' => $email, 'password' => $password], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // A leaver's credentials still match — the row is untouched — so the
        // lockout is checked after a real authentication, and answered like
        // any other failure.
        if ($logins->isLeaverLocked(Auth::user())) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact HR.',
            ]);
        }

        // The credentials matched. In production, refuse the ones this repository publishes —
        // `password` is in config/core.php, .env.example and both seeders, and the account it
        // belongs to holds the `*` wildcard permission. Checked *after* Auth::attempt so the
        // message only appears for a login that really would have succeeded.
        if (PublishedCredentialGuard::shouldRefuse($email, $password)) {
            PublishedCredentialGuard::report($email);
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => PublishedCredentialGuard::message($email),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
