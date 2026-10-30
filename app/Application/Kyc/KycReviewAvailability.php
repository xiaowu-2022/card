<?php

namespace App\Application\Kyc;

use Illuminate\Support\Facades\DB;

final class KycReviewAvailability
{
    /** @return array{isSuperseded: bool, currentApplicationId: string, reviewable: bool, retryable: bool} */
    public function forApplication(string $tenantId, string $userId, string $applicationId, string $reviewStatus, ?string $processingStatus): array
    {
        return $this->forApplications([compact('tenantId', 'userId', 'applicationId', 'reviewStatus', 'processingStatus')])[$applicationId];
    }

    /**
     * Read all requested users' retained chains in one database round trip.
     *
     * @param array<int, array{tenantId: string, userId: string, applicationId: string, reviewStatus: string, processingStatus: ?string}> $applications
     * @return array<string, array{isSuperseded: bool, currentApplicationId: string, reviewable: bool, retryable: bool}>
     */
    public function forApplications(array $applications): array
    {
        if ($applications === []) {
            return [];
        }
        $requested = [];
        $result = [];
        foreach ($applications as $application) {
            $id = $application['applicationId'];
            $requested[$id] = $application;
            // A concurrently removed or mismatched resource never receives actions.
            $result[$id] = ['isSuperseded' => false, 'currentApplicationId' => $id, 'reviewable' => false, 'retryable' => false];
        }
        $bindings = array_map(static fn (array $application): array => [
            'tenant_id' => $application['tenantId'], 'user_id' => $application['userId'], 'application_id' => $application['applicationId'],
        ], array_values($requested));

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE requested AS (
                SELECT r.tenant_id, r.user_id, r.application_id
                FROM jsonb_to_recordset(CAST(? AS jsonb)) AS r(tenant_id uuid, user_id uuid, application_id uuid)
            ), scoped_applications AS MATERIALIZED (
                SELECT a.id, a.tenant_id, a.user_id, a.resubmission_of_id, a.review_status, a.processing_status, a.submitted_at
                FROM kyc_applications a
                JOIN (SELECT DISTINCT tenant_id, user_id FROM requested) owners
                    ON owners.tenant_id = a.tenant_id AND owners.user_id = a.user_id
            ), roots AS (
                SELECT a.* FROM scoped_applications a
                JOIN requested r ON r.application_id = a.id AND r.tenant_id = a.tenant_id AND r.user_id = a.user_id
            ), chain AS (
                SELECT a.id AS root_id, a.id, a.tenant_id, a.user_id, a.submitted_at, ARRAY[a.id] AS path
                FROM roots a
                UNION ALL
                SELECT parent.root_id, child.id, child.tenant_id, child.user_id, child.submitted_at, parent.path || child.id
                FROM chain parent
                JOIN scoped_applications child ON child.resubmission_of_id = parent.id
                    AND child.tenant_id = parent.tenant_id AND child.user_id = parent.user_id
                WHERE NOT child.id = ANY(parent.path)
            )
            SELECT root.id, root.review_status, root.processing_status,
                CASE WHEN EXISTS (
                    SELECT 1 FROM scoped_applications child WHERE child.resubmission_of_id = root.id
                        AND child.tenant_id = root.tenant_id AND child.user_id = root.user_id
                ) THEN 1 ELSE 0 END AS has_successor,
                COALESCE((
                    SELECT leaf.id FROM chain leaf WHERE leaf.root_id = root.id AND NOT EXISTS (
                        SELECT 1 FROM scoped_applications child WHERE child.resubmission_of_id = leaf.id
                            AND child.tenant_id = leaf.tenant_id AND child.user_id = leaf.user_id
                    ) ORDER BY leaf.submitted_at DESC, leaf.id DESC LIMIT 1
                ), root.id) AS current_application_id,
                (
                    SELECT latest.id FROM scoped_applications latest
                    WHERE latest.tenant_id = root.tenant_id AND latest.user_id = root.user_id
                        AND NOT EXISTS (
                            SELECT 1 FROM scoped_applications child WHERE child.resubmission_of_id = latest.id
                                AND child.tenant_id = latest.tenant_id AND child.user_id = latest.user_id
                        )
                    ORDER BY latest.submitted_at DESC, latest.id DESC LIMIT 1
                ) AS latest_application_id,
                CASE WHEN EXISTS (
                    SELECT 1 FROM scoped_applications active WHERE active.tenant_id = root.tenant_id AND active.user_id = root.user_id
                        AND active.id <> root.id AND active.review_status = 'PENDING' AND active.processing_status IS DISTINCT FROM 'FAILED'
                ) THEN 1 ELSE 0 END AS has_active_conflict
            FROM roots root
            SQL, [json_encode($bindings, JSON_THROW_ON_ERROR)]);

        foreach ($rows as $row) {
            $superseded = (int) $row->has_successor === 1;
            $retryState = ($row->processing_status === 'FAILED' && $row->review_status === 'PENDING')
                || ($row->processing_status !== null && $row->review_status === 'REJECTED');
            $result[$row->id] = [
                'isSuperseded' => $superseded,
                // With no child the original request remains the relevant record.
                'currentApplicationId' => $superseded ? $row->current_application_id : $row->id,
                'reviewable' => ! $superseded && $row->review_status === 'PENDING',
                'retryable' => ! $superseded && $retryState && $row->latest_application_id === $row->id && (int) $row->has_active_conflict === 0,
            ];
        }

        return $result;
    }
}
