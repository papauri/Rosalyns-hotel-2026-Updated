<?php

/**
 * Rosalyns Beach Hotel — UAT Guide PDF Generator
 * Run: php scripts/generate-uat-guide.php
 * Output: docs/Rosalyns_UAT_Guide_2026.pdf
 */

require_once __DIR__ . '/../vendor/autoload.php';

$outputPath = __DIR__ . '/../docs/Rosalyns_UAT_Guide_2026.pdf';

// ─── Brand colours ────────────────────────────────────────────────────────────
const C_GOLD    = [177, 130,  71];
const C_BROWN   = [138, 119,  95];
const C_INK     = [35,  31,  28];
const C_CREAM   = [243, 236, 228];
const C_BG      = [247, 243, 238];
const C_MUTED   = [94,  85,  77];
const C_WHITE   = [255, 255, 255];
const C_SUCCESS = [40, 167,  69];
const C_DANGER  = [220,  53,  69];

const CONTACT_EMAIL = 'johnpaulchirwa@pro-managed-it.com';
const CONTACT_WA    = '+353 860 081 635';
const HOTEL_PHONE   = '+265 888 226 665';
const HOTEL_ADDRESS = 'Matuwi Village, Mangochi, Malawi';
const BASE_URL      = 'https://promanaged-it.com/rosalyns-hotel/';
const ADMIN_URL     = 'https://promanaged-it.com/rosalyns-hotel/admin/';

// ─── Document class ───────────────────────────────────────────────────────────
class UATDoc extends TCPDF
{
    public function Header()
    {
        if ($this->getPage() === 1) return;
        $this->SetFillColor(...C_GOLD);
        $this->Rect(0, 0, 210, 3, 'F');
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(...C_MUTED);
        $this->SetXY(20, 5);
        $this->Cell(0, 5, 'ROSALYNS BEACH HOTEL — USER ACCEPTANCE TESTING GUIDE 2026', 0, 0, 'R');
    }

    public function Footer()
    {
        if ($this->getPage() === 1) return;
        $this->SetY(-14);
        $this->SetFillColor(...C_CREAM);
        $this->Rect(0, $this->GetY() - 1, 210, 15, 'F');
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(...C_MUTED);
        $this->Cell(
            0,
            8,
            'Rosalyns Beach Hotel  |  ' . HOTEL_ADDRESS . '  |  ' . HOTEL_PHONE .
                '  |  Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(),
            0,
            0,
            'C'
        );
    }
}

$pdf = new UATDoc('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ProManaged IT');
$pdf->SetAuthor('ProManaged IT');
$pdf->SetTitle('Rosalyns Beach Hotel — UAT Guide 2026');
$pdf->SetSubject('User Acceptance Testing Guide');
$pdf->SetKeywords('UAT, Rosalyns, Hotel, Testing');
$pdf->SetMargins(20, 16, 20);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true, 22);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// ─── Layout helpers ───────────────────────────────────────────────────────────

function sectionHeading(UATDoc $pdf, string $title): void
{
    $pdf->SetY($pdf->GetY() + 5);
    $y = $pdf->GetY();
    $pdf->SetFillColor(...C_GOLD);
    $pdf->Rect(20, $y, 4, 9, 'F');
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetXY(27, $y + 1);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
    $pdf->SetDrawColor(...C_CREAM);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->SetY($pdf->GetY() + 3);
}

function subHeading(UATDoc $pdf, string $title): void
{
    $pdf->SetY($pdf->GetY() + 4);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(...C_BROWN);
    $pdf->SetX(20);
    $pdf->Cell(0, 7, $title, 0, 1, 'L');
    $pdf->SetY($pdf->GetY() + 1);
}

function bodyText(UATDoc $pdf, string $text): void
{
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetX(20);
    $pdf->MultiCell(170, 6, $text, 0, 'L');
    $pdf->SetY($pdf->GetY() + 2);
}

/**
 * Draws a left-accented cream box with a heading and bullet lines.
 * Height is auto-calculated from content.
 */
function labelBox(UATDoc $pdf, string $heading, array $lines, bool $isDanger = false): void
{
    $c = $isDanger ? C_DANGER : C_GOLD;
    $h = 8 + 8 + count($lines) * 7 + 6; // top padding + heading row + lines + bottom padding
    $startY = $pdf->GetY() + 2;
    $pdf->SetFillColor(...C_CREAM);
    $pdf->SetDrawColor(...$c);
    $pdf->SetLineWidth(0.4);
    $pdf->RoundedRect(20, $startY, 170, $h, 3, '1111', 'DF');
    $pdf->SetFillColor(...$c);
    $pdf->Rect(20, $startY, 4, $h, 'F');
    $pdf->SetXY(28, $startY + 5);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...$c);
    $pdf->Cell(0, 7, strtoupper($heading), 0, 1, 'L');
    foreach ($lines as $line) {
        $pdf->SetX(28);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(...C_INK);
        $pdf->Cell(0, 7, $line, 0, 1, 'L');
    }
    $pdf->SetY($startY + $h + 3);
}

