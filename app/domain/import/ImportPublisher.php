<?php
declare(strict_types=1);

/**
 * Creating the real records from the reviewed proposals.
 *
 * Everything lands as a DRAFT. That is not a conservative default that could
 * be relaxed later — it is the rule the whole platform is built on (Section
 * 24). Content reaches a visitor only after a person has approved it, and an
 * import is not an approval. So this step puts the knowledge into the normal
 * review queue, where it is checked exactly like anything typed in by hand.
 */
final class ImportPublisher
{
    /**
     * @return array{created:int,failed:int,by_type:array<string,int>,errors:string[]}
     */
    public static function publish(array $run, array $agent, string $actor, bool $sendForReview = false): array
    {
        $runId    = (int)$run['id'];
        $proposals = db()->all(
            "SELECT * FROM import_proposals
             WHERE run_id = ? AND status IN ('proposed','edited')
             ORDER BY target_type ASC, sort_order ASC, id ASC",
            [$runId]
        );

        $created = 0;
        $failed  = 0;
        $byType  = [];
        $errors  = [];

        Audit::setActor($actor);

        foreach ($proposals as $proposal) {
            $payload = json_decode((string)$proposal['payload_json'], true);
            if (!is_array($payload)) {
                $failed++;
                $errors[] = 'Could not read the content of "' . (string)$proposal['title'] . '".';
                continue;
            }

            db()->begin();
            try {
                // Draft either way. "Send for review" only moves it into the
                // review queue so a reviewer can be given the whole batch at
                // once; it is still not visible to any visitor.
                $state = $sendForReview ? 'pending_review' : 'draft';

                $entityId = match ((string)$proposal['target_type']) {
                    'knowledge_article'   => self::createKnowledge($agent, $payload, $proposal, $state),
                    'faq'                 => self::createFaq($agent, $payload, $proposal, $state),
                    'stall_package'       => self::createStall($agent, $payload, $proposal, $state),
                    'sponsorship_package' => self::createSponsorship($agent, $payload, $proposal, $state),
                    default               => null,
                };

                if ($entityId === null) {
                    throw new RuntimeException('unsupported target type');
                }

                db()->update('import_proposals', [
                    'status'            => 'published',
                    'created_entity_id' => $entityId,
                ], 'id = ?', [(int)$proposal['id']]);

                db()->commit();

                $created++;
                $type = (string)$proposal['target_type'];
                $byType[$type] = ($byType[$type] ?? 0) + 1;
            } catch (Throwable $ex) {
                db()->rollback();
                db()->update('import_proposals', ['status' => 'failed'], 'id = ?', [(int)$proposal['id']]);
                $failed++;
                $errors[] = '"' . (string)$proposal['title'] . '" could not be created: ' . $ex->getMessage();
                app_log('import', 'publish failed', [
                    'proposal' => (int)$proposal['id'],
                    'error'    => $ex->getMessage(),
                ]);
            }
        }

        db()->update('import_runs', [
            'published_total' => $created,
            'status'          => 'published',
            'stage_detail'    => $created . ' ' . ($created === 1 ? 'item' : 'items')
                . ($sendForReview ? ' added and sent for review.' : ' added as drafts.'),
            'completed_at'    => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$runId]);

        Audit::log(
            'import_published',
            (int)$agent['id'],
            'import_run',
            $runId,
            $created . ImportRun::plural($created, ' record', ' records') . ' created from '
                . (int)$run['documents_total'] . ImportRun::plural((int)$run['documents_total'], ' document', ' documents')
        );

        // The uploaded files have served their purpose. The extracted text is
        // kept on the run for reference, but the original documents are not
        // needed and should not sit in storage indefinitely.
        ImportRun::purgeFiles($runId);

        return ['created' => $created, 'failed' => $failed, 'by_type' => $byType, 'errors' => $errors];
    }

    // -----------------------------------------------------------------
    // One record per type
    // -----------------------------------------------------------------

    private static function createKnowledge(array $agent, array $payload, array $proposal, string $state = 'draft'): int
    {
        $id = db()->insert('knowledge_articles', [
            'agent_id'         => (int)$agent['id'],
            'title'            => mb_substr((string)($payload['title'] ?? $proposal['title']), 0, 200),
            'category'         => mb_substr((string)($payload['category'] ?? 'imported'), 0, 80),
            'body'             => (string)($payload['body'] ?? ''),
            'keywords'         => mb_substr((string)($payload['keywords'] ?? ''), 0, 500),
            'stage_tags'       => mb_substr((string)($payload['stage_tags'] ?? ''), 0, 200),
            'status'           => $state,
            'active'           => 1,
            'source_reference' => self::reference($proposal),
        ]);

        Audit::log('knowledge_changed', (int)$agent['id'], 'knowledge_article', $id,
            'Created by document import');

        return $id;
    }

    private static function createFaq(array $agent, array $payload, array $proposal, string $state = 'draft'): int
    {
        $id = db()->insert('faq_entries', [
            'agent_id'         => (int)$agent['id'],
            'question'         => mb_substr((string)($payload['question'] ?? ''), 0, 400),
            'answer'           => (string)($payload['answer'] ?? ''),
            'keywords'         => mb_substr((string)($payload['keywords'] ?? ''), 0, 500),
            'category'         => mb_substr((string)($payload['category'] ?? 'imported'), 0, 80),
            'status'           => $state,
            'active'           => 1,
            'source_reference' => self::reference($proposal),
        ]);

        Audit::log('knowledge_changed', (int)$agent['id'], 'faq', $id,
            'Created by document import');

        return $id;
    }

