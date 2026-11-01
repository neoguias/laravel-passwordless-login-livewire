<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component
{
    public function logout(): void
    {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('login');
    }
};
?>

<div>
    <h1>Hola, {{ auth()->user()->name }}</h1>
    <p class="lead">Has entrado como <strong>{{ auth()->user()->email }}</strong>, sin contraseña.</p>

    <button type="button" wire:click="logout">Cerrar sesión</button>
</div>
