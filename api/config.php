<?php
/**
 * Plik konfiguracygny galerii
 */

// ------------------------------------------------------------------
// GŁÓWNE USTAWIENIA GALERII
// ------------------------------------------------------------------

// Ustaw tytuł, który będzie widoczny w nagłówku albumu.
define('ALBUM_TITLE', 'Wybór zdjęć do obrobienia');
// Ustaw na `true`, aby włączyć ochronę hasłem.
// Ustaw na `false`, aby galeria była publicznie dostępna dla każdego.
define('PASSWORD_PROTECTION_ENABLED', false);

// Hasło do panelu administratora (admin/login.php)
define('ADMIN_PASSWORD_HASH', '$2y$10$iG1uHbnb..19hsOckQZWHem2xsdF/6UaurRnlXamYr58I4PL2HmI2'); // Unbraided5-Uncivil-Unabashed

// To hasło będzie używane tylko wtedy, gdy PASSWORD_PROTECTION_ENABLED jest `true`.
define('GALLERY_PASSWORD', 'admin###');


// ------------------------------------------------------------------
// LINKI KONTAKTOWE
// ------------------------------------------------------------------
// Uzupełnij linki, które pojawią się w sekcji kontaktowej na dole strony.
define('CONTACT_TELEGRAM', 'https://t.me/WowkDigital');
define('CONTACT_FACEBOOK', 'https://www.facebook.com/krzysztof.wowk.42');
define('CONTACT_SIGNAL', 'https://signal.me/#eu/m1_mIKa_RlVvrUuDje_yvykzJ7AvG6rNlWORi2exRqOb8_ScnPlMC7ADyT2xqb3p');
// Dodano link do WhatsApp, choć nie było go w oryginalnym pliku HTML. Można go dodać.
define('CONTACT_WHATSAPP', 'https://wa.me/48664433505');
// Zostawiam link do Instagrama, na wypadek gdyby był potrzebny.
define('CONTACT_INSTAGRAM', 'https://www.instagram.com/wowkdigital/');