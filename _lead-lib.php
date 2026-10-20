<?php
// =====================================================================
//  Lead-Bibliothek (Double-Opt-In) fuer meine-kapitalanlage-immobilie.de
//  Wird von lead-intake.php und bestaetigen.php eingebunden.
// =====================================================================

// ------------------- EINSTELLUNGEN -------------------
const LEAD_EMPFAENGER = "info@meine-kapitalanlage-immobilie.de"; // Robin bekommt die Leads
const LEAD_ABSENDER   = "info@meine-kapitalanlage-immobilie.de"; // Postfach dieser Domain
const LEAD_BASISURL   = "https://meine-kapitalanlage-immobilie.de";
const LEAD_STORE      = __DIR__ . "/_leads";

// HubSpot (EU-Portal). Formular-GUID nach dem Anlegen des HubSpot-Formulars eintragen:
const HUBSPOT_PORTAL_ID = "149537457"; // neues Konto "Immobilie als Kapitalanlage" (info@meine-kapitalanlage-immobilie.de), seit 13.10.2026
const HUBSPOT_FORM_GUID = "6192bfac-c361-4efb-af5e-16d19d3cf39b";
// Deal-Pipeline (Standard-Pipeline "Kapitalanlage")
const HUBSPOT_PIPELINE        = "default";
// Automatisch gesetzt werden nur die ersten vier Phasen; alles danach
// (stattgefunden, Folgetermin, eingestellt, Abschluss) pflegt der Berater von Hand.
const HUBSPOT_STAGE_RUECKRUF    = "6266551534"; // Rueckrufbitte
const HUBSPOT_STAGE_RUECKRUF_SA = "6266551535"; // Rueckrufbitte inkl. Selbstauskunft
const HUBSPOT_STAGE_TERMIN      = "6219734245"; // Termin vereinbart
const HUBSPOT_STAGE_TERMIN_SA   = "6266551536"; // Termin vereinbart inkl. Selbstauskunft
// Zugangsschluessel der HubSpot Private App liegt NUR auf dem Server in
// hubspot-config.php (per .gitignore vom Repo ausgeschlossen), Inhalt:
//   <?php const HUBSPOT_TOKEN = "pat-eu1-...";
// Fehler in dieser Datei duerfen die Lead-Strecke nie lahmlegen (ParseError wird abgefangen).
if (is_file(__DIR__ . "/hubspot-config.php")) {
    try { require_once __DIR__ . "/hubspot-config.php"; }
    catch (\Throwable $e) {
        @file_put_contents(__DIR__ . "/_leads/hubspot-fehler.log",
            date("c") . " hubspot-config.php fehlerhaft: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
} // HubSpot-Formular (EU-Portal). Felder: firstname, lastname, email, phone, message, bewertung_kapitalanlage, score_kapitalanlage, nettoeinkommen_mtl, zu_versteuerndes_einkommen_zve_pa, eigenkapital, alter, beschaftigungsart

// Lesbare Feld-Bezeichnungen fuer die Lead-Mail
function lead_labels() {
    return [
        "name" => "Name", "phone" => "Telefon", "email" => "E-Mail",
        "time" => "Beste Erreichbarkeit", "thema" => "Thema", "status" => "Aktuelle Situation",
        "einkommen" => "Nettoeinkommen mtl.", "eigenkapital" => "Verfuegbares Eigenkapital", "nachricht" => "Nachricht",
        "zve" => "Zu versteuerndes Einkommen (ZVE)", "familie" => "Familien-Unterstuetzung moeglich", "familie_ek" => "Voraussichtliche Familien-Unterstuetzung",
        "quelle" => "Quelle", "form-name" => "Formular", "terminwunsch" => "Terminwunsch",
        "terminstatus" => "TERMINSTATUS",
        "erfahrung" => "Erste Anlage oder Bestand",
        "eigenaufwand" => "Moeglicher mtl. Eigenaufwand",
        "direkt" => "Direkt-Download",
        "newsletter" => "Newsletter-Einwilligung",
        "rechner_zusammenfassung" => "Rechner-Eingaben des Kunden",
        "immobilientyp" => "Immobilientyp", "plz" => "PLZ", "ort" => "Ort",
        "nutzung" => "Nutzung", "kaltmiete" => "Aktuelle Kaltmiete (€/Monat)",
        "wunschpreis" => "Wunschpreis (€)", "kaufzeitpunkt" => "Gekauft",
        "verkaufszeitpunkt" => "Verkaufswunsch",
        "sa_link" => "Persoenlicher Link zur Selbstauskunft",
    ];
}

// Zahl aus formatiertem String ("3.000 EUR") -> reine Ziffern ("3000")
function lead_num($s) {
    $s = preg_replace('/[^\d]/', '', (string)$s);
    return $s === "" ? "" : $s;
}

// Lead-Qualitaet: Punktesystem -> Ampel (Gruen/Gelb/Rot)
function lead_score($data) {
    $netto  = (int) lead_num($data["einkommen"] ?? "0");
    $ek     = (int) lead_num($data["eigenkapital"] ?? "0");
    $zve    = (int) lead_num($data["zve"] ?? "0");
    $status = mb_strtolower(trim($data["status"] ?? ""));
    $thema  = mb_strtolower(trim($data["thema"] ?? ""));
    $fam    = strtolower(trim($data["familie"] ?? "")) === "ja";
    $s = 0;
    if ($netto > 5000) $s += 3; elseif ($netto >= 3500) $s += 2; elseif ($netto >= 2500) $s += 1;
    if ($ek > 60000) $s += 3; elseif ($ek >= 30000) $s += 2; elseif ($ek >= 10000) $s += 1;
    if ($zve > 60000) $s += 2; elseif ($zve >= 40000) $s += 1;
    if (strpos($status, "angestellt") !== false || strpos($status, "beamt") !== false) $s += 2;
    elseif (strpos($status, "selbst") !== false) $s += 1;
    if (strpos($thema, "konkretes objekt") !== false) $s += 3;
    elseif (strpos($thema, "erste") !== false || strpos($thema, "weitere") !== false) $s += 2;
    elseif (strpos($thema, "eigene immobilie") !== false) $s += 1;
    if ($fam) $s += 1;
    $ampel = $s >= 9 ? "Grün" : ($s >= 5 ? "Gelb" : "Rot");
    return ["score" => $s, "ampel" => $ampel];
}

function lead_headers($replyTo = "") {
    $h  = "From: " . LEAD_ABSENDER . "\r\n";
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: " . $replyTo . "\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";
    return $h;
}

function lead_subject_encode($s) { return "=?UTF-8?B?" . base64_encode($s) . "?="; }

/* Zentraler Versand. Zwei Dinge, die vorher fehlten:
   1. Der Envelope-Absender (-f). Ohne ihn setzt der Server als Absender das
      Systemkonto ein; das passt nicht zur Domain, und viele Empfaenger
      verwerfen die Mail oder sortieren sie in den Spam.
   2. Ein Protokoll. mail() wurde mit @ aufgerufen, Fehler waren unsichtbar.
      Jetzt landet jeder Fehlversuch in _leads/mail-fehler.log. */
function lead_mail($to, $betreff, $text, $headers) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $ok = @mail($to, $betreff, $text, $headers, "-f" . LEAD_ABSENDER);
    if (!$ok) {
        if (!is_dir(LEAD_STORE)) @mkdir(LEAD_STORE, 0700, true);
        @file_put_contents(LEAD_STORE . "/mail-fehler.log",
            date("d.m.Y H:i") . "  an " . $to . "  Betreff: " . $betreff . "\n",
            FILE_APPEND | LOCK_EX);
    }
    return $ok;
}

// Sendet die Lead-Benachrichtigung an Robin
function lead_notify_robin($data, $zusatz = "") {
    $labels = lead_labels();
    $zeilen = [];
    foreach ($data as $key => $wert) {
        if ($key === "bot-field" || $key[0] === "_") continue;
        $wert = trim((string)$wert);
        if ($wert === "") continue;
        $label = $labels[$key] ?? ucfirst($key);
        $zeilen[] = $label . ": " . $wert;
    }
    $name = isset($data["name"]) ? $data["name"] : "";
    $form = isset($data["form-name"]) ? $data["form-name"] : "anfrage";
    $sc = lead_score($data);
    $betreff = "[" . mb_strtoupper($sc["ampel"]) . "] Neuer Lead (" . $form . ")" . $zusatz . " - " . $name;
    $text = "Neue Anfrage ueber meine-kapitalanlage-immobilie.de\n"
          . "==================================================\n"
          . "LEAD-AMPEL: " . $sc["ampel"] . "   (Score " . $sc["score"] . ")\n"
          . "==================================================\n\n"
          . implode("\n", $zeilen)
          . "\n\nGesendet am " . date("d.m.Y") . " um " . date("H:i") . " Uhr";
    $reply = isset($data["email"]) ? $data["email"] : "";
    return lead_mail(LEAD_EMPFAENGER, lead_subject_encode($betreff), $text, lead_headers($reply));
}

// Sendet die Bestaetigungsmail (Double-Opt-In) an den Interessenten
function lead_send_confirm($email, $name, $link) {
    $anrede = $name !== "" ? "Hallo " . $name . "," : "Hallo,";
    $text = $anrede . "\n\n"
          . "vielen Dank fuer Ihre Anfrage ueber meine-kapitalanlage-immobilie.de.\n\n"
          . "Bitte bestaetigen Sie kurz, dass Sie einen Rueckruf wuenschen – mit einem Klick auf diesen Link:\n\n"
          . $link . "\n\n"
          . "Erst nach dieser Bestaetigung nehme ich mit Ihnen Kontakt auf. So stelle ich sicher, dass die Angaben\n"
          . "wirklich von Ihnen stammen. Wenn Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese Mail einfach.\n\n"
          . "Herzliche Gruesse\nRobin Jaenicke\nSpezialist fuer Kapitalanlage-Immobilien\nTel. 0160 92085192";
    return lead_mail($email, lead_subject_encode("Bitte bestaetigen Sie Ihre Rueckrufbitte"), $text, lead_headers());
}

// Bestaetigungsmail fuer den Leitfaden-Download (Double-Opt-In)
function lead_send_confirm_guide($email, $name, $link) {
    $anrede = $name !== "" ? "Hallo " . $name . "," : "Hallo,";
    $text = $anrede . "\n\n"
          . "vielen Dank fuer Ihr Interesse am Einsteiger-Guide \"Immobilie als Kapitalanlage 2026\".\n\n"
          . "Bitte bestaetigen Sie kurz Ihre E-Mail-Adresse - mit einem Klick auf diesen Link:\n\n"
          . $link . "\n\n"
          . "Direkt danach oeffnet sich der Download. Kein Spam, keine Serienmails - versprochen.\n"
          . "Wenn Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese Mail einfach.\n\n"
          . "Herzliche Gruesse\nRobin Jaenicke\nSpezialist fuer Kapitalanlage-Immobilien\nTel. 0160 92085192";
    return lead_mail($email, lead_subject_encode("Ihr Einsteiger-Guide wartet - bitte E-Mail bestaetigen"), $text, lead_headers());
}

// Leitfaden ohne Double-Opt-In: Datei sofort, Mail als Zweitweg
function lead_send_guide_direct($email, $name) {
    $anrede = $name !== "" ? "Hallo " . $name . "," : "Hallo,";
    $pdf = LEAD_BASISURL . "/einsteiger-guide-kapitalanlage-2026.pdf";
    $text = $anrede . "\n\n"
          . "hier ist Ihr Einsteiger-Guide \"Immobilie als Kapitalanlage 2026\":\n\n"
          . $pdf . "\n\n"
          . "Sie haben ihn eben schon auf der Website heruntergeladen - diese Mail ist nur,\n"
          . "damit Sie den Link jederzeit wiederfinden.\n\n"
          . "Wenn nach dem Lesen Fragen offen bleiben: Melden Sie sich einfach, ich helfe gern weiter.\n"
          . "Kein Verkaufsdruck, kein Serienbrief.\n\n"
          . "Herzliche Gruesse\nRobin Jaenicke\nSpezialist fuer Kapitalanlage-Immobilien\nTel. 0160 92085192";
    return lead_mail($email, lead_subject_encode("Ihr Einsteiger-Guide: Immobilie als Kapitalanlage"), $text, lead_headers());
}

// Bestaetigung an den Interessenten: Rueckrufbitte ist da + die beiden Geschenke.
// Wird nach dem Double-Opt-In verschickt bzw. dort, wo kein DOI noetig ist.
function lead_send_danke($email, $name, $saLink = "", $termin = false) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $anrede = trim((string)$name) !== "" ? "Hallo " . $name . "," : "Hallo,";
    $guide   = LEAD_BASISURL . "/einsteiger-guide-kapitalanlage-2026.pdf";
    $rechner = LEAD_BASISURL . "/rechner.html";
    $text = $anrede . "\n\n";
    if ($termin) {
        $text .= "vielen Dank fuer Ihre Terminbuchung - ich freue mich auf unser Gespraech.\n"
               . "Die Termindetails kommen in einer separaten Bestaetigung von Calendly.\n\n";
    } else {
        $text .= "vielen Dank, Ihre Rueckrufbitte ist bei mir eingegangen.\n"
               . "Ich melde mich kurzfristig persoenlich bei Ihnen - in der Regel noch am selben oder am naechsten Werktag.\n\n";
    }
    if ($saLink !== "") {
        $text .= "IHRE DIGITALE SELBSTAUSKUNFT\n"
               . "Wenn Sie moechten, koennen Sie schon vorab Ihre Selbstauskunft ausfuellen (ca. 10 Minuten).\n"
               . "Dann kann ich Ihre Moeglichkeiten zum Gespraech bereits konkret durchrechnen:\n\n"
               . "   " . $saLink . "\n\n"
               . "Der Link ist nur fuer Sie bestimmt. Ihre Eingaben werden zwischengespeichert - Sie koennen\n"
               . "jederzeit unterbrechen, spaeter ueber diesen Link weitermachen und am Ende absenden.\n"
               . "Bitte leiten Sie diese E-Mail deshalb nicht weiter.\n\n";
    }
    $text .= "Uebrigens - zwei Dinge, die Sie schon nutzen koennen:\n"
           . "- Einsteiger-Guide 2026: " . $guide . "\n"
           . "- Kapitalanlage-Rechner:  " . $rechner . "\n\n"
           . "Wenn Sie vorher etwas wissen moechten, erreichen Sie mich direkt unter 0160 92085192.\n\n"
           . "Herzliche Gruesse\n"
           . "Robin Jaenicke\n"
           . "Spezialist fuer Kapitalanlage-Immobilien\n"
           . "Tel. 0160 92085192\n"
           . "robin.jaenicke@bonnfinanz.de";
    $betreff = $termin ? "Ihr Termin ist gebucht - und Ihre Selbstauskunft"
                       : "Ihre Rueckrufbitte ist eingegangen - ich melde mich kurzfristig";
    if ($saLink === "") $betreff = $termin ? "Ihr Termin ist gebucht" : "Ihre Rueckrufbitte ist eingegangen";
    return lead_mail($email, lead_subject_encode($betreff), $text, lead_headers());
}

