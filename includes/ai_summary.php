<?php
declare(strict_types=1);

function aiSummarySourceText(array $record): string
{
    $parts = [];

    $map = [
        'Name' => $record['name'] ?? '',
        'Meaning' => $record['meaning_extended'] ?? ($record['meaning'] ?? ''),
        'Ethnic Group' => $record['ethnic_group'] ?? '',
        'Region' => $record['region'] ?? '',
        'Gender' => $record['gender'] ?? '',
        'Origin Overview' => $record['origin_overview'] ?? '',
        'Historical Context' => $record['historical_context'] ?? '',
        'Naming Traditions' => $record['naming_traditions'] ?? ($record['naming_context'] ?? ''),
        'Cultural Significance' => $record['cultural_significance'] ?? ($record['cultural_explanation'] ?? ''),
        'Variants' => $record['variants'] ?? '',
        'Pronunciation Notes' => $record['pronunciation_notes'] ?? '',
        'Sources' => $record['sources_extended'] ?? ($record['sources'] ?? ''),
    ];

    foreach ($map as $label => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $parts[] = $label . ': ' . $value;
        }
    }

    return implode("\n", $parts);
}

function aiSummaryHash(array $record): string
{
    return hash('sha256', aiSummarySourceText($record));
}

function aiGenerateDraftSummary(array $record): string
{
    $name = trim((string) ($record['name'] ?? 'This name'));
    $meaning = trim((string) ($record['meaning_extended'] ?? ($record['meaning'] ?? '')));
    $ethnicGroup = trim((string) ($record['ethnic_group'] ?? ''));
    $region = trim((string) ($record['region'] ?? ''));
    $gender = trim((string) ($record['gender'] ?? ''));
    $origin = trim((string) ($record['origin_overview'] ?? ''));
    $history = trim((string) ($record['historical_context'] ?? ''));
    $traditions = trim((string) ($record['naming_traditions'] ?? ($record['naming_context'] ?? '')));
    $culture = trim((string) ($record['cultural_significance'] ?? ($record['cultural_explanation'] ?? '')));
    $variants = trim((string) ($record['variants'] ?? ''));

    $sentences = [];

    if ($meaning !== '') {
        $sentences[] = $name . ' is a personal name that is commonly interpreted to mean ' . strtolower($meaning) . '.';
    } else {
        $sentences[] = $name . ' is an Indigenous African personal name documented within this knowledge system.';
    }

    if ($ethnicGroup !== '' && $region !== '') {
        $sentences[] = 'It is associated with the ' . $ethnicGroup . ' community in ' . $region . '.';
    } elseif ($ethnicGroup !== '') {
        $sentences[] = 'It is associated with the ' . $ethnicGroup . ' community.';
    } elseif ($region !== '') {
        $sentences[] = 'It is linked to ' . $region . '.';
    }

    if ($gender !== '') {
        $sentences[] = 'Within this context, it is typically used as a ' . strtolower($gender) . ' name.';
    }

    if ($traditions !== '') {
        $sentences[] = 'In traditional usage, the name is often given in relation to ' . strtolower($traditions) . '.';
    }

    if ($culture !== '') {
        $sentences[] = 'It carries cultural significance that may be understood through the following perspective: ' . $culture;
    }

    if ($history !== '') {
        $sentences[] = 'Historical references further situate the name within the following context: ' . $history;
    }

    if ($origin !== '' && strlen($origin) > 20) {
        $sentences[] = 'Additional background information highlights that ' . lcfirst($origin);
    }

    if ($variants !== '') {
        $sentences[] = 'Related forms or variants of the name have also been documented, including ' . $variants . '.';
    }

    $text = implode(' ', $sentences);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return trim($text);
}

