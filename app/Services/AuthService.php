<?php

namespace App\Services;

use App\Enums\LoginResult;
use App\Enums\Platform;
use App\Enums\PlatformRole;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PlatformAccessService $platformAccessService,
        private readonly OtpService $otpService,
        private readonly AuditService $auditService
    ) {}

    public function registerB2b(array $data, ?Request $request = null): array
    {
        $user = $this->userRepository->create([
            'uuid' => Str::uuid()->toString(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        // BUSINESS RULE: B2B signup creates pending B2B access + auto-approved Bids access
        $this->platformAccessService->grantB2bAccess($user, PlatformRole::WHOLESALER->value);
        $this->platformAccessService->grantBidsAccess($user, PlatformRole::CONSUMER->value);

        $token = $user->createToken('b2b-web', ['platform:b2b'])->plainTextToken;

        Mail::to($user->email)->queue(new WelcomeEmail($user, Platform::B2B));

        $this->auditService->log(
            'register',
            'User registered via B2B',
            $user->id,
            Platform::B2B->value,
            request: $request
        );

        return [
            'user' => $user->fresh('platforms'),
            'token' => $token,
        ];
    }

    public function registerBids(string $identifier, string $identifierType = 'email'): void
    {
        $this->otpService->sendOtp($identifier, $identifierType);
    }

    public function completeBidsRegistration(string $identifier, string $code, ?Request $request = null): ?array
    {
        $result = $this->otpService->verifyOtp($identifier, $code);

        if (! $result['verified']) {
            return null;
        }

        $isNewUser = false;

        if ($result['user_id']) {
            $user = $this->userRepository->findById($result['user_id']);
        } else {
            // New user — create from OTP identifier
            $user = $this->userRepository->findByEmail($identifier);

            if (! $user) {
                $user = $this->userRepository->create([
                    'uuid' => Str::uuid()->toString(),
                    'name' => explode('@', $identifier)[0],
                    'email' => $identifier,
                    'email_verified_at' => now(),
                ]);
                $isNewUser = true;
            }
        }

        // Ensure bids access exists
        if (! $user->hasPlatformAccess(Platform::BIDS)) {
            $this->platformAccessService->grantBidsAccess($user);
            if (! $isNewUser) {
                $isNewUser = true;
            }
        }

        $token = $user->createToken('bids-web', ['platform:bids'])->plainTextToken;

        $this->auditService->log(
            $isNewUser ? 'register' : 'login',
            $isNewUser ? 'User registered via Bids OTP' : 'User logged in via Bids OTP',
            $user->id,
            Platform::BIDS->value,
            request: $request
        );

        return [
            'user' => $user->fresh('platforms'),
            'token' => $token,
            'is_new_user' => $isNewUser,
        ];
    }

    public function loginB2b(string $email, string $password, ?Request $request = null): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditService->log(
                'login_failed',
                'Failed B2B login attempt',
                $user?->id,
                Platform::B2B->value,
                ['user_found' => $user !== null],
                $request
            );

            return ['result' => LoginResult::INVALID_CREDENTIALS];
        }

        $b2bAccess = $user->platformAccess(Platform::B2B);

        $blockedResult = match (true) {
            ! $b2bAccess => LoginResult::NO_ACCESS,
            $b2bAccess->isSuspended() => LoginResult::SUSPENDED,
            $b2bAccess->isPending() => LoginResult::PENDING,
            ! $b2bAccess->isApproved() => LoginResult::REJECTED,
            default => null,
        };

        if ($blockedResult) {
            $this->auditService->log(
                'login_blocked',
                'B2B login blocked — '.$blockedResult->value,
                $user->id,
                Platform::B2B->value,
                ['reason' => $blockedResult->value],
                $request
            );

            return ['result' => $blockedResult];
        }

        $token = $user->createToken('b2b-web', ['platform:b2b'])->plainTextToken;

        $this->auditService->log(
            'login',
            'User logged in via B2B',
            $user->id,
            Platform::B2B->value,
            request: $request
        );

        return [
            'result' => LoginResult::SUCCESS,
            'user' => $user,
            'token' => $token,
        ];
    }

    public function createAdmin(array $data, User $createdBy): array
    {
        $user = $this->userRepository->create([
            'uuid' => Str::uuid()->toString(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        // SECURITY: is_super_admin is set out-of-band of mass assignment and is
        // only honoured when the actor is already a super-admin. See User::$fillable.
        if ($createdBy->isSuperAdmin() && ($data['is_super_admin'] ?? false)) {
            $user->is_super_admin = true;
            $user->save();
        }

        $this->platformAccessService->grantAdminAccess($user);

        $token = $user->createToken('admin', ['platform:b2b', 'platform:bids'])->plainTextToken;

        return [
            'user' => $user->fresh('platforms'),
            'token' => $token,
        ];
    }

    public function logout(User $user, string $tokenId): void
    {
        $user->tokens()->where('id', $tokenId)->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
