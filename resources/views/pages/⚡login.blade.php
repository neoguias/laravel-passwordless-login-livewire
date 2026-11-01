<?php

use App\Auth\EmailCode;
use App\Models\User;
use EduLazaro\Laracaptcha\Livewire\WithCaptcha;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Una sola puerta, en un único componente: primero el email y después las seis cifras que
 * le enviamos. Entrar y registrarse son la misma pantalla, y el código sirve también para
 * recuperar el acceso, así que no hay contraseña que olvidar.
 */
new #[Title('Entrar')] class extends Component
{
    use WithCaptcha;

    /**
     * Códigos que puede haber enviado una misma máquina, sumando todas las direcciones. El
     * límite por dirección de EmailCode no ve a un bot que escribe una dirección distinta en
     * cada intento.
     *
     * @var array<int, int> Ventana en segundos => códigos permitidos en ella.
     */
    private const CODES_PER_ORIGIN = [3600 => 6, 86400 => 20];

    public string $step = 'email';

    public string $email = '';

    public string $code = '';

    /**
     * Turnstile se comprueba una vez por sesión: quien se equivoca de email y vuelve a
     * empezar no tiene que resolverlo de nuevo.
     */
    public function sendCode(): void
    {
        // Livewire no pasa por el middleware que recorta espacios: un email pegado con un
        // espacio al final no sería válido.
        $this->email = Str::lower(trim($this->email));

        $this->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.required' => 'Escribe tu email.', 'email.email' => 'Ese email no es válido.', 'email.max' => 'Ese email es demasiado largo.'],
        );

        $this->verifyCaptchaOnce('login');

        $this->issue();

        $this->step = 'code';
        $this->code = '';
    }

    public function resend(): void
    {
        $this->issue();

        $this->code = '';
        $this->resetValidation();
        session()->flash('status', 'Te hemos enviado un código nuevo. El anterior ya no sirve.');
    }

    public function verify(): void
    {
        $this->validate(
            ['code' => ['required', 'digits:6']],
            ['code.required' => 'Escribe el código.', 'code.digits' => 'El código tiene 6 cifras.'],
        );

        $codes = EmailCode::for($this->email);

        $error = match ($codes->check($this->code)) {
            EmailCode::OK => null,
            EmailCode::WRONG => 'Ese no es el código. Te quedan '.$codes->triesLeft().' intentos.',
            EmailCode::BURNT => 'Demasiados intentos con ese código. Pide uno nuevo.',
            EmailCode::GONE => 'El código ha caducado. Pide uno nuevo.',
            EmailCode::LOCKED => 'Demasiados códigos incorrectos hoy para este email. Vuelve a intentarlo mañana.',
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        // Llegar a la bandeja de entrada es lo que demuestra la dirección: ninguna cuenta nace sin verificar.
        $user = User::firstOrCreate(['email' => $this->email], ['name' => Str::before($this->email, '@')]);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'));
    }

    public function useAnother(): void
    {
        $this->reset('step', 'code');
        $this->resetValidation();
    }

    /**
     * Comprueba los límites y envía el código. Cualquier método público de un componente se
     * puede llamar desde el navegador con la dirección que se quiera, así que el captcha se
     * vuelve a exigir aquí y no solo en sendCode().
     */
    private function issue(): void
    {
        if (! $this->captchaPassed('login')) {
            $this->step = 'email';

            throw ValidationException::withMessages(['captcha' => 'Completa la verificación de seguridad.']);
        }

        $codes = EmailCode::for($this->email);

        if ($codes->tooManyToday()) {
            throw ValidationException::withMessages([$this->field() => 'Has pedido demasiados códigos hoy para este email. Vuelve a intentarlo mañana.']);
        }

        if ($codes->tooMany()) {
            throw ValidationException::withMessages([$this->field() => 'Ya te hemos enviado varios códigos. Espera '.max(1, (int) ceil($codes->availableIn() / 60)).' minutos o usa el último que te llegó.']);
        }

        $origin = sha1((string) request()->ip());

        foreach (self::CODES_PER_ORIGIN as $window => $allowed) {
            if (RateLimiter::tooManyAttempts("login:origin:{$window}:{$origin}", $allowed)) {
                throw ValidationException::withMessages([$this->field() => 'Se han enviado demasiados códigos desde aquí. Vuelve a intentarlo más tarde.']);
            }
        }

        foreach (array_keys(self::CODES_PER_ORIGIN) as $window) {
            RateLimiter::hit("login:origin:{$window}:{$origin}", $window);
        }

        $codes->send(isNew: ! User::where('email', $this->email)->exists());
    }

    /**
     * El campo junto al que se muestra un error de envío: el email en el primer paso y el
     * código en el segundo.
     */
    private function field(): string
    {
        return $this->step === 'email' ? 'email' : 'code';
    }
};
?>

<div>
    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    @if ($step === 'email')
        <h1>Entrar</h1>
        <p class="lead">Sin contraseña: te enviamos un código a tu email.</p>

        <form wire:submit="sendCode">
            <label for="email">Email</label>
            <input id="email" type="email" wire:model="email" required autofocus autocomplete="username" maxlength="255">
            @error('email') <p class="error">{{ $message }}</p> @enderror

            @unless ($this->captchaPassed('login'))
                <x-laracaptcha::widget wire:model="captcha" language="es" />
                @error('captcha') <p class="error">{{ $message }}</p> @enderror
            @endunless

            <button type="submit">
                <span wire:loading.remove wire:target="sendCode">Enviarme el código</span>
                <span wire:loading wire:target="sendCode">Enviando…</span>
            </button>
        </form>

        <p class="hint">¿Aún no tienes cuenta? Escribe tu email: el mismo formulario la crea.</p>
    @else
        <h1>Revisa tu correo</h1>
        <p class="lead">Te hemos enviado seis cifras a <strong>{{ $email }}</strong>.</p>

        <form wire:submit="verify">
            <label for="code">Código</label>
            <input id="code" type="text" wire:model="code" required autofocus inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" class="code">
            @error('code') <p class="error">{{ $message }}</p> @enderror

            <button type="submit">
                <span wire:loading.remove wire:target="verify">Entrar</span>
                <span wire:loading wire:target="verify">Comprobando…</span>
            </button>
        </form>

        <div class="actions">
            <button type="button" wire:click="useAnother" class="link">Usar otro email</button>
            <button type="button" wire:click="resend" class="link">Enviar otro código</button>
        </div>

        <p class="hint">Caduca en diez minutos y solo sirve una vez. Si no lo ves, mira en spam.</p>
    @endif
</div>