function aiFetchCachedSummary(PDO $pdo, int $nameEntryId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_name_summaries
        WHERE name_entry_id = :name_entry_id
        LIMIT 1
    ");
    $stmt->execute([':name_entry_id' => $nameEntryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function aiUpsertSummary(PDO $pdo, int $nameEntryId, string $summaryText, string $sourceHash, ?int $generatedBy = null): void
{
    $existing = aiFetchCachedSummary($pdo, $nameEntryId);

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE ai_name_summaries
            SET
                summary_text = :summary_text,
                model_name = :model_name,
                prompt_version = :prompt_version,
                source_hash = :source_hash,
                generated_by = :generated_by,
                generated_at = NOW(),
                updated_at = NOW()
            WHERE name_entry_id = :name_entry_id
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO ai_name_summaries (
                name_entry_id,
                summary_text,
                model_name,
                prompt_version,
                source_hash,
                generated_by,
                generated_at,
                updated_at
            ) VALUES (
                :name_entry_id,
                :summary_text,
                :model_name,
                :prompt_version,
                :source_hash,
                :generated_by,
                NOW(),
                NOW()
            )
        ");
    }

    $stmt->execute([
        ':name_entry_id' => $nameEntryId,
        ':summary_text' => $summaryText,
        ':model_name' => 'internal-ai-draft-v1',
        ':prompt_version' => 'v1',
        ':source_hash' => $sourceHash,
        ':generated_by' => $generatedBy,
    ]);
}

function aiRecordGapAnalysis(array $record): array
{
    $checks = [
        'meaning' => [
            'label' => 'Meaning',
            'value' => trim((string) ($record['meaning_extended'] ?? ($record['meaning'] ?? ''))),
            'priority' => 'high',
            'advice' => 'Add a stronger interpretation of the name’s meaning and semantic nuance.',
        ],
        'origin_overview' => [
            'label' => 'Origin and Background',
            'value' => trim((string) ($record['origin_overview'] ?? '')),
            'priority' => 'medium',
            'advice' => 'Document ethnic, linguistic, geographic, clan, or lineage background.',
        ],
        'historical_context' => [
            'label' => 'Historical Context',
            'value' => trim((string) ($record['historical_context'] ?? '')),
            'priority' => 'medium',
            'advice' => 'Add historical context, generational usage, or period-specific significance.',
        ],
        'naming_traditions' => [
            'label' => 'Naming Context and Traditions',
            'value' => trim((string) ($record['naming_traditions'] ?? ($record['naming_context'] ?? ''))),
            'priority' => 'high',
            'advice' => 'Explain when, why, and under what social or ritual conditions the name is given.',
        ],
        'cultural_significance' => [
            'label' => 'Cultural Significance',
            'value' => trim((string) ($record['cultural_significance'] ?? ($record['cultural_explanation'] ?? ''))),
            'priority' => 'high',
            'advice' => 'Describe symbolic, communal, spiritual, or identity-related significance.',
        ],
        'variants' => [
            'label' => 'Variants and Related Forms',
            'value' => trim((string) ($record['variants'] ?? '')),
            'priority' => 'low',
            'advice' => 'List spelling variants, related forms, dialect variants, or gendered counterparts.',
        ],
        'pronunciation_notes' => [
            'label' => 'Pronunciation Notes',
            'value' => trim((string) ($record['pronunciation_notes'] ?? '')),
            'priority' => 'low',
            'advice' => 'Add pronunciation, tonal, or phonetic notes where helpful.',
        ],
        'sources' => [
            'label' => 'Sources and Documentation',
            'value' => trim((string) ($record['sources_extended'] ?? ($record['sources'] ?? ''))),
            'priority' => 'high',
            'advice' => 'Add better citations, oral authority references, field notes, or academic documentation.',
        ],
    ];

    $missing = [];
    $weak = [];
    $score = 100;

    foreach ($checks as $key => $item) {
        $value = $item['value'];
        $length = mb_strlen($value);

        if ($value === '') {
            $missing[] = [
                'key' => $key,
                'label' => $item['label'],
                'priority' => $item['priority'],
                'advice' => $item['advice'],
                'state' => 'missing',
            ];

            $score -= match ($item['priority']) {
                'high' => 18,
                'medium' => 10,
                default => 5,
            };

            continue;
        }

        if ($length < 35 && in_array($item['priority'], ['high', 'medium'], true)) {
            $weak[] = [
                'key' => $key,
                'label' => $item['label'],
                'priority' => $item['priority'],
                'advice' => 'This section exists but is still thin. Expand it with more culturally grounded detail.',
                'state' => 'weak',
            ];

            $score -= match ($item['priority']) {
                'high' => 8,
                'medium' => 5,
                default => 2,
            };
        }
    }

    $score = max(0, min(100, $score));

    $priorityActions = [];

    foreach (array_merge($missing, $weak) as $gap) {
        $priorityActions[] = $gap['label'] . ': ' . $gap['advice'];
    }

    $summary = match (true) {
        $score >= 85 => 'This authority record is relatively strong, though a few targeted enrichments could still improve editorial depth.',
        $score >= 65 => 'This authority record is developing well but still has notable editorial gaps that should be addressed before it is treated as fully mature.',
        $score >= 40 => 'This authority record has core structure in place, but several important sections remain weak or incomplete.',
        default => 'This authority record is still early-stage and needs substantial enrichment before it can be considered robust.',
    };

    return [
        'score' => $score,
        'summary' => $summary,
        'missing' => $missing,
        'weak' => $weak,
        'priority_actions' => $priorityActions,
    ];
}