function credRow(UATDoc $pdf, string $label, string $value, bool $mono = false): void
{
    $pdf->SetX(28);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->Cell(48, 7, $label, 0, 0, 'L');
    $pdf->SetFont($mono ? 'courier' : 'helvetica', 'B', $mono ? 11 : 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(0, 7, $value, 0, 1, 'L');
}

function urlRow(UATDoc $pdf, string $label, string $url, string $note): void
{
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(44, 7, $label, 0, 0, 'L');
    $pdf->SetFont('courier', '', 8);
    $pdf->SetTextColor(0, 85, 170);
    $pdf->Cell(106, 7, $url, 0, 0, 'L', false, $url);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->Cell(20, 7, $note, 0, 1, 'R');
}

function roleRow(UATDoc $pdf, string $role, string $user, string $desc): void
{
    $startY = $pdf->GetY();
    $pdf->SetXY(22, $startY);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(40, 6, $role, 0, 0, 'L');
    $pdf->SetFont('courier', 'B', 9);
    $pdf->SetTextColor(...C_GOLD);
    $pdf->Cell(38, 6, $user, 0, 0, 'L');
    $pdf->SetXY(100, $startY);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->MultiCell(88, 5.5, $desc, 0, 'L');
    $rowH = max(7, $pdf->GetY() - $startY);
    $pdf->SetY($startY + $rowH);
    $pdf->SetDrawColor(230, 222, 214);
    $pdf->SetLineWidth(0.2);
    $pdf->Line(22, $pdf->GetY(), 190, $pdf->GetY());
}

function scenarioRow(UATDoc $pdf, int $n, string $title, string $steps, string $expected): void
{
    $pdf->SetY($pdf->GetY() + 2);

    // Gold badge
    $badgeY = $pdf->GetY();
    $pdf->SetFillColor(...C_GOLD);
    $pdf->Rect(20, $badgeY, 9, 7, 'F');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetXY(20, $badgeY + 0.5);
    $pdf->Cell(9, 6, (string)$n, 0, 0, 'C');

    // Scenario title
    $pdf->SetXY(31, $badgeY);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(159, 7, $title, 0, 1, 'L');

    // Steps
    $pdf->SetX(22);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->MultiCell(168, 5.5, $steps, 0, 'L');

    // Expected result
    $pdf->SetY($pdf->GetY() + 1);
    $pdf->SetX(22);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(...C_SUCCESS);
    $pdf->Write(5, 'Expected:  ');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_INK);
    $pdf->MultiCell(158, 5, $expected, 0, 'L');

    // Row divider
    $pdf->SetY($pdf->GetY() + 1);
    $pdf->SetDrawColor(230, 222, 214);
    $pdf->SetLineWidth(0.2);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
}

function scenarioEstimatedHeight(UATDoc $pdf, string $title, string $steps, string $expected): float
{
    $titleLines = max(1, $pdf->getNumLines($title, 159));
    $stepsLines = max(1, $pdf->getNumLines($steps, 168));
    $expectedLines = max(1, $pdf->getNumLines($expected, 158));

    // Approximate total row height from fixed paddings and line heights used in scenarioRow().
    return 2 + ($titleLines * 7) + ($stepsLines * 5.5) + 1 + ($expectedLines * 5) + 2;
}

function drawScenarioTableHeader(UATDoc $pdf): void
{
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFillColor(...C_INK);
    $pdf->Rect(20, $pdf->GetY(), 170, 8, 'F');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_GOLD);
    $pdf->SetX(22);
    $pdf->Cell(8, 8, '#', 0, 0, 'C');
    $pdf->Cell(162, 8, 'SCENARIO / STEPS / EXPECTED RESULT', 0, 1, 'L');
    $pdf->SetY($pdf->GetY() + 1);
}

