<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\SystemSetting;

new class extends Component {
    use WithFileUploads;

    public $activeYear;
    public $logoUpload;
    public $systemOwner;
    public string $institutionLevel = 'ministry';
    public string $reportRecipient = '';
    public $implementedBy;
    public bool $emailNotifications = false;

    /** The mail server (MailSettings): host, port, security, username, from_address, from_name, delivery */
    public array $mail = [];

    /** A new password; empty keeps the saved one */
    public string $mailPassword = '';

    public bool $mailHasPassword = false;

    public bool $mailClearPassword = false;
    public $enableTestMode;
    public array $appTexts = [];

    public function mount()
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        $this->activeYear = \App\Support\FiscalYear::current() ?? date('Y');

        $this->systemOwner         = SystemSetting::get('system_owner', '');
        $this->institutionLevel    = \App\Support\Institution::level();
        $this->reportRecipient     = (string) SystemSetting::get('report_recipient', '');
        $this->implementedBy       = SystemSetting::get('implemented_by', '');

        $this->emailNotifications     = \App\Support\EmailNotifications::enabled();
        $server                       = \App\Support\MailSettings::current();
        $this->mailHasPassword        = $server['has_password'];
        unset($server['has_password']);
        $this->mail                   = $server;
        $this->enableTestMode         = SystemSetting::get('enable_paa_testing_mode', false);

        $empty = ['en' => '', 'pt' => '', 'tet' => ''];

        foreach (['org_name' => 'org_name', 'org_abbr' => 'org_abbr', 'name' => 'app_name', 'tagline' => 'app_tagline', 'description' => 'app_description'] as $field => $dbKey) {
            $saved = SystemSetting::get($dbKey);
            $this->appTexts[$field] = is_array($saved) ? $saved : $empty;
        }
    }

    /**
     * A picture is chosen: it is checked and becomes the logo and the browser icon at once. The page is loaded again so
     * that the menu, and the icon in the browser tab, show it straight away.
     */
    public function updatedLogoUpload(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate(['logoUpload' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=32,min_height=32']]);

        try {
            \App\Support\Branding::store($this->logoUpload);
        } catch (\RuntimeException $e) {
            $this->addError('logoUpload', $e->getMessage());

            return;
        }
        $this->reset('logoUpload');
        session()->flash('success', __('Logo updated.'));
        $this->redirect(route('management.settings').'#logo');
    }

    /** Back to the application's own logo and icon. */
    public function removeLogo(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        \App\Support\Branding::clear();
        $this->reset('logoUpload');
        session()->flash('success', __('Logo removed.'));
        $this->redirect(route('management.settings').'#logo');
    }

    public function saveSettings()
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $this->validate([
            'systemOwner'           => 'nullable|string|max:255',
            'institutionLevel'      => 'required|in:ministry,subordinate',
            'reportRecipient'       => 'nullable|string|max:255',
            'implementedBy'         => 'nullable|string|max:255',
            'emailNotifications'    => 'boolean',
            'enableTestMode'        => 'boolean',
        ] + $this->mailRules());

        SystemSetting::set('system_owner',         $this->systemOwner,         'string', 'Copyright / footer owner name');
        SystemSetting::set('institution_level',    $this->institutionLevel,    'string', 'ministry or subordinate (directorate / gabinete inside a ministry)');
        SystemSetting::set('report_recipient',     trim($this->reportRecipient), 'string', 'Who receives the approved plan when the institution is not a ministry');
        SystemSetting::set('implemented_by',       $this->implementedBy,       'string', 'Implementing partner / vendor name');

        SystemSetting::set(\App\Support\EmailNotifications::KEY, (bool) $this->emailNotifications, 'boolean', 'Also e-mail every in-app notification (queued) to people who have not opted out');
        \App\Support\MailSettings::save($this->mail, $this->mailPassword, $this->mailClearPassword);
        $this->mailHasPassword = \App\Support\MailSettings::current()['has_password'];
        $this->reset('mailPassword', 'mailClearPassword');
        SystemSetting::set('enable_paa_testing_mode',        (bool) $this->enableTestMode,         'boolean', 'Enable testing mode for PAA planning');

        SystemSetting::set('org_name',        $this->appTexts['org_name'],     'json', 'Organization / institution name (localized)');
        SystemSetting::set('org_abbr',        $this->appTexts['org_abbr'],     'json', 'Organization abbreviation (localized)');
        SystemSetting::set('app_name',        $this->appTexts['name'],         'json', 'Application name (localized)');
        SystemSetting::set('app_tagline',     $this->appTexts['tagline'],      'json', 'Application tagline / subtitle (localized)');
        SystemSetting::set('app_description', $this->appTexts['description'],  'json', 'Application description (localized)');

        $this->dispatch('toast', message: __('System settings updated successfully.'), type: 'success');
    }

    /** @return array<string, mixed> */
    private function mailRules(): array
    {
        return [
            'mail.host'         => 'nullable|string|max:255',
            'mail.port'         => 'nullable|required_with:mail.host|integer|between:1,65535',
            'mail.security'     => 'required|in:'.implode(',', array_keys(\App\Support\MailSettings::SECURITY)),
            'mail.username'     => 'nullable|string|max:255',
            'mail.from_address' => 'nullable|required_with:mail.host|email|max:255',
            'mail.from_name'    => 'nullable|string|max:255',
            'mail.delivery'     => 'required|in:'.implode(',', \App\Support\MailSettings::DELIVERY),
            'mailPassword'      => 'nullable|string|max:255',
        ];
    }

    /**
     * Send a test e-mail to the administrator with the server as it is in the form now (saved or not), right away,
     * so that a wrong host, port or password shows at once with the server's own answer.
     */
    public function testMail(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $this->validate($this->mailRules());
        $to = auth()->user()->email;

        if (trim((string) $this->mail['host']) !== '') {
            $password = $this->mailPassword !== '' ? $this->mailPassword : ($this->mailClearPassword ? null : \App\Support\MailSettings::savedPassword());
            \App\Support\MailSettings::use($this->mail, $password);
        } elseif (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->dispatch('toast', type: 'error', message: __('No mail server is set, here or in the .env file: e-mails are only written to the log. Fill in the server above.'));

            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::raw(
                __('This is a test e-mail from :app. If you read it, the mail server settings work.', ['app' => config('app.name')]),
                fn ($m) => $m->to($to)->subject(__('Test e-mail from :app', ['app' => config('app.name')]))
            );
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: __('The test e-mail could not be sent: :m', ['m' => \Illuminate\Support\Str::limit($e->getMessage(), 300)]));

            return;
        }
        $this->dispatch('toast', type: 'success', message: __('A test e-mail was sent to :email. Check the inbox (and the spam folder).', ['email' => $to]));
    }
}; ?>

