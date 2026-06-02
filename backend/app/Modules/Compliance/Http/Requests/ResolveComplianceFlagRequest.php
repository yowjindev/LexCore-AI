<?php
namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveComplianceFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'manager', 'superadmin']) ?? false;
    }

    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): never
    {
        abort(403, 'Only admins and managers can resolve compliance flags.');
    }
}
