<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\User;

class PasswordResetToken extends Model
{
    use HasFactory;

    protected $table = 'password_reset_tokens';
    protected $primaryKey = 'email';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'email',
        'token',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function generateFor(User $user): self
    {
        static::where('email', $user->email)->delete();

        return static::create([
            'email' => $user->email,
            'token' => Str::random(64),
            'created_at' => now(),
        ]);
    }

    public function isExpired(): bool
    {
        $expirationMinutes = config('auth.passwords.users.expire', 60);

        return $this->created_at !== null && $this->created_at->addMinutes($expirationMinutes)->isPast();
    }

    public function getResetUrl(): string
    {
        return rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/') . '/reset-password?token=' . $this->token;
    }
}
