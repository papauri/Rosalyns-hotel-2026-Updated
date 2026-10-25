<?php

/**
 * Child charge form fields, shared by Room Management (room types) and Individual Rooms.
 *
 * One rule per room type; an individual room can override it ("Same as room type" = inherit).
 * Posted names: child_charge_mode, child_price_multiplier, child_charge_amount, free_children, max_children.
 * rh_child_rule_post() in includes/pricing.php reads them back and validates them.
 *
 * The mode select shows the matching amount field with an inline handler (no extra script needed, so it also
 * works after the admin SPA swaps the page). Pages that fill the form from JavaScript set the values and then
 * dispatch a 'change' event on the mode select.
 */
if (!function_exists('rh_child_charge_fields')) {
    /**
     * @param string $prefix     id prefix, e.g. 'edit', 'add', 'ir'
     * @param bool   $individual true for an individual room (adds "Same as room type" and inherit placeholders)
     */
    function rh_child_charge_fields(string $prefix, bool $individual, string $currency): void
    {
        $p = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
        $cur = htmlspecialchars($currency, ENT_QUOTES, 'UTF-8');
        $toggle = "var b=this.closest('.cc-box');"
            . "b.querySelector('.cc-pct').style.display=(this.value==='percent')?'':'none';"
            . "b.querySelector('.cc-amt').style.display=(this.value==='fixed')?'':'none';";
        $toggle = htmlspecialchars($toggle, ENT_QUOTES, 'UTF-8');
        $freePlaceholder = $individual ? 'Same as room type' : '0';
        $maxPlaceholder = $individual ? 'Same as room type' : 'No limit';
        ?>
        <div class="cc-box">
            <div class="form-row">
                <div class="form-group">
                    <label for="<?php echo $p; ?>ChildMode">Child charge</label>
                    <select name="child_charge_mode" id="<?php echo $p; ?>ChildMode" onchange="<?php echo $toggle; ?>">
                        <?php if ($individual): ?><option value="">Same as room type</option><?php endif; ?>
                        <option value="percent">% of the room rate</option>
                        <option value="fixed">Fixed amount per child per night</option>
                    </select>
                </div>
                <div class="form-group cc-pct"<?php echo $individual ? ' style="display:none"' : ''; ?>>
                    <label for="<?php echo $p; ?>ChildPct">Percent of the room rate (%)</label>
                    <input type="number" name="child_price_multiplier" id="<?php echo $p; ?>ChildPct" step="0.01" min="0" max="100" data-money<?php echo $individual ? '' : ' value="50.00"'; ?> placeholder="<?php echo $individual ? 'Same as room type' : '50.00'; ?>">
                    <small class="helper-text"><?php echo $individual ? 'Blank follows the room type. 0 = children stay free in this room.' : '0% = children stay free.'; ?></small>
                </div>
                <div class="form-group cc-amt" style="display:none">
                    <label for="<?php echo $p; ?>ChildAmt">Amount per child per night (<?php echo $cur; ?>)</label>
                    <input type="number" name="child_charge_amount" id="<?php echo $p; ?>ChildAmt" step="0.01" min="0" data-money placeholder="0.00 = free">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="<?php echo $p; ?>ChildFree">Free children (first N in each room)</label>
                    <input type="number" name="free_children" id="<?php echo $p; ?>ChildFree" min="0" max="20" step="1"<?php echo $individual ? '' : ' value="0"'; ?> placeholder="<?php echo $freePlaceholder; ?>">
                </div>
                <div class="form-group">
                    <label for="<?php echo $p; ?>ChildMax">Most children in one room</label>
                    <input type="number" name="max_children" id="<?php echo $p; ?>ChildMax" min="0" max="20" step="1" placeholder="<?php echo $maxPlaceholder; ?>">
                </div>
            </div>
            <div class="field-hint">0% or a fixed amount of 0 means children stay free. Leave the last box blank for no limit beyond the room's guest capacity.<?php echo $individual ? ' Anything left as "Same as room type" follows the room type.' : ''; ?></div>
        </div>
        <?php
    }
}
