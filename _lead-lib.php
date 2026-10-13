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
const HUBSPOT_FORM_GUID = "6192bfac-c361-4efb-af5e-16d19d3cf39b"; // HubSpot-Formular (EU-Portal). Felder: firstname, lastname, email, phone, message, bewertung_kapitalanlage, score_kapitalanlage, nettoeinkommen_mtl, zu_versteuerndes_einkommen_zve_pa, eigenkapital, alter, beschaftigungsart

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
        if ($key === "bot-field") continue;
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
function lead_send_danke($email, $name) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $anrede = trim((string)$name) !== "" ? "Hallo " . $name . "," : "Hallo,";
    $guide   = LEAD_BASISURL . "/einsteiger-guide-kapitalanlage-2026.pdf";
    $rechner = LEAD_BASISURL . "/rechner.html";
    $text = $anrede . "\n\n"
          . "Ihre Rueckrufbitte ist bei mir angekommen - ich melde mich zeitnah persoenlich bei Ihnen.\n"
          . "In der Regel noch am selben oder am naechsten Werktag.\n\n"
          . "Bis dahin zwei Dinge, die Sie schon nutzen koennen:\n\n"
          . "1) Der Einsteiger-Guide 2026 - Immobilie als Kapitalanlage\n"
          . "   " . $guide . "\n"
          . "   20 Seiten, verstaendlich erklaert: wie sich eine Anlageimmobilie wirklich rechnet,\n"
          . "   der ehrliche Vergleich zu ETF, Tagesgeld und Gold, und die haeufigsten Einsteiger-Fehler.\n\n"
          . "2) Ihr Kapitalanlage-Rechner zum Behalten\n"
          . "   " . $rechner . "\n"
          . "   Rendite, Cashflow und Steuerwirkung mit Ihren eigenen Zahlen durchrechnen.\n"
          . "   Einmal speichern, danach jederzeit offline nutzbar.\n\n"
          . "Wenn Sie vorher etwas wissen moechten, erreichen Sie mich direkt unter 0160 92085192.\n\n"
          . "Herzliche Gruesse\n"
          . "Robin Jaenicke\n"
          . "Spezialist fuer Kapitalanlage-Immobilien\n"
          . "Tel. 0160 92085192\n"
          . "robin.jaenicke@bonnfinanz.de";
    return lead_mail($email, lead_subject_encode("Ihre Rueckrufbitte ist angekommen - und zwei Dinge vorab"), $text, lead_headers());
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
        if (in_array($k, $skip, true)) continue;
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
    return $ok;
}
