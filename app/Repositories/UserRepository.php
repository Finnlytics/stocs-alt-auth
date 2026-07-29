<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class UserRepository
{
    public function findById(int $id): ?User
    {
        return User::with('platforms')->find($id);
    }

    public function findByUuid(string $uuid): ?User
    {
        return User::with('platforms')->where('uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?User
    {
        // Stored emails are always lowercased (User::email mutator); normalize
        // the lookup value the same way so casing differences at call sites
        // (OTP identifier, admin search, service lookups) don't miss a match.
        return User::with('platforms')->where('email', Str::lower(trim($email)))->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh('platforms');
    }

    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = User::with('platforms');

        if (isset($filters['platform'])) {
            $query->whereHas('platforms', function ($q) use ($filters) {
                $q->where('platform', $filters['platform']);
                if (isset($filters['status'])) {
                    $q->where('status', $filters['status']);
                }
                if (isset($filters['role'])) {
                    $q->where('role', $filters['role']);
                }
            });
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function softDelete(User $user): void
    {
        $user->delete();
    }
}
