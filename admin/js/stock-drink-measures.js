/*
 * Drink measures (Step U2, owner 2026-11-19): "2 shots" / "50 ml" / "1 glass of wine" of a stock item -> its stock unit.
 * Same maths as the rh_drink_* helpers in config/database.php (keep the two in step). The screens only SHOW these figures;
 * the server works the saved figure out again from what was typed.
 * Item objects use the stock_ingredients column names: name, unit, volume_ml, measure_big_name, measure_small_name,
 * measure_per_big, measure_stock_side, cost_per_unit. A list entry is {name, ml}.
 */
(function () {
    'use strict';
    if (window.RHDrink) return;

    var MAX_N = 1000; // RH_STOCK_MEASURE_MAX_N
    var ready = true; // false = the hotel has not run migration 089: no measures at all (the server would refuse them)

    function lc(s) { return String(s == null ? '' : s).trim().toLowerCase(); }
    function round(n, dp) { var f = Math.pow(10, dp); return Math.round(n * f) / f; }

    /* ml in ONE of a stock unit that is itself a volume (ml 1, cl 10, litre 1000), else null. */
    function unitMl(unit) {
        var u = lc(unit);
        if (/^(ml|mls|millilit(re|er)s?)$/.test(u)) return 1;
        if (/^(cl|centilit(re|er)s?)$/.test(u)) return 10;
        if (/^(l|ltrs?|litres?|liters?)$/.test(u)) return 1000;
        return null;
    }

    function isWeight(unit) {
        return /^(mg|g|gm|grams?|kgs?|kilograms?|kilos?|lbs?|pounds?|oz|ounces?)$/i.test(String(unit || '').trim());
    }

    /* A unit that holds many bottles or pieces (crate, case, pack...): same list as rh_stock_unit_is_container(). */
    function isContainer(unit) {
        return /^(packets?|packs?|packages?|boxe?s?|tins?|trays?|crates?|cartons?|bags?|sachets?|buckets?|containers?|bunch(es)?|bundles?|cases?|punnets?|dozens?|dz|doz)$/i.test(String(unit || '').trim());
    }

    /* Size written in a name: "Gin 750ml" -> 750, "Vodka 1L" -> 1000, else null. */
    function nameMl(name) {
        var m = /(\d+(?:[.,]\d+)?)\s*(ml|cl|l|ltr|litres?|liters?)(?![a-z])/i.exec(String(name || ''));
        if (!m) return null;
        var v = parseFloat(m[1].replace(',', '.'));
        var u = m[2].toLowerCase();
        var ml = u === 'ml' ? v : (u === 'cl' ? v * 10 : v * 1000);
        return ml > 0 && ml <= 100000 ? ml : null;
    }

    /* The item's count measure (bottle / shot, N, which side is the stock unit), or null. */
    function itemMeasure(ing) {
        var big = String(ing.measure_big_name || '').trim();
        var small = String(ing.measure_small_name || '').trim();
        var n = parseFloat(ing.measure_per_big);
        var side = String(ing.measure_stock_side || '');
        if (!big || !small || !(n >= 1) || n > MAX_N || (side !== 'big' && side !== 'small')) return null;
        return { big: big, small: small, n: round(n, 4), side: side };
    }

    /* ml in one stock unit: a volume unit wins, else the bottle size, else a size in the name. */
    function itemMl(ing) {
        var u = unitMl(ing.unit);
        if (u !== null) return { perUnit: u, container: null, source: 'unit' };
        if (isWeight(ing.unit) || isContainer(ing.unit)) return null; // a crate of "Beer 330ml" is not 330 ml
        var v = parseFloat(ing.volume_ml);
        var src = 'item';
        if (!(v > 0)) { v = nameMl(ing.name); src = 'name'; }
        if (!(v > 0)) return null;
        var m = itemMeasure(ing);
        return { perUnit: (m && m.side === 'small') ? v / m.n : v, container: v, source: src };
    }

    /* Measures a recipe line of this item can be typed in: [{name, kind 'item'|'list'|'ml', ml, n}]. */
    function options(ing, list) {
        if (!ready || isWeight(ing.unit)) return [];
        var out = [], seen = {};
        var m = itemMeasure(ing);
        var size = itemMl(ing);
        if (m && m.side === 'big') {
            out.push({ name: m.small, kind: 'item', n: m.n, ml: (size && size.container !== null) ? round(size.container / m.n, 4) : null });
            seen[lc(m.small)] = true;
        }
        if (size) {
            (list || []).forEach(function (dm) {
                var k = lc(dm.name);
                if (!k || seen[k]) return;
                seen[k] = true;
                out.push({ name: String(dm.name), kind: 'list', ml: parseFloat(dm.ml), n: null });
            });
            if (!(size.source === 'unit' && Math.abs(size.perUnit - 1) < 1e-9)) {
                out.push({ name: 'ml', kind: 'ml', ml: 1, n: null });
            }
        }
        return out;
    }

    function find(ing, list, name) {
        var k = lc(name), opts = options(ing, list);
        for (var i = 0; i < opts.length; i++) { if (lc(opts[i].name) === k) return opts[i]; }
        return null;
    }

    /* Stock units in ONE of a measure, not rounded (for working an amount back into the measure). null = cannot. */
    function perOne(ing, list, name) {
        var o = find(ing, list, name);
        if (!o) return null;
        if (o.kind === 'item') return 1 / o.n;
        var size = itemMl(ing);
        return size ? o.ml / size.perUnit : null;
    }

    /* qty of a measure -> stock units (6 dp), or null when it cannot be worked out. Same order of sums as the server. */
    function toStock(qty, name, ing, list) {
        qty = parseFloat(qty);
        if (!isFinite(qty) || qty <= 0 || qty > 100000) return null;
        var o = find(ing, list, name);
        if (!o) return null;
        var stock;
        if (o.kind === 'item') {
            stock = qty / o.n;
        } else {
            var size = itemMl(ing);
            if (!size) return null;
            stock = qty * o.ml / size.perUnit;
        }
        stock = round(stock, 6);
        return stock > 0 && stock <= 99999999 ? stock : null;
    }

    /* "shot" -> "shots", "glass of wine" -> "glasses of wine", "ml" stays. */
    function plural(name, qty) {
        name = String(name || '');
        if (/^(ml|mls)$/i.test(name)) return 'ml';
        if (Math.abs(parseFloat(qty) - 1) < 0.0000005 || !name) return name;
        var mm = /^(\S+)(\s+of\s+.+)$/i.exec(name);
        if (mm) return plural(mm[1], qty) + mm[2];
        return /(s|x|z|ch|sh)$/i.test(name) ? name + 'es' : name + 's';
    }

    /* Option label: "shot (25 ml)", "ml", or the item's own "shot (1/30 bottle)". */
    function label(o, ing) {
        if (o.kind === 'ml') return 'ml';
        if (o.kind === 'item' && !(o.ml > 0)) return o.name + ' (' + fmt(o.n, 4) + ' in one ' + String(ing.measure_big_name || ing.unit || '') + ')';
        return o.name + ' (' + fmt(o.ml, 2) + ' ml)';
    }

    /* 0.060000 -> "0.06", 33.3333 -> "33.3" with dp=1. */
    function fmt(n, dp) {
        var s = Number(n).toFixed(dp === undefined ? 6 : dp);
        if (s.indexOf('.') >= 0) s = s.replace(/0+$/, '').replace(/\.$/, '');
        return s === '-0' ? '0' : s;
    }

    /* Each screen says whether migration 089 is in (RHDrink.setReady(false) before it is). */
    function setReady(on) { ready = !!on; }
    function isReady() { return ready; }

    window.RHDrink = { setReady: setReady, isReady: isReady, isContainer: isContainer, unitMl: unitMl, isWeight: isWeight, nameMl: nameMl, itemMeasure: itemMeasure, itemMl: itemMl,
        options: options, find: find, perOne: perOne, toStock: toStock, plural: plural, label: label, fmt: fmt };
})();
