<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertRedirect(route('dashboard'));
    }

    public function test_guests_opening_the_home_end_up_on_the_login_page(): void
    {
        $this->followingRedirects()
            ->get(route('home'))
            ->assertOk()
            ->assertViewIs('pages::auth.login');
    }
}
