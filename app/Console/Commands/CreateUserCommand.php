<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create
                            {email : Email del usuario}
                            {--name= : Nombre completo del usuario}
                            {--role=admin : Rol del usuario (admin, operations, pos, warehouse)}
                            {--password= : Contraseña (si no se especifica se genera una segura)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea o actualiza un usuario en el sistema con su rol y contraseña';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        $role = strtolower(trim((string) $this->option('role') ?: User::ROLE_ADMIN));
        $password = (string) $this->option('password');

        if (! in_array($role, User::ROLES, true)) {
            $this->error('Rol inválido. Valores permitidos: ' . implode(', ', User::ROLES));
            return self::INVALID;
        }

        if (empty($password)) {
            $password = 'SBS' . Str::random(8) . '!';
        }

        if (empty($name)) {
            $name = Str::headline(explode('@', $email)[0]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'name' => $name,
                'role' => $role,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            $this->info("Usuario existente actualizado: {$user->email} (ID: {$user->id})");
        } else {
            $user = new User();
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            $this->info("Usuario creado exitosamente: {$user->email} (ID: {$user->id})");
        }

        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $user->id],
                ['Nombre', $user->name],
                ['Email', $user->email],
                ['Rol', $user->role],
                ['Contraseña', $password],
                ['Email Verificado', $user->email_verified_at?->toDateTimeString()],
            ]
        );

        return self::SUCCESS;
    }
}
