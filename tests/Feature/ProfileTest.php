<?php

use App\Models\User;

test('profile page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', ['name' => 'Rashad', 'email' => 'rashad@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();
    expect($user->name)->toBe('Rashad')
        ->and($user->email)->toBe('rashad@example.com');
});

test('account deletion route does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->delete('/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();
});
