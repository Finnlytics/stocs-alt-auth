<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_lowercased_and_trimmed_on_create(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => '  Connor.AK.Finn@Gmail.com  ',
            'password' => null,
        ]);

        $this->assertSame('connor.ak.finn@gmail.com', $user->email);
        $this->assertDatabaseHas('users', ['email' => 'connor.ak.finn@gmail.com']);
    }

    public function test_repository_find_by_email_is_case_insensitive(): void
    {
        User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'connor.ak.finn@gmail.com',
            'password' => null,
        ]);

        $found = app(UserRepository::class)->findByEmail('Connor.AK.Finn@Gmail.COM');

        $this->assertNotNull($found);
        $this->assertSame('connor.ak.finn@gmail.com', $found->email);
    }
}
