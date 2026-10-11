<?php

namespace App\Modules\Teams\Presentation\Http\Requests;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Presentation\Rules\ValidTeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RespondToTeamInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invitation' => ['required', new ValidTeamInvitation($this->user(), app(UserDirectory::class))],
        ];
    }

    /**
     * Get the validation data from the request.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'invitation' => $this->route('invitation'),
        ]);
    }
}
