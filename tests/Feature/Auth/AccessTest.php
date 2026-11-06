<?php

use App\Models\User;

test('every app page requires login', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with(['/', '/archive', '/companies', '/search', '/settings', '/profile']);

test('logged-in user can open every app page', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertOk();
})->with(['/', '/archive', '/companies', '/search', '/settings', '/profile']);

test('registration, password reset and email verification are disabled', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['GET', '/forgot-password'],
    ['POST', '/forgot-password'],
    ['GET', '/reset-password/some-token'],
    ['GET', '/verify-email'],
]);

test('login is rate limited after repeated failures', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('ثانية');
    $this->assertGuest();
});

test('horizon dashboard is not public', function () {
    $this->get('/horizon')->assertForbidden();
});
