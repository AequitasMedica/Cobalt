<?php
// RAND/UCLA autosave + dashboard endpoint.
// Stores one row per round + panelist + question in a CSV file.
// No SQL database is used. Inputs are validated/sanitized and CSV formula injection is mitigated.

declare(strict_types=1);

define('RESPONSES_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'respuestas.csv');
define('ALLOWED_ROUNDS', [1, 2]);

$CSV_HEADERS = [
    'round',
    'panelist',
    'question',
    'row_index',
    'question_text',
    'answer',
    'comment',
    'saved_at',
    'created_at',
    'ip_hash',
    'user_agent_hash'
];

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text($value, int $maxLength = 500): string {
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }

    $text = (string)$value;
    $text = strip_tags($text);
    $text = str_replace(["\0", "\r"], ['', ' '], $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function csv_safe(string $value): string {
    // Prevent CSV formula injection when opened in Excel/Sheets.
    $trimmed = ltrim($value);
    if ($trimmed !== '' && preg_match('/^[=+\-@\t]/', $trimmed)) {
        return "'" . $value;
    }
    return $value;
}

function un_csv_safe(string $value): string {
    if (str_starts_with($value, "'")) {
        $rest = substr($value, 1);
        if ($rest !== '' && preg_match('/^[=+\-@\t]/', ltrim($rest))) {
            return $rest;
        }
    }
    return $value;
}

function int_in_range($value, int $min, int $max): ?int {
    if (is_string($value)) {
        $value = trim($value);
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    $int = (int)$value;
    if ($int < $min || $int > $max) {
        return null;
    }

    return $int;
}

function request_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['success' => false, 'message' => 'JSON inválido.'], 400);
    }

    return $data;
}

function read_csv_rows_unlocked(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return [];
    }

    $file = fopen($filePath, 'r');
    if (!$file) {
        return [];
    }

    $headers = fgetcsv($file);
    if (!$headers) {
        fclose($file);
        return [];
    }

    $rows = [];
    while (($data = fgetcsv($file)) !== false) {
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            $assoc[$header] = $data[$index] ?? '';
        }
        $rows[] = $assoc;
    }

    fclose($file);
    return $rows;
}

function read_csv_rows_locked($fileHandle): array {
    rewind($fileHandle);
    $headers = fgetcsv($fileHandle);
    if (!$headers) {
        return [];
    }

    $rows = [];
    while (($data = fgetcsv($fileHandle)) !== false) {
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            $assoc[$header] = $data[$index] ?? '';
        }
        $rows[] = $assoc;
    }

    return $rows;
}

function percentile(array $values, float $percent): ?float {
    $values = array_values(array_filter($values, static fn($v) => is_numeric($v)));
    $n = count($values);

    if ($n === 0) {
        return null;
    }

    sort($values, SORT_NUMERIC);

    if ($n === 1) {
        return (float)$values[0];
    }

    $position = ($percent / 100) * ($n - 1);
    $lower = (int)floor($position);
    $upper = (int)ceil($position);
    $weight = $position - $lower;

    return ((float)$values[$lower]) + (((float)$values[$upper] - (float)$values[$lower]) * $weight);
}

function round_num(?float $value, int $precision = 3): ?float {
    return $value === null ? null : round($value, $precision);
}

function empty_count_map(): array {
    $counts = [];
    for ($i = 1; $i <= 9; $i++) {
        $counts[(string)$i] = 0;
    }
    return $counts;
}

function stats_from_rows(array $rows, int $round = 1): array {
    $groups = [];
    $questionTexts = [];

    foreach ($rows as $row) {
        if ((int)($row['round'] ?? 0) !== $round) {
            continue;
        }

        $question = (string)($row['question'] ?? '');
        $answer = (int)($row['answer'] ?? 0);

        if ($question === '' || $answer < 1 || $answer > 9) {
            continue;
        }

        if (!isset($groups[$question])) {
            $groups[$question] = [];
        }

        $groups[$question][] = $answer;
        if (!isset($questionTexts[$question]) && isset($row['question_text'])) {
            $questionTexts[$question] = (string)$row['question_text'];
        }
    }

    ksort($groups, SORT_NATURAL);
    $stats = [];

    foreach ($groups as $question => $answers) {
        $counts = empty_count_map();
        foreach ($answers as $answer) {
            $counts[(string)$answer]++;
        }

        $n = count($answers);
        $percentages = [];
        for ($i = 1; $i <= 9; $i++) {
            $percentages[(string)$i] = $n > 0 ? round(($counts[(string)$i] / $n) * 100, 3) : 0;
        }

        $p30 = percentile($answers, 30);
        $p50 = percentile($answers, 50);
        $p70 = percentile($answers, 70);
        $di = null;
        $consensus = false;

        if ($p30 !== null && $p70 !== null) {
            $denominator = 2.35 + (1.5 * abs(5 - (($p30 + $p70) / 2)));
            $di = $denominator > 0 ? (($p70 - $p30) / $denominator) : null;
            $consensus = $di !== null && $di < 1;
        }

        $stats[$question] = [
            'question' => $question,
            'question_text' => $questionTexts[$question] ?? '',
            'n' => $n,
            'counts' => $counts,
            'percentages' => $percentages,
            'median' => round_num($p50, 3),
            'p30' => round_num($p30, 3),
            'p70' => round_num($p70, 3),
            'di' => round_num($di, 3),
            'consensus' => $consensus,
            'answers' => $answers
        ];
    }

    return $stats;
}

