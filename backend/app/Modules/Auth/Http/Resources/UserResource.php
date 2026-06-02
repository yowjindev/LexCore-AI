<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'email'           => $this->email,
            'organization_id' => $this->organization_id,
            'roles'           => $this->getRoleNames(),
            'permissions'     => $this->getAllPermissions()->pluck('name'),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
