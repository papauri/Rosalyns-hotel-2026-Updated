<?php
/**
 * Coffee Bar Display System (CDS) — Touchscreen ticket board for the coffee bar.
 *
 * Thin wrapper around kds.php that re-skins the engine for the coffee bar.
 * Permission: cds_view (granted to admin, manager, coffee_staff).
 */
$STATION       = 'coffee_bar';
$STATION_LABEL = 'CDS';
$STATION_TITLE = 'CDS — Coffee Bar Display';
$STATION_PERM  = 'cds_view';
$STATION_ICON  = 'fa-mug-hot';
$STATION_COLOR = '#6f4e37';
$STATION_ROLE  = 'coffee_staff';
require __DIR__ . '/kds.php';

