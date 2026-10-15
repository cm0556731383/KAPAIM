<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The "ייצוא לאקסל" download on ⚡expenses.blade.php — every (non-deleted)
 * expense with its supplier, program, status and invoice details, as one
 * right-to-left sheet with a bold frozen header row, filters, ₪-formatted
 * amounts, real Excel dates and a total row at the bottom.
 */
class ExpensesSpreadsheet
{
    private const DATE_FORMAT = 'dd/mm/yyyy';

    private const MONEY_FORMAT = '"₪"#,##0.00';

    /** Column header => [width, cell value for an expense, number format]. */
    private function columns(): array
    {
        return [
            'מס׳ הוצאה' => [11, fn (Expense $e) => $e->id, null],
            'תאריך' => [12, fn (Expense $e) => ExcelDate::PHPToExcel($e->expense_date), self::DATE_FORMAT],
            'ספק' => [26, fn (Expense $e) => $e->supplier?->name, null],
            'ח.פ / ע.מ ספק' => [15, fn (Expense $e) => $e->supplier?->company_number, null],
            'סיווג ספק' => [16, fn (Expense $e) => $e->supplier?->classification, null],
            'טלפון ספק' => [14, fn (Expense $e) => $e->supplier?->phone, null],
            'אימייל ספק' => [26, fn (Expense $e) => $e->supplier?->email, null],
            'תוכנית' => [22, fn (Expense $e) => $e->program?->name, null],
            'סכום' => [13, fn (Expense $e) => (float) $e->amount, self::MONEY_FORMAT],
            'סטטוס' => [16, fn (Expense $e) => $e->status?->name, null],
            'חשבונית' => [11, fn (Expense $e) => $e->hasInvoiceFile() ? 'יש' : 'חסרה', null],
            'שם קובץ החשבונית' => [28, fn (Expense $e) => $e->hasInvoiceFile() ? $e->invoiceFileName() : null, null],
            'תאריך העלאת חשבונית' => [16, fn (Expense $e) => $e->hasInvoiceFile() && $e->document->received_at ? ExcelDate::PHPToExcel($e->document->received_at) : null, self::DATE_FORMAT],
            'הערות' => [40, fn (Expense $e) => $e->notes, null],
            'נרשמה בתאריך' => [14, fn (Expense $e) => ExcelDate::PHPToExcel($e->created_at), self::DATE_FORMAT],
        ];
    }

    public function expenses(): Collection
    {
        return Expense::with(['supplier', 'program', 'status', 'document'])
            ->orderByDesc('expense_date')->orderByDesc('id')->get();
    }

    /** Renders the workbook and returns the raw .xlsx bytes. */
    public function render(?Collection $expenses = null): string
    {
        $expenses ??= $this->expenses();
        $columns = $this->columns();
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $lastRow = $expenses->count() + 1;

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('הוצאות')->setCreator('כפיים');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('הוצאות');
        $sheet->setRightToLeft(true);

        $index = 1;
        foreach ($columns as $header => [$width, $value, $format]) {
            $letter = Coordinate::stringFromColumnIndex($index++);
            $sheet->setCellValue("{$letter}1", $header);
            $sheet->getColumnDimension($letter)->setWidth($width);

            foreach ($expenses->values() as $i => $expense) {
                $sheet->setCellValue($letter.($i + 2), $value($expense));
            }

            if ($format && $lastRow > 1) {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
            }
        }

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2F5D8A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D7DE']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getStyle("N2:N{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

        // Total row — a live SUM, so it still adds up after filtering out nothing / editing a cell.
        $totalRow = $lastRow + 2;
        $sheet->setCellValue("H{$totalRow}", 'סה״כ');
        $sheet->setCellValue("I{$totalRow}", $lastRow > 1 ? "=SUM(I2:I{$lastRow})" : 0);
        $sheet->getStyle("I{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle("H{$totalRow}:I{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM]],
        ]);

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }
}
