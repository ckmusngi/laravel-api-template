<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
{
    /**
     * Columns the list may be sorted by. Prefix with "-" for descending order.
     *
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = ['name', 'email', 'created_at'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sorts = collect(self::SORTABLE_COLUMNS)
            ->flatMap(fn (string $column): array => [$column, '-'.$column])
            ->all();

        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get the column and direction to sort by.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function sort(): array
    {
        $sort = $this->validated('sort', '-created_at');

        return str_starts_with($sort, '-')
            ? [substr($sort, 1), 'desc']
            : [$sort, 'asc'];
    }
}
