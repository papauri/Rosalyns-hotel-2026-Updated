<?php
/**
 * Bar Display System (BDS) — Touchscreen ticket board for bartenders.
 *
 * Thin wrapper around kds.php that re-skins the engine for the bar station.
 * Permission: bds_view (granted to admin, manager, bar_staff).
 */
$STATION       = 'bar';
$STATION_LABEL = 'BDS';
$STATION_TITLE = 'BDS — Bar Display';
$STATION_PERM  = 'bds_view';
$STATION_ICON  = 'fa-cocktail';
$STATION_COLOR = '#5e35b1';
$STATION_ROLE  = 'bar_staff';
require __DIR__ . '/kds.php';