    private static function createStall(array $agent, array $payload, array $proposal, string $state = 'draft'): int
    {
        $currency = strtoupper((string)($payload['currency'] ?? 'USD'));
        $region   = (string)($payload['region_group'] ?? 'international');

        // The currency rule is enforced here as well as at display time, so a
        // record that could never legally be shown is never even created.
        if (in_array($region, ['international', 'both'], true) && $currency !== 'USD') {
            throw new RuntimeException('international pricing must be in USD');
        }

        $mode = (string)($payload['pricing_mode'] ?? 'fixed');
        $rate = self::decimal($payload['rate_per_sqm'] ?? null);
        $min  = self::decimal($payload['min_sqm'] ?? null);

        // A stored price is always needed, because it is what the list view
        // and the reference figure use. For a rate-based record it is the
        // cost of the smallest bookable size, which is arithmetic on approved
        // numbers rather than an invented figure.
        $price = self::decimal($payload['price'] ?? null);
        if ($price === null && $rate !== null && $min !== null) {
            $price = round($rate * $min, 2);
        }

        $id = db()->insert('stall_packages', [
            'agent_id'         => (int)$agent['id'],
            'package_name'     => mb_substr((string)($payload['package_name'] ?? $proposal['title']), 0, 160),
            'package_type'     => in_array($payload['package_type'] ?? '', ['shell', 'bare'], true)
                ? (string)$payload['package_type'] : 'shell',
            'stall_size'       => mb_substr((string)($payload['stall_size'] ?? ''), 0, 64),
            'size_sqm'         => $min,
            'currency'         => $currency,
            'price'            => $price ?? 0,
            'price_basis'      => $mode === 'per_sqm' ? 'per_sqm' : 'per_stall',
            'pricing_mode'     => $mode === 'per_sqm' ? 'per_sqm' : 'fixed',
            'rate_per_sqm'     => $rate,
            'min_sqm'          => $min,
            'increment_sqm'    => self::decimal($payload['increment_sqm'] ?? null),
            'max_sqm'          => self::decimal($payload['max_sqm'] ?? null),
            'edition_label'    => mb_substr((string)($payload['edition_label'] ?? ''), 0, 160) ?: null,
            'region_group'     => in_array($region, ['international', 'pakistan', 'both'], true) ? $region : 'international',
            'description'      => (string)($payload['description'] ?? ''),
            'included_items'   => (string)($payload['included_items'] ?? ''),
            'tax_included'     => in_array($payload['tax_included'] ?? '', ['yes', 'no', 'unspecified'], true)
                ? (string)$payload['tax_included'] : 'unspecified',
            'tax_rate_percent' => self::decimal($payload['tax_rate_percent'] ?? null),
            'tax_label'        => mb_substr((string)($payload['tax_label'] ?? ''), 0, 120) ?: null,
            'status'           => $state,
            'active'           => 1,
            'source_reference' => self::reference($proposal),
        ]);

        Audit::log('pricing_changed', (int)$agent['id'], 'stall_package', $id,
            'Created by document import');

        return $id;
    }

    private static function createSponsorship(array $agent, array $payload, array $proposal, string $state = 'draft'): int
    {
        $currency = strtoupper((string)($payload['currency'] ?? 'USD'));
        $region   = (string)($payload['region_group'] ?? 'international');

        if (in_array($region, ['international', 'both'], true) && $currency !== 'USD') {
            throw new RuntimeException('international pricing must be in USD');
        }

        $quantity = $payload['quantity_available'] ?? null;

        $id = db()->insert('sponsorship_packages', [
            'agent_id'           => (int)$agent['id'],
            'tier_name'          => mb_substr((string)($payload['tier_name'] ?? $proposal['title']), 0, 160),
            'currency'           => $currency,
            'price'              => self::decimal($payload['price'] ?? null) ?? 0,
            'benefits'           => (string)($payload['benefits'] ?? ''),
            'quantity_available' => ($quantity === null || $quantity === '') ? null : (int)$quantity,
            'edition_label'      => mb_substr((string)($payload['edition_label'] ?? ''), 0, 160) ?: null,
            'region_group'       => in_array($region, ['international', 'pakistan', 'both'], true) ? $region : 'international',
            'description'        => (string)($payload['description'] ?? ''),
            'tax_included'       => in_array($payload['tax_included'] ?? '', ['yes', 'no', 'unspecified'], true)
                ? (string)$payload['tax_included'] : 'unspecified',
            'tax_rate_percent'   => self::decimal($payload['tax_rate_percent'] ?? null),
            'tax_label'          => mb_substr((string)($payload['tax_label'] ?? ''), 0, 120) ?: null,
            'status'             => $state,
            'active'             => 1,
            'source_reference'   => self::reference($proposal),
        ]);

        Audit::log('pricing_changed', (int)$agent['id'], 'sponsorship_package', $id,
            'Created by document import');

        return $id;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Where this record came from, recorded on the record itself so that
     * months later it is obvious which document a figure was taken from.
     */
    private static function reference(array $proposal): string
    {
        $note = 'Imported from ' . (string)($proposal['source_summary'] ?: 'uploaded documents');
        if ((int)$proposal['clarified'] === 1) {
            $note .= ' (a value was confirmed by the administrator)';
        }
        return mb_substr($note, 0, 255);
    }

    private static function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            $clean = preg_replace('/[^0-9.\-]/', '', (string)$value) ?? '';
            if ($clean === '' || !is_numeric($clean)) {
                return null;
            }
            $value = $clean;
        }
        $number = round((float)$value, 2);
        return $number > 0 ? $number : null;
    }
}
