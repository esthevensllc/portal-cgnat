@extends('layouts.portal')
@section('title', 'Auditoría | Portal CGNAT')
@section('page-title', 'Auditoría')
@section('content')
<div class="page-heading"><div><h1>Auditoría del portal</h1><p>Eventos de autenticación, consultas, exportaciones y plantillas. Se muestran como máximo 500 eventos.</p></div></div>
<section class="card">
    <div class="card__header"><h2>Filtros</h2></div>
    <div class="card__body">
        <form class="audit-filters" method="GET" action="{{ route('audit.index') }}">
            <label>Desde<input class="control" type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label>Hasta<input class="control" type="date" name="to" value="{{ $filters['to'] }}"></label>
            <label>Usuario<input class="control" name="username" value="{{ $filters['username'] ?? '' }}" maxlength="100"></label>
            <label>Transacción<input class="control" name="event_type" value="{{ $filters['event_type'] ?? '' }}" placeholder="auth.login"></label>
            <label>EstadoEvento<select class="control" name="outcome"><option value="">Todos</option>@foreach(['Correcto', 'Fallido', 'Pendiente'] as $value)<option value="{{ $value }}" @selected(($filters['outcome'] ?? '') === $value)>{{ $value }}</option>@endforeach</select></label>
            <label>Source IP<input class="control" name="source_ip" value="{{ $filters['source_ip'] ?? '' }}"></label>
            <label>ID de solicitud<input class="control" name="request_id" value="{{ $filters['request_id'] ?? '' }}"></label>
            <button class="btn btn--primary" type="submit">Buscar</button>
        </form>
    </div>
</section>
<section class="card">
    <div class="card__header"><h2>Eventos</h2><span class="pill pill--neutral">{{ count($events) }} registros</span></div>
    <div class="card__body audit-results">
        @if(count($events))
            <div class="table-wrap"><table class="results-table audit-table"><thead><tr><th>IdEvento</th><th>Usuario</th><th>DescripcionUsuario</th><th>Source IP</th><th>Source Hostname</th><th>Destination IP</th><th>Detination Hostname</th><th>UsuarioSO</th><th>Fecha y Hora</th><th>Transacción</th><th>Variables</th><th>EstadoEvento</th></tr></thead><tbody>
            @foreach($events as $event)<tr>
                <td><code>{{ $event['event_id'] }}</code></td>
                <td>{{ $event['username'] ?: '—' }}</td>
                <td>{{ $event['user_description'] ?: '—' }}</td>
                <td>{{ $event['source_ip'] ?: '—' }}</td>
                <td>{{ $event['source_hostname'] ?: '—' }}</td>
                <td>{{ $event['destination_ip'] ?: '—' }}</td>
                <td>{{ $event['destination_hostname'] ?: '—' }}</td>
                <td>{{ $event['os_username'] ?: '—' }}</td>
                <td>{{ $event['occurred_at'] }} UTC</td>
                <td>{{ $event['event_type'] }}</td>
                <td><details><summary>Ver</summary><pre>{{ json_encode(json_decode($event['details_json'] ?: '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details></td>
                <td><span class="pill {{ $event['event_status'] === 'Correcto' ? 'pill--success' : ($event['event_status'] === 'Fallido' ? 'pill--danger' : 'pill--neutral') }}">{{ $event['event_status'] }}</span></td>
            </tr>@endforeach
            </tbody></table></div>
        @else<div class="empty-state"><div><strong>No se encontraron eventos</strong><span>Ajusta los filtros y vuelve a intentar.</span></div></div>@endif
    </div>
</section>
@endsection