<div class="space-y-6">
    <x-system-tabs />
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">{{ __('General Settings') }}</h1>
            <p class="page-subtitle">{{ __('Who the institution is, how the plan is approved, and how people are told') }}</p>
        </div>
        <div class="flex gap-3">
            <x-ui.button wire:click="saveSettings" variant="black" shadow="lg" className="text-white">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
        {{-- the four parts of this page --}}
        <nav aria-label="{{ __('On this page') }}" class="lg:sticky lg:top-24 lg:self-start">
            <ol class="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
                @foreach([['identity', __('Institution and texts')], ['logo', __('Logo and browser icon')], ['approval', __('Level and approval')], ['notifications', __('Notifications and testing')]] as $i => [$anchor, $label])
                    <li><a href="#{{ $anchor }}" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-semibold text-zinc-600" aria-hidden="true">{{ $i + 1 }}</span>{{ $label }}</a></li>
                @endforeach
            </ol>
            <p class="mt-4 hidden text-xs leading-relaxed text-zinc-500 lg:block">{{ __('Save Settings saves everything on this page except the logo, which is saved with its own button.') }}</p>
        </nav>

        <div class="space-y-8">
            <section id="identity" class="scroll-mt-6">
            <x-ui.card padding="p-8">
                <x-slot name="header">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Institution and texts') }}</h3>
                        <p class="text-xs text-zinc-500 font-medium mt-1">{{ __('The names and texts shown on the sign-in page and in the menu, in the three languages, and the credits at the foot of the page') }}</p>
                    </div>
                </x-slot>
                <div x-data="{ tab: 'tet' }">
                    <!-- Language Tabs -->
                    <div class="flex gap-2 mb-8">
                        @foreach(['tet' => 'Tetum', 'pt' => 'Português', 'en' => 'English'] as $code => $label)
                            <button type="button" @click="tab = '{{ $code }}'"
                                :class="tab === '{{ $code }}' ? 'bg-indigo-600 text-white shadow-sm ring-1 ring-indigo-600' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 ring-1 ring-zinc-200'"
                                class="px-5 py-2 rounded-xl text-xs font-bold transition-all">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    @foreach(['tet' => 'Tetum', 'pt' => 'Português', 'en' => 'English'] as $locale => $localeLabel)
                    <div x-show="tab === '{{ $locale }}'" x-cloak>
                        <div class="space-y-7">

                            <!-- Organisation -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Organisation') }}
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Institution Full Name')" class="field-label" />
                                        <x-text-input wire:model="appTexts.org_name.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Institution Name" />
                                    </div>
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Abbreviation')" class="field-label" />
                                        <x-text-input wire:model="appTexts.org_abbr.{{ $locale }}"
                                            class="w-full"
                                            placeholder="MOF" />
                                    </div>
                                </div>
                            </div>

                            <!-- Application Name -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Application Name') }}
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Title')" class="field-label" />
                                        <x-text-input wire:model="appTexts.name.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Strategic Budget" />
                                    </div>
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Tagline / Subtitle')" class="field-label" />
                                        <x-text-input wire:model="appTexts.tagline.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Planning & Control" />
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Description') }}
                                </p>
                                <textarea wire:model="appTexts.description.{{ $locale }}" rows="3"
                                    class="w-full px-4 py-3 rounded-xl bg-zinc-50 border border-zinc-100 text-sm font-bold text-zinc-900 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all resize-none outline-none"
                                    placeholder="Unified platform for annual activity planning..."></textarea>
                            </div>

                        </div>
                    </div>
                    @endforeach

                    <div class="pt-8 mt-8 border-t border-zinc-100">
                        <p class="text-xs font-semibold text-zinc-400 mb-5 flex items-center gap-2">
                            <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Footer Credits') }}
                            <span class="text-zinc-500 font-medium normal-case tracking-normal">{{ __('(shown in login page copyright)') }}</span>
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-2">
                                <x-input-label for="systemOwner" :value="__('System Owner')" class="field-label" />
                                <x-text-input id="systemOwner" wire:model="systemOwner"
                                    class="w-full"
                                    placeholder="Institution Name" />
                                <x-input-error :messages="$errors->get('systemOwner')" />
                            </div>
                            <div class="space-y-2">
                                <x-input-label for="implementedBy" :value="__('Implemented By')" class="field-label" />
                                <x-text-input id="implementedBy" wire:model="implementedBy"
                                    class="w-full"
                                    placeholder="Tech Partner" />
                                <x-input-error :messages="$errors->get('implementedBy')" />
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>
            </section>
            <section id="logo" class="scroll-mt-6">
            <x-ui.card padding="p-8">
                <x-slot name="header">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Logo and browser icon') }}</h3>
                        <p class="text-xs text-zinc-500 font-medium mt-1">{{ __('Shown in the menu and on the sign-in page; a browser icon is made from it') }}</p>
                    </div>
                </x-slot>
                    <div>
                        @php $currentLogo = \App\Support\Branding::logoUrl(); $ownLogo = \App\Support\Branding::hasOwnLogo(); @endphp
                        <div class="flex flex-wrap items-start gap-6">
                            <div class="flex h-28 w-28 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 p-2">
                                @if($currentLogo)
                                    <img src="{{ $currentLogo }}" alt="{{ __('The current logo') }}" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-center text-xs text-zinc-500">{{ __('No logo') }}</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1 space-y-3">
                                <div>
                                    <x-input-label for="logoUpload" :value="__('Logo of the institution')" class="field-label" />
                                    <input id="logoUpload" type="file" wire:model="logoUpload" accept="image/png,image/jpeg,image/webp"
                                        class="mt-1 block w-full text-sm text-zinc-600 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                                    <p class="mt-1 text-xs text-zinc-500">{{ __('PNG, JPG or WebP, up to 2 MB, at least 32 pixels. It is used as soon as it is chosen: in the menu, on the sign-in page, and as the browser icon (favicon).') }}</p>
                                    <div wire:loading wire:target="logoUpload" class="mt-1 text-xs font-medium text-indigo-700">{{ __('Uploading...') }}</div>
                                    <x-input-error :messages="$errors->get('logoUpload')" />
                                    @unless($ownLogo)
                                        <p class="mt-2 text-xs text-zinc-500">{{ __('Shown now: the application\'s own logo, until the institution chooses its own.') }}</p>
                                    @endunless
                                </div>
                                @if($ownLogo)
                                    <div class="flex flex-wrap gap-3">
                                        <x-ui.button type="button" variant="secondary" shadow="none" wire:click="removeLogo" wire:confirm="{{ __('Remove the logo of the institution? The application\'s own logo is shown again.') }}">{{ __('Remove logo') }}</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>


            </x-ui.card>
            </section>
            <section id="approval" class="scroll-mt-6">
            <x-ui.card padding="p-8">
                <x-slot name="header">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Level and approval') }}</h3>
                        <p class="text-xs text-zinc-500 font-medium mt-1">{{ __('What kind of institution this is, and how an activity is certified and approved') }}</p>
                    </div>
                </x-slot>
                <div class="space-y-8">
                    <!-- Institution level: decides who approves and who receives the plan -->
                    <div>
                        <p class="text-xs font-semibold text-zinc-400 mb-5 flex items-center gap-2">
                            <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Institution level') }}
                        </p>
                        <div class="space-y-4">
                            <label class="flex items-start gap-3 text-sm text-zinc-700">
                                <input type="radio" wire:model.live="institutionLevel" value="ministry" class="mt-1">
                                <span>
                                    <span class="font-medium text-zinc-900">{{ __('A ministry') }}</span>
                                    <span class="block text-xs text-zinc-500">{{ __('A whole ministry: several directorates, each finalizing its own plan.') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 text-sm text-zinc-700">
                                <input type="radio" wire:model.live="institutionLevel" value="subordinate" class="mt-1">
                                <span>
                                    <span class="font-medium text-zinc-900">{{ __('A directorate or gabinete inside a ministry') }}</span>
                                    <span class="block text-xs text-zinc-500">{{ __('One directorate or gabinete: its plan is sent to the ministry.') }}</span>
                                </span>
                            </label>
                            @if($institutionLevel === 'subordinate')
                                <div class="space-y-2 pl-7">
                                    <x-input-label for="reportRecipient" :value="__('Plan is sent to')" class="field-label" />
                                    <x-text-input id="reportRecipient" wire:model="reportRecipient"
                                        class="w-full"
                                        placeholder="{{ __('The ministry or the team there that prepares the budget') }}" />
                                    <x-input-error :messages="$errors->get('reportRecipient')" />
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- The approval rules are with the other rules of the cycle -->
                    <p class="pt-6 mt-6 border-t border-zinc-100 text-sm text-zinc-600">
                        {{ __('The final approval is given by the head of the directorate, for the plans of their own directorate.') }}
                        <a href="{{ route('management.optional-rules') }}" wire:navigate class="font-medium text-indigo-700 hover:underline">{{ __('The approval rules are in Optional rules.') }}</a>
                    </p>


                </div>
            </x-ui.card>
            </section>
            <section id="notifications" class="scroll-mt-6">
            <x-ui.card padding="p-8">
                <x-slot name="header">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Notifications and testing') }}</h3>
                        <p class="text-xs text-zinc-500 font-medium mt-1">{{ __('How people hear about what waits for them, and the practice area') }}</p>
                    </div>
                </x-slot>
                <div class="space-y-8">
                    <div
                        class="flex items-center justify-between p-6 bg-zinc-50/50 rounded-xl border border-zinc-100">
                        <div class="max-w-md">
                            <h4 class="text-xs font-semibold text-zinc-900 mb-1">
                                {{ __('Send notifications by e-mail') }}
                            </h4>
                            <p class="text-xs text-zinc-500 font-medium leading-relaxed">
                                {{ __('Besides the bell and the notifications page, every notification is e-mailed to the people who have not opted out in their profile. Set the mail server below.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="emailNotifications" class="sr-only peer" aria-label="{{ __('Send notifications by e-mail') }}">
                            <div
                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600">
                            </div>
                        </label>
                    </div>

                    @if($emailNotifications)
                        <div class="space-y-4 rounded-xl border border-zinc-200 p-6">
                            <div>
                                <h4 class="text-xs font-semibold text-zinc-900">{{ __('Mail server') }}</h4>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('The server that sends the e-mails (SMTP), as your e-mail provider or IT gives it. Left empty, the settings of the server installation (.env file) are used.') }}</p>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-6">
                                <div class="sm:col-span-3">
                                    <label for="mail-host" class="field-label">{{ __('Server (host)') }}</label>
                                    <input id="mail-host" type="text" wire:model="mail.host" class="flux-input" placeholder="smtp.example.gov.tl" autocomplete="off">
                                    @error('mail.host')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-1">
                                    <label for="mail-port" class="field-label">{{ __('Port') }}</label>
                                    <input id="mail-port" type="number" min="1" max="65535" wire:model="mail.port" class="flux-input">
                                    @error('mail.port')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="mail-security" class="field-label">{{ __('Security') }}</label>
                                    <select id="mail-security" wire:model="mail.security" class="flux-input">
                                        <option value="starttls">{{ __('STARTTLS (port 587)') }}</option>
                                        <option value="ssl">{{ __('SSL/TLS (port 465)') }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="mail-user" class="field-label">{{ __('User name') }} <span class="font-normal text-zinc-500">{{ __('(optional)') }}</span></label>
                                    <input id="mail-user" type="text" wire:model="mail.username" class="flux-input" autocomplete="off">
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="mail-pass" class="field-label">{{ __('Password') }}</label>
                                    <input id="mail-pass" type="password" wire:model="mailPassword" class="flux-input" autocomplete="new-password"
                                        placeholder="{{ $mailHasPassword ? __('Saved. Leave empty to keep it.') : '' }}">
                                    @if($mailHasPassword)
                                        <label class="mt-1 flex items-center gap-2 text-xs text-zinc-600"><input type="checkbox" wire:model="mailClearPassword" class="rounded border-zinc-300"> {{ __('Remove the saved password') }}</label>
                                    @endif
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="mail-from" class="field-label">{{ __('Sender address') }}</label>
                                    <input id="mail-from" type="email" wire:model="mail.from_address" class="flux-input" placeholder="paa@example.gov.tl">
                                    @error('mail.from_address')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="mail-from-name" class="field-label">{{ __('Sender name') }} <span class="font-normal text-zinc-500">{{ __('(optional)') }}</span></label>
                                    <input id="mail-from-name" type="text" wire:model="mail.from_name" class="flux-input">
                                </div>
                            </div>
                            <fieldset class="space-y-2">
                                <legend class="field-label">{{ __('Delivery') }}</legend>
                                <label class="flex items-start gap-2 text-sm text-zinc-700"><input type="radio" wire:model="mail.delivery" value="queue" class="mt-1">
                                    <span>{{ __('Through the queue worker') }} <span class="block text-xs text-zinc-500">{{ __('Recommended where a queue worker runs (the Docker installation has one): a slow mail server never holds anyone up.') }}</span></span></label>
                                <label class="flex items-start gap-2 text-sm text-zinc-700"><input type="radio" wire:model="mail.delivery" value="now" class="mt-1">
                                    <span>{{ __('Right away') }} <span class="block text-xs text-zinc-500">{{ __('Nothing else to run; each action waits while its e-mails are sent. A mail server that fails never stops the action.') }}</span></span></label>
                            </fieldset>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-ui.button variant="secondary" shadow="none" wire:click="testMail" wire:loading.attr="disabled" wire:target="testMail">
                                    <span wire:loading.remove wire:target="testMail">{{ __('Send a test e-mail to me') }}</span>
                                    <span wire:loading wire:target="testMail">{{ __('Sending…') }}</span>
                                </x-ui.button>
                                <p class="text-xs text-zinc-500">{{ __('Tests what is in the form now, before saving. Save Settings keeps it.') }}</p>
                            </div>
                        </div>
                    @endif

                    <div
                        class="flex items-center justify-between p-6 bg-amber-50 rounded-xl border border-amber-100">
                        <div class="max-w-md">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="bg-amber-100 text-amber-700 text-xs px-2 py-0.5 rounded-full font-bold">BETA</span>
                                <h4 class="text-xs font-semibold text-amber-900 ">
                                    {{ __('Enable PAA Testing Mode') }}
                                </h4>
                            </div>
                            <p class="text-xs text-amber-800 font-medium leading-relaxed">
                                {{ __('For practice and testing: activities can be planned for any year (otherwise only the current year and the next), and the Testing Zone menu appears. Its test data is excluded from all reports and totals. Switch it off for real use.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="enableTestMode" class="sr-only peer" aria-label="{{ __('Testing mode') }}">
                            <div
                                class="w-11 h-6 bg-amber-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600">
                            </div>
                        </label>
                    </div>
                </div>

            </x-ui.card>
            </section>
        </div>
    </div>
</div>
