<?php

namespace App\Services;

use OpenAI;
use DateTime;
use Exception;

class OpenAIService
{
    protected $client;

    protected $relevantRoleKeywords = [
        'comercial', 'venta', 'vendedor', 'sales', 'gestor', 'gestora', 'account', 'business development', 'comercialización'
    ];

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function analizarCV($texto, $nombreVacante , $requisito_ia , $criterios)
    {
        // Normalizar criterios
        if (is_string($criterios)) {
            $criterios = json_decode($criterios, true) ?: [];
        }
        if (!is_array($criterios)) {
            $criterios = [];
        }
        $criteriosTexto = '';
        foreach ($criterios as $criterio => $peso) {
            $criteriosTexto .= ucfirst($criterio) . ": " . $peso . "%\n";
        }

        // EXTRAER posiciones detectadas (pre-parse determinista)
        $positions = $this->extractPositionsFromText($texto);

        // Intentar extraer requisito mínimo de experiencia (ej: "2 años") desde $requisito_ia
       // Intentar extraer requisito mínimo de experiencia (ej: "2 años") desde $requisito_ia
$requiredMonths = null;

if (!empty($requisito_ia) && is_string($requisito_ia)) {
    $requiredMonths = $this->extractRequiredMonthsFromRequisitos($requisito_ia);
}

// Si no hay indicación, usar 24 meses por defecto (ajusta si quieres)
if ($requiredMonths === null) {
    $requiredMonths = 24;
}


        // Construir prompt: incluimos la lista de posiciones detectadas (autoridad) para que el modelo las tenga en cuenta
        $detectedText = "";
        if (!empty($positions)) {
            $detectedText .= "Posiciones detectadas por el sistema (autoridad):\n";
            foreach ($positions as $p) {
                $detectedText .= "- {$p['title']} | {$p['start_raw']} - {$p['end_raw']} ({$p['months']} meses)\n";
            }
            $detectedText .= "\n";
        }

        // Mensaje del sistema: reglas estrictas (obliga a salida JSON)
        $system = [
            'role' => 'system',
            'content' => "Eres un reclutador experto. RESPONDE SIEMPRE SÓLO y ÚNICAMENTE con un objeto JSON válido usando exactamente las claves: \"estado\" (aprobado|rechazado), \"razon\" (texto claro), \"puntaje\" (numero 1-100). Si no puedes evaluar, devuelve {\"estado\":\"pendiente\",\"razon\":\"explicación clara\",\"puntaje\":0}."
        ];

        $userPrompt = <<<PROMPT
Vacante: "$nombreVacante"

Requisitos a tener en cuenta:
$requisito_ia

Criterios de evaluación (peso %):
$criteriosTexto

$detectedText

Instrucciones:
1) Analiza el CV con cuidado (usa la lista de posiciones detectadas arriba como referencia).
2) Asigna un puntaje del 1 al 100 (según los pesos).
3) Decide "aprobado" o "rechazado".
4) Explica la razón en la clave "razon" y menciona, si procede, las posiciones detectadas que usaste como evidencia.
5) RESPONDE SÓLO EN JSON (formato abajo).

Formato de salida obligatorio:
{
  "estado": "aprobado" o "rechazado",
  "razon": "motivo detallado (máx 6-8 líneas)",
  "puntaje": numero del 1 al 100
}