function checkRow(UATDoc $pdf, string $item, string $where, string $note): void
{
    $startY = $pdf->GetY();
    $pdf->SetDrawColor(...C_GOLD);
    $pdf->SetLineWidth(0.35);
    $pdf->Rect(20, $startY + 1, 5, 5, 'D');
    $pdf->SetXY(28, $startY);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(52, 6, $item, 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->Cell(56, 6, $where, 0, 0, 'L');
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(...C_BROWN);
    $pdf->MultiCell(54, 5.5, $note, 0, 'L');
    $rowH = max(7, $pdf->GetY() - $startY);
    $pdf->SetY($startY + $rowH + 1);
}

// ══════════════════════════════════════════════════════════════════════════════
// COVER PAGE
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// Background
$pdf->SetFillColor(...C_BG);
$pdf->Rect(0, 0, 210, 297, 'F');
// Top bar
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(0, 0, 210, 10, 'F');
// Bottom bar
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(0, 287, 210, 10, 'F');
// Left accent bar
$pdf->SetFillColor(...C_BROWN);
$pdf->Rect(0, 0, 5, 297, 'F');

// Hotel name (shifted right of side bar, use full printable width)
$pdf->SetFont('times', 'B', 40);
$pdf->SetTextColor(...C_INK);
$pdf->SetXY(10, 56);
$pdf->Cell(190, 22, 'Rosalyns Beach Hotel', 0, 1, 'C');

// Tagline
$pdf->SetFont('helvetica', 'I', 14);
$pdf->SetTextColor(...C_BROWN);
$pdf->SetX(10);
$pdf->Cell(190, 8, 'Experience the Difference', 0, 1, 'C');

// Divider
$lineY = $pdf->GetY() + 5;
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(1);
$pdf->Line(55, $lineY, 155, $lineY);

// Document title
$pdf->SetY($lineY + 12);
$pdf->SetFont('helvetica', 'B', 24);
$pdf->SetTextColor(...C_GOLD);
$pdf->SetX(10);
$pdf->Cell(190, 13, 'USER ACCEPTANCE TESTING GUIDE', 0, 1, 'C');

// Subtitle
$pdf->SetFont('helvetica', '', 12);
$pdf->SetTextColor(...C_MUTED);
$pdf->SetX(10);
$pdf->Cell(190, 7, 'Hotel Management System — Pre-Production Review', 0, 1, 'C');

// Date badge
$pdf->SetY($pdf->GetY() + 10);
$pdf->SetFillColor(...C_GOLD);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetX(65);
$pdf->Cell(80, 11, '  May 2026  —  UAT Phase', 0, 1, 'C', true, '', 2);

// Two info cards side by side
$cardTopY = $pdf->GetY() + 16;
$cardH    = 56;

// Left card — Prepared For
$pdf->SetFillColor(...C_CREAM);
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.4);
$pdf->RoundedRect(12, $cardTopY, 89, $cardH, 3, '1111', 'DF');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(12, $cardTopY, 4, $cardH, 'F');
$pdf->SetXY(20, $cardTopY + 6);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(0, 5, 'PREPARED FOR', 0, 1, 'L');
$pdf->SetX(20);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_INK);
$pdf->Cell(0, 8, 'Rosalyns Beach Hotel', 0, 1, 'L');
$pdf->SetX(20);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MUTED);
$pdf->Cell(0, 5.5, 'Management Team', 0, 1, 'L');
$pdf->SetX(20);
$pdf->Cell(0, 5.5, HOTEL_ADDRESS, 0, 1, 'L');
$pdf->SetX(20);
$pdf->Cell(0, 5.5, HOTEL_PHONE, 0, 1, 'L');

// Right card — Prepared By
$pdf->SetFillColor(...C_CREAM);
$pdf->RoundedRect(109, $cardTopY, 89, $cardH, 3, '1111', 'DF');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(109, $cardTopY, 4, $cardH, 'F');
$pdf->SetXY(117, $cardTopY + 6);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(0, 5, 'PREPARED BY', 0, 1, 'L');
$pdf->SetX(117);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_INK);
$pdf->Cell(0, 8, 'ProManaged IT', 0, 1, 'L');
$pdf->SetX(117);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MUTED);
$pdf->Cell(0, 5.5, 'JP Chirwa', 0, 1, 'L');
$pdf->SetX(117);
$pdf->Cell(0, 5.5, CONTACT_EMAIL, 0, 1, 'L');
$pdf->SetX(117);
$pdf->Cell(0, 5.5, 'WhatsApp: ' . CONTACT_WA, 0, 1, 'L');

// ══════════════════════════════════════════════════════════════════════════════
// PAGE 2 — INTRODUCTION & SCOPE
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHeading($pdf, '1.  Introduction & Purpose of UAT');
bodyText(
    $pdf,
    "This document is your guide for the User Acceptance Testing (UAT) phase of the Rosalyns Beach Hotel Management System. " .
        "UAT is the final stage before the system goes live with real guests and real transactions."
);
bodyText(
    $pdf,
    "During UAT, you — the hotel team — will use the system exactly as you would day-to-day. Your goal is to confirm that " .
        "every feature works correctly for your specific operations and to raise any issues before production launch."
);
bodyText(
    $pdf,
    "UAT is NOT about finding technical bugs — it is about verifying that the system does what YOU need it to do, in the way YOU need to do it."
);

