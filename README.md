# Login sin contraseña en Laravel (Livewire)

Una aplicación Laravel con Livewire en la que se entra sin contraseña: escribes tu email, te llega un código de seis cifras y con él entras. Todo el login es un único componente que pasa del primer paso al segundo sin recargar la página. El mismo formulario crea la cuenta la primera vez y sirve para recuperar el acceso.

Antes de enviar ningún código, el formulario pasa por [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) con [Laracaptcha](https://github.com/edulazaro/laracaptcha), una vez por sesión.

El tutorial completo, paso a paso, está en Neoguias: [Cómo crear un login sin contraseña con Laravel y Livewire](https://www.neoguias.com/login-sin-contrasena-laravel-livewire/). La versión sin Livewire, con controladores y Blade, está en [neoguias/laravel-passwordless-login](https://github.com/neoguias/laravel-passwordless-login).

## Cómo arrancarlo

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve
```

Abre `http://localhost:8000` y escribe cualquier email. En local los correos no se envían: van al log (`MAIL_MAILER=log`), así que el código lo verás con `php artisan pail` o en `storage/logs/laravel.log`.

Las claves de Turnstile de `.env.example` son las de prueba que publica Cloudflare, que siempre aprueban. En producción pon las tuyas en `TURNSTILE_KEY` y `TURNSTILE_SECRET`, y un SMTP real en las variables `MAIL_*`.

Los tests cubren todo el flujo con `Livewire::test`, `Mail::fake()` y `Captcha::fake()`, sin enviar correos ni llamar a Cloudflare:

```bash
php artisan test
```

Requiere PHP 8.3, Laravel 13 y Livewire 4.

## Dónde está cada cosa

- [`resources/views/pages/⚡login.blade.php`](resources/views/pages/⚡login.blade.php): el componente del login, con la clase y la vista en el mismo archivo. Comprueba el captcha una vez por sesión, envía el código, lo verifica y crea la cuenta. Como cualquier método público de un componente se puede llamar desde el navegador, el reenvío vuelve a exigir el captcha.
- [`resources/views/pages/⚡panel.blade.php`](resources/views/pages/⚡panel.blade.php): el panel y el cierre de sesión.
- [`app/Auth/EmailCode.php`](app/Auth/EmailCode.php): genera el código, guarda su hash en la caché y lo comprueba. Caduca a los diez minutos, admite cinco intentos y limita los envíos por dirección.
- [`app/Mail/LoginCode.php`](app/Mail/LoginCode.php) y [`resources/views/mail/login-code.blade.php`](resources/views/mail/login-code.blade.php): el correo, con el código también en el asunto.
- [`routes/web.php`](routes/web.php): las páginas `/login` y `/panel`, registradas con `Route::livewire`.
- [`database/migrations`](database/migrations): la migración que deja la columna `password` admitiendo `null`, porque estas cuentas no tienen contraseña.
- [`tests/Feature/PasswordlessLoginTest.php`](tests/Feature/PasswordlessLoginTest.php): los tests.

## Autor

Creado por [Edu Lázaro](https://edulazaro.com) para [Neoguias](https://www.neoguias.com).

## Licencia

Este ejemplo es software libre con [licencia MIT](LICENSE).
