<?php

namespace App\Http\Controllers;

use App\Models\Candidato;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use App\Models\Vacante;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\AbstractElement; // Importar la clase base AbstractElement
use App\Services\OpenAiService;
use Illuminate\Support\Facades\Storage;
use MailerSend\MailerSend;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\Helpers\Builder\EmailParams;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CandidatosExport;
use Illuminate\Support\Facades\Log;
use Spatie\PdfToText\Pdf; 

class CandidatoController extends Controller
{

public function index(Request $request)
{
    $query = Candidato::query();

    if ($request->filled('q')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm($request->input('q')));
    }

    $candidatos = $query->get();

    return view('candidatos.index', compact('candidatos'));
}

    public function mostrarCandidatos(Request $request)
    {
        if ($request->filled('q')) {
        $search = trim((string) $request->input('q'));
        $vacantes = Vacante::where('slug', $search)
            ->orWhere('titulo', 'LIKE', $this->likeSearchTerm($search))
            ->get();
        } else {
            $vacantes = Vacante::all();
        }

    return view('candidatos.mostrarCandidatos', compact('vacantes'));
}

    public function create()
    {
return view('candidatos.create');
    }
public function store(Request $request, $slug)
{
    try {
        // 🔍 Buscar la vacante
        $vacante = Vacante::where('slug', $slug)->firstOrFail();

        // ✅ Validación de datos
        $data = $request->validate([
            'nombre' => 'required|string|min:3|max:100|regex:/^[\pL\s\-]+$/u',
            'email' => 'required|email|max:150',
            'cv' => 'nullable|file|mimes:pdf,doc,docx|max:4096',
            'celular' => 'required|string|min:7|max:15|regex:/^[0-9+\-\s]+$/',
            'autorizacion' => 'required|boolean',
        ]);

        // 📁 Subir archivo
        if ($request->hasFile('cv')) {
            try {
                $data['cv'] = $request->file('cv')->store('privado/cvs', 'private');
            } catch (\Throwable $e) {
                Log::error("Error al subir CV: " . $e->getMessage());
                return back()->with('error', '⚠️ No se pudo subir tu hoja de vida. Intenta nuevamente.');
            }
        } else {
            $data['cv'] = null;
        }

        $data['vacante_id'] = $vacante->id;
        $text = '[Sin texto disponible]';

        // 🧾 Si hay CV, extraer texto
        if (!empty($data['cv'])) {
            $filePath = Storage::disk('private')->path($data['cv']);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            try {

if ($extension === 'pdf') {
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        // Verificar si el PDF realmente tiene texto
        if (empty(trim($text)) || strlen(trim($text)) < 30) {
            Log::warning("PDF sin texto legible detectado: {$filePath}");
            
            // Mover el archivo a carpeta de revisión
            $nuevoPath = 'privado/cvs_fallos/' . basename($data['cv']);
            Storage::disk('private')->move($data['cv'], $nuevoPath);

            return back()->with('error', '⚠️ Tu hoja de vida parece ser una imagen escaneada o no contiene texto legible. 
                Por favor súbela nuevamente en formato Word (.docx) o PDF digital.');
        }

    } catch (\Throwable $e) {
        Log::error("Error al leer PDF ({$filePath}): " . $e->getMessage());
        return back()->with('error', '⚠️ No se pudo procesar tu hoja de vida. Verifica que el archivo no esté dañado o protegido.');
    }
}
 elseif (in_array($extension, ['doc', 'docx'])) {
                    // ✅ Leer archivo Word
                    $phpWord = IOFactory::load($filePath);

                    $extractText = function (AbstractElement $element) use (&$extractText) {
                        $result = '';
                        if ($element instanceof Text) {
                            $result .= $element->getText();
                        } elseif ($element instanceof TextRun) {
                            foreach ($element->getElements() as $child) {
                                $result .= $extractText($child);
                            }
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                            foreach ($element->getRows() as $row) {
                                foreach ($row->getCells() as $cell) {
                                    foreach ($cell->getElements() as $cellElement) {
                                        $result .= $extractText($cellElement) . " ";
                                    }
                                    $result .= "\n";
                                }
                            }
                        } elseif (method_exists($element, 'getElements')) {
                            foreach ($element->getElements() as $child) {
                                $result .= $extractText($child);
                            }
                        }
                        return $result;
                    };

                    $text = '';
                    foreach ($phpWord->getSections() as $section) {
                        foreach ($section->getElements() as $element) {
                            $text .= $extractText($element) . "\n";
                        }
                    }
                } else {
                    $text = '[Formato de archivo no soportado]';
                }
            } catch (\Throwable $e) {
                Log::warning("Error al leer archivo ($extension): " . $e->getMessage());
                $text = '[No se pudo leer el contenido del archivo]';
            }
        }

        $data['cv_text'] = $text;

        // 🤖 Evaluar con OpenAI
        try {
            $openai = new OpenAIService();
            $evaluacion = $openai->analizarCV(
                $text,
                $vacante->slug,
                $vacante->requisito_ia,
                $vacante->criterios
            );
        } catch (\Throwable $e) {
            Log::error("Error al procesar IA: " . $e->getMessage());
            $evaluacion = ['estado' => 'pendiente', 'razon' => 'Error al procesar IA', 'puntaje' => 0];
        }

        // 🧠 Validar respuesta IA
        $data['estado'] = $evaluacion['estado'] ?? 'pendiente';
        $data['razon_ia'] = $evaluacion['razon'] ?? 'No se obtuvo respuesta de la IA';
        $data['puntaje'] = $evaluacion['puntaje'] ?? 0;

        // 💾 Guardar candidato
        try {
            Candidato::create($data);
        } catch (\Throwable $e) {
            Log::error("Error al guardar candidato: " . $e->getMessage());
            return back()->with('error', '❌ No se pudo guardar tu información. Intenta más tarde.');
        }

        return back()->with('success', '✅ Hoja de vida enviada con éxito. ¡Gracias por postularte!');

    } catch (\Throwable $e) {
        Log::error("Error general en store(): " . $e->getMessage());
        return back()->with('error', '❌ Ocurrió un error inesperado. Por favor, intenta nuevamente.');
    }
}

    
    /**
     * Display the specified resource.
     */
    public function show(Request $request, $slug)
    {
    session()->put('toggles.contador', $request->input('contador') == '1');
    session()->put('toggles.cajero',   $request->input('cajero')   == '1');
    session()->put('toggles.ventas',   $request->input('ventas')   == '1');
    $query = Candidato::query();
    $vacante = Vacante::where('slug', $slug)->firstOrFail();
    $candidatos = $query->where('vacante_id', 'like', $vacante->id)->get();
    
    if ($request->filled('ordenar')) {
    $orden = $request->input('ordenar');
    if ($orden === 'puntaje_asc') {
        $query->orderBy('puntaje', 'asc');
    } elseif ($orden === 'puntaje_desc') {
        $query->orderBy('puntaje', 'desc');
    } elseif ($orden === 'fecha') {
        $query->orderBy('created_at', 'desc');
    }
}

    
    if ($request->filled('q')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm($request->input('q')));
    }
    if ($request->filled('puntaje_min') && $request->filled('puntaje_max')) {
        $puntaje_max = $request->input('puntaje_max');
        $puntaje_min = $request->input('puntaje_min');
        $query->whereBetween('puntaje', [$puntaje_min , $puntaje_max]);
    }
    if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
    $query->whereBetween('created_at', [
        $request->input('fecha_inicio') . ' 00:00:00',
        $request->input('fecha_fin') . ' 23:59:59'
    ]);
        } elseif ($request->filled('fecha_inicio')) {
            $query->where('created_at', '>=', $request->input('fecha_inicio') . ' 00:00:00');
        } elseif ($request->filled('fecha_fin')) {
            $query->where('created_at', '<=', $request->input('fecha_fin') . ' 23:59:59');
        }


