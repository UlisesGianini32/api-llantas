<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserRoleCommand extends Command
{
    protected $signature = 'user:set-role {user : ID o email del usuario} {role : admin u operations}';

    protected $description = 'Asigna de forma explícita el nivel de acceso de un usuario';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('user'));
        $role = strtolower(trim((string) $this->argument('role')));

        if (! in_array($role, User::ROLES, true)) {
            $this->error('Rol inválido. Valores permitidos: '.implode(', ', User::ROLES).'.');

            return self::INVALID;
        }

        $query = User::query();
        $user = ctype_digit($identifier)
            ? $query->whereKey((int) $identifier)->first()
            : $query->where('email', $identifier)->first();

        if (! $user) {
            $this->error('Usuario no encontrado. Usa su ID numérico o email exacto.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();
        $this->info("Rol {$role} asignado a {$user->email} (ID {$user->id}).");

        return self::SUCCESS;
    }
}
