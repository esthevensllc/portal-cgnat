<div class="card__header">
    <h2 id="result-title">Resultado de la consulta</h2>
    @if ($result)
        <div class="result-meta">
            <span>{{ number_format($result['total_rows'] ?? 0) }} registros</span>
            <span>·</span>
            <span>{{ number_format($result['elapsed_ms']) }} ms</span>
            @foreach ($result['nodes'] as $name => $status)
                <span class="pill {{ $status['ok'] ? 'pill--success' : 'pill--danger' }}" title="{{ $status['message'] ?? '' }}">
                    {{ strtoupper($name) }} {{ $status['ok'] ? 'OK' : 'ERROR' }}
                </span>
            @endforeach
        </div>
    @else
        <span class="pill pill--neutral">Sin ejecución</span>
    @endif
</div>

@if (! empty($runtimeError))
    <div class="result-message result-message--warning">{{ $runtimeError }}</div>
@endif

@if ($result && ($result['large_result'] ?? false))
    <div class="result-message result-message--warning">
        <strong>
            La consulta encontró {{ number_format($result['total_rows']) }} registros.
        </strong>

        <span>
            Por superar {{ number_format($result['export_threshold'] ?? 100000) }} registros no se mostrará la tabla.
            Genera el CSV y descárgalo luego desde Tareas y exportaciones.
        </span>

        <button class="btn btn--primary" type="button" data-export-button>
            Generar CSV
        </button>
    </div>
@elseif ($result && count($result['rows']))
    @php
        $pageSize = (int) ($result['page_size'] ?? 20);
        $rowCount = count($result['rows']);
        $totalRows = (int) ($result['total_rows'] ?? 0);
        $fromRow = $rowCount > 0 ? 1 : 0;
        $toRow = $rowCount;
        $totalPages = max(1, (int) ceil($totalRows / max(1, $pageSize)));
        $visiblePages = $totalPages <= 7 ? range(1, $totalPages) : [1, 2, 3, 4, 5, 'ellipsis', $totalPages];
    @endphp

    <div class="result-actions">
        <button class="btn btn--secondary" type="button" data-export-button>⇩ Generar CSV</button>
        <span>El archivo se preparará en segundo plano.</span>
    </div>

    <div class="table-wrap">
        <table class="results-table">
            <thead>
                <tr>
                    <th>Nodo</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                    <th>Router</th>
                    <th>IP privada</th>
                    <th>Puerto</th>
                    <th>IP NAT pública</th>
                    <th>Puerto NAT</th>
                    <th>IP destino</th>
                    <th>Puerto destino</th>
                    <th>Paquete</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result['rows'] as $row)
                    <tr>
                        <td>{{ $row['source_node'] }}</td>
                        <td>{{ $row['start_time'] }}</td>
                        <td>{{ $row['end_time'] }}</td>
                        <td>{{ $row['router_ip'] }}:{{ $row['router_port'] }}</td>
                        <td>{{ $row['private_ip'] }}</td>
                        <td>{{ $row['private_port'] }}</td>
                        <td>{{ $row['public_ip'] }}</td>
                        <td>{{ $row['public_port'] }}</td>
                        <td>{{ $row['destination_ip'] }}</td>
                        <td>{{ $row['destination_port'] }}</td>
                        <td>{{ $row['packet_size'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div
        class="server-pagination"
        data-server-pagination
        data-page-size="{{ $pageSize }}"
        data-row-count="{{ $rowCount }}"
        data-total-rows="{{ $totalRows }}"
        data-has-more="{{ ($result['has_more'] ?? false) ? '1' : '0' }}"
        data-next-cursor="{{ $result['next_cursor'] ?? '' }}"
    >
        <div class="server-pagination__summary" data-pagination-summary>
            Mostrando registros {{ number_format($fromRow) }} a {{ number_format($toRow) }} de un total de {{ number_format($totalRows) }} registros
        </div>

        <nav class="server-pagination__nav" aria-label="Paginación de resultados">
            <button class="server-page-control" type="button" data-query-prev disabled>Anterior</button>

            <div class="server-pagination__pages" data-query-pages>
                @foreach ($visiblePages as $page)
                    @if ($page === 'ellipsis')
                        <span class="server-page-ellipsis">…</span>
                    @elseif ((int) $page === 1)
                        <button class="server-page-number is-active" type="button" aria-current="page">1</button>
                    @else
                        <button class="server-page-number" type="button" disabled>{{ $page }}</button>
                    @endif
                @endforeach
            </div>

            <button
                class="server-page-control"
                type="button"
                data-query-next
                data-cursor="{{ $result['next_cursor'] ?? '' }}"
                @disabled(! ($result['has_more'] ?? false))
            >Siguiente</button>
        </nav>
    </div>
@elseif ($result)
    <div class="empty-state">
        <div>
            <div class="empty-state__icon">⌕</div>
            <strong>La consulta no devolvió registros</strong>
            <span>Revisa el rango start_time, router e IP origen.</span>
        </div>
    </div>
@else
    <div class="empty-state">
        <div>
            <div class="empty-state__icon">⌕</div>
            <strong>Completa los filtros para comenzar</strong>
            <span><b>Fecha inicio</b>, <b>fecha fin</b>, <b>router</b> e <b>IP origen</b> son obligatorios.</span>
        </div>
    </div>
@endif
