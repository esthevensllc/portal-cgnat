@extends('layouts.portal')
@section('title', 'Auditoría | Portal CGNAT')
@section('page-title', 'Auditoría')
@section('content')
<div class="page-heading"><div><h1>Auditoría del portal</h1><p>Eventos de autenticación LDAP, permisos, exportaciones y plantillas. Se muestran como máximo 500 eventos.</p></div></div>
<section class="card">
    <div class="card__header"><h2>Filtros</h2></div>
    <div class="card__body">
        <form class="audit-filters" method="GET" action="{{ route('audit.index') }}">
            <label>Desde<input class="control" type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label>Hasta<input class="control" type="date" name="to" value="{{ $filters['to'] }}"></label>
            <label>Usuario<input class="control" name="username" value="{{ $filters['username'] ?? '' }}" maxlength="100"></label>
            <label>Evento<input class="control" name="event_type" value="{{ $filters['event_type'] ?? '' }}" placeholder="auth.login"></label>
            <label>Resultado<select class="control" name="outcome"><option value="">Todos</option>@foreach(['success' => 'Correcto', 'failure' => 'Error', 'denied' => 'Denegado', 'rate_limited' => 'Bloqueado'] as $value => $label)<option value="{{ $value }}" @selected(($filters['outcome'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>IP origen<input class="control" name="source_ip" value="{{ $filters['source_ip'] ?? '' }}"></label>
            <label>ID de solicitud<input class="control" name="request_id" value="{{ $filters['request_id'] ?? '' }}"></label>
            <button class="btn btn--primary" type="submit">Buscar</button>
        </form>
    </div>
</section>
<section class="card">
    <div class="card__header"><h2>Eventos</h2><span class="pill pill--neutral">{{ count($events) }} registros</span></div>
    <div class="card__body audit-results">
        @if(count($events))
            <div class="table-wrap"><table class="results-table audit-table"><thead><tr><th>Fecha UTC</th><th>Usuario</th><th>Evento</th><th>Resultado</th><th>IP</th><th>Recurso</th><th>Filas</th><th>Solicitud</th><th>Detalle</th></tr></thead><tbody>
            @foreach($events as $event)<tr>
                <td>{{ $event['occurred_at'] }}</td><td>{{ $event['username'] ?: '—' }}</td><td>{{ $event['event_type'] }}</td>
                <td><span class="pill {{ $event['outcome'] === 'success' ? 'pill--success' : ($event['outcome'] === 'failure' ? 'pill--danger' : 'pill--neutral') }}">{{ $event['outcome'] }}</span></td>
                <td>{{ $event['source_ip'] ?: '—' }}</td><td>{{ $event['resource_type'] ?: '—' }}{{ $event['resource_id'] ? ': '.$event['resource_id'] : '' }}</td>
                <td>{{ number_format((int) $event['total_rows']) }}</td><td><code>{{ $event['request_id'] }}</code></td>
                <td><details><summary>Ver</summary><pre>{{ json_encode(json_decode($event['details_json'] ?: '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details></td>
            </tr>@endforeach
            </tbody></table></div>
        @else<div class="empty-state"><div><strong>No se encontraron eventos</strong><span>Ajusta los filtros y vuelve a intentar.</span></div></div>@endif
    </div>
</section>
@endsection
