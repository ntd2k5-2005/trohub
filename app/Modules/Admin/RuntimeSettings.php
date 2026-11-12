<?php

namespace App\Modules\Admin;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class RuntimeSettings
{
    public function apply(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        foreach (DB::table('system_settings')->get() as $row) {
            $definition = config('runtime_settings')[$row->key] ?? null;
            $value = json_decode($row->value, true);
            if ($definition && is_int($value) && $value >= $definition['min'] && $value <= $definition['max']) {
                config([$definition['config'] => $value]);
            }
        }
    }

    public function save(User $actor, array $data): void
    {
        abort_unless(app(Authorization::class)->global($actor, 'system.configure'), 403);
        $rules = [];
        foreach (config('runtime_settings') as $key => $definition) {
            $rules[$key] = 'required|integer|between:'.$definition['min'].','.$definition['max'];
        }
        $data = Validator::make($data, $rules)->validate();
        DB::transaction(function () use ($actor, $data) {
            $before = DB::table('system_settings')->get()->mapWithKeys(fn ($r) => [$r->key => json_decode($r->value, true)])->all();
            foreach ($data as $key => $value) {
                DB::table('system_settings')->updateOrInsert(['key' => $key], ['value' => json_encode((int) $value), 'created_at' => now(), 'updated_at' => now()]);
            }
            app(Journal::class)->record($actor->id, 'system.settings', $actor, null, ['summary' => 'Cập nhật cấu hình vận hành', 'before' => $before, 'after' => $data]);
        });
        $this->apply();
    }
}
