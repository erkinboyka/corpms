<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('group', 'corpms')->where('key', 'ai.api_key')
            ->whereNotNull('value')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    if ($row->value !== '' && ! str_starts_with($row->value, 'enc:v1:')) {
                        DB::table('settings')->where('id', $row->id)->update(['value' => 'enc:v1:'.Crypt::encryptString($row->value)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // DB-02: шифрование необратимо без ключа/исходного plaintext.
        // Явный отказ защищает от частичного отката с потерей настроек.
        throw new RuntimeException(
            'Migration 2026_11_19_000002 is irreversible: encrypted settings cannot be decrypted safely during rollback. Restore settings from backup before rolling back.',
        );
    }
};
