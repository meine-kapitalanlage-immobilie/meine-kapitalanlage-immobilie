<?php
// =====================================================================
//  Lead-Intake mit Double-Opt-In fuer meine-kapitalanlage-immobilie.de
//  - Terminwunsch (Buchung) -> gilt als qualifiziert, Robin bekommt Lead sofort
//  - sonst (Rueckruf/Quiz)   -> Bestaetigungsmail; erst nach Klick geht Lead an Robin + HubSpot
// =====================================================================
require __DIR__ . "/_lead-lib.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405); echo json_encode(["ok" => false, "error" => "method"]); exit;
}
// Honeypot
if (!empty($_POST["bot-field"])) { echo json_encode(["ok" => true, "confirm" => false]); exit; }

// Felder einsammeln
$data = [];
foreach ($_POST as $k => $v) {
    if ($k === "bot-field") continue;
    if (is_array($v)) $v = implode(", ", $v);
    $data[$k] = trim(strip_tags((string)$v));
}

$name  = $data["name"]  ?? "";
$email = $data["email"] ?? "";
$phone = $data["phone"] ?? "";
$terminwunsch = strtolower($data["terminwunsch"] ?? "nein");

// Terminstatus lesbar machen. Faellt automatisch in die Robin-Mail und in die
// HubSpot-Notiz, weil unbekannte Felder dort ueber lead_labels() mitlaufen.
$tstat = strtolower(trim($data["terminstatus"] ?? ""));
$statusText = [
    "termin_gebucht" => "Termin gebucht",
    "termin_offen"   => "Termin offen - Kalender geoeffnet, aber (noch) nicht gebucht",
    "nur_rueckruf"   => "Nur Rueckruf gewuenscht",
    "leitfaden"      => "Leitfaden heruntergeladen - noch kein Rueckruf angefordert",
];
$data["terminstatus"] = $statusText[$tstat] ?? "Nur Rueckruf gewuenscht";
$leadmagnet   = strtolower($data["leadmagnet"] ?? "");

// Persoenlicher Selbstauskunft-Link: Der Browser erzeugt beim Laden der Seite
// einen Zufalls-Token (32 Hex-Zeichen) und schickt ihn mit. So kennt die
// Erfolgsseite den Link sofort, auch wenn der Lead erst nach der Buchung kommt.
$saToken = strtolower(trim((string)($data["sa_token"] ?? "")));
unset($data["sa_token"]);
$saNeu = false; $saLink = "";
if ($leadmagnet === "" && sa_valid_token($saToken)) {
    $saNeu = sa_create($saToken, $data);
    if ($rec = sa_load($saToken)) {
        if ($tstat === "termin_gebucht" && empty($rec["termin_at"])) { $rec["termin_at"] = time(); sa_save($saToken, $rec); }
        $saLink = sa_link($saToken);
        $data["sa_link"]  = $saLink;
        $data["_sa_done"] = sa_is_done($saToken) ? "1" : "";
    }
}

// Basis-Validierung (Leitfaden-Download: nur E-Mail noetig)
if ($leadmagnet === "" && ($name === "" || $phone === "")) {
    http_response_code(400); echo json_encode(["ok" => false, "error" => "input"]); exit;
}

// --- Fall 1: Terminbuchung => bereits qualifiziert, sofort an Robin (kein Double-Opt-In) ---
if ($terminwunsch === "ja") {
    $zusatz = ($tstat === "termin_gebucht") ? " (TERMIN GEBUCHT)" : " (Termin offen)";
    lead_notify_robin($data, $zusatz);
    lead_to_hubspot($data, "entfaellt - Terminwunsch ueber das Formular");
    // Hier gibt es kein Double-Opt-In, also direkt die Bestaetigung an den Lead.
    // Nur beim ersten Eingang - die spaetere Meldung "Termin gebucht" ist ein
    // Statusupdate und soll keine zweite Mail ausloesen.
    if ($saLink !== "") {
        // Bestaetigung + Selbstauskunft-Link genau einmal pro Lead, auch wenn direkt gebucht wurde
        if ($saNeu) lead_send_danke($email, $name, $saLink, $tstat === "termin_gebucht");
    } elseif ($tstat !== "termin_gebucht") {
        lead_send_danke($email, $name);
    }
    echo json_encode(["ok" => true, "confirm" => false]); exit;
}

// --- Fall 2: E-Mail ist ab hier Pflicht ---
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(["ok" => false, "error" => "email"]); exit;
}

// --- Fall 2a: Leitfaden mit Sofort-Download => kein Double-Opt-In ---
// Der Besucher hat die Datei bereits im Browser bekommen; ein Bestaetigungslink
// wuerde ins Leere laufen. Der Lead wird deshalb sofort uebergeben und klar als
// unbestaetigt gekennzeichnet.
if ($leadmagnet !== "" && strtolower($data["direkt"] ?? "") === "ja") {
    lead_send_guide_direct($email, $name);
    lead_notify_robin($data, " (Leitfaden-Download, E-Mail unbestaetigt)");
    lead_to_hubspot($data, "nicht bestaetigt - Sofort-Download Leitfaden");
    echo json_encode(["ok" => true, "confirm" => false]); exit;
}

// --- Fall 2b: Rueckrufbitte => direkt an Robin, KEIN Double-Opt-In ---
// Wer aktiv um einen Rueckruf bittet, hat den Kontakt selbst angefordert.
// Ein Bestaetigungsklick davorzuschalten hiesse: Kommt die Mail nicht an
// oder landet sie im Spam, erreicht der Lead Robin nie. Die Einwilligung
// fuer den Newsletter laeuft weiterhin getrennt ueber HubSpot.
if ($leadmagnet === "") {
    lead_notify_robin($data, " (Rueckruf)");
    lead_to_hubspot($data, "entfaellt - Rueckrufbitte direkt ueber das Formular");
    if ($saLink === "" || $saNeu) lead_send_danke($email, $name, $saLink, false);
    echo json_encode(["ok" => true, "confirm" => false]); exit;
}

// --- Ab hier nur noch: Leitfaden ohne Sofort-Download => Double-Opt-In ---
$token = bin2hex(random_bytes(16));
if (!is_dir(LEAD_STORE)) @mkdir(LEAD_STORE, 0700, true);
$saved = @file_put_contents(
    LEAD_STORE . "/" . $token . ".json",
    json_encode(["data" => $data, "ts" => time()], JSON_UNESCAPED_UNICODE)
);

// Fallback: kann nicht gespeichert werden -> Lead nicht verlieren, direkt an Robin
if ($saved === false) {
    lead_notify_robin($data, " (unbestaetigt – Speicher-Fallback)");
    echo json_encode(["ok" => true, "confirm" => false]); exit;
}

$link = LEAD_BASISURL . "/bestaetigen.php?t=" . $token;
if ($leadmagnet !== "") { lead_send_confirm_guide($email, $name, $link); }
else                    { lead_send_confirm($email, $name, $link); }

echo json_encode(["ok" => true, "confirm" => true]);
