<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at,
            'is_super_admin' => $this->is_super_admin,
            'marketing_preferences' => $this->marketing_preferences,
            'platforms' => $this->whenLoaded('platforms', function () {
                return $this->platforms->map(fn ($p) => [
                    'platform' => $p->platform,
                    'role' => $p->role,
                    'status' => $p->status,
                    'approved_at' => $p->approved_at,
                ]);
            }),
            'created_at' => $this->created_at,
        ];
    }
}
