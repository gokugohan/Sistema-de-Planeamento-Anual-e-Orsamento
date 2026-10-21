<?php

namespace App\View\Components;

use App\Services\YearAtAGlance as Service;
use App\Support\FiscalYear;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** "The year at a glance": where planning, approval, finalizing and reporting stand, in plain sentences, with the way to act. */
class YearAtAGlance extends Component
{
    /** @var array{year: int, phases: array<int, array<string, mixed>>, now: array<int, string>}|null */
    public ?array $glance = null;

    /** @param  array<int, array<string, mixed>>|null  $items  the person's inbox, when the page already has it (saves asking twice) */
    public function __construct(public ?int $year = null, ?array $items = null)
    {
        if ($user = auth()->user()) {
            $this->glance = app(Service::class)->for($user, $this->year ?? FiscalYear::current(), $items);
        }
    }

    public function render(): View
    {
        return view('components.year-at-a-glance');
    }
}
