<?php
/**
 * ProManaged IT — PMS Service Agreement Generator
 * Run: php scripts/generate-contract.php
 * Output: docs/PMS_Service_Agreement_2026.pdf
 */

require_once __DIR__ . '/../vendor/autoload.php';

$outputPath   = __DIR__ . '/../docs/PMS_Service_Agreement_2026.pdf';
$contractRef  = 'MSP-PMS-' . date('Y') . '-001';
$contractDate = date('j F Y');

define('C_GOLD',  [177, 130,  71]);
define('C_BROWN', [138, 119,  95]);
define('C_INK',   [ 35,  31,  28]);
define('C_CREAM', [243, 236, 228]);
define('C_WHITE', [255, 255, 255]);
define('C_MUTED', [ 94,  85,  77]);
define('C_LIGHT', [247, 243, 238]);
define('C_RED',   [180,  30,  30]);
define('C_GREEN', [ 30, 120,  60]);
define('C_STEEL', [ 45,  85, 140]);

define('PROVIDER_NAME',    'ProManaged IT');
define('PROVIDER_CONTACT', 'John-Paul Chirwa');
define('PROVIDER_EMAIL',   'johnpaulchirwa@pro-managed-it.com');
define('PROVIDER_PHONE',   '+353 860 081 635');
define('PROVIDER_ADDRESS', 'c/o Matuwi Village, Mangochi, Malawi');

define('CLIENT_NAME',    "Rosalyn's Beach Hotel");
define('CLIENT_ADDRESS', 'Matuwi Village, Mangochi, Malawi');
define('CLIENT_PHONE',   '+265 888 226 665');
define('CLIENT_CONTACT', '[Authorised Signatory Name]');
define('CLIENT_TITLE',   '[Title / Position]');

define('SETUP_FEE',   'MWK 4,000,000');
define('MONTHLY_FEE', 'MWK 350,000');

class ContractDoc extends TCPDF
{
    public function Header(): void
    {
        if ($this->getPage() === 1) return;
        $this->SetFillColor(...C_BROWN);
        $this->Rect(0, 0, 210, 2.5, 'F');
        $this->SetFont('helvetica', 'B', 6.5);
        $this->SetTextColor(...C_MUTED);
        $this->SetXY(20, 4);
        $this->Cell(0, 4, 'PMS SERVICE AGREEMENT  |  ' . PROVIDER_NAME . '  x  ' . CLIENT_NAME . '  |  CONFIDENTIAL', 0, 0, 'R');
    }

