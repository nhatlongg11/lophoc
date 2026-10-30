<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['assignments', 'games', 'arcade_collections'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('grade', 40)->nullable();
                $table->index(['organization_id', 'grade', 'subject_id']);
            });
        }
        foreach (['assignments', 'games'] as $name) {
            DB::table($name)->select('id', 'title')->orderBy('id')->chunkById(200, function ($rows) use ($name) {
                foreach ($rows as $row) {
                    preg_match_all('/\b(?:lớp|lop|khối|khoi)\s*([345])(?!\d|\s*[-–,\/]\s*[345])\b/iu', $row->title, $matches);
                    $grades = array_values(array_unique($matches[1]));
                    if (count($grades) === 1) {
                        DB::table($name)->where('id', $row->id)->update(['grade' => $grades[0]]);
                    }
                }
            });
        }
        DB::table('arcade_collections')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $grades = DB::table('arcade_collection_rounds')->join('arcade_rounds', 'arcade_collection_rounds.round_id', '=', 'arcade_rounds.id')->where('collection_id', $row->id)->where('arcade_collection_rounds.organization_id', $row->organization_id)->where('arcade_rounds.organization_id', $row->organization_id)->distinct()->pluck('arcade_rounds.grade')->all();
                if (count($grades) === 1 && in_array($grades[0], ['3', '4', '5'], true)) {
                    DB::table('arcade_collections')->where('id', $row->id)->update(['grade' => $grades[0]]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['assignments', 'games', 'arcade_collections'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'grade', 'subject_id']);
                $table->dropColumn('grade');
            });
        }
    }
};
