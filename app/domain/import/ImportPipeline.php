<?php
declare(strict_types=1);

/**
 * Advancing an import by one step.
 *
 * The browser calls this repeatedly. Each call does a small, bounded piece of
 * work and returns, so nothing runs long enough to be killed by the host's
 * execution limit. If the operator closes the tab, the run simply stops where
 * it is and picks up from the same place when they come back.
 */
final class ImportPipeline
{
    /**
     * @return array{run:array,message:string,continue:bool,finished:bool}
     */
    public static function step(array $agent, array $run): array
    {
        $runId  = (int)$run['id'];
        $status = (string)$run['status'];

        // Stop before the work rather than after it, with a reason the
        // operator can act on.
        if (in_array($status, ['collecting', 'extracting', 'analysing'], true)) {
            $problem = self::providerProblem($agent, $run);
            if ($problem !== null) {
                ImportRun::fail($runId, $problem);
                $fresh = ImportRun::load($runId, (int)$agent['id']) ?? $run;
                return ['run' => $fresh, 'message' => $problem, 'continue' => false, 'finished' => true];
            }
        }

        try {
            $message = match ($status) {
                'collecting'  => self::beginReading($run),
                'extracting'  => self::readDocuments($run),
                'analysing'   => self::analyse($agent, $run),
                'reconciling' => self::compare($run),
                'clarifying'  => self::checkClarifications($run),
                'proposing'   => self::assemble($run),
                default       => '',
            };
        } catch (Throwable $ex) {
            ImportRun::fail($runId, $ex->getMessage());
            $fresh = ImportRun::load($runId, (int)$agent['id']) ?? $run;
            return [
                'run'      => $fresh,
                'message'  => 'Something went wrong: ' . $ex->getMessage(),
                'continue' => false,
                'finished' => true,
            ];
        }

        $fresh = ImportRun::load($runId, (int)$agent['id']) ?? $run;

        return [
            'run'      => $fresh,
            'message'  => $message,
            'continue' => ImportRun::hasPendingWork($fresh),
            'finished' => in_array((string)$fresh['status'], ['review', 'published', 'failed', 'cancelled'], true),
        ];
    }

    /**
     * Is there a working AI provider? Worth establishing before anything else,
     * because reading the documents is pointless without one and a missing API
     * key is by far the commonest reason this fails.
     *
     * @return string|null the problem, or null when all is well
     */
    public static function providerProblem(array $agent, ?array $run = null): ?string
    {
        $name = $run !== null
            ? ImportRun::reader($run, $agent)['provider']
            : (string)$agent['llm_provider'];

        $provider = LlmProviderFactory::make($name);

        // The provider itself is the authority on whether it can run; the
        // catalogue only supplies the wording for why it cannot.
        if (!$provider->isConfigured()) {
            $problem = LlmCatalogue::configurationProblem($name)
                ?? ('No API key is saved for ' . LlmCatalogue::label($name) . '.');

            // If another provider IS set up, say so — the operator can switch
            // this import to it without touching the agent or adding a key.
            $alternatives = array_diff_key(LlmCatalogue::configuredOptions(), [$name => true]);
            if ($alternatives !== []) {
                return $problem . ' You already have '
                     . self::listNames(array_values($alternatives))
                     . ' set up, so you can use ' . (count($alternatives) === 1 ? 'that' : 'one of those')
                     . ' to read the documents instead — choose it below. Your uploads are still here.';
            }

            return $problem . ' The documents cannot be analysed without it. Add a key under Settings, '
                 . 'then come back to this import — your uploads are still here.';
        }

        // The breaker tracks the agent's live provider. It is only relevant
        // here when the import is using that same one.
        if ($name === (string)$agent['llm_provider'] && CircuitBreaker::isUnavailable($agent)) {
            return 'The assistant is currently offline because ' . LlmCatalogue::label($name)
                 . ' has been failing. Either wait and try again, or pick a different provider below '
                 . 'to read the documents.';
        }

        return null;
    }

