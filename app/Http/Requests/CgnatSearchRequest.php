<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CgnatSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $nodes = array_keys(config('clickhouse.nodes', []));
        $port = ['nullable', 'integer', 'between:1,65535'];

        return [
            'from' => ['required', 'date_format:Y-m-d\TH:i'],
            'to' => ['required', 'date_format:Y-m-d\TH:i', 'after:from'],
            'nodes' => ['nullable', 'array', 'min:1'],
            'nodes.*' => ['string', Rule::in($nodes)],
            'router_ip' => ['required', 'ipv4'],
            'private_ip' => ['required', 'ipv4'],
            'private_port' => $port,
            'public_ip' => ['nullable', 'ipv4'],
            'public_port_from' => $port,
            'public_port_to' => [
                ...$port,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $from = request()->integer('public_port_from');

                    if ($value !== null && $from > 0 && (int) $value < $from) {
                        $fail('El puerto final NAT debe ser mayor o igual al puerto inicial.');
                    }
                },
            ],
            'destination_ip' => ['nullable', 'ipv4'],
            'destination_port' => $port,
            'cursor' => ['nullable', 'string', 'max:4096'],
            'known_total' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $timezone = (string) config('clickhouse.timezone', 'America/Lima');
                $maxHours = max(1, (int) config('clickhouse.max_interactive_hours', 168));
                $from = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $this->input('from'), $timezone);
                $to = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $this->input('to'), $timezone);

                if ($from->diffInMinutes($to) > $maxHours * 60) {
                    $rangeLabel = $maxHours % 24 === 0
                        ? ($maxHours / 24).' días'
                        : $maxHours.' horas';

                    $validator->errors()->add('to', "El rango máximo de búsqueda es de {$rangeLabel}.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'La fecha inicial es obligatoria.',
            'from.date_format' => 'La fecha inicial no tiene un formato válido.',
            'to.required' => 'La fecha final es obligatoria.',
            'to.date_format' => 'La fecha final no tiene un formato válido.',
            'to.after' => 'La fecha final debe ser posterior a la fecha inicial.',
            'router_ip.required' => 'El router es obligatorio.',
            'router_ip.ipv4' => 'El router debe contener una dirección IPv4 válida.',
            'private_ip.required' => 'La IP origen es obligatoria.',
            'private_ip.ipv4' => 'La IP origen debe contener una dirección IPv4 válida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'fecha inicial',
            'to' => 'fecha final',
            'router_ip' => 'router',
            'private_ip' => 'IP origen',
            'public_ip' => 'IP NAT pública',
            'destination_ip' => 'IP destino',
        ];
    }
}
