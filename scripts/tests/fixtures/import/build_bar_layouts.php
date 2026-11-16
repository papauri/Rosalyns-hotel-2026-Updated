<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * SYNTHETIC corpus of stock-list layouts (owner 2026-11-17: "read files like this without so many questions" + "diverse enough for other
 * patterns or abnormal column names"). Every name and price is invented. Used by import_engine_test.php (pure) and import_stock_child.php (DB).
 *
 *   php scripts/tests/fixtures/import/build_bar_layouts.php [dir]   writes the layouts as files (for a browser upload check), default: system temp
 *
 * fx_corpus() = [key => ['name' => file name, 'bytes' => file bytes, 'expect' => what the tests assert]].
 */

require_once __DIR__ . '/build_fixture.php';

function fx_csv(array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);
    return $s;
}

/** 1) the bar tracker layout: title in A1, headings on row 3, headings merged in A or typed in B, a total line, a value column at SELLING price. */
function fx_bar_tracker_xlsx(): string
{
    $S = 'fx_S';
    $N = 'fx_N';
    $item = function ($name, $qty, $price, $sell, $bought, $per, $served) use ($S, $N) {
        $r = ['A' => $S($name), 'B' => $N($qty), 'C' => $N($price), 'F' => $S($bought), 'G' => $N($per)];
        if ($sell !== null) {
            $r['D'] = $N($sell);
            $r['E'] = $N($qty * $sell);
        } else {
            $r['E'] = $N(0);
        }
        if ($served !== null) {
            $r['H'] = $S($served);
        }
        return $r;
    };
    $rows = [
        1 => ['A' => $S('LAKESIDE LODGE - BAR STOCK TRACKER (SYNTHETIC)')],
        3 => ['A' => $S('Item Name'), 'B' => $S('Total Balance'), 'C' => $S('Unit Price (MWK)'), 'D' => $S('Selling price '), 'E' => $S('Stock Value'),
              'F' => $S('Unit Bought As'), 'G' => $S('Total Per Unit'), 'H' => $S('Unit sold/Served by')],
        4 => ['B' => $S('LOCAL LAGERS')],                                                   // heading typed in B, name cell empty
        5 => $item('Zebra Lager', 40, 48000, 5000, 'Crate', 24, 'Bottle'),                  // 48,000 / 24 = 2,000 per bottle
        6 => $item('Kudu Lager', 10, 36000, 4000, 'Crate', 12, 'Bottle'),                   // 3,000 per bottle
        7 => $item('Over Lager', 5, 120000, 3000, 'Case', 24, 'Bottle'),                    // 5,000 per bottle > selling 3,000: asked (that row only)
        8 => ['A' => $S('SOFT DRINKS'), 'E' => $N(0)],                                       // merged heading A8:D8
        9 => $item('Fizz Tonic', 6, 2500, 6000, 'Can', 24, 'Can'),                          // same unit: 2,500 per can, 24 to a case is information only
        10 => ['A' => $S('SPIRITS'), 'E' => $N(0), 'F' => $S('Bottle')],                    // merged heading A10:D10 with a word in the bought-as column
        11 => $item('Moon Gin', 12, 30000, 2500, 'Bottle', 1, 'Shot'),                      // served by the shot: counted and costed per bottle
        12 => ['B' => $S('HOUSE WINE')],
        13 => $item('Valley Red', 3, 60000, 9000, 'Casket', 1, 'Glass'),                    // served by the glass: per casket
        14 => ['A' => $S('RED BY BOTTLE'), 'E' => $N(0)],
        15 => $item('Twin Peak', 2, 20000, 60000, 'Bottle', 1, 'Bottle'),
        16 => ['A' => $S('WHITE BY BOTTLE'), 'E' => $N(0)],
        17 => $item('Twin Peak', 0, 20000, 60000, 'Bottle', 1, 'Bottle'),                    // same name under another heading: two items
        18 => $item('Paper Straw', 50, 0, 500, 'Pack', 100, 'Each'),                        // price 0 = no price
        19 => ['A' => $S('TOTAL'), 'E' => $N(493000)],
    ];
    return fx_xlsx(['sheets' => [['name' => 'Sheet1', 'merges' => ['A1:D1', 'A8:D8', 'A10:D10', 'A14:D14', 'A16:C16'], 'rows' => $rows]]]);
}

