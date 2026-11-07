<?php

use App\Support\ApprovalRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Approval lists became an optional rule, off by default: an institution that already has lists keeps the rule on. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('approver_assignments')->exists()) {
            $rules = ApprovalRules::current();
            (new ApprovalRules($rules->parentValidation, $rules->budgetConfirmation, $rules->segregation, true))->save();
        }
    }

    public function down(): void
    {
        // the rule stays as it is: it does nothing without the page that reads it
    }
};