labelBox($pdf, 'UAT Period', [
    'Start Date:    16 May 2026',
    'Target End:    30 May 2026',
    'Go-Live:       1 June 2026',
    'Issues to:     ' . CONTACT_EMAIL . '  |  WhatsApp ' . CONTACT_WA,
]);

sectionHeading($pdf, '2.  What Is In Scope for UAT');

$modules = [
    ['Front Desk / Reservations', 'Room bookings, check-in, check-out, invoicing, payments, refunds'],
    ['Restaurant POS',            'Walk-in, dine-in, takeaway and room service orders, tabs, shift close'],
    ['Kitchen Display (KDS)',     'Viewing and bumping food orders from the kitchen screen'],
    ['Bar Display (BDS)',         'Viewing and bumping bar orders'],
    ['Coffee Display (CDS)',      'Viewing and bumping coffee orders'],
    ['Conference & Events',       'Enquiry management, payments, balance tracking'],
    ['Housekeeping',              'Room status management — clean / dirty / maintenance'],
    ['Stock Management',          'Receiving stock, wastage logging, stock counts'],
    ['Reports & Analytics',       'Z-reports, payment summaries, stock reports, occupancy'],
    ['Reviews Management',         'View, approve, and reject guest reviews; import web feedback via scraper'],
    ['Admin / Settings',          'Site settings, user management, menu management'],
];

$pdf->SetY($pdf->GetY() + 2);
foreach ($modules as $i => $m) {
    $pdf->SetFillColor(...($i % 2 === 0 ? C_CREAM : C_BG));
    $pdf->Rect(20, $pdf->GetY(), 170, 7, 'F');
    $pdf->SetX(25);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_BROWN);
    $pdf->Cell(60, 7, '  ›  ' . $m[0], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(110, 7, $m[1], 0, 1, 'L');
}

// ══════════════════════════════════════════════════════════════════════════════
// PAGE 3 — SYSTEM URLS & LOGIN
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHeading($pdf, '3.  System URLs');
bodyText($pdf, 'All system screens are accessible at the following URLs. Bookmark these on every device you plan to use.');

$urls = [
    ['Public Website',  BASE_URL,                                              'Guest-facing'],
    ['Admin Dashboard', ADMIN_URL,                                             'All staff login here'],
    ['Room Bookings',   ADMIN_URL . 'bookings.php',                            'Front desk'],
    ['POS Till',        ADMIN_URL . 'pos.php',                                 'Restaurant'],
    ['Kitchen Display', ADMIN_URL . 'kds.php',                                 'Kitchen screen'],
    ['Bar Display',     ADMIN_URL . 'bds.php',                                 'Bar screen'],
    ['Coffee Display',  ADMIN_URL . 'cds.php',                                 'Coffee screen'],
    ['Housekeeping',    ADMIN_URL . 'housekeeping.php',                        'Housekeeping'],
    ['Stock Dashboard', ADMIN_URL . 'stock-dashboard.php',                     'Stock team'],
    ['Reports',         ADMIN_URL . 'reports.php',                             'Management'],
    ['Shift Z-Report',  ADMIN_URL . 'shift-close-report.php',                  'End-of-shift'],
    ['Reviews',         ADMIN_URL . 'reviews.php',                                'Manager'],
];

// Table container
$urlTableY = $pdf->GetY();
$urlTableH = count($urls) * 7 + 14; // 7 per row + header + padding
$pdf->SetFillColor(...C_CREAM);
$pdf->RoundedRect(20, $urlTableY, 170, $urlTableH, 2, '1111', 'F');
$pdf->SetY($urlTableY + 3);

// Column headers
$pdf->SetX(20);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(44, 6, 'SCREEN', 0, 0, 'L');
$pdf->Cell(106, 6, 'URL', 0, 0, 'L');
$pdf->Cell(20, 6, 'WHO', 0, 1, 'R');
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.3);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
$pdf->SetY($pdf->GetY() + 1);

foreach ($urls as $u) {
    urlRow($pdf, $u[0], $u[1], $u[2]);
}

$pdf->SetY($pdf->GetY() + 5);

sectionHeading($pdf, '4.  UAT Login Credentials');
bodyText($pdf, 'Use the following account for all UAT testing. It has Manager-level access to every operational feature.');

// Credentials box — 4 data rows * 7 + heading 8 + warning 6 + padding 14 = 64
$credsY = $pdf->GetY() + 2;
$credsH = 64;
$pdf->SetFillColor(...C_CREAM);
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.5);
$pdf->RoundedRect(20, $credsY, 170, $credsH, 3, '1111', 'DF');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(20, $credsY, 4, $credsH, 'F');

