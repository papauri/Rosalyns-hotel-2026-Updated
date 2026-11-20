<?php
/**
 * Calendar page shell: title, "New booking", the Calendar | Housekeeping | Maintenance tabs and (Month view) the
 * Timeline | Month toggle. Included by admin/calendar.php only; expects $calTab, $calIsMonth, $calCanHkTab,
 * $calCanMtTab, $calHkOpenCount, $calMtOpenCount, $calUserId, $filterQs, $currentYear, $currentMonth.
 */
if (!defined('ADMIN_ACCESS')) {
    http_response_code(403);
    exit;
}
$calCanCreate = rhCanLinkTo((int)$calUserId, 'create-booking.php');
$calTabs = [['calendar', 'Calendar', 'fa-calendar-days', null]];
if ($calCanHkTab) {
    $calTabs[] = ['housekeeping', 'Housekeeping', 'fa-broom', $calHkOpenCount];
}
if ($calCanMtTab) {
    $calTabs[] = ['maintenance', 'Maintenance', 'fa-screwdriver-wrench', $calMtOpenCount];
}
?>
<div class="tl-page-head">
    <h2 class="section-title"><i class="fas fa-calendar-days" aria-hidden="true"></i> Calendar</h2>
    <?php if ($calCanCreate): ?>
        <a class="btn btn-primary tl-page-head__new" href="create-booking.php"><i class="fas fa-plus" aria-hidden="true"></i> New booking</a>
    <?php endif; ?>
</div>
<?php if (count($calTabs) > 1): ?>
    <nav class="ck-tabs tl-tabs" role="tablist" aria-label="Calendar sections">
        <?php foreach ($calTabs as [$key, $label, $icon, $count]): $active = $calTab === $key; ?>
            <a class="ck-tab<?php echo $active ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                href="calendar.php<?php echo $key === 'calendar' ? '' : '?tab=' . $key; ?>">
                <i class="fas <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($label); ?>
                <?php if ($count !== null): ?><span class="ck-tab__count"><?php echo (int)$count; ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($calIsMonth): ?>
    <div class="tl-month-bar">
        <div class="ck-tabs tl-view" role="group" aria-label="Calendar view">
            <a class="ck-tab" href="calendar.php"><i class="fas fa-bars-staggered" aria-hidden="true"></i> Timeline</a>
            <a class="ck-tab is-active" aria-current="page" href="calendar.php?view=month&amp;year=<?php echo (int)$currentYear; ?>&amp;month=<?php echo (int)$currentMonth; ?><?php echo htmlspecialchars($filterQs, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-calendar" aria-hidden="true"></i> Month</a>
        </div>
    </div>
<?php endif; ?>
