<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\Permission;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        // Basic Roles
        $roles = [
            'super-admin' => 'Super Administrator',
            'admin' => 'Administrator',
            'manager' => 'Manager',
            'staff' => 'Staff',
            'vendor' => 'Vendor Representative',
            'buyer' => 'Buyer Representative',
        ];

        foreach ($roles as $slug => $name) {
            Role::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        // Basic Permissions (Examples)
        $permissions = [
            'view-dashboard' => 'View Dashboard',
            'manage-users' => 'Manage Users',
            'manage-companies' => 'Manage Companies',
            'create-rfq' => 'Create RFQ',
            'approve-rfq' => 'Approve RFQ',
            'submit-proposal' => 'Submit Proposal',
        ];

        foreach ($permissions as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
        
        // Assign all permissions to super-admin
        $superAdmin = Role::where('slug', 'super-admin')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->sync(Permission::all());
        }
    }
}
