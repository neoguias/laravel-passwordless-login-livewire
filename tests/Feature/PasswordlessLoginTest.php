<?php

namespace Tests\Feature;

use App\Mail\LoginCode;
use App\Models\User;
use EduLazaro\Laracaptcha\Facades\Captcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordlessLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Captcha::fake();
    }

    public function test_the_login_page_asks_only_for_an_email(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('wire:model="email"', false)
            ->assertDontSee('password');
    }

    public function test_a_new_address_gets_a_code_and_the_right_code_creates_a_verified_account(): void
    {
        $login = $this->askForCode('Nueva@Example.com ')->assertSet('step', 'code')->assertHasNoErrors();

        Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => $mail->hasTo('nueva@example.com') && $mail->isNew);
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.com']);

        $login->set('code', $this->sentCode('nueva@example.com'))->call('verify')->assertRedirect('/panel');

        $user = User::where('email', 'nueva@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
    }

    public function test_an_existing_account_signs_in_with_its_code(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $login = $this->askForCode('ana@example.com');
        Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => ! $mail->isNew);

        $login->set('code', $this->sentCode('ana@example.com'))->call('verify')->assertRedirect('/panel');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_no_code_is_sent_without_passing_the_captcha(): void
    {
        Livewire::test('pages::login')->set('email', 'nueva@example.com')->call('sendCode')->assertHasErrors('captcha')->assertSet('step', 'email');

        Captcha::fake(success: false);
        $this->askForCode('nueva@example.com')->assertHasErrors('captcha');

        Mail::assertNothingSent();
    }

    public function test_calling_resend_directly_without_the_captcha_sends_nothing(): void
    {
        Livewire::test('pages::login')
            ->set('email', 'victima@example.com')
            ->set('step', 'code')
            ->call('resend')
            ->assertHasErrors('captcha')
            ->assertSet('step', 'email');

        Mail::assertNothingSent();
    }

    public function test_a_wrong_code_is_refused_and_the_fifth_destroys_it(): void
    {
        $login = $this->askForCode('nueva@example.com');
        $code = $this->sentCode('nueva@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        $login->set('code', $wrong)->call('verify')->assertHasErrors(['code' => 'Ese no es el código. Te quedan 4 intentos.']);

        for ($i = 0; $i < 4; $i++) {
            $login->set('code', $wrong)->call('verify');
        }

        $login->set('code', $code)->call('verify')->assertHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.com']);
    }

    public function test_a_new_code_replaces_the_previous_one(): void
    {
        $login = $this->askForCode('nueva@example.com');
        $first = $this->sentCode('nueva@example.com');

        $login->call('resend')->assertHasNoErrors();
        $second = $this->sentCode('nueva@example.com');

        if ($first !== $second) {
            $login->set('code', $first)->call('verify')->assertHasErrors('code');
        }
        $login->set('code', $second)->call('verify')->assertRedirect('/panel');
    }

    public function test_one_address_gets_three_codes_every_ten_minutes(): void
    {
        $login = $this->askForCode('nueva@example.com');
        $login->call('resend')->call('resend');

        $login->call('resend')->assertHasErrors('code');

        Mail::assertSentCount(3);
    }

    public function test_one_machine_gets_six_codes_an_hour_across_addresses(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->askForCode("persona{$i}@example.com")->assertSet('step', 'code');
        }

        $this->askForCode('persona7@example.com')->assertHasErrors('email')->assertSet('step', 'email');

        Mail::assertSentCount(6);
    }

    public function test_the_panel_is_only_for_signed_in_users_and_logout_ends_the_session(): void
    {
        $this->get('/panel')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/panel')->assertOk()->assertSee($user->email);

        Livewire::test('pages::panel')->call('logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function askForCode(string $email): Testable
    {
        return Livewire::test('pages::login')
            ->set('email', $email)
            ->set('captcha', Str::random(20))
            ->call('sendCode');
    }

    /**
     * El último código enviado a una dirección.
     */
    private function sentCode(string $email): string
    {
        return Mail::sent(LoginCode::class, fn (LoginCode $mail) => $mail->hasTo($email))->last()->code;
    }
}