// Uebergabe an HubSpot (Forms-API, EU). Ohne GUID passiert nichts.
function lead_to_hubspot($data, $doi = "bestaetigt") {
    if (HUBSPOT_FORM_GUID === "" || !function_exists("curl_init")) return false;
    $fields = [];
    $nm = trim((string)($data["name"] ?? ""));
    if ($nm !== "") {
        $parts = explode(" ", $nm, 2);
        $fields[] = ["name" => "firstname", "value" => $parts[0]];
        if (!empty($parts[1])) $fields[] = ["name" => "lastname", "value" => $parts[1]];
    }
    if (!empty($data["email"])) $fields[] = ["name" => "email", "value" => $data["email"]];
    if (!empty($data["phone"])) $fields[] = ["name" => "phone", "value" => $data["phone"]];

    // Lead-Score/Ampel berechnen (fliesst in strukturierte Felder + Nachricht + Lead-Mail).
    $sc = lead_score($data);

    // --- Strukturierte HubSpot-Felder (fuer Filtern / Ampel / Lead-Scoring) ---
    // Diese Properties sind im HubSpot-Formular (GUID oben) als Felder angelegt.
    // Ampel (Dropdown Gruen/Gelb/Rot) + Score (Zahl)
    $fields[] = ["name" => "bewertung_kapitalanlage", "value" => $sc["ampel"]];
    $fields[] = ["name" => "score_kapitalanlage", "value" => (string)$sc["score"]];
    // Zahlen-Felder (formatiert "3.000 EUR" -> reine Ziffern)
    $netto = lead_num($data["einkommen"] ?? "");
    if ($netto !== "") $fields[] = ["name" => "nettoeinkommen_mtl", "value" => $netto];
    $zve = lead_num($data["zve"] ?? "");
    if ($zve !== "") $fields[] = ["name" => "zu_versteuerndes_einkommen_zve_pa", "value" => $zve];
    $ek = lead_num($data["eigenkapital"] ?? "");
    if ($ek !== "") $fields[] = ["name" => "eigenkapital", "value" => $ek];
    $alter = lead_num($data["alter"] ?? "");
    if ($alter !== "") $fields[] = ["name" => "alter", "value" => $alter];
    // Beschaeftigungsart: Quiz-Antwort -> HubSpot-Dropdown-Wert mappen
    $stat = mb_strtolower(trim($data["status"] ?? ""));
    if ($stat !== "") {
        if (strpos($stat, "beamt") !== false)            $besch = "Beamter";
        elseif (strpos($stat, "selbst") !== false)       $besch = "Selbstständig";
        elseif (strpos($stat, "angestellt") !== false)   $besch = "Angestellt";
        else                                             $besch = "Sonstiges"; // Ausbildung/Studium/Sonstiges
        $fields[] = ["name" => "beschaftigungsart", "value" => $besch];
    }

    // --- Volltext als Referenz ---
    $labels = lead_labels();
    $skip = ["name","email","phone","bot-field","form-name","quelle"];
    $msg = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $skip, true) || $k[0] === "_") continue;
        $v = trim((string)$v); if ($v === "") continue;
        $msg[] = ($labels[$k] ?? ucfirst($k)) . ": " . $v;
    }
    $msg[] = "Lead-Ampel: " . $sc["ampel"] . " (Score " . $sc["score"] . ")";
    $msg[] = "Double-Opt-In: " . $doi;
    if ($msg) $fields[] = ["name" => "message", "value" => implode("\n", $msg)];

    $payload = json_encode([
        "fields"  => $fields,
        "context" => ["pageName" => "Kapitalanlage-Landingpage", "pageUri" => LEAD_BASISURL],
    ]);
    $url = "https://api-eu1.hsforms.com/submissions/v3/integration/submit/" . HUBSPOT_PORTAL_ID . "/" . HUBSPOT_FORM_GUID;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300;
    // Abgelehnte Uebergaben protokollieren (sonst faellt ein falscher Feldname nie auf)
    if (!$ok) {
        @file_put_contents(LEAD_STORE . "/hubspot-fehler.log",
            date("c") . " HTTP " . $code . " " . $cerr . " " . substr((string)$res, 0, 1500) . "\n",
            FILE_APPEND | LOCK_EX);
    }
    // Deal nur fuer echte Kontaktanfragen (Rueckruf/Termin), nicht fuer reine Guide-Downloads
    if (($data["leadmagnet"] ?? "") === "") lead_hubspot_deal($data);
    return $ok;
}

