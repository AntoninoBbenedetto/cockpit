<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property UserStatus $status
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Solo i permessi dati tramite ruolo contano: quelli diretti sono un limite noto. */
    public function hasPrivilegedRole(): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->whereIn('name', Permission::privilegedValues()))
            ->exists();
    }

    protected static function booted(): void
    {
        // Evento senza valori: né password in chiaro né hash né la chiave nei dati.
        static::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                activity('user')
                    ->performedOn($user)
                    ->event('password.changed')
                    ->log('Password modificata');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Elenco esplicito: password e remember_token non devono mai comparire nel log.
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
