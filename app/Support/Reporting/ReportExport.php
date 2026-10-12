<?php

namespace App\Support\Reporting;

use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

final class ReportExport
{
    public const MAX_BYTES = 26214400;

    public const MAX_SECONDS = 30;

    public const MAX_MEMORY = 134217728;

    public static function directory(): string
    {
        return storage_path('app/private/report-tmp');
    }

    /** @param array<string,mixed> $report */
    public static function write(array $report, string $format): string
    {
        $start = microtime(true);
        $memory = memory_get_usage(true);
        memory_reset_peak_usage();
        $dir = self::directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        self::cleanup();
        $path = tempnam($dir, 'report-');
        if ($path === false) {
            throw new \RuntimeException('report_storage');
        }
        chmod($path, 0600);
        $writer = null;
        $csv = null;
        try {
            $columns = $report['columns'];
            $metadata = json_encode($report['meta'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($format === 'csv') {
                $csv = fopen($path, 'wb');
                if ($csv === false) {
                    throw new \RuntimeException('report_storage');
                }
                fwrite($csv, "\xEF\xBB\xBF");
                fputcsv($csv, ['_row_kind', ...array_map(fn ($key) => $key.' · '.__('reporting.columns.'.$key), $columns), 'report_metadata', 'comparison_metadata'], ',', '"', '', "\r\n");
                fputcsv($csv, ['metadata', ...array_fill(0, count($columns), ''), self::csv($metadata), self::csv(json_encode($report['comparison'], JSON_THROW_ON_ERROR))], ',', '"', '', "\r\n");
            } else {
                $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 22, tempFolder: $dir));
                $writer->openToFile($path);
                $sheet = $writer->getCurrentSheet();
                $sheet->setName('Report');
                $sheet->setSheetView(new SheetView(freezeRow: 2));
                $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, count($columns) - 1), max(2, count($report['rows']) + 1)));
                $writer->addRow(new Row(array_map(fn ($v) => new StringCell($v.' · '.__('reporting.columns.'.$v), new Style(fontBold: true)), $columns)));
            }
            foreach ($report['rows'] as $row) {
                $values = array_map(fn ($key) => str_ends_with($key, '_minor') && isset($row[$key]) && strlen(ltrim((string) $row[$key], '-')) <= 15 ? (int) $row[$key] : ($row[$key] ?? null), $columns);
                if (is_resource($csv)) {
                    fputcsv($csv, ['data', ...array_map(fn ($value, $key) => str_ends_with($key, '_minor') && is_string($value) && preg_match('/^-?[0-9]+$/D', $value) ? $value : self::csv($value), $values, $columns), self::csv($metadata), ''], ',', '"', '', "\r\n");
                } elseif ($writer !== null) {
                    $writer->addRow(new Row(array_map(self::cell(...), $values)));
                }
                self::budget($start, $memory, $path);
            }
            if (is_resource($csv)) {
                fclose($csv);
                $csv = null;
            }
            if ($writer !== null) {
                $writer->addNewSheetAndMakeItCurrent()->setName('Metadata');
                foreach ($report['meta'] as $k => $v) {
                    $writer->addRow(new Row([new StringCell($k), new StringCell(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]));
                }
                $writer->addNewSheetAndMakeItCurrent()->setName('Totals');
                foreach ($report['totals'] as $k => $v) {
                    $writer->addRow(new Row([new StringCell($k), self::cell($v)]));
                }
                if ($report['comparison'] !== null) {
                    $writer->addNewSheetAndMakeItCurrent()->setName('Comparison');
                    $writer->addRow(new Row([new StringCell('metric'), new StringCell('current'), new StringCell('previous'), new StringCell('absolute'), new StringCell('relative_percent'), new StringCell('percentage_points')]));
                    foreach ($report['comparison']['changes'] as $k => $v) {
                        $writer->addRow(new Row([new StringCell($k), ...array_map(self::cell(...), array_values($v))]));
                    }
                }
                $writer->close();
                $writer = null;
            }
            self::budget($start, $memory, $path);

            return $path;
        } catch (Throwable $e) {
            if (is_resource($csv)) {
                fclose($csv);
            }
            if ($writer !== null) {
                try {
                    $writer->close();
                } catch (Throwable) { /* Preserve the original failure and remove the private output. */
                }
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    public static function csv(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = (string) $value;

        // Every string is literal: also defeat control/whitespace-prefix formula bypasses.
        return preg_match('/^[\s\x00-\x20\x7f]*[=+@\-]/u', $text) || preg_match('/^[\t\r\n]/', $text) ? "'".$text : $text;
    }

    private static function cell(mixed $value): Cell
    {
        if (is_int($value) || is_float($value)) {
            return abs($value) <= 999999999999999 ? new NumericCell($value) : new StringCell((string) $value);
        }

        return new StringCell($value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
    }

    private static function budget(float $start, int $memory, string $path): void
    {
        clearstatcache(true, $path);
        abort_if(microtime(true) - $start > self::MAX_SECONDS || memory_get_peak_usage(true) - $memory > self::MAX_MEMORY || filesize($path) > self::MAX_BYTES, 422, __('reporting.limit'));
    }

    public static function cleanup(): int
    {
        $count = 0;
        foreach (glob(self::directory().'/report-*') ?: [] as $path) {
            if (is_file($path) && ! is_link($path) && filemtime($path) < time() - 86400) {
                unlink($path);
                $count++;
            }
        }

        foreach (glob(self::directory().'/xlsx*') ?: [] as $path) {
            if (is_dir($path) && ! is_link($path) && filemtime($path) < time() - 86400) {
                File::deleteDirectory($path);
                $count++;
            }
        }

        return $count;
    }
}
