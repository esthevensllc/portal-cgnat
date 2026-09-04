@extends('layouts.portal')
@section('title', 'Plantillas | Portal CGNAT')
@section('page-title', 'Plantillas')
@section('content')
<div class="page-heading"><div><h1>Plantillas</h1><p>Estas plantillas son privadas para tu usuario y abren la consulta con los filtros guardados.</p></div></div>
<section class="card"><div class="card__header"><h2>Mis plantillas</h2></div><div class="card__body">@if(count($templates))<div class="template-list">@foreach($templates as $template)@php($filters = json_decode($template['filters_json'], true) ?: [])<a class="template-item" href="{{ route('queries.index', $filters) }}"><strong>{{ $template['name'] }}</strong><span>Creada: {{ $template['created_at'] }}</span><small>{{ $filters['from'] ?? '—' }} a {{ $filters['to'] ?? '—' }}</small></a>@endforeach</div>@else<div class="empty-state"><div><div class="empty-state__icon">◇</div><strong>Aún no tienes plantillas</strong><span>Guarda los filtros desde Consulta sesiones.</span></div></div>@endif</div></section>
@endsection