CV a analizar:
$texto
PROMPT;

        // Llamada al modelo (primera pasada)
        $result = $this->callOpenAI($system, $userPrompt);

        // Si la IA devolvió 'pendiente' o datos extraños, no hacemos override inmediato
        if (!isset($result['estado'], $result['puntaje'])) {
            return [
                'estado' => 'pendiente',
                'razon' => 'Respuesta IA inválida o incompleta',
                'puntaje' => 0,
                'raw' => $result['raw'] ?? null
            ];
        }

        // POST-CHECK determinista: si las posiciones detectadas muestran experiencia comercial suficiente,
        // y el modelo NO lo reflejó, hacemos un override basado en evidencia.
        $parsedCommercialMonths = $this->countRelevantMonths($positions, $this->relevantRoleKeywords);

        // Condición de inconsistencia a corregir: modelo dice 'rechazado' o puntaje muy bajo, pero evidencia cumple
        if ($parsedCommercialMonths >= $requiredMonths) {
            // Modelo no detectó (o subestimó) la experiencia: override si su resultado no coincide
            $modelSaysApproved = (strtolower($result['estado']) === 'aprobado');
            $modelScore = (int) ($result['puntaje'] ?? 0);

            if (!$modelSaysApproved || $modelScore < 50) {
                // calcular puntaje basado en la evidencia (fórmula simple)
                $computedScore = (int) min(100, round(50 + ($parsedCommercialMonths / $requiredMonths) * 50));
                $finalScore = max($modelScore, $computedScore);

                $evidenceStr = $this->formatPositionsForReason($positions, $this->relevantRoleKeywords);

                $overrideReason = "Override basado en evidencia: se detectó experiencia comercial de {$parsedCommercialMonths} meses ({$requiredMonths} meses requeridos). Ejemplo: {$evidenceStr}. Se ajustó la evaluación.";
                \Log::info("OpenAI override aplicado: " . $overrideReason);

                return [
                    'estado' => 'aprobado',
                    'razon' => $overrideReason,
                    'puntaje' => $finalScore
                ];
            }
        }

        // Si no hay override, retornamos la respuesta del modelo (normalizada)
        return [
            'estado' => $result['estado'],
            'razon' => $result['razon'],
            'puntaje' => (int) ($result['puntaje'] ?? 0)
        ];
    }

    /**
     * Llamada encapsulada al cliente OpenAI (devuelve array con estado, razon, puntaje o raw)
     */
    protected function callOpenAI($system, $userPrompt)
    {
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [$system, ['role' => 'user', 'content' => $userPrompt]],
                'temperature' => 0.0,
                'max_tokens' => 2000,
                'top_p' => 1.0,
                'n' => 1,
            ]);
        } catch (Exception $e) {
            \Log::error("OpenAI request failed: " . $e->getMessage());
            return [
                'estado' => 'pendiente',
                'razon' => 'Error al solicitar IA: ' . $e->getMessage(),
                'puntaje' => 0,
                'raw' => null
            ];
        }

        $content = $response->choices[0]->message->content ?? '';
        \Log::debug("Respuesta OpenAI cruda: " . $content);

        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($json['estado'], $json['razon'], $json['puntaje'])) {
            return $json;
        }

        $extracted = $this->extractJsonBlock($content);
        if ($extracted) {
            $decoded = json_decode($extracted, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['estado'], $decoded['razon'], $decoded['puntaje'])) {
                return $decoded;
            }
        }

        \Log::warning("OpenAI no devolvió JSON válido. Respuesta: " . $content);

        return [
            'estado' => 'pendiente',
            'razon' => 'Respuesta IA inválida',
            'puntaje' => 0,
            'raw' => $content
        ];
    }

    /**
     * Extrae un bloque JSON bruto desde el primer '{' hasta la última '}'.
     */
    protected function extractJsonBlock(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $candidate = substr($text, $start, $end - $start + 1);
        $tmp = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $candidate;
        }
        if (preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $text, $matches)) {
            foreach ($matches[0] as $m) {
                $d = json_decode($m, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $m;
                }
            }
        }
        return null;
    }

    /**
     * Detecta posiciones en el texto con patrón "Titulo | Mes Year - Mes Year"
     * y devuelve array con ['title','start_raw','end_raw','start','end','months']
     */
    protected function extractPositionsFromText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $positions = [];

        foreach ($lines as $line) {
            // limpieza mínima
            $lineClean = trim($line);
            if (empty($lineClean)) continue;

            // patrones comunes con pipe '|' o guion '-' indicando rango
            // Ejemplo: "Gestora comercial | Noviembre 2021 - Septiembre 2025"
            if (preg_match('/^(.*?)[\|\-]\s*([A-Za-zñÑáéíóúÁÉÍÓÚ]{3,}\s*\d{4})\s*-\s*([A-Za-zñÑáéíóúÁÉÍÓÚ]{3,}\s*\d{4}|presente|actual|hoy|\d{4})/i', $lineClean, $m)) {
                $title = trim($m[1]);
                $startRaw = trim($m[2]);
                $endRaw = trim($m[3]);

                $start = $this->parseSpanishMonthYear($startRaw);
                $end = $this->parseSpanishMonthYear($endRaw);
                if ($end === null) {
                    $end = new DateTime(); // hoy si dice presente/actual
                }

                if ($start instanceof DateTime && $end instanceof DateTime) {
                    $months = $this->monthsBetween($start, $end);
                    $positions[] = [
                        'title' => $title,
                        'start_raw' => $startRaw,
                        'end_raw' => $endRaw,
                        'start' => $start,
                        'end' => $end,
                        'months' => $months
                    ];
                } else {
                    // si no pudo parsear fechas, guardamos raw pero months=0
                    $positions[] = [
                        'title' => $title,
                        'start_raw' => $startRaw,
                        'end_raw' => $endRaw,
                        'start' => null,
                        'end' => null,
                        'months' => 0
                    ];
                }
            }
        }

        return $positions;
    }

    /**
     * Convierte cadenas como "Noviembre 2021" o "Nov 2021" o "2021" en DateTime (1er día del mes)
     */
    protected function parseSpanishMonthYear(string $text): ?DateTime
    {
        $text = trim(mb_strtolower($text));
        if (empty($text)) return null;

        // si contiene 'presente' o 'actual' consideramos null y el caller usará hoy
        if (preg_match('/presente|actual|hoy|hasta la fecha/i', $text)) {
            return null;
        }

        // map de meses (acepta abreviaturas)
        $map = [
            'enero'=>'01','ene'=>'01',
            'febrero'=>'02','feb'=>'02',
            'marzo'=>'03','mar'=>'03',
            'abril'=>'04','abr'=>'04',
            'mayo'=>'05','may'=>'05',
            'junio'=>'06','jun'=>'06',
            'julio'=>'07','jul'=>'07',
            'agosto'=>'08','ago'=>'08',
            'septiembre'=>'09','sep'=>'09','sept'=>'09',
            'octubre'=>'10','oct'=>'10',
            'noviembre'=>'11','nov'=>'11',
            'diciembre'=>'12','dic'=>'12'
        ];

        // intentar extraer mes y año
        if (preg_match('/([a-záéíóúñ]{3,})\s+(\d{4})/i', $text, $m)) {
            $mes = mb_strtolower($m[1]);
            $anio = $m[2];
            $mesNum = $map[$mes] ?? null;
            if ($mesNum) {
                try {
                    return new DateTime("{$anio}-{$mesNum}-01");
                } catch (Exception $e) {
                    return null;
                }
            }
        }

        // si viene solo año "2021"
        if (preg_match('/(\d{4})/', $text, $m2)) {
            $anio = $m2[1];
            try {
                return new DateTime("{$anio}-01-01");
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Calcula meses completos entre dos DateTime (incluye meses parciales como entero)
     */
    protected function monthsBetween(DateTime $start, DateTime $end): int
    {
        $y1 = (int)$start->format('Y');
        $m1 = (int)$start->format('n');
        $y2 = (int)$end->format('Y');
        $m2 = (int)$end->format('n');

        return max(0, (($y2 - $y1) * 12) + ($m2 - $m1));
    }

    /**
     * Cuenta meses de roles relevantes (filtra por keywords)
     */
    protected function countRelevantMonths(array $positions, array $keywords): int
    {
        $total = 0;
        foreach ($positions as $p) {
            $title = mb_strtolower($p['title']);
            foreach ($keywords as $k) {
                if (mb_stripos($title, $k) !== false) {
                    $total += (int)($p['months'] ?? 0);
                    break;
                }
            }
        }
        return $total;
    }

    /**
     * Formatea una breve evidencia (citas de títulos y rangos) para poner en la "razon"
     */
    protected function formatPositionsForReason(array $positions, array $keywords): string
    {
        $evidence = [];
        foreach ($positions as $p) {
            $title = $p['title'];
            foreach ($keywords as $k) {
                if (mb_stripos(mb_strtolower($title), $k) !== false) {
                    $evidence[] = "{$title} ({$p['start_raw']} - {$p['end_raw']})";
                    break;
                }
            }
        }
        return implode('; ', $evidence);
    }

    /**
     * Intenta extraer requisito mínimo de años desde el texto de requisitos.
     * Devuelve meses (ej: "2 años" -> 24) o null si no encuentra.
     */
    protected function extractRequiredMonthsFromRequisitos(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(años|año|years)/i', $text, $m)) {
            $years = (int)$m[1];
            return $years * 12;
        }
        // intentar formato "X meses"
        if (preg_match('/(\d+)\s*(meses|mes)/i', $text, $m2)) {
            return (int)$m2[1];
        }
        return null;
    }
}
