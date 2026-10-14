<?php

use Livewire\Volt\Component;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Directorate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public $tab = 'users'; // users, roles, permissions
    public $search = '';

    // User Form State
    public $userId = null;
    public $name;
    public $email;
    public $selectedRole;
    public $directorate_id;
    public $modalOpen = false;
    public $isEditing = false;

    // A temporary password is shown once, right after it is issued, and never stored in clear text.
    public $issuedPassword = null;
    public $issuedFor = null;

    public function setTab($tab)
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'users' => $this->getUsers(),
            'roles' => Role::with('permissions')->get(),
            'permissions' => Permission::all()->groupBy('module'),
            'directorates' => Directorate::all(),
        ];
    }

    private function getUsers()
    {
        return User::with(['directorate', 'roles'])
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })
            ->paginate(10);
    }

    public function openModal($id = null)
    {
        $this->resetValidation();
        if ($id) {
            $this->isEditing = true;
            $this->userId = $id;
            $user = User::findOrFail($id);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->selectedRole = $user->roles->first()?->name;
            $this->directorate_id = $user->directorate_id;
        } else {
            $this->isEditing = false;
            $this->userId = null;
            $this->reset(['name', 'email', 'selectedRole', 'directorate_id']);
        }
        $this->modalOpen = true;
    }

    public function saveUser()
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $this->userId,
            'selectedRole' => 'required|exists:roles,name',
            'directorate_id' => 'nullable|exists:directorates,id',
        ];

        $this->validate($rules);

        if ($this->isEditing) {
            $user = User::findOrFail($this->userId);
            $user->update([
                'name' => $this->name,
                'email' => $this->email,
                'directorate_id' => $this->directorate_id ?: null,
            ]);
            $user->syncRoles([$this->selectedRole]);
            $this->dispatch('toast', message: __('User updated successfully!'), type: 'success');
        } else {
            $temporary = Str::password(14, symbols: false);
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($temporary),
                'must_change_password' => true,
                'directorate_id' => $this->directorate_id ?: null,
            ]);
            $user->assignRole($this->selectedRole);
            $this->issuedPassword = $temporary;
            $this->issuedFor = $user->email;
            $this->dispatch('toast', message: __('User created. Pass on the temporary password shown below.'), type: 'success');
        }

        $this->modalOpen = false;
    }

    public function deleteUser($id)
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        if (!auth()->user()->isAdministrator()) {
            $this->dispatch('toast', message: __('Only administrators can delete user accounts.'), type: 'error');
            return;
        }

        if ((int) $id === auth()->id()) {
            $this->dispatch('toast', message: __('You cannot delete your own account!'), type: 'error');
            return;
        }
        User::destroy($id);
        $this->dispatch('toast', message: __('User deleted successfully.'), type: 'success');
    }

    public function resetPassword($id)
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->dispatch('toast', message: __('Change your own password from your profile.'), type: 'error');

            return;
        }

        $temporary = Str::password(14, symbols: false);
        $user->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true])->save();
        $this->issuedPassword = $temporary;
        $this->issuedFor = $user->email;
        $this->dispatch('toast', message: __('A new temporary password was issued.'), type: 'success');
    }

    public function resetTwoFactor($id)
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $user = User::findOrFail($id);
        app(\App\Services\TwoFactor::class)->disable($user, auth()->user());
        $this->dispatch('toast', message: __('Two-factor authentication was reset for :email. They can set it up again.', ['email' => $user->email]), type: 'success');
    }

    public function dismissIssuedPassword()
    {
        $this->issuedPassword = null;
        $this->issuedFor = null;
    }

    public function togglePermission($roleId, $permissionName)
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $role = Role::findOrFail($roleId);
        
        if ($role->hasPermissionTo($permissionName)) {
            $role->revokePermissionTo($permissionName);
        } else {
            $role->givePermissionTo($permissionName);
        }

        $this->dispatch('toast', message: __('Permissions updated for role: ') . $role->name, type: 'success');
    }
}; ?>

