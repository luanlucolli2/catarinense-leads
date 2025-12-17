<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InovachatTriage extends Model
{
    use HasFactory;

    protected $table = 'inovachat_triages';
    protected $primaryKey = 'tracking_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tracking_id',
        'cpf',
        'connection_token',
        'phone',
        'ticket_id',
        'protocol',
        'name',
        'first_name',
        'source',
        'status',
    ];

    public function authorizations()
    {
        return $this->hasMany(BankAuthorization::class, 'tracking_id', 'tracking_id');
    }
}
