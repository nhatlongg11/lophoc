<?php

namespace App;

class ArcadeTemplates
{
    public static function activity(string $id, string $engine, string $title, string $topic, array $payload): array
    {
        return ['id' => $id, 'engine' => $engine, 'title' => $title, 'topic' => $topic, 'objective' => 'Nhận biết và vận dụng kiến thức: '.$topic, 'difficulty' => 1, 'allow_hints' => true, 'show_explanations' => true, 'retry_limit' => 2, 'timed' => false, 'time_limit_seconds' => 180, ...$payload];
    }

    public static function typed(string $id, string $prompt, array $answers, string $explanation, string $hint = '', bool $numeric = false): array
    {
        return ['id' => $id, 'prompt' => $prompt, 'answer_mode' => 'typed', 'choices' => [], 'accepted_answers' => $answers, 'case_sensitive' => false, 'numeric' => $numeric, 'explanation' => $explanation, 'hint' => $hint];
    }

    public static function choice(string $id, string $prompt, array $choices, string $answer, string $explanation): array
    {
        return [...self::typed($id, $prompt, [$answer], $explanation), 'choices' => $choices, 'answer_mode' => 'choice'];
    }

    public static function sequence(string $id, string $prompt, array $words, string $explanation): array
    {
        $tokens = array_map(fn ($text, $i) => ['id' => 't'.$i, 'text' => $text], $words, array_keys($words));

        return ['id' => $id, 'prompt' => $prompt, 'tokens' => $tokens, 'accepted_orders' => [array_column($tokens, 'id')], 'explanation' => $explanation, 'hint' => 'Tìm thành phần mở đầu, rồi kiểm tra ý nghĩa của cả câu.'];
    }

    public static function activities(string $subject): array
    {
        if ($subject === 'Toán học') {
            $pairs = [['6 × 7', '42'], ['8 × 4', '32'], ['9 × 5', '45'], ['12 ÷ 3', '4']];

            return [self::activity('math_match', 'matching', 'Kết nối phép tính', 'Nhân và chia', ['pairs' => self::pairs($pairs)]), self::activity('math_quick', 'quick_challenge', 'Nhẩm một chút mỗi ngày', 'Nhân và chia', ['items' => [self::choice('m1', '125 + 75 bằng bao nhiêu?', ['190', '200', '210'], '200', '125 + 75 = 200.'), self::typed('m2', '9 × 8 bằng bao nhiêu?', ['72'], '9 nhóm, mỗi nhóm 8 phần tử: 9 × 8 = 72.', 'Có thể tính 10 × 8 rồi bớt 8.', true)]]), self::activity('math_gap', 'fill_gap', 'Tìm số còn thiếu', 'Nhân và chia', ['items' => [self::typed('m3', '12 × ___ = 48', ['4'], '48 ÷ 12 = 4.', 'Dùng phép chia để tìm thừa số chưa biết.', true), self::typed('m4', '3,5 + ___ = 5', ['1.5'], '5 − 3,5 = 1,5. Nhập 1.5 theo định dạng số của ô trả lời.', 'Tính hiệu của 5 và 3,5.', true)]])];
        }
        if ($subject === 'Khoa học') {
            $pairs = [['Rễ cây', 'Hút nước và muối khoáng'], ['Lá cây', 'Thực hiện quang hợp'], ['Thân cây', 'Vận chuyển nước và chất dinh dưỡng'], ['Hoa', 'Tham gia sinh sản của cây']];

            return [self::activity('science_match', 'matching', 'Cây xanh quanh em', 'Cơ quan của cây', ['pairs' => self::pairs($pairs)]), self::activity('science_memory', 'memory_flip', 'Ghi nhớ điều kỳ diệu', 'Cơ quan của cây', ['pairs' => self::pairs($pairs)])];
        }
        if ($subject === 'Tiếng Việt') {
            return [self::activity('vi_builder', 'word_builder', 'Xưởng câu tiếng Việt', 'Câu và từ', ['items' => [self::sequence('v1', 'Sắp xếp thành câu miêu tả một buổi sáng.', ['Buổi sáng,', 'những chú chim', 'hót', 'trong vườn.'], 'Cụm chỉ thời gian mở đầu câu; chủ ngữ là những chú chim.'), self::sequence('v2', 'Sắp xếp thành câu nêu hoạt động.', ['Chúng em', 'cùng nhau', 'trồng cây', 'ở sân trường.'], 'Câu có chủ ngữ, hoạt động và địa điểm rõ ràng.')]]), self::activity('vi_quick', 'quick_challenge', 'Đọc và hiểu', 'Câu và từ', ['items' => [self::choice('v3', 'Trong câu “Bạn Lan đọc sách”, từ nào chỉ hoạt động?', ['Bạn', 'Lan', 'đọc'], 'đọc', 'Đọc là động từ chỉ hoạt động.'), self::choice('v4', 'Từ nào gần nghĩa với “chăm chỉ”?', ['siêng năng', 'lười biếng', 'vội vàng'], 'siêng năng', 'Siêng năng và chăm chỉ cùng chỉ sự cần mẫn.')]]), self::activity('vi_gap', 'fill_gap', 'Từ ngữ biết nói', 'Câu và từ', ['items' => [[...self::choice('v5', 'Có công mài sắt, có ngày nên ___.', ['kim', 'cây', 'đá'], 'kim', 'Câu tục ngữ khuyên chúng ta kiên trì.'), 'answer_mode' => 'word_bank']]])];
        }
        $pairs = self::pairs([['book', 'quyển sách'], ['pencil', 'bút chì'], ['classroom', 'lớp học'], ['teacher', 'giáo viên']]);

        return [
            self::activity('english_match', 'matching', 'Ghép đồ dùng học tập', 'My school', ['pairs' => $pairs]),
            self::activity('english_builder', 'word_builder', 'Xếp câu thật hay', 'My school', ['items' => [self::sequence('e1', 'Sắp xếp câu nói về việc đi học của cô ấy.', ['She', 'goes', 'to', 'school.'], 'Chủ ngữ She đi cùng động từ goes ở hiện tại đơn.'), self::sequence('e2', 'Sắp xếp câu giới thiệu đồ vật.', ['This', 'is', 'my', 'book.'], 'This is... dùng để giới thiệu một người hoặc vật ở gần.')]]),
            self::activity('english_quick', 'quick_challenge', 'Thử thách trong lớp', 'My school', ['items' => [self::choice('e3', 'Which word means “giáo viên”?', ['teacher', 'pencil', 'book'], 'teacher', 'Teacher có nghĩa là giáo viên.'), self::choice('e4', 'Choose the correct sentence.', ['She go to school.', 'She goes to school.', 'She going school.'], 'She goes to school.', 'Với She, động từ go thêm -es ở hiện tại đơn.'), self::typed('e5', 'Write the English word for “quyển sách”.', ['book'], 'Book là quyển sách.', 'Từ gồm bốn chữ cái, bắt đầu bằng b.')]]),
            self::activity('english_memory', 'memory_flip', 'Đôi thẻ của lớp mình', 'My school', ['pairs' => $pairs]),
            self::activity('english_gap', 'fill_gap', 'Hoàn thiện câu chuyện', 'My school', ['items' => [[...self::choice('e6', 'She ___ to school every day.', ['go', 'goes', 'going'], 'goes', 'She dùng goes ở hiện tại đơn.'), 'answer_mode' => 'word_bank'], self::typed('e7', 'This ___ my classroom.', ['is'], 'This là chủ ngữ số ít, dùng is.', 'Chọn dạng to be cho chủ ngữ số ít.'), self::choice('e8', 'I write with a ___.', ['pencil', 'classroom', 'teacher'], 'pencil', 'Pencil là bút chì, dùng để viết.')]]),
        ];
    }

