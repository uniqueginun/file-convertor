<?php

namespace App\Support;

use Illuminate\Support\Str;

class RandomPdfGenerator
{
    /**
     * Build a minimal valid PDF with unique random text content.
     */
    public function generate(): string
    {
        $randomText = $this->escapePdfString(
            Str::uuid()->toString().' '.Str::random(24).' '.fake()->words(random_int(3, 12), true)
        );

        $stream = "BT /F1 12 Tf 72 720 Td ({$randomText}) Tj ET";
        $streamLength = strlen($stream);

        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj',
            "4 0 obj<</Length {$streamLength}>>stream\n{$stream}\nendstream\nendobj",
            '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xrefOffset = strlen($pdf);
        $objectCount = count($objects) + 1;

        $pdf .= "xref\n0 {$objectCount}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index < $objectCount; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        $pdf .= "trailer<</Size {$objectCount}/Root 1 0 R>>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function escapePdfString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
