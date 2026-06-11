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
        $date = $this->dateFromQuery($query);
        $mode = $this->mode((string) $request->query('mode', 'raw'));
        $operator = $this->operator((string) $request->query('bz_operator', '<'));
        $bzValue = (float) $request->query('bz_value', -40);
        $bucketHours = $this->bucketHours((int) $request->query('bucket_hours', 12));
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        try {
            return response()->json([
                'query' => $query,
                'normalized_query' => $date,
                'mode' => $mode,
                'rows' => $this->search($clickHouse, $date, $mode, $operator, $bzValue, $bucketHours, $limit),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'ClickHouse unavailable.',
            ], 503);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(
        ClickHouseClient $clickHouse,
        string $date,
        string $mode,
        string $operator,
        float $bzValue,
        int $bucketHours,
        int $limit,
    ): array {
        return match ($mode) {
            'bz_average' => $this->averageBz($clickHouse, $date),
            'bz_threshold' => $this->thresholdBz($clickHouse, $date, $operator, $bzValue, $limit),
            'speed_12h_average' => $this->averageSpeedByHourBucket($clickHouse, $date, $bucketHours, $limit),
            default => $this->dateRows($clickHouse, $date, $limit),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dateRows(ClickHouseClient $clickHouse, string $date, int $limit): array
    {
        [$whereSql, $parameters] = $this->dateWhere($date);
        $parameters['limit'] = $limit;
        $order = $date === '' ? 'DESC' : 'ASC';

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
ORDER BY measured_at {$order}
LIMIT :limit
SQL, $parameters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function averageBz(ClickHouseClient $clickHouse, string $date): array
    {
        [$whereSql, $parameters] = $this->dateWhere($date, ['bz IS NOT NULL']);

        return $clickHouse->select(<<<SQL
SELECT
    formatDateTime(min(measured_at), '%Y-%m-%d %H:%i:%S') AS period_start,
    formatDateTime(max(measured_at), '%Y-%m-%d %H:%i:%S') AS period_end,
    round(avg(bz), 4) AS bz_average,
    count(bz) AS samples
FROM measurements
{$whereSql}
SQL, $parameters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function thresholdBz(ClickHouseClient $clickHouse, string $date, string $operator, float $bzValue, int $limit): array
    {
        [$whereSql, $parameters] = $this->dateWhere($date, ["bz {$operator} :bz_value"]);
        $parameters['bz_value'] = $bzValue;
        $parameters['limit'] = $limit;

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
ORDER BY measured_at ASC
LIMIT :limit
SQL, $parameters);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function averageSpeedByHourBucket(ClickHouseClient $clickHouse, string $date, int $bucketHours, int $limit): array
    {
        [$whereSql, $parameters] = $this->dateWhere($date, ['speed IS NOT NULL']);
        $parameters['limit'] = $limit;

        return $clickHouse->select(<<<SQL
SELECT
    formatDateTime(toStartOfInterval(measured_at, INTERVAL {$bucketHours} HOUR), '%Y-%m-%d %H:%i:%S') AS bucket_start,
    formatDateTime(toStartOfInterval(measured_at, INTERVAL {$bucketHours} HOUR) + INTERVAL {$bucketHours} HOUR, '%Y-%m-%d %H:%i:%S') AS bucket_end,
    round(avg(speed), 4) AS speed_average,
    count(speed) AS samples
FROM measurements
{$whereSql}
GROUP BY bucket_start, bucket_end
ORDER BY bucket_start ASC
LIMIT :limit
SQL, $parameters);
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

        return [$where === [] ? '' : 'WHERE '.implode(' AND ', $where), $parameters];
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

    private function mode(string $mode): string
    {
        return in_array($mode, ['raw', 'bz_average', 'bz_threshold', 'speed_12h_average'], true) ? $mode : 'raw';
    }

    private function operator(string $operator): string
    {
        return in_array($operator, ['<', '<=', '=', '>=', '>'], true) ? $operator : '<';
    }

    private function bucketHours(int $bucketHours): int
    {
        return in_array($bucketHours, [1, 3, 6, 12, 24], true) ? $bucketHours : 12;
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
