<?php
// database/migrations/xxxx_xx_xx_resize_meses_admissao.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $t) {
            $t->smallInteger('meses_admissao')->unsigned()->nullable()->change();
        });
    }
    public function down(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $t) {
            $t->tinyInteger('meses_admissao')->unsigned()->nullable()->change(); // ajuste conforme estado anterior
        });
    }
};
