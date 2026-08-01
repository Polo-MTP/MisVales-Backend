<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Evidencia extends Model
{
    use HasFactory;

    protected $table = 'evidencias';

    protected $fillable = [
        'solicitud_id',
        'entidad_tipo',
        'entidad_id',
        'tipo_documento',
        'url_archivo',
        'subido_por',
        'fecha_subida',
    ];

    protected $casts = [
        'fecha_subida' => 'datetime',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudProveedor::class, 'solicitud_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