// ---------- HubSpot CRM-API (Private App) ----------
function hs_api($method, $path, $body = null) {
    if (!defined("HUBSPOT_TOKEN") || HUBSPOT_TOKEN === "") return [0, null];
    $ch = curl_init("https://api.hubapi.com" . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . HUBSPOT_TOKEN, "Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        if (!is_dir(LEAD_STORE)) @mkdir(LEAD_STORE, 0700, true);
        @file_put_contents(LEAD_STORE . "/hubspot-fehler.log",
            date("c") . " CRM " . $method . " " . $path . " HTTP " . $code . " " . substr((string)$res, 0, 800) . "\n",
            FILE_APPEND | LOCK_EX);
    }
    return [$code, json_decode((string)$res, true)];
}

// Legt pro Kontakt genau einen Deal an und hebt ihn bei neuen Ereignissen
// (Termin gebucht, Selbstauskunft uebermittelt) in die passende Phase.
function lead_hubspot_deal($data) {
    $email  = trim((string)($data["email"] ?? ""));
    $termin = (stripos((string)($data["terminstatus"] ?? ""), "Termin gebucht") !== false);
    $sa     = !empty($data["_sa_done"]);
    hs_deal_advance($email, (string)($data["name"] ?? ""), $termin, $sa);
}

// Phase <-> (Termin?, Selbstauskunft?)
function hs_stage_flags($stage) {
    $map = [
        HUBSPOT_STAGE_RUECKRUF    => [false, false],
        HUBSPOT_STAGE_RUECKRUF_SA => [false, true],
        HUBSPOT_STAGE_TERMIN      => [true,  false],
        HUBSPOT_STAGE_TERMIN_SA   => [true,  true],
    ];
    return $map[$stage] ?? null; // null = manuell gepflegte Phase, nicht anfassen
}
function hs_stage_for($termin, $sa) {
    if ($termin) return $sa ? HUBSPOT_STAGE_TERMIN_SA : HUBSPOT_STAGE_TERMIN;
    return $sa ? HUBSPOT_STAGE_RUECKRUF_SA : HUBSPOT_STAGE_RUECKRUF;
}

