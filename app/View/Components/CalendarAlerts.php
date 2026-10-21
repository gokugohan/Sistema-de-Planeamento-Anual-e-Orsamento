<?php

namespace App\View\Components;

use App\Services\CalendarAlerts as Service;
use App\Support\FiscalYear;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The dates that concern the person, so that nobody finds out about a deadline after it has passed. */
class CalendarAlerts extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    public int $forYear;

    public function __construct(?int $year = null)
    {
        $this->forYear = $year ?? FiscalYear::current();
        if ($user = auth()->user()) {
            $this->alerts = app(Service::class)->for($user, $this->forYear);
        }
    }

    public function render(): View
    {
        return view('components.calendar-alerts');
    }
}
