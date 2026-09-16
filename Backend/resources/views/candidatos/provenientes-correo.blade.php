@extends('layouts.app')

@section('content')

<link rel="stylesheet" href="{{ asset('css/candidatos-correo.css') }}">

<main class="mail-candidates">
    <div class="mail-header">
        <a href="{{ route('panel.candidatos') }}" class="back-link">Volver</a>
        <div>
            <h1>Hojas de vida desde Gmail</h1>
            <p>Revisa procedencia, vacante asignada, estado de IA y casos que necesitan ajuste manual.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="notice notice-success">{{ session('success') }}</div>
    @endif

    <form class="filter-panel" method="GET" action="{{ route('candidatos.provenientes-correo') }}">
        <label>
            Bandeja
            <select name="estado_bandeja">
                <option value="pendientes" @selected(request('estado_bandeja', 'pendientes') === 'pendientes')>Pendientes</option>
                <option value="vistos" @selected(request('estado_bandeja') === 'vistos')>Vistos</option>
                <option value="todos" @selected(request('estado_bandeja') === 'todos')>Todos</option>
            </select>
        </label>
        <label>
            Remitente
            <input name="from" value="{{ request('from') }}" placeholder="correo o dominio" />
        </label>
        <label>
            Asunto
            <input name="subject" value="{{ request('subject') }}" placeholder="palabra del asunto" />
        </label>
        <label>
            Vacante
            <select name="vacante">
                <option value="">Todas</option>
                @foreach ($vacantes as $vacante)
                    <option value="{{ $vacante->slug }}" @selected(request('vacante') === $vacante->slug)>
                        {{ $vacante->titulo }}
                    </option>
                @endforeach
            </select>
        </label>
        <button type="submit">Filtrar</button>
    </form>

    <section class="candidate-list">
        @forelse ($candidatos as $candidato)
            @php
                $stateClass = $candidato->estado === 'aprobado'
                    ? 'is-approved'
                    : ($candidato->estado === 'rechazado' ? 'is-rejected' : 'is-pending');
                $routingLabel = match ($candidato->routing_method) {
                    'manual' => 'Manual',
                    'review' => 'Revision',
                    'rule' => 'Regla',
                    'ai' => 'IA',
                    default => 'Pendiente',
                };
                $needsReview = str_contains(mb_strtolower((string) $candidato->razon_ia), 'requiere revision');
            @endphp
            <article class="candidate-row {{ $stateClass }} {{ $needsReview ? 'needs-review' : '' }}">
                <div class="candidate-main">
                    <div class="candidate-title">
                        <h2>{{ $candidato->nombre }}</h2>
                        <span class="status-pill">{{ $candidato->estado ?: 'pendiente' }}</span>
                        @if ($needsReview)
                            <span class="review-pill">Revisar asignacion</span>
                        @endif
                    </div>
                    <div class="meta-grid">
                        <span><strong>Email</strong>{{ $candidato->email }}</span>
                        <span><strong>Remitente</strong>{{ $candidato->source_email_from ?: 'Sin remitente' }}</span>
                        <span><strong>Asunto</strong>{{ $candidato->source_email_subject ?: 'Sin asunto' }}</span>
                        <span><strong>Fecha</strong>{{ optional($candidato->created_at)->format('Y-m-d H:i') }}</span>
                    </div>
                    <p class="reason">{{ $candidato->razon_ia ?: 'Sin razon IA registrada.' }}</p>
                    @if ($candidato->cv_summary)
                        <div class="cv-summary">
                            <strong>Resumen hoja de vida</strong>
                            <p>{{ $candidato->cv_summary }}</p>
                        </div>
                    @endif

                    @if (is_array($candidato->vacancy_suggestions) && count($candidato->vacancy_suggestions))
                        <div class="suggestions">
                            <strong>Puede servir para</strong>
                            <div class="suggestion-list">
                                @foreach ($candidato->vacancy_suggestions as $suggestion)
                                    <span>
                                        {{ $suggestion['titulo'] ?? $suggestion['slug'] ?? 'Vacante' }}
                                        <b>{{ $suggestion['puntaje'] ?? 0 }}</b>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <aside class="candidate-side">
                    <div class="assignment">
                        <span class="route-pill">{{ $routingLabel }}</span>
                        <strong>{{ $needsReview ? 'Sin vacante definitiva' : (optional($candidato->vacante)->titulo ?: 'Sin vacante') }}</strong>
                        <small>{{ $needsReview ? 'Selecciona una vacante y reevalua' : (optional($candidato->vacante)->localidad ?: 'Sin localidad') }}</small>
                    </div>

                    <form method="POST" action="{{ route('candidatos.provenientes-correo.reasignar', $candidato) }}" class="assign-form">
                        @csrf
                        <select name="vacante_slug" aria-label="Reasignar vacante">
                            @foreach ($vacantes as $vacante)
                                <option value="{{ $vacante->slug }}" @selected(optional($candidato->vacante)->slug === $vacante->slug)>
                                    {{ $vacante->titulo }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit">Reasignar y reevaluar IA</button>
                    </form>

                    @if ($candidato->cv)
                        <a class="download-link" href="{{ route('candidatos.cv', $candidato->id) }}">Descargar CV</a>
                    @endif

                    @if ($candidato->gmail_seen_at)
                        <form method="POST" action="{{ route('candidatos.provenientes-correo.pendiente', $candidato) }}" class="plain-form">
                            @csrf
                            <button type="submit" class="secondary-action">Devolver a pendientes</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('candidatos.provenientes-correo.visto', $candidato) }}" class="plain-form">
                            @csrf
                            <button type="submit" class="secondary-action">Marcar como vista</button>
                        </form>
                    @endif
                </aside>
            </article>
        @empty
            <div class="empty-state">No hay hojas de vida importadas desde Gmail.</div>
        @endforelse
    </section>

    <div class="pagination-wrap">
        {{ $candidatos->links() }}
    </div>
</main>

@endsection