if ($request->input('contador') == 1) {
    $keywords = ['Contabilidad', 'Finanzas', 'Auditoría', 'Impuestos', 'Declaraciones Fiscales',
        'Balances', 'Estados Financieros', 'Conciliaciones Bancarias', 'Cuentas por Pagar',
        'Cuentas por Cobrar', 'Nóminas', 'Presupuestos', 'Análisis Financiero', 'Costos',
        'Contabilidad de Costos', 'Normativa Contable', 'Regulaciones Fiscales', 'SAP',
        'QuickBooks', 'Excel Avanzado', 'Software Contable', 'Gestión de Activos',
        'Gestión de Pasivos', 'Análisis de Datos', 'Reportes Financieros', 'Cierre Contable',
        'Libros Contables', 'Planificación Financiera'];

    // Esta condición se añadirá AL LADO de cualquier condición anterior
    $query->where(function ($q) use ($keywords) {
        foreach ($keywords as $keyword) {
            $q->orWhereRaw('LOWER(cv_text) LIKE ?', [$this->lowerLikeSearchTerm($keyword)]);
        }
    });
    // Si $request->filled('q') fue true, $query ahora representa:
    // SELECT * FROM candidatos WHERE cv_text LIKE '%searchTerm%' AND (LOWER(cv_text) LIKE '%contador%' OR ...)
}
if ($request->input('aprobar')) {
    $candidato = Candidato::findOrFail($request->input('aprobar'));
    $candidato->estado = 'aprobado';
    $candidato->save();
    return redirect()->route('candidatos.show', ['slug' => $vacante->slug])->with('success', 'Candidato aprobado');
}
if ($request->input('ventas') == 1) {
    $keywords = ['Técnicas de Venta', 'Cierre de Ventas', 'Negociación', 'Prospección', 'Captación de Clientes',
        'Fidelización de Clientes', 'Gestión de Cartera', 'CRM', 'Análisis de Ventas',
        'Cumplimiento de Metas', 'Cuotas de Venta', 'Venta Cruzada', 'Up-selling',
        'Presentación de Productos', 'Elaboración de Presupuestos', 'Marketing', 'Comercial',
        'Generación de Leads', 'Pronóstico de Ventas'];

    // Esta condición se añadirá AL LADO de cualquier condición anterior
    $query->where(function ($q) use ($keywords) {
        foreach ($keywords as $keyword) {
            $q->orWhereRaw('LOWER(cv_text) LIKE ?', [$this->lowerLikeSearchTerm($keyword)]);
        }
    });
    // Si $request->filled('q') fue true, $query ahora representa:
    // SELECT * FROM candidatos WHERE cv_text LIKE '%searchTerm%' AND (LOWER(cv_text) LIKE '%contador%' OR ...)
}
if ($request->input('cajero') == 1) {
    $keywords = ['Manejo de Efectivo', 'Cierre de Caja', 'Arqueo de Caja', 'TPV', 'POS', 'Cobros',
        'Devoluciones', 'Cambios', 'Facturación', 'Contabilidad básica', 'Gestión de Inventario',
        'Control de Stock', 'Escáner de códigos', 'Operaciones bancarias', 'Detección de billetes falsos'];

    // Esta condición se añadirá AL LADO de cualquier condición anterior
    $query->where(function ($q) use ($keywords) {
        foreach ($keywords as $keyword) {
            $q->orWhereRaw('LOWER(cv_text) LIKE ?', [$this->lowerLikeSearchTerm($keyword)]);
        }
    });
    // Si $request->filled('q') fue true, $query ahora representa:
    // SELECT * FROM candidatos WHERE cv_text LIKE '%searchTerm%' AND (LOWER(cv_text) LIKE '%contador%' OR ...)
}
    $candidatos = $query->get();
    $initialToggles = session('toggles', []);
    return view('candidatos.show', compact('candidatos', 'vacante', 'initialToggles'));
    
  }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Candidato $candidato)
    {
    return view('candidatos.edit', compact('candidato'));

    }