    public function Footer(): void
    {
        if ($this->getPage() === 1) return;
        $this->SetY(-12);
        $this->SetFillColor(...C_CREAM);
        $this->Rect(0, $this->GetY() - 0.5, 210, 13, 'F');
        $this->SetFont('helvetica', '', 6.5);
        $this->SetTextColor(...C_MUTED);
        $this->Cell(0, 6, PROVIDER_NAME . '  |  ' . PROVIDER_EMAIL . '  |  Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new ContractDoc('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(PROVIDER_NAME);
$pdf->SetAuthor(PROVIDER_NAME);
$pdf->SetTitle('PMS Service Agreement — ' . CLIENT_NAME);
$pdf->SetSubject('Property Management System Service Agreement');
$pdf->SetMargins(20, 14, 20);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function sp(ContractDoc $pdf, float $mm = 3): void
{
    $pdf->SetY($pdf->GetY() + $mm);
}

function sectionHeading(ContractDoc $pdf, string $number, string $title): void
{
    sp($pdf, 4);
    $y = $pdf->GetY();
    $pdf->SetFillColor(...C_GOLD);
    $pdf->Rect(20, $y, 4, 7, 'F');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetXY(27, $y + 0.5);
    $pdf->Cell(0, 7, $number . '.  ' . strtoupper($title), 0, 1, 'L');
    $pdf->SetDrawColor(...C_CREAM);
    $pdf->SetLineWidth(0.35);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    sp($pdf, 2);
}

function subHead(ContractDoc $pdf, string $text): void
{
    sp($pdf, 2);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(...C_BROWN);
    $pdf->SetX(20);
    $pdf->Cell(0, 5.5, $text, 0, 1, 'L');
    sp($pdf, 0.5);
}

function body(ContractDoc $pdf, string $text): void
{
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetX(20);
    $pdf->MultiCell(170, 5, $text, 0, 'J');
    sp($pdf, 1);
}

function bullet(ContractDoc $pdf, string $text, string $label = ''): void
{
    $y = $pdf->GetY();
    $pdf->SetFillColor(...C_GOLD);
    $pdf->Circle(24.5, $y + 2.2, 0.9, 0, 360, 'F');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_INK);
    if ($label !== '') {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(27, $y);
        $pdf->Cell(32, 5, $label, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(59, $y);
        $pdf->MultiCell(131, 5, $text, 0, 'L');
    } else {
        $pdf->SetXY(27, $y);
        $pdf->MultiCell(163, 5, $text, 0, 'L');
    }
    sp($pdf, 0.5);
}

function alertBox(ContractDoc $pdf, string $text, array $color): void
{
    sp($pdf, 2);
    // Measure text height first
    $pdf->SetFont('helvetica', '', 8.5);
    $lines   = $pdf->getNumLines($text, 161);
    $boxH    = max(10, $lines * 4.8 + 6);
    $y       = $pdf->GetY();
    $pdf->SetFillColor($color[0], $color[1], $color[2]);
    $pdf->Rect(20, $y, 4, $boxH, 'F');
    $pdf->SetFillColor(248, 247, 245);
    $pdf->Rect(24, $y, 166, $boxH, 'F');
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->SetXY(27, $y + 2);
    $pdf->MultiCell(161, 4.8, $text, 0, 'L');
    sp($pdf, $boxH - ($pdf->GetY() - $y) + 3);
}

function priceRow(ContractDoc $pdf, string $item, string $amount, string $note = ''): void
{
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetX(20);
    $pdf->Cell(110, 6.5, $item, 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_GOLD);
    $pdf->Cell(40, 6.5, $amount, 0, 0, 'R');
    if ($note !== '') {
        $pdf->SetFont('helvetica', 'I', 7.5);
        $pdf->SetTextColor(...C_MUTED);
        $pdf->Cell(0, 6.5, '  ' . $note, 0, 0, 'L');
    }
    $pdf->Ln();
    $pdf->SetDrawColor(...C_CREAM);
    $pdf->SetLineWidth(0.12);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    sp($pdf, 0.5);
}

function sigBlock(ContractDoc $pdf, float $x, float $y, string $party, string $name, string $role): void
{
    $pdf->SetFillColor(...C_LIGHT);
    $pdf->SetDrawColor(...C_CREAM);
    $pdf->SetLineWidth(0.25);
    $pdf->RoundedRect($x, $y, 80, 38, 1.5, '1111', 'DF');
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(...C_BROWN);
    $pdf->SetXY($x + 3, $y + 3);
    $pdf->Cell(74, 4.5, strtoupper($party), 0, 1, 'L');
    $pdf->SetDrawColor(...C_MUTED);
    $pdf->SetLineWidth(0.35);
    $pdf->Line($x + 3, $y + 19, $x + 77, $y + 19);
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->SetXY($x + 3, $y + 21);
    $pdf->Cell(74, 4, 'Signature', 0, 1, 'L');
    $pdf->Line($x + 3, $y + 28, $x + 77, $y + 28);
    $pdf->SetXY($x + 3, $y + 30);
    $pdf->Cell(74, 4, $name, 0, 1, 'L');
    $pdf->SetXY($x + 3, $y + 34);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(74, 4, $role, 0, 1, 'L');
}

// ══════════════════════════════════════════════════════════════════════════════
// COVER PAGE
// ══════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(0, 0, 210, 7, 'F');
$pdf->SetFillColor(...C_INK);
$pdf->Rect(0, 7, 210, 68, 'F');
$pdf->SetFillColor(...C_GOLD);
$pdf->Rect(0, 7, 5, 68, 'F');

$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetTextColor(...C_GOLD);
$pdf->SetXY(14, 14);
$pdf->Cell(0, 5, 'PROPERTY MANAGEMENT SYSTEM (PMS)  —  SERVICE AGREEMENT', 0, 1, 'L');

$pdf->SetFont('helvetica', 'B', 24);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetXY(14, 22);
$pdf->Cell(0, 11, PROVIDER_NAME, 0, 1, 'L');

$pdf->SetFont('helvetica', '', 13);
$pdf->SetTextColor(200, 180, 150);
$pdf->SetXY(14, 35);
$pdf->Cell(0, 6, 'x', 0, 1, 'L');

$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetXY(14, 43);
$pdf->Cell(0, 7, CLIENT_NAME, 0, 1, 'L');

$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(160, 140, 120);
$pdf->SetXY(14, 61);
$pdf->Cell(0, 5, 'Reference: ' . $contractRef . '   |   Date: ' . $contractDate, 0, 1, 'L');

// Cream lower section
$pdf->SetFillColor(...C_CREAM);
$pdf->Rect(0, 75, 210, 222, 'F');

// Tagline
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(...C_INK);
$pdf->SetXY(20, 82);
$pdf->Cell(0, 6, 'YOUR HOTEL. YOUR DATA. YOUR SYSTEM.', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9.5);
$pdf->SetTextColor(...C_INK);
$pdf->SetXY(20, 90);
$pdf->MultiCell(170, 5.5,
    'This Agreement governs the supply, licensing, and ongoing support of a bespoke Hotel Property Management System ' .
    'built and operated by ' . PROVIDER_NAME . ' exclusively for ' . CLIENT_NAME . '. ' .
    'The PMS is an enterprise-grade hospitality platform covering every operational touchpoint — from online bookings ' .
    'and the restaurant POS to housekeeping, stock management, and real-time analytics.',
    0, 'J');

// Three cards
$cards = [
    ['NO UPFRONT RISK',    'Demo first. Pay only\nonce you are satisfied.'],
    ['SETUP FEE',          SETUP_FEE . "\nOne-time. Everything included."],
    ['MONTHLY RETAINER',   MONTHLY_FEE . "/month\nFull support & hosting."],
];
$cy = $pdf->GetY() + 5;
$cx = 20;
foreach ($cards as $i => $card) {
    $pdf->SetFillColor(...C_WHITE);
    $pdf->SetDrawColor(...C_GOLD);
    $pdf->SetLineWidth(0.35);
    $pdf->RoundedRect($cx, $cy, 55, 36, 1.5, '1111', 'DF');
    $pdf->SetFillColor(...C_GOLD);
    $pdf->Rect($cx, $cy, 55, 3.5, 'F');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetXY($cx + 2, $cy + 5);
    $pdf->Cell(51, 4, $card[0], 0, 1, 'L');
    $pdf->SetFont('helvetica', $i === 0 ? 'I' : 'B', $i === 0 ? 9 : 11);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetXY($cx + 2, $cy + 11);
    $pdf->MultiCell(51, 5, $card[1], 0, 'L');
    $cx += 58;
}
$pdf->SetY($cy + 44);

$pdf->SetDrawColor(...C_BROWN);
$pdf->SetLineWidth(0.35);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
sp($pdf, 3);

$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(...C_MUTED);
$pdf->SetX(20);
$pdf->MultiCell(170, 4.5, 'CONFIDENTIAL — This document is intended solely for the named parties. Unauthorised disclosure is prohibited.', 0, 'C');
sp($pdf, 2);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetX(20);
$pdf->Cell(0, 4.5, PROVIDER_EMAIL . '   |   ' . PROVIDER_PHONE, 0, 1, 'C');

// ══════════════════════════════════════════════════════════════════════════════
// BODY PAGES
// ══════════════════════════════════════════════════════════════════════════════
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->AddPage();

// ─── §1 PARTIES ───────────────────────────────────────────────────────────────
sectionHeading($pdf, '1', 'Parties to This Agreement');

subHead($pdf, '1.1  Service Provider');
bullet($pdf, PROVIDER_NAME,    'Company:');
bullet($pdf, PROVIDER_CONTACT, 'Principal:');
bullet($pdf, PROVIDER_EMAIL,   'Email:');
bullet($pdf, PROVIDER_PHONE,   'Phone:');
bullet($pdf, PROVIDER_ADDRESS, 'Address:');

subHead($pdf, '1.2  Client');
bullet($pdf, CLIENT_NAME,         'Hotel:');
bullet($pdf, CLIENT_ADDRESS,      'Address:');
bullet($pdf, CLIENT_PHONE,        'Phone:');
bullet($pdf, '[To be completed]',  'Email:');
bullet($pdf, CLIENT_CONTACT,      'Signatory:');
bullet($pdf, CLIENT_TITLE,        'Title:');

body($pdf, 'Each party confirms that the individual signing this Agreement has full authority to bind their respective organisation to the terms set out herein. This Agreement is entered into on ' . $contractDate . '.');

// ─── §2 DEFINITIONS ───────────────────────────────────────────────────────────
sectionHeading($pdf, '2', 'Definitions');

$defs = [
    '"PMS"'             => 'The Hotel Property Management System built and hosted by the Provider for the Client, including all modules listed in Section 3.',
    '"Setup Fee"'       => 'One-time payment of ' . SETUP_FEE . ' covering design, development, deployment, configuration, and initial staff training.',
    '"Monthly Retainer"'=> 'Recurring fee of ' . MONTHLY_FEE . '/month covering the full ongoing operation of the PMS.',
    '"Module"'          => 'A self-contained functional area of the PMS (e.g. Booking Engine, POS Till, Kitchen Display).',
    '"Business Day"'    => 'A day on which the Provider is ordinarily available for support, as communicated to the Client.',
    '"Go-Live Date"'    => 'The date the PMS is deployed to production and staff training is completed, agreed in writing.',
    '"Uptime"'          => 'Percentage of time the PMS is accessible, excluding pre-agreed maintenance windows.',
];
foreach ($defs as $term => $defn) {
    bullet($pdf, $defn, $term);
}

// ─── §3 SCOPE ─────────────────────────────────────────────────────────────────
sectionHeading($pdf, '3', 'Scope of the Property Management System');

body($pdf,
    'The PMS is a comprehensive, enterprise-grade hospitality platform engineered specifically for ' . CLIENT_NAME . '. ' .
    'It is not a website plugin or an off-the-shelf product. Its scope and capability is equivalent to commercial PMS ' .
    'solutions such as Opera PMS, Protel, or Semper — which carry licence and hosting costs of USD 200–800/month. ' .
    'The Client receives the same power, purpose-built for their property, at a fraction of the global market rate.');

subHead($pdf, '3.1  Modules Included');
$modules = [
    ['Online Booking Engine',    'Guest-facing room booking with rate management, occupancy tiers, availability calendar, and duplicate-prevention logic.'],
    ['Reservations & Folios',    'Full booking lifecycle: creation, modification, check-in, check-out, folio charges, group bookings, and cancellations.'],
    ['POS Till (Touchscreen)',   'Tablet-first point-of-sale for walk-in, dine-in, takeaway, and room service with live order management.'],
    ['Kitchen Display (KDS)',    'Full-screen real-time kitchen order display with timer colour-coding, bump tracking, and prep-time analytics.'],
    ['Bar Display (BDS)',        'Real-time bar order management — station-separated, identical architecture to KDS.'],
    ['Coffee Bar (CDS)',         'Dedicated coffee station order flow and display.'],
    ['Room Service Dashboard',   'Room-service order routing, status tracking, and guest folio integration.'],
    ['Housekeeping Management',  'Room status board, task assignment, inspection workflow, and front-desk status sync.'],
    ['Stock Management',         'Ingredients, recipes, batch tracking, wastage logging, purchase orders, stock counts, and per-dish costing.'],
    ['Payments & Invoicing',     'Cash, card, and mobile money (Airtel/TNM) processing, VAT calculation, PDF invoicing, and refunds.'],
    ['Conference & Events',      'Enquiry management, room allocation, event packages, and conference invoicing.'],
    ['Visitor & Sales Analytics','Website visitor tracking, conversion funnel, traffic sources, bounce rate, and marketing insights.'],
    ['F&B Analytics',            'Revenue by category, top sellers, station performance, void analysis, and recommendations.'],
    ['Admin Panel',              'Role-based access, user management, audit logs, cache management, and CMS for site content.'],
    ['WhatsApp Notifications',   'Automated guest messaging for booking confirmation, reminders, and check-out summaries.'],
    ['Offline Capability',       'POS and KDS continue operating during internet outages; orders sync automatically on reconnection.'],
    ['API Layer',                'Authenticated API with rate limiting and structured JSON responses for all major operations.'],
    ['Security Layer',           'CSRF protection, prepared statements (SQL injection prevention), CSP headers, and OWASP Top 10 compliance.'],
];
foreach ($modules as [$name, $desc]) {
    bullet($pdf, $desc, $name . ':');
}

// ─── §4 FEES ──────────────────────────────────────────────────────────────────
sectionHeading($pdf, '4', 'Fees & Payment Terms');

subHead($pdf, '4.1  Fee Schedule');
priceRow($pdf, 'Setup Fee (one-time)', SETUP_FEE,   'Paid in three instalments — see 4.2');
priceRow($pdf, 'Monthly Retainer',     MONTHLY_FEE, 'Per calendar month from Go-Live Date');

sp($pdf, 2);
subHead($pdf, '4.2  Setup Fee Payment Schedule — Demo First, Pay When Happy');
body($pdf,
    'The Provider takes the risk. The Client will see a working, fully configured system before committing the majority ' .
    'of the setup fee. The payment schedule is structured as follows:');

bullet($pdf, '25% — MWK 1,000,000 — due upon signing this Agreement. This covers initial configuration and development work.');
bullet($pdf, '50% — MWK 2,000,000 — due only after the Client has reviewed the completed system on a staging environment and confirmed satisfaction in writing. No payment is required if the Client is not satisfied at this stage — the Agreement may be reviewed or concluded.');
bullet($pdf, '25% — MWK 1,000,000 — due on the Go-Live Date, when the system is live and staff training is complete.');

alertBox($pdf,
    'The 50% milestone payment is triggered by the Client\'s written approval of the staged system. The Provider will not invoice for this amount until the Client confirms the system meets their requirements.',
    C_GOLD);

subHead($pdf, '4.3  Monthly Retainer');
bullet($pdf, 'Commences on the Go-Live Date and is billed on the 1st of each calendar month.');
bullet($pdf, 'A pro-rated amount applies for any partial month at go-live.');
bullet($pdf, 'Fee reviews may occur annually with 60 days written notice before any increase takes effect.');
bullet($pdf, 'Non-payment beyond 30 days of invoice date entitles the Provider to suspend system access until payment is received.');

subHead($pdf, '4.4  Late Payment');
body($pdf, 'Invoices unpaid after 30 days accrue interest at 2% per month on the outstanding balance. The Provider may suspend service after 45 days of non-payment without further liability.');

alertBox($pdf, 'All fees are in Malawian Kwacha (MWK) and exclusive of applicable taxes. Payment is due within 7 calendar days of invoice.', C_BROWN);

// ─── §5 WHAT THE RETAINER COVERS ──────────────────────────────────────────────
sectionHeading($pdf, '5', 'Monthly Retainer — What Is Covered');

body($pdf, 'The Monthly Retainer of ' . MONTHLY_FEE . ' covers the complete ongoing operation of the PMS with no hidden charges.');

bullet($pdf, 'Web hosting for the PMS and public-facing hotel website.');
bullet($pdf, 'SSL certificate management and renewal.');
bullet($pdf, 'Bug fixes — defects in existing functionality resolved at no additional charge.');
bullet($pdf, 'Security patches and dependency updates.');
bullet($pdf, 'Minor improvements and refinements to existing features.');
bullet($pdf, 'Daily automated database backups.');
bullet($pdf, 'Email and WhatsApp support from the Provider.');
bullet($pdf, 'Staff training on existing system features.');
bullet($pdf, 'Monthly system health check.');

// ─── §6 SERVICE LEVELS ────────────────────────────────────────────────────────
sectionHeading($pdf, '6', 'Service Levels');

$slas = [
    ['Critical — System Down',       '4 hours',       'Core system inaccessible, affecting live operations'],
    ['High — Major Feature Broken',  '8 hours',       'POS, bookings, or payments non-functional'],
    ['Medium — Partial Degradation', '2 Business Days','Non-critical feature unavailable'],
    ['Low — Cosmetic / Minor',       '5 Business Days','Visual issue or minor discrepancy'],
];

$pdf->SetFillColor(...C_BROWN);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetX(20);
$pdf->Cell(64, 6.5, '  Severity', 1, 0, 'L', true);
$pdf->Cell(36, 6.5, 'Response Target', 1, 0, 'C', true);
$pdf->Cell(70, 6.5, 'Definition', 1, 1, 'L', true);

$alt = false;
foreach ($slas as $row) {
    $pdf->SetFillColor($alt ? 247 : 255, $alt ? 243 : 255, $alt ? 238 : 255);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetX(20);
    $pdf->Cell(64, 6, '  ' . $row[0], 1, 0, 'L', true);
    $pdf->Cell(36, 6, $row[1], 1, 0, 'C', true);
    $pdf->Cell(70, 6, $row[2], 1, 1, 'L', true);
    $alt = !$alt;
}
sp($pdf, 2);
body($pdf, 'Response targets are measured from the time a support request is received. The Provider targets 99.5% monthly uptime, excluding scheduled maintenance windows and events beyond the Provider\'s reasonable control.');

// ─── §7 DATA OWNERSHIP ────────────────────────────────────────────────────────
sectionHeading($pdf, '7', 'Data Ownership & Privacy');

bullet($pdf, 'All data entered into the PMS — guest records, booking data, payment records, operational data — is and remains the sole property of the Client.');
bullet($pdf, 'The Provider acts as a data processor only. The Client is the data controller under applicable law.');
bullet($pdf, 'Client data will not be sold, shared, or disclosed to any third party without written consent, except as required by law.');
bullet($pdf, 'On termination, the Provider supplies a full database export in MySQL/CSV format within 14 days at no charge.');
bullet($pdf, 'The Provider maintains encrypted backups and implements industry-standard access controls.');
bullet($pdf, 'The Client is responsible for compliance with applicable Malawian data protection regulations.');

// ─── §8 INTELLECTUAL PROPERTY ─────────────────────────────────────────────────
sectionHeading($pdf, '8', 'Intellectual Property');

bullet($pdf, 'The PMS software — source code, design assets, database schemas, and documentation — is the intellectual property of the Provider unless otherwise agreed in writing.');
bullet($pdf, 'The Client receives a non-exclusive, non-transferable licence to use the PMS for the duration of this Agreement.');
bullet($pdf, 'The Client may not copy, resell, sublicence, or distribute the PMS or any component without prior written consent from the Provider.');

alertBox($pdf, 'The PMS platform may be adapted for other hotel clients. The Client does not acquire exclusivity over the platform — only over their own data and bespoke customisations unique to their property.', C_STEEL);

// ─── §9 CONFIDENTIALITY ───────────────────────────────────────────────────────
sectionHeading($pdf, '9', 'Confidentiality');

bullet($pdf, 'Both parties agree to keep the terms of this Agreement, and all technical and business information exchanged, strictly confidential.');
bullet($pdf, 'Confidential information may not be disclosed without written consent, except as required by law or to professional advisors under equivalent obligations.');
bullet($pdf, 'This obligation survives termination for five (5) years.');

// ─── §10 TERM & TERMINATION ───────────────────────────────────────────────────
sectionHeading($pdf, '10', 'Term & Termination');

subHead($pdf, '10.1  Initial Term');
body($pdf, 'This Agreement commences on the date of signing and continues for an initial term of twelve (12) months from the Go-Live Date. During the Initial Term, neither party may terminate except as provided in Section 10.3.');

subHead($pdf, '10.2  Renewal');
body($pdf, 'After the Initial Term, the Agreement renews month-to-month unless either party gives 30 days written notice of termination.');

subHead($pdf, '10.3  Termination for Cause');
bullet($pdf, 'Either party may terminate immediately if the other commits a material breach not remedied within 21 days of written notice.');
bullet($pdf, 'The Provider may terminate immediately if the Client fails to pay within 45 days of invoice date.');
bullet($pdf, 'The Client may terminate immediately if the Provider cannot restore the system after a critical outage lasting more than 72 consecutive hours.');

subHead($pdf, '10.4  Effect of Termination');
bullet($pdf, 'Client access ceases on the last day of the paid period.');
bullet($pdf, 'The Provider supplies a full data export within 14 days per Section 7.');
bullet($pdf, 'All outstanding invoices become immediately due and payable.');
bullet($pdf, 'The setup fee is non-refundable once the staged system has been approved by the Client.');

// ─── §11 LIMITATION OF LIABILITY ──────────────────────────────────────────────
sectionHeading($pdf, '11', 'Limitation of Liability');

body($pdf, 'To the maximum extent permitted by applicable law:');
bullet($pdf, 'The Provider\'s total aggregate liability shall not exceed the total Monthly Retainer fees paid in the 12 months preceding the event.');
bullet($pdf, 'The Provider is not liable for indirect, consequential, special, or punitive loss including loss of revenue, profit, data, or goodwill.');
bullet($pdf, 'The Provider is not liable for failures caused by: (a) Client misuse; (b) third-party services outside the Provider\'s control; (c) force majeure events including load-shedding.');

alertBox($pdf, 'Nothing in this Agreement limits liability for death, personal injury, or fraud caused by the Provider\'s gross negligence or wilful misconduct.', C_RED);

// ─── §12 CLIENT RESPONSIBILITIES ──────────────────────────────────────────────
sectionHeading($pdf, '12', 'Client Responsibilities');

bullet($pdf, 'Designate a named System Administrator responsible for staff access management and first-line support.');
bullet($pdf, 'Ensure staff complete go-live training before using the system operationally.');
bullet($pdf, 'Not share admin credentials; notify the Provider promptly of any suspected security incident.');
bullet($pdf, 'Not attempt to access, modify, or copy the system source code, database, or server configuration.');
bullet($pdf, 'Provide accurate business information required for system configuration (room names, prices, menu items, VAT number, etc.).');
bullet($pdf, 'Maintain a stable internet connection; the PMS requires internet access for full functionality.');

// ─── §13 GOVERNING LAW ────────────────────────────────────────────────────────
sectionHeading($pdf, '13', 'Governing Law & Dispute Resolution');

body($pdf, 'This Agreement is governed by the laws of the Republic of Malawi. Disputes shall first be referred to good-faith negotiation. If unresolved within 30 days, disputes shall be referred to mediation by an agreed neutral mediator in Malawi before any court proceedings commence.');

// ─── §14 GENERAL ──────────────────────────────────────────────────────────────
sectionHeading($pdf, '14', 'General Provisions');

bullet($pdf, 'Entire Agreement: This document constitutes the entire agreement and supersedes all prior discussions and representations.');
bullet($pdf, 'Amendments: No amendment is valid unless made in writing and signed by both parties.');
bullet($pdf, 'Waiver: Failure to enforce any provision does not constitute a waiver of that provision.');
bullet($pdf, 'Severability: If any provision is found unenforceable, the remainder continues in full force.');
bullet($pdf, 'Notices: All formal notices must be in writing, delivered by email with read receipt or registered post.');
bullet($pdf, 'Force Majeure: Neither party is liable for failures caused by events beyond reasonable control.');

// ─── §15 SIGNATURES ───────────────────────────────────────────────────────────
sectionHeading($pdf, '15', 'Execution — Signatures');

body($pdf, 'By signing below, each party confirms they have read, understood, and agree to be bound by all terms of this Agreement.');

sp($pdf, 4);
$pdf->SetDrawColor(...C_CREAM);
$pdf->SetLineWidth(0.25);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
sp($pdf, 5);

$sigY = $pdf->GetY();
sigBlock($pdf, 20,  $sigY, 'Service Provider', PROVIDER_CONTACT, 'Director — ' . PROVIDER_NAME);
sigBlock($pdf, 110, $sigY, 'Client',            CLIENT_CONTACT,   CLIENT_TITLE . ', ' . CLIENT_NAME);
$pdf->SetY($sigY + 44);
sp($pdf, 3);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MUTED);
$pdf->SetX(20);
$pdf->Cell(85, 5.5, 'Date: ___________________________', 0, 0, 'L');
$pdf->SetX(110);
$pdf->Cell(80, 5.5, 'Date: ___________________________', 0, 1, 'L');

sp($pdf, 6);
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.4);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
sp($pdf, 4);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(...C_BROWN);
$pdf->SetX(20);
$pdf->Cell(0, 5, 'Witness (optional but recommended)', 0, 1, 'L');
sp($pdf, 2);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->SetTextColor(...C_MUTED);
$pdf->SetX(20);
$pdf->Cell(0, 5.5, 'Witness Name: _______________________________     Signature: _______________________________     Date: _______________', 0, 1, 'L');
sp($pdf, 2);
$pdf->SetX(20);
$pdf->Cell(0, 5.5, 'Occupation: _________________________________     National ID / Passport No: ___________________________', 0, 1, 'L');

sp($pdf, 6);
$pdf->SetDrawColor(...C_BROWN);
$pdf->SetLineWidth(0.3);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
sp($pdf, 3);
$pdf->SetFont('helvetica', 'I', 7.5);
$pdf->SetTextColor(...C_MUTED);
$pdf->SetX(20);
$pdf->MultiCell(170, 4.5, 'Contract Reference: ' . $contractRef . '  |  ' . $contractDate . '  |  ' . PROVIDER_NAME . '  |  ' . PROVIDER_EMAIL, 0, 'C');

// ─── SCHEDULE A ───────────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(...C_INK);
$pdf->SetX(20);
$pdf->Cell(0, 8, 'SCHEDULE A — SYSTEM MODULES & DELIVERY', 0, 1, 'L');
$pdf->SetDrawColor(...C_GOLD);
$pdf->SetLineWidth(0.5);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
sp($pdf, 3);

body($pdf, 'All modules are included in the Setup Fee and covered by the Monthly Retainer at no additional cost. Items marked (*) require third-party API credentials to be supplied by the Client.');

$schedule = [
    ['Online Booking Engine',           'Yes',     'Guest-facing, fully responsive'],
    ['Room Management & Availability',  'Yes',     'Real-time calendar'],
    ['Rate Plans & Pricing Rules',      'Yes',     'Multi-tier, seasonal rates'],
    ['Check-in / Check-out Processing', 'Yes',     ''],
    ['Folio Management & Charges',      'Yes',     'Room, F&B, conference'],
    ['POS Till (Touchscreen)',          'Yes',     'Tablet-optimised'],
    ['Kitchen Display (KDS)',           'Yes',     'Real-time, offline-capable'],
    ['Bar Display (BDS)',               'Yes',     ''],
    ['Coffee Bar Display (CDS)',        'Yes',     ''],
    ['Room Service Dashboard',          'Yes',     'Folio-integrated'],
    ['Housekeeping Board',              'Yes',     ''],
    ['Stock & Recipe Management',       'Yes',     'Ingredients, batches, costing'],
    ['Conference & Events',             'Yes',     'Enquiry to invoice workflow'],
    ['Payments (Cash / Card / MoMo)',   'Yes',     ''],
    ['Invoicing & Credit Notes (PDF)',  'Yes',     'VAT-compliant'],
    ['WhatsApp Notifications',          'Yes (*)', 'Client supplies WhatsApp API key'],
    ['Email Notifications',             'Yes (*)', 'Client supplies SMTP credentials'],
    ['Visitor & Sales Analytics',       'Yes',     ''],
    ['F&B Analytics',                   'Yes',     ''],
    ['Admin Panel & Role Management',   'Yes',     ''],
    ['Audit Logs',                      'Yes',     ''],
    ['Offline Capability (POS/KDS)',    'Yes',     'Service Worker + auto-sync'],
    ['API Layer',                       'Yes',     'API key authenticated'],
    ['Security (CSRF, CSP, PDO)',       'Yes',     'OWASP Top 10 compliant'],
];

$pdf->SetFillColor(...C_BROWN);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetX(20);
$pdf->Cell(104, 6.5, '  Module', 1, 0, 'L', true);
$pdf->Cell(22, 6.5, 'Included', 1, 0, 'C', true);
$pdf->Cell(44, 6.5, 'Notes', 1, 1, 'L', true);

$alt = false;
foreach ($schedule as $row) {
    $pdf->SetFillColor($alt ? 247 : 255, $alt ? 243 : 255, $alt ? 238 : 255);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_INK);
    $pdf->SetX(20);
    $pdf->Cell(104, 5.5, '  ' . $row[0], 1, 0, 'L', true);
    if (str_starts_with($row[1], 'Yes')) {
        $pdf->SetTextColor(...C_GREEN);
    }
    $pdf->Cell(22, 5.5, $row[1], 1, 0, 'C', true);
    $pdf->SetTextColor(...C_MUTED);
    $pdf->SetFont('helvetica', 'I', 7.5);
    $pdf->Cell(44, 5.5, $row[2], 1, 1, 'L', true);
    $alt = !$alt;
}

sp($pdf, 3);
body($pdf, '(*) WhatsApp and email notification services require separate third-party accounts. Any costs charged by those providers are outside this Agreement and are the Client\'s sole responsibility.');

// ─── Output ───────────────────────────────────────────────────────────────────
if (!is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0755, true);
}
$pdf->Output($outputPath, 'F');
echo 'Contract PDF generated: ' . $outputPath . PHP_EOL;
echo 'Reference: ' . $contractRef . PHP_EOL;
