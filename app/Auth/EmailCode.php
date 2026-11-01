<?php

namespace App\Auth;

use App\Mail\LoginCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Seis cifras a una bandeja de entrada, y las reglas que hacen que signifiquen algo.
 *
 * - En la caché se guarda el hash del código: quien pueda leer la caché no puede entrar en
 *   las cuentas con lo que encuentre.
 * - Cinco intentos por código. Seis cifras son un millón de combinaciones para una persona
 *   y nada para un bucle.
 * - Tres códigos por dirección cada diez minutos y diez al día, contados por DIRECCIÓN y no
 *   por la máquina que los pide: nadie debe poder llenar un buzón desde mil máquinas.
 * - Veinte códigos incorrectos por dirección y día; después, esa dirección no recibe nada
 *   hasta mañana.
 */
final class EmailCode
{
    public const TRIES = 5;

    public const MINUTES = 10;

    public const PER_WINDOW = 3;

    public const CODES_PER_DAY = 10;

    public const FAILURES_PER_DAY = 20;

    /** Correcto, y ya gastado. */
    public const OK = 'ok';

    /** No hay ninguno: caducó, o nunca se pidió. */
    public const GONE = 'gone';

    /** Demasiados intentos fallidos: el código se destruye en vez de dejar que lo adivinen. */
    public const BURNT = 'burnt';

    public const WRONG = 'wrong';

    /** Demasiados códigos incorrectos hoy para esta dirección. Quien lo reciba debe tratarlo como un rechazo. */
    public const LOCKED = 'locked';

    private function __construct(private readonly string $email) {}

    public static function for(string $email): self
    {
        return new self(Str::lower(trim($email)));
    }

    public function tooMany(): bool
    {
        return RateLimiter::tooManyAttempts($this->key('send'), self::PER_WINDOW) || $this->tooManyToday();
    }

    /**
     * Si lo que impide enviar es el límite diario, para que la pantalla pueda decir «mañana».
     */
    public function tooManyToday(): bool
    {
        return RateLimiter::tooManyAttempts($this->key('send-day'), self::CODES_PER_DAY);
    }

    /**
     * Segundos que faltan para que la ventana de diez minutos deje pasar otro código.
     */
    public function availableIn(): int
    {
        return RateLimiter::availableIn($this->key('send'));
    }

    /**
     * Genera un código, guarda su hash y lo envía.
     *
     * Sin cola, a propósito: quien lo ha pedido está esperando delante de la pantalla, y una
     * cola solo añade una forma de que el código no llegue nunca sin que nadie se entere.
     *
     * @param  bool  $isNew  Si esta dirección está a punto de convertirse en una cuenta.
     */
    public function send(bool $isNew): void
    {
        RateLimiter::hit($this->key('send'), self::MINUTES * 60);
        RateLimiter::hit($this->key('send-day'), 86400);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key('code'), ['code' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::MINUTES));

        Mail::to($this->email)->send(new LoginCode($code, $isNew));
    }

    /**
     * Los intentos se cuentan antes de comparar, así que el quinto fallo es el último, y el
     * límite diario se mira antes que nada, así que en un día bloqueado ni el código correcto
     * abre la cuenta.
     *
     * @return string OK, GONE, BURNT, WRONG o LOCKED.
     */
    public function check(string $code): string
    {
        if (RateLimiter::tooManyAttempts($this->key('failures'), self::FAILURES_PER_DAY)) {
            return self::LOCKED;
        }

        $held = Cache::get($this->key('code'));

        if (! $held) {
            return self::GONE;
        }

        if ($held['tries'] >= self::TRIES) {
            Cache::forget($this->key('code'));

            return self::BURNT;
        }

        if (! Hash::check($code, $held['code'])) {
            $held['tries']++;
            Cache::put($this->key('code'), $held, now()->addMinutes(self::MINUTES));
            RateLimiter::hit($this->key('failures'), 86400);

            return self::WRONG;
        }

        // Gastado: un código que funciona dos veces lo puede reutilizar quien vio la notificación.
        Cache::forget($this->key('code'));
        RateLimiter::clear($this->key('send'));

        return self::OK;
    }

    public function triesLeft(): int
    {
        $held = Cache::get($this->key('code'));

        return $held ? max(0, self::TRIES - (int) $held['tries']) : 0;
    }

    private function key(string $kind): string
    {
        return "login:{$kind}:".sha1($this->email);
    }
}
