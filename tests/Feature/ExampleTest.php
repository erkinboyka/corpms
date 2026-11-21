<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_shows_the_public_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Рабочий день компании')
            ->assertSee(route('login'), false)
            ->assertSee('data-landing-canvas', false);
    }

    public function test_landing_aliases_redirect_to_the_root(): void
    {
        $this->get('/welcome')->assertRedirect(route('welcome'));
        $this->get('/landing')->assertRedirect(route('welcome'));
    }
}
