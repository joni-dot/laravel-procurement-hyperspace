<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UploadsDiskTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.uploads' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');
        $this->supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());
    }

    public function test_documents_are_stored_read_and_deleted_on_the_configured_disk()
    {
        $this->post(route('suppliers.documents.store', $this->supplier), [
            'file' => UploadedFile::fake()->createWithContent('prices.pdf', '%PDF-1.4 prices'),
        ])->assertSessionHasNoErrors();

        $document = Document::sole();
        Storage::disk('s3')->assertExists($document->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->get(route('suppliers.documents.download', [$this->supplier, $document]))
            ->assertOk()
            ->assertStreamedContent('%PDF-1.4 prices');

        $this->delete(route('suppliers.documents.destroy', [$this->supplier, $document]));
        Storage::disk('s3')->assertMissing($document->file_path);
    }

    public function test_certificate_files_are_stored_read_and_replaced_on_the_configured_disk()
    {
        $this->post(route('suppliers.certificates.store', $this->supplier), [
            ...$this->certificateInput(),
            'file' => UploadedFile::fake()->createWithContent('iso.pdf', '%PDF-1.4 first'),
        ])->assertSessionHasNoErrors();

        $certificate = Certificate::sole();
        $firstPath = (string) $certificate->file_path;
        Storage::disk('s3')->assertExists($firstPath);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->get(route('suppliers.certificates.download', [$this->supplier, $certificate]))
            ->assertOk()
            ->assertStreamedContent('%PDF-1.4 first');

        $this->put(route('suppliers.certificates.update', [$this->supplier, $certificate]), [
            ...$this->certificateInput(),
            'file' => UploadedFile::fake()->createWithContent('iso-renewed.pdf', '%PDF-1.4 second'),
        ])->assertSessionHasNoErrors();

        Storage::disk('s3')->assertMissing($firstPath);
        Storage::disk('s3')->assertExists((string) $certificate->refresh()->file_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateInput(): array
    {
        return [
            'certificate_type_id' => CertificateType::query()->firstOr(fn () => CertificateType::factory()->create())->id,
            'reference' => 'QMS-123',
            'issued_on' => today()->subYear()->toDateString(),
            'expires_on' => today()->addDays(100)->toDateString(),
            'reminder_days' => [14],
        ];
    }
}
