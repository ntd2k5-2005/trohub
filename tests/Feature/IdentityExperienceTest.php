<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_only_an_ordinary_account(): void
    {
        $this->post('/dang-ky', ['name' => 'Người dùng mới', 'email' => 'new@example.test', 'password' => 'SecurePass2026', 'password_confirmation' => 'SecurePass2026', 'role' => 'admin', 'access_group_id' => 999])
            ->assertRedirect('/xac-minh-email');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertGuest();
        $this->assertTrue($user->needsEmailActivation());
        $this->assertTrue(Hash::check('SecurePass2026', $user->password));
        $this->assertNotEquals(999, $user->access_group_id);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_invalid_login_preserves_email_and_displays_inline_error(): void
    {
        $url = '/ui/login?context=public';
        $this->from($url)->post('/dang-nhap', ['email' => 'missing@example.test', 'password' => 'WrongPassword'])->assertSessionHasErrors('email');
        $this->get($url)->assertOk()->assertSee('email-error')->assertSee('missing@example.test')->assertDontSee('value="WrongPassword"', false);
        $this->assertGuest();
    }

    public function test_locked_account_cannot_login_even_with_correct_password(): void
    {
        User::factory()->create(['email' => 'locked@example.test', 'password' => 'SecurePass2026', 'status' => 'locked']);
        $this->post('/dang-nhap', ['email' => 'locked@example.test', 'password' => 'SecurePass2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
