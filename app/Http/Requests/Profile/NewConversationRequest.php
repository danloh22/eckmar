<?php

namespace App\Http\Requests\Profile;

class NewConversationRequest extends NewMessageRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
            'username' => 'required|exists:users,username',
        ]);
    }

    public function messages()
    {
        return [
          'username.exists' => 'User under that username doesn\'t exists!'
        ];
    }
}
