<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;   // 👈
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory;                                      // 👈

    protected $fillable = ['name', 'name_clean'];

    public function contracts(): HasMany
    {
        return $this->hasMany(LeadContract::class);
    }

    /** Normaliza o nome para busca/unicidade */
    public static function clean(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);             // espaço extra → um
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $name); // remove acentos
        return $converted !== false ? $converted : $name;      // nunca retorna false
    }
}