    private static function listNames(array $names): string
    {
        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** Move a collected run into reading, once there is something to read. */
    private static function beginReading(array $run): string
    {
        $pending = (int)db()->value(
            "SELECT COUNT(*) FROM import_documents WHERE run_id = ? AND extraction_status = 'pending'",
            [(int)$run['id']]
        );

        if ($pending === 0) {
            $ready = (int)db()->value('SELECT COUNT(*) FROM import_chunks WHERE run_id = ?', [(int)$run['id']]);
            if ($ready === 0) {
                return 'Add at least one document before starting.';
            }
            ImportRun::setStatus((int)$run['id'], 'analysing', null);
            return 'Reading the documents.';
        }

        ImportRun::setStatus((int)$run['id'], 'extracting', null);
        return 'Reading the documents.';
    }

    private static function readDocuments(array $run): string
    {
        $result = ImportRun::extractNext($run);

        if ($result['done']) {
            $usable = (int)db()->value('SELECT COUNT(*) FROM import_chunks WHERE run_id = ?', [(int)$run['id']]);
            if ($usable === 0) {
                ImportRun::setStatus(
                    (int)$run['id'],
                    'collecting',
                    'No readable text was found in those documents.'
                );
                return 'None of those documents gave up any text. Check the notes against each one below.';
            }
            ImportRun::setStatus((int)$run['id'], 'analysing', null);
            return 'All documents read. Working out what they say.';
        }

        $name   = (string)($result['document']['original_name'] ?? 'a document');
        $detail = $result['result'];

        if (!$detail['ok']) {
            ImportRun::setStatus((int)$run['id'], 'extracting', 'Could not read ' . $name . '.');
            return 'Could not read ' . $name . '. ' . (string)$detail['problem'];
        }

        ImportRun::setStatus(
            (int)$run['id'],
            'extracting',
            'Read ' . $name . ' (' . number_format((int)$detail['chars']) . ' characters).'
        );

        return 'Read ' . $name . '.';
    }

    private static function analyse(array $agent, array $run): string
    {
        $found = 0;
        $last  = null;

        for ($i = 0; $i < ImportRun::CHUNKS_PER_PASS; $i++) {
            $result = FindingExtractor::analyseNext($agent, $run);

            if ($result['done']) {
                ImportRun::setStatus((int)$run['id'], 'reconciling', null);
                return 'Finished reading. Comparing the documents against each other.';
            }

            $found += (int)$result['findings'];
            $last   = $result;

            if ($result['error'] !== null) {
                break;
            }
        }

        $run = ImportRun::load((int)$run['id'], (int)$agent['id']) ?? $run;

        if ($last !== null && $last['error'] !== null) {
            $name = (string)($last['chunk']['original_name'] ?? 'a document');
            ImportRun::setStatus(
                (int)$run['id'],
                'analysing',
                'Had trouble with part of ' . $name . '. Trying the rest.'
            );
            return 'Had trouble with part of ' . $name . '.';
        }

        ImportRun::setStatus(
            (int)$run['id'],
            'analysing',
            'Analysed ' . (int)$run['chunks_done'] . ' of ' . (int)$run['chunks_total'] . ' sections, '
            . (int)$run['findings_total'] . ' facts so far.'
        );

        return $found > 0
            ? 'Found ' . $found . ' more ' . ($found === 1 ? 'fact' : 'facts') . '.'
            : 'That section had nothing worth recording.';
    }

    private static function compare(array $run): string
    {
        $result = FindingReconciler::reconcile($run);
        $runId  = (int)$run['id'];

        if ($result['questions'] > 0) {
            ImportRun::setStatus(
                $runId,
                'clarifying',
                $result['questions'] . ($result['questions'] === 1 ? ' thing needs' : ' things need') . ' your decision.'
            );
            return $result['questions'] === 1
                ? 'One thing needs your decision before I can finish.'
                : $result['questions'] . ' things need your decision before I can finish.';
        }

        ImportRun::setStatus($runId, 'proposing', 'Everything agreed. Assembling the knowledge base.');

        return $result['duplicates'] > 0
            ? 'The documents agree with each other. ' . $result['duplicates']
              . ' repeated ' . ($result['duplicates'] === 1 ? 'fact was' : 'facts were') . ' merged.'
            : 'The documents agree with each other.';
    }

    private static function checkClarifications(array $run): string
    {
        $open = FindingReconciler::nextOpenQuestion((int)$run['id']);
        if ($open !== null) {
            return '';   // still waiting on the operator
        }

        // Back through the comparison rather than straight to assembly: an
        // answer can expose a new gap, such as a tax rate that is only needed
        // once the operator has said tax is added on top.
        ImportRun::setStatus((int)$run['id'], 'reconciling', 'Applying your answers.');
        return 'Thank you. Applying your answers now.';
    }

    private static function assemble(array $run): string
    {
        $result = ProposalBuilder::build($run);
        $runId  = (int)$run['id'];

        if ($result['proposals'] === 0) {
            ImportRun::setStatus(
                $runId,
                'review',
                'Nothing could be assembled from those documents.'
            );
            return 'I could not assemble anything usable from those documents.';
        }

        ImportRun::setStatus(
            $runId,
            'review',
            $result['proposals'] . ImportRun::plural($result['proposals'], ' item', ' items') . ' ready for you to check.'
        );

        $message = 'Ready. I have prepared ' . $result['proposals'] . ' '
                 . ($result['proposals'] === 1 ? 'item' : 'items') . ' for you to look over.';

        if ($result['excluded'] > 0) {
            $message .= ' ' . $result['excluded'] . ' '
                      . ($result['excluded'] === 1 ? 'was' : 'were')
                      . ' left out, either because you said so or because they could not be used.';
        }

        return $message;
    }

    /**
     * Re-run the comparison after the operator answers something, so a late
     * answer is taken into account without starting again.
     */
    public static function rebuild(array $run): void
    {
        ImportRun::setStatus((int)$run['id'], 'proposing', 'Applying your answers.');
    }
}
