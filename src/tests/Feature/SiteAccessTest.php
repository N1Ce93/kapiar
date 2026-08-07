<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SiteAccessTest extends TestCase
{
    public function test_access_page_is_available_without_password(): void
    {
        $this->get('/access')
            ->assertOk()
            ->assertSee('Доступ до моніторингу');
    }

    public function test_protected_pages_redirect_to_access_page(): void
    {
        $this->get('/sites')->assertRedirect('/access');
        $this->get('/telegram')->assertRedirect('/access');
    }

    public function test_correct_password_grants_access(): void
    {
        config(['services.site_access.password_hash' => Hash::make('marketing')]);

        $this->post('/access', ['password' => 'marketing'])
            ->assertRedirect('/sites')
            ->assertSessionHas('site_access_granted', true);
    }

    public function test_empty_password_shows_ukrainian_validation_message(): void
    {
        $this->from('/access')
            ->post('/access', [])
            ->assertRedirect('/access')
            ->assertSessionHasErrors(['password' => 'Введіть пароль.']);
    }

    public function test_wrong_password_shows_denied_page(): void
    {
        config(['services.site_access.password_hash' => Hash::make('marketing')]);

        $this->post('/access', ['password' => 'wrong-password'])
            ->assertForbidden()
            ->assertSee('Вибачте, у вас немає доступу');
    }

    public function test_invalid_password_hash_shows_denied_page(): void
    {
        config(['services.site_access.password_hash' => 'invalid-hash']);

        $this->post('/access', ['password' => 'marketing'])
            ->assertForbidden()
            ->assertSee('Вибачте, у вас немає доступу');
    }

    public function test_logout_revokes_access(): void
    {
        $this
            ->withSession(['site_access_granted' => true])
            ->post('/logout')
            ->assertRedirect('/access')
            ->assertSessionMissing('site_access_granted');
    }
}
