<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel workbook of a production plan, one sheet per table, laid out like
 * the page: a colored band per rank or family, its column headers, then
 * its rows. The browser sends the tables as displayed (done jobs, stock
 * deducted or not); this class only checks and lays them out.
 */
class PlanExporter
{
    private const MAX_SHEETS = 4;
    private const MAX_COLUMNS = 20;
    private const MAX_ROWS = 10000;
    private const MAX_TEXT = 500;

    /** Number formats by column type; durations are stored as days. */
    private const FORMATS = [
        'int' => '#,##0',
        'dec1' => '#,##0.0',
        'dec2' => '#,##0.00',
        'isk' => '#,##0',
        'duration' => '[h]:mm:ss',
    ];

    private const DEFAULT_BAND = '3B4A6B';

    public function build(array $data): Spreadsheet
    {
        $sheets = $data['sheets'] ?? null;
        if (! is_array($sheets) || $sheets === [] || count($sheets) > self::MAX_SHEETS)
            throw new InvalidArgumentException('sheets');

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $rowBudget = self::MAX_ROWS;
        foreach (array_values($sheets) as $index => $sheet) {
            $this->fillSheet($book->createSheet($index), (array) $sheet, (string) ($data['title'] ?? ''), $rowBudget);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function fillSheet(Worksheet $ws, array $sheet, string $title, int &$rowBudget): void
    {
        $columns = array_values((array) ($sheet['columns'] ?? []));
        if ($columns === [] || count($columns) > self::MAX_COLUMNS)
            throw new InvalidArgumentException('columns');

        $ws->setTitle($this->sheetName((string) ($sheet['name'] ?? 'Sheet')));
        $last = Coordinate::stringFromColumnIndex(count($columns));
        foreach ($columns as $i => $column) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))
                ->setWidth(max(4, min(80, (float) ($column['width'] ?? 12))));
        }

        $row = 1;
        $this->text($ws, 'A' . $row, $title !== '' ? $title . ' — ' . ($sheet['name'] ?? '') : ($sheet['name'] ?? ''));
        $ws->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row += 2;

        foreach ((array) ($sheet['sections'] ?? []) as $section) {
            $section = (array) $section;

            // Colored band: rank or family, with its figures.
            $ws->mergeCells("A{$row}:{$last}{$row}");
            $this->text($ws, 'A' . $row, (string) ($section['title'] ?? ''));
            $band = $ws->getStyle("A{$row}:{$last}{$row}");
            $band->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->color($section['color'] ?? null) ?? self::DEFAULT_BAND);
            $band->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $row++;

            // Column headers of the section.
            foreach ($columns as $i => $column) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
                $this->text($ws, $cell, (string) ($column['label'] ?? ''));
                if ($this->isNumeric($column))
                    $ws->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $head = $ws->getStyle("A{$row}:{$last}{$row}");
            $head->getFont()->setBold(true);
            $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
            $head->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('ADB5BD');
            $row++;

            $first = $row;
            foreach ((array) ($section['rows'] ?? []) as $n => $cells) {
                if (--$rowBudget < 0)
                    throw new InvalidArgumentException('rows');
                foreach ($columns as $i => $column) {
                    $this->cell($ws, Coordinate::stringFromColumnIndex($i + 1) . $row, $column, ((array) $cells)[$i] ?? null);
                }
                if ($n % 2 === 1) {
                    $ws->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F6F8');
                }
                $row++;
            }
            if ($row > $first) {
                $ws->getStyle("A{$first}:{$last}" . ($row - 1))->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DEE2E6');
            }
            $row++;
        }

        if (! empty($sheet['footer'])) {
            $this->text($ws, 'A' . $row, (string) $sheet['footer']);
            $ws->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB('6C757D');
        }
    }

    /** A cell: a value, or {v, c} for a value with a text color. */
    private function cell(Worksheet $ws, string $coordinate, array $column, $cell): void
    {
        $color = null;
        if (is_array($cell)) {
            $color = $this->color($cell['c'] ?? null);
            $cell = $cell['v'] ?? null;
        }
        if ($cell === null || $cell === '')
            return;

        $type = (string) ($column['type'] ?? 'text');
        $style = $ws->getStyle($coordinate);
        if ($type === 'check') {
            $this->text($ws, $coordinate, $cell ? '✔' : '');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        } elseif (isset(self::FORMATS[$type]) && is_numeric($cell)) {
            $value = $type === 'duration' ? (float) $cell / 86400 : (float) $cell;
            $ws->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
            $style->getNumberFormat()->setFormatCode(self::FORMATS[$type]);
        } else {
            $this->text($ws, $coordinate, (string) $cell);
        }
        if (! empty($column['bold']))
            $style->getFont()->setBold(true);
        if ($color !== null)
            $style->getFont()->getColor()->setRGB($color);
    }

    // Always written as text: a value starting with "=" must not become a
    // formula.
    private function text(Worksheet $ws, string $coordinate, string $value): void
    {
        $ws->setCellValueExplicit($coordinate, mb_substr($value, 0, self::MAX_TEXT), DataType::TYPE_STRING);
    }

    private function isNumeric(array $column): bool
    {
        return isset(self::FORMATS[(string) ($column['type'] ?? 'text')]);
    }

    private function color($value): ?string
    {
        return is_string($value) && preg_match('/^#?([0-9a-fA-F]{6})$/', $value, $m) ? strtoupper($m[1]) : null;
    }

    // Excel sheet names: 31 characters at most, without : \ / ? * [ ].
    private function sheetName(string $name): string
    {
        $name = trim(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name));

        return mb_substr($name !== '' ? $name : 'Sheet', 0, 31);
    }
}
