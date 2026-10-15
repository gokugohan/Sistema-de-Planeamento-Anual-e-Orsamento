<?php

namespace App\View\Components;

use App\Services\ApprovalInbox;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Needs your attention": the handful of things the signed-in user can act on right now, each with a
 * count and a direct link. The same items as the Approvals page (see ApprovalInbox), as a short list.
 */
class NextActions extends Component
{
    /** @var array<int, array{group: string, count: int, text: string, url: string, tone: string}> */
    public array $items = [];

    public function __construct()
    {
        if ($user = auth()->user()) {
            $this->items = app(ApprovalInbox::class)->items($user);
        }
    }

    public function render(): View
    {
        return view('components.next-actions');
    }
}
