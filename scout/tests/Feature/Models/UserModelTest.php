<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * This test verifies users table schema including fortify 2FA columns.
 */
test('users table contains required columns', function () {
    expect(Schema::hasTable('users'))->toBeTrue();

    expect(Schema::hasColumns('users', [
        'id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

/**
 * This test verifies user-thread relationship and hashed password cast behavior.
 */
test('user has many threads and password is hashed on create', function () {
    $user = User::factory()->create([
        'password' => 'plain-password',
    ]);

    $thread = $user->threads()->create([
        'objective' => 'Assess melatonin efficacy for delayed sleep phase disorder',
        'theme_context' => 'Circadian rhythm disorders',
    ]);

    $user->refresh();

    expect($user->threads)->toHaveCount(1);
    expect($user->threads->first()?->is($thread))->toBeTrue();
    expect(password_verify('plain-password', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('plain-password');
});

/**
 * This test verifies fillable/hidden policy from model attributes.
 */
test('user model fillable and hidden attributes are configured', function () {
    $user = new User();

    expect($user->isFillable('name'))->toBeTrue();
    expect($user->isFillable('email'))->toBeTrue();
    expect($user->isFillable('password'))->toBeTrue();
    expect($user->isFillable('remember_token'))->toBeFalse();

    expect($user->getHidden())->toContain('password');
    expect($user->getHidden())->toContain('two_factor_secret');
    expect($user->getHidden())->toContain('two_factor_recovery_codes');
    expect($user->getHidden())->toContain('remember_token');
});