function hs_deal_advance($email, $name, $termin, $sa) {
    if (!defined("HUBSPOT_TOKEN") || !function_exists("curl_init")) return;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    // Kontakt per E-Mail finden oder anlegen (die Formular-Uebergabe fuehrt per E-Mail zusammen)
    [$c, $r] = hs_api("POST", "/crm/v3/objects/contacts/batch/upsert", [
        "inputs" => [["idProperty" => "email", "id" => $email, "properties" => ["email" => $email]]],
    ]);
    $cid = $r["results"][0]["id"] ?? null;
    if (!$cid) return;

    [$c, $r] = hs_api("GET", "/crm/v4/objects/contacts/" . $cid . "/associations/deals");
    $dealId = $r["results"][0]["toObjectId"] ?? null;
    if ($dealId) {
        [$c, $d] = hs_api("GET", "/crm/v3/objects/deals/" . $dealId . "?properties=dealstage");
        $cur   = (string)($d["properties"]["dealstage"] ?? "");
        $flags = hs_stage_flags($cur);
        if ($flags === null) return; // Berater hat den Deal schon weitergeschoben
        $ziel = hs_stage_for($flags[0] || $termin, $flags[1] || $sa);
        if ($ziel !== $cur) hs_api("PATCH", "/crm/v3/objects/deals/" . $dealId, ["properties" => ["dealstage" => $ziel]]);
        return;
    }

    $name = trim($name) ?: $email;
    hs_api("POST", "/crm/v3/objects/deals", [
        "properties" => [
            "dealname"  => $name . " – Kapitalanlage",
            "pipeline"  => HUBSPOT_PIPELINE,
            "dealstage" => hs_stage_for($termin, $sa),
        ],
        "associations" => [[
            "to"    => ["id" => $cid],
            "types" => [["associationCategory" => "HUBSPOT_DEFINED", "associationTypeId" => 3]],
        ]],
    ]);
}

