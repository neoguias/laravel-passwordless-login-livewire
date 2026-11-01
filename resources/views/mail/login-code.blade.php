<x-mail::message>
# {{ $isNew ? 'Crea tu cuenta' : 'Entra en tu cuenta' }}

Escribe este código en la página que has dejado abierta:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

Caduca en diez minutos y solo sirve una vez.

Si no has pedido entrar, no hagas nada: sin este código nadie puede usar tu email para acceder a una cuenta.

{{ config('app.name') }}
</x-mail::message>
