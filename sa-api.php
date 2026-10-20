<?php
// =====================================================================
//  Digitale Selbstauskunft – Schnittstelle fuer selbstauskunft.html
//  GET  ?t=TOKEN                  -> Status, Vorbelegung aus dem Lead, Entwurf
//  POST action=save   t, draft    -> Entwurf zwischenspeichern
//  POST action=submit t, pdf(Datei), pdf2(Ergaenzungsblatt, optional), zusammenfassung, name
//                                 -> PDF per Mail an den Berater, HubSpot-Phase
//                                    anheben, Entwurf loeschen
//  Zugriff nur ueber den persoenlichen 32-stelligen Token.
// =====================================================================
require __DIR__ . "/_lead-lib.php";
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Robots-Tag: noindex");

function sa_out($arr, $code = 200) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// Aufraeumen: Entwuerfe, die ein halbes Jahr nicht angefasst wurden, verschwinden.
if (is_dir(SA_DIR) && mt_rand(1, 20) === 1) {
    foreach ((array)glob(SA_DIR . "/*.json") as $f) {
        if (@filemtime($f) < time() - 183 * 86400) @unlink($f);
    }
}

$t = strtolower(trim((string)($_GET["t"] ?? $_POST["t"] ?? "")));
if (!sa_valid_token($t)) sa_out(["ok" => false, "error" => "token"], 404);
$rec = sa_load($t);
if (!$rec) sa_out(["ok" => false, "error" => "token"], 404);

// ---------- Laden ----------
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    sa_out([
        "ok"        => true,
        "status"    => $rec["status"] ?? "offen",
        "submitted" => isset($rec["submitted_at"]) ? date("d.m.Y", $rec["submitted_at"]) : "",
        "termin"    => !empty($rec["termin_at"]),
        "prefill"   => $rec["lead"] ?? new stdClass(),
        "draft"     => $rec["draft"] ?? new stdClass(),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") sa_out(["ok" => false, "error" => "method"], 405);
$action = (string)($_POST["action"] ?? "");

// ---------- Zwischenspeichern ----------
if ($action === "save") {
    $raw = (string)($_POST["draft"] ?? "");
    if (strlen($raw) > 200000) sa_out(["ok" => false, "error" => "size"], 413);
    $draft = json_decode($raw, true);
    if (!is_array($draft)) sa_out(["ok" => false, "error" => "draft"], 400);
    // Ein verspaeteter Autosave direkt nach dem Absenden darf den Entwurf nicht wiederherstellen
    if (($rec["status"] ?? "") === "uebermittelt" && time() - (int)($rec["submitted_at"] ?? 0) < 60) sa_out(["ok" => true]);
    $rec["draft"] = $draft;
    if (($rec["status"] ?? "") === "uebermittelt") $rec["status"] = "offen"; // neue Fassung wird bearbeitet
    sa_out(["ok" => sa_save($t, $rec)]);
}

// ---------- Absenden ----------
if ($action === "submit") {
    if (empty($_FILES["pdf"]) || ($_FILES["pdf"]["error"] ?? 1) !== UPLOAD_ERR_OK) sa_out(["ok" => false, "error" => "pdf"], 400);
    if ($_FILES["pdf"]["size"] > 8 * 1024 * 1024) sa_out(["ok" => false, "error" => "size"], 413);
    $bytes = (string)file_get_contents($_FILES["pdf"]["tmp_name"]);
    if (substr($bytes, 0, 5) !== "%PDF-") sa_out(["ok" => false, "error" => "pdf"], 400);

    $lead  = $rec["lead"] ?? [];
    $name  = trim(strip_tags((string)($_POST["name"] ?? ""))) ?: (string)($lead["name"] ?? "");
    $email = (string)($lead["email"] ?? "");
    $zus   = trim(strip_tags((string)($_POST["zusammenfassung"] ?? "")));
    $zus   = mb_substr($zus, 0, 20000);
    $basis = preg_replace('/[^A-Za-z0-9]+/', '_', $name ?: "Kunde") . "_" . date("Y-m-d");
    $anhaenge = [["Selbstauskunft_" . $basis . ".pdf", $bytes]];
    // Optional: Ergaenzungsblatt mit allem, was die Bonnfinanz-Vorlage nicht abbildet
    if (!empty($_FILES["pdf2"]) && ($_FILES["pdf2"]["error"] ?? 1) === UPLOAD_ERR_OK && $_FILES["pdf2"]["size"] <= 4 * 1024 * 1024) {
        $b2 = (string)file_get_contents($_FILES["pdf2"]["tmp_name"]);
        if (substr($b2, 0, 5) === "%PDF-") $anhaenge[] = ["Selbstauskunft_Ergaenzung_" . $basis . ".pdf", $b2];
    }
    $erneut = !empty($rec["submitted_at"]);

    $text = "Neue digitale Selbstauskunft" . ($erneut ? " (AKTUALISIERTE FASSUNG)" : "") . "\n"
          . "==================================================\n"
          . "Name:    " . $name . "\n"
          . "E-Mail:  " . $email . "\n"
          . "Telefon: " . ($lead["phone"] ?? "") . "\n"
          . "Eingang: " . date("d.m.Y H:i") . " Uhr\n"
          . "==================================================\n\n"
          . "Anhang 1: Selbstauskunft (Bonnfinanz-Vorlage, Felder weiterhin bearbeitbar) - fuers CRM.\n"
          . (count($anhaenge) > 1 ? "Anhang 2: Ergaenzungsblatt - Vorhaben, Finanzierungswuensche und Kurzauswertung, nur was nicht in der Vorlage steht.\n" : "")
          . "\n"
          . ($zus !== "" ? "KURZUEBERSICHT\n" . $zus . "\n" : "");
    $betreff = "Selbstauskunft" . ($erneut ? " (aktualisiert)" : "") . " - " . ($name ?: $email);
    $ok = lead_mail_attach(LEAD_EMPFAENGER, lead_subject_encode($betreff), $text, $email, $anhaenge);
    if (!$ok) sa_out(["ok" => false, "error" => "mail"], 500);

    // Sensible Angaben nicht laenger als noetig auf dem Server halten
    $rec["status"]       = "uebermittelt";
    $rec["submitted_at"] = time();
    $rec["submissions"]  = (int)($rec["submissions"] ?? 0) + 1;
    $rec["draft"]        = new stdClass();
    sa_save($t, $rec);

    // HubSpot: "... inkl. Selbstauskunft"
    hs_deal_advance($email, (string)($lead["name"] ?? $name), false, true);

    sa_out(["ok" => true]);
}

// ---------- Termin ueber die Selbstauskunft-Seite gebucht ----------
if ($action === "termin") {
    if (empty($rec["termin_at"])) {
        $rec["termin_at"] = time();
        sa_save($t, $rec);
        $lead = $rec["lead"] ?? [];
        $email = (string)($lead["email"] ?? "");
        $text = "Termin gebucht (ueber die Selbstauskunft-Seite)\n\n"
              . "Name:    " . ($lead["name"] ?? "") . "\n"
              . "E-Mail:  " . $email . "\n"
              . "Telefon: " . ($lead["phone"] ?? "") . "\n\n"
              . "Die Termindetails stehen in der Calendly-Benachrichtigung.";
        lead_mail(LEAD_EMPFAENGER, lead_subject_encode("Termin gebucht - " . ($lead["name"] ?? $email)), $text, lead_headers($email));
        hs_deal_advance($email, (string)($lead["name"] ?? ""), true, ($rec["status"] ?? "") === "uebermittelt");
    }
    sa_out(["ok" => true]);
}

sa_out(["ok" => false, "error" => "action"], 400);
