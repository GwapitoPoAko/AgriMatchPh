<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Seed stable, idempotent demo accounts without deleting existing records. */
    public function run(): void
    {
        $provinceIds = Province::query()->pluck('id', 'name');
        $accounts = [
            ['name' => 'Patricia Go', 'email' => 'procurement@gaisanofresh.test', 'phone' => '+63 88 856 1200', 'role' => User::ROLE_BUYER,
                'business_name' => 'Gaisano Fresh - CDO', 'business_type' => 'supermarket', 'province' => 'Misamis Oriental', 'municipality' => 'Cagayan de Oro', 'barangay' => null],
            ['name' => 'Romeo Dagatan', 'email' => 'romeo.dagatan@agrimatch.test', 'phone' => '+63 917 812 4410', 'role' => User::ROLE_FARMER,
                'business_name' => 'Romeo Dagatan Farm', 'business_type' => 'individual_farm', 'province' => 'Bukidnon', 'municipality' => 'Lantapan', 'barangay' => 'Songco'],
            ['name' => 'Datu Ramil Manggubat', 'email' => 'ramil.manggubat@agrimatch.test', 'phone' => '+63 926 441 7782', 'role' => User::ROLE_COOP_MANAGER,
                'business_name' => 'Lantapan Highland', 'business_type' => 'cooperative', 'province' => 'Bukidnon', 'municipality' => 'Lantapan', 'barangay' => null],
            ['name' => 'Engr. Mylene Tabañag', 'email' => 'mylene.tabanag@agrimatch.test', 'phone' => '+63 917 640 3381', 'role' => User::ROLE_INSPECTOR,
                'business_name' => 'Quality Desk', 'business_type' => 'inspection', 'province' => 'Bukidnon', 'municipality' => null, 'barangay' => null],
            ['name' => 'Junrey Abellanosa', 'email' => 'junrey.abellanosa@agrimatch.test', 'phone' => '+63 926 305 1128', 'role' => User::ROLE_DRIVER,
                'business_name' => 'Elf 3T - CDO', 'business_type' => 'logistics', 'province' => 'Misamis Oriental', 'municipality' => 'Cagayan de Oro', 'barangay' => null],
            ['name' => 'AgriMatch Administrator', 'email' => 'admin@agrimatch.test', 'phone' => '+63 917 000 0001', 'role' => User::ROLE_ADMIN,
                'business_name' => 'Fuel & Audit Desk', 'business_type' => 'platform', 'province' => null, 'municipality' => null, 'barangay' => null],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'phone' => $account['phone'],
                    'password' => Hash::make('password'),
                ],
            );
            $user->forceFill([
                'role' => $account['role'],
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $account['business_name'],
                    'business_type' => $account['business_type'],
                    'province_id' => $account['province'] ? ($provinceIds[$account['province']] ?? null) : null,
                    'municipality' => $account['municipality'],
                    'barangay' => $account['barangay'],
                    'sms_opt_in' => true,
                ],
            );
        }
    }
}