function answers_for_panelist(array $rows, int $round, string $panelist): array {
    $answers = [];

    foreach ($rows as $row) {
        if ((int)($row['round'] ?? 0) !== $round) {
            continue;
        }

        if ((string)($row['panelist'] ?? '') !== $panelist) {
            continue;
        }

        $question = (string)($row['question'] ?? '');
        if ($question === '') {
            continue;
        }

        $answers[$question] = [
            'answer' => (string)($row['answer'] ?? ''),
            'comment' => un_csv_safe((string)($row['comment'] ?? '')),
            'row_index' => (string)($row['row_index'] ?? ''),
            'saved_at' => (string)($row['saved_at'] ?? '')
        ];
    }

    return $answers;
}

function distinct_panelists(array $rows, int $round = 1): array {
    $panelists = [];

    foreach ($rows as $row) {
        if ((int)($row['round'] ?? 0) !== $round) {
            continue;
        }

        $panelist = (string)($row['panelist'] ?? '');
        if ($panelist !== '') {
            $panelists[$panelist] = true;
        }
    }

    $list = array_keys($panelists);
    natcasesort($list);
    return array_values($list);
}

function comments_by_question(array $rows, int $round): array {
    $comments = [];

    foreach ($rows as $row) {
        if ((int)($row['round'] ?? 0) !== $round) {
            continue;
        }

        $question = (string)($row['question'] ?? '');
        $comment = trim(un_csv_safe((string)($row['comment'] ?? '')));
        if ($question === '' || $comment === '') {
            continue;
        }

        if (!isset($comments[$question])) {
            $comments[$question] = [];
        }

        $comments[$question][] = [
            'panelist' => (string)($row['panelist'] ?? ''),
            'answer' => (string)($row['answer'] ?? ''),
            'comment' => $comment,
            'saved_at' => (string)($row['saved_at'] ?? '')
        ];
    }

    ksort($comments, SORT_NATURAL);
    return $comments;
}

function dashboard_payload(array $rows, int $round): array {
    $stats = stats_from_rows($rows, $round);
    $comments = comments_by_question($rows, $round);
    $questions = [];

    foreach ($stats as $question => $stat) {
        $stat['comments'] = $comments[$question] ?? [];
        $questions[] = $stat;
    }

    return [
        'success' => true,
        'round' => $round,
        'generated_at' => date('c'),
        'panelists' => distinct_panelists($rows, $round),
        'questions' => $questions
    ];
}

function output_csv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function handle_get(): void {
    $action = clean_text($_GET['action'] ?? '', 50);
    $round = int_in_range($_GET['round'] ?? 1, 1, 2) ?? 1;
    $rows = read_csv_rows_unlocked(RESPONSES_FILE);

    if ($action === 'list_panelists') {
        json_response([
            'success' => true,
            'panelists' => distinct_panelists($rows, 1)
        ]);
    }

    if ($action === 'context') {
        $panelist = csv_safe(clean_text($_GET['panelist'] ?? '', 120));
        if ($panelist === '') {
            json_response(['success' => false, 'message' => 'Panelista requerido.'], 400);
        }

        $payload = [
            'success' => true,
            'round' => $round,
            'panelist' => $panelist,
            'current_answers' => answers_for_panelist($rows, $round, $panelist)
        ];

        if ($round === 2) {
            $payload['previous_answers'] = answers_for_panelist($rows, 1, $panelist);
            $payload['round1_stats'] = stats_from_rows($rows, 1);
        }

        json_response($payload);
    }

    if ($action === 'stats') {
        json_response([
            'success' => true,
            'round' => $round,
            'stats' => stats_from_rows($rows, $round)
        ]);
    }

    if ($action === 'dashboard') {
        json_response(dashboard_payload($rows, $round));
    }

    if ($action === 'export_individual') {
        global $CSV_HEADERS;
        $filterRound = isset($_GET['round']) ? int_in_range($_GET['round'], 1, 2) : null;
        $exportRows = [];
        foreach ($rows as $row) {
            if ($filterRound !== null && (int)($row['round'] ?? 0) !== $filterRound) {
                continue;
            }
            $exportRows[] = $row;
        }
        output_csv('respuestas_individuales.csv', $CSV_HEADERS, $exportRows);
    }

    if ($action === 'export_stats') {
        $stats = stats_from_rows($rows, $round);
        $headers = ['round', 'question', 'question_text', 'n', 'count_1', 'count_2', 'count_3', 'count_4', 'count_5', 'count_6', 'count_7', 'count_8', 'count_9', 'median', 'p30', 'p70', 'di', 'consensus'];
        $exportRows = [];
        foreach ($stats as $stat) {
            $row = [
                'round' => (string)$round,
                'question' => $stat['question'],
                'question_text' => $stat['question_text'],
                'n' => $stat['n'],
                'median' => $stat['median'],
                'p30' => $stat['p30'],
                'p70' => $stat['p70'],
                'di' => $stat['di'],
                'consensus' => $stat['consensus'] ? 'yes' : 'no'
            ];
            for ($i = 1; $i <= 9; $i++) {
                $row['count_' . $i] = $stat['counts'][(string)$i] ?? 0;
            }
            $exportRows[] = $row;
        }
        output_csv('estadisticas_ronda' . $round . '.csv', $headers, $exportRows);
    }

    if ($action === 'export_comments') {
        $headers = ['round', 'question', 'panelist', 'answer', 'comment', 'saved_at'];
        $exportRows = [];
        foreach (comments_by_question($rows, $round) as $question => $items) {
            foreach ($items as $item) {
                $exportRows[] = [
                    'round' => (string)$round,
                    'question' => $question,
                    'panelist' => $item['panelist'],
                    'answer' => $item['answer'],
                    'comment' => $item['comment'],
                    'saved_at' => $item['saved_at']
                ];
            }
        }
        output_csv('comentarios_ronda' . $round . '.csv', $headers, $exportRows);
    }

    json_response(['success' => false, 'message' => 'Acción no reconocida.'], 404);
}

