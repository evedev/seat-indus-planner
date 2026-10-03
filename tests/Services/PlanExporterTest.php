<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Tests\Services;

use EveDev\Seat\IndusPlanner\Services\PlanExporter;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

/**
 * Excel export of the production plan: sheets, bands, formats, and the
 * checks on the content sent by the browser.
 */
class PlanExporterTest extends TestCase
{
    private function payload(array $rows = [[true, 'Ferrogel', 3, 10800]]): array
    {
        return [
            'title' => 'Ishtar × 1',
            'sheets' => [
                [
                    'name' => 'Jobs to start',
                    'columns' => [
                        ['label' => 'Done', 'type' => 'check'],
                        ['label' => 'Item', 'type' => 'text', 'width' => 40],
                        ['label' => 'Runs', 'type' => 'int'],
                        ['label' => 'Duration', 'type' => 'duration'],
                    ],
                    'sections' => [['title' => 'Rank 2', 'color' => '#6a5acd', 'rows' => $rows]],
                    'footer' => '1 job',
                ],
                [
                    'name' => 'Materials: to buy?',
                    'columns' => [['label' => 'Material', 'type' => 'text']],
                    'sections' => [],
                ],
            ],
        ];
    }

    public function test_one_sheet_per_table_with_safe_names(): void
    {
        $book = (new PlanExporter())->build($this->payload());
        $this->assertSame(['Jobs to start', 'Materials  to buy'], $book->getSheetNames());
    }

    public function test_section_is_a_colored_band_followed_by_its_headers(): void
    {
        $ws = (new PlanExporter())->build($this->payload())->getSheet(0);
        $this->assertSame('Ishtar × 1 — Jobs to start', $ws->getCell('A1')->getValue());
        $this->assertArrayHasKey('A3:D3', $ws->getMergeCells());
        $this->assertSame('6A5ACD', $ws->getStyle('A3')->getFill()->getStartColor()->getRGB());
        $this->assertSame(['Done', 'Item', 'Runs', 'Duration'], $ws->rangeToArray('A4:D4')[0]);
        $this->assertSame(40.0, $ws->getColumnDimension('B')->getWidth());
    }

    public function test_values_keep_their_type_and_format(): void
    {
        $ws = (new PlanExporter())->build($this->payload())->getSheet(0);
        $this->assertSame('✔', $ws->getCell('A5')->getValue());
        $this->assertSame(3.0, (float) $ws->getCell('C5')->getValue());
        $this->assertSame('#,##0', $ws->getStyle('C5')->getNumberFormat()->getFormatCode());
        $this->assertEqualsWithDelta(0.125, $ws->getCell('D5')->getValue(), 1e-9);
        $this->assertSame('[h]:mm:ss', $ws->getStyle('D5')->getNumberFormat()->getFormatCode());
        $this->assertSame('1 job', $ws->getCell('A7')->getValue());
    }

    public function test_text_starting_with_equal_never_becomes_a_formula(): void
    {
        $ws = (new PlanExporter())->build($this->payload([[false, '=HYPERLINK("x")', 1, null]]))->getSheet(0);
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('B5')->getDataType());
        $this->assertNull($ws->getCell('D5')->getValue());
    }

    public function test_unexpected_content_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlanExporter())->build(['sheets' => array_fill(0, 5, ['columns' => [['type' => 'text']]])]);
    }
}
