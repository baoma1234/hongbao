<?php

namespace app\admin\library\traits;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait FanshubExport
{
    protected function exportXlsx($filename, array $headers, array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $header);
        }
        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($row as $cell) {
                $sheet->setCellValueByColumnAndRow($col++, $rowNum, $cell);
            }
            $rowNum++;
        }
        $filename = preg_replace('/[^\w\-\.]+/u', '_', $filename);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    protected function exportQueryRows($query, $limit = 10000)
    {
        return $query->limit($limit)->select();
    }
}
