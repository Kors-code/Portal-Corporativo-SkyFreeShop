<?php

namespace App\Services;

use App\Models\Candidato;
use App\Models\Vacante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class CandidateCvImportService
{
    public function importUploadedFile(UploadedFile $file, Vacante $vacante, array $metadata = []): Candidato
    {
        $path = $file->store('privado/cvs', 'private');
        $filePath = Storage::disk('private')->path($path);
        $text = $this->extractText($filePath);

        return $this->createCandidate($file, $vacante, $path, $text, $metadata, $metadata['routing_method'] ?? 'rule');
    }

    public function importUploadedFileWithAiRouting(UploadedFile $file, array $metadata = []): Candidato
    {
        $path = $file->store('privado/cvs', 'private');
        $filePath = Storage::disk('private')->path($path);
        $text = $this->extractText($filePath);
        $routing = $this->routeVacancyWithAi($text, $metadata);

        $routingMethod = str_contains(mb_strtolower($routing['reason']), 'requiere revision') ? 'review' : 'ai';

        return $this->createCandidate($file, $routing['vacante'], $path, $text, array_merge($metadata, [
            'routing_reason' => $routing['reason'],
        ]), $routingMethod);
    }

    public function reprocessImportedCandidate(Candidato $candidato): Candidato
    {
        $text = (string) $candidato->cv_text;
        if (trim($text) === '' && $candidato->cv) {
            $text = $this->extractText(Storage::disk('private')->path($candidato->cv));
            $candidato->cv_text = $text;
        }

        $routing = $this->routeVacancyWithAi($text, [
            'subject' => $candidato->source_email_subject,
            'email' => $candidato->source_email_from,
        ]);

        $summary = $this->summarizeForInbox($text, (string) $candidato->source_email_subject);
        $routingMethod = str_contains(mb_strtolower($routing['reason']), 'requiere revision') ? 'review' : 'ai';
        $evaluation = $routingMethod === 'review'
            ? $this->reviewEvaluation($routing['reason'], $summary)
            : $this->evaluate($text, $routing['vacante']);

        $candidato->vacante_id = $routing['vacante']->id;
        $candidato->estado = $evaluation['estado'] ?? 'pendiente';
        $candidato->puntaje = $evaluation['puntaje'] ?? 0;
        $candidato->razon_ia = ($evaluation['razon'] ?? 'Sin respuesta IA') . ' | Ruta: ' . $routing['reason'];
        $candidato->routing_method = $routingMethod;
        $candidato->cv_summary = $summary['summary_text'];
        $candidato->vacancy_suggestions = $summary['suggestions'];
        $candidato->save();

        return $candidato;
    }

    public function reevaluateCandidateForVacancy(Candidato $candidato, Vacante $vacante, string $note = ''): Candidato
    {
        $text = (string) $candidato->cv_text;
        if (trim($text) === '' && $candidato->cv) {
            $text = $this->extractText(Storage::disk('private')->path($candidato->cv));
            $candidato->cv_text = $text;
        }

        $evaluation = $this->evaluate($text, $vacante);
        $summary = $this->summarizeForInbox($text, (string) $candidato->source_email_subject);
        $reason = (string) ($evaluation['razon'] ?? 'Sin respuesta IA');

        if ($note !== '') {
            $reason .= ' | ' . $note;
        }

        $candidato->vacante_id = $vacante->id;
        $candidato->estado = $evaluation['estado'] ?? 'pendiente';
        $candidato->puntaje = $evaluation['puntaje'] ?? 0;
        $candidato->razon_ia = $reason;
        $candidato->routing_method = 'manual';
        $candidato->cv_summary = $summary['summary_text'];
        $candidato->vacancy_suggestions = $summary['suggestions'];
        $candidato->save();

        return $candidato;
    }

    private function createCandidate(
        UploadedFile $file,
        Vacante $vacante,
        string $path,
        string $text,
        array $metadata,
        string $routingMethod
    ): Candidato {
        $summary = $this->summarizeForInbox($text, (string) ($metadata['subject'] ?? ''));
        $routingReason = trim((string) ($metadata['routing_reason'] ?? ''));
        $evaluation = $routingMethod === 'review'
            ? $this->reviewEvaluation($routingReason, $summary)
            : $this->evaluate($text, $vacante);
        $evaluationReason = (string) ($evaluation['razon'] ?? 'Sin respuesta IA');
        $reason = $routingReason !== ''
            ? $evaluationReason . ' | Ruta: ' . $routingReason
            : $evaluationReason;

        return Candidato::create([
            'nombre' => $this->candidateName($metadata, $file),
            'email' => $this->candidateEmail($metadata),
            'celular' => $metadata['phone'] ?? '0000000000',
            'cv' => $path,
            'cv_text' => $text,
            'vacante_id' => $vacante->id,
            'estado' => $evaluation['estado'] ?? 'pendiente',
            'razon_ia' => $reason,
            'puntaje' => $evaluation['puntaje'] ?? 0,
            'autorizacion' => $metadata['autorizacion'] ?? true,
            'source_channel' => $metadata['source_channel'] ?? null,
            'source_email_message_id' => $metadata['message_id'] ?? null,
            'source_email_from' => $metadata['email'] ?? null,
            'source_email_subject' => $metadata['subject'] ?? null,
            'source_email_received_at' => $metadata['received_at'] ?? null,
            'routing_method' => $routingMethod,
            'cv_summary' => $summary['summary_text'],
            'vacancy_suggestions' => $summary['suggestions'],
        ]);
    }

    public function extractText(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        try {
            if ($extension === 'pdf') {
                $pdf = (new Parser())->parseFile($filePath);
                $text = trim($pdf->getText());

                return $text !== '' ? $text : '[PDF sin texto legible]';
            }

            if (in_array($extension, ['doc', 'docx'], true)) {
                return trim($this->extractWordText($filePath)) ?: '[Word sin texto legible]';
            }
        } catch (\Throwable $error) {
            Log::warning('No se pudo extraer texto de hoja de vida.', [
                'file' => $filePath,
                'error' => $error->getMessage(),
            ]);

            return '[No se pudo leer el contenido del archivo]';
        }

        return '[Formato de archivo no soportado]';
    }

    private function extractWordText(string $filePath): string
    {
        $phpWord = IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractElementText($element) . "\n";
            }
        }

        return $text;
    }

    private function extractElementText(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractElementText($child);
            }

            return $text;
        }

        if ($element instanceof Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $text .= $this->extractElementText($cellElement) . ' ';
                    }
                    $text .= "\n";
                }
            }

            return $text;
        }

        if (method_exists($element, 'getElements')) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractElementText($child);
            }

            return $text;
        }

        return '';
    }

    private function candidateName(array $metadata, UploadedFile $file): string
    {
        $name = trim((string) ($metadata['name'] ?? ''));

        if ($name !== '') {
            return mb_substr($name, 0, 100);
        }

        return mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 100);
    }

    private function candidateEmail(array $metadata): string
    {
        $email = trim((string) ($metadata['email'] ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'pendiente@correo.com';
    }

    private function evaluate(string $text, Vacante $vacante): array
    {
        try {
            return (new OpenAiService())->analizarCV(
                $text,
                $vacante->slug,
                $vacante->requisito_ia,
                $vacante->criterios
            );
        } catch (\Throwable $error) {
            Log::error('Error al evaluar hoja de vida importada desde Gmail.', [
                'vacante_id' => $vacante->id,
                'error' => $error->getMessage(),
            ]);

            return [
                'estado' => 'pendiente',
                'razon' => 'Error al procesar IA: ' . $error->getMessage(),
                'puntaje' => 0,
            ];
        }
    }

    private function summarizeForInbox(string $text, string $subject): array
    {
        $vacantes = Vacante::query()
            ->select('id', 'titulo', 'slug', 'localidad', 'requisito_ia')
            ->orderByDesc('id')
            ->get();

        $summary = (new OpenAiService())->resumirCVParaBandeja(
            $text,
            $subject,
            $vacantes->map->toArray()->all()
        );

        $summaryText = $summary['resumen'] ?? 'Sin resumen.';
        $fortalezas = array_filter((array) ($summary['fortalezas'] ?? []));
        $alertas = array_filter((array) ($summary['alertas'] ?? []));

        if ($fortalezas !== []) {
            $summaryText .= "\n\nFortalezas: " . implode('; ', $fortalezas);
        }

        if ($alertas !== []) {
            $summaryText .= "\n\nAlertas: " . implode('; ', $alertas);
        }

        return [
            'summary_text' => $summaryText,
            'suggestions' => $this->sanitizeVacancySuggestions((array) ($summary['sugerencias'] ?? []), $vacantes),
        ];
    }

    private function reviewEvaluation(string $routingReason, array $summary): array
    {
        $reason = trim($routingReason) !== ''
            ? $routingReason
            : 'Requiere revision manual: no hay una vacante suficientemente clara para evaluar.';

        return [
            'estado' => 'pendiente',
            'puntaje' => $this->bestSuggestionScore($summary['suggestions'] ?? []),
            'razon' => $reason . ' No se rechazo automaticamente porque la vacante exacta debe confirmarse en bandeja.',
        ];
    }

    private function bestSuggestionScore(array $suggestions): int
    {
        $scores = array_map(fn ($suggestion) => (int) ($suggestion['puntaje'] ?? 0), $suggestions);

        return max([0, ...array_map(fn ($score) => min(100, max(0, $score)), $scores)]);
    }

    private function sanitizeVacancySuggestions(array $suggestions, $vacantes): array
    {
        $bySlug = $vacantes->keyBy('slug');
        $byTitle = $vacantes->keyBy(fn (Vacante $vacante) => $this->normalizeText((string) $vacante->titulo));
        $clean = [];

        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }

            $slug = (string) ($suggestion['slug'] ?? '');
            $title = (string) ($suggestion['titulo'] ?? '');
            $vacante = $slug !== '' ? $bySlug->get($slug) : null;
            $vacante ??= $title !== '' ? $byTitle->get($this->normalizeText($title)) : null;

            if (!$vacante) {
                continue;
            }

            $clean[$vacante->slug] = [
                'slug' => $vacante->slug,
                'titulo' => $vacante->titulo,
                'puntaje' => min(100, max(0, (int) ($suggestion['puntaje'] ?? 0))),
                'razon' => mb_substr((string) ($suggestion['razon'] ?? ''), 0, 500),
            ];
        }

        return array_values($clean);
    }

    private function routeVacancyWithAi(string $text, array $metadata): array
    {
        $vacantes = Vacante::query()
            ->select('id', 'titulo', 'slug', 'localidad', 'descripcion', 'requisito_ia', 'criterios')
            ->orderByDesc('id')
            ->get();

        if ($vacantes->isEmpty()) {
            throw new \RuntimeException('No hay vacantes disponibles para clasificar la hoja de vida.');
        }

        $locationFilterReason = null;
        $routableVacantes = $this->filterVacanciesByLocationSignal(
            $vacantes,
            (string) ($metadata['subject'] ?? ''),
            $text,
            $locationFilterReason
        );

        $review = $this->detectMissingAirportAdvisorVacancy($routableVacantes, (string) ($metadata['subject'] ?? ''), $text);
        if ($review) {
            $reason = 'Requiere revision manual: ' . $review['reason'];
            if ($locationFilterReason) {
                $reason .= ' Filtro previo: ' . $locationFilterReason;
            }

            return [
                'vacante' => $review['vacante'],
                'reason' => $reason,
            ];
        }

        $deterministic = $this->routeVacancyByRules($routableVacantes, (string) ($metadata['subject'] ?? ''), $text);
        if ($deterministic) {
            $reason = 'Asignado por reglas a "' . $deterministic['vacante']->titulo . '" con puntaje ' . $deterministic['score'] . '. ' . $deterministic['reason'];
            if ($locationFilterReason) {
                $reason .= ' Filtro previo: ' . $locationFilterReason;
            }

            return [
                'vacante' => $deterministic['vacante'],
                'reason' => $reason,
            ];
        }

        $classification = (new OpenAiService())->elegirVacanteParaCV(
            $text,
            (string) ($metadata['subject'] ?? ''),
            (string) ($metadata['email'] ?? ''),
            $routableVacantes->map->toArray()->all()
        );

        $slug = $classification['slug'] ?? null;
        $vacante = $slug ? $routableVacantes->firstWhere('slug', $slug) : null;

        if (!$vacante) {
            $vacante = $routableVacantes->first();
            $reason = 'Requiere revision manual: IA no encontro una vacante clara. Se asigno temporalmente a "' . $vacante->titulo . '". Razon IA: ' . ($classification['razon'] ?? 'sin razon');
        } else {
            $reason = 'Asignado por IA a "' . $vacante->titulo . '" con confianza ' . ($classification['confianza'] ?? 0) . '%. Razon: ' . ($classification['razon'] ?? 'sin razon');
        }

        if ($locationFilterReason) {
            $reason .= ' Filtro previo: ' . $locationFilterReason;
        }

        return [
            'vacante' => $vacante,
            'reason' => $reason,
        ];
    }

    private function detectMissingAirportAdvisorVacancy($vacantes, string $subject, string $text): ?array
    {
        $haystack = $this->normalizeText($subject . "\n" . mb_substr($text, 0, 5000));

        if (!$this->isAirportSalesApplicant($haystack)) {
            return null;
        }

        $hasExactAirportAdvisorVacancy = $vacantes->contains(function (Vacante $vacante) {
            $title = $this->normalizeText((string) $vacante->titulo);
            $location = $this->normalizeText((string) $vacante->localidad);

            return str_contains($location, 'jose maria cordoba')
                && (
                    str_contains($title, 'asesor')
                    || str_contains($title, 'asesora')
                    || str_contains($title, 'cajero')
                    || str_contains($title, 'cajera')
                    || str_contains($title, 'vendedor')
                    || str_contains($title, 'vendedora')
                );
        });

        if ($hasExactAirportAdvisorVacancy) {
            return null;
        }

        $fallback = $vacantes->first(function (Vacante $vacante) {
            return str_contains($this->normalizeText((string) $vacante->localidad), 'jose maria cordoba');
        }) ?: $vacantes->first();

        return [
            'vacante' => $fallback,
            'reason' => 'El CV parece de asesor/cajero/ventas para el apartado Jose Maria Cordoba, pero no existe una vacante exacta de asesor/cajero/vendedor para esa sede.',
        ];
    }

    private function routeVacancyByRules($vacantes, string $subject, string $text): ?array
    {
        $haystack = $this->normalizeText($subject . "\n" . mb_substr($text, 0, 5000));
        $scores = [];

        foreach ($vacantes as $vacante) {
            $profile = $this->vacancyKeywordProfile($vacante);
            $score = 0;
            $hits = [];
            $strongHits = 0;

            foreach ($profile['strong'] as $keyword) {
                if ($this->containsPhrase($haystack, $keyword)) {
                    $score += 35;
                    $strongHits++;
                    $hits[] = $keyword;
                }
            }

            if (($profile['requires_strong'] ?? false) && $strongHits === 0) {
                $scores[] = [
                    'vacante' => $vacante,
                    'score' => -100,
                    'hits' => [],
                ];
                continue;
            }

            foreach ($profile['normal'] as $keyword) {
                if ($this->containsPhrase($haystack, $keyword)) {
                    $score += 12;
                    $hits[] = $keyword;
                }
            }

            foreach ($profile['negative'] as $keyword) {
                if ($this->containsPhrase($haystack, $keyword)) {
                    $score -= 35;
                }
            }

            $scores[] = [
                'vacante' => $vacante,
                'score' => $score,
                'hits' => array_values(array_unique($hits)),
            ];
        }

        usort($scores, fn ($left, $right) => $right['score'] <=> $left['score']);

        $best = $scores[0] ?? null;
        $second = $scores[1] ?? null;

        if (!$best || $best['score'] < 35) {
            return null;
        }

        if ($second && ($best['score'] - $second['score']) < 18) {
            return null;
        }

        return [
            'vacante' => $best['vacante'],
            'score' => $best['score'],
            'reason' => 'Coincidencias: ' . implode(', ', $best['hits']),
        ];
    }

    private function vacancyKeywordProfile(Vacante $vacante): array
    {
        $slug = (string) $vacante->slug;

        $profiles = [
            'auxiliar-de-visual-merchandising' => [
                'strong' => ['visual merchandising', 'merchandising', 'vitrinismo', 'exhibicion', 'exhibiciones'],
                'normal' => ['visual', 'mercadeo', 'tienda', 'retail', 'diseno', 'decoracion'],
                'negative' => ['seguridad', 'contable', 'contador', 'mantenimiento'],
            ],
            'auxiliar-de-seguridad' => [
                'strong' => ['seguridad', 'guarda', 'vigilante', 'vigilancia'],
                'normal' => ['control de acceso', 'riesgos', 'prevencion', 'aeropuerto'],
                'negative' => ['contable', 'merchandising', 'mantenimiento'],
            ],
            'auxiliar-contable' => [
                'strong' => ['contable', 'contador', 'contabilidad', 'auxiliar contable'],
                'normal' => ['finanzas', 'conciliacion', 'facturacion', 'nomina', 'siigo', 'excel'],
                'negative' => ['seguridad', 'mantenimiento', 'merchandising'],
            ],
            'asesora-cartagena' => [
                'strong' => ['cartagena', 'manga', 'puerto de manga'],
                'normal' => ['asesor', 'asesora', 'vendedor', 'ventas', 'comercial', 'servicio al cliente', 'cajero', 'caja'],
                'negative' => ['rionegro', 'medellin', 'jose maria cordoba', 'antioquia'],
                'requires_strong' => true,
            ],
            'auxiliar-de-mantenimiento' => [
                'strong' => ['mantenimiento', 'tecnico mantenimiento', 'auxiliar mantenimiento'],
                'normal' => ['electricidad', 'locativo', 'reparacion', 'herramientas', 'infraestructura'],
                'negative' => ['contable', 'seguridad', 'merchandising'],
            ],
            'gerente-de-ventas' => [
                'strong' => ['gerente de ventas', 'gerente comercial', 'administrador de tienda'],
                'normal' => ['gerente', 'administrador', 'ventas', 'comercial', 'presupuesto', 'equipo'],
                'negative' => ['auxiliar', 'mantenimiento', 'seguridad', 'contable'],
            ],
            'lider-de-ventas' => [
                'strong' => ['lider de ventas', 'lider comercial', 'supervisor de ventas', 'coordinador de ventas'],
                'normal' => ['lider', 'supervisor', 'coordinador', 'ventas', 'comercial', 'equipo'],
                'negative' => ['mantenimiento', 'seguridad', 'contable'],
            ],
        ];

        return $profiles[$slug] ?? [
            'strong' => [$this->normalizeText((string) $vacante->titulo)],
            'normal' => [],
            'negative' => [],
        ];
    }

    private function isAirportSalesApplicant(string $haystack): bool
    {
        return $this->containsAny($haystack, $this->airportLocationSignals()) && $this->containsAny($haystack, [
            'asesor',
            'asesora',
            'cajero',
            'cajera',
            'ventas',
            'vendedor',
            'vendedora',
            'servicio al cliente',
            'comercial',
            'part time',
            'full time',
        ]);
    }

    private function filterVacanciesByLocationSignal($vacantes, string $subject, string $text, ?string &$reason)
    {
        $haystack = $this->normalizeText($subject . "\n" . mb_substr($text, 0, 8000));

        $airportSignals = $this->airportLocationSignals();

        $cartagenaSignals = [
            'cartagena',
            'manga',
            'puerto de manga',
        ];

        if ($this->containsAny($haystack, $airportSignals) && !$this->containsAny($haystack, $cartagenaSignals)) {
            $filtered = $vacantes->filter(function (Vacante $vacante) {
                $location = $this->normalizeText((string) $vacante->localidad);

                return str_contains($location, 'jose maria cordoba')
                    || str_contains($location, 'aeropuerto internacional jose maria cordoba');
            })->values();

            if ($filtered->isNotEmpty()) {
                $reason = 'Detectada ubicacion del apartado Jose Maria Cordoba/Oriente antioqueno; se excluyeron vacantes de Cartagena.';

                return $filtered;
            }
        }

        if ($this->containsAny($haystack, $cartagenaSignals)) {
            $filtered = $vacantes->filter(function (Vacante $vacante) {
                $location = $this->normalizeText((string) $vacante->localidad . ' ' . (string) $vacante->titulo);

                return str_contains($location, 'cartagena')
                    || str_contains($location, 'manga')
                    || str_contains($location, 'puerto de manga');
            })->values();

            if ($filtered->isNotEmpty()) {
                $reason = 'Detectada ubicacion Cartagena/Manga; se filtraron vacantes a esa sede.';

                return $filtered;
            }
        }

        return $vacantes;
    }

    private function airportLocationSignals(): array
    {
        return [
            'rionegro',
            'medellin',
            'medellín',
            'antioquia',
            'oriente antioqueno',
            'oriente antioqueño',
            'marinilla',
            'la ceja',
            'ceja',
            'guarne',
            'el carmen de viboral',
            'carmen de viboral',
            'el retiro',
            'retiro',
            'el santuario',
            'santuario',
            'san vicente ferrer',
            'san vicente',
            'la union',
            'la unión',
            'el penol',
            'el peñol',
            'guatape',
            'guatapé',
            'llanogrande',
            'llano grande',
            'jose maria cordoba',
            'josé maría córdoba',
            'aeropuerto jose maria cordoba',
            'aeropuerto josé maría córdoba',
            'aeropuerto internacional jose maria cordoba',
            'aeropuerto internacional josé maría córdoba',
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $this->normalizeText($needle));
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'];
        $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n'];

        return str_replace($from, $to, $value);
    }
}
