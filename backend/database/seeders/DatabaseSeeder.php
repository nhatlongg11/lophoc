<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        DB::transaction(function () {
            User::firstOrCreate(['email' => 'admin@lophoc.local'], ['organization_id' => 1, 'name' => 'Quản trị viên', 'password' => 'MatKhau123!', 'role' => 'admin', 'active' => true]);
            User::firstOrCreate(['email' => 'superadmin@eduquest.local'], ['organization_id' => 1, 'name' => 'Quản trị nền tảng', 'password' => 'MatKhau123!', 'role' => 'super_admin', 'active' => true]);
            $teacher = User::firstOrCreate(['email' => 'giaovien@lophoc.local'], ['organization_id' => 1, 'name' => 'Nguyễn Minh Hà', 'password' => 'MatKhau123!', 'role' => 'teacher', 'active' => true]);
            $students = collect([['Nguyễn Ngọc Anh', 'hocsinh@lophoc.local'], ['Trần Minh Tú', 'minhtu@lophoc.local'], ['Lê Hoàng Khang', 'hoangkhang@lophoc.local'], ['Phạm Bảo Trâm', 'baotram@lophoc.local']])->map(fn ($s) => User::firstOrCreate(['email' => $s[1]], ['organization_id' => 1, 'name' => $s[0], 'password' => 'MatKhau123!', 'role' => 'student', 'active' => true]));
            $family = User::firstOrCreate(['email' => 'giadinh@eduquest.local'], ['organization_id' => 1, 'name' => 'Gia đình Ngọc Anh', 'password' => 'MatKhau123!', 'role' => 'family', 'parent_pin_hash' => Hash::make('738291'), 'active' => true]);
            DB::table('parent_student_links')->insertOrIgnore(['organization_id' => 1, 'parent_id' => $family->id, 'student_id' => $students[0]->id, 'created_at' => now(), 'updated_at' => now()]);
            $year = $this->ensure('school_years', ['organization_id' => 1, 'name' => '2026–2027'], ['starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'active' => true]);
            $this->ensure('semesters', ['organization_id' => 1, 'school_year_id' => $year, 'name' => 'Học kỳ I'], ['starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
            $subjects = [];
            foreach (['Toán học' => '#397c78', 'Tiếng Anh' => '#4f7d9c', 'Tiếng Việt' => '#c06d53', 'Khoa học' => '#b18c40'] as $name => $color) {
                $subjects[$name] = $this->ensure('subjects', ['organization_id' => 1, 'name' => $name], ['color' => $color]);
            }
            foreach ($students as $student) {
                DB::table('teacher_students')->insertOrIgnore(['organization_id' => 1, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
            }
            $lesson = $this->ensure('lessons', ['organization_id' => 1, 'title' => 'Ôn tập chương I'], ['teacher_id' => $teacher->id, 'subject_id' => $subjects['Toán học'], 'room' => '205', 'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(9, 45), 'status' => 'scheduled']);
            $assignment = $this->ensure('assignments', ['organization_id' => 1, 'teacher_id' => $teacher->id, 'title' => 'Khám phá kiến thức mỗi ngày'], ['grade' => '5', 'subject_id' => $subjects['Toán học'], 'instructions' => 'Hoàn thành các câu hỏi. Em có thể lưu tiến độ và làm tối đa hai lượt.', 'max_score' => 10, 'due_at' => now()->addDays(7), 'published' => true, 'submission_type' => 'both', 'allowed_attempts' => 2, 'grade_policy' => 'highest']);
            foreach ($students as $student) {
                DB::table('assignment_students')->insertOrIgnore(['organization_id' => 1, 'assignment_id' => $assignment, 'student_id' => $student->id]);
                DB::table('lesson_students')->insertOrIgnore(['organization_id' => 1, 'lesson_id' => $lesson, 'student_id' => $student->id]);
            }
            foreach ([['single', '2 × 6 bằng bao nhiêu?', ['10', '12', '14'], '12'], ['fill_blank', 'Điền từ: I ___ a student.', [], ['am']], ['multiple', 'Chọn các số chẵn.', ['2', '3', '4', '5'], ['2', '4']]] as $i => $q) {
                $this->ensure('assignment_questions', ['organization_id' => 1, 'assignment_id' => $assignment, 'sort_order' => $i], ['type' => $q[0], 'prompt' => $q[1], 'options' => json_encode($q[2], JSON_UNESCAPED_UNICODE), 'answer' => json_encode($q[3], JSON_UNESCAPED_UNICODE), 'points' => 1]);
            }
            $rounds = [
                ['quiz', 'Những con số biết nói', [['single', '8 + 7 bằng bao nhiêu?', ['13', '15', '17'], '15'], ['single', 'Hình tam giác có mấy cạnh?', ['2', '3', '4'], '3']]],
                ['matching', 'Khu vườn từ vựng', [['matching', 'Ghép từ tiếng Anh với nghĩa tiếng Việt.', ['left' => ['cat', 'dog', 'bird'], 'right' => ['con chim', 'con mèo', 'con chó']], ['cat' => 'con mèo', 'dog' => 'con chó', 'bird' => 'con chim']]]],
                ['word_builder', 'Bí mật trong những chữ cái', [['word_builder', 'Từ tiếng Anh chỉ con mèo (3 chữ cái).', ['t', 'c', 'a'], 'cat'], ['word_builder', 'Từ tiếng Anh chỉ mặt trời (3 chữ cái).', ['n', 's', 'u'], 'sun']]],
                ['fill_blank', 'Hoàn thiện câu chuyện', [['fill_blank', 'Nước đóng băng ở ___ độ C.', [], ['0', 'không']], ['fill_blank', 'Điền từ: She ___ my friend.', [], ['is']]]],
                ['listening', 'Lắng nghe giai điệu', [['audio', 'Nghe hai nốt nhạc. Nốt thứ hai có cao độ như thế nào so với nốt thứ nhất?', ['Cao hơn', 'Thấp hơn', 'Bằng nhau'], 'Cao hơn', '/app/audio/rising-notes.wav']]],
                ['challenge', 'Nhà khám phá nhỏ', [['single', 'Bộ phận nào giúp cây hút nước?', ['Rễ', 'Hoa', 'Lá'], 'Rễ'], ['ordering', 'Sắp xếp các số từ bé đến lớn.', ['5', '1', '3'], ['1', '3', '5']], ['fill_blank', 'Điền từ còn thiếu: Có công mài sắt, có ngày nên ___.', [], ['kim']]]],
            ];
            foreach ($rounds as [$type, $title, $questions]) {
                $game = $this->ensure('games', ['organization_id' => 1, 'title' => $title], ['grade' => '5', 'subject_id' => $subjects['Toán học'], 'teacher_id' => $teacher->id, 'type' => $type, 'time_limit_seconds' => 300, 'allowed_attempts' => 10]);
                foreach ($students as $student) {
                    DB::table('game_students')->insertOrIgnore(['organization_id' => 1, 'game_id' => $game, 'student_id' => $student->id]);
                }
                foreach ($questions as $i => $q) {
                    $this->ensure('questions', ['organization_id' => 1, 'game_id' => $game, 'sort_order' => $i], ['kind' => $q[0], 'prompt' => $q[1], 'options' => json_encode($q[2], JSON_UNESCAPED_UNICODE), 'answer' => json_encode($q[3], JSON_UNESCAPED_UNICODE), 'media_url' => $q[4] ?? null, 'topic' => 'Kiến thức cơ bản']);
                }
            }
            $this->ensure('announcements', ['organization_id' => 1, 'title' => 'Chào mừng đến hành trình EduQuest'], ['student_ids' => json_encode($students->pluck('id')->all()), 'author_id' => $teacher->id, 'body' => 'Mỗi ngày một nỗ lực nhỏ. Cùng học tập, khám phá và chia sẻ những điều mới!']);
            foreach (['attention_threshold' => '70', 'risk_threshold' => '50'] as $key => $value) {
                DB::table('app_settings')->insertOrIgnore(['organization_id' => 1, 'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function ensure(string $table, array $keys, array $values): int
    {
        $existing = DB::table($table)->where($keys)->value('id');

        return $existing ? (int) $existing : DB::table($table)->insertGetId([...$keys, ...$values, 'created_at' => now(), 'updated_at' => now()]);
    }
}