public function aprobar(Candidato $candidato)
{
    $candidato->estado = 'aprobado';
    $candidato->save();

    return back()->with('success', 'Candidato '.$candidato->nombre.' aprobado');
}
public function rechazar(Candidato $candidato)
{
    $candidato->estado = 'rechazado';
    $candidato->save();

    return back()->with('success', 'Candidato '.$candidato->nombre.' rechazado');
}

public function showaprobados($slug)
{
 $query = Candidato::query();
    $vacante = Vacante::where('slug', $slug)->firstOrFail();
    $candidatos = $query->where('vacante_id', 'like', $vacante->id)->get();
    return view('candidatos.aprobar', compact('candidatos' , 'slug'));
}
public function showrechazados($slug)
{
 $query = Candidato::query();
    $vacante = Vacante::where('slug', $slug)->firstOrFail();
    $candidatos = $query->where('vacante_id', 'like', $vacante->id)->get();
    return view('candidatos.rechazados', compact('candidatos' , 'slug'));
}

public function descargarCV($id)
{
    $candidato = Candidato::findOrFail($id);

    if (!$candidato->cv) {
        return redirect()->back()->with('error', 'Este candidato no tiene hoja de vida adjunta.');
    }

    $ruta = $candidato->cv;

    if (!Storage::disk('private')->exists($ruta)) {
        return redirect()->back()->with('error', 'Archivo no encontrado en el servidor.');
    }

    return Storage::disk('private')->download($ruta);
}