$pdf->SetXY(28, $credsY + 5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(0, 8, 'UAT TEST ACCOUNT — MANAGER ACCESS', 0, 1, 'L');

credRow($pdf, 'Login URL',    ADMIN_URL, false);
credRow($pdf, 'Username',     'rosalyns_uat', true);
credRow($pdf, 'Password',     'RosalynsUAT2026!', true);
credRow($pdf, 'Access Level', 'Manager — full operational access', false);

$pdf->SetY($pdf->GetY() + 3);
$pdf->SetX(28);
$pdf->SetFont('helvetica', 'BI', 8);
$pdf->SetTextColor(...C_DANGER);
$pdf->Cell(0, 5, 'IMPORTANT: These credentials are for UAT only. They will be removed / changed before go-live.', 0, 1, 'L');

$roles = [
    ['Front Desk',       'receptionist',  'Bookings, check-in/out, payments, invoices, housekeeping'],
    ['Restaurant Staff', 'fnb1 / fnb2',   'POS till, tab management, order dispatch, shift close'],
    ['Chef / Kitchen',   'chef1 / chef2', 'KDS — kitchen ticket display and bump board'],
    ['Bar Staff',        'bar1 / bar2',   'BDS — bar ticket display and bump board'],
    ['Coffee Bar',       'coffee1',       'CDS — coffee display and bump board'],
    ['Manager (UAT)',    'rosalyns_uat',  'All of the above plus reports, stock, shift close'],
];

$pdf->SetY($credsY + $credsH + 5);

// Keep the full staff role section together to avoid overflow into blank-looking pages.
$roleTableH = count($roles) * 12 + 10;
$rolesSectionEstimate = 16 + 10 + $roleTableH + 10; // heading + body + table + note
if ($pdf->GetY() + $rolesSectionEstimate > 270) {
    $pdf->AddPage();
}

sectionHeading($pdf, '5.  Staff Role Accounts');
bodyText($pdf, 'These accounts are pre-configured. Each role sees only what is relevant to their job.');

$roleTableY = $pdf->GetY();
$pdf->SetFillColor(...C_CREAM);
$pdf->RoundedRect(20, $roleTableY, 170, $roleTableH, 2, '1111', 'F');
$pdf->SetY($roleTableY + 3);

$pdf->SetX(22);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(40, 6, 'ROLE', 0, 0, 'L');
$pdf->Cell(38, 6, 'USERNAME', 0, 0, 'L');
$pdf->Cell(0,  6, 'CAN ACCESS', 0, 1, 'L');
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.3);
$pdf->Line(22, $pdf->GetY(), 190, $pdf->GetY());
$pdf->SetY($pdf->GetY() + 1);

foreach ($roles as $r) {
    roleRow($pdf, $r[0], $r[1], $r[2]);
}

$pdf->SetY($pdf->GetY() + 3);
$pdf->SetX(24);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(...C_MUTED);
$pdf->Cell(0, 5, 'Contact ProManaged IT for individual staff passwords before go-live.', 0, 1, 'L');

// ══════════════════════════════════════════════════════════════════════════════
// PAGE 4 — KEY SYSTEM CONCEPTS
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHeading($pdf, '6.  Key System Concepts — Read Before Testing');

subHeading($pdf, '6.1  POS Order Types');
bodyText($pdf, 'The POS supports four order types. Each behaves differently:');

$types = [
    ['Walk-in',      'Customer pays immediately at the counter. Click Pay & Send — payment is collected now, order fires to kitchen.'],
    ['Takeaway',     'Same as walk-in. Click Pay & Send — customer pays before they leave.'],
    ['Dine-in',      'Customer is seated at a table. Click Fire — order goes to the kitchen and the tab stays OPEN. Payment is collected at the end of the meal from the Tabs tray.'],
    ['Room Service', "Order goes to a guest's room. Click Fire — charge is added to the guest folio and settled at checkout."],
];

