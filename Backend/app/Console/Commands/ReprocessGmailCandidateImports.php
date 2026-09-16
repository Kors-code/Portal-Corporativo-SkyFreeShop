<?php

namespace App\Console\Commands;

use App\Models\Candidato;
use App\Services\CandidateCvImportService;
use Illuminate\Console\Command;

class ReprocessGmailCandidateImports extends Command
{
    protected $signature = 'candidates:gmail-reprocess
        {--limit=50 : Numero maximo de candidatos a reprocesar}
        {--id=* : ID especifico de candidato a reprocesar}';

    protected $description = 'Reclasifica y reevalua hojas de vida importadas desde Gmail.';

    public function handle(CandidateCvImportService $importer): int
    {
        $query = Candidato::query()
            ->where('source_channel', 'gmail')
            ->latest();

        $ids = array_filter((array) $this->option('id'));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->limit((int) $this->option('limit'));
        }

        $processed = 0;

        foreach ($query->get() as $candidato) {
            $updated = $importer->reprocessImportedCandidate($candidato);
            $this->line(sprintf(
                '#%s %s -> vacante_id=%s estado=%s puntaje=%s',
                $updated->id,
                $updated->nombre,
                $updated->vacante_id,
                $updated->estado,
                $updated->puntaje
            ));
            $processed++;
        }

        $this->info("Candidatos reprocesados: {$processed}");

        return self::SUCCESS;
    }
}
