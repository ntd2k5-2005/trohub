<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete());
        foreach (DB::table('invitations')->get() as $invite) {
            $recipient = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($invite->email)])->where('created_at', '<', $invite->created_at)->value('id');
            if ($recipient) {
                DB::table('invitations')->where('id', $invite->id)->update(['recipient_id' => $recipient]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropConstrainedForeignId('recipient_id'));
    }
};
