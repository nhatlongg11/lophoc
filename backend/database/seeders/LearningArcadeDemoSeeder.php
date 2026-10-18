<?php

namespace Database\Seeders;

use App\ArcadeContent;
use App\ArcadeTemplates;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LearningArcadeDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $this->call(DatabaseSeeder::class);
        DB::transaction(function () {
            $teacher = User::where('organization_id', 1)->where('email', 'giaovien@lophoc.local')->firstOrFail();
            foreach ([['hocsinh5@eduquest.local', 'An Nhiên · Mẫu khối 5'], ['banhoc5@eduquest.local', 'Minh Khoa · Mẫu khối 5']] as [$email, $name]) {
                $student = User::firstOrCreate(['email' => $email], ['organization_id' => 1, 'name' => $name, 'role' => 'student', 'password' => 'MatKhau123!', 'active' => true]);
                DB::table('teacher_students')->insertOrIgnore(['organization_id' => 1, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
            }
            $studentIds = DB::table('teacher_students')->where('organization_id', 1)->where('teacher_id', $teacher->id)->pluck('student_id')->all();
            foreach (['Tiếng Anh' => 'My school · Một ngày đến lớp', 'Toán học' => 'Những phép tính quanh em', 'Khoa học' => 'Cây xanh và sự sống', 'Tiếng Việt' => 'Câu chữ kể chuyện'] as $name => $title) {
                $subject = DB::table('subjects')->where('organization_id', 1)->where('name', $name)->first();
                $content = app(ArcadeContent::class)->validate(['schema_version' => 'arcade-v1', 'title' => '[Mẫu khối 5] '.$title, 'subject_id' => $subject->id, 'grade' => '5', 'topic' => $name === 'Tiếng Anh' ? 'My school' : $title, 'objectives' => 'Luyện tập nội dung khối 5 qua các hoạt động ngắn; ôn lại câu sai và ghi nhận tiến bộ.', 'description' => 'Nội dung minh họa phát triển, do EduQuest biên soạn. Không tính vào điểm học bạ.', 'linear' => true, 'completion_accuracy' => 50, 'stars' => [50, 75, 90], 'rewards' => ['activity' => 10, 'accuracy' => 10, 'round' => 20, 'mastery' => 10], 'activities' => ArcadeTemplates::activities($name)]);
                $round = DB::table('arcade_rounds')->where('organization_id', 1)->where('teacher_id', $teacher->id)->whereIn('title', [$content['title'], str_replace('[Mẫu khối 5]', '[Mẫu lớp 5]', $content['title'])])->first();
                if (! $round) {
                    $id = DB::table('arcade_rounds')->insertGetId(['organization_id' => 1, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'title' => $content['title'], 'grade' => '5', 'topic' => $content['topic'], 'status' => 'assigned', 'is_demo' => true, 'created_at' => now(), 'updated_at' => now()]);
                    $version = DB::table('arcade_versions')->insertGetId(['organization_id' => 1, 'round_id' => $id, 'author_id' => $teacher->id, 'revision' => 1, 'subject_id' => $subject->id, 'title' => $content['title'], 'grade' => '5', 'topic' => $content['topic'], 'schema_version' => 'arcade-v1', 'content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('arcade_rounds')->where('id', $id)->update(['draft_version_id' => $version, 'published_version_id' => $version]);
                    foreach (['draft_saved', 'previewed', 'published'] as $event) {
                        DB::table('arcade_publish_history')->insert(['organization_id' => 1, 'round_id' => $id, 'version_id' => $version, 'actor_id' => $teacher->id, 'action' => $event, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $round = DB::table('arcade_rounds')->find($id);
                }
                if (! DB::table('arcade_assignments')->where('round_id', $round->id)->where('version_id', $round->published_version_id)->exists()) {
                    DB::table('arcade_assignments')->insert(['organization_id' => 1, 'round_id' => $round->id, 'version_id' => $round->published_version_id, 'student_ids' => json_encode($studentIds), 'assigned_by' => $teacher->id, 'allowed_attempts' => 20, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $english = DB::table('subjects')->where('organization_id', 1)->where('name', 'Tiếng Anh')->first();
            $bank = DB::table('question_banks')->where('organization_id', 1)->where('owner_id', $teacher->id)->whereIn('name', ['[Mẫu khối 5] Nội dung tương thích Arcade', '[Mẫu lớp 5] Nội dung tương thích Arcade'])->value('id');
            if (! $bank) {
                $bank = DB::table('question_banks')->insertGetId(['organization_id' => 1, 'owner_id' => $teacher->id, 'name' => '[Mẫu khối 5] Nội dung tương thích Arcade', 'subject_id' => $english->id, 'grade' => '5', 'topic' => 'My school', 'difficulty' => 'easy', 'learning_objective' => 'Thực hành từ vựng và cấu trúc câu ở trường học.', 'created_at' => now(), 'updated_at' => now()]);
                $questions = [
                    ['type' => 'matching', 'prompt' => 'Ghép từ vựng học đường.', 'options' => ['left' => ['book', 'pencil', 'classroom', 'teacher'], 'right' => ['quyển sách', 'bút chì', 'lớp học', 'giáo viên']], 'answer' => ['book' => 'quyển sách', 'pencil' => 'bút chì', 'classroom' => 'lớp học', 'teacher' => 'giáo viên']],
                    ['type' => 'ordering', 'prompt' => 'Sắp xếp câu giới thiệu đồ vật.', 'options' => ['my', 'This', 'book.', 'is'], 'answer' => ['This', 'is', 'my', 'book.']],
                    ['type' => 'single', 'prompt' => 'Which word means “giáo viên”?', 'options' => ['book', 'teacher', 'pencil'], 'answer' => 'teacher'],
                    ['type' => 'fill_blank', 'prompt' => 'She ___ to school.', 'options' => [], 'answer' => ['goes']],
                    ['type' => 'essay', 'prompt' => 'Viết đoạn văn kể về lớp của em.', 'options' => [], 'answer' => null],
                ];
                foreach ($questions as $i => $q) {
                    DB::table('bank_questions')->insert(['organization_id' => 1, 'question_bank_id' => $bank, 'content' => json_encode([...$q, 'points' => 1, 'topic' => 'My school'], JSON_UNESCAPED_UNICODE), 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