<div class="space-y-6">
    @if($issuedPassword)
        <div class="alert alert-success !items-start" role="status">
            <div class="flex-1">
                <p class="font-semibold">{{ __('Temporary password for :email', ['email' => $issuedFor]) }}</p>
                <p class="mt-2 inline-flex items-center gap-3">
                    <code class="px-3 py-1.5 rounded bg-white border border-emerald-200 text-base font-mono tracking-wide select-all">{{ $issuedPassword }}</code>
                    <button type="button" class="text-sm font-medium underline"
                        x-data @click="navigator.clipboard.writeText(@js($issuedPassword)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                </p>
                <p class="mt-2 text-xs font-normal">{{ __('It is shown only now and is not stored. The user must choose their own password the first time they sign in.') }}</p>
            </div>
            <button type="button" wire:click="dismissIssuedPassword" class="text-sm font-medium underline shrink-0">{{ __('I have noted it') }}</button>
        </div>
    @endif

    <!-- Header with Breadcrumbs & Action -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">{{ __('Access Governance') }}</h1>
            <p class="page-subtitle">
                {{ __('Manage system access, roles, and security permissions') }}
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if($tab === 'users')
                <x-ui.button wire:click="openModal()" variant="primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <line x1="19" x2="19" y1="8" y2="14" />
                        <line x1="16" x2="22" y1="11" y2="11" />
                    </svg>
                    {{ __('Onboard User') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Navigation Hub (Floating Pill Style) -->
    <div class="flex items-center gap-2 p-1.5 bg-zinc-100/50 border border-zinc-200 rounded-xl shadow-sm w-fit">
        <x-ui.button wire:click="setTab('users')"
            variant="{{ $tab === 'users' ? 'secondary' : 'ghost' }}" size="sm" class="!rounded-xl !text-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
            </svg>
            {{ __('Personnel') }}
        </x-ui.button>
        <x-ui.button wire:click="setTab('roles')"
            variant="{{ $tab === 'roles' ? 'secondary' : 'ghost' }}" size="sm" class="!rounded-xl !text-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                <path d="m9 12 2 2 4-4" />
            </svg>
            {{ __('Authorization') }}
        </x-ui.button>
        <x-ui.button wire:click="setTab('permissions')"
            variant="{{ $tab === 'permissions' ? 'secondary' : 'ghost' }}" size="sm" class="!rounded-xl !text-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            </svg>
            {{ __('Policy Matrix') }}
        </x-ui.button>
    </div>

    <!-- Main View Content -->
    <div class="animate-in fade-in slide-in-from-bottom-2 duration-500">
        @if($tab === 'users')
            @include('livewire.pages.management.users.user-list')
        @elseif($tab === 'roles')
            @include('livewire.pages.management.users.role-management')
        @elseif($tab === 'permissions')
            @include('livewire.pages.management.users.permission-list')
        @endif
    </div>

    <!-- User Form Modal -->
    @if($modalOpen)
        <x-dialog max-width="lg" close="$set('modalOpen', false)">
<x-slot:title>{{ $isEditing ? __('Edit User') : __('Create New User') }}</x-slot:title>
<x-slot:subtitle>{{ __('User Profile & Access') }}</x-slot:subtitle>

                <form wire:submit="saveUser" class="p-6 space-y-5">
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <x-input-label for="modal_name" :value="__('Full Name')"
                                class="text-xs font-semibold text-gray-400 ml-1" />
                            <x-text-input id="modal_name" wire:model="name"
                                class="w-full !rounded-xl !bg-gray-50/50 !border-gray-100 focus:!ring-black"
                                placeholder="John Doe" />
                            <x-input-error :messages="$errors->get('name')" />
                        </div>

                        <div class="space-y-2">
                            <x-input-label for="modal_email" :value="__('Email Address')"
                                class="text-xs font-semibold text-gray-400 ml-1" />
                            <x-text-input id="modal_email" type="email" wire:model="email"
                                class="w-full !rounded-xl !bg-gray-50/50 !border-gray-100 focus:!ring-black"
                                placeholder="john@msa.gov.tl" />
                            <x-input-error :messages="$errors->get('email')" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <x-input-label for="modal_role" :value="__('Security Role')"
                                    class="text-xs font-semibold text-gray-400 ml-1" />
                                <select id="modal_role" wire:model.live="selectedRole"
                                    class="w-full h-11 px-4 rounded-xl bg-gray-50/50 border-gray-100 text-sm focus:ring-1 focus:ring-black focus:border-black transition-all">
                                    <option value="">{{ __('Select Role') }}</option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->name }}">{{ $role->display_name ?: $role->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('selectedRole')" />
                            </div>

                            <div class="space-y-2">
                                <x-input-label for="modal_directorate" :value="__('Directorate')"
                                    class="text-xs font-semibold text-gray-400 ml-1" />
                                <select id="modal_directorate" wire:model="directorate_id"
                                    class="w-full h-11 px-4 rounded-xl bg-gray-50/50 border-gray-100 text-sm focus:ring-1 focus:ring-black focus:border-black transition-all">
                                    <option value="">{{ __('National (All)') }}</option>
                                    @foreach($directorates as $dir)
                                        <option value="{{ $dir->id }}">{{ $dir->code }} {{ $dir->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('directorate_id')" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4">
                        <x-ui.button type="button" wire:click="$set('modalOpen', false)" variant="ghost">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary">
                            {{ $isEditing ? __('Confirm Changes') : __('Onboard Personnel') }}
                        </x-ui.button>
                    </div>
                </form>
</x-dialog>
@endif
</div>