    private static function pairs(array $pairs): array
    {
        return array_map(fn ($p, $i) => ['id' => 'p'.$i, 'left' => $p[0], 'right' => $p[1]], $pairs, array_keys($pairs));
    }

    public static function all(): array
    {
        $english = self::activities('Tiếng Anh');
        $definitions = [
            ['vocabulary', 'Luyện từ vựng', 'Tiếng Anh', 'Từ vựng học đường', [$english[0], $english[3]]],
            ['grammar', 'Luyện ngữ pháp', 'Tiếng Anh', 'Hiện tại đơn', [$english[1], $english[4]]],
            ['math', 'Sự thật toán học', 'Toán học', 'Nhân và chia', self::activities('Toán học')],
            ['science', 'Khái niệm khoa học', 'Khoa học', 'Cơ quan của cây', self::activities('Khoa học')],
            ['reading', 'Đọc hiểu', 'Tiếng Việt', 'Câu và từ', self::activities('Tiếng Việt')],
            ['mixed', 'Ôn tập theo chủ đề', 'Tiếng Anh', 'My school', [$english[0], $english[1], $english[2]]],
            ['unit', 'Tổng kết cuối bài', 'Tiếng Anh', 'My school', $english],
        ];
        $defense = [];
        foreach (['Làm quen', 'Luyện tập', 'Từ vựng hỗn hợp', 'Đội quân tốc độ', 'Trùm thư viện'] as $i => $name) {
            $defense[] = self::activity('defense_'.($i + 1), 'word_defense', $name, 'My school', ['pairs' => $english[0]['pairs'], 'difficulty' => min(3, 1 + intdiv($i, 2)), 'defense_stage' => $i + 1, 'allow_hints' => false, 'objective' => 'Chọn thẻ từ rồi chạm đường để đặt tháp. Ghép từ với nghĩa trên quân địch để tăng sát thương.']);
        }
        $definitions[] = ['word-defense', 'Word Defense · Bảo vệ thư viện', 'Tiếng Anh', 'Từ vựng học đường', $defense];

        return array_map(fn ($d) => ['id' => $d[0], 'name' => $d[1], 'subject_name' => $d[2], 'description' => 'Nội dung mẫu do EduQuest biên soạn. Kiểm tra và điều chỉnh theo mục tiêu của lớp.', 'content' => ['schema_version' => 'arcade-v1', 'title' => $d[1], 'subject_id' => 1, 'grade' => '5', 'topic' => $d[3], 'objectives' => 'Nhận biết, thực hành và vận dụng kiến thức trong chủ đề '.$d[3].'.', 'description' => 'Một vòng học ngắn với phản hồi rõ ràng và cơ hội ôn lại.', 'linear' => true, 'completion_accuracy' => 50, 'stars' => [50, 75, 90], 'rewards' => ['activity' => 10, 'accuracy' => 10, 'round' => 20, 'mastery' => 10], 'activities' => $d[4]]], $definitions);
    }
}