function handle_post(): void {
    global $CSV_HEADERS;

    $data = request_json();

    $round = int_in_range($data['round'] ?? null, 1, 2);
    $question = int_in_range($data['question'] ?? null, 1, 10000);
    $rowIndex = int_in_range($data['row_index'] ?? null, 1, 10000);
    $answer = int_in_range($data['answer'] ?? null, 1, 9);

    if ($round === null || !in_array($round, ALLOWED_ROUNDS, true)) {
        json_response(['success' => false, 'message' => 'Ronda inválida.'], 400);
    }

    if ($question === null) {
        json_response(['success' => false, 'message' => 'Número de pregunta inválido.'], 400);
    }

    if ($answer === null) {
        json_response(['success' => false, 'message' => 'La respuesta es obligatoria.'], 400);
    }

    $panelist = csv_safe(clean_text($data['panelist'] ?? '', 120));
    if ($panelist === '') {
        json_response(['success' => false, 'message' => 'Nombre de panelista requerido.'], 400);
    }

    $now = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $newRow = [
        'round' => (string)$round,
        'panelist' => $panelist,
        'question' => (string)$question,
        'row_index' => (string)($rowIndex ?? $question),
        'question_text' => csv_safe(clean_text($data['question_text'] ?? '', 1200)),
        'answer' => (string)$answer,
        'comment' => csv_safe(clean_text($data['comment'] ?? '', 2000)),
        'saved_at' => $now,
        'created_at' => $now,
        'ip_hash' => $ip !== '' ? hash('sha256', $ip . '|rand-ucla') : '',
        'user_agent_hash' => $userAgent !== '' ? hash('sha256', $userAgent . '|rand-ucla') : ''
    ];

    $file = fopen(RESPONSES_FILE, 'c+');
    if (!$file) {
        json_response(['success' => false, 'message' => 'No se pudo abrir el archivo de respuestas.'], 500);
    }

    if (!flock($file, LOCK_EX)) {
        fclose($file);
        json_response(['success' => false, 'message' => 'No se pudo bloquear el archivo de respuestas.'], 500);
    }

    $rows = read_csv_rows_locked($file);
    $updated = false;

    foreach ($rows as &$row) {
        if (
            (string)($row['round'] ?? '') === (string)$round &&
            (string)($row['panelist'] ?? '') === $panelist &&
            (string)($row['question'] ?? '') === (string)$question
        ) {
            $createdAt = $row['created_at'] ?? $now;
            $row = array_merge($newRow, ['created_at' => $createdAt]);
            $updated = true;
            break;
        }
    }
    unset($row);

    if (!$updated) {
        $rows[] = $newRow;
    }

    rewind($file);
    ftruncate($file, 0);
    fputcsv($file, $CSV_HEADERS);

    foreach ($rows as $row) {
        $line = [];
        foreach ($CSV_HEADERS as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($file, $line);
    }

    fflush($file);
    flock($file, LOCK_UN);
    fclose($file);

    json_response([
        'success' => true,
        'message' => $updated ? 'Respuesta actualizada.' : 'Respuesta guardada.',
        'updated' => $updated
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_get();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post();
}

json_response(['success' => false, 'message' => 'Método no permitido.'], 405);
?>