foreach ($types as $t) {
    $pdf->SetY($pdf->GetY() + 1);
    $pdf->SetX(24);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(...C_GOLD);
    $pdf->Cell(30, 6, $t[0] . ':', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->MultiCell(136, 6, $t[1], 0, 'L');
}

$pdf->SetY($pdf->GetY() + 3);
$ruleY = $pdf->GetY();
$pdf->SetFillColor(...C_CREAM);
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.5);
$pdf->RoundedRect(20, $ruleY, 170, 17, 2, '1111', 'DF');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(20, $ruleY, 4, 17, 'F');
$pdf->SetXY(28, $ruleY + 3.5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(...C_BROWN);
$pdf->Cell(16, 6, 'RULE:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9.5);
$pdf->SetTextColor(...C_INK);
$pdf->MultiCell(
    142,
    5.2,
    'Walk-in & Takeaway orders = PAY & SEND (settle now)  |  Dine-in & Room Service orders = FIRE (settle later)',
    0,
    'L'
);
$pdf->SetY($ruleY + 21);

subHeading($pdf, '6.2  Tabs Tray — Dine-in Settlement');
bodyText(
    $pdf,
    'When a dine-in order is fired, it appears in the Tabs tray (top right of POS). ' .
        'When the guest is ready to pay, click the table in the Tabs tray to reopen it, then click Pay Existing to settle.'
);

subHeading($pdf, '6.3  POS / KDS Live Notifications');
bodyText(
    $pdf,
    'The POS polls KDS/BDS/CDS updates every second. Ready-for-collection and station-message popups stay visible for 2 minutes, ' .
        'can be closed with the X button, and include a small ordered-item summary. POS and station devices have sound settings for alert choice and volume. ' .
        'Station tickets show FOH/orderer and guest/service metadata, action buttons spin while saving, and friendly dialogs explain already-paid tabs, short-stock ingredients, and no-pending-action states. ' .
        'The My Orders panel in the bottom-left updates within about 1 second when stations mark items ready, collected, or served.'
);

subHeading($pdf, '6.4  Shift Close — Z-Report');
bodyText(
    $pdf,
    'At the end of each shift, staff click Close Shift on the POS and declare how much cash is in the till. ' .
        'The system calculates expected cash, variance (over/short), revenue by payment method, order type, ' .
        'and top-selling items. A printable Z-Report is generated — this must be signed and filed each day.'
);

subHeading($pdf, '6.5  Booking Payments & Refunds');
bodyText(
    $pdf,
    'Room and conference bookings support multiple payments (deposit, balance, extras) and partial or full refunds. ' .
        'Each payment generates an invoice. Refunds are tracked against the original payment and the booking balance updates automatically.'
);

subHeading($pdf, '6.6  VAT');
bodyText(
    $pdf,
    'The system applies 16.5% VAT to all applicable transactions. VAT is calculated server-side — it cannot be overridden from the POS. ' .
        'Invoices show a clear VAT breakdown.'
);

subHeading($pdf, '6.7  Currency');
bodyText($pdf, 'All amounts are in Malawian Kwacha (MWK). Format: MWK X,XXX — not dollars, not euros.');

// ══════════════════════════════════════════════════════════════════════════════
// PAGE 5 — UAT TEST SCENARIOS
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHeading($pdf, '7.  UAT Test Scenarios');
bodyText($pdf, "Work through each scenario in order. Tick the checkbox when done. Flag anything that doesn't match the Expected result.");

drawScenarioTableHeader($pdf);

$scenarios = [
    [
        'Walk-in Coffee Order',
        "1. Open POS\n2. Select Walk-in as order type\n3. Add 2x Coffee to the cart\n4. Click Pay & Send\n5. Select Cash, enter the amount",
        'Receipt shown. Order appears on Coffee Display (CDS). Payment recorded in the shift total.',
    ],
    [
        'Dine-in Table Order — Fire to Kitchen, Settle from Tabs',
        "1. POS → Dine-in, enter table number\n2. Add food and drinks\n3. Click Fire\n4. Check KDS (food) and BDS (drinks) — tickets must appear on the right screens\n5. Kitchen marks items Ready / Collect / Serve\n6. Confirm POS ready popup stays visible and My Orders updates\n7. Return to POS → open Tabs tray (top right) → find the table\n8. Click to reopen → Pay Existing → settle",
        'Order fires to correct stations. POS shows ready popup within about 1 second with item summary. My Orders updates promptly. Settlement clears the tab and records payment.',
    ],
    [
        'Room Service Order',
        "1. POS → Room Service\n2. Enter the room number\n3. Add items\n4. Click Fire\n5. Verify ticket appears on KDS",
        'Order fires to KDS. Charge linked to the guest folio. Settled at checkout.',
    ],
    [
        'Takeaway Order — Mobile Money',
        "1. POS → Takeaway\n2. Add items to cart\n3. Click Pay & Send\n4. Select Mobile Money as payment method",
        'Payment confirmed. Order fires to kitchen. Mobile money amount recorded in the shift report.',
    ],
    [
        'Shift Close — Z-Report',
        "1. At end of a test shift, click Close Shift in the POS\n2. Declare the cash amount in the till\n3. Review the Z-Report summary on screen\n4. Click Print",
        'Z-Report shows total revenue, payment method breakdown, cash variance, and top 10 items. Printout matches shift totals.',
    ],
    [
        'Room Booking + Deposit Payment + Invoice',
        "1. Admin → Bookings → Create Booking\n2. Select room, dates, and guest name\n3. Confirm the booking\n4. Add a deposit payment\n5. Open the invoice",
        'Booking created at correct rate. Deposit recorded. Invoice shows correct total with VAT breakdown.',
    ],
    [
        'Booking Partial Refund',
        "1. Open an existing paid booking\n2. Admin → Payments → click Refund on a payment\n3. Enter a partial refund amount\n4. Confirm",
        'Refund recorded. Booking balance updated. Refund invoice generated.',
    ],
    [
        'Conference Enquiry — Deposit then Balance',
        "1. Admin → Conference Management → open an enquiry\n2. Add a deposit payment\n3. Add the final balance payment\n4. Check the outstanding balance shows zero",
        'Balance is correct at each step. Both payments visible. Balance reaches zero after full payment.',
    ],
    [
        'Housekeeping Status Update',
        "1. Log in as receptionist\n2. Open Housekeeping\n3. Mark one room as Cleaned\n4. Mark another as Maintenance Required",
        'Room status updates immediately. Colour-coded change visible on the room dashboard.',
    ],
    [
        'Stock Receipt Delivery',
        "1. Admin → Stock → Receive Stock\n2. Select supplier and ingredient\n3. Enter quantity and unit cost\n4. Submit",
        'Stock level increases. Batch recorded with correct cost. Stock dashboard reflects new quantity.',
    ],
    [
        'KDS / BDS / CDS Display',
        "1. Open KDS on a separate device or browser tab\n2. Fire an order from POS\n3. Watch the ticket appear within a few seconds\n4. Mark items Ready / Collect / Serve\n5. Check POS notification popup and My Orders panel",
        'Ticket appears promptly. POS receives station updates within about 1 second. Ready popup lists ordered items, stays for 2 minutes, and can be closed with X.',
    ],
    [
        'Payments CSV Export',
        "1. Admin → Payments\n2. Filter by today's date\n3. Click Export CSV\n4. Open the file in Microsoft Excel",
        'CSV downloads. All columns present. No formula injection characters (=, +, -) in amount cells.',
    ],
    [
        'Review Scraper — Find Web Reviews',
        "1. Log in as Manager\n2. Go to Admin → Reviews\n3. Click Find Web Reviews\n4. Search term pre-filled with the hotel name — click Search\n5. Review the candidate list returned from DuckDuckGo / Bing\n6. For a positive candidate, set rating, handle, and status (Pending or Approved)\n7. Click Import",
        'Candidates list appears within 15 seconds. Each card shows title, snippet, and source URL. Import saves the review; if status is Approved it immediately appears on the public website reviews section.',
    ],
    [
        'Review Management — Approve, Reject, and Delete',
        "1. Admin → Reviews\n2. Find a Pending review (imported or guest-submitted)\n3. Click Approve — confirm status changes to Approved\n4. Find another review — click Reject — confirm it disappears from public view\n5. Delete a test review",
        'Status transitions work correctly. Approved reviews appear on the public website. Rejected and deleted reviews do not.',
    ],
];

foreach ($scenarios as $i => $s) {
    $estimated = scenarioEstimatedHeight($pdf, $s[0], $s[1], $s[2]);
    if ($pdf->GetY() + $estimated > 270) {
        $pdf->AddPage();
        sectionHeading($pdf, '7.  UAT Test Scenarios (Continued)');
        drawScenarioTableHeader($pdf);
    }
    scenarioRow($pdf, $i + 1, $s[0], $s[1], $s[2]);
}

// ══════════════════════════════════════════════════════════════════════════════
// PAGE 6 — PRE-GO-LIVE CHECKLIST & NOTES
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

sectionHeading($pdf, '8.  Pre-Go-Live Configuration Checklist');
bodyText($pdf, 'Before the system goes live, confirm every item below. Contact ProManaged IT for anything that needs changing.');

$checks = [
    ['Hotel name & address',          'Admin → Settings',              'Rosalyns Beach Hotel, Matuwi Village, Mangochi'],
    ['Phone numbers',                 'Admin → Settings',              '+265 888 226 665 and all cell numbers'],
    ['Hotel email address',           'Admin → Settings → Email',      'Main guest contact email'],
    ['VAT rate',                      'Admin → Settings → Finance',    '16.5% — confirm with your accountant'],
    ['Room types & rates',            'Admin → Room Management',       'All room names, capacities, and nightly prices'],
    ['Menu items & prices',           'Admin → Menu Management',       'All items, categories, prices, station routing'],
    ['Staff user accounts',           'Admin → User Management',       'Every staff member has own login with correct role'],
    ['Station assignments',           'Admin → Station Settings',      'Chef → KDS, Bar → BDS, Coffee → CDS'],
    ['WhatsApp number',               'Admin → Settings → WhatsApp',   'Guest notification number confirmed'],
    ['SMTP / Email settings',         'Admin → Settings → Email',      'Send a test email — confirm delivery'],
    ['Booking confirmation template', 'Admin → Email Templates',       'Review wording before real guests receive it'],
    ['Cash float',                    'Physical setup with management', 'Starting float agreed for each shift'],
    ['Printer / receipt test',        'POS device — browser print',    'Test print a receipt and a Z-report before go-live'],
    ['Reviews — scraper search',       'Admin → Reviews → Find Web Reviews', 'Run a test search; confirm candidates return for hotel name'],
    ['Reviews — initial import',       'Admin → Reviews',               'Import 2–3 quality web reviews; approve and confirm they show on the public website'],
];

$pdf->SetY($pdf->GetY() + 2);
foreach ($checks as $c) {
    checkRow($pdf, $c[0], $c[1], $c[2]);
}

$pdf->SetY($pdf->GetY() + 4);
sectionHeading($pdf, '9.  How to Report Issues During UAT');
bodyText($pdf, "When something doesn't work correctly, please provide:");

$reportItems = [
    '1.  Which screen you were on  (e.g. "POS — Walk-in order")',
    '2.  What you did — step by step',
    '3.  What you expected to happen',
    '4.  What actually happened',
    '5.  A screenshot if possible  (phone camera is fine)',
    '6.  The approximate time  (so we can check system logs)',
];
foreach ($reportItems as $item) {
    $pdf->SetX(26);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->Cell(0, 6, $item, 0, 1, 'L');
}

$pdf->SetY($pdf->GetY() + 2);
labelBox($pdf, 'Report Issues To', [
    'JP Chirwa — ProManaged IT',
    'Email:     ' . CONTACT_EMAIL,
    'WhatsApp:  ' . CONTACT_WA,
]);

sectionHeading($pdf, '10.  Important Notes');

$notes = [
    [
        'Live database.',
        'Any entries created during UAT are in the live database. Mark all test entries clearly (e.g. guest name: "UAT TEST"). ProManaged IT will clean test data before go-live.',
    ],
    [
        'No real payments.',
        'Use MWK 1.00 test amounts. No real money should flow through the system until go-live.',
    ],
    [
        'Passwords will change.',
        'The UAT password (RosalynsUAT2026!) is temporary. All staff accounts will be re-issued with secure, private passwords before go-live.',
    ],
    [
        'Internet required.',
        'The system is cloud-hosted. A stable connection is needed. Mobile 3G/4G is sufficient for POS and KDS screens.',
    ],
    [
        'Load shedding.',
        'The POS and KDS have offline indicators. Complete open transactions before power is cut where possible.',
    ],
];

foreach ($notes as $n) {
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetX(22);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(...C_GOLD);
    $pdf->Cell(40, 6, $n[0], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_INK);
    $pdf->MultiCell(128, 6, $n[1], 0, 'L');
}

// ══════════════════════════════════════════════════════════════════════════════
// BACK COVER
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();

$pdf->SetFillColor(...C_INK);
$pdf->Rect(0, 0, 210, 297, 'F');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(0, 0, 210, 10, 'F');
$pdf->Rect(0, 287, 210, 10, 'F');
$pdf->SetFillColor(...C_BROWN);
$pdf->Rect(0, 0, 5, 297, 'F');

$pdf->SetY(85);
$pdf->SetFont('times', 'I', 30);
$pdf->SetTextColor(...C_GOLD);
$pdf->Cell(0, 16, '"Experience the Difference."', 0, 1, 'C');

$pdf->SetY($pdf->GetY() + 12);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(...C_CREAM);
$pdf->Cell(0, 8, 'Rosalyns Beach Hotel', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(...C_BROWN);
$pdf->Cell(0, 7, HOTEL_ADDRESS, 0, 1, 'C');
$pdf->Cell(0, 7, HOTEL_PHONE, 0, 1, 'C');

$pdf->SetY($pdf->GetY() + 18);
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.4);
$pdf->Line(70, $pdf->GetY(), 140, $pdf->GetY());

$pdf->SetY($pdf->GetY() + 10);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MUTED);
$pdf->Cell(0, 6, 'System built and supported by', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(...C_WHITE);
$pdf->Cell(0, 8, 'ProManaged IT', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MUTED);
$pdf->Cell(0, 6, CONTACT_EMAIL . '  |  WhatsApp ' . CONTACT_WA, 0, 1, 'C');

// ══════════════════════════════════════════════════════════════════════════════
// OUTPUT
// ══════════════════════════════════════════════════════════════════════════════
$pdf->Output($outputPath, 'F');
echo "PDF written to: " . $outputPath . PHP_EOL;
