<?php

namespace Tests\Feature\Production;

use App\Files\Uploads;
use App\Models\User;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * When a record is gone or its file replaced, removing the old file is tidying up. S3 being briefly
 * down then must not turn a finished delete or save into an error page; the failure is reported.
 */
class LeftoverFileDeletesTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Uploads::diskName());
        $this->supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());
        Exceptions::fake();
    }

    public function test_deleting_a_document_succeeds_when_its_file_cannot_be_removed()
    {
        $document = Document::factory()->create(['supplier_id' => $this->supplier->id]);
        $this->storageCannotDelete();

        $this->delete(route('suppliers.documents.destroy', [$this->supplier, $document]))->assertRedirect();

        $this->assertModelMissing($document);
        $this->assertDeleteFailureWasReported();
    }

    public function test_deleting_a_contract_succeeds_when_its_files_cannot_be_removed()
    {
        $contract = Contract::factory()->for($this->supplier)->create();
        Document::factory()->create(['supplier_id' => $this->supplier->id, 'contract_id' => $contract->id]);
        $this->storageCannotDelete();

        $this->delete(route('suppliers.contracts.destroy', [$this->supplier, $contract]))->assertRedirect();

        $this->assertModelMissing($contract);
        $this->assertDeleteFailureWasReported();
    }

    public function test_deleting_a_certificate_succeeds_when_its_file_cannot_be_removed()
    {
        $certificate = Certificate::factory()->for($this->supplier)->create(['file_path' => 'certificates/x.pdf', 'file_name' => 'x.pdf']);
        $this->storageCannotDelete();

        $this->delete(route('suppliers.certificates.destroy', [$this->supplier, $certificate]))->assertRedirect();

        $this->assertModelMissing($certificate);
        $this->assertDeleteFailureWasReported();
    }

    public function test_replacing_a_certificate_file_succeeds_when_the_old_file_cannot_be_removed()
    {
        $type = CertificateType::factory()->create();
        $certificate = Certificate::factory()->for($this->supplier)->for($type, 'type')
            ->create(['file_path' => 'certificates/old.pdf', 'file_name' => 'old.pdf']);
        $this->storageCannotDelete();

        $this->put(route('suppliers.certificates.update', [$this->supplier, $certificate]), [
            'certificate_type_id' => $type->id,
            'reference' => 'QMS-123',
            'issued_on' => today()->subYear()->toDateString(),
            'expires_on' => today()->addDays(100)->toDateString(),
            'reminder_days' => [60, 14],
            'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('new.pdf', $certificate->refresh()->file_name);
        $this->assertDeleteFailureWasReported();
    }

    /**
     * The uploads disk keeps working, except that every delete fails as S3 does when it is down.
     */
    private function storageCannotDelete(): void
    {
        $disk = Storage::disk(Uploads::diskName());
        $this->assertInstanceOf(FilesystemAdapter::class, $disk);

        Storage::set(Uploads::diskName(), new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                throw UnableToDeleteFile::atLocation(implode(', ', (array) $paths), 'The storage service is unavailable.');
            }
        });
    }

    private function assertDeleteFailureWasReported(): void
    {
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(UnableToDeleteFile::class);
    }
}
