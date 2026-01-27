<?php

use Illuminate\Support\Facades\DB;

// Drop the index if it exists
try {
    DB::statement('DROP INDEX IF EXISTS fiscal_envelopes_temp_fiscal_year_unique');
    echo "Index dropped successfully.\n";
} catch (\Exception $e) {
    echo "Error dropping index: " . $e->getMessage() . "\n";
}

// Drop the temp table if it exists
try {
    DB::statement('DROP TABLE IF EXISTS fiscal_envelopes_temp');
    echo "Temp table dropped successfully.\n";
} catch (\Exception $e) {
    echo "Error dropping table: " . $e->getMessage() . "\n";
}