public function update(Request $request, Candidato $candidato)
{
    $data = $request->validate([
        'nombre' => 'required',
        'email' => 'required|email',
        'cv' => 'nullable|file|mimes:pdf,doc,docx|max:2048',
    ]);

    if ($request->hasFile('cv')) {
        $data['cv'] = $request->file('cv')->store('cvs', 'public');
    }

    $candidato->update($data);

    return redirect()->route('candidatos.index')->with('success', 'Candidato actualizado');
}

    /**
     * Remove the specified resource from storage.
     */
public function destroy(Candidato $candidato)
{
    $candidato->delete();

    return back()->with('success', 'Candidato eliminado');

}

public function enviarCorreo(Request $request,$id)
{
    $estado = $request->action;
    $candidato = Candidato::findOrFail($id);
    $apiKey = (string) config('services.mailersend.api_key');

    if ($apiKey === '') {
        return redirect()->back()->with('error', 'MailerSend no esta configurado.');
    }

    $mailersend = new MailerSend([
        'api_key' => $apiKey,
    ]);

    $recipients = [
        new Recipient($candidato->email, 'DutyFreePartners'),
    ];

    if ($estado === "aprobado") {
        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Duty Free Partners')
            ->setRecipients($recipients)
            ->setSubject('¡Felicitaciones, avanzas en nuestro proceso! 🚀')
            ->setHtml('
                <div style="font-family: Arial, sans-serif; color:#333; line-height:1.6; max-width:600px; margin:auto;">
                    <p>Hola <strong>'.$candidato->nombre.'</strong>,</p>
                    <p>Tu hoja de vida nos llevó directo a un destino especial: <strong>nuestro radar de talento Duty Free</strong> 🌟. 
                    Nos encantó conocer tu experiencia y estamos convencidos de que tu perfil tiene todo para conectar con la energía única de nuestras tiendas.</p>
                    <p><strong>Muy pronto te estaremos contactando para agendar tu próxima escala: la entrevista ✈️.</strong></p>
                    <p>Será la oportunidad perfecta para que nos cuentes tu historia, conozcas más sobre lo que hacemos y descubras el fascinante universo del travel retail, donde cada día es una nueva aventura y cada pasajero, una conexión inolvidable.</p>
                    <p>Gracias por embarcarte en este viaje con nosotros 🚀🌎</p>
                    <p style="margin-top:20px;">Con entusiasmo,<br><strong>Equipo Duty Free Partners Colombia</strong></p>
                </div>
            ')
            ->setText('Hola '.$candidato->nombre.', felicidades 🎉 avanzas en nuestro proceso de selección. Pronto te contactaremos para la entrevista. - Duty Free Partners Colombia')
            ->setReplyTo('no-reply@skyfreeshopdutyfree.com')
            ->setReplyToName('No Reply');

    } else {
        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Duty Free Partners')
            ->setRecipients($recipients)
            ->setSubject('Gracias por tu interés en Duty Free Partners ✨')
            ->setHtml('
                <div style="font-family: Arial, sans-serif; color:#333; line-height:1.6; max-width:600px; margin:auto;">
                    <p>Hola <strong>'.$candidato->nombre.'</strong>,</p>
                    <p>Queremos agradecerte por postularte y permitirnos conocer tu hoja de vida 🚀. 
                    Valoramos mucho el interés que mostraste en ser parte del mundo Duty Free, un espacio lleno de conexiones, experiencias y nuevos destinos.</p>
                    <p>En esta ocasión, tu perfil no se ajusta del todo a lo que estamos buscando para esta vacante. 
                    <strong>Pero cada camino tiene diferentes escalas ✈️ y estamos seguros de que pronto llegará la oportunidad perfecta para ti.</strong></p>
                    <p>Te invitamos a seguir pendiente de nuestras futuras convocatorias, porque tu talento siempre puede encontrar un nuevo destino en nuestro equipo 🌟.</p>
                    <p style="margin-top:20px;">Con gratitud,<br><strong>Equipo Duty Free Partners Colombia</strong></p>
                </div>
            ')
            ->setText('Hola '.$candidato->nombre.', gracias por postularte. En esta ocasión tu perfil no avanzó, pero te invitamos a estar pendiente de futuras convocatorias. - Duty Free Partners Colombia')
            ->setReplyTo('no-reply@skyfreeshopdutyfree.com')
            ->setReplyToName('No Reply');

    }

    try {
        $response = $mailersend->email->send($emailParams);

        if (isset($response['status_code']) && $response['status_code'] === 202) {
            $candidato->estado_correo = $estado;
            $candidato->save();

            return redirect()->back()->with('success', 'Correo enviado con éxito');
        } else {
            return redirect()->back()->with(
                'error',
                'Error al enviar correo. Código: ' . ($response['status_code'] ?? 'desconocido')
            );
        }
    } catch (\Exception $e) {
        return redirect()->back()->with('error', 'Error al enviar correo: ' . $e->getMessage());
    }
}




public function export(Request $request, $slug)
{
    $vacante = Vacante::where('slug', $slug)->firstOrFail();
    $query = Candidato::where('vacante_id', $vacante->id);

    // Aplica filtros igual que en show
    if ($request->filled('q')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm($request->q));
    }
    if ($request->filled('puntaje_min') && $request->filled('puntaje_max')) {
        $query->whereBetween('puntaje', [$request->puntaje_min, $request->puntaje_max]);
    }
    if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
        $query->whereBetween('created_at', [
            $request->fecha_inicio . ' 00:00:00',
            $request->fecha_fin . ' 23:59:59'
        ]);
    }
    if ($request->filled('contador')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm('contador'));
    }
    if ($request->filled('cajero')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm('cajero'));
    }
    if ($request->filled('ventas')) {
        $query->where('cv_text', 'LIKE', $this->likeSearchTerm('ventas'));
    }
    if ($request->filled('ordenar')) {
        if ($request->ordenar == 'puntaje_asc') {
            $query->orderBy('puntaje', 'asc');
        } elseif ($request->ordenar == 'puntaje_desc') {
            $query->orderBy('puntaje', 'desc');
        } elseif ($request->ordenar == 'fecha') {
            $query->orderBy('created_at', 'desc');
        }
    }

    $candidatos = $query->get();

    return Excel::download(new CandidatosExport($candidatos), 'candidatos.xlsx');
}

