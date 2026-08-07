<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    public function test_google_callback_without_authorization_code_returns_a_friendly_error(): void
    {
        $response = $this
            ->withSession(['login_redirect' => '/licenses'])
            ->withCookie('login_redirect', '/licenses')
            ->get('/auth/google/callback');

        $response
            ->assertRedirect('/')
            ->assertSessionHasErrors([
                'auth' => 'Google did not return a valid sign-in response. Please try again.',
            ])
            ->assertSessionMissing('login_redirect')
            ->assertCookieExpired('login_redirect');
    }

    public function test_google_callback_handles_user_cancellation_without_contacting_google(): void
    {
        $response = $this->get('/auth/google/callback?error=access_denied');

        $response
            ->assertRedirect('/')
            ->assertSessionHasErrors([
                'auth' => 'Google sign-in was cancelled. Please try again when you are ready.',
            ]);
    }
}
