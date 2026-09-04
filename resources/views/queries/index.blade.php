@extends('layouts.portal')
@section('title', 'Consulta sesiones | Portal CGNAT')
@section('page-title', 'Consulta sesiones')
@section('content')
@php
    $values = request()->query();
    $defaultFrom = now(config('clickhouse.timezone'))->subHour()->format('Y-m-d\TH:i');
    $defaultTo = now(config('clickhouse.timezone'))->format('Y-m-d\TH:i');
    $selectedNodes = (array) ($values['nodes'] ?? old('nodes', array_keys($nodes)));
    $canSelectNodes = in_array('cgnat.nodes', $permissions, true);
    $isAdmin = in_array('cgnat.admin', $permissions, true);
    $maxInteractiveHours = max(1, (int) config('clickhouse.max_interactive_hours', 168));
    $maxRangeLabel = $maxInteractiveHours % 24 === 0
        ? ($maxInteractiveHours / 24).' días'
        : $maxInteractiveHours.' horas';
@endphp

<div class="page-heading">
    <div>
        <h1>Consulta sesiones</h1>
        <p>Consulta registros CGNAT por <strong>fecha inicio/fin, ip privada, puerto , router</strong>. El rango máximo es de {{ $maxRangeLabel }}.</p>
    </div>
    @if ($isAdmin)
        <span class="environment-badge">Entorno {{ app()->environment() }}</span>
    @endif
</div>

<section class="card" data-search-card aria-labelledby="search-title">
    <div class="card__header">
        <h2 id="search-title">Condiciones de búsqueda</h2>
        <div class="card__tools">
            <span class="pill pill--neutral">Zona horaria: {{ config('clickhouse.timezone') }}</span>
            <button class="icon-button" type="button" data-search-collapse aria-expanded="true" aria-label="Ocultar condiciones">⌃</button>
        </div>
    </div>

    <div class="card__body" data-search-body>
        <div class="alert alert--danger" data-query-feedback role="alert" hidden></div>
        <form method="POST" action="{{ route('queries.store') }}" data-query-form data-export-url="{{ route('exports.store') }}" data-template-url="{{ route('templates.store') }}">
            @csrf
            <div class="query-grid">
                <div class="field">
                    <label for="from">DESDE (Fecha Inicio) *</label>
                    <input class="control" id="from" name="from" type="datetime-local" value="{{ old('from', $values['from'] ?? $defaultFrom) }}" required>
                </div>

                <div class="field">
                    <label for="to">HASTA (Fecha Fin) *</label>
                    <input class="control" id="to" name="to" type="datetime-local" value="{{ old('to', $values['to'] ?? $defaultTo) }}" required>
                </div>

                <div class="field">
                    <label for="router_ip">Router / fuente *</label>
                    <input class="control" id="router_ip" name="router_ip" inputmode="decimal" placeholder="10.0.0.1" value="{{ old('router_ip', $values['router_ip'] ?? '') }}" required>
                </div>

                <div class="field">
                    <label for="private_ip">IP origen privada *</label>
                    <input class="control" id="private_ip" name="private_ip" inputmode="decimal" placeholder="10.172.154.77" value="{{ old('private_ip', $values['private_ip'] ?? '') }}" required>
                </div>

                <div class="field">
                    <label for="private_port">Puerto origen</label>
                    <input class="control" id="private_port" name="private_port" type="number" min="1" max="65535" placeholder="65535" value="{{ old('private_port', $values['private_port'] ?? '') }}">
                </div>

                <div class="field">
                    <label for="public_ip">IP NAT pública</label>
                    <input class="control" id="public_ip" name="public_ip" inputmode="decimal" placeholder="179.6.5.194" value="{{ old('public_ip', $values['public_ip'] ?? '') }}">
                </div>

                <div class="field">
                    <span class="field-label">Rango de puertos NAT</span>
                    <div class="control-pair">
                        <input class="control" aria-label="Puerto NAT inicial" name="public_port_from" type="number" min="1" max="65535" placeholder="Desde" value="{{ old('public_port_from', $values['public_port_from'] ?? '') }}">
                        <input class="control" aria-label="Puerto NAT final" name="public_port_to" type="number" min="1" max="65535" placeholder="Hasta" value="{{ old('public_port_to', $values['public_port_to'] ?? '') }}">
                    </div>
                </div>

                <div class="field">
                    <label for="destination_ip">IP destino</label>
                    <input class="control" id="destination_ip" name="destination_ip" inputmode="decimal" placeholder="190.113.220.54" value="{{ old('destination_ip', $values['destination_ip'] ?? '') }}">
                </div>

                <div class="field">
                    <label for="destination_port">Puerto destino</label>
                    <input class="control" id="destination_port" name="destination_port" type="number" min="1" max="65535" placeholder="65535" value="{{ old('destination_port', $values['destination_port'] ?? '') }}">
                </div>

                @if ($canSelectNodes)
                    <div class="field field--full">
                        <span class="field-label">Nodos ClickHouse</span>
                        <div class="node-selector">
                            @foreach ($nodes as $name => $node)
                                <label class="node-option">
                                    <input type="checkbox" name="nodes[]" value="{{ $name }}" @checked(in_array($name, $selectedNodes, true))>
                                    <span class="node-state {{ filled($node['url']) ? 'is-configured' : '' }}"></span>
                                    {{ $node['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="field field--full form-actions">
                    <button class="btn btn--secondary" type="button" data-clear-form>Limpiar</button>
                    <button class="btn btn--secondary" type="button" data-template-save>Guardar plantilla</button>
                    <button class="btn btn--primary" type="submit" data-query-submit>⌕ Ejecutar consulta</button>
                </div>
            </div>
        </form>
    </div>
</section>

<section class="card query-result-card" data-query-result-card>
    <div class="query-result-loading" data-query-loading hidden>
        <div class="query-result-loading__content">
            <span class="query-spinner" aria-hidden="true"></span>
            <span data-query-loading-text>Cargando resultados...</span>
        </div>
    </div>

    <div aria-live="polite" data-query-result>
        @include('queries.partials.result', ['result' => $result, 'runtimeError' => $runtimeError ?? null])
    </div>
</section>
@endsection