public function storeMasivo(Request $request)
{
    // Validamos al menos la vacante (dejamos cvs opcional aquí para manejar manualmente)
    $request->validate([
        'vacante_id' => 'required|exists:vacantes,slug',
        'cvs.*'      => 'file|mimes:pdf,doc,docx|max:8000',
    ]);

    // Si PHP/servidor truncó el POST (post_max_size) no habrá archivos en $request->files
    if (!$request->hasFile('cvs')) {
        // Log para depuración
        \Log::warning('storeMasivo: no se detectaron archivos en la solicitud', [
            'has_file' => $request->hasFile('cvs'),
            'files_keys' => array_keys($request->files->all()),
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        ]);

        return back()->withErrors(['cvs' => 'No se detectaron archivos. Verifica que hayas seleccionado archivos y que el tamaño total no exceda la configuración de PHP.']);
    }

    $files = $request->file('cvs');

    // Normalizar a array siempre
    if ($files instanceof \Illuminate\Http\UploadedFile) {
        $files = [$files];
    }

    if (!is_array($files) || empty($files)) {
        \Log::error('storeMasivo: $files no es array o está vacío', ['files' => $files]);
        return back()->withErrors(['cvs' => 'Debes subir al menos un archivo válido.']);
    }

    // Buscar la vacante por slug desde el form
    $vacante = Vacante::where('slug', $request->vacante_id)->firstOrFail();

    foreach ($files as $cvFile) {
        if (!$cvFile || !$cvFile->isValid()) {
            \Log::warning('storeMasivo: archivo inválido encontrado', ['file' => $cvFile]);
            continue; // saltar archivo inválido pero procesar otros
        }

        $path = $cvFile->store('privado/cvs', 'private');
        $filePath = Storage::disk('private')->path($path);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $text = '';
        try {
            if ($extension === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
            } elseif (in_array($extension, ['doc', 'docx'])) {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);

                $extractText = function ($element) use (&$extractText) {
                    $result = '';
                    if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                        $result .= $element->getText();
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        foreach ($element->getElements() as $child) {
                            $result .= $extractText($child);
                        }
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                        foreach ($element->getRows() as $row) {
                            foreach ($row->getCells() as $cell) {
                                foreach ($cell->getElements() as $cellElement) {
                                    $result .= $extractText($cellElement) . " ";
                                }
                                $result .= "\n";
                            }
                        }
                    } elseif (method_exists($element, 'getElements')) {
                        foreach ($element->getElements() as $child) {
                            $result .= $extractText($child);
                        }
                    }
                    return $result;
                };

                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $text .= $extractText($element) . "\n";
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error("Error al procesar archivo: " . $e->getMessage(), ['file' => $cvFile->getClientOriginalName()]);
            $text = "[Error al procesar CV]";
        }

        // Evaluación IA
        $openai = new \App\Services\OpenAiService();
        $evaluacion = $openai->analizarCV(
            $text,
            $vacante->slug,
            $vacante->requisito_ia,
            $vacante->criterios
        );

        // Guardar candidato
        Candidato::create([
            'nombre'     => pathinfo($cvFile->getClientOriginalName(), PATHINFO_FILENAME),
            'email'      => 'pendiente@correo.com',
            'celular'    => '0000000000',
            'cv'         => $path,
            'cv_text'    => $text,
            'vacante_id' => $vacante->id,
            'estado'     => $evaluacion['estado'] ?? 'pendiente',
            'razon_ia'   => $evaluacion['razon'] ?? 'Sin respuesta IA',
            'puntaje'    => $evaluacion['puntaje'] ?? 0,
        ]);
    }


        return back()->with('success', 'Hojas de vida subidas correctamente');
}

public function subirAllCv()
{
    $vacantes = \App\Models\Vacante::all();
    return view('candidatos.subirAllCv', compact('vacantes'));
}

private function likeSearchTerm(mixed $value): string
{
    $term = mb_substr(trim((string) $value), 0, 100);

    return '%' . addcslashes($term, '\\%_') . '%';
}

private function lowerLikeSearchTerm(mixed $value): string
{
    return mb_strtolower($this->likeSearchTerm($value));
}




}
