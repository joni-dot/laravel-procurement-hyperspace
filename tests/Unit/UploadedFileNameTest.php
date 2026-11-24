<?php

namespace Tests\Unit;

use App\Files\UploadedFileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UploadedFileNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'an ordinary name' => ['Price list 2026.pdf', 'Price list 2026.pdf'],
            'letters of other languages' => ['Hinnasto äö 価格.xlsx', 'Hinnasto äö 価格.xlsx'],
            'a right-to-left override' => ["report\u{202E}gnp.exe.pdf", 'reportgnp.exe.pdf'],
            'other invisible formatting' => ["a\u{200B}b\u{2066}c\u{FEFF}.pdf", 'abc.pdf'],
            'control characters' => ["line\nbreak\t\u{0000}.pdf", 'linebreak.pdf'],
            'nothing but invisible characters' => ["\u{202E}\u{200B}", 'file'],
            'broken UTF-8' => ["bad\xC3name.pdf", 'bad?name.pdf'],
        ];
    }

    #[DataProvider('names')]
    public function test_invisible_and_control_characters_are_removed(string $uploaded, string $kept)
    {
        $this->assertSame($kept, UploadedFileName::clean($uploaded));
    }

    public function test_a_long_name_is_shortened_before_its_extension()
    {
        $kept = UploadedFileName::clean(str_repeat('ä', 300).'.docx');

        $this->assertSame(UploadedFileName::MAX_LENGTH, mb_strlen($kept));
        $this->assertStringEndsWith('ä.docx', $kept);
    }

    public function test_a_long_ending_that_is_no_extension_is_simply_cut()
    {
        $kept = UploadedFileName::clean('v1.'.str_repeat('x', 300));

        $this->assertSame('v1.'.str_repeat('x', UploadedFileName::MAX_LENGTH - 3), $kept);
    }
}
