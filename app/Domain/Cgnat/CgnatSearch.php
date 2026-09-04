<?php

namespace App\Domain\Cgnat;

use Carbon\CarbonImmutable;

final readonly class CgnatSearch
{
    /**
     * @param  list<string>  $nodes
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $nodes,
        public string $routerIp,
        public string $privateIp,
        public ?int $privatePort,
        public ?string $publicIp,
        public ?int $publicPortFrom,
        public ?int $publicPortTo,
        public ?string $destinationIp,
        public ?int $destinationPort,
        public ?string $cursor = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $timezone = (string) config('clickhouse.timezone');

        return new self(
            from: CarbonImmutable::parse($validated['from'], $timezone),
            to: CarbonImmutable::parse($validated['to'], $timezone),
            nodes: array_values($validated['nodes'] ?? array_keys(config('clickhouse.nodes', []))),
            routerIp: (string) $validated['router_ip'],
            privateIp: (string) $validated['private_ip'],
            privatePort: isset($validated['private_port']) ? (int) $validated['private_port'] : null,
            publicIp: $validated['public_ip'] ?? null,
            publicPortFrom: isset($validated['public_port_from']) ? (int) $validated['public_port_from'] : null,
            publicPortTo: isset($validated['public_port_to']) ? (int) $validated['public_port_to'] : null,
            destinationIp: $validated['destination_ip'] ?? null,
            destinationPort: isset($validated['destination_port']) ? (int) $validated['destination_port'] : null,
            cursor: filled($validated['cursor'] ?? null) ? (string) $validated['cursor'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d\TH:i'),
            'to' => $this->to->format('Y-m-d\TH:i'),
            'nodes' => $this->nodes,
            'router_ip' => $this->routerIp,
            'private_ip' => $this->privateIp,
            'private_port' => $this->privatePort,
            'public_ip' => $this->publicIp,
            'public_port_from' => $this->publicPortFrom,
            'public_port_to' => $this->publicPortTo,
            'destination_ip' => $this->destinationIp,
            'destination_port' => $this->destinationPort,
        ];
    }
}
