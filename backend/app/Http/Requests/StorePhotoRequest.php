<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePhotoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'max:'.config('photos.max_upload_kb'),
                'mimetypes:'.implode(',', config('photos.allowed_mimes')),
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must not be larger than '.(config('photos.max_upload_kb') / 1024).' MB.',
            'photo.mimetypes' => 'Only JPEG, PNG and WebP photos are supported. HEIC photos must be converted first.',
        ];
    }

    /**
     * Run checks that need the file contents, after the standard rules pass.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('photo')) {
                    return;
                }

                $dimensions = @getimagesize($this->file('photo')->getRealPath());

                if ($dimensions === false) {
                    $validator->errors()->add('photo', 'The photo could not be read as an image.');

                    return;
                }

                if (config('photos.max_pixels') < $dimensions[0] * $dimensions[1]) {
                    $validator->errors()->add('photo', 'The photo has too many pixels.');
                }
            },
        ];
    }
}
