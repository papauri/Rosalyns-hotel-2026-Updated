<?php
/**
 * Daily report e-mail sections (scripts/daily_reports.php): one section failing never stops the others.
 * Pure (no database, no settings); unit tested by scripts/tests/z_room_charge.php.
 */

if (!function_exists('rh_report_section')) {
    /**
     * Run one report builder. On any error: $log($name, $e) is called (the CLI passes rh_log_event) and the
     * section comes back not ok, so the e-mail says "could not be loaded" for it and sends the rest.
     * @return array{name:string, ok:bool, result:mixed, error:string}
     */
    function rh_report_section(string $name, callable $build, ?callable $log = null): array
    {
        try {
            return ['name' => $name, 'ok' => true, 'result' => $build(), 'error' => ''];
        } catch (Throwable $e) {
            if ($log !== null) {
                try {
                    $log($name, $e);
                } catch (Throwable $ignored) {
                    // Logging must never stop the e-mail either.
                }
            }
            return ['name' => $name, 'ok' => false, 'result' => null, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('rh_report_failed_line')) {
    /** The e-mail line for a section that could not be built (no error detail: that goes to the log). */
    function rh_report_failed_line(string $label): string
    {
        return $label . ' could not be loaded. The error has been logged; the other reports are attached.';
    }
}
