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
        $date = preg_replace('/\D+/', '', $query) ?? '';
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        try {
            return response()->json([
                'query' => $query,
                'normalized_query' => $date,
                'rows' => $this->search($clickHouse, $date, $limit),
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
    private function search(ClickHouseClient $clickHouse, string $date, int $limit): array
    {
        $where = [];
        $parameters = ['limit' => $limit];

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
}