// ===================== Digitale Selbstauskunft =====================
// Pro Lead ein persoenlicher Link (selbstauskunft.html?t=TOKEN). Der Entwurf
// liegt bis zum Absenden in _leads/sa/ (per .htaccess gesperrt) und wird nach
// dem Absenden geloescht - uebrig bleibt nur der Status.
const SA_DIR = LEAD_STORE . "/sa";

function sa_valid_token($t) { return is_string($t) && preg_match('/^[a-f0-9]{32}$/', $t); }
function sa_path($t) { return SA_DIR . "/" . $t . ".json"; }
function sa_link($t) { return LEAD_BASISURL . "/selbstauskunft.html?t=" . $t; }
function sa_load($t) {
    if (!sa_valid_token($t) || !is_file(sa_path($t))) return null;
    $j = json_decode((string)@file_get_contents(sa_path($t)), true);
    return is_array($j) ? $j : null;
}
function sa_save($t, $rec) {
    if (!sa_valid_token($t)) return false;
    if (!is_dir(SA_DIR)) @mkdir(SA_DIR, 0700, true);
    $rec["updated"] = time();
    return @file_put_contents(sa_path($t), json_encode($rec, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
// Legt den Datensatz an, falls er noch nicht existiert. true = neu angelegt.
function sa_create($t, $data) {
    if (!sa_valid_token($t) || is_file(sa_path($t))) return false;
    return sa_save($t, [
        "created" => time(),
        "status"  => "offen",
        "lead"    => [
            "name"  => (string)($data["name"] ?? ""),
            "email" => (string)($data["email"] ?? ""),
            "phone" => (string)($data["phone"] ?? ""),
        ],
        "draft"   => new stdClass(),
    ]);
}
function sa_is_done($t) { $r = sa_load($t); return $r && ($r["status"] ?? "") === "uebermittelt"; }

// Mail mit Dateianhang (Selbstauskunft-PDF an den Berater)
function lead_mail_attach($to, $betreff, $text, $replyTo, $dateiname, $bytes) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $grenze = "=_mki_" . bin2hex(random_bytes(8));
    $h  = "From: " . LEAD_ABSENDER . "\r\n";
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: " . $replyTo . "\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"" . $grenze . "\"\r\n";
    $dn = preg_replace('/[^A-Za-z0-9._-]/', '_', $dateiname);
    $body  = "--" . $grenze . "\r\n"
           . "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
           . $text . "\r\n\r\n"
           . "--" . $grenze . "\r\n"
           . "Content-Type: application/pdf; name=\"" . $dn . "\"\r\n"
           . "Content-Transfer-Encoding: base64\r\n"
           . "Content-Disposition: attachment; filename=\"" . $dn . "\"\r\n\r\n"
           . chunk_split(base64_encode($bytes)) . "\r\n"
           . "--" . $grenze . "--\r\n";
    return lead_mail($to, $betreff, $body, $h);
}
