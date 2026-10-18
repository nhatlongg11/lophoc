<?php

namespace App;

/** Server-owned fixed-step simulation. Clients submit decisions, never scores or damage. */
class WordDefenseBattle
{
    private const ROLES = [
        ['type' => 'scribe', 'cost' => 3, 'damage' => 22, 'interval' => 1.1, 'duration' => 9, 'cooldown' => 2],
        ['type' => 'spark', 'cost' => 2, 'damage' => 14, 'interval' => .65, 'duration' => 8, 'cooldown' => 1.5],
        ['type' => 'cannon', 'cost' => 4, 'damage' => 38, 'interval' => 1.7, 'duration' => 12, 'cooldown' => 3],
    ];

    public function initial(array $activity, string $seed, float $now): array
    {
        $stage = $activity['defense_stage'] ?? $activity['difficulty'];
        $waves = $stage === 1 ? 2 : ($stage < 4 ? 3 : 4);
        $perWave = $stage === 1 ? 3 : ($stage === 2 ? 4 : 5);
        $cards = [];
        foreach ($activity['pairs'] as $index => $pair) {
            $cards[] = ['id' => substr(hash_hmac('sha256', 'spell:'.$index, $seed), 0, 20), 'text' => $pair['left'], 'pair' => $index, ...self::ROLES[$index % 3]];
        }
        usort($cards, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $plan = [];
        for ($wave = 0; $wave < $waves; $wave++) {
            for ($i = 0; $i < $perWave; $i++) {
                $n = count($plan);
                $plan[] = ['at' => 3 + $wave * 20 + $i * ($stage >= 4 ? 2.4 : 3.5), 'lane' => ($i + $wave) % 3, 'pair' => ($n * 7 + $wave) % count($cards), 'kind' => $stage === 5 && $wave === $waves - 1 && $i === $perWave - 1 ? 'boss' : ($n % 4 === 2 ? 'runner' : ($stage > 1 && $n % 4 === 3 ? 'armored' : 'scout')), 'wave' => $wave + 1];
            }
        }

        return ['time' => 0, 'tick' => 0, 'last_at' => $now, 'paused' => true, 'status' => 'playing', 'stage' => $stage, 'health' => 12, 'energy' => 10, 'wave' => 1, 'waves' => $waves, 'spawned' => 0, 'total' => count($plan), 'kills' => 0, 'leaked' => 0, 'correct' => 0, 'wrong' => 0, 'combo' => 0, 'best_combo' => 0, 'shield_until' => 0, 'freeze_until' => 0, 'ability_ready' => 0, 'card_ready' => [], 'cards' => $cards, 'plan' => $plan, 'enemies' => [], 'towers' => [], 'projectiles' => [], 'effects' => [], 'lessons' => [], 'sequence' => 0];
    }

    public function advance(array &$battle, array $activity, float $now): void
    {
        // Interrupted connections freeze after three seconds instead of simulating an unseen loss.
        $seconds = min(3, max(0, $now - $battle['last_at']));
        if ($battle['paused'] || $battle['status'] !== 'playing') {
            $battle['last_at'] = $now;

            return;
        }
        $steps = (int) floor($seconds * 10 + 0.00001);
        $battle['last_at'] = $now - ($seconds - $steps / 10);
        for ($step = 0; $step < $steps && $battle['status'] === 'playing'; $step++) {
            $this->tick($battle, $activity);
        }
    }

    private function spawn(array &$b, array $spec): void
    {
        $kind = $spec['kind'];
        $health = ['scout' => 30, 'runner' => 24, 'armored' => 50, 'boss' => 200][$kind];
        $b['enemies'][] = ['id' => ++$b['sequence'], 'lane' => $spec['lane'], 'pair' => $spec['pair'], 'kind' => $kind, 'position' => 0, 'speed' => ($kind === 'runner' ? .046 : ($kind === 'boss' ? .019 : .029)) * (1 + ($b['stage'] - 1) * .05), 'health' => $health, 'max_health' => $health, 'summons' => 0];
    }

    private function tick(array &$b, array $activity): void
    {
        $b['tick']++;
        $b['time'] = $b['tick'] / 10;
        $t = $b['time'];
        $b['energy'] = min(10, $b['energy'] + .12);
        $b['effects'] = array_values(array_filter($b['effects'], fn ($e) => $e['until'] > $t));
        while (isset($b['plan'][$b['spawned']]) && $b['plan'][$b['spawned']]['at'] <= $t) {
            $spec = $b['plan'][$b['spawned']++];
            $b['wave'] = $spec['wave'];
            $this->spawn($b, $spec);
        }
        $summons = [];
        foreach ($b['enemies'] as &$enemy) {
            $enemy['position'] += $enemy['speed'] * .1 * ($b['freeze_until'] > $t ? .25 : 1);
            if ($enemy['kind'] === 'boss' && $enemy['summons'] < 2 && $enemy['position'] > .2 + $enemy['summons'] * .25) {
                $enemy['summons']++;
                foreach ([0, 2] as $lane) {
                    $summons[] = ['lane' => $lane, 'pair' => ($enemy['pair'] + $lane + 1) % count($activity['pairs']), 'kind' => 'runner'];
                }
            }
        }
        unset($enemy);
        foreach ($summons as $summon) {
            $this->spawn($b, $summon);
            $b['total']++;
        }
        $b['towers'] = array_values(array_filter($b['towers'], fn ($tower) => $tower['until'] > $t));
        foreach ($b['towers'] as &$tower) {
            if ($tower['ready'] > $t) {
                continue;
            }
            $targets = array_values(array_filter($b['enemies'], fn ($e) => $e['lane'] === $tower['lane'] && $e['health'] > 0));
            usort($targets, fn ($a, $c) => $c['position'] <=> $a['position']);
            if (! $targets) {
                continue;
            }
            $target = $targets[0];
            $profile = self::ROLES[$tower['pair'] % 3];
            $b['projectiles'][] = ['id' => ++$b['sequence'], 'target' => $target['id'], 'lane' => $tower['lane'], 'position' => 1, 'pair' => $tower['pair'], 'damage' => (int) round($profile['damage'] * ($tower['level'] === 2 ? 1.55 : 1)), 'type' => $profile['type']];
            $b['effects'][] = ['id' => ++$b['sequence'], 'lane' => $tower['lane'], 'position' => $target['position'], 'text' => '', 'correct' => true, 'until' => $t + 1, 'kind' => 'shot', 'target' => $target['id'], 'type' => $profile['type']];
            $tower['ready'] = $t + $profile['interval'] * ($tower['level'] === 2 ? .68 : 1);
        }
        unset($tower);
        foreach ($b['projectiles'] as &$projectile) {
            $projectile['position'] -= .14;
            $found = false;
            foreach ($b['enemies'] as &$enemy) {
                if ($enemy['id'] !== $projectile['target'] || $enemy['health'] <= 0) {
                    continue;
                }
                $found = true;
                if ($projectile['position'] <= $enemy['position'] + .04) {
                    $correct = $projectile['pair'] === $enemy['pair'];
                    $shielded = $enemy['kind'] === 'boss' && ((int) floor($t) % 8 < 2);
                    $damage = $shielded ? 0 : ($correct ? $projectile['damage'] : 2);
                    $enemy['health'] -= $damage;
                    $b[$correct ? 'correct' : 'wrong']++;
                    $b['learning'][$enemy['pair']] ??= ['correct' => 0, 'wrong' => 0];
                    $b['learning'][$enemy['pair']][$correct ? 'correct' : 'wrong']++;
                    $b['combo'] = $correct ? $b['combo'] + 1 : 0;
                    $b['best_combo'] = max($b['best_combo'], $b['combo']);
                    $b['effects'][] = ['id' => ++$b['sequence'], 'lane' => $enemy['lane'], 'position' => $enemy['position'], 'text' => $shielded ? 'KHIÊN' : '-'.$damage, 'correct' => $correct, 'until' => $t + .8, 'kind' => 'hit'];
                    $projectile['position'] = -10;
                    break;
                }
            }
            unset($enemy);
            if (! $found) {
                $projectile['position'] = -10;
            }
        }
        unset($projectile);
        $b['projectiles'] = array_values(array_filter($b['projectiles'], fn ($p) => $p['position'] > -1));
        $remaining = [];
        foreach ($b['enemies'] as $enemy) {
            if ($enemy['health'] > 0 && $enemy['position'] < 1) {
                $remaining[] = $enemy;

                continue;
            }
            $dead = $enemy['health'] <= 0;
            $b[$dead ? 'kills' : 'leaked']++;
            if (! $dead && $b['shield_until'] <= $t) {
                $b['health'] = max(0, $b['health'] - ($enemy['kind'] === 'boss' ? 6 : 2));
            }
            $pair = $activity['pairs'][$enemy['pair']];
            $b['learning'][$enemy['pair']] ??= ['correct' => 0, 'wrong' => 0];
            if (! $dead) {
                $b['learning'][$enemy['pair']]['wrong']++;
            }
            if ($activity['show_explanations']) {
                $b['lessons'][] = ['id' => $enemy['id'], 'text' => $pair['left'].' → '.$pair['right'], 'correct' => $dead];
                $b['lessons'] = array_slice($b['lessons'], -8);
            }
            $b['effects'][] = ['id' => ++$b['sequence'], 'lane' => $enemy['lane'], 'position' => min(1, $enemy['position']), 'text' => $dead ? '+1' : 'THƯ VIỆN!', 'correct' => $dead, 'until' => $t + .9, 'kind' => 'defeat'];
        }
        $b['enemies'] = $remaining;
        if ($b['health'] <= 0) {
            $b['status'] = 'lost';
        } elseif ($b['spawned'] === count($b['plan']) && ! $b['enemies']) {
            $b['status'] = 'won';
        }
    }

    public function command(array &$b, array $activity, array $action): void
    {
        $kind = $action['kind'];
        if ($kind === 'defense_pause' || $kind === 'defense_resume') {
            $b['paused'] = $kind === 'defense_pause';

            return;
        }
        if ($kind === 'defense_sync') {
            return;
        }
        abort_if($b['paused'] || $b['status'] !== 'playing', 422, 'Tiếp tục trận đấu trước khi ra lệnh.');
        if ($kind === 'defense_ability') {
            abort_if($b['ability_ready'] > $b['time'] || $b['energy'] < 4, 422, 'Kỹ năng cần 4 năng lượng và 18 giây hồi.');
            $b['energy'] -= 4;
            $b['ability_ready'] = $b['time'] + 18;
            $b['freeze_until'] = $b['time'] + 4;
            $b['shield_until'] = $b['time'] + 4;

            return;
        }
        $lane = $action['lane'] ?? -1;
        abort_unless(is_int($lane) && $lane >= 0 && $lane < 3, 422, 'Chọn một trong ba đường.');
        if ($kind === 'defense_upgrade') {
            foreach ($b['towers'] as &$tower) {
                if ($tower['lane'] === $lane && $tower['level'] === 1 && $b['energy'] >= 2) {
                    $tower['level'] = 2;
                    $tower['until'] += 5;
                    $b['energy'] -= 2;

                    return;
                }
            }
            unset($tower);
            abort(422, 'Chọn tháp cấp 1 và đủ 2 năng lượng để nâng cấp.');
        }
        abort_unless($kind === 'defense_deploy', 422, 'Thao tác không phù hợp với trận đấu.');
        $card = array_values(array_filter($b['cards'], fn ($c) => $c['id'] === ($action['spell_id'] ?? '')))[0] ?? null;
        $profile = $card ? self::ROLES[$card['pair'] % 3] : null;
        abort_unless($card && $b['energy'] >= $profile['cost'] && ($b['card_ready'][$card['id']] ?? 0) <= $b['time'], 422, 'Chưa đủ năng lượng hoặc thẻ đang hồi.');
        $b['energy'] -= $profile['cost'];
        $b['card_ready'][$card['id']] = $b['time'] + $profile['cooldown'];
        $b['towers'] = array_values(array_filter($b['towers'], fn ($tower) => $tower['lane'] !== $lane));
        $b['towers'][] = ['lane' => $lane, 'pair' => $card['pair'], 'text' => $card['text'], 'type' => $profile['type'], 'level' => 1, 'ready' => $b['time'], 'until' => $b['time'] + $profile['duration']];
    }

    public function publicState(array $b, array $activity): array
    {
        unset($b['plan'], $b['last_at'], $b['sequence'], $b['learning']);
        $b['cards'] = array_map(fn ($card) => ['id' => $card['id'], 'text' => $card['text'], ...self::ROLES[$card['pair'] % 3]], $b['cards']);
        $b['enemies'] = array_map(function ($enemy) use ($activity) {
            $enemy['clue'] = $activity['pairs'][$enemy['pair']]['right'];
            unset($enemy['pair'], $enemy['summons']);

            return $enemy;
        }, $b['enemies']);
        $b['towers'] = array_map(function ($tower) {
            unset($tower['pair']);

            return $tower;
        }, $b['towers']);
        $b['projectiles'] = array_map(function ($projectile) {
            unset($projectile['pair'], $projectile['damage']);

            return $projectile;
        }, $b['projectiles']);

        return $b;
    }
}
