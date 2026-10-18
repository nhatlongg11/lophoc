<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnrollmentImportTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(User $admin): void
    {
        DB::table('api_tokens')->insert(['user_id' => $admin->id, 'organization_id' => $admin->organization_id, 'token_hash' => hash('sha256', 'import-token'), 'expires_at' => now()->addHour()]);
        $this->withToken('import-token');
    }

    public function test_csv_import_is_atomic_repeatable_and_export_blocks_spreadsheet_formula_injection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->asAdmin($admin);
        $content = "name,email,phone\nNgọc Anh,anh@example.test,+84901234567\n";
        $this->postJson('/api/students/import', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $content)])->assertOk()->assertJsonPath('added', 1);
        $this->postJson('/api/students/import', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $content)])->assertOk()->assertJsonPath('added', 0);
        $this->assertSame(1, DB::table('users')->where('role', 'student')->count());
        $csv = $this->get('/api/students/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'+84901234567", $csv);
        $invalid = "name,email\nNew,new@example.test\nBad,not-an-email\n";
        $this->postJson('/api/students/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $invalid)])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->getJson('/api/classes')->assertNotFound();
        $this->assertDatabaseHas('users', ['email' => 'anh@example.test', 'role' => 'student']);
    }

    public function test_xlsx_reader_imports_inline_strings_from_first_worksheet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->asAdmin($admin);
        $path = tempnam(sys_get_temp_dir(), 'eduquest-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Minh Tú</t></is></c><c r="B2" t="inlineStr"><is><t>tu@example.test</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $file = new UploadedFile($path, 'roster.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->postJson('/api/students/import', ['file' => $file])->assertOk()->assertJsonPath('added', 1);
            $this->assertDatabaseHas('users', ['email' => 'tu@example.test', 'name' => 'Minh Tú']);
        } finally {
            @unlink($path);
        }
    }
}
