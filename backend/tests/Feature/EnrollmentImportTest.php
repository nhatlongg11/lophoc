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

    private function classroom(User $admin): int
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $year = DB::table('school_years')->insertGetId(['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Toán']);
        $class = DB::table('school_classes')->insertGetId(['name' => 'A', 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year]);
        DB::table('api_tokens')->insert(['user_id' => $admin->id, 'token_hash' => hash('sha256', 'import-token'), 'expires_at' => now()->addHour()]);
        $this->withToken('import-token');

        return $class;
    }

    public function test_csv_import_is_atomic_repeatable_and_export_blocks_spreadsheet_formula_injection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $class = $this->classroom($admin);
        $content = "name,email,phone\nNgọc Anh,anh@example.test,+84901234567\n";
        $this->postJson('/api/classes/'.$class.'/import', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $content)])->assertOk()->assertJsonPath('added', 1);
        $this->postJson('/api/classes/'.$class.'/import', ['file' => UploadedFile::fake()->createWithContent('roster.csv', $content)])->assertOk()->assertJsonPath('added', 0);
        $this->assertDatabaseCount('class_memberships', 1);
        $csv = $this->get('/api/classes/'.$class.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'+84901234567", $csv);
        $invalid = "name,email\nNew,new@example.test\nBad,not-an-email\n";
        $this->postJson('/api/classes/'.$class.'/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $invalid)])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->deleteJson('/api/classes/'.$class)->assertOk();
        $this->assertDatabaseHas('class_memberships', ['class_id' => $class]);
    }

    public function test_xlsx_reader_imports_inline_strings_from_first_worksheet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $class = $this->classroom($admin);
        $path = tempnam(sys_get_temp_dir(), 'eduquest-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>name</t></is></c><c r="B1" t="inlineStr"><is><t>email</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Minh Tú</t></is></c><c r="B2" t="inlineStr"><is><t>tu@example.test</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $file = new UploadedFile($path, 'roster.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->postJson('/api/classes/'.$class.'/import', ['file' => $file])->assertOk()->assertJsonPath('added', 1);
            $this->assertDatabaseHas('users', ['email' => 'tu@example.test', 'name' => 'Minh Tú']);
        } finally {
            @unlink($path);
        }
    }
}
