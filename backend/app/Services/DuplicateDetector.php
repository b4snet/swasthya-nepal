<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate detection for patient registration (PRODUCT_REQUIREMENTS §6.1,
 * API_CONTRACTS.md §21.7).
 *
 * Candidates are surfaced, NEVER auto-merged. Two signals:
 *
 *  - identifier exact matches (deterministic hash) — the strongest signal,
 *    score 1.0;
 *  - full-name trigram similarity + date-of-birth equality (pg_trgm).
 *
 * The scan is TENANT-wide by design (identity integrity): the whole point
 * is to stop the same person being registered twice at two facilities of
 * one organization. Candidates carry minimal identity fields only.
 */
final class DuplicateDetector
{
    private const NAME_SIMILARITY_THRESHOLD = 0.3;

    private const CANDIDATE_SCORE_THRESHOLD = 0.55;

    private const MAX_CANDIDATES = 5;

    /**
     * @param  array<string, string>  $identifierHashes  type → hash
     * @return Collection<int, array{id: string, mrn: string, fullName: string, dateOfBirth: string|null, sex: string, score: float}>
     */
    public function candidates(string $tenantId, string $fullName, ?string $dateOfBirth, array $identifierHashes = []): Collection
    {
        $candidates = collect();

        foreach ($identifierHashes as $type => $hash) {
            $matches = DB::table('patient_identifiers')
                ->join('patients', function ($join): void {
                    $join->on('patients.id', '=', 'patient_identifiers.patient_id')
                        ->on('patients.tenant_id', '=', 'patient_identifiers.tenant_id');
                })
                ->where('patient_identifiers.tenant_id', $tenantId)
                ->where('patient_identifiers.status', 'active')
                ->where('patient_identifiers.type', $type)
                ->where('patient_identifiers.value_hash', $hash)
                ->where('patients.status', 'active')
                ->select('patients.id', 'patients.mrn', 'patients.full_name', 'patients.date_of_birth', 'patients.sex')
                ->get();

            foreach ($matches as $match) {
                $candidates->push($this->present($match, 1.0));
            }
        }

        if ($dateOfBirth !== null) {
            $nameMatches = DB::table('patients')
                ->select(
                    'id',
                    'mrn',
                    'full_name',
                    'date_of_birth',
                    'sex',
                    DB::raw('similarity(lower(full_name), ?) as score')
                )
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereRaw('similarity(lower(full_name), ?) > ?', [strtolower($fullName), self::NAME_SIMILARITY_THRESHOLD])
                ->addBinding(strtolower($fullName), 'select')
                ->get();

            foreach ($nameMatches as $match) {
                $score = (float) $match->score + ($match->date_of_birth === $dateOfBirth ? 0.3 : 0.0);
                $candidates->push($this->present($match, round($score, 4)));
            }
        }

        return $candidates
            ->unique('id')
            ->filter(fn (array $candidate): bool => $candidate['score'] >= self::CANDIDATE_SCORE_THRESHOLD)
            ->sortByDesc('score')
            ->take(self::MAX_CANDIDATES)
            ->values();
    }

    /**
     * @return array{id: string, mrn: string, fullName: string, dateOfBirth: string|null, sex: string, score: float}
     */
    private function present(object $row, float $score): array
    {
        return [
            'id' => (string) $row->id,
            'mrn' => (string) $row->mrn,
            'fullName' => (string) $row->full_name,
            'dateOfBirth' => $row->date_of_birth !== null ? (string) $row->date_of_birth : null,
            'sex' => (string) $row->sex,
            'score' => $score,
        ];
    }
}
