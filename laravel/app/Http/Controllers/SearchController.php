<?php

namespace App\Http\Controllers;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SearchController extends Controller
{
    public function __invoke(Request $request, ClickHouseClient $clickHouse): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 500);
        $intent = $this->intent($query);

        try {
            return response()->json([
                'query' => $query,
                'normalized_query' => $intent['date'],
                'type' => $intent['type'],
                ...$this->search($clickHouse, $intent, $limit),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'ClickHouse unavailable.',
            ], 503);
        }
    }

    /**
     * @return array{summary: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function search(ClickHouseClient $clickHouse, array $intent, int $limit): array
    {
        if ($intent['type'] === 'bz_average') {
            return $this->averageBz($clickHouse, $intent);
        }

        if ($intent['type'] === 'bz_extreme') {
            return $this->extremeBz($clickHouse, $intent, $limit);
        }

        if ($intent['type'] === 'bz_threshold') {
            return $this->thresholdBz($clickHouse, $intent, $limit);
        }

        return [
            'summary' => null,
            'rows' => $this->dateRows($clickHouse, $intent['date'], $limit),
        ];
    }

    /**
     * @return array{type: string, date: string, direction?: string, operator?: string, value?: float}
     */
    private function intent(string $query): array
    {
        $date = $this->dateFromQuery($query);
        $text = $this->normalizedText($query);

        if (str_contains($text, 'bz') && preg_match('/\bmoyenn?e?\b/', $text) === 1) {
            return [
                'type' => 'bz_average',
                'date' => $date,
            ];
        }

        if (str_contains($text, 'bz') && preg_match('/\b(maximum|maximal|plus haut|plus eleve|pic)\b/', $text) === 1) {
            return [
                'type' => 'bz_extreme',
                'date' => $date,
                'direction' => 'DESC',
            ];
        }

        if (str_contains($text, 'bz') && preg_match('/\b(minimum|minimal|plus bas|plus faible)\b/', $text) === 1) {
            return [
                'type' => 'bz_extreme',
                'date' => $date,
                'direction' => 'ASC',
            ];
        }

        $threshold = $this->thresholdFromQuery($text);

        if (str_contains($text, 'bz') && $threshold !== null) {
            return [
                'type' => 'bz_threshold',
                'date' => $date,
                ...$threshold,
            ];
        }

        return [
            'type' => 'date_rows',
            'date' => $date,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function averageBz(ClickHouseClient $clickHouse, array $intent): array
    {
        [$whereSql, $parameters] = $this->dateWhere($intent['date']);

        $rows = $clickHouse->select(<<<SQL
SELECT
    round(avg(bz), 4) AS bz_average,
    count(bz) AS samples,
    formatDateTime(min(measured_at), '%Y-%m-%d %H:%i:%S') AS period_start,
    formatDateTime(max(measured_at), '%Y-%m-%d %H:%i:%S') AS period_end
FROM measurements
{$whereSql}
SQL, $parameters);

        return [
            'summary' => [
                'kind' => 'bz_average',
                'date' => $intent['date'],
                'bz_average' => $rows[0]['bz_average'] ?? null,
                'samples' => (int) ($rows[0]['samples'] ?? 0),
                'period_start' => $rows[0]['period_start'] ?? null,
                'period_end' => $rows[0]['period_end'] ?? null,
            ],
            'rows' => [],
        ];
    }

    /**
     * @return array{summary: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function extremeBz(ClickHouseClient $clickHouse, array $intent, int $limit): array
    {
        [$whereSql, $parameters] = $this->dateWhere($intent['date'], ['bz IS NOT NULL']);
        $parameters['limit'] = $limit;
        $direction = $intent['direction'] === 'ASC' ? 'ASC' : 'DESC';

        return [
            'summary' => [
                'kind' => $direction === 'ASC' ? 'bz_minimum' : 'bz_maximum',
                'date' => $intent['date'],
            ],
            'rows' => $this->measurementRows($clickHouse, $whereSql, $parameters, "bz {$direction}, measured_at ASC"),
        ];
    }

    /**
     * @return array{summary: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function thresholdBz(ClickHouseClient $clickHouse, array $intent, int $limit): array
    {
        $operator = $this->operator($intent['operator']);
        [$whereSql, $parameters] = $this->dateWhere($intent['date'], ["bz {$operator} :bz_value"]);
        $parameters['bz_value'] = $intent['value'];
        $parameters['limit'] = $limit;

        return [
            'summary' => [
                'kind' => 'bz_threshold',
                'date' => $intent['date'],
                'operator' => $operator,
                'value' => $intent['value'],
            ],
            'rows' => $this->measurementRows($clickHouse, $whereSql, $parameters, 'measured_at ASC'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dateRows(ClickHouseClient $clickHouse, string $date, int $limit): array
    {
        [$whereSql, $parameters] = $this->dateWhere($date);
        $parameters['limit'] = $limit;
        $order = $date === '' ? 'measured_at DESC' : 'measured_at ASC';

        return $this->measurementRows($clickHouse, $whereSql, $parameters, $order);
    }

    /**
     * @param  array<int, string>  $extraWhere
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function dateWhere(string $date, array $extraWhere = []): array
    {
        $where = $extraWhere;
        $parameters = [];

        if ($date !== '') {
            $where[] = 'startsWith(date_raw, :date)';
            $parameters['date'] = $date;
        }

        if (strlen($date) >= 4) {
            $where[] = 'source_year = :year';
            $parameters['year'] = (int) substr($date, 0, 4);
        }

        if (strlen($date) >= 6) {
            $where[] = 'source_month = :month';
            $parameters['month'] = (int) substr($date, 4, 2);
        }

        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);

        return [$whereSql, $parameters];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function measurementRows(ClickHouseClient $clickHouse, string $whereSql, array $parameters, string $order): array
    {
        return $clickHouse->select(<<<SQL
SELECT
    date_raw,
    formatDateTime(measured_at, '%Y-%m-%d %H:%i:%S') AS measured_at,
    speed,
    density,
    bt,
    bz
FROM measurements
{$whereSql}
ORDER BY {$order}
LIMIT :limit
SQL, $parameters);
    }

    private function dateFromQuery(string $query): string
    {
        $text = $this->normalizedText($query);
        $months = [
            'janvier' => '01',
            'fevrier' => '02',
            'mars' => '03',
            'avril' => '04',
            'mai' => '05',
            'juin' => '06',
            'juillet' => '07',
            'aout' => '08',
            'septembre' => '09',
            'octobre' => '10',
            'novembre' => '11',
            'decembre' => '12',
        ];

        if (preg_match('/\b(\d{1,2})\s+('.implode('|', array_keys($months)).')\s+(\d{4})\b/', $text, $match) === 1) {
            return sprintf('%04d%s%02d', (int) $match[3], $months[$match[2]], (int) $match[1]);
        }

        if (preg_match('/\b(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $text, $match) === 1) {
            return sprintf('%04d%02d%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        }

        if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})\b/', $text, $match) === 1) {
            return sprintf('%04d%02d%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        return preg_replace('/\D+/', '', $query) ?? '';
    }

    /**
     * @return array{operator: string, value: float}|null
     */
    private function thresholdFromQuery(string $text): ?array
    {
        if (preg_match('/(<=|>=|<|>|=)\s*(-?\d+(?:[\.,]\d+)?)/', $text, $match) === 1) {
            return [
                'operator' => $match[1],
                'value' => (float) str_replace(',', '.', $match[2]),
            ];
        }

        if (preg_match('/\b(superieur|au dessus|plus grand|plus haut|inferieur|au dessous|plus petit|plus bas|egal)\b.*?(-?\d+(?:[\.,]\d+)?)/', $text, $match) !== 1) {
            return null;
        }

        $operator = match ($match[1]) {
            'superieur', 'au dessus', 'plus grand', 'plus haut' => '>',
            'inferieur', 'au dessous', 'plus petit', 'plus bas' => '<',
            default => '=',
        };

        return [
            'operator' => $operator,
            'value' => (float) str_replace(',', '.', $match[2]),
        ];
    }

    private function operator(string $operator): string
    {
        return in_array($operator, ['<', '<=', '=', '>=', '>'], true) ? $operator : '=';
    }

    private function normalizedText(string $query): string
    {
        return strtr(mb_strtolower($query), [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
        ]);
    }
}
