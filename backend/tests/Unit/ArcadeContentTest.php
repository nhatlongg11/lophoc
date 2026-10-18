<?php

namespace Tests\Unit;

use App\ArcadeContent;
use App\ArcadeTemplates;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArcadeContentTest extends TestCase
{
    public function test_all_templates_have_valid_complete_structured_content(): void
    {
        $content = new ArcadeContent;
        $this->assertCount(8, ArcadeTemplates::all());
        foreach (ArcadeTemplates::all() as $template) {
            $validated = $content->validate($template['content']);
            $this->assertSame('arcade-v1', $validated['schema_version']);
            $this->assertNotEmpty($validated['activities']);
        }
    }

    public function test_text_normalization_preserves_vietnamese_meaning_and_configured_case(): void
    {
        $engine = new ArcadeContent;
        $question = ['accepted_answers' => ['mẹ'], 'case_sensitive' => false, 'numeric' => false];
        $this->assertTrue($engine->correct($question, '  '.\Normalizer::normalize('MẸ', \Normalizer::FORM_D).'  ', 'fill_gap'));
        $this->assertFalse($engine->correct($question, 'me', 'fill_gap'));
        $this->assertTrue($engine->correct(['accepted_answers' => ['New York'], 'case_sensitive' => false, 'numeric' => false], 'New    York', 'quick_challenge'));
        $this->assertFalse($engine->correct(['accepted_answers' => ['She'], 'case_sensitive' => true, 'numeric' => false], 'she', 'fill_gap'));
    }

    public function test_numeric_answers_accept_equivalent_decimals_but_not_expressions_or_partial_numbers(): void
    {
        $engine = new ArcadeContent;
        $q = ['accepted_answers' => ['4'], 'case_sensitive' => false, 'numeric' => true];
        $this->assertTrue($engine->correct($q, '004.00', 'fill_gap'));
        foreach (['4e0', '4,0', '4 apples', '2+2', 'Infinity', '', '9999999999999'] as $answer) {
            $this->assertFalse($engine->correct($q, $answer, 'fill_gap'));
        }
        $this->assertSame('0', $engine->number('-0.00'));
        $this->assertSame('-1.5', $engine->number('-01.500'));
    }

    public function test_ordering_uses_unique_token_ids_and_accepts_equivalent_repeated_words(): void
    {
        $engine = new ArcadeContent;
        $q = ['tokens' => [['id' => 'a', 'text' => 'rất'], ['id' => 'b', 'text' => 'rất'], ['id' => 'c', 'text' => 'vui']], 'accepted_orders' => [['a', 'b', 'c']]];
        $this->assertTrue($engine->correct($q, ['b', 'a', 'c'], 'word_builder'));
        $this->assertFalse($engine->correct($q, ['c', 'b', 'a'], 'word_builder'));
        $this->assertFalse($engine->correct($q, ['a', 'a', 'c'], 'word_builder'));
        $this->assertFalse($engine->correct($q, ['a', 'b', 'unknown'], 'word_builder'));
    }

    public function test_combo_and_rewards_are_bounded_and_stars_depend_on_accuracy(): void
    {
        $engine = new ArcadeContent;
        $this->assertSame(275, $engine->points(2, 1000));
        $this->assertSame(0, $engine->points(0, 1000));
        $this->assertSame(3, $engine->stars(90, [50, 75, 90]));
        $this->assertSame(2, $engine->stars(80, [50, 75, 90]));
        $this->assertSame(1, $engine->stars(50, [50, 75, 90]));
        $this->assertSame(0, $engine->stars(49, [50, 75, 90]));
        $this->assertSame(20, $engine->activityXp(100, ['activity' => 10, 'accuracy' => 10]));
        $this->assertSame(0, $engine->activityXp(0, ['activity' => 10, 'accuracy' => 10]));
    }

    public function test_invalid_matching_ids_are_rejected(): void
    {
        $content = ArcadeTemplates::all()[0]['content'];
        $content['activities'][0]['pairs'][1]['id'] = $content['activities'][0]['pairs'][0]['id'];
        $this->expectException(ValidationException::class);
        (new ArcadeContent)->validate($content);
    }

    public function test_invalid_accepted_sequences_are_rejected_instead_of_guessed(): void
    {
        $content = ArcadeTemplates::all()[1]['content'];
        $content['activities'][0]['items'][0]['accepted_orders'] = [['t0', 't0', 't2', 't3']];
        $this->expectException(ValidationException::class);
        (new ArcadeContent)->validate($content);
    }

    public function test_bank_conversion_rejects_incompatible_content_without_fabrication(): void
    {
        $questions = [['type' => 'essay', 'prompt' => 'Kể về lớp em', 'options' => [], 'answer' => null], ['type' => 'single', 'prompt' => '2 + 2?', 'options' => ['3', '4'], 'answer' => '4']];
        $result = (new ArcadeContent)->bankActivities($questions, 'memory_flip');
        $this->assertSame([], $result['activities']);
        $this->assertCount(2, $result['rejected']);
        $quick = (new ArcadeContent)->bankActivities($questions, 'quick_challenge');
        $this->assertSame(1, $quick['compatible_count']);
        $this->assertSame(['4'], $quick['activities'][0]['items'][0]['accepted_answers']);
    }
}