/** 6) a two-sheet workbook: kitchen (plain unit + cost) and bar (bought as / units per pack / served by). */
function fx_two_sheet_xlsx(): string
{
    $S = 'fx_S';
    $N = 'fx_N';
    $kitchen = [
        1 => ['A' => $S('Item'), 'B' => $S('Qty'), 'C' => $S('Unit'), 'D' => $S('Unit Cost')],
        2 => ['A' => $S('Wheat Flour'), 'B' => $N(20), 'C' => $S('kg'), 'D' => $N(1200)],
        3 => ['A' => $S('Brown Sugar'), 'B' => $N(15), 'C' => $S('kg'), 'D' => $N(1500)],
        4 => ['A' => $S('Sea Salt'), 'B' => $N(5), 'C' => $S('kg'), 'D' => $N(400)],
    ];
    $bar = [
        1 => ['A' => $S('Item'), 'B' => $S('Qty'), 'C' => $S('Bought As'), 'D' => $S('Units per pack'), 'E' => $S('Served By'), 'F' => $S('Price')],
        2 => ['A' => $S('Sky Lager'), 'B' => $N(48), 'C' => $S('Crate'), 'D' => $N(24), 'E' => $S('Bottle'), 'F' => $N(36000)],
        3 => ['A' => $S('Star Cola'), 'B' => $N(30), 'C' => $S('Crate'), 'D' => $N(24), 'E' => $S('Bottle'), 'F' => $N(12000)],
        4 => ['A' => $S('Reed Rum'), 'B' => $N(6), 'C' => $S('Bottle'), 'D' => $N(1), 'E' => $S('Tot'), 'F' => $N(25000)],
    ];
    return fx_xlsx(['sheets' => [['name' => 'Kitchen', 'rows' => $kitchen], ['name' => 'Bar', 'rows' => $bar]]]);
}

function fx_corpus(): array
{
    return [
        'bar_tracker' => ['name' => 'bar tracker.xlsx', 'bytes' => fx_bar_tracker_xlsx()],
        'mangled' => ['name' => 'mangled.csv', 'bytes' => fx_csv([
            ['Product', 'Bal', 'U/Price', 'S.Price', 'Unit Bougth As', 'Pcs per ctn', 'Sold by'],
            ['Alpha Juice', 10, 24000, 3000, 'Ctn', 12, 'Bottle'],           // 2,000 per bottle
            ['Beta Soda', 20, 12000, 1500, 'Crate', 24, 'Bottle'],           // 500
            ['Gamma Water', 30, 500, 800, 'Bottle', 1, 'Bottle'],            // 500 (per one)
            ['Delta Crisps', 5, 6000, 1000, 'Box', 10, 'Packet'],           // 600 per packet
        ])],
        'pack_in_name' => ['name' => 'pack in name.csv', 'bytes' => fx_csv([
            ['Item', 'Qty', 'Unit', 'Unit Price'],
            ['Sun Cola 330ml', 24, 'can', 600], ['Sun Orange 330ml', 12, 'can', 650], ['Sun Lemon 330ml', 10, 'can', 620],
            ['Sun Ginger 330ml', 8, 'can', 640], ['Sun Grape 330ml', 9, 'can', 610],
            ['Hill Lager 6 pack', 6, 'can', 4200],                           // the name says 6 pack and the price is ~7x its peers: 700 per can, decided
            ['Mountain Water 24x500ml', 5, 'can', 9600],                     // a size, no pack word: still asked
        ])],
        'pack_in_unit' => ['name' => 'pack in unit.csv', 'bytes' => fx_csv([
            ['Item', 'Qty', 'Unit', 'Price'],
            ['Tomato Paste', 4, 'Case of 24', 36000],                        // counted and priced per case (24 to a case: information)
            ['Cooking Oil', 3, 'Crate(12)', 60000],
            ['Table Napkins', 10, 'ctn/12', 9000],
            ['Farm Eggs', 5, 'Tray', 3000],
        ])],
        'unit_and_pack_price' => ['name' => 'unit and pack price.csv', 'bytes' => fx_csv([
            ['Item', 'Balance', 'Unit', 'Pack Size', 'Unit Price', 'Case Price'],
            ['Lake Lager', 48, 'bottle', 24, 1000, 24000],                    // agree: 1,000 per bottle
            ['Rift Stout', 24, 'bottle', 12, 1500, 18000],                    // agree
            ['Delta Cider', 12, 'bottle', 6, 2000, 15000],                    // 2,000 x 6 = 12,000, not 15,000: asked (that row only)
        ])],
        'two_sheets' => ['name' => 'two sheets.xlsx', 'bytes' => fx_two_sheet_xlsx()],
        'notes_first' => ['name' => 'notes first.csv', 'bytes' => fx_csv([
            ['Stock count for the month', '', '', '', '', ''],
            ['Prepared by the store keeper', '', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Description', 'Closing Balance', 'Cost Price', 'Bought In', 'Qty per Case', 'Sold As'],
            ['Mango Juice', 12, 18000, 'Case', 12, 'Bottle'],                  // 1,500 per bottle
            ['Lime Cordial', 4, 9000, 'Bottle', 1, 'Shot'],                    // 9,000 per bottle
            ['Guava Juice', 6, 24000, 'Case', 12, 'Bottle'],                   // 2,000 per bottle
        ])],
    ];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dir = $argv[1] ?? sys_get_temp_dir();
    foreach (fx_corpus() as $k => $f) {
        file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $f['name'], $f['bytes']);
        echo 'Wrote ' . $f['name'] . "\n";
    }
}
