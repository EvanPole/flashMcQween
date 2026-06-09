<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class ImportDataToClickHouse extends Command
{
    protected $signature = 'clickhouse:import-data
        {path=data : Directory containing yearly CSV folders, or a single CSV file}
        {--table=measurements : Destination ClickHouse table}
        {--fresh : Truncate the destination table before importing}
        {--timeout=300 : HTTP timeout, in seconds, for each CSV insert}';

    protected $description = 'Import Date;Speed;Density;Bt;Bz CSV data into ClickHouse.';

    public function handle(ClickHouseClient $clickHouse): int
    {
        $path = $this->argument('path');
        $table = $this->identifier((string) $this->option('table'));
        $timeout = (int) $this->option('timeout');

        $absolutePath = $this->absolutePath($path);
        $files = $this->csvFiles($absolutePath);

        if ($files === []) {
            $this->components->error(sprintf('No CSV files found in [%s].', $absolutePath));

            return self::FAILURE;
        }

        $this->createTable($clickHouse, $table);

        if ($this->option('fresh')) {
            $clickHouse->statement(sprintf('TRUNCATE TABLE %s', $table));
        }

        $this->components->info(sprintf(
            'Importing %d CSV files into ClickHouse table [%s].',
            count($files),
            $table,
        ));

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            $clickHouse->insertCsvFile(
                table: $table,
                path: $file->getPathname(),
                columns: ['date_raw', 'speed', 'density', 'bt', 'bz'],
                settings: [
                    'format_csv_delimiter' => ';',
                    'input_format_csv_empty_as_default' => 1,
                ],
                timeout: $timeout,
                hasHeader: true,
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $rows = $clickHouse->select(sprintf('SELECT count() AS rows FROM %s', $table));
        $rowCount = (int) ($rows[0]['rows'] ?? 0);

        $this->components->info(sprintf(
            'Done. %s rows are now available in [%s].',
            Number::format($rowCount),
            $table,
        ));

        return self::SUCCESS;
    }

    private function createTable(ClickHouseClient $clickHouse, string $table): void
    {
        $clickHouse->statement(<<<SQL
CREATE TABLE IF NOT EXISTS {$table}
(
    date_raw String,
    measured_at DateTime MATERIALIZED makeDateTime(
        toUInt16(substring(date_raw, 1, 4)),
        toUInt8(substring(date_raw, 5, 2)),
        toUInt8(substring(date_raw, 7, 2)),
        toUInt8(substring(date_raw, 9, 2)),
        toUInt8(substring(date_raw, 11, 2)),
        toUInt8(substring(date_raw, 13, 2))
    ),
    speed Nullable(Float64),
    density Nullable(Float64),
    bt Nullable(Float64),
    bz Nullable(Float64),
    source_year UInt16 MATERIALIZED toUInt16(substring(date_raw, 1, 4)),
    source_month UInt8 MATERIALIZED toUInt8(substring(date_raw, 5, 2)),
    imported_at DateTime DEFAULT now()
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(measured_at)
ORDER BY (measured_at, date_raw)
SQL);
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function csvFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.csv') ? [new SplFileInfo($path)] : [];
        }

        if (! is_dir($path)) {
            throw new RuntimeException(sprintf('Path [%s] does not exist.', $path));
        }

        $files = iterator_to_array(
            Finder::create()
                ->files()
                ->name('*.csv')
                ->in($path)
                ->sortByName(),
            false,
        );

        return array_values($files);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function identifier(string $identifier): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $identifier)) {
            throw new RuntimeException('Invalid ClickHouse table name.');
        }

        return $identifier;
    }
